<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use InnoGE\LaravelMsGraphMail\Graph\InvalidResponseException;
use InnoGE\LaravelMsGraphMailbox\EmailAddress;
use InnoGE\LaravelMsGraphMailbox\Enums\BodyType;
use InnoGE\LaravelMsGraphMailbox\Enums\FlagStatus;
use InnoGE\LaravelMsGraphMailbox\Enums\WellKnownFolder;
use InnoGE\LaravelMsGraphMailbox\Exceptions\AttachmentTooLargeException;
use InnoGE\LaravelMsGraphMailbox\Facades\Mailbox;

const MESSAGE = 'GET /users/support@example.com/messages/msg-1';

function graphMessage(array $attributes = []): array
{
    return [
        'id' => 'msg-1',
        'subject' => 'Hello',
        'from' => ['emailAddress' => ['address' => 'Customer@Example.org', 'name' => 'Customer']],
        'sender' => ['emailAddress' => ['address' => 'customer@example.org']],
        'toRecipients' => [['emailAddress' => ['address' => 'support@example.com', 'name' => 'Support']], ['emailAddress' => []]],
        'ccRecipients' => [['emailAddress' => ['address' => 'boss@example.org']]],
        'receivedDateTime' => '2026-11-01T10:00:00Z',
        'internetMessageId' => '<abc@example.org>',
        'internetMessageHeaders' => [['name' => 'Received', 'value' => 'x'], ['name' => 'X-Custom', 'value' => null], ['value' => 'no name']],
        'isRead' => true,
        'flag' => ['flagStatus' => 'complete'],
        'categories' => ['Red', 5],
        'parentFolderId' => 'inbox-id',
        ...$attributes,
    ];
}

it('loads all message properties with one request', function () {
    fakeGraph([MESSAGE => Http::response(graphMessage())]);

    $message = Mailbox::for('support@example.com')->message('msg-1');

    expect($message->subject())->toBe('Hello')
        ->and($message->from())->toEqual(new EmailAddress('Customer@Example.org', 'Customer'))
        ->and($message->sender())->toEqual(new EmailAddress('customer@example.org'))
        ->and($message->to())->toEqual([new EmailAddress('support@example.com', 'Support')])
        ->and($message->cc())->toEqual([new EmailAddress('boss@example.org')])
        ->and($message->receivedAt()?->toIso8601ZuluString())->toBe('2026-11-01T10:00:00Z')
        ->and($message->internetMessageId())->toBe('<abc@example.org>')
        ->and($message->parentFolderId())->toBe('inbox-id')
        ->and($message->isRead())->toBeTrue()
        ->and($message->flagStatus())->toBe(FlagStatus::Complete)
        ->and($message->categories())->toBe(['Red'])
        ->and($message->headers())->toBe([['name' => 'Received', 'value' => 'x'], ['name' => 'X-Custom', 'value' => '']])
        ->and($message->header('received'))->toBe('x')
        ->and($message->header('Missing'))->toBeNull();

    expect(graphRequestCount())->toBe(1);
    Http::assertSent(fn (Request $request) => Str::contains(urldecode($request->url()), '$select=id,subject,from,sender'));
});

it('handles sparse messages', function () {
    fakeGraph([MESSAGE => Http::response(['id' => 'msg-1'])]);

    $message = Mailbox::for('support@example.com')->message('msg-1');

    expect($message->subject())->toBeNull()
        ->and($message->from())->toBeNull()
        ->and($message->receivedAt())->toBeNull()
        ->and($message->flagStatus())->toBe(FlagStatus::NotFlagged)
        ->and($message->categories())->toBe([])
        ->and($message->isAutoSubmitted())->toBeFalse()
        ->and($message->isFromSelf())->toBeFalse();
});

it('rejects a non object message response', function () {
    fakeGraph([MESSAGE => Http::response('"nope"')]);

    Mailbox::for('support@example.com')->message('msg-1')->subject();
})->throws(InvalidResponseException::class);

it('fetches and memoizes bodies per type and uniqueness', function () {
    fakeGraph([MESSAGE => fn (Request $request) => Http::response(Str::contains($request->header('Prefer')[0], 'html')
        ? ['body' => ['content' => '<p>Hi</p>'], 'uniqueBody' => ['content' => '<p>unique</p>']]
        : ['body' => ['content' => 'Hi'], 'uniqueBody' => ['content' => 'unique']])]);

    $message = Mailbox::for('support@example.com')->message('msg-1');

    expect($message->body())->toBe('Hi')
        ->and($message->body())->toBe('Hi')
        ->and($message->body(BodyType::Html, unique: true))->toBe('<p>unique</p>')
        ->and($message->body(BodyType::Html))->toBe('<p>Hi</p>');

    expect(graphRequestCount())->toBe(3);
    Http::assertSent(fn (Request $request) => Str::contains(urldecode($request->url()), '$select=uniqueBody'));
    Http::assertSent(fn (Request $request) => Str::contains(urldecode($request->url()), '$select=body'));
});

