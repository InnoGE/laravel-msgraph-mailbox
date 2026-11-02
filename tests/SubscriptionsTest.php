<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InnoGE\LaravelMsGraphMailbox\Enums\ChangeType;
use InnoGE\LaravelMsGraphMailbox\Jobs\RefreshSubscription;
use InnoGE\LaravelMsGraphMailbox\Jobs\SyncFolder;
use InnoGE\LaravelMsGraphMailbox\Models\MailboxFolder;
use InnoGE\LaravelMsGraphMailbox\Subscriptions;

function subscriptionRoutes(array $routes = []): void
{
    fakeGraph([
        'GET /users/support@example.com/mailFolders/inbox' => Http::response(['id' => 'inbox-id']),
        'POST /subscriptions' => Http::response(['id' => 'sub-1'], 201),
        'PATCH /subscriptions/*' => Http::response([]),
        'DELETE /subscriptions/*' => Http::response([], 204),
        ...$routes,
    ]);
}

it('creates the observed folder and its subscription', function () {
    subscriptionRoutes();

    $actions = app(Subscriptions::class)->reconcile();

    $folder = MailboxFolder::sole();
    expect($actions)->toBe([['mailbox' => 'support@example.com', 'folder' => 'inbox', 'action' => 'subscribed', 'failed' => false]])
        ->and($folder->folder_id)->toBe('inbox-id')
        ->and($folder->subscription_id)->toBe('sub-1')
        ->and($folder->client_state_hash)->toHaveLength(64)
        ->and($folder->subscription_expires_at->diffInMinutes(now()->addDays(3)))->toBeLessThan(1.0);

    Http::assertSent(function (Request $request) use ($folder) {
        if ($request->url() !== 'https://graph.microsoft.com/v1.0/subscriptions') {
            return false;
        }

        return $request['changeType'] === 'created,deleted'
            && $request['resource'] === 'users/support@example.com/mailFolders/inbox-id/messages'
            && $request['notificationUrl'] === url('webhooks/msgraph-mailbox')
            && $request['lifecycleNotificationUrl'] === $request['notificationUrl']
            && $request['expirationDateTime'] === $folder->subscription_expires_at->toIso8601ZuluString()
            && $request->header('Prefer') === ['IdType="ImmutableId"']
            && $folder->verifiesClientState($request['clientState']);
    });
});

it('uses the notification url override and caps the lifetime', function () {
    config()->set('msgraph-mailbox.webhooks.notification_url', 'https://tunnel.example/hook');
    config()->set('msgraph-mailbox.subscriptions.lifetime_minutes', 999999);
    subscriptionRoutes();

    app(Subscriptions::class)->reconcile();

    Http::assertSent(fn (Request $request) => ($request->data()['notificationUrl'] ?? null) === 'https://tunnel.example/hook');
    expect(MailboxFolder::sole()->subscription_expires_at->diffInMinutes(now()->addMinutes(10070)))->toBeLessThan(1.0);
});

it('leaves fresh subscriptions alone and renews them past half their lifetime', function () {
    subscriptionRoutes();
    $folder = mailboxFolder(['subscription_id' => 'sub-1', 'subscription_expires_at' => now()->addDays(3)]);

    $folder->forceFill(['subscription_expires_at' => now()->addDay()])->save();

    expect(app(Subscriptions::class)->reconcile()[0]['action'])->toBe('renewed')
        ->and($folder->fresh()->subscription_expires_at->isAfter(now()->addDays(2)))->toBeTrue();
    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH' && Str::endsWith($request->url(), '/subscriptions/sub-1')
        && $request['expirationDateTime'] === $folder->fresh()->subscription_expires_at->toIso8601ZuluString());
});

it('renews at half the lifetime', function (int $minutesLeft, string $action) {
    subscriptionRoutes();
    mailboxFolder(['subscription_id' => 'sub-1', 'subscription_expires_at' => now()->addMinutes($minutesLeft)]);

    expect(app(Subscriptions::class)->reconcile()[0]['action'])->toBe($action);
    Http::assertNotSent(fn (Request $request) => Str::contains($request->url(), '/mailFolders'));
})->with([
    'just over half' => [2161, 'ok'],
    'just under half' => [2159, 'renewed'],
]);

it('renews a subscription without known expiry', function () {
    subscriptionRoutes();
    mailboxFolder(['subscription_id' => 'sub-1', 'subscription_expires_at' => null]);

    expect(app(Subscriptions::class)->reconcile()[0]['action'])->toBe('renewed');
});

it('re-creates subscriptions whose change types changed', function () {
    subscriptionRoutes();
    mailboxFolder(['subscription_id' => 'old', 'subscription_expires_at' => now()->addDays(3), 'change_types' => collect([ChangeType::Created])]);

    expect(app(Subscriptions::class)->reconcile()[0]['action'])->toBe('subscribed');

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && Str::endsWith($request->url(), '/subscriptions/old'));
    expect(MailboxFolder::sole()->subscription_id)->toBe('sub-1');
});

it('keeps the old change types until the old subscription is gone, so a failed swap is retried', function () {
    subscriptionRoutes(['DELETE /subscriptions/*' => Http::response([], 500)]);
    mailboxFolder(['subscription_id' => 'old', 'subscription_expires_at' => now()->addDays(3), 'change_types' => collect([ChangeType::Created])]);

    expect(app(Subscriptions::class)->reconcile()[0]['failed'])->toBeTrue()
        ->and(MailboxFolder::sole())->subscription_id->toBe('old')->change_types->all()->toBe([ChangeType::Created]);
});

