<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use InnoGE\LaravelMsGraphMailbox\Enums\FlagStatus;
use InnoGE\LaravelMsGraphMailbox\Enums\WellKnownFolder;
use InnoGE\LaravelMsGraphMailbox\Exceptions\MailboxNotAllowedException;
use InnoGE\LaravelMsGraphMailbox\Facades\Mailbox;
use InnoGE\LaravelMsGraphMailbox\Jobs\CategorizeMessage;
use InnoGE\LaravelMsGraphMailbox\Jobs\CopyMessage;
use InnoGE\LaravelMsGraphMailbox\Jobs\DeleteMessage;
use InnoGE\LaravelMsGraphMailbox\Jobs\FlagMessage;
use InnoGE\LaravelMsGraphMailbox\Jobs\MarkMessageRead;
use InnoGE\LaravelMsGraphMailbox\Jobs\MarkMessageUnread;
use InnoGE\LaravelMsGraphMailbox\Jobs\Middleware\ThrottleGraphRequests;
use InnoGE\LaravelMsGraphMailbox\Jobs\MoveMessage;
use InnoGE\LaravelMsGraphMailbox\Jobs\PermanentlyDeleteMessage;
use InnoGE\LaravelMsGraphMailbox\Jobs\RefreshSubscription;
use InnoGE\LaravelMsGraphMailbox\Jobs\SyncFolder;

it('performs each action through the mailbox', function () {
    $fake = Mailbox::fake()->addMessage('support@example.com', 'msg-1');

    MoveMessage::dispatchSync('support@example.com', 'msg-1', WellKnownFolder::Archive);
    CopyMessage::dispatchSync('support@example.com', 'msg-1', 'Support/Done');
    MarkMessageRead::dispatchSync('support@example.com', 'msg-1');
    MarkMessageUnread::dispatchSync('support@example.com', 'msg-1');
    FlagMessage::dispatchSync('support@example.com', 'msg-1', FlagStatus::Complete);
    CategorizeMessage::dispatchSync('support@example.com', 'msg-1', ['Red']);
    DeleteMessage::dispatchSync('support@example.com', 'msg-1');
    PermanentlyDeleteMessage::dispatchSync('support@example.com', 'msg-1');

    $fake->assertMoved('msg-1', WellKnownFolder::Archive);
    $fake->assertCopied('msg-1', 'Support/Done');
    $fake->assertMarkedRead('msg-1');
    $fake->assertMarkedUnread('msg-1');
    $fake->assertFlagged('msg-1', FlagStatus::Complete);
    $fake->assertCategorized('msg-1', ['Red']);
    $fake->assertDeleted('msg-1');
    $fake->assertPermanentlyDeleted('msg-1');
});

it('re-checks the mailbox scope when the job runs', function () {
    Mailbox::fake();

    MarkMessageRead::dispatchSync('ceo@example.com', 'msg-1');
})->throws(MailboxNotAllowedException::class);

it('collapses duplicates but keeps different actions, mailboxes and arguments', function () {
    Queue::fake();

    MoveMessage::dispatch('a@example.com', 'msg-1', WellKnownFolder::Archive);
    MoveMessage::dispatch(' A@Example.com ', 'msg-1', 'archive');
    MoveMessage::dispatch('b@example.com', 'msg-1', WellKnownFolder::Archive);
    MoveMessage::dispatch('a@example.com', 'msg-1', 'Archive/2026');
    MoveMessage::dispatch('a@example.com', 'msg-2', WellKnownFolder::Archive);
    CopyMessage::dispatch('a@example.com', 'msg-1', WellKnownFolder::Archive);
    FlagMessage::dispatch('a@example.com', 'msg-1');
    FlagMessage::dispatch('a@example.com', 'msg-1', FlagStatus::Complete);
    CategorizeMessage::dispatch('a@example.com', 'msg-1', ['a']);
    CategorizeMessage::dispatch('a@example.com', 'msg-1', ['b']);

    Queue::assertPushed(MoveMessage::class, 4);
    Queue::assertPushed(CopyMessage::class, 1);
    Queue::assertPushed(FlagMessage::class, 2);
    Queue::assertPushed(CategorizeMessage::class, 2);
});

it('collapses duplicate folder syncs', function () {
    Queue::fake();
    $folder = mailboxFolder();

    SyncFolder::dispatch($folder);
    SyncFolder::dispatch($folder);

    Queue::assertPushed(SyncFolder::class, 1);
});

it('uses the configured queue, retry window and throttle', function () {
    config()->set('msgraph-mailbox.queue', ['connection' => 'redis', 'queue' => 'graph']);
    config()->set('msgraph-mailbox.throttle.max_exceptions', 7);
    config()->set('msgraph-mailbox.throttle.retry_until_hours', 2);

    $job = new MarkMessageRead('support@example.com', 'msg-1');

    $sync = new SyncFolder(mailboxFolder());

    expect($job->connection)->toBe('redis')
        ->and($job->queue)->toBe('graph')
        ->and($job->maxExceptions)->toBe(7)
        ->and($sync->connection)->toBe('redis')
        ->and($sync->queue)->toBe('graph')
        ->and($job->retryUntil()->diffInMinutes(now()->addHours(2)))->toBeLessThan(1.0)
        ->and($job->middleware()[0])->toBeInstanceOf(ThrottleGraphRequests::class);
});

it('survives the queue serialization roundtrip', function () {
    $folder = mailboxFolder();

    $sync = unserialize(serialize(new SyncFolder($folder)));
    $refresh = unserialize(serialize(new RefreshSubscription($folder)));
    $original = new MoveMessage('support@example.com', 'msg-1', WellKnownFolder::Archive);
    $move = unserialize(serialize($original));

    expect($sync->folder->is($folder))->toBeTrue()
        ->and($refresh->folder->is($folder))->toBeTrue()
        ->and($move->folder)->toBe(WellKnownFolder::Archive)
        ->and($move->messageId)->toBe('msg-1')
        ->and($move->uniqueId())->toBe($original->uniqueId());
});
