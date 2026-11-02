<?php

declare(strict_types=1);

namespace InnoGE\LaravelMsGraphMailbox;

use InnoGE\LaravelMsGraphMailbox\Contracts\MessageGateway;

final readonly class MailboxClient
{
    public function __construct(
        public string $address,
        private MessageGateway $gateway,
    ) {}

    /**
     * Get the message with the given id.
     */
    public function message(string $messageId): Message
    {
        return new Message($this->address, $messageId, $this->gateway);
    }
}
