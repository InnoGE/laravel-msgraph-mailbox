<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use InnoGE\LaravelMsGraphMailbox\Enums\LifecycleEvent;
use InnoGE\LaravelMsGraphMailbox\Facades\Mailbox;
use InnoGE\LaravelMsGraphMailbox\Jobs\RefreshSubscription;
use InnoGE\LaravelMsGraphMailbox\Jobs\SyncFolder;
use InnoGE\LaravelMsGraphMailbox\Testing\WebhookPayload;

beforeEach(fn () => Bus::fake());

it('echoes the validation token as plain text', function () {
    $this->post('/webhooks/msgraph-mailbox?validationToken=%3Cscript%3Ealert(1)%3C%2Fscript%3E')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertContent('<script>alert(1)</script>');
});

it('rejects oversized validation tokens', function () {
    $this->post('/webhooks/msgraph-mailbox?validationToken='.Str::repeat('a', 1025))->assertBadRequest()->assertContent('');
    $this->post('/webhooks/msgraph-mailbox?validationToken[]=a')->assertBadRequest();
});

it('rejects malformed bodies', function (mixed $body) {
    $this->postJson('/webhooks/msgraph-mailbox', ['value' => $body])->assertBadRequest();

    Bus::assertNothingDispatched();
})->with([
    'missing' => [null],
    'empty' => [[]],
    'too many' => [array_fill(0, 101, ['subscriptionId' => 'x', 'clientState' => 'y'])],
]);

it('queues a delta sync for an authentic change notification', function () {
    Log::spy();
    $folder = mailboxFolder();

    $this->postJson('/webhooks/msgraph-mailbox', WebhookPayload::change($folder))->assertAccepted()->assertContent('');

    Bus::assertDispatched(SyncFolder::class, fn (SyncFolder $job) => $job->folder->is($folder));
    expect($folder->fresh()->last_notification_at)->not->toBeNull();
    Log::shouldNotHaveReceived('warning');
});

it('accepts validation tokens and batches at their size limit', function () {
    $this->post('/webhooks/msgraph-mailbox?validationToken='.Str::repeat('a', 1024))->assertOk();

    $this->postJson('/webhooks/msgraph-mailbox', ['value' => array_fill(0, 100, ['subscriptionId' => 'x', 'clientState' => 'y'])])->assertForbidden();
});

it('rejects forged notifications without touching graph', function () {
    Log::spy();
    $folder = mailboxFolder();
    $payload = WebhookPayload::change($folder);
    $payload['value'][0]['clientState'] = 'forged';

    $this->postJson('/webhooks/msgraph-mailbox', $payload)->assertForbidden();

    Bus::assertNothingDispatched();
    Log::shouldHaveReceived('warning')->once();
});

it('rejects notifications for unknown subscriptions or without client state', function () {
    $this->postJson('/webhooks/msgraph-mailbox', ['value' => [
        ['subscriptionId' => 'unknown', 'clientState' => 'x'],
        ['subscriptionId' => 'unknown'],
        'garbage',
    ]])->assertForbidden();
});

it('syncs and records each notified folder once per batch', function () {
    $folder = mailboxFolder();
    $payload = WebhookPayload::change($folder);
    $payload['value'] = array_fill(0, 3, $payload['value'][0]);
    DB::enableQueryLog();

    $this->postJson('/webhooks/msgraph-mailbox', $payload)->assertAccepted();

    $updates = collect(DB::getQueryLog())->filter(fn (array $query) => Str::startsWith($query['query'], 'update "mailbox_folders"'));
    expect($updates)->toHaveCount(1);
    Bus::assertDispatchedTimes(SyncFolder::class, 1);
});

it('drops invalid entries but processes the valid ones of a batch', function () {
    $folder = mailboxFolder();
    $payload = WebhookPayload::change($folder);
    array_unshift($payload['value'], ['subscriptionId' => 'stale-subscription', 'clientState' => 'x']);

    $this->postJson('/webhooks/msgraph-mailbox', $payload)->assertAccepted();

    Bus::assertDispatchedTimes(SyncFolder::class, 1);
});

it('handles lifecycle notifications', function (LifecycleEvent $event, string $job) {
    $folder = mailboxFolder();

    $this->postJson('/webhooks/msgraph-mailbox', WebhookPayload::lifecycle($folder, $event))->assertAccepted();

    Bus::assertDispatched($job);
    expect($folder->fresh()->last_notification_at)->toBeNull();
})->with([
    'missed' => [LifecycleEvent::Missed, SyncFolder::class],
    'reauthorization required' => [LifecycleEvent::ReauthorizationRequired, RefreshSubscription::class],
    'subscription removed' => [LifecycleEvent::SubscriptionRemoved, RefreshSubscription::class],
]);

it('logs and ignores unknown lifecycle events', function (mixed $event) {
    Log::spy();
    $payload = WebhookPayload::lifecycle(mailboxFolder(), LifecycleEvent::Missed);
    $payload['value'][0]['lifecycleEvent'] = $event;

    $this->postJson('/webhooks/msgraph-mailbox', $payload)->assertAccepted();

    Bus::assertNothingDispatched();
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => Str::contains($message, 'unknown Microsoft Graph lifecycle event'));
})->with(['newEvent', 42]);

it('registers no route in polling-only mode', function () {
    $this->bootWith(['msgraph-mailbox.webhooks.enabled' => false]);

    expect(Route::has('msgraph-mailbox.webhook'))->toBeFalse();
});

it('lets the app register the route itself', function () {
    $this->bootWith(['msgraph-mailbox.webhooks.register_route' => false]);

    expect(Route::has('msgraph-mailbox.webhook'))->toBeFalse();

    Route::prefix('api')->group(fn () => Mailbox::routes());

    $this->post('/api/webhooks/msgraph-mailbox?validationToken=token')->assertOk()->assertContent('token');
});

it('applies the configured path and middleware', function () {
    $this->bootWith(['msgraph-mailbox.webhooks.path' => 'graph/hook', 'msgraph-mailbox.webhooks.middleware' => ['throttle:60,1']]);

    $route = Route::getRoutes()->getByName('msgraph-mailbox.webhook');

    expect($route->uri())->toBe('graph/hook')
        ->and($route->middleware())->toBe(['throttle:60,1']);
});
