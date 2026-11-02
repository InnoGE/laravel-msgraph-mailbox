<?php

declare(strict_types=1);

namespace InnoGE\LaravelMsGraphMailbox\Enums;

/** @internal */
enum ChangeType: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
}
