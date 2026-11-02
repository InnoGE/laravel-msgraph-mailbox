<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InnoGE\LaravelMsGraphMail\Graph\GraphClient;
use InnoGE\LaravelMsGraphMail\Graph\InvalidCredentialsException;
use InnoGE\LaravelMsGraphMailbox\Enums\ChangeType;
use InnoGE\LaravelMsGraphMailbox\Enums\WellKnownFolder;
use InnoGE\LaravelMsGraphMailbox\Events\MessageChanged;
use InnoGE\LaravelMsGraphMailbox\Events\MessageReceived;
use InnoGE\LaravelMsGraphMailbox\Events\MessageRemoved;
use InnoGE\LaravelMsGraphMailbox\Exceptions\InvalidConfigurationException;
use InnoGE\LaravelMsGraphMailbox\Exceptions\MissingCredentialsException;
use InnoGE\LaravelMsGraphMailbox\Facades\Mailbox;
use InnoGE\LaravelMsGraphMailbox\ObservedMailbox;

it('reads mailboxes from config as a plain list or with options', function () {
    config()->set('msgraph-mailbox.mailboxes', [
        'info@example.com',
        'Support@Example.com' => ['folders' => ['inbox', ' Support / Escalations '], 'events' => MessageChanged::class],
    ]);

    [$info, $support] = Mailbox::observed();

    expect($info->folders)->toBe([WellKnownFolder::Inbox])
        ->and($info->changeTypes)->toBe([ChangeType::Created, ChangeType::Deleted])
        ->and($support->address)->toBe('support@example.com')
        ->and($support->folders)->toBe([WellKnownFolder::Inbox, 'Support/Escalations'])
        ->and($support->changeTypes)->toBe([ChangeType::Updated]);
});

it('resolves mailboxes with a closure in the same shape as the config', function () {
    Mailbox::observe(fn () => collect(['db@example.com', 'team@example.com' => ['events' => [MessageReceived::class]]]));

    expect(Mailbox::observed())->sequence(
        fn ($mailbox) => $mailbox->address->toBe('db@example.com'),
        fn ($mailbox) => $mailbox->changeTypes->toBe([ChangeType::Created]),
    )->and(Mailbox::allows('support@example.com'))->toBeFalse();
});

it('observes a plain list of mailboxes', function () {
    Mailbox::observe(['list@example.com']);

    expect(Mailbox::observed())->toHaveCount(1)
        ->and(Mailbox::allows('list@example.com'))->toBeTrue();
});

it('accepts user ids as mailbox', function () {
    expect((new ObservedMailbox('6F1C1E2A-6C1B-4C4B-9E43-7A1F2B3C4D5E'))->address)->toBe('6f1c1e2a-6c1b-4c4b-9e43-7a1f2b3c4d5e');
});

it('deduplicates events', function () {
    expect((new ObservedMailbox('a@example.com', events: [MessageRemoved::class, MessageRemoved::class]))->changeTypes)->toBe([ChangeType::Deleted]);
});

it('rejects invalid mailbox definitions', function (Closure $definition, string $message) {
    expect($definition)->toThrow(InvalidConfigurationException::class, $message);
})->with([
    'no address' => [fn () => new ObservedMailbox('not-an-address'), 'expected an email address or user id'],
    'no folders' => [fn () => new ObservedMailbox('a@example.com', []), 'must not be empty'],
    'no events' => [fn () => new ObservedMailbox('a@example.com', events: []), 'must not be empty'],
    'unknown event' => [fn () => new ObservedMailbox('a@example.com', events: ['created']), 'expected MessageReceived::class'],
    'non string event' => [fn () => new ObservedMailbox('a@example.com', events: [1]), 'expected MessageReceived::class'],
    'non string folder' => [fn () => new ObservedMailbox('a@example.com', [1]), 'got int'],
]);

it('rejects folder paths with empty segments', function () {
    new ObservedMailbox('a@example.com', ['Support//Escalations']);
})->throws(InvalidArgumentException::class);

it('rejects a malformed mailbox list', function (array $mailboxes) {
    config()->set('msgraph-mailbox.mailboxes', $mailboxes);

    Mailbox::observed();
})->with([
    'options without address' => [[['folders' => ['inbox']]]],
    'address without options' => [['support@example.com' => 'inbox']],
])->throws(InvalidConfigurationException::class, 'expected a list of addresses or a map of address => options');

it('fails on first graph use, not on boot, when credentials are missing', function () {
    config()->set('msgraph-mailbox.credentials', []);

    expect(fn () => app(GraphClient::class))->toThrow(MissingCredentialsException::class, 'Set MICROSOFT_GRAPH_TENANT_ID');
});

it('requires a secret or a private key', function (array $credentials) {
    config()->set('msgraph-mailbox.credentials', ['tenant_id' => 'tenant', 'client_id' => 'client', ...$credentials]);

    app(GraphClient::class);
})->with([
    'no secret' => [['client_secret' => '']],
    'certificate without key' => [['client_certificate' => ['certificate' => 'cert', 'private_key' => null]]],
])->throws(MissingCredentialsException::class);

it('authenticates with a client certificate when one is configured', function () {
    config()->set('msgraph-mailbox.credentials.client_certificate', [
        'certificate' => __DIR__.'/Fixtures/test-certificate.pem',
        'private_key' => __DIR__.'/Fixtures/test-private-key.pem',
        'passphrase' => null,
    ]);
    Http::fake([
        'https://login.microsoftonline.com/*' => Http::response(['access_token' => 'token', 'expires_in' => 3599]),
        'https://graph.microsoft.com/*' => Http::response(),
    ]);

    app(GraphClient::class)->request()->get('/me');

    Http::assertSent(fn (Request $request) => Str::startsWith($request->url(), 'https://login.microsoftonline.com')
        && Str::contains($request->body(), 'client_assertion=') && ! Str::contains($request->body(), 'client_secret='));
});

it('passes a configured passphrase to the certificate', function () {
    config()->set('msgraph-mailbox.credentials.client_certificate', [
        'certificate' => __DIR__.'/Fixtures/test-certificate-encrypted.pem',
        'private_key' => __DIR__.'/Fixtures/test-private-key-encrypted.pem',
        'passphrase' => 'wrong',
    ]);

    app(GraphClient::class);
})->throws(InvalidCredentialsException::class, 'wrong passphrase or malformed PEM');
