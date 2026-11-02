<?php

declare(strict_types=1);

namespace InnoGE\LaravelMsGraphMailbox\Testing;

use Closure;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Str;
use InnoGE\LaravelMsGraphMailbox\Contracts\MessageGateway;
use InnoGE\LaravelMsGraphMailbox\Enums\BodyType;
use InnoGE\LaravelMsGraphMailbox\Enums\FlagStatus;
use InnoGE\LaravelMsGraphMailbox\Enums\WellKnownFolder;
use InnoGE\LaravelMsGraphMailbox\Events\MessageReceived;
use InnoGE\LaravelMsGraphMailbox\Folders;
use InnoGE\LaravelMsGraphMailbox\Message;
use InnoGE\LaravelMsGraphMailbox\ObservedMailbox;
use PHPUnit\Framework\Assert;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

final class MailboxFake implements MessageGateway
{
    /** @var array<string, array<string, mixed>> */
    private array $messages = [];

    /** @var list<array{action: string, mailbox: string, id: string, argument: mixed}> */
    private array $actions = [];

    /**
     * Add a message to the fake mailbox without dispatching an event.
     *
     * @param  array<string, mixed>  $message
     */
    public function addMessage(string $mailbox, string $messageId, array $message = []): self
    {
        $this->messages[$this->key($mailbox, $messageId)] = ['id' => $messageId, ...$message];

        return $this;
    }

    /**
     * Add a message to the fake mailbox and dispatch the MessageReceived event.
     *
     * @param  array<string, mixed>  $message
     */
    public function receive(string $mailbox, array $message = [], WellKnownFolder|string $folder = WellKnownFolder::Inbox): Message
    {
        $messageId = is_string($message['id'] ?? null) ? $message['id'] : (string) Str::uuid();
        $mailbox = ObservedMailbox::normalize($mailbox);

        $this->addMessage($mailbox, $messageId, $message);

        event(new MessageReceived($mailbox, Folders::key(Folders::normalize($folder)), $messageId));

        return new Message($mailbox, $messageId, $this);
    }

    public function find(string $mailbox, string $messageId): array
    {
        return $this->messages[$this->key($mailbox, $messageId)]
            ?? throw new RuntimeException("No fake message [{$messageId}] was added for mailbox [{$mailbox}].");
    }

    public function body(string $mailbox, string $messageId, BodyType $type, bool $unique): string
    {
        return $this->string(data_get($this->find($mailbox, $messageId), ($unique ? 'uniqueBody' : 'body').'.content'));
    }

    public function attachments(string $mailbox, string $messageId): array
    {
        /** @var list<array<string, mixed>> */
        return array_values(array_map(fn (mixed $attachment) => [
            '@odata.type' => '#microsoft.graph.fileAttachment',
            ...(array) $attachment,
            'size' => strlen($this->contentBytes($attachment)),
        ], (array) ($this->find($mailbox, $messageId)['attachments'] ?? [])));
    }

    public function attachmentContent(string $mailbox, string $messageId, string $attachmentId): StreamInterface
    {
        foreach ((array) ($this->find($mailbox, $messageId)['attachments'] ?? []) as $attachment) {
            if (data_get($attachment, 'id') === $attachmentId) {
                return Utils::streamFor($this->contentBytes($attachment));
            }
        }

        throw new RuntimeException("No fake attachment [{$attachmentId}] was added to message [{$messageId}].");
    }

    public function mime(string $mailbox, string $messageId): StreamInterface
    {
        return Utils::streamFor($this->string($this->find($mailbox, $messageId)['mime'] ?? null));
    }

    public function move(string $mailbox, string $messageId, WellKnownFolder|string $folder, bool $createMissing): void
    {
        $this->record('move', $mailbox, $messageId, Folders::key(Folders::normalize($folder)));
    }

    public function copy(string $mailbox, string $messageId, WellKnownFolder|string $folder, bool $createMissing): string
    {
        $this->record('copy', $mailbox, $messageId, Folders::key(Folders::normalize($folder)));

        return (string) Str::uuid();
    }

    public function markRead(string $mailbox, string $messageId, bool $read): void
    {
        $this->record('markRead', $mailbox, $messageId, $read);
    }

    public function flag(string $mailbox, string $messageId, FlagStatus $status): void
    {
        $this->record('flag', $mailbox, $messageId, $status);
    }

    public function setCategories(string $mailbox, string $messageId, array $categories): void
    {
        $this->record('setCategories', $mailbox, $messageId, $categories);
    }

