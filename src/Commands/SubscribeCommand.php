<?php

declare(strict_types=1);

namespace InnoGE\LaravelMsGraphMailbox\Commands;

use Illuminate\Console\Command;
use InnoGE\LaravelMsGraphMailbox\MailboxManager;
use InnoGE\LaravelMsGraphMailbox\Models\MailboxFolder;
use InnoGE\LaravelMsGraphMailbox\Subscriptions;

/** @internal */
final class SubscribeCommand extends Command
{
    protected $signature = 'msgraph-mailbox:subscribe';

    protected $description = 'Reconcile observed folders and their Microsoft Graph subscriptions with the configured mailboxes, renewing expiring subscriptions';

    public function handle(MailboxManager $mailboxes): int
    {
        if ($mailboxes->observed() === [] && ! MailboxFolder::query()->exists()) {
            $this->components->info('No mailboxes are observed.');

            return self::SUCCESS;
        }

        $actions = $this->laravel->make(Subscriptions::class)->reconcile();

        $this->table(['Mailbox', 'Folder', 'Action'], array_map(fn (array $action) => [$action['mailbox'], $action['folder'], $action['action']], $actions));

        return collect($actions)->contains('failed', true) ? self::FAILURE : self::SUCCESS;
    }
}
