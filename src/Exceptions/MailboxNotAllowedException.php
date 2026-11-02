<?php

declare(strict_types=1);

namespace InnoGE\LaravelMsGraphMailbox\Exceptions;

use InvalidArgumentException;

final class MailboxNotAllowedException extends InvalidArgumentException
{
    public function __construct(string $mailbox)
    {
        parent::__construct("Mailbox [{$mailbox}] is neither observed nor listed in msgraph-mailbox.allowed_mailboxes.");
    }
}
