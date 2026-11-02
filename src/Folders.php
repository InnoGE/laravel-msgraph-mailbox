<?php

declare(strict_types=1);

namespace InnoGE\LaravelMsGraphMailbox;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InnoGE\LaravelMsGraphMail\Graph\GraphClient;
use InnoGE\LaravelMsGraphMail\Graph\InvalidResponseException;
use InnoGE\LaravelMsGraphMailbox\Enums\WellKnownFolder;
use InnoGE\LaravelMsGraphMailbox\Exceptions\FolderNotFoundException;
use InnoGE\LaravelMsGraphMailbox\Models\MailboxFolder;
use InvalidArgumentException;

/** @internal */
final readonly class Folders
{
    private const int CACHE_SECONDS = 3600;

    public function __construct(private GraphClient $graph) {}

    public static function key(WellKnownFolder|string $folder): string
    {
        return $folder instanceof WellKnownFolder ? $folder->value : $folder;
    }

    public static function normalize(WellKnownFolder|string $folder): WellKnownFolder|string
    {
        if ($folder instanceof WellKnownFolder) {
            return $folder;
        }

        $segments = Str::of($folder)->explode('/')->map(fn (string $segment) => Str::trim($segment));

        if ($segments->contains(fn (string $segment) => $segment === '' || Str::isMatch('/[\x00-\x1F\x7F\\\\]/', $segment))) {
            throw new InvalidArgumentException('Folder paths must consist of non-empty display names separated by "/".');
        }

        return WellKnownFolder::tryFrom($segments->implode('/')) ?? $segments->implode('/');
    }

    public function id(string $mailbox, WellKnownFolder|string $folder, bool $createMissing = false): string
    {
        $folder = self::normalize($folder);

        return Cache::remember(
            'msgraph-mailbox:folder:'.hash('xxh128', $mailbox."\0".self::key($folder)),
            self::CACHE_SECONDS,
            fn (): string => $folder instanceof WellKnownFolder
                ? $this->wellKnownId($mailbox, $folder)
                : $this->resolvePath($mailbox, $folder, $createMissing),
        );
    }

    public function idOf(MailboxFolder $folder): string
    {
        return $folder->folder_id ??= $this->id($folder->mailbox, $folder->folder);
    }

    private function wellKnownId(string $mailbox, WellKnownFolder $folder): string
    {
        return $this->string($this->graph->request()
            ->get($this->graph->path('users', $mailbox, 'mailFolders', $folder->value), ['$select' => 'id'])
            ->throw()
            ->json('id'));
    }

    private function resolvePath(string $mailbox, string $path, bool $createMissing): string
    {
        $parentId = null;

        foreach (Str::of($path)->explode('/') as $name) {
            $collection = $parentId === null
                ? $this->graph->path('users', $mailbox, 'mailFolders')
                : $this->graph->path('users', $mailbox, 'mailFolders', $parentId, 'childFolders');

            $id = $this->graph->request()
                ->get($collection, ['$filter' => "displayName eq '".Str::replace("'", "''", $name)."'", '$select' => 'id'])
                ->throw()
                ->json('value.0.id');

            if ($id === null && ! $createMissing) {
                throw new FolderNotFoundException($mailbox, $path);
            }

            $parentId = $this->string($id ?? $this->graph->request()->post($collection, ['displayName' => $name])->throw()->json('id'));
        }

        return (string) $parentId;
    }

    private function string(mixed $id): string
    {
        if (! is_string($id) || $id === '') {
            throw new InvalidResponseException('Microsoft Graph returned a folder without an id.');
        }

        return $id;
    }
}
