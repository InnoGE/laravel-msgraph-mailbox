<?php

declare(strict_types=1);

namespace InnoGE\LaravelMsGraphMailbox;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use InnoGE\LaravelMsGraphMail\Graph\ClientCertificate;
use InnoGE\LaravelMsGraphMail\Graph\ClientSecret;
use InnoGE\LaravelMsGraphMail\Graph\GraphClient;
use InnoGE\LaravelMsGraphMail\Graph\GraphCredentials;
use InnoGE\LaravelMsGraphMailbox\Commands\InstallCommand;
use InnoGE\LaravelMsGraphMailbox\Commands\StatusCommand;
use InnoGE\LaravelMsGraphMailbox\Commands\SubscribeCommand;
use InnoGE\LaravelMsGraphMailbox\Commands\SyncCommand;
use InnoGE\LaravelMsGraphMailbox\Commands\UnsubscribeCommand;
use InnoGE\LaravelMsGraphMailbox\Contracts\MessageGateway;
use InnoGE\LaravelMsGraphMailbox\Exceptions\MissingCredentialsException;
use InnoGE\LaravelMsGraphMailbox\Http\WebhookController;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class MailboxServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-msgraph-mailbox')
            ->hasConfigFile('msgraph-mailbox')
            ->hasMigration('create_mailbox_tables')
            ->hasCommands([
                InstallCommand::class,
                SubscribeCommand::class,
                SyncCommand::class,
                UnsubscribeCommand::class,
                StatusCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(GraphClient::class, fn () => new GraphClient($this->credentials(), ['IdType="ImmutableId"']));
        $this->app->singleton(MailboxManager::class);
        $this->app->bind(MessageGateway::class, GraphMessageGateway::class);
    }

    private function credentials(): GraphCredentials
    {
        $credentials = config()->array('msgraph-mailbox.credentials');
        $value = fn (string $key): ?string => is_string($found = data_get($credentials, $key)) && $found !== '' ? $found : null;

        $certificate = $value('client_certificate.certificate');
        $secret = $certificate === null ? $value('client_secret') : $value('client_certificate.private_key');

        if ($value('tenant_id') === null || $value('client_id') === null || $secret === null) {
            throw new MissingCredentialsException;
        }

        return new GraphCredentials(
            tenantId: (string) $value('tenant_id'),
            clientId: (string) $value('client_id'),
            authentication: $certificate === null
                ? new ClientSecret($secret)
                : new ClientCertificate($certificate, $secret, $value('client_certificate.passphrase')),
        );
    }

    public function packageBooted(): void
    {
        if (config()->boolean('msgraph-mailbox.webhooks.enabled')) {
            Route::post(config()->string('msgraph-mailbox.webhooks.path'), WebhookController::class)
                ->middleware(array_filter(config()->array('msgraph-mailbox.webhooks.middleware'), is_string(...)))
                ->name('msgraph-mailbox.webhook');
        }

        if (config()->boolean('msgraph-mailbox.schedule')) {
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->command(SubscribeCommand::class)->cron(config()->string('msgraph-mailbox.subscriptions.cron'))->onOneServer()->withoutOverlapping();
                $schedule->command(SyncCommand::class)->cron(config()->string('msgraph-mailbox.sync.cron'))->onOneServer();
            });
        }
    }
}