    public function delete(string $mailbox, string $messageId): void
    {
        $this->record('delete', $mailbox, $messageId, null);
    }

    public function permanentDelete(string $mailbox, string $messageId): void
    {
        $this->record('permanentDelete', $mailbox, $messageId, null);
    }

    /**
     * Assert that the message was moved to the given folder.
     */
    public function assertMoved(Message|string $message, WellKnownFolder|string $folder, ?string $mailbox = null): void
    {
        $folder = Folders::key(Folders::normalize($folder));

        $this->assertPerformed('move', $message, fn (mixed $moved) => $moved === $folder, "was not moved to [{$folder}]", $mailbox);
    }

    /**
     * Assert that the message was copied to the given folder.
     */
    public function assertCopied(Message|string $message, WellKnownFolder|string $folder, ?string $mailbox = null): void
    {
        $folder = Folders::key(Folders::normalize($folder));

        $this->assertPerformed('copy', $message, fn (mixed $copied) => $copied === $folder, "was not copied to [{$folder}]", $mailbox);
    }

    /**
     * Assert that the message was moved to Deleted Items.
     */
    public function assertDeleted(Message|string $message, ?string $mailbox = null): void
    {
        $this->assertPerformed('delete', $message, fn () => true, 'was not deleted', $mailbox);
    }

    /**
     * Assert that the message was deleted permanently.
     */
    public function assertPermanentlyDeleted(Message|string $message, ?string $mailbox = null): void
    {
        $this->assertPerformed('permanentDelete', $message, fn () => true, 'was not permanently deleted', $mailbox);
    }

    /**
     * Assert that the message was marked as read.
     */
    public function assertMarkedRead(Message|string $message, ?string $mailbox = null): void
    {
        $this->assertPerformed('markRead', $message, fn (mixed $read) => $read === true, 'was not marked as read', $mailbox);
    }

    /**
     * Assert that the message was marked as unread.
     */
    public function assertMarkedUnread(Message|string $message, ?string $mailbox = null): void
    {
        $this->assertPerformed('markRead', $message, fn (mixed $read) => $read === false, 'was not marked as unread', $mailbox);
    }

    /**
     * Assert that the message was flagged with the given status.
     */
    public function assertFlagged(Message|string $message, FlagStatus $status = FlagStatus::Flagged, ?string $mailbox = null): void
    {
        $this->assertPerformed('flag', $message, fn (mixed $flagged) => $flagged === $status, "was not flagged as [{$status->value}]", $mailbox);
    }

    /**
     * Assert that the message was given all of the given categories.
     *
     * @param  list<string>  $categories
     */
    public function assertCategorized(Message|string $message, array $categories, ?string $mailbox = null): void
    {
        $this->assertPerformed(
            'setCategories',
            $message,
            fn (mixed $set) => is_array($set) && array_diff($categories, array_filter($set, is_string(...))) === [],
            'was not categorized as ['.implode(', ', $categories).']',
            $mailbox,
        );
    }

    /**
     * Assert that no message was acted on.
     */
    public function assertNothingPerformed(): void
    {
        Assert::assertSame([], $this->actions, 'Unexpected mailbox actions were performed.');
    }

    /**
     * @param  Closure(mixed): bool  $matches
     */
    private function assertPerformed(string $action, Message|string $message, Closure $matches, string $failure, ?string $mailbox): void
    {
        $messageId = $message instanceof Message ? $message->id : $message;

        $performed = array_filter($this->actions, fn (array $performed) => $performed['action'] === $action
            && $performed['id'] === $messageId
            && $matches($performed['argument'])
            && ($mailbox === null || $performed['mailbox'] === ObservedMailbox::normalize($mailbox)));

        Assert::assertNotEmpty($performed, "Message [{$messageId}] {$failure}".($mailbox === null ? '.' : " in mailbox [{$mailbox}]."));
    }

    private function record(string $action, string $mailbox, string $messageId, mixed $argument): void
    {
        $this->actions[] = ['action' => $action, 'mailbox' => ObservedMailbox::normalize($mailbox), 'id' => $messageId, 'argument' => $argument];
    }

    private function key(string $mailbox, string $messageId): string
    {
        return ObservedMailbox::normalize($mailbox)."\0".$messageId;
    }

    private function contentBytes(mixed $attachment): string
    {
        return (string) base64_decode($this->string(data_get($attachment, 'contentBytes')), true);
    }

    private function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
