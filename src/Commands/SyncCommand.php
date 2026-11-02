<?php

declare(strict_types=1);

namespace InnoGE\LaravelMsGraphMailbox\Commands;

use Illuminate\Console\Command;
use InnoGE\LaravelMsGraphMailbox\Jobs\SyncFolder;
use InnoGE\LaravelMsGraphMailbox\Models\MailboxFolder;

/** @internal */
final class SyncCommand extends Command
{
    protected $signature = 'msgraph-mailbox:sync
        {mailbox? : Only sync this mailbox}
        {--folder= : Only sync this folder (as configured, e.g. inbox or Support/Escalations)}
        {--reset : Drop the delta link and walk the folder again, reporting what changed meanwhile}';

    protected $description = 'Queue a delta sync of the observed folders';

    public function handle(): int
    {
        $mailbox = $this->argument('mailbox');
        $folder = $this->option('folder');

        $queued = 0;

        MailboxFolder::query()
            ->forMailbox(is_string($mailbox) ? $mailbox : null)
            ->when(is_string($folder) ? $folder : null, fn ($query, string $folder) => $query->where('folder', $folder))
            ->each(function (MailboxFolder $folder) use (&$queued): void {
                if ($this->option('reset')) {
                    $folder->restartWalk();
                }

                SyncFolder::dispatch($folder);
                $queued++;
            });

        $this->components->info("Queued the sync of {$queued} folder(s).");

        return self::SUCCESS;
    }
}
