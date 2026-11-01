<?php

declare(strict_types=1);

namespace InnoGE\LaravelMsGraphMailbox\Contracts;

use InnoGE\LaravelMsGraphMailbox\Enums\BodyType;
use InnoGE\LaravelMsGraphMailbox\Enums\FlagStatus;
use InnoGE\LaravelMsGraphMailbox\Enums\WellKnownFolder;
use Psr\Http\Message\StreamInterface;

/** @internal */
interface MessageGateway
{
    /**
     * @return array<string, mixed>
     */
    public function find(string $mailbox, string $messageId): array;

    public function body(string $mailbox, string $messageId, BodyType $type, bool $unique): string;

    /**
     * @return list<array<string, mixed>>
     */
    public function attachments(string $mailbox, string $messageId): array;

    public function attachmentContent(string $mailbox, string $messageId, string $attachmentId): StreamInterface;

    public function mime(string $mailbox, string $messageId): StreamInterface;

    public function move(string $mailbox, string $messageId, WellKnownFolder|string $folder, bool $createMissing): void;

    public function copy(string $mailbox, string $messageId, WellKnownFolder|string $folder, bool $createMissing): string;

    public function markRead(string $mailbox, string $messageId, bool $read): void;

    public function flag(string $mailbox, string $messageId, FlagStatus $status): void;

    /**
     * @param  list<string>  $categories
     */
    public function setCategories(string $mailbox, string $messageId, array $categories): void;

    public function delete(string $mailbox, string $messageId): void;

    public function permanentDelete(string $mailbox, string $messageId): void;
}
