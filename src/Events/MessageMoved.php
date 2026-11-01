<?php

declare(strict_types=1);

namespace InnoGE\LaravelMsGraphMailbox\Events;

use InnoGE\LaravelMsGraphMailbox\Enums\WellKnownFolder;
use InnoGE\LaravelMsGraphMailbox\Exceptions\FolderNotFoundException;
use InnoGE\LaravelMsGraphMailbox\Folders;

final readonly class MessageMoved extends AbstractMessageEvent implements MessageRemoved
{
    public function __construct(
        string $mailbox,
        string $folder,
        string $messageId,
        public string $toFolderId,
    ) {
        parent::__construct($mailbox, $folder, $messageId);
    }

    /**
     * Determine if the message was moved to the given folder.
     */
    public function movedTo(WellKnownFolder|string $folder): bool
    {
        try {
            return app(Folders::class)->id($this->mailbox, $folder) === $this->toFolderId;
        } catch (FolderNotFoundException) {
            return false;
        }
    }
}
