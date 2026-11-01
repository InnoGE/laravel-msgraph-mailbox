<?php

declare(strict_types=1);

namespace InnoGE\LaravelMsGraphMailbox;

use Carbon\CarbonImmutable;
use InnoGE\LaravelMsGraphMail\Graph\GraphClient;
use InnoGE\LaravelMsGraphMail\Graph\InvalidResponseException;
use InnoGE\LaravelMsGraphMailbox\Enums\ChangeType;
use InnoGE\LaravelMsGraphMailbox\Models\MailboxFolder;

/** @internal */
final readonly class Subscriptions
{
    private const int MAX_LIFETIME_MINUTES = 10070;

    public function __construct(
        private GraphClient $graph,
        private Folders $folders,
        private MailboxManager $mailboxes,
    ) {}

    /**
     * @return list<array{mailbox: string, folder: string, action: string}>
     */
    public function reconcile(): array
    {
        $rows = [];

        foreach ($this->mailboxes->observed() as $mailbox) {
            foreach ($mailbox->folders as $folder) {
                $row = MailboxFolder::query()->firstOrNew(['mailbox' => $mailbox->address, 'folder' => Folders::key($folder)]);
                $row->change_types = collect($mailbox->changeTypes);
                $this->folders->idOf($row);
                $rows[] = $row;
            }
        }

        $actions = [];

        foreach ($rows as $row) {
            $changeTypesChanged = $row->exists && $row->isDirty('change_types');
            $row->save();

            $actions[] = ['mailbox' => $row->mailbox, 'folder' => $row->folder, 'action' => $this->ensure($row, $changeTypesChanged)];
        }

        MailboxFolder::query()
            ->whereNotIn('id', array_map(fn (MailboxFolder $row) => $row->id, $rows))
            ->eachById(function (MailboxFolder $row) use (&$actions): void {
                $this->unsubscribe($row);
                $row->delete();
                $actions[] = ['mailbox' => $row->mailbox, 'folder' => $row->folder, 'action' => 'removed'];
            });

        return $actions;
    }

    public function refresh(MailboxFolder $folder): void
    {
        if ($folder->subscription_id !== null) {
            $expiresAt = $this->expiry();
            $response = $this->graph->request()->patch($this->graph->path('subscriptions', $folder->subscription_id), [
                'expirationDateTime' => $expiresAt->toIso8601ZuluString(),
            ]);

            if (! $response->notFound()) {
                $response->throw();
                $folder->subscription_expires_at = $expiresAt;
                $folder->save();

                return;
            }
        }

        $this->create($folder);
    }

    public function unsubscribe(MailboxFolder $folder): void
    {
        if ($folder->subscription_id === null) {
            return;
        }

        $response = $this->graph->request()->delete($this->graph->path('subscriptions', $folder->subscription_id));
        if (! $response->notFound()) {
            $response->throw();
        }

        $folder->subscription_id = null;
        $folder->client_state_hash = null;
        $folder->subscription_expires_at = null;
        $folder->save();
    }

    private function ensure(MailboxFolder $folder, bool $changeTypesChanged): string
    {
        if (! config()->boolean('msgraph-mailbox.webhooks.enabled')) {
            $this->unsubscribe($folder);

            return 'polling';
        }

        if ($folder->subscription_id !== null && $changeTypesChanged) {
            $this->unsubscribe($folder);
        }

        if ($folder->subscription_id === null) {
            $this->create($folder);

            return 'subscribed';
        }

        $renewAfter = CarbonImmutable::now()->addMinutes(intdiv($this->lifetimeMinutes(), 2));
        if ($folder->subscription_expires_at?->isAfter($renewAfter) === true) {
            return 'ok';
        }

        $this->refresh($folder);

        return 'renewed';
    }

    private function create(MailboxFolder $folder): void
    {
        $clientState = $folder->rotateClientState();
        $expiresAt = $this->expiry();
        $notificationUrl = config('msgraph-mailbox.webhooks.notification_url') ?: route('msgraph-mailbox.webhook');

        $subscriptionId = $this->graph->request()
            ->post('/subscriptions', [
                'changeType' => $folder->change_types->map(fn (ChangeType $type) => $type->value)->implode(','),
                'notificationUrl' => $notificationUrl,
                'lifecycleNotificationUrl' => $notificationUrl,
                'resource' => "users/{$folder->mailbox}/mailFolders/{$folder->folder_id}/messages",
                'expirationDateTime' => $expiresAt->toIso8601ZuluString(),
                'clientState' => $clientState,
            ])
            ->throw()
            ->json('id');

        if (! is_string($subscriptionId)) {
            throw new InvalidResponseException('Expected Microsoft Graph to return the id of the created subscription.');
        }

        $folder->subscription_id = $subscriptionId;
        $folder->subscription_expires_at = $expiresAt;
        $folder->save();
    }

    private function expiry(): CarbonImmutable
    {
        return CarbonImmutable::now()->addMinutes($this->lifetimeMinutes());
    }

    private function lifetimeMinutes(): int
    {
        return min(config()->integer('msgraph-mailbox.subscriptions.lifetime_minutes'), self::MAX_LIFETIME_MINUTES);
    }
}
