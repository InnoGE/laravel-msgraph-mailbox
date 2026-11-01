<?php

declare(strict_types=1);

namespace InnoGE\LaravelMsGraphMailbox\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use InnoGE\LaravelMsGraphMailbox\Jobs\Middleware\ThrottleGraphRequests;

/** @internal */
abstract class GraphJob implements ShouldQueue
{
    use Queueable;

    public int $maxExceptions;

    public function __construct()
    {
        $connection = config('msgraph-mailbox.queue.connection');
        $queue = config('msgraph-mailbox.queue.queue');

        $this->maxExceptions = config()->integer('msgraph-mailbox.throttle.max_exceptions');
        $this->onConnection(is_string($connection) ? $connection : null);
        $this->onQueue(is_string($queue) ? $queue : null);
    }

    abstract protected function mailbox(): string;

    public function retryUntil(): CarbonImmutable
    {
        return CarbonImmutable::now()->addHours(config()->integer('msgraph-mailbox.throttle.retry_until_hours'));
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new ThrottleGraphRequests($this->mailbox())];
    }
}
