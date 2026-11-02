<?php

declare(strict_types=1);

namespace InnoGE\LaravelMsGraphMailbox\Exceptions;

use RuntimeException;

final class FolderNotFoundException extends RuntimeException
{
    public function __construct(string $mailbox, string $folder)
    {
        parent::__construct("Folder [{$folder}] does not exist in mailbox [{$mailbox}].");
    }
}