it('re-creates a subscription graph no longer knows', function () {
    subscriptionRoutes(['PATCH /subscriptions/*' => Http::response([], 404)]);
    $folder = mailboxFolder(['subscription_id' => 'gone']);

    app(Subscriptions::class)->refresh($folder);

    expect($folder->fresh()->subscription_id)->toBe('sub-1');
});

it('rethrows other renewal errors without creating a second subscription', function () {
    subscriptionRoutes(['PATCH /subscriptions/*' => Http::response([], 403)]);

    expect(fn () => app(Subscriptions::class)->refresh(mailboxFolder(['subscription_id' => 'sub-0'])))->toThrow(RequestException::class);
    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST' && Str::endsWith($request->url(), '/subscriptions'));
});

it('removes subscriptions in polling-only mode', function () {
    config()->set('msgraph-mailbox.webhooks.enabled', false);
    subscriptionRoutes(['DELETE /subscriptions/*' => Http::response([], 404)]);
    mailboxFolder(['subscription_id' => 'sub-1', 'client_state_hash' => Str::repeat('a', 64)]);

    expect(app(Subscriptions::class)->reconcile()[0]['action'])->toBe('polling');
    expect(MailboxFolder::sole())->subscription_id->toBeNull()->client_state_hash->toBeNull();
});

it('removes folders that are no longer observed', function () {
    subscriptionRoutes();
    mailboxFolder(['mailbox' => 'old@example.com', 'subscription_id' => 'sub-old']);

    $actions = app(Subscriptions::class)->reconcile();

    expect($actions)->toContain(['mailbox' => 'old@example.com', 'folder' => 'inbox', 'action' => 'removed', 'failed' => false])
        ->and(MailboxFolder::where('mailbox', 'old@example.com')->exists())->toBeFalse();
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && Str::endsWith($request->url(), '/subscriptions/sub-old'));
});

it('removes every stale folder, also beyond the first chunk', function () {
    subscriptionRoutes();
    foreach (range(1, 1005) as $i) {
        mailboxFolder(['mailbox' => "old{$i}@example.com"]);
    }

    app(Subscriptions::class)->reconcile();

    expect(MailboxFolder::count())->toBe(1);
});

it('keeps reconciling the other folders when one fails', function () {
    config()->set('msgraph-mailbox.mailboxes', [
        'info@example.com' => ['folders' => ['Missing']],
        'sales@example.com' => [],
        'support@example.com' => [],
    ]);
    subscriptionRoutes([
        'GET /users/info@example.com/mailFolders' => Http::response(['value' => []]),
        'GET /users/sales@example.com/mailFolders/inbox' => Http::response([], 403),
        'DELETE /subscriptions/*' => fn (Request $request) => Http::response([], Str::endsWith($request->url(), 'sub-broken') ? 500 : 204),
    ]);
    mailboxFolder(['mailbox' => 'sales@example.com', 'folder_id' => null, 'subscription_id' => 'sub-sales']);
    mailboxFolder(['mailbox' => 'support@example.com', 'subscription_id' => 'sub-support', 'subscription_expires_at' => now()->addHour()]);
    mailboxFolder(['mailbox' => 'broken@example.com', 'subscription_id' => 'sub-broken']);
    mailboxFolder(['mailbox' => 'old@example.com', 'subscription_id' => 'sub-old']);

    $actions = app(Subscriptions::class)->reconcile();

    expect(array_column($actions, 'failed', 'mailbox'))->toBe([
        'info@example.com' => true,
        'sales@example.com' => true,
        'support@example.com' => false,
        'broken@example.com' => true,
        'old@example.com' => false,
    ])
        ->and($actions[0])->toMatchArray(['folder' => 'Missing'])
        ->and($actions[1]['action'])->toStartWith('failed: HTTP request returned status code 403')
        ->and($actions[2]['action'])->toBe('renewed')
        ->and($actions[4]['action'])->toBe('removed')
        ->and(MailboxFolder::orderBy('mailbox')->pluck('subscription_id', 'mailbox')->all())->toBe(['broken@example.com' => 'sub-broken', 'sales@example.com' => 'sub-sales', 'support@example.com' => 'sub-support']);
    Http::assertNotSent(fn (Request $request) => Str::endsWith($request->url(), '/subscriptions/sub-sales'));
});

it('rethrows errors when deleting a subscription fails', function () {
    subscriptionRoutes(['DELETE /subscriptions/*' => Http::response([], 500)]);

    app(Subscriptions::class)->unsubscribe(mailboxFolder(['subscription_id' => 'sub-1']));
})->throws(RequestException::class);

it('fails on a subscription response without id', function () {
    subscriptionRoutes(['POST /subscriptions' => Http::response([], 201)]);

    expect(app(Subscriptions::class)->reconcile()[0]['action'])->toBe('failed: Expected Microsoft Graph to return the id of the created subscription.');
});

it('renews and syncs after a lifecycle notification', function () {
    subscriptionRoutes();
    Bus::fake([SyncFolder::class]);
    $folder = mailboxFolder(['subscription_id' => 'sub-1']);

    $job = new RefreshSubscription($folder);
    app()->call([$job, 'handle']);

    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH');
    Bus::assertDispatched(SyncFolder::class);
});

it('only registers folders in polling-only mode', function () {
    config()->set('msgraph-mailbox.webhooks.enabled', false);
    subscriptionRoutes();

    expect(app(Subscriptions::class)->reconcile()[0]['action'])->toBe('polling');

    Http::assertNotSent(fn (Request $request) => Str::contains($request->url(), '/subscriptions'));
    expect(MailboxFolder::sole()->folder_id)->toBe('inbox-id');
});
