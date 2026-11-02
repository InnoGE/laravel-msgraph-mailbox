<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InnoGE\LaravelMsGraphMail\Graph\InvalidResponseException;
use InnoGE\LaravelMsGraphMailbox\Enums\WellKnownFolder;
use InnoGE\LaravelMsGraphMailbox\Exceptions\FolderNotFoundException;
use InnoGE\LaravelMsGraphMailbox\Folders;

it('resolves well-known folders and caches the id', function () {
    fakeGraph(['GET /users/support@example.com/mailFolders/archive' => Http::response(['id' => 'archive-id'])]);

    expect(app(Folders::class)->id('support@example.com', WellKnownFolder::Archive))->toBe('archive-id')
        ->and(app(Folders::class)->id('support@example.com', WellKnownFolder::Archive))->toBe('archive-id');

    expect(graphRequestCount())->toBe(1);
});

it('caches folder ids per mailbox', function () {
    fakeGraph([
        'GET /users/a@example.com/mailFolders' => Http::response(['value' => [['id' => 'a-done']]]),
        'GET /users/b@example.com/mailFolders' => Http::response(['value' => [['id' => 'b-done']]]),
    ]);

    expect(app(Folders::class)->id('a@example.com', 'Done'))->toBe('a-done')
        ->and(app(Folders::class)->id('b@example.com', 'Done'))->toBe('b-done');
});

it('walks folder paths by display name, escaping quotes', function () {
    fakeGraph([
        'GET /users/support@example.com/mailFolders' => Http::response(['value' => [['id' => 'parent-id']]]),
        'GET /users/support@example.com/mailFolders/parent-id/childFolders' => Http::response(['value' => [['id' => 'child-id']]]),
    ]);

    expect(app(Folders::class)->id('support@example.com', "Customer's/Escalations"))->toBe('child-id');

    Http::assertSent(fn (Request $request) => Str::contains(urldecode($request->url()), "displayName eq 'Customer''s'"));
});

it('fails loud when a folder does not exist', function () {
    fakeGraph(['GET /users/support@example.com/mailFolders' => Http::response(['value' => []])]);

    app(Folders::class)->id('support@example.com', 'Missing');
})->throws(FolderNotFoundException::class, 'Folder [Missing] does not exist in mailbox [support@example.com].');

it('creates missing folders on request', function () {
    fakeGraph([
        'GET /users/support@example.com/mailFolders' => Http::response(['value' => []]),
        'POST /users/support@example.com/mailFolders' => Http::response(['id' => 'new-id'], 201),
    ]);

    expect(app(Folders::class)->id('support@example.com', 'Processed', createMissing: true))->toBe('new-id');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && ($request->data()['displayName'] ?? null) === 'Processed');
});

it('rejects folders without an id', function () {
    fakeGraph(['GET /users/support@example.com/mailFolders/inbox' => Http::response([])]);

    app(Folders::class)->id('support@example.com', WellKnownFolder::Inbox);
})->throws(InvalidResponseException::class);

it('rejects control characters in folder names', function () {
    Folders::normalize("In\0box");
})->throws(InvalidArgumentException::class);

it('treats well-known names the same everywhere', function () {
    expect(Folders::normalize('archive'))->toBe(WellKnownFolder::Archive)
        ->and(Folders::normalize(' Support / Escalations '))->toBe('Support/Escalations')
        ->and(Folders::normalize(WellKnownFolder::Inbox))->toBe(WellKnownFolder::Inbox);
});
