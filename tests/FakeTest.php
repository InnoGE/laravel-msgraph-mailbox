<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use InnoGE\LaravelMsGraphMailbox\Enums\BodyType;
use InnoGE\LaravelMsGraphMailbox\Enums\FlagStatus;
use InnoGE\LaravelMsGraphMailbox\Enums\WellKnownFolder;
use InnoGE\LaravelMsGraphMailbox\Events\MessageReceived;
use InnoGE\LaravelMsGraphMailbox\Facades\Mailbox;
use PHPUnit\Framework\ExpectationFailedException;

it('receives a message through the real event dispatcher', function () {
    $fake = Mailbox::fake();
    Event::listen(function (MessageReceived $event) {
        $event->message()->archive();
    });

    $message = $fake->receive('Support@Example.com', ['subject' => "My order hasn't arrived"]);

    expect($message->subject())->toBe("My order hasn't arrived")
        ->and($message->mailbox)->toBe('support@example.com');
    $fake->assertMoved($message->id, WellKnownFolder::Archive, mailbox: 'support@example.com');
});

it('receives into a given folder with a given id', function () {
    Event::fake([MessageReceived::class]);

    Mailbox::fake()->receive('support@example.com', ['id' => 'msg-1'], 'Support/Escalations');

    Event::assertDispatched(MessageReceived::class, fn (MessageReceived $event) => $event->messageId === 'msg-1' && $event->folder === 'Support/Escalations');
});

it('serves seeded messages without graph calls', function () {
    Mailbox::fake()->addMessage('support@example.com', 'msg-1', [
        'subject' => 'Hello',
        'body' => ['content' => 'Hi there'],
        'uniqueBody' => ['content' => 'Hi'],
        'mime' => 'raw',
        'attachments' => [['id' => 'att-1', 'name' => 'a.txt', 'contentBytes' => base64_encode('abc')]],
    ]);

    $message = Mailbox::for('support@example.com')->message('msg-1');

    expect($message->subject())->toBe('Hello')
        ->and($message->body(BodyType::Html))->toBe('Hi there')
        ->and($message->body(unique: true))->toBe('Hi')
        ->and($message->mime()->getContents())->toBe('raw')
        ->and($message->attachments()[0]->size)->toBe(3)
        ->and($message->attachments()[0]->contents())->toBe('abc');
});

it('defaults missing fake content', function () {
    Mailbox::fake()->addMessage('support@example.com', 'msg-1');

    $message = Mailbox::for('support@example.com')->message('msg-1');

    expect($message->body())->toBe('')
        ->and($message->mime()->getContents())->toBe('')
        ->and($message->attachments())->toBe([]);
});

it('complains about unseeded messages and attachments', function () {
    $fake = Mailbox::fake()->addMessage('support@example.com', 'msg-1');

    expect(fn () => Mailbox::for('support@example.com')->message('missing')->subject())->toThrow(RuntimeException::class, 'No fake message [missing]')
        ->and(fn () => $fake->attachmentContent('support@example.com', 'msg-1', 'att-9'))->toThrow(RuntimeException::class, 'No fake attachment [att-9]');
});

it('records actions for assertions', function () {
    $fake = Mailbox::fake()->addMessage('support@example.com', 'msg-1', ['categories' => ['Existing']]);
    $fake->assertNothingPerformed();

    $message = Mailbox::for('support@example.com')->message('msg-1');
    $message->move('archive');
    $copy = $message->copy('Support/Done');
    $message->markRead();
    $message->markUnread();
    $message->flag(FlagStatus::Complete);
    $message->categorize('Red');
    $message->delete();
    $message->permanentDelete();

    expect($copy->id)->not->toBe('msg-1');
    $fake->assertMoved('msg-1', WellKnownFolder::Archive);
    $fake->assertCopied('msg-1', 'Support/Done');
    $fake->assertMarkedRead('msg-1');
    $fake->assertMarkedUnread('msg-1');
    $fake->assertFlagged('msg-1', FlagStatus::Complete);
    $fake->assertCategorized('msg-1', ['Red']);
    $fake->assertCategorized($message, ['Existing', 'Red']);
    $fake->assertMoved($message, WellKnownFolder::Archive);
    $fake->assertDeleted('msg-1');
    $fake->assertPermanentlyDeleted('msg-1');

    $fake->assertMoved('msg-1', WellKnownFolder::Archive, mailbox: 'SUPPORT@example.com');

    expect(fn () => $fake->assertMoved('msg-1', WellKnownFolder::Archive, mailbox: 'other@example.com'))->toThrow(ExpectationFailedException::class, 'in mailbox [other@example.com].')
        ->and(fn () => $fake->assertMoved('msg-1', 'Elsewhere'))->toThrow(ExpectationFailedException::class, 'Message [msg-1] was not moved to [Elsewhere].')
        ->and(fn () => $fake->assertNothingPerformed())->toThrow(ExpectationFailedException::class);
});

it('lists fake messages by folder and date', function () {
    Mailbox::fake()
        ->addMessage('support@example.com', 'old', ['receivedDateTime' => '2025-01-01T00:00:00Z'])
        ->addMessage('support@example.com', 'new', ['receivedDateTime' => '2026-11-01T00:00:00Z'])
        ->addMessage('support@example.com', 'sent', [], WellKnownFolder::SentItems)
        ->addMessage('other@example.com', 'elsewhere');

    $mailbox = Mailbox::for('support@example.com');

    expect(array_column($mailbox->messages()->messages, 'id'))->toBe(['old', 'new'])
        ->and(array_column($mailbox->messages(since: CarbonImmutable::parse('2026-01-01', 'UTC'))->messages, 'id'))->toBe(['new'])
        ->and(array_column($mailbox->messages(WellKnownFolder::SentItems)->messages, 'id'))->toBe(['sent'])
        ->and($mailbox->messages()->next)->toBeNull();
});

it('records replies for assertions', function () {
    $fake = Mailbox::fake()->addMessage('support@example.com', 'msg-1');

    Mailbox::for('support@example.com')->message('msg-1')->replyAll('<p>Hi</p>', [['name' => 'a.txt', 'contentType' => 'text/plain', 'contents' => 'a']]);

    $fake->assertReplied('msg-1');
    $fake->assertReplied('msg-1', fn (string $html, bool $all, array $attachments) => $html === '<p>Hi</p>' && $all && $attachments[0]['name'] === 'a.txt');
});
