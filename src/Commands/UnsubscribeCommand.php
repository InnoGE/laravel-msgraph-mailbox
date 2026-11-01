<?php

declare(strict_types=1);

namespace InnoGE\LaravelMsGraphMailbox\Commands;

use Illuminate\Console\Command;
use InnoGE\LaravelMsGraphMailbox\Models\MailboxFolder;
use InnoGE\LaravelMsGraphMailbox\Subscriptions;

/** @internal */
final class UnsubscribeCommand extends Command
{
    protected $signature = 'msgraph-mailbox:unsubscribe {mailbox? : Only unsubscribe this mailbox}';

    protected $description = 'Delete the Microsoft Graph subscriptions (the delta sync state is kept)';

    public function handle(Subscriptions $subscriptions): int
    {
        $mailbox = $this->argument('mailbox');

        $deleted = 0;

        MailboxFolder::query()
            ->whereNotNull('subscription_id')
            ->forMailbox(is_string($mailbox) ? $mailbox : null)
            ->eachById(function (MailboxFolder $folder) use ($subscriptions, &$deleted): void {
                $subscriptions->unsubscribe($folder);
                $deleted++;
            });

        $this->components->info("Deleted {$deleted} subscription(s).");

        return self::SUCCESS;
    }
}
