<?php

declare(strict_types=1);

namespace InnoGE\LaravelMsGraphMailbox\Exceptions;

use RuntimeException;

final class MissingCredentialsException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Microsoft Graph credentials are missing. Set MICROSOFT_GRAPH_TENANT_ID, MICROSOFT_GRAPH_CLIENT_ID and '
            .'either MICROSOFT_GRAPH_CLIENT_SECRET or MICROSOFT_GRAPH_CERTIFICATE with MICROSOFT_GRAPH_PRIVATE_KEY '
            .'in your .env file, or run: php artisan msgraph-mailbox:install',
        );
    }
}
