<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use InnoGE\LaravelMsGraphMailbox\Jobs\SyncFolder;
use InnoGE\LaravelMsGraphMailbox\Models\MailboxFolder;

function installArtifacts(): array
{
    return [config_path('msgraph-mailbox.php'), base_path('.env'), ...File::glob(database_path('migrations/*_create_mailbox_tables.php'))];
}

it('installs config, migration, env variables and the first mailbox', function () {
    File::put(base_path('.env'), "APP_NAME=Test\nMICROSOFT_GRAPH_TENANT_ID=tenant\n");

    try {
        $this->artisan('msgraph-mailbox:install')
            ->expectsQuestion('Which mailbox would you like to observe? (leave empty to skip)', 'support@example.com')
            ->expectsConfirmation('Would you like to run the migrations now?', 'no')
            ->expectsOutputToContain('Added MICROSOFT_GRAPH_CLIENT_ID, MICROSOFT_GRAPH_CLIENT_SECRET to your .env file.')
            ->expectsOutputToContain('php artisan msgraph-mailbox:subscribe')
            ->assertSuccessful();

        expect(File::get(base_path('.env')))->toBe("APP_NAME=Test\nMICROSOFT_GRAPH_TENANT_ID=tenant\n\nMICROSOFT_GRAPH_CLIENT_ID=\nMICROSOFT_GRAPH_CLIENT_SECRET=\n")
            ->and(File::get(config_path('msgraph-mailbox.php')))->toContain("'mailboxes' => ['support@example.com'],")
            ->and(File::glob(database_path('migrations/*_create_mailbox_tables.php')))->toHaveCount(1);
    } finally {
        File::delete(installArtifacts());
    }
});

it('leaves complete env files alone and skips invalid mailboxes', function () {
    File::put(base_path('.env'), "MICROSOFT_GRAPH_TENANT_ID=t\nMICROSOFT_GRAPH_CLIENT_ID=c\nMICROSOFT_GRAPH_CLIENT_SECRET=s\n");

    try {
        $this->artisan('msgraph-mailbox:install')
            ->expectsQuestion('Which mailbox would you like to observe? (leave empty to skip)', 'not an address')
            ->expectsConfirmation('Would you like to run the migrations now?', 'no')
            ->expectsOutputToContain('is not an email address')
            ->assertSuccessful();

        expect(File::get(base_path('.env')))->toBe("MICROSOFT_GRAPH_TENANT_ID=t\nMICROSOFT_GRAPH_CLIENT_ID=c\nMICROSOFT_GRAPH_CLIENT_SECRET=s\n")
            ->and(File::get(config_path('msgraph-mailbox.php')))->toContain("'mailboxes' => [],");
    } finally {
        File::delete(installArtifacts());
    }
});

it('runs the migrations on request and works without an env file', function () {
    Schema::drop('mailbox_messages');
    Schema::drop('mailbox_folders');

    try {
        $this->artisan('msgraph-mailbox:install')
            ->expectsQuestion('Which mailbox would you like to observe? (leave empty to skip)', '')
            ->expectsConfirmation('Would you like to run the migrations now?', 'yes')
            ->assertSuccessful();

        expect(Schema::hasTable('mailbox_folders'))->toBeTrue();
    } finally {
        File::delete(installArtifacts());
    }
});

it('reconciles subscriptions', function () {
    fakeGraph([
        'GET /users/support@example.com/mailFolders/inbox' => Http::response(['id' => 'inbox-id']),
        'POST /subscriptions' => Http::response(['id' => 'sub-1'], 201),
    ]);

    $this->artisan('msgraph-mailbox:subscribe')
        ->expectsTable(['Mailbox', 'Folder', 'Action'], [['support@example.com', 'inbox', 'subscribed']])
        ->assertSuccessful();
});

it('fails the subscribe command when a folder fails', function () {
    fakeGraph(['GET /users/support@example.com/mailFolders/inbox' => Http::response([], 500)]);

    $this->artisan('msgraph-mailbox:subscribe')->assertFailed();
});

