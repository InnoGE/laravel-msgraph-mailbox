<?php

declare(strict_types=1);

namespace InnoGE\LaravelMsGraphMailbox\Exceptions;

use InvalidArgumentException;

final class InvalidConfigurationException extends InvalidArgumentException
{
    public function __construct(string $key, string $reason)
    {
        parent::__construct("msgraph-mailbox.{$key} is invalid: {$reason}.");
    }
}
