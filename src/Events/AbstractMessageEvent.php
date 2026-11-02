<?php

declare(strict_types=1);

namespace InnoGE\LaravelMsGraphMailbox\Events;

use Illuminate\Foundation\Events\Dispatchable;
use InnoGE\LaravelMsGraphMailbox\Facades\Mailbox;
use InnoGE\LaravelMsGraphMailbox\Message;

abstract readonly class AbstractMessageEvent implements MessageEvent
{
    use Dispatchable;

    public function __construct(
        public string $mailbox,
        public string $folder,
        public string $messageId,
    ) {}

    /**
     * Get the message, loaded lazily on first access.
     */
    public function message(): Message
    {
        return Mailbox::for($this->mailbox)->message($this->messageId);
    }
}
