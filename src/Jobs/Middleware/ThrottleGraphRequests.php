<?php

declare(strict_types=1);

namespace InnoGE\LaravelMsGraphMailbox\Jobs\Middleware;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use InnoGE\LaravelMsGraphMailbox\ObservedMailbox;
use LogicException;

final readonly class ThrottleGraphRequests
{
    private const int DEFAULT_RETRY_AFTER = 30;

    private const int MAX_RETRY_AFTER = 3600;

    public function __construct(private string $mailbox) {}

    /**
     * Process the job within the mailbox's concurrency limit.
     *
     * @param  Closure(object): mixed  $next
     */
    public function handle(object $job, Closure $next): void
    {
        Cache::funnel('msgraph-mailbox:'.hash('xxh128', ObservedMailbox::normalize($this->mailbox)))
            ->limit(config()->integer('msgraph-mailbox.throttle.concurrency_per_mailbox'))
            ->releaseAfter(90)
            ->block(0)
            ->then(function () use ($job, $next): void {
                try {
                    $next($job);
                } catch (ConnectionException) {
                    $this->release($job, self::DEFAULT_RETRY_AFTER);
                } catch (RequestException $exception) {
                    throw_unless($exception->response->status() === 429 || $exception->response->serverError(), $exception);

                    $retryAfter = $exception->response->header('Retry-After');

                    $this->release($job, is_numeric($retryAfter) ? (int) $retryAfter : self::DEFAULT_RETRY_AFTER);
                }
            }, fn () => $this->release($job, random_int(5, 15)));
    }

    private function release(object $job, int $seconds): void
    {
        if (! method_exists($job, 'release')) {
            throw new LogicException('ThrottleGraphRequests needs a queued job or listener that can be released (InteractsWithQueue).');
        }

        $job->release(max(1, min(self::MAX_RETRY_AFTER, $seconds)));
    }
}
