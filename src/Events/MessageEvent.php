<?php

declare(strict_types=1);

namespace InnoGE\LaravelMsGraphMailbox\Events;

use InnoGE\LaravelMsGraphMailbox\Message;

/**
 * @property-read string $mailbox
 * @property-read string $folder
 * @property-read string $messageId
 */
interface MessageEvent
{
    public function message(): Message;
}
