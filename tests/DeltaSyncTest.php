<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InnoGE\LaravelMsGraphMail\Graph\InvalidResponseException;
use InnoGE\LaravelMsGraphMailbox\Enums\ChangeType;
use InnoGE\LaravelMsGraphMailbox\Events\MessageChanged;
use InnoGE\LaravelMsGraphMailbox\Events\MessageDeleted;
use InnoGE\LaravelMsGraphMailbox\Events\MessageMoved;
use InnoGE\LaravelMsGraphMailbox\Events\MessagePermanentlyDeleted;
use InnoGE\LaravelMsGraphMailbox\Events\MessageReceived;
use InnoGE\LaravelMsGraphMailbox\Jobs\SyncFolder;
use InnoGE\LaravelMsGraphMailbox\Models\MailboxFolder;
use InnoGE\LaravelMsGraphMailbox\Models\MailboxMessage;
use InnoGE\LaravelMsGraphMailbox\Sync\DeltaSync;

const DELTA = 'GET /users/support@example.com/mailFolders/inbox-id/messages/delta';
const LINK = 'https://graph.microsoft.com/v1.0/users/support%40example.com/mailFolders/inbox-id/messages/delta';

/**
 * @param  array<string, mixed>  $pages
 */
function deltaPages(array $pages, array $routes = []): void
{
    fakeGraph([
        DELTA => function (Request $request) use ($pages) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $token = $query['$skiptoken'] ?? $query['$deltatoken'] ?? '';

            return $pages[$token] ?? Http::response(['error' => ['code' => 'unexpected page '.$token]], 500);
        },
        ...$routes,
    ]);
}

function page(array $items, ?string $next = null, ?string $delta = null): mixed
{
    return Http::response([
        'value' => $items,
        ...($next !== null ? ['@odata.nextLink' => LINK.'?$skiptoken='.$next] : []),
        ...($delta !== null ? ['@odata.deltaLink' => LINK.'?$deltatoken='.$delta] : []),
    ]);
}

function sync(MailboxFolder $folder, int $maxPages = 50): bool
{
    return app(DeltaSync::class)->run($folder, $maxPages);
}

function baselined(array $attributes = []): MailboxFolder
{
    return mailboxFolder([
        'baselined_at' => now()->subDay(),
        'delta_link' => LINK.'?$deltatoken=d1',
        ...$attributes,
    ]);
}

function track(MailboxFolder $folder, string $id, ?string $changeKey = 'ck'): void
{
    $folder->messages()->create(['message_id' => $id, 'change_key' => $changeKey, 'seen_at' => now()->subDay()]);
}

beforeEach(fn () => Event::fake([MessageReceived::class, MessageChanged::class, MessageMoved::class, MessageDeleted::class, MessagePermanentlyDeleted::class]));

it('records a silent baseline on the first sync', function () {
    deltaPages([
        '' => page([['id' => 'm1', 'changeKey' => 'a'], ['id' => 'm2', 'changeKey' => 'b']], next: 's1'),
        's1' => page([['id' => 'm3', 'changeKey' => 'c'], 'garbage', ['changeKey' => 'no id']], delta: 'd1'),
    ]);
    $folder = mailboxFolder();

    expect(sync($folder))->toBeFalse();

    Event::assertNothingDispatched();
    expect($folder->messages()->pluck('change_key', 'message_id')->all())->toBe(['m1' => 'a', 'm2' => 'b', 'm3' => 'c'])
        ->and($folder->baselined_at)->not->toBeNull()
        ->and($folder->walk_started_at)->toBeNull()
        ->and($folder->last_synced_at)->not->toBeNull()
        ->and($folder->delta_link)->toBe(LINK.'?$deltatoken=d1');

    Http::assertSent(fn (Request $request) => Str::contains(urldecode($request->url()), '/messages/delta?$select=changeKey')
        && $request->header('Prefer') === ['IdType="ImmutableId", odata.maxpagesize=100']);
});

it('resolves the folder id when it is not known yet', function () {
    deltaPages(['' => page([], delta: 'd1')], ['GET /users/support@example.com/mailFolders/inbox' => Http::response(['id' => 'inbox-id'])]);
    $folder = mailboxFolder(['folder_id' => null]);

    sync($folder);

    expect($folder->fresh()->folder_id)->toBe('inbox-id');
});

it('reports new and changed messages after the baseline', function () {
    deltaPages(['d1' => page([
        ['id' => 'new', 'changeKey' => 'a'],
        ['id' => 'changed', 'changeKey' => 'new-key'],
        ['id' => 'same', 'changeKey' => 'ck'],
    ], delta: 'd2')]);
    $folder = baselined(['change_types' => collect([ChangeType::Created, ChangeType::Updated])]);
    track($folder, 'changed');
    track($folder, 'same');

    sync($folder);

    Event::assertDispatched(MessageReceived::class, fn (MessageReceived $event) => $event->messageId === 'new' && $event->mailbox === 'support@example.com' && $event->folder === 'inbox');
    Event::assertDispatchedTimes(MessageChanged::class, 1);
    Event::assertDispatched(MessageChanged::class, fn (MessageChanged $event) => $event->messageId === 'changed');
    expect($folder->messages()->where('message_id', 'changed')->value('change_key'))->toBe('new-key')
        ->and($folder->delta_link)->toBe(LINK.'?$deltatoken=d2');
});

