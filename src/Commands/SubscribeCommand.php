<?php

declare(strict_types=1);

namespace InnoGE\LaravelMsGraphMailbox\Commands;

use Illuminate\Console\Command;
use InnoGE\LaravelMsGraphMailbox\Subscriptions;

/** @internal */
final class SubscribeCommand extends Command
{
    protected $signature = 'msgraph-mailbox:subscribe';

    protected $description = 'Reconcile observed folders and their Microsoft Graph subscriptions with the configured mailboxes, renewing expiring subscriptions';

    public function handle(Subscriptions $subscriptions): int
    {
        $this->table(['Mailbox', 'Folder', 'Action'], $subscriptions->reconcile());

        return self::SUCCESS;
    }
}
