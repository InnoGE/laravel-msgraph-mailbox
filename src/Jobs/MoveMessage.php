<?php

declare(strict_types=1);

namespace InnoGE\LaravelMsGraphMailbox\Jobs;

use InnoGE\LaravelMsGraphMailbox\Enums\WellKnownFolder;
use InnoGE\LaravelMsGraphMailbox\Folders;
use InnoGE\LaravelMsGraphMailbox\Message;

final class MoveMessage extends MessageJob
{
    public function __construct(
        string $mailbox,
        string $messageId,
        public readonly WellKnownFolder|string $folder,
        public readonly bool $createMissing = false,
    ) {
        parent::__construct($mailbox, $messageId, uniqueBy: [Folders::key($folder), $createMissing]);
    }

    protected function perform(Message $message): void
    {
        $message->move($this->folder, $this->createMissing);
    }
}
