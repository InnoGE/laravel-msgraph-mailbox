<?php

declare(strict_types=1);

namespace InnoGE\LaravelMsGraphMailbox\Jobs;

use InnoGE\LaravelMsGraphMailbox\Message;

final class PermanentlyDeleteMessage extends MessageJob
{
    protected function perform(Message $message): void
    {
        $message->permanentDelete();
    }
}
