<?php

declare(strict_types=1);

namespace InnoGE\LaravelMsGraphMailbox\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InnoGE\LaravelMsGraphMailbox\Enums\ChangeType;
use InnoGE\LaravelMsGraphMailbox\ObservedMailbox;

/**
 * @property int $id
 * @property string $mailbox
 * @property string $folder
 * @property string|null $folder_id
 * @property Collection<int, ChangeType> $change_types
 * @property string|null $subscription_id
 * @property string|null $client_state_hash
 * @property CarbonImmutable|null $subscription_expires_at
 * @property string|null $delta_link
 * @property CarbonImmutable|null $walk_started_at
 * @property CarbonImmutable|null $baselined_at
 * @property CarbonImmutable|null $last_notification_at
 * @property CarbonImmutable|null $last_synced_at
 *
 * @internal
 */
final class MailboxFolder extends Model
{
    protected $fillable = ['mailbox', 'folder', 'change_types'];

    protected $hidden = ['client_state_hash', 'delta_link'];

    /**
     * @return HasMany<MailboxMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(MailboxMessage::class);
    }

    public function reports(ChangeType $type): bool
    {
        return $this->baselined_at !== null && $this->change_types->contains($type);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function forMailbox(Builder $query, ?string $mailbox): void
    {
        $query->when($mailbox !== null, fn (Builder $query) => $query->where('mailbox', ObservedMailbox::normalize((string) $mailbox)));
    }

    public function isWalking(): bool
    {
        return $this->walk_started_at !== null;
    }

    public function restartWalk(): void
    {
        $this->delta_link = null;
        $this->walk_started_at = CarbonImmutable::now();
        $this->save();
    }

    public function advanceTo(string $nextLink): void
    {
        $this->delta_link = $nextLink;
        $this->save();
    }

    public function completeSync(string $deltaLink): void
    {
        $this->delta_link = $deltaLink;
        $this->walk_started_at = null;
        $this->baselined_at ??= CarbonImmutable::now();
        $this->last_synced_at = CarbonImmutable::now();
        $this->save();
    }

    public function rotateClientState(): string
    {
        $clientState = Str::random(64);
        $this->client_state_hash = hash('sha256', $clientState);

        return $clientState;
    }

    public function verifiesClientState(string $clientState): bool
    {
        return $this->client_state_hash !== null && hash_equals($this->client_state_hash, hash('sha256', $clientState));
    }

    protected function casts(): array
    {
        return [
            'change_types' => AsEnumCollection::of(ChangeType::class),
            'subscription_expires_at' => 'immutable_datetime',
            'walk_started_at' => 'immutable_datetime',
            'baselined_at' => 'immutable_datetime',
            'last_notification_at' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
        ];
    }
}
