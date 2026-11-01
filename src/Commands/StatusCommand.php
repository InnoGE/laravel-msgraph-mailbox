<?php

declare(strict_types=1);

namespace InnoGE\LaravelMsGraphMailbox\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use InnoGE\LaravelMsGraphMailbox\Enums\ChangeType;
use InnoGE\LaravelMsGraphMailbox\Models\MailboxFolder;

/** @internal */
final class StatusCommand extends Command
{
    protected $signature = 'msgraph-mailbox:status';

    protected $description = 'Show the subscription and sync state of the observed folders; fails when something needs attention';

    public function handle(): int
    {
        $webhooks = config()->boolean('msgraph-mailbox.webhooks.enabled');
        $warnings = [];
        $rows = [];

        MailboxFolder::query()->orderBy('mailbox')->orderBy('folder')->each(function (MailboxFolder $folder) use ($webhooks, &$warnings, &$rows): void {
            $name = "{$folder->mailbox} {$folder->folder}";

            if ($webhooks && ($folder->subscription_expires_at === null || $folder->subscription_expires_at->isBefore(CarbonImmutable::now()->addHour()))) {
                $warnings[] = "{$name}: subscription is missing or expires within the hour (run msgraph-mailbox:subscribe).";
            }

            if ($folder->baselined_at === null) {
                $warnings[] = "{$name}: no completed sync yet (run msgraph-mailbox:sync).";
            }

            $rows[] = [
                $folder->mailbox,
                $folder->folder,
                $folder->change_types->map(fn (ChangeType $type) => match ($type) {
                    ChangeType::Created => 'received',
                    ChangeType::Updated => 'changed',
                    ChangeType::Deleted => 'removed',
                })->implode(', '),
                $webhooks ? ($folder->subscription_expires_at?->diffForHumans() ?? 'missing') : 'polling',
                $folder->last_notification_at?->diffForHumans() ?? 'never',
                $folder->last_synced_at?->diffForHumans() ?? 'never',
            ];
        });

        $this->table(['Mailbox', 'Folder', 'Events', 'Subscription expires', 'Last notification', 'Last sync'], $rows);

        foreach ($warnings as $warning) {
            $this->components->warn($warning);
        }

        return $warnings === [] ? self::SUCCESS : self::FAILURE;
    }
}