it('only fires events for observed change types', function () {
    deltaPages(['d1' => page([['id' => 'new'], ['id' => 'changed', 'changeKey' => 'x'], ['id' => 'gone', '@removed' => ['reason' => 'deleted']]], delta: 'd2')]);
    $folder = baselined(['change_types' => collect([ChangeType::Updated])]);
    track($folder, 'changed');
    track($folder, 'gone');

    sync($folder);

    Event::assertNotDispatched(MessageReceived::class);
    Event::assertDispatched(MessageChanged::class);
    Event::assertNotDispatched(MessagePermanentlyDeleted::class);
    expect($folder->messages()->pluck('message_id')->sort()->values()->all())->toBe(['changed', 'new']);
});

it('classifies where removed messages went', function () {
    deltaPages(['d1' => page(array_map(fn (string $id) => ['id' => $id, '@removed' => ['reason' => 'deleted']], ['purged', 'trashed', 'recoverable', 'in-purges', 'moved', 'still-here', 'unknown']), delta: 'd2')], [
        'GET /users/support@example.com/messages/purged' => Http::response(['error' => ['code' => 'ErrorItemNotFound']], 404),
        'GET /users/support@example.com/messages/trashed' => Http::response(['parentFolderId' => 'deleted-id']),
        'GET /users/support@example.com/messages/recoverable' => Http::response(['parentFolderId' => 'recoverable-id']),
        'GET /users/support@example.com/messages/in-purges' => Http::response(['parentFolderId' => 'purges-id']),
        'GET /users/support@example.com/messages/moved' => Http::response(['parentFolderId' => 'archive-id']),
        'GET /users/support@example.com/messages/still-here' => Http::response(['parentFolderId' => 'inbox-id']),
        'GET /users/support@example.com/mailFolders/deleteditems' => Http::response(['id' => 'deleted-id']),
        'GET /users/support@example.com/mailFolders/recoverableitemsdeletions' => Http::response(['id' => 'recoverable-id']),
        'GET /users/support@example.com/mailFolders/recoverableitemspurges' => Http::response(['id' => 'purges-id']),
    ]);
    $folder = baselined();
    foreach (['purged', 'trashed', 'recoverable', 'in-purges', 'moved', 'still-here'] as $id) {
        track($folder, $id);
    }

    sync($folder);

    Event::assertDispatched(MessagePermanentlyDeleted::class, fn ($event) => $event->messageId === 'purged');
    Event::assertDispatched(MessagePermanentlyDeleted::class, fn ($event) => $event->messageId === 'recoverable');
    Event::assertDispatched(MessagePermanentlyDeleted::class, fn ($event) => $event->messageId === 'in-purges');
    Event::assertNotDispatched(MessageDeleted::class, fn ($event) => $event->messageId !== 'trashed');
    Event::assertDispatched(MessageDeleted::class, fn ($event) => $event->messageId === 'trashed');
    Event::assertDispatched(MessageMoved::class, fn (MessageMoved $event) => $event->messageId === 'moved' && $event->toFolderId === 'archive-id');
    Event::assertDispatchedTimes(MessageMoved::class, 1);
    expect($folder->messages()->pluck('message_id')->all())->toBe(['still-here']);
    Http::assertSent(fn (Request $request) => Str::contains(urldecode($request->url()), '/messages/moved?$select=parentFolderId'));
    Http::assertNotSent(fn (Request $request) => Str::endsWith($request->url(), '/messages/unknown?%24select=parentFolderId'));
});

it('walks the folder again when graph lost the sync state, reporting what changed meanwhile', function (mixed $lostResponse) {
    deltaPages([
        'd1' => $lostResponse,
        '' => page([['id' => 'kept', 'changeKey' => 'ck'], ['id' => 'arrived', 'changeKey' => 'x']], delta: 'd2'),
    ], [
        'GET /users/support@example.com/messages/vanished' => Http::response([], 404),
    ]);
    $folder = baselined();
    track($folder, 'kept');
    track($folder, 'vanished');

    sync($folder);

    Event::assertDispatched(MessageReceived::class, fn ($event) => $event->messageId === 'arrived');
    Event::assertDispatched(MessagePermanentlyDeleted::class, fn ($event) => $event->messageId === 'vanished');
    Event::assertNotDispatched(MessageReceived::class, fn ($event) => $event->messageId === 'kept');
    expect($folder->walk_started_at)->toBeNull()
        ->and($folder->delta_link)->toBe(LINK.'?$deltatoken=d2');
})->with([
    'gone' => [fn () => Http::response(['error' => ['code' => 'SyncStateNotFound']], 410)],
    'sync state not found' => [fn () => Http::response(['error' => ['code' => 'syncStateNotFound']], 400)],
]);

