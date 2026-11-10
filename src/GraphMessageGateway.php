<?php

declare(strict_types=1);

namespace InnoGE\LaravelMsGraphMailbox;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Str;
use InnoGE\LaravelMsGraphMail\Graph\GraphClient;
use InnoGE\LaravelMsGraphMail\Graph\InvalidResponseException;
use InnoGE\LaravelMsGraphMailbox\Contracts\MessageGateway;
use InnoGE\LaravelMsGraphMailbox\Enums\BodyType;
use InnoGE\LaravelMsGraphMailbox\Enums\FlagStatus;
use InnoGE\LaravelMsGraphMailbox\Enums\WellKnownFolder;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use Throwable;

/** @internal */
final readonly class GraphMessageGateway implements MessageGateway
{
    private const string FIELDS = 'id,subject,from,sender,toRecipients,ccRecipients,receivedDateTime,sentDateTime,internetMessageId,conversationId,bccRecipients,replyTo,bodyPreview,hasAttachments,internetMessageHeaders,isRead,flag,categories,parentFolderId';

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

    public function page(string $mailbox, WellKnownFolder|string $folder, ?DateTimeInterface $since, ?string $next): array
    {
        if ($next !== null && ! Str::startsWith(Str::lower(rawurldecode((string) parse_url($next, PHP_URL_PATH))), Str::lower('/v1.0'.$this->graph->path('users', $mailbox).'/'))) {
            throw new InvalidArgumentException("The next link does not belong to mailbox [{$mailbox}].");
        }

        $page = ($next === null
            ? $this->graph->request()->get($this->graph->path('users', $mailbox, 'mailFolders', $this->destination($mailbox, $folder, false), 'messages'), array_filter([
                '$select' => self::FIELDS,
                '$top' => 50,
                '$orderby' => 'receivedDateTime desc',
                '$filter' => $since === null ? null : 'receivedDateTime ge '.CarbonImmutable::instance($since)->toIso8601ZuluString(),
            ]))
            : $this->graph->request()->get($next))
            ->throw()
            ->json();

        if (! is_array($page) || ! is_array($page['value'] ?? null)) {
            throw new InvalidResponseException('Expected Microsoft Graph to return a page of messages.');
        }

        /** @var list<array<string, mixed>> $messages */
        $messages = array_values(array_filter($page['value'], is_array(...)));

        return ['messages' => $messages, 'next' => is_string($page['@odata.nextLink'] ?? null) ? $page['@odata.nextLink'] : null];
    }

    public function reply(string $mailbox, string $messageId, string $html, bool $all, array $attachments): void
    {
        $draft = $this->graph->request('outlook.body-content-type="html"')
            ->post($this->graph->path('users', $mailbox, 'messages', $messageId, $all ? 'createReplyAll' : 'createReply'))
            ->throw()
            ->json();

        if (! is_array($draft) || ! is_string($draft['id'] ?? null)) {
            throw new InvalidResponseException('Expected Microsoft Graph to return the reply draft.');
        }

        $quoted = data_get($draft, 'body.content');
        $quoted = is_string($quoted) ? $quoted : '';

        $bodyTag = Str::match('/<body[^>]*>/i', $quoted);

        try {
            $this->patch($mailbox, $draft['id'], ['body' => [
                'contentType' => 'HTML',
                'content' => $bodyTag === '' ? $html.$quoted : Str::replaceFirst($bodyTag, $bodyTag.$html, $quoted),
            ]]);

            // Graph accepts attachments up to 3 MB in a single request; larger files need an upload session.
            foreach ($attachments as $attachment) {
                $this->graph->request()
                    ->post($this->graph->path('users', $mailbox, 'messages', $draft['id'], 'attachments'), [
                        '@odata.type' => '#microsoft.graph.fileAttachment',
                        'name' => $attachment['name'],
                        'contentType' => $attachment['contentType'],
                        'contentBytes' => base64_encode($attachment['contents']),
                    ])
                    ->throw();
            }

            $this->graph->request()->post($this->graph->path('users', $mailbox, 'messages', $draft['id'], 'send'))->throw();
        } catch (Throwable $exception) {
            rescue(fn () => $this->graph->request()->delete($this->graph->path('users', $mailbox, 'messages', $draft['id'])), report: false);

            throw $exception;
        }
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
