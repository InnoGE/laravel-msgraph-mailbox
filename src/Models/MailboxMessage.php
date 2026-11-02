<?php

declare(strict_types=1);

namespace InnoGE\LaravelMsGraphMailbox\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $mailbox_folder_id
 * @property string $message_id
 * @property string|null $change_key
 * @property CarbonImmutable $seen_at
 *
 * @internal
 */
final class MailboxMessage extends Model
{
    public $timestamps = false;

    protected $fillable = ['mailbox_folder_id', 'message_id', 'change_key', 'seen_at'];

    protected function casts(): array
    {
        return ['seen_at' => 'immutable_datetime'];
    }
}
