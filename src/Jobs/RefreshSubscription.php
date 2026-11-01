<?php

declare(strict_types=1);

namespace InnoGE\LaravelMsGraphMailbox\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use InnoGE\LaravelMsGraphMailbox\Subscriptions;

/** @internal */
final class RefreshSubscription extends FolderJob implements ShouldBeUnique
{
    public int $uniqueFor = 300;

    public function handle(Subscriptions $subscriptions): void
    {
        $subscriptions->refresh($this->folder);

        SyncFolder::dispatch($this->folder);
    }
}
