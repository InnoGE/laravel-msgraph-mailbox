<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use InnoGE\LaravelMsGraphMailbox\Jobs\Middleware\ThrottleGraphRequests;

function throttledJob(): object
{
    return new class
    {
        public ?int $releasedFor = null;

        public function release(int $seconds): void
        {
            $this->releasedFor = $seconds;
        }
    };
}

function graphFailure(int $status, array $headers = []): RequestException
{
    return new RequestException(new Response(new Psr7Response($status, $headers)));
}

it('runs the job when a slot is free', function () {
    $ran = false;

    (new ThrottleGraphRequests('support@example.com'))->handle(throttledJob(), function () use (&$ran) {
        $ran = true;
    });

    expect($ran)->toBeTrue();
});

it('releases the job while all slots of the mailbox are taken', function () {
    config()->set('msgraph-mailbox.throttle.concurrency_per_mailbox', 1);
    $waiting = throttledJob();

    (new ThrottleGraphRequests('support@example.com'))->handle(throttledJob(), function () use ($waiting) {
        (new ThrottleGraphRequests('SUPPORT@example.com'))->handle($waiting, fn () => throw new LogicException('must not run'));
    });

    expect($waiting->releasedFor)->toBeBetween(5, 15);
});

it('does not share slots between mailboxes', function () {
    config()->set('msgraph-mailbox.throttle.concurrency_per_mailbox', 1);
    $ran = false;

    (new ThrottleGraphRequests('support@example.com'))->handle(throttledJob(), function () use (&$ran) {
        (new ThrottleGraphRequests('info@example.com'))->handle(throttledJob(), function () use (&$ran) {
            $ran = true;
        });
    });

    expect($ran)->toBeTrue();
});

it('releases on throttling, server and connection errors', function (Throwable $error, int $expected) {
    $job = throttledJob();

    (new ThrottleGraphRequests('support@example.com'))->handle($job, fn () => throw $error);

    expect($job->releasedFor)->toBe($expected);
})->with([
    '429 with retry-after' => [fn () => graphFailure(429, ['Retry-After' => '120']), 120],
    '503 without retry-after' => [fn () => graphFailure(503), 30],
    'http-date retry-after' => [fn () => graphFailure(429, ['Retry-After' => 'Wed, 21 Oct 2026 07:28:00 GMT']), 30],
    'absurd retry-after' => [fn () => graphFailure(429, ['Retry-After' => '999999999']), 3600],
    'zero retry-after' => [fn () => graphFailure(429, ['Retry-After' => '0']), 1],
    'connection error' => [fn () => new ConnectionException('timeout'), 30],
]);

it('rethrows client errors', function (int $status) {
    (new ThrottleGraphRequests('support@example.com'))->handle(throttledJob(), fn () => throw graphFailure($status));
})->with([403, 404, 412])->throws(RequestException::class);

it('fails loudly for jobs that cannot be released', function () {
    (new ThrottleGraphRequests('support@example.com'))->handle(new stdClass, fn () => throw new ConnectionException('timeout'));
})->throws(LogicException::class, 'needs a queued job or listener that can be released');
