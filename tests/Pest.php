<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InnoGE\LaravelMsGraphMailbox\Enums\ChangeType;
use InnoGE\LaravelMsGraphMailbox\Models\MailboxFolder;
use InnoGE\LaravelMsGraphMailbox\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * @param  array<string, mixed>  $routes
 */
function fakeGraph(array $routes = []): void
{
    Http::preventStrayRequests();
    Http::fake(function (Request $request) use ($routes) {
        if (Str::startsWith($request->url(), 'https://login.microsoftonline.com/')) {
            return Http::response(['access_token' => 'token', 'expires_in' => 3599]);
        }

        $path = rawurldecode(Str::after((string) parse_url($request->url(), PHP_URL_PATH), '/v1.0'));

        foreach ($routes as $route => $response) {
            [$method, $pattern] = explode(' ', $route, 2);

            if ($request->method() === $method && Str::is($pattern, $path)) {
                return $response instanceof Closure ? $response($request) : $response;
            }
        }

        return null;
    });
}

function graphRequestCount(): int
{
    return Http::recorded(fn (Request $request) => Str::startsWith($request->url(), 'https://graph.microsoft.com/'))->count();
}

/**
 * @param  array<string, mixed>  $attributes
 */
function mailboxFolder(array $attributes = []): MailboxFolder
{
    $folder = new MailboxFolder;
    $folder->forceFill([
        'mailbox' => 'support@example.com',
        'folder' => 'inbox',
        'folder_id' => 'inbox-id',
        'change_types' => collect([ChangeType::Created, ChangeType::Deleted]),
        ...$attributes,
    ])->save();

    return $folder;
}
