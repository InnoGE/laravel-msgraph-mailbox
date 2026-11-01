<?php

declare(strict_types=1);

namespace InnoGE\LaravelMsGraphMailbox\Enums;

enum FlagStatus: string
{
    case NotFlagged = 'notFlagged';
    case Flagged = 'flagged';
    case Complete = 'complete';
}
