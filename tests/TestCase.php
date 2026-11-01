<?php

declare(strict_types=1);

namespace InnoGE\LaravelMsGraphMailbox\Tests;

use Closure;
use Illuminate\Foundation\Application;
use InnoGE\LaravelMsGraphMailbox\Enums\WellKnownFolder;
use InnoGE\LaravelMsGraphMailbox\Events\MessageReceived;
use InnoGE\LaravelMsGraphMailbox\Events\MessageRemoved;
use InnoGE\LaravelMsGraphMailbox\MailboxServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    /** @var (Closure(Application): void)|null */
    public static ?Closure $environment = null;

    protected function tearDown(): void
    {
        static::$environment = null;

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function bootWith(array $config): void
    {
        static::$environment = fn (Application $app) => $app['config']->set($config);

        $this->refreshApplication();
    }

    protected function getPackageProviders($app): array
    {
        return [MailboxServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('msgraph-mailbox.credentials', [
            'tenant_id' => 'tenant',
            'client_id' => 'client',
            'client_secret' => 'secret',
        ]);
        $app['config']->set('msgraph-mailbox.mailboxes', [
            'support@example.com' => [
                'folders' => [WellKnownFolder::Inbox],
                'events' => [MessageReceived::class, MessageRemoved::class],
            ],
        ]);

        if (static::$environment !== null) {
            (static::$environment)($app);
        }
    }

    protected function defineDatabaseMigrations(): void
    {
        (include __DIR__.'/../database/migrations/create_mailbox_tables.php.stub')->up();
    }
}
