<?php

declare(strict_types=1);

namespace InnoGE\LaravelMsGraphMailbox\Jobs;

use InnoGE\LaravelMsGraphMailbox\Enums\FlagStatus;
use InnoGE\LaravelMsGraphMailbox\Message;

final class FlagMessage extends MessageJob
{
    public function __construct(
        string $mailbox,
        string $messageId,
        public readonly FlagStatus $status = FlagStatus::Flagged,
    ) {
        parent::__construct($mailbox, $messageId, uniqueBy: [$status->value]);
    }

    protected function perform(Message $message): void
    {
        $message->flag($this->status);
    }
}