it('returns an empty body when graph has none', function () {
    fakeGraph([MESSAGE => Http::response([])]);

    expect(Mailbox::for('support@example.com')->message('msg-1')->body())->toBe('');
});

it('detects automatic replies', function (array $headers, bool $expected) {
    fakeGraph([MESSAGE => Http::response(graphMessage(['internetMessageHeaders' => $headers]))]);

    expect(Mailbox::for('support@example.com')->message('msg-1')->isAutoSubmitted())->toBe($expected);
})->with([
    'auto-submitted' => [[['name' => 'Auto-Submitted', 'value' => 'auto-replied']], true],
    'auto-submitted no' => [[['name' => 'Auto-Submitted', 'value' => ' No ']], false],
    'response suppress from a ticketing system' => [[['name' => 'X-Auto-Response-Suppress', 'value' => 'All']], false],
    'bulk precedence' => [[['name' => 'Precedence', 'value' => 'Bulk']], true],
    'auto reply precedence' => [[['name' => 'Precedence', 'value' => 'auto_reply']], true],
    'mailing list' => [[['name' => 'Precedence', 'value' => 'list']], false],
    'normal mail' => [[['name' => 'Precedence', 'value' => 'first-class']], false],
]);

it('detects mail sent by the mailbox itself', function (array $attributes, bool $expected) {
    fakeGraph([MESSAGE => Http::response(graphMessage($attributes))]);

    expect(Mailbox::for('support@example.com')->message('msg-1')->isFromSelf())->toBe($expected);
})->with([
    'customer' => [[], false],
    'from' => [['from' => ['emailAddress' => ['address' => 'SUPPORT@example.com']]], true],
    'sender' => [['sender' => ['emailAddress' => ['address' => 'support@example.com']]], true],
]);

