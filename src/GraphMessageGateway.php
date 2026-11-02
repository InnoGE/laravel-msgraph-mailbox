<?php

declare(strict_types=1);

namespace InnoGE\LaravelMsGraphMailbox;

use Illuminate\Http\Client\RequestException;
use InnoGE\LaravelMsGraphMail\Graph\GraphClient;
use InnoGE\LaravelMsGraphMail\Graph\InvalidResponseException;
use InnoGE\LaravelMsGraphMailbox\Contracts\MessageGateway;
use InnoGE\LaravelMsGraphMailbox\Enums\BodyType;
use InnoGE\LaravelMsGraphMailbox\Enums\FlagStatus;
use InnoGE\LaravelMsGraphMailbox\Enums\WellKnownFolder;
use Psr\Http\Message\StreamInterface;
use Throwable;

/** @internal */
final readonly class GraphMessageGateway implements MessageGateway
{
    private const string FIELDS = 'id,subject,from,sender,toRecipients,ccRecipients,receivedDateTime,internetMessageId,internetMessageHeaders,isRead,flag,categories,parentFolderId';

    public function __construct(private GraphClient $graph, private Folders $folders) {}

    public function find(string $mailbox, string $messageId): array
    {
        $message = $this->graph->request()
            ->get($this->graph->path('users', $mailbox, 'messages', $messageId), ['$select' => self::FIELDS])
            ->throw()
            ->json();

        if (! is_array($message)) {
            throw new InvalidResponseException('Expected Microsoft Graph to return a message object.');
        }

        /** @var array<string, mixed> $message */
        return $message;
    }

    public function body(string $mailbox, string $messageId, BodyType $type, bool $unique): string
    {
        $field = $unique ? 'uniqueBody' : 'body';

        $content = $this->graph->request("outlook.body-content-type=\"{$type->value}\"")
            ->get($this->graph->path('users', $mailbox, 'messages', $messageId), ['$select' => $field])
            ->throw()
            ->json("{$field}.content");

        return is_string($content) ? $content : '';
    }

    public function attachments(string $mailbox, string $messageId): array
    {
        $attachments = $this->graph->request()
            ->get($this->graph->path('users', $mailbox, 'messages', $messageId, 'attachments'), ['$select' => 'id,name,contentType,size,isInline'])
            ->throw()
            ->json('value');

        /** @var list<array<string, mixed>> */
        return array_values(array_filter(is_array($attachments) ? $attachments : [], is_array(...)));
    }

    public function attachmentContent(string $mailbox, string $messageId, string $attachmentId): StreamInterface
    {
        return $this->stream($this->graph->path('users', $mailbox, 'messages', $messageId, 'attachments', $attachmentId).'/$value');
    }

    public function mime(string $mailbox, string $messageId): StreamInterface
    {
        return $this->stream($this->graph->path('users', $mailbox, 'messages', $messageId).'/$value');
    }

    public function move(string $mailbox, string $messageId, WellKnownFolder|string $folder, bool $createMissing): void
    {
        $this->graph->request()
            ->post($this->graph->path('users', $mailbox, 'messages', $messageId, 'move'), ['destinationId' => $this->destination($mailbox, $folder, $createMissing)])
            ->throw();
    }

    public function copy(string $mailbox, string $messageId, WellKnownFolder|string $folder, bool $createMissing): string
    {
        $id = $this->graph->request()
            ->post($this->graph->path('users', $mailbox, 'messages', $messageId, 'copy'), ['destinationId' => $this->destination($mailbox, $folder, $createMissing)])
            ->throw()
            ->json('id');

        if (! is_string($id)) {
            throw new InvalidResponseException('Expected Microsoft Graph to return the id of the copied message.');
        }

        return $id;
    }

    public function markRead(string $mailbox, string $messageId, bool $read): void
    {
        $this->patch($mailbox, $messageId, ['isRead' => $read]);
    }

    public function flag(string $mailbox, string $messageId, FlagStatus $status): void
    {
        $this->patch($mailbox, $messageId, ['flag' => ['flagStatus' => $status->value]]);
    }

    public function setCategories(string $mailbox, string $messageId, array $categories): void
    {
        $this->patch($mailbox, $messageId, ['categories' => $categories]);
    }

    public function delete(string $mailbox, string $messageId): void
    {
        $this->move($mailbox, $messageId, WellKnownFolder::DeletedItems, false);
    }

    public function permanentDelete(string $mailbox, string $messageId): void
    {
        $this->graph->request()
            ->post($this->graph->path('users', $mailbox, 'messages', $messageId, 'permanentDelete'))
            ->throw();
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function patch(string $mailbox, string $messageId, array $changes): void
    {
        $this->graph->request()
            ->retry(3, 1000, fn (Throwable $exception) => $exception instanceof RequestException && $exception->response->status() === 412)
            ->patch($this->graph->path('users', $mailbox, 'messages', $messageId), $changes)
            ->throw();
    }

    private function destination(string $mailbox, WellKnownFolder|string $folder, bool $createMissing): string
    {
        $folder = Folders::normalize($folder);

        return $folder instanceof WellKnownFolder ? $folder->value : $this->folders->id($mailbox, $folder, $createMissing);
    }

    private function stream(string $path): StreamInterface
    {
        return $this->graph->request()
            ->withOptions(['stream' => true])
            ->get($path)
            ->throw()
            ->toPsrResponse()
            ->getBody();
    }
}
