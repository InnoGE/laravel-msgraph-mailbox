<?php

declare(strict_types=1);

namespace InnoGE\LaravelMsGraphMailbox;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InnoGE\LaravelMsGraphMailbox\Contracts\MessageGateway;
use InnoGE\LaravelMsGraphMailbox\Enums\BodyType;
use InnoGE\LaravelMsGraphMailbox\Enums\FlagStatus;
use InnoGE\LaravelMsGraphMailbox\Enums\WellKnownFolder;
use LogicException;
use Psr\Http\Message\StreamInterface;

final class Message
{
    /** @var array<string, mixed>|null */
    private ?array $data = null;

    /** @var array<string, string> */
    private array $bodies = [];

    /** @var list<Attachment>|null */
    private ?array $attachments = null;

    public function __construct(
        public readonly string $mailbox,
        public readonly string $id,
        private readonly MessageGateway $gateway,
    ) {}

    /**
     * Get the subject of the message.
     */
    public function subject(): ?string
    {
        return $this->string('subject');
    }

    /**
     * Get the address the message is from.
     */
    public function from(): ?EmailAddress
    {
        return EmailAddress::fromGraph($this->get('from'));
    }

    /**
     * Get the address that sent the message on behalf of the "from" address.
     */
    public function sender(): ?EmailAddress
    {
        return EmailAddress::fromGraph($this->get('sender'));
    }

    /**
     * Get the recipients of the message.
     *
     * @return list<EmailAddress>
     */
    public function to(): array
    {
        return $this->recipients('toRecipients');
    }

    /**
     * Get the carbon copy recipients of the message.
     *
     * @return list<EmailAddress>
     */
    public function cc(): array
    {
        return $this->recipients('ccRecipients');
    }

    /**
     * Get the time the message was received.
     */
    public function receivedAt(): ?CarbonImmutable
    {
        $receivedAt = $this->string('receivedDateTime');

        return $receivedAt === null ? null : CarbonImmutable::parse($receivedAt);
    }

    /**
     * Get the message's "Message-ID" header, which identifies it across mailboxes.
     */
    public function internetMessageId(): ?string
    {
        return $this->string('internetMessageId');
    }

    /**
     * Get the id of the folder the message is in.
     */
    public function parentFolderId(): ?string
    {
        return $this->string('parentFolderId');
    }

    /**
     * Determine if the message has been read.
     */
    public function isRead(): bool
    {
        return $this->get('isRead') === true;
    }

    /**
     * Get the follow-up flag status of the message.
     */
    public function flagStatus(): FlagStatus
    {
        $status = data_get($this->get('flag'), 'flagStatus');

        return FlagStatus::tryFrom(is_string($status) ? $status : '') ?? FlagStatus::NotFlagged;
    }

    /**
     * Get the categories of the message.
     *
     * @return list<string>
     */
    public function categories(): array
    {
        $categories = $this->get('categories');

        return array_values(array_filter(is_array($categories) ? $categories : [], is_string(...)));
    }

    /**
     * Get all of the message's internet headers.
     *
     * @return list<array{name: string, value: string}>
     */
    public function headers(): array
    {
        $headers = [];

        foreach ((array) $this->get('internetMessageHeaders') as $header) {
            $name = data_get($header, 'name');
            $value = data_get($header, 'value');

            if (is_string($name)) {
                $headers[] = ['name' => $name, 'value' => is_string($value) ? $value : ''];
            }
        }

        return $headers;
    }

    /**
     * Get the value of the given header.
     */
    public function header(string $name): ?string
    {
        foreach ($this->headers() as $header) {
            if (Str::lower($header['name']) === Str::lower($name)) {
                return $header['value'];
            }
        }

        return null;
    }

    /**
     * Get the body of the message.
     */
    public function body(BodyType $type = BodyType::Text, bool $unique = false): string
    {
        return $this->bodies[$type->value.($unique ? ':unique' : '')] ??= $this->gateway->body($this->mailbox, $this->id, $type, $unique);
    }

