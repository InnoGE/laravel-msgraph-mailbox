<?php

declare(strict_types=1);

namespace InnoGE\LaravelMsGraphMailbox;

final readonly class MessagePage
{
    /**
     * @param  list<Message>  $messages
     * @param  string|null  $next  Pass it to MailboxClient::messages() to get the following page, null on the last page.
     */
    public function __construct(
        public array $messages,
        public ?string $next,
    ) {}
}
