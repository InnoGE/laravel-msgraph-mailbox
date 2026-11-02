<?php

declare(strict_types=1);

namespace InnoGE\LaravelMsGraphMailbox;

use Illuminate\Support\Str;
use InnoGE\LaravelMsGraphMailbox\Enums\ChangeType;
use InnoGE\LaravelMsGraphMailbox\Enums\WellKnownFolder;
use InnoGE\LaravelMsGraphMailbox\Events\MessageChanged;
use InnoGE\LaravelMsGraphMailbox\Events\MessageReceived;
use InnoGE\LaravelMsGraphMailbox\Events\MessageRemoved;
use InnoGE\LaravelMsGraphMailbox\Exceptions\InvalidConfigurationException;

final readonly class ObservedMailbox
{
    public const array DEFAULT_FOLDERS = [WellKnownFolder::Inbox];

    public const array DEFAULT_EVENTS = [MessageReceived::class, MessageRemoved::class];

    private const array CHANGE_TYPES = [
        MessageReceived::class => ChangeType::Created,
        MessageChanged::class => ChangeType::Updated,
        MessageRemoved::class => ChangeType::Deleted,
    ];

    public string $address;

    /** @var list<WellKnownFolder|string> */
    public array $folders;

    /** @var list<ChangeType> */
    public array $changeTypes;

    /**
     * @param  array<array-key, mixed>  $folders
     * @param  array<array-key, mixed>  $events
     */
    public function __construct(
        string $address,
        array $folders = self::DEFAULT_FOLDERS,
        array $events = self::DEFAULT_EVENTS,
    ) {
        $address = self::normalize($address);
        if (! self::isAddress($address)) {
            throw new InvalidConfigurationException('mailboxes', "expected an email address as mailbox, got '{$address}'");
        }

        if ($folders === [] || $events === []) {
            throw new InvalidConfigurationException("mailboxes.{$address}", 'folders and events must not be empty');
        }

        $this->address = $address;
        $this->folders = array_values(array_map(fn (mixed $folder) => self::folder($address, $folder), $folders));
        $this->changeTypes = array_values(array_unique(array_map(fn (mixed $event) => self::changeType($address, $event), $events), SORT_REGULAR));
    }

    public static function normalize(string $address): string
    {
        return Str::of($address)->trim()->lower()->value();
    }

    public static function isAddress(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_EMAIL) !== false;
    }

    private static function folder(string $address, mixed $folder): WellKnownFolder|string
    {
        if ($folder instanceof WellKnownFolder) {
            return $folder;
        }

        if (! is_string($folder)) {
            throw new InvalidConfigurationException("mailboxes.{$address}.folders", 'expected WellKnownFolder cases or strings, got '.get_debug_type($folder));
        }

        return Folders::normalize($folder);
    }

    private static function changeType(string $address, mixed $event): ChangeType
    {
        return self::CHANGE_TYPES[is_string($event) ? $event : '']
            ?? throw new InvalidConfigurationException("mailboxes.{$address}.events", 'expected MessageReceived::class, MessageChanged::class or MessageRemoved::class');
    }
}
