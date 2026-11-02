<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use InnoGE\LaravelMsGraphMailbox\Enums\WellKnownFolder;
use InnoGE\LaravelMsGraphMailbox\Events\MessageChanged;
use InnoGE\LaravelMsGraphMailbox\Events\MessageDeleted;
use InnoGE\LaravelMsGraphMailbox\Events\MessageEvent;
use InnoGE\LaravelMsGraphMailbox\Events\MessageMoved;
use InnoGE\LaravelMsGraphMailbox\Events\MessagePermanentlyDeleted;
use InnoGE\LaravelMsGraphMailbox\Events\MessageReceived;
use InnoGE\LaravelMsGraphMailbox\Events\MessageRemoved;
use InnoGE\LaravelMsGraphMailbox\Exceptions\MailboxNotAllowedException;
use InnoGE\LaravelMsGraphMailbox\Facades\Mailbox;

it('resolves the message lazily', function () {
    Mailbox::fake()->addMessage('support@example.com', 'msg-1', ['subject' => 'Hello']);

    $event = unserialize(serialize(new MessageReceived('support@example.com', 'inbox', 'msg-1')));

    expect($event->message()->subject())->toBe('Hello')
        ->and((new MessageMoved('support@example.com', 'inbox', 'msg-1', 'archive-id'))->message()->id)->toBe('msg-1');
});

it('reaches listeners of the MessageEvent interface', function () {
    $received = [];
    Event::listen(MessageEvent::class, function (MessageEvent $event) use (&$received) {
        $received[] = $event::class;
    });

    event(new MessageReceived('support@example.com', 'inbox', 'a'));
    event(new MessageChanged('support@example.com', 'inbox', 'a'));
    event(new MessageMoved('support@example.com', 'inbox', 'a', 'archive-id'));
    event(new MessageDeleted('support@example.com', 'inbox', 'a'));
    event(new MessagePermanentlyDeleted('support@example.com', 'inbox', 'a'));

    expect($received)->toBe([
        MessageReceived::class,
        MessageChanged::class,
        MessageMoved::class,
        MessageDeleted::class,
        MessagePermanentlyDeleted::class,
    ]);
});

it('reaches listeners of every removal through MessageRemoved', function () {
    $received = [];
    Event::listen(MessageRemoved::class, function (MessageRemoved $event) use (&$received) {
        $received[] = $event::class;
    });

    event(new MessageReceived('support@example.com', 'inbox', 'a'));
    event(new MessageMoved('support@example.com', 'inbox', 'a', 'archive-id'));
    event(new MessageDeleted('support@example.com', 'inbox', 'a'));
    event(new MessagePermanentlyDeleted('support@example.com', 'inbox', 'a'));

    expect($received)->toBe([MessageMoved::class, MessageDeleted::class, MessagePermanentlyDeleted::class]);
});

it('tells where a message was moved to', function () {
    fakeGraph([
        'GET /users/support@example.com/mailFolders/archive' => Http::response(['id' => 'archive-id']),
        'GET /users/support@example.com/mailFolders' => Http::response(['value' => []]),
    ]);

    $event = new MessageMoved('support@example.com', 'inbox', 'a', 'archive-id');

    expect($event->movedTo(WellKnownFolder::Archive))->toBeTrue()
        ->and($event->movedTo('archive'))->toBeTrue()
        ->and($event->movedTo('Missing'))->toBeFalse();
});

it('can be dispatched like any Laravel event', function () {
    Event::fake([MessageReceived::class]);

    MessageReceived::dispatch('support@example.com', 'inbox', 'a');

    Event::assertDispatched(MessageReceived::class, fn (MessageReceived $event) => $event->messageId === 'a');
});

it('refuses mailboxes that are no longer observed', function () {
    (new MessageReceived('old@example.com', 'inbox', 'msg-1'))->message();
})->throws(MailboxNotAllowedException::class);
