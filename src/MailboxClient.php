<?php

declare(strict_types=1);

namespace InnoGE\LaravelMsGraphMailbox;

use DateTimeInterface;
use InnoGE\LaravelMsGraphMailbox\Contracts\MessageGateway;
use InnoGE\LaravelMsGraphMailbox\Enums\WellKnownFolder;

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

    /**
     * Get one page of a folder's messages, newest first. Pass the page's "next" value to get the following page.
     */
    public function messages(WellKnownFolder|string $folder = WellKnownFolder::Inbox, ?DateTimeInterface $since = null, ?string $next = null): MessagePage
    {
        $page = $this->gateway->page($this->address, $folder, $since, $next);
        $messages = [];

        foreach ($page['messages'] as $message) {
            if (is_string($message['id'] ?? null)) {
                $messages[] = new Message($this->address, $message['id'], $this->gateway, $message);
            }
        }

        return new MessagePage($messages, $page['next']);
    }
}
