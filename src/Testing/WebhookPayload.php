<?php

declare(strict_types=1);

namespace InnoGE\LaravelMsGraphMailbox\Testing;

use Illuminate\Support\Str;
use InnoGE\LaravelMsGraphMailbox\Enums\ChangeType;
use InnoGE\LaravelMsGraphMailbox\Enums\LifecycleEvent;
use InnoGE\LaravelMsGraphMailbox\Models\MailboxFolder;

final class WebhookPayload
{
    /**
     * Build a change notification for the given folder.
     *
     * @return array{value: list<array<string, mixed>>}
     */
    public static function change(MailboxFolder $folder, string $messageId = 'message-id', ChangeType $changeType = ChangeType::Created): array
    {
        return self::payload($folder, [
            'changeType' => $changeType->value,
            'resource' => "Users/{$folder->mailbox}/Messages/{$messageId}",
            'resourceData' => ['@odata.type' => '#Microsoft.Graph.Message', 'id' => $messageId],
        ]);
    }

    /**
     * Build a lifecycle notification for the given folder.
     *
     * @return array{value: list<array<string, mixed>>}
     */
    public static function lifecycle(MailboxFolder $folder, LifecycleEvent $event): array
    {
        return self::payload($folder, ['lifecycleEvent' => $event->value]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{value: list<array<string, mixed>>}
     */
    private static function payload(MailboxFolder $folder, array $attributes): array
    {
        $folder->subscription_id ??= (string) Str::uuid();
        $clientState = $folder->rotateClientState();
        $folder->save();

        return ['value' => [[
            'subscriptionId' => $folder->subscription_id,
            'clientState' => $clientState,
            'subscriptionExpirationDateTime' => $folder->subscription_expires_at?->toIso8601ZuluString(),
            'tenantId' => (string) Str::uuid(),
            ...$attributes,
        ]]];
    }
}
