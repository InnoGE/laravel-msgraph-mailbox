<?php

declare(strict_types=1);

namespace InnoGE\LaravelMsGraphMailbox\Facades;

use Illuminate\Support\Facades\Facade;
use InnoGE\LaravelMsGraphMailbox\Contracts\MessageGateway;
use InnoGE\LaravelMsGraphMailbox\MailboxManager;
use InnoGE\LaravelMsGraphMailbox\Testing\MailboxFake;

/**
 * @method static \InnoGE\LaravelMsGraphMailbox\MailboxClient for(string $address)
 * @method static \InnoGE\LaravelMsGraphMailbox\MailboxClient forUnrestricted(string $address)
 * @method static bool allows(string $address)
 * @method static void observe(\Closure|iterable<array-key, mixed> $mailboxes)
 * @method static list<\InnoGE\LaravelMsGraphMailbox\ObservedMailbox> observed()
 *
 * @see MailboxManager
 */
final class Mailbox extends Facade
{
    /**
     * Replace Microsoft Graph with an in-memory fake for testing.
     */
    public static function fake(): MailboxFake
    {
        app()->instance(MessageGateway::class, $fake = new MailboxFake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return MailboxManager::class;
    }
}
