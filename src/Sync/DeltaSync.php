<?php

declare(strict_types=1);

namespace InnoGE\LaravelMsGraphMailbox\Sync;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use InnoGE\LaravelMsGraphMail\Graph\GraphClient;
use InnoGE\LaravelMsGraphMail\Graph\InvalidResponseException;
use InnoGE\LaravelMsGraphMail\Graph\UntrustedGraphLinkException;
use InnoGE\LaravelMsGraphMailbox\Enums\ChangeType;
use InnoGE\LaravelMsGraphMailbox\Enums\WellKnownFolder;
use InnoGE\LaravelMsGraphMailbox\Events\MessageChanged;
use InnoGE\LaravelMsGraphMailbox\Events\MessageDeleted;
use InnoGE\LaravelMsGraphMailbox\Events\MessageEvent;
use InnoGE\LaravelMsGraphMailbox\Events\MessageMoved;
use InnoGE\LaravelMsGraphMailbox\Events\MessagePermanentlyDeleted;
use InnoGE\LaravelMsGraphMailbox\Events\MessageReceived;
use InnoGE\LaravelMsGraphMailbox\Folders;
use InnoGE\LaravelMsGraphMailbox\Models\MailboxFolder;
use InnoGE\LaravelMsGraphMailbox\Models\MailboxMessage;

/** @internal */
final readonly class DeltaSync
{
    private const string PAGE_SIZE = 'odata.maxpagesize=100';

    private const array LOST_SYNC_STATE = ['syncStateNotFound', 'syncStateInvalid', 'resyncRequired'];

    public function __construct(
        private GraphClient $graph,
        private Folders $folders,
        private Dispatcher $events,
    ) {}

    public function run(MailboxFolder $folder, int $maxPages): bool
    {
        $this->folders->idOf($folder);

        for ($page = 0; $page < $maxPages; $page++) {
            $response = $this->fetch($folder);

            if ($response === null) {
                $folder->restartWalk();

                continue;
            }

            $body = (array) $response->json();
            $this->applyPage($folder, (array) ($body['value'] ?? []));

            if (is_string($body['@odata.nextLink'] ?? null)) {
                $folder->advanceTo($body['@odata.nextLink']);

                continue;
            }

            if (! is_string($body['@odata.deltaLink'] ?? null)) {
                throw new InvalidResponseException('Expected the delta response to contain @odata.nextLink or @odata.deltaLink.');
            }

            if ($folder->isWalking()) {
                $this->removeUnseen($folder);
            }

            $folder->completeSync($body['@odata.deltaLink']);

            return false;
        }

        return true;
    }

    private function fetch(MailboxFolder $folder): ?Response
    {
        if ($folder->delta_link === null) {
            $response = $this->graph->request(self::PAGE_SIZE)->get(
                $this->graph->path('users', $folder->mailbox, 'mailFolders', (string) $folder->folder_id, 'messages', 'delta'),
                ['$select' => 'changeKey'],
            );
        } else {
            try {
                $response = $this->graph->request(self::PAGE_SIZE)->get($folder->delta_link);
            } catch (UntrustedGraphLinkException) {
                return null;
            }
        }

        if ($response->status() === 410 || in_array($response->json('error.code'), self::LOST_SYNC_STATE, true)) {
            return null;
        }

        return $response->throw();
    }

    /**
     * @param  array<mixed>  $items
     */
    private function applyPage(MailboxFolder $folder, array $items): void
    {
        $items = array_filter($items, fn (mixed $item) => is_array($item) && is_string($item['id'] ?? null));

        $tracked = [];
        $folder->messages()
            ->whereIn('message_id', array_column($items, 'id'))
            ->each(function (MailboxMessage $message) use (&$tracked): void {
                $tracked[$message->message_id] = $message;
            });

        foreach ($items as $item) {
            $messageId = (string) $item['id'];
            $this->apply($folder, $messageId, $item, $tracked[$messageId] ?? null);
        }
    }

    /**
     * @param  array<mixed>  $item
     */
    private function apply(MailboxFolder $folder, string $messageId, array $item, ?MailboxMessage $tracked): void
    {
        if (array_key_exists('@removed', $item)) {
            if ($tracked !== null) {
                $this->remove($folder, $tracked);
            }

            return;
        }

        $changeKey = is_string($item['changeKey'] ?? null) ? $item['changeKey'] : null;
        $seenAt = CarbonImmutable::now();

        if ($tracked === null) {
            $this->record(
                $folder,
                fn () => $folder->messages()->create(['message_id' => $messageId, 'change_key' => $changeKey, 'seen_at' => $seenAt]),
                $folder->reports(ChangeType::Created) ? new MessageReceived($folder->mailbox, $folder->folder, $messageId) : null,
            );

            return;
        }

        $changed = $tracked->change_key !== $changeKey;

        $this->record(
            $folder,
            fn () => $tracked->update(['change_key' => $changeKey, 'seen_at' => $seenAt]),
            $changed && $folder->reports(ChangeType::Updated) ? new MessageChanged($folder->mailbox, $folder->folder, $messageId) : null,
        );
    }

    private function remove(MailboxFolder $folder, MailboxMessage $tracked): void
    {
        $event = null;

        if ($folder->reports(ChangeType::Deleted)) {
            $parentFolderId = $this->parentFolderId($folder, $tracked->message_id);

            if ($parentFolderId === $folder->folder_id) {
                return;
            }

            $event = $this->removalEvent($folder, $tracked->message_id, $parentFolderId);
        }

        $this->record($folder, fn () => $tracked->delete(), $event);
    }

    private function parentFolderId(MailboxFolder $folder, string $messageId): ?string
    {
        $response = $this->graph->request()->get(
            $this->graph->path('users', $folder->mailbox, 'messages', $messageId),
            ['$select' => 'parentFolderId'],
        );

        if ($response->notFound()) {
            return null;
        }

        $parentFolderId = $response->throw()->json('parentFolderId');
        if (! is_string($parentFolderId)) {
            throw new InvalidResponseException('Expected the message to contain a parentFolderId.');
        }

        return $parentFolderId;
    }

    private function removalEvent(MailboxFolder $folder, string $messageId, ?string $parentFolderId): MessageEvent
    {
        return match ($parentFolderId) {
            null => new MessagePermanentlyDeleted($folder->mailbox, $folder->folder, $messageId),
            $this->folders->id($folder->mailbox, WellKnownFolder::DeletedItems) => new MessageDeleted($folder->mailbox, $folder->folder, $messageId),
            $this->folders->id($folder->mailbox, WellKnownFolder::RecoverableItemsDeletions),
            $this->folders->id($folder->mailbox, WellKnownFolder::RecoverableItemsPurges) => new MessagePermanentlyDeleted($folder->mailbox, $folder->folder, $messageId),
            default => new MessageMoved($folder->mailbox, $folder->folder, $messageId, $parentFolderId),
        };
    }

    private function removeUnseen(MailboxFolder $folder): void
    {
        $folder->messages()
            ->where('seen_at', '<', $folder->walk_started_at)
            ->eachById(fn (MailboxMessage $tracked) => $this->remove($folder, $tracked));
    }

    private function record(MailboxFolder $folder, Closure $write, ?MessageEvent $event): void
    {
        DB::transaction(function () use ($write, $event): void {
            $write();

            if ($event !== null) {
                $this->events->dispatch($event);
            }
        });
    }
}
