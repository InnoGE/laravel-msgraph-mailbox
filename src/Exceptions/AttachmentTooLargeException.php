<?php

declare(strict_types=1);

namespace InnoGE\LaravelMsGraphMailbox\Exceptions;

use RuntimeException;

final class AttachmentTooLargeException extends RuntimeException
{
    public function __construct(string $attachmentId, int $maxBytes)
    {
        parent::__construct("Attachment [{$attachmentId}] exceeds msgraph-mailbox.attachments.max_bytes ({$maxBytes} bytes).");
    }
}
