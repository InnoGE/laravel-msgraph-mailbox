<?php

declare(strict_types=1);

namespace InnoGE\LaravelMsGraphMailbox\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/** @internal */
final class InstallCommand extends Command
{
    private const array ENVIRONMENT_VARIABLES = [
        'MICROSOFT_GRAPH_TENANT_ID',
        'MICROSOFT_GRAPH_CLIENT_ID',
        'MICROSOFT_GRAPH_CLIENT_SECRET',
    ];

    protected $signature = 'msgraph-mailbox:install';

    protected $description = 'Install the Microsoft Graph mailbox package';

    public function handle(): int
    {
        $this->callSilently('vendor:publish', ['--tag' => 'msgraph-mailbox-config']);
        $this->callSilently('vendor:publish', ['--tag' => 'msgraph-mailbox-migrations']);
        $this->components->info('Published the configuration file and the migration.');

        $this->addEnvironmentVariables();
        $this->observeFirstMailbox();

        if ($this->confirm('Would you like to run the migrations now?', true)) {
            $this->call('migrate');
        }

        $this->components->info('Fill in your Microsoft Graph credentials in .env, then run: php artisan msgraph-mailbox:subscribe');

        return self::SUCCESS;
    }

    private function addEnvironmentVariables(): void
    {
        $path = $this->laravel->environmentFilePath();

        if (! File::exists($path)) {
            return;
        }

        $environment = File::get($path);
        $missing = array_filter(self::ENVIRONMENT_VARIABLES, fn (string $name) => ! Str::isMatch("/^{$name}=/m", $environment));

        if ($missing === []) {
            return;
        }

        File::append($path, Str::of(PHP_EOL)->append(...array_map(fn (string $name) => $name.'='.PHP_EOL, $missing))->value());
        $this->components->info('Added '.implode(', ', $missing).' to your .env file.');
    }

    private function observeFirstMailbox(): void
    {
        $answer = $this->ask('Which mailbox would you like to observe? (leave empty to skip)');
        $mailbox = Str::trim(is_string($answer) ? $answer : '');
        $config = config_path('msgraph-mailbox.php');

        if ($mailbox === '' || ! File::exists($config)) {
            return;
        }

        if (filter_var($mailbox, FILTER_VALIDATE_EMAIL) === false) {
            $this->components->warn("[{$mailbox}] is not an email address. Add your mailboxes to config/msgraph-mailbox.php yourself.");

            return;
        }

        File::put($config, Str::replace("'mailboxes' => [],", "'mailboxes' => [".var_export($mailbox, true).'],', File::get($config)));
        $this->components->info("Observing [{$mailbox}]. Add more mailboxes in config/msgraph-mailbox.php.");
    }
}
