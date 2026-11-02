<?php

declare(strict_types=1);

use InnoGE\LaravelMsGraphMailbox\Exceptions\MailboxNotAllowedException;
use InnoGE\LaravelMsGraphMailbox\Facades\Mailbox;

it('acts on observed mailboxes, case-insensitively', function () {
    expect(Mailbox::for(' Support@Example.COM ')->address)->toBe('support@example.com');
});

it('refuses mailboxes that are neither observed nor allowed', function () {
    Mailbox::for('ceo@example.com');
})->throws(MailboxNotAllowedException::class, 'Mailbox [ceo@example.com] is neither observed nor listed');

it('allows mailboxes matching an allowed pattern', function () {
    config()->set('msgraph-mailbox.allowed_mailboxes', ['*@Example.com', 42]);

    expect(Mailbox::allows('CEO@example.com'))->toBeTrue()
        ->and(Mailbox::allows('ceo@other.com'))->toBeFalse();
});

it('allows every mailbox with the wildcard', function () {
    config()->set('msgraph-mailbox.allowed_mailboxes', ['*']);

    expect(Mailbox::for('anyone@anywhere.com')->address)->toBe('anyone@anywhere.com');
});

it('skips the check for explicitly unrestricted access', function () {
    expect(Mailbox::forUnrestricted('ceo@example.com')->message('id')->mailbox)->toBe('ceo@example.com')
        ->and(fn () => Mailbox::forUnrestricted('8b0f6a2e-7c1d-4e3a-9f5b-2d6c8e1a4b7f'))->toThrow(InvalidArgumentException::class, 'Expected an email address');
});
