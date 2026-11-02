<?php

declare(strict_types=1);

namespace InnoGE\LaravelMsGraphMailbox\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use InnoGE\LaravelMsGraphMailbox\Models\MailboxFolder;
use InnoGE\LaravelMsGraphMailbox\Sync\DeltaSync;

/** @internal */
final class SyncFolder extends FolderJob implements ShouldBeUniqueUntilProcessing
{
    public const int PAGES_PER_RUN = 50;

    public function __construct(MailboxFolder $folder, public bool $reset = false)
    {
        parent::__construct($folder);
    }

    public function uniqueId(): string
    {
        return parent::uniqueId().($this->reset ? ':reset' : '');
    }

    public function handle(DeltaSync $sync): void
    {
        if ($this->reset) {
            $this->folder->restartWalk();
            self::dispatch($this->folder);

            return;
        }

        if ($sync->run($this->folder, self::PAGES_PER_RUN)) {
            self::dispatch($this->folder);
        }
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping((string) $this->folder->id))->releaseAfter(30)->expireAfter(900),
            ...parent::middleware(),
        ];
    }
}
