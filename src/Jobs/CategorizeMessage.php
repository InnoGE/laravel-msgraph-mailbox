<?php

declare(strict_types=1);

namespace InnoGE\LaravelMsGraphMailbox\Jobs;

use InnoGE\LaravelMsGraphMailbox\Message;

final class CategorizeMessage extends MessageJob
{
    /**
     * @param  list<string>  $categories
     */
    public function __construct(
        string $mailbox,
        string $messageId,
        public readonly array $categories,
    ) {
        parent::__construct($mailbox, $messageId, uniqueBy: $categories);
    }

    protected function perform(Message $message): void
    {
        $message->categorize(...$this->categories);
    }
}