it('reports removals after a reset even when an earlier walk was aborted', function () {
    deltaPages(['' => page([['id' => 'kept', 'changeKey' => 'ck']], delta: 'd2')], [
        'GET /users/support@example.com/messages/vanished' => Http::response([], 404),
    ]);
    $folder = baselined(['walk_started_at' => now()->subHours(2), 'delta_link' => LINK.'?$skiptoken=stale']);
    track($folder, 'kept');
    $folder->messages()->create(['message_id' => 'vanished', 'change_key' => 'ck', 'seen_at' => now()->subHour()]);

    Bus::fake([SyncFolder::class]);
    $this->artisan('msgraph-mailbox:sync', ['--reset' => true]);
    sync($folder->fresh());

    Event::assertDispatched(MessagePermanentlyDeleted::class, fn ($event) => $event->messageId === 'vanished');
});

it('keeps messages seen earlier in a walk that spans several runs', function () {
    deltaPages([
        '' => page([['id' => 'first-page', 'changeKey' => 'ck']], next: 's1'),
        's1' => page([['id' => 'second-page', 'changeKey' => 'ck']], delta: 'd2'),
    ], [
        'GET /users/support@example.com/messages/vanished' => Http::response([], 404),
    ]);
    $folder = baselined();
    foreach (['first-page', 'second-page', 'vanished'] as $id) {
        track($folder, $id);
    }
    $folder->restartWalk();

    expect(sync($folder, maxPages: 1))->toBeTrue()
        ->and(sync($folder->fresh(), maxPages: 1))->toBeFalse();

    Event::assertDispatchedTimes(MessagePermanentlyDeleted::class, 1);
    Event::assertDispatched(MessagePermanentlyDeleted::class, fn ($event) => $event->messageId === 'vanished');
    expect($folder->messages()->pluck('message_id')->sort()->values()->all())->toBe(['first-page', 'second-page']);
});

it('removes every unseen message after a walk, also beyond the first chunk', function () {
    deltaPages(['' => page([], delta: 'd2')]);
    $folder = baselined(['change_types' => collect([ChangeType::Created])]);
    $folder->messages()->insert(array_map(fn (int $i) => ['mailbox_folder_id' => $folder->id, 'message_id' => "m{$i}", 'seen_at' => now()->subDay()], range(1, 1005)));
    $folder->restartWalk();

    sync($folder);

    expect($folder->messages()->count())->toBe(0);
});

it('does not follow a tampered delta link', function () {
    deltaPages(['' => page([], delta: 'd2')]);
    $folder = baselined(['delta_link' => 'https://attacker.example/steal?$deltatoken=d1']);

    sync($folder);

    Http::assertNotSent(fn (Request $request) => Str::contains($request->url(), 'attacker'));
    expect($folder->delta_link)->toBe(LINK.'?$deltatoken=d2');
});

it('stops after the page budget and resumes from the stored link', function () {
    deltaPages([
        '' => page([['id' => 'm1']], next: 's1'),
        's1' => page([['id' => 'm2']], delta: 'd1'),
    ]);
    $folder = mailboxFolder();

    expect(sync($folder, maxPages: 1))->toBeTrue()
        ->and($folder->delta_link)->toBe(LINK.'?$skiptoken=s1')
        ->and(sync($folder->fresh(), maxPages: 1))->toBeFalse();

    expect($folder->fresh()->baselined_at)->not->toBeNull();
    Event::assertNothingDispatched();
});

it('fails when graph returns a message without parent folder', function () {
    deltaPages(['d1' => page([['id' => 'gone', '@removed' => ['reason' => 'deleted']]], delta: 'd2')], [
        'GET /users/support@example.com/messages/gone' => Http::response([]),
    ]);
    $folder = baselined();
    track($folder, 'gone');

    sync($folder);
})->throws(InvalidResponseException::class, 'parentFolderId');

it('fails on a page without links', function () {
    deltaPages(['' => Http::response(['value' => []])]);

    sync(mailboxFolder());
})->throws(InvalidResponseException::class);

it('rolls back the tracked message when a listener fails, so the change is retried', function () {
    Event::fake([MessageChanged::class]);
    Event::listen(MessageReceived::class, fn () => throw new RuntimeException('listener failed'));
    deltaPages(['d1' => page([['id' => 'new']], delta: 'd2')]);
    $folder = baselined();

    expect(fn () => sync($folder))->toThrow(RuntimeException::class, 'listener failed')
        ->and(MailboxMessage::count())->toBe(0)
        ->and($folder->fresh()->delta_link)->toBe(LINK.'?$deltatoken=d1');
});

it('runs as a job that continues with the next batch of pages', function () {
    Bus::fake([SyncFolder::class]);
    deltaPages(['' => page([], next: 'more'), 'more' => page([], next: 'more')]);
    $folder = mailboxFolder();

    app()->call([new SyncFolder($folder), 'handle']);

    Bus::assertDispatched(SyncFolder::class);
});

it('finishes in one run through the queue, with throttling and overlap protection', function () {
    deltaPages(['' => page([], delta: 'd1')]);
    $folder = mailboxFolder();

    SyncFolder::dispatchSync($folder);

    expect($folder->fresh()->baselined_at)->not->toBeNull();
    Http::assertSentCount(2);
});
