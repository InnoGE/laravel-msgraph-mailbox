<?php

declare(strict_types=1);

namespace InnoGE\LaravelMsGraphMailbox;

use Closure;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Str;
use InnoGE\LaravelMsGraphMailbox\Exceptions\AttachmentTooLargeException;
use Psr\Http\Message\StreamInterface;

final readonly class Attachment
{
    /**
     * @param  Closure(): StreamInterface  $content
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $contentType,
        public int $size,
        public bool $isInline,
        public string $type,
        private Closure $content,
    ) {}

    /**
     * @param  array<string, mixed>  $attachment
     * @param  Closure(): StreamInterface  $content
     */
    public static function fromGraph(array $attachment, Closure $content): self
    {
        $type = $attachment['@odata.type'] ?? null;

        return new self(
            id: is_string($attachment['id'] ?? null) ? $attachment['id'] : '',
            name: is_string($attachment['name'] ?? null) ? $attachment['name'] : '',
            contentType: is_string($attachment['contentType'] ?? null) ? $attachment['contentType'] : null,
            size: is_int($attachment['size'] ?? null) ? $attachment['size'] : 0,
            isInline: ($attachment['isInline'] ?? false) === true,
            type: is_string($type) ? Str::remove(['#microsoft.graph.', 'Attachment'], $type) : 'file',
            content: $content,
        );
    }

    /**
     * Stream the content of the attachment.
     */
    public function stream(): StreamInterface
    {
        $maxBytes = self::maxBytes();
        if ($maxBytes !== null && $this->size > $maxBytes) {
            throw new AttachmentTooLargeException($this->id, $maxBytes);
        }

        return ($this->content)();
    }

    /**
     * Get the content of the attachment.
     */
    public function contents(): string
    {
        $maxBytes = self::maxBytes();
        $contents = Utils::copyToString($this->stream(), $maxBytes === null ? -1 : $maxBytes + 1);

        if ($maxBytes !== null && strlen($contents) > $maxBytes) {
            throw new AttachmentTooLargeException($this->id, $maxBytes);
        }

        return $contents;
    }

    /**
     * Get the file name, stripped of paths and control characters so it is safe to use on disk.
     */
    public function safeFilename(): string
    {
        $name = Str::of($this->name)
            ->replace('\\', '/')
            ->basename()
            ->replaceMatches('/[\x00-\x1F\x7F]/u', '')
            ->trim(" .\t");

        return $name->isEmpty() ? 'attachment' : $name->value();
    }

    private static function maxBytes(): ?int
    {
        $maxBytes = config('msgraph-mailbox.attachments.max_bytes');

        return is_numeric($maxBytes) ? (int) $maxBytes : null;
    }
}
