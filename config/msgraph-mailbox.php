<?php

return [

    /*
     * The Microsoft Entra app the package authenticates as. It needs the Microsoft Graph application
     * permission "Mail.ReadWrite" ("Mail.Read" is enough if you never act on messages) with admin consent.
     *
     * The env names are the same as for innoge/laravel-msgraph-mail, so an app that sends and observes
     * mail with the same Entra app only needs these entries once.
     *
     * Authenticate with either a client secret or a certificate. The certificate is used as soon as
     * "certificate" is filled; both "certificate" and "private_key" accept PEM content or a local file path.
     */
    'credentials' => [
        'tenant_id' => env('MICROSOFT_GRAPH_TENANT_ID'),
        'client_id' => env('MICROSOFT_GRAPH_CLIENT_ID'),

        'client_secret' => env('MICROSOFT_GRAPH_CLIENT_SECRET'),

        'client_certificate' => [
            'certificate' => env('MICROSOFT_GRAPH_CERTIFICATE'),
            'private_key' => env('MICROSOFT_GRAPH_PRIVATE_KEY'),
            'passphrase' => env('MICROSOFT_GRAPH_KEY_PASSPHRASE'),
        ],
    ],

    /*
     * The mailboxes to observe. List them by their primary SMTP address (not an alias, Graph reports
     * senders by their primary address) or by their Entra user id.
     *
     * A plain address observes the inbox and fires MessageReceived and every MessageRemoved event:
     *
     *     'mailboxes' => ['support@example.com'],
     *
     * Pass options to pick the folders and events yourself. Folders are WellKnownFolder cases or display
     * name paths like 'Support/Escalations'. Events are MessageReceived::class, MessageRemoved::class
     * (moved, deleted or permanently deleted) and MessageChanged::class. Leave MessageChanged out unless
     * you need it: Outlook changes a message every time it is read, flagged or categorized.
     *
     *     'mailboxes' => [
     *         'support@example.com' => [
     *             'folders' => [WellKnownFolder::Inbox, 'Support/Escalations'],
     *             'events' => [MessageReceived::class, MessageRemoved::class],
     *         ],
     *     ],
     *
     * Mailboxes that live in your database can be passed to Mailbox::observe() in a service provider
     * instead, as a closure returning the same shape.
     */
    'mailboxes' => [],

    /*
     * Mailbox::for() only acts on observed mailboxes, so an address that comes from user input can never
     * reach another mailbox of your tenant. List additional mailboxes the app may act on here, as
     * case-insensitive patterns such as '*@example.com', or '*' for every mailbox the Entra app can access.
     */
    'allowed_mailboxes' => [],

    'webhooks' => [

        /*
         * Microsoft Graph notifies the app about changes through a webhook, so events arrive within
         * seconds. Disable it to rely on the scheduled delta sync alone, e.g. in local development where
         * Microsoft cannot reach your machine. Events are then delayed by up to sync.cron.
         */
        'enabled' => env('MSGRAPH_MAILBOX_WEBHOOKS', true),

        /*
         * The path of the webhook route. It must be reachable by Microsoft over HTTPS.
         */
        'path' => 'webhooks/msgraph-mailbox',

        /*
         * Middleware for the webhook route. It runs outside the "web" group: no session, no CSRF.
         * Do not add rate limiting, Microsoft retries rejected notifications and drops subscriptions
         * that keep failing.
         */
        'middleware' => [],

        /*
         * The URL Microsoft sends notifications to. Leave it null to use the webhook route under your
         * APP_URL, or set it to a tunnel URL (herd share, ngrok) plus the path in local development.
         */
        'notification_url' => env('MSGRAPH_MAILBOX_NOTIFICATION_URL'),
    ],

    'subscriptions' => [

        /*
         * How long a Graph subscription lives before it has to be renewed, in minutes. Subscriptions are
         * renewed once they pass half their lifetime. Microsoft allows at most 10080 minutes (7 days).
         */
        'lifetime_minutes' => 60 * 24 * 3,

        /*
         * When msgraph-mailbox:subscribe runs to create, renew and remove subscriptions (if "schedule" is on).
         */
        'cron' => '0 * * * *',
    ],

    'sync' => [

        /*
         * When the delta sync runs for every observed folder (if "schedule" is on). It catches up on
         * anything a webhook missed, and is the only source of events when webhooks are disabled.
         */
        'cron' => '*/15 * * * *',
    ],

    /*
     * Register msgraph-mailbox:subscribe and msgraph-mailbox:sync in the scheduler (onOneServer). Turn it
     * off to schedule the commands yourself.
     */
    'schedule' => env('MSGRAPH_MAILBOX_SCHEDULE', true),

    'throttle' => [

        /*
         * Graph allows about four concurrent requests per mailbox. The package's jobs and every job using
         * the ThrottleGraphRequests middleware share this limit per mailbox.
         */
        'concurrency_per_mailbox' => 3,

        /*
         * How long a throttled job keeps being retried before it fails, in hours.
         */
        'retry_until_hours' => 12,

        /*
         * How many exceptions a job may throw before it fails. Releases for throttling don't count.
         */
        'max_exceptions' => 3,
    ],

    /*
     * The queue connection and queue of the package's jobs. Null uses your app's defaults.
     */
    'queue' => [
        'connection' => env('MSGRAPH_MAILBOX_QUEUE_CONNECTION'),
        'queue' => env('MSGRAPH_MAILBOX_QUEUE'),
    ],

    'attachments' => [

        /*
         * Attachments larger than this are never downloaded: contents() and stream() throw
         * AttachmentTooLargeException instead. Null removes the limit.
         */
        'max_bytes' => 25 * 1024 * 1024,
    ],
];
