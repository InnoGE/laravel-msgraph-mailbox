<?php

declare(strict_types=1);

namespace InnoGE\LaravelMsGraphMailbox\Enums;

enum WellKnownFolder: string
{
    case Inbox = 'inbox';
    case Archive = 'archive';
    case DeletedItems = 'deleteditems';
    case SentItems = 'sentitems';
    case Drafts = 'drafts';
    case JunkEmail = 'junkemail';
    case Outbox = 'outbox';
    case RecoverableItemsDeletions = 'recoverableitemsdeletions';
    case RecoverableItemsPurges = 'recoverableitemspurges';
}