it('subscribes without credentials when no mailboxes are observed', function () {
    config()->set('msgraph-mailbox.mailboxes', []);
    config()->set('msgraph-mailbox.credentials', []);

    $this->artisan('msgraph-mailbox:subscribe')->expectsOutputToContain('No mailboxes are observed.')->assertSuccessful();
    $this->artisan('msgraph-mailbox:unsubscribe')->expectsOutputToContain('Deleted 0 subscription(s).')->assertSuccessful();
});

it('queues syncs, optionally filtered and reset', function () {
    Bus::fake();
    $inbox = mailboxFolder(['delta_link' => 'https://graph.microsoft.com/v1.0/x']);
    mailboxFolder(['folder' => 'Support/Escalations']);
    mailboxFolder(['mailbox' => 'info@example.com']);

    $this->artisan('msgraph-mailbox:sync')->expectsOutputToContain('Queued the sync of 3 folder(s).')->assertSuccessful();
    Bus::assertDispatchedTimes(SyncFolder::class, 3);
    Bus::assertNotDispatched(SyncFolder::class, fn (SyncFolder $job) => $job->reset);

    $this->artisan('msgraph-mailbox:sync', ['mailbox' => 'SUPPORT@example.com', '--folder' => 'inbox', '--reset' => true])
        ->expectsOutputToContain('Queued the sync of 1 folder(s).');
    Bus::assertDispatched(SyncFolder::class, fn (SyncFolder $job) => $job->reset && $job->folder->is($inbox));
    expect($inbox->fresh())->delta_link->not->toBeNull()->walk_started_at->toBeNull();
});

it('deletes subscriptions but keeps the sync state', function () {
    fakeGraph(['DELETE /subscriptions/*' => Http::response([], 204)]);
    mailboxFolder(['subscription_id' => 'sub-1', 'delta_link' => 'https://graph.microsoft.com/v1.0/x']);
    mailboxFolder(['mailbox' => 'info@example.com', 'subscription_id' => 'sub-2']);

    $this->artisan('msgraph-mailbox:unsubscribe', ['mailbox' => 'support@example.com'])
        ->expectsOutputToContain('Deleted 1 subscription(s).')
        ->assertSuccessful();

    expect(MailboxFolder::where('mailbox', 'support@example.com')->sole())
        ->subscription_id->toBeNull()
        ->delta_link->not->toBeNull();
});

it('reports a healthy status', function () {
    mailboxFolder(['subscription_expires_at' => now()->addDays(2), 'baselined_at' => now(), 'last_synced_at' => now(), 'last_notification_at' => now()]);

    $this->artisan('msgraph-mailbox:status')->assertSuccessful();
});

it('fails the status on expiring subscriptions and missing baselines', function () {
    mailboxFolder(['subscription_expires_at' => now()->addMinutes(5)]);

    $this->artisan('msgraph-mailbox:status')
        ->expectsOutputToContain('subscription is missing or expires within the hour')
        ->expectsOutputToContain('no completed sync yet')
        ->assertFailed();
});

it('does not expect subscriptions in polling-only mode', function () {
    config()->set('msgraph-mailbox.webhooks.enabled', false);
    mailboxFolder(['baselined_at' => now()]);

    $this->artisan('msgraph-mailbox:status')->expectsOutputToContain('polling')->assertSuccessful();
});

it('schedules subscribe and sync on one server', function () {
    $events = collect(app(Schedule::class)->events());

    expect($events->map->expression->all())->toBe(['0 * * * *', '*/15 * * * *'])
        ->and($events->every->onOneServer)->toBeTrue()
        ->and($events->pluck('command')->implode(' '))->toContain('msgraph-mailbox:subscribe')->toContain('msgraph-mailbox:sync');
});

it('schedules nothing when disabled', function () {
    $this->bootWith(['msgraph-mailbox.schedule' => false]);

    expect(app(Schedule::class)->events())->toBe([]);
});