it('lists attachment metadata without content and streams on demand', function () {
    fakeGraph([
        'GET /users/support@example.com/messages/msg-1/attachments' => Http::response(['value' => [
            ['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'att-1', 'name' => '../../etc/passwd', 'contentType' => 'text/plain', 'size' => 5, 'isInline' => false],
            ['@odata.type' => '#microsoft.graph.itemAttachment', 'id' => 'att-2', 'name' => '', 'size' => 1, 'isInline' => true],
            'garbage',
        ]]),
        'GET /users/support@example.com/messages/msg-1/attachments/att-1/$value' => Http::response('hello'),
    ]);

    $message = Mailbox::for('support@example.com')->message('msg-1');
    [$file, $item] = $attachments = $message->attachments();
    $message->attachments();

    expect($attachments)->toHaveCount(2)
        ->and($file->type)->toBe('file')
        ->and($file->safeFilename())->toBe('passwd')
        ->and($file->contents())->toBe('hello')
        ->and($item->type)->toBe('item')
        ->and($item->isInline)->toBeTrue()
        ->and($item->contentType)->toBeNull()
        ->and($item->safeFilename())->toBe('attachment');

    Http::assertSent(fn (Request $request) => Str::contains(urldecode($request->url()), '$select=id,name,contentType,size,isInline'));
    expect(graphRequestCount())->toBe(2);
});

it('refuses attachments above the size limit before downloading', function () {
    config()->set('msgraph-mailbox.attachments.max_bytes', 4);
    fakeGraph(['GET /users/support@example.com/messages/msg-1/attachments' => Http::response(['value' => [['id' => 'att-1', 'name' => 'a', 'size' => 5]]])]);

    Mailbox::for('support@example.com')->message('msg-1')->attachments()[0]->stream();
})->throws(AttachmentTooLargeException::class, 'Attachment [att-1] exceeds msgraph-mailbox.attachments.max_bytes (4 bytes).');

it('allows attachments of exactly the size limit', function () {
    config()->set('msgraph-mailbox.attachments.max_bytes', 5);
    fakeGraph([
        'GET /users/support@example.com/messages/msg-1/attachments' => Http::response(['value' => [['id' => 'att-1', 'name' => 'a', 'size' => 5]]]),
        'GET /users/support@example.com/messages/msg-1/attachments/att-1/$value' => Http::response('12345'),
    ]);

    expect(Mailbox::for('support@example.com')->message('msg-1')->attachments()[0]->contents())->toBe('12345');
});

it('caps the read when the reported size is wrong', function () {
    config()->set('msgraph-mailbox.attachments.max_bytes', 4);
    fakeGraph([
        'GET /users/support@example.com/messages/msg-1/attachments' => Http::response(['value' => [['id' => 'att-1', 'name' => 'a', 'size' => 1]]]),
        'GET /users/support@example.com/messages/msg-1/attachments/att-1/$value' => Http::response('12345'),
    ]);

    Mailbox::for('support@example.com')->message('msg-1')->attachments()[0]->contents();
})->throws(AttachmentTooLargeException::class);

it('reads attachments without a limit when disabled', function () {
    config()->set('msgraph-mailbox.attachments.max_bytes', null);
    fakeGraph([
        'GET /users/support@example.com/messages/msg-1/attachments' => Http::response(['value' => [['id' => 'att-1', 'name' => 'a', 'size' => 999]]]),
        'GET /users/support@example.com/messages/msg-1/attachments/att-1/$value' => Http::response('12345'),
    ]);

    expect(Mailbox::for('support@example.com')->message('msg-1')->attachments()[0]->contents())->toBe('12345');
});

it('handles a missing attachment list', function () {
    fakeGraph(['GET /users/support@example.com/messages/msg-1/attachments' => Http::response([])]);

    expect(Mailbox::for('support@example.com')->message('msg-1')->attachments())->toBe([]);
});

it('streams the raw mime message', function () {
    fakeGraph(['GET /users/support@example.com/messages/msg-1/$value' => Http::response("Subject: Hi\r\n")]);

    expect(Mailbox::for('support@example.com')->message('msg-1')->mime()->getContents())->toBe("Subject: Hi\r\n");
});

it('performs actions against graph', function () {
    fakeGraph([
        'POST /users/support@example.com/messages/msg-1/move' => Http::response(['id' => 'msg-1']),
        'POST /users/support@example.com/messages/msg-1/copy' => Http::response(['id' => 'copy-1']),
        'PATCH /users/support@example.com/messages/msg-1' => Http::response([]),
        'POST /users/support@example.com/messages/msg-1/permanentDelete' => Http::response([], 204),
        'GET /users/support@example.com/mailFolders' => Http::response(['value' => [['id' => 'done-id']]]),
        MESSAGE => Http::response(graphMessage(['categories' => ['Existing']])),
    ]);

    $message = Mailbox::for('support@example.com')->message('msg-1');
    $message->move(WellKnownFolder::Archive);
    $message->move('Done');
    $message->move('archive');
    $message->archive();
    $copy = $message->copy(WellKnownFolder::Inbox);
    $message->markRead();
    $message->markUnread();
    $message->flag();
    $message->unflag();
    $message->categorize('Red', 'Existing');
    $message->delete();
    $message->permanentDelete();

    expect($copy->id)->toBe('copy-1');

    $sent = Http::recorded()->map(fn (array $pair) => [$pair[0]->method(), $pair[0]->data()])->all();
    expect($sent)->toContain(['POST', ['destinationId' => 'archive']])
        ->toContain(['POST', ['destinationId' => 'done-id']])
        ->toContain(['POST', ['destinationId' => 'archive']])
        ->toContain(['POST', ['destinationId' => 'inbox']])
        ->toContain(['POST', ['destinationId' => 'deleteditems']])
        ->toContain(['PATCH', ['isRead' => true]])
        ->toContain(['PATCH', ['isRead' => false]])
        ->toContain(['PATCH', ['flag' => ['flagStatus' => 'flagged']]])
        ->toContain(['PATCH', ['flag' => ['flagStatus' => 'notFlagged']]])
        ->toContain(['PATCH', ['categories' => ['Existing', 'Red']]]);
    Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE');
    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && Str::endsWith($request->url(), '/messages/msg-1/permanentDelete'));
});

it('retries an update that hits an exchange conflict', function () {
    Sleep::fake();
    fakeGraph(['PATCH /users/support@example.com/messages/msg-1' => function () {
        static $calls = 0;

        return $calls++ === 0
            ? Http::response(['error' => ['code' => 'ErrorIrresolvableConflict']], 412)
            : Http::response([]);
    }]);

    Mailbox::for('support@example.com')->message('msg-1')->setCategories(['Red']);

    expect(graphRequestCount())->toBe(2);
});

it('does not retry other update errors', function () {
    Sleep::fake();
    fakeGraph(['PATCH /users/support@example.com/messages/msg-1' => Http::response([], 403)]);

    expect(fn () => Mailbox::for('support@example.com')->message('msg-1')->markRead())->toThrow(RequestException::class);
    expect(graphRequestCount())->toBe(1);
});

it('rejects a copy response without id', function () {
    fakeGraph(['POST /users/support@example.com/messages/msg-1/copy' => Http::response([])]);

    Mailbox::for('support@example.com')->message('msg-1')->copy(WellKnownFolder::Archive);
})->throws(InvalidResponseException::class);

it('refetches properties after an action', function () {
    fakeGraph([
        MESSAGE => function () {
            static $calls = 0;

            return Http::response(graphMessage(['isRead' => $calls++ > 0]));
        },
        'PATCH /users/support@example.com/messages/msg-1' => Http::response([]),
    ]);

    $message = Mailbox::for('support@example.com')->message('msg-1');

    expect($message->isRead())->toBeFalse();
    $message->markRead();
    expect($message->isRead())->toBeTrue();
});

it('cannot be serialized', function () {
    serialize(Mailbox::for('support@example.com')->message('msg-1'));
})->throws(LogicException::class);
