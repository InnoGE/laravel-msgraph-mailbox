<?php

declare(strict_types=1);

namespace InnoGE\LaravelMsGraphMailbox\Jobs;

use InnoGE\LaravelMsGraphMailbox\Models\MailboxFolder;

/** @internal */
abstract class FolderJob extends GraphJob
{
    public bool $deleteWhenMissingModels = true;

    public function __construct(public readonly MailboxFolder $folder)
    {
        parent::__construct();
    }

    public function uniqueId(): string
    {
        return (string) $this->folder->id;
    }

    protected function mailbox(): string
    {
        return $this->folder->mailbox;
    }
}