    /**
     * Get the attachments of the message, without downloading their content.
     *
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return $this->attachments ??= array_map(
            fn (array $attachment): Attachment => Attachment::fromGraph(
                $attachment,
                fn (): StreamInterface => $this->gateway->attachmentContent($this->mailbox, $this->id, is_string($attachment['id'] ?? null) ? $attachment['id'] : ''),
            ),
            $this->gateway->attachments($this->mailbox, $this->id),
        );
    }

    /**
     * Get the raw RFC 822 message.
     */
    public function mime(): StreamInterface
    {
        return $this->gateway->mime($this->mailbox, $this->id);
    }

    /**
     * Determine if the message was sent automatically, like an out-of-office reply or a bounce.
     */
    public function isAutoSubmitted(): bool
    {
        $autoSubmitted = Str::of((string) $this->header('Auto-Submitted'))->trim()->lower()->value();

        return ($autoSubmitted !== '' && $autoSubmitted !== 'no')
            || $this->header('X-Auto-Response-Suppress') !== null
            || in_array(Str::of((string) $this->header('Precedence'))->trim()->lower()->value(), ['bulk', 'junk', 'list', 'auto_reply'], true);
    }

    /**
     * Determine if the mailbox sent the message itself.
     */
    public function isFromSelf(): bool
    {
        return in_array($this->mailbox, array_map(
            fn (?EmailAddress $address) => Str::lower((string) $address?->address),
            [$this->from(), $this->sender()],
        ), true);
    }

    /**
     * Move the message to another folder of the same mailbox.
     */
    public function move(WellKnownFolder|string $folder, bool $createMissing = false): static
    {
        $this->gateway->move($this->mailbox, $this->id, $folder, $createMissing);

        return $this->refresh();
    }

    /**
     * Move the message to the mailbox's archive folder.
     */
    public function archive(): static
    {
        return $this->move(WellKnownFolder::Archive);
    }

    /**
     * Copy the message to another folder of the same mailbox and get the copy.
     */
    public function copy(WellKnownFolder|string $folder, bool $createMissing = false): self
    {
        return new self($this->mailbox, $this->gateway->copy($this->mailbox, $this->id, $folder, $createMissing), $this->gateway);
    }

    /**
     * Mark the message as read.
     */
    public function markRead(): static
    {
        $this->gateway->markRead($this->mailbox, $this->id, true);

        return $this->refresh();
    }

    /**
     * Mark the message as unread.
     */
    public function markUnread(): static
    {
        $this->gateway->markRead($this->mailbox, $this->id, false);

        return $this->refresh();
    }

    /**
     * Set the follow-up flag of the message.
     */
    public function flag(FlagStatus $status = FlagStatus::Flagged): static
    {
        $this->gateway->flag($this->mailbox, $this->id, $status);

        return $this->refresh();
    }

    /**
     * Remove the follow-up flag of the message.
     */
    public function unflag(): static
    {
        return $this->flag(FlagStatus::NotFlagged);
    }

    /**
     * Add the given categories to the message, keeping the ones it already has.
     */
    public function categorize(string ...$categories): static
    {
        return $this->setCategories(array_values(array_unique([...$this->categories(), ...$categories])));
    }

    /**
     * Replace the categories of the message.
     *
     * @param  list<string>  $categories
     */
    public function setCategories(array $categories): static
    {
        $this->gateway->setCategories($this->mailbox, $this->id, $categories);

        return $this->refresh();
    }

    /**
     * Move the message to Deleted Items, where users can still restore it.
     */
    public function delete(): void
    {
        $this->gateway->delete($this->mailbox, $this->id);
        $this->refresh();
    }

    /**
     * Delete the message permanently.
     */
    public function permanentDelete(): void
    {
        $this->gateway->permanentDelete($this->mailbox, $this->id);
        $this->refresh();
    }

    /**
     * Forget the loaded properties, so the next access fetches them again.
     */
    public function refresh(): static
    {
        $this->data = null;

        return $this;
    }

    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw new LogicException('Messages cannot be serialized. Pass the mailbox and message id instead.');
    }

    private function get(string $key): mixed
    {
        $this->data ??= $this->gateway->find($this->mailbox, $this->id);

        return $this->data[$key] ?? null;
    }

    private function string(string $key): ?string
    {
        $value = $this->get($key);

        return is_string($value) ? $value : null;
    }

    /**
     * @return list<EmailAddress>
     */
    private function recipients(string $key): array
    {
        return array_values(array_filter(array_map(EmailAddress::fromGraph(...), (array) $this->get($key))));
    }
}
