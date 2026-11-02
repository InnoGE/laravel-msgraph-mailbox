<?php

declare(strict_types=1);

namespace InnoGE\LaravelMsGraphMailbox\Http;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use InnoGE\LaravelMsGraphMailbox\Enums\LifecycleEvent;
use InnoGE\LaravelMsGraphMailbox\Jobs\RefreshSubscription;
use InnoGE\LaravelMsGraphMailbox\Jobs\SyncFolder;
use InnoGE\LaravelMsGraphMailbox\Models\MailboxFolder;

/** @internal */
final class WebhookController
{
    private const int MAX_NOTIFICATIONS = 100;

    private const int MAX_VALIDATION_TOKEN_LENGTH = 1024;

    public function __invoke(Request $request): Response
    {
        $validationToken = $request->query('validationToken');
        if ($validationToken !== null) {
            return is_string($validationToken) && Str::length($validationToken) <= self::MAX_VALIDATION_TOKEN_LENGTH
                ? new Response($validationToken, 200, ['Content-Type' => 'text/plain', 'X-Content-Type-Options' => 'nosniff'])
                : new Response('', 400);
        }

        $notifications = $request->json('value');
        if (! is_array($notifications) || $notifications === [] || count($notifications) > self::MAX_NOTIFICATIONS) {
            return new Response('', 400);
        }

        $received = count($notifications);
        $notifications = array_filter($notifications, fn (mixed $notification) => is_array($notification)
            && is_string($notification['subscriptionId'] ?? null)
            && is_string($notification['clientState'] ?? null));

        $folders = [];
        MailboxFolder::query()
            ->whereIn('subscription_id', array_column($notifications, 'subscriptionId'))
            ->each(function (MailboxFolder $folder) use (&$folders): void {
                $folders[(string) $folder->subscription_id] = $folder;
            });

        $accepted = 0;
        $changed = [];

        foreach ($notifications as $notification) {
            $folder = $folders[$notification['subscriptionId']] ?? null;

            if ($folder === null || ! $folder->verifiesClientState($notification['clientState'])) {
                continue;
            }

            $accepted++;

            if (array_key_exists('lifecycleEvent', $notification)) {
                $this->handleLifecycle($folder, $notification['lifecycleEvent']);
            } else {
                $changed[$folder->id] = $folder;
            }
        }

        foreach ($changed as $folder) {
            $folder->last_notification_at = CarbonImmutable::now();
            $folder->save();

            SyncFolder::dispatch($folder);
        }

        if ($accepted < $received) {
            RateLimiter::attempt('msgraph-mailbox:rejected-notifications', 10, fn () => Log::warning(
                'Rejected Microsoft Graph notifications with an unknown subscription or client state.',
                ['rejected' => $received - $accepted],
            ));
        }

        return new Response('', $accepted > 0 ? 202 : 403);
    }

    private function handleLifecycle(MailboxFolder $folder, mixed $lifecycleEvent): void
    {
        match (LifecycleEvent::tryFrom(is_string($lifecycleEvent) ? $lifecycleEvent : '')) {
            LifecycleEvent::Missed => SyncFolder::dispatch($folder),
            LifecycleEvent::ReauthorizationRequired, LifecycleEvent::SubscriptionRemoved => RefreshSubscription::dispatch($folder),
            null => Log::warning('Ignored an unknown Microsoft Graph lifecycle event.', [
                'lifecycleEvent' => Str::limit(is_string($lifecycleEvent) ? $lifecycleEvent : get_debug_type($lifecycleEvent), 64),
                'subscriptionId' => $folder->subscription_id,
            ]),
        };
    }
}
