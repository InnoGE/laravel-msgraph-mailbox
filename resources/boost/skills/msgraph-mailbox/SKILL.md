---
name: msgraph-mailbox
description: Observe Microsoft 365 mailboxes and act on their messages with the innoge/laravel-msgraph-mailbox package. Use when configuring observed mailboxes, writing listeners for MessageReceived / MessageRemoved / MessageChanged, reading or moving/archiving/deleting messages via the Mailbox facade, testing with Mailbox::fake()->receive(), or debugging subscriptions, webhooks and delta sync.
license: MIT
metadata:
  author: innoge
---

# Microsoft Graph Mailbox

## When to use this skill

Use this skill when working with the `innoge/laravel-msgraph-mailbox` package:

- Reacting to mail arriving in a Microsoft 365 mailbox (support inboxes, document intake, reply handling).
- Reading, moving, archiving, flagging, categorizing or deleting messages through Microsoft Graph.
- Testing listeners that react to incoming mail.
- Debugging subscriptions, webhooks or the delta sync.

## Setup

```shell
composer require innoge/laravel-msgraph-mailbox
php artisan msgraph-mailbox:install
```

Credentials use the same env names as `innoge/laravel-msgraph-mail` (`MICROSOFT_GRAPH_TENANT_ID`, `MICROSOFT_GRAPH_CLIENT_ID`, `MICROSOFT_GRAPH_CLIENT_SECRET` or the certificate variables). The Entra app needs the Microsoft Graph application permission `Mail.ReadWrite` (or `Mail.Read` when the app never acts on messages) with admin consent, ideally restricted to the observed mailboxes with an Application Access Policy.

Observed mailboxes live in `config/msgraph-mailbox.php`. Always use the mailbox's primary SMTP address, not an alias:

```php
'mailboxes' => ['support@example.com'],

// or with options
'mailboxes' => [
    'support@example.com' => [
        'folders' => [WellKnownFolder::Inbox, 'Support/Escalations'],
        'events' => [MessageReceived::class, MessageRemoved::class], // MessageChanged::class is opt-in and noisy
    ],
],
```

Mailboxes from the database: `Mailbox::observe(fn () => Team::pluck('support_mailbox'))` in a service provider, returning the same shape.

Deploy: run `php artisan msgraph-mailbox:subscribe`. The package schedules it hourly plus a delta sync every 15 minutes, so the scheduler and a queue worker must run. Webhooks need the app reachable over HTTPS; locally use `MSGRAPH_MAILBOX_WEBHOOKS=false` (polling only) or a tunnel URL in `MSGRAPH_MAILBOX_NOTIFICATION_URL`. To own the scheduling, set `MSGRAPH_MAILBOX_SCHEDULE=false` and schedule both commands yourself. To register the webhook route on a custom domain or prefix, set `webhooks.register_route` to `false` and call `Mailbox::routes()` in a route file, outside the `web` group.

## Events

| Event | Fires when |
|---|---|
| `MessageReceived` | a message arrives in (or is moved into) an observed folder |
| `MessageMoved` | it moves to another folder (`$event->toFolderId`) |
| `MessageDeleted` | it is deleted into Deleted Items |
| `MessagePermanentlyDeleted` | it is permanently deleted or Shift+Deleted |
| `MessageChanged` | it is read/flagged/categorized/edited (opt-in) |

`MessageMoved` (`$event->movedTo(WellKnownFolder::Archive)`), `MessageDeleted`, `MessagePermanentlyDeleted` implement `MessageRemoved`; every event implements `MessageEvent`. Events carry `mailbox`, `folder`, `messageId`; `$event->message()` loads the message lazily. Delivery is at least once: make listeners idempotent (e.g. by `internetMessageId()`). The first sync of a folder is a silent baseline.

## Working with messages

```php
$message = Mailbox::for('support@example.com')->message($id);

$message->subject(); $message->from()?->address; $message->body(unique: true); $message->attachments();
$message->isAutoSubmitted(); $message->isFromSelf(); // loop guards, never applied automatically

$message->markRead()->categorize('Customer')->archive(); // actions are chainable
$message->move('Support/Done'); $message->flag(FlagStatus::Complete); $message->setCategories([...]); // replaces
$message->delete(); // Deleted Items
$message->permanentDelete();

$message->conversationId(); $message->sentAt(); $message->bcc(); $message->replyTo(); $message->preview();
$message->reply('<p>HTML</p>'); $message->replyAll('<p>HTML</p>', [['name' => 'a.pdf', 'contentType' => 'application/pdf', 'contents' => $pdf]]);

$page = Mailbox::for('support@example.com')->messages(WellKnownFolder::SentItems, since: now()->subYear()); // newest first, 50 per page
$page->messages; $page->next; // pass next: $page->next for the following page, one page per queued job
```

- `Mailbox::for()` only accepts observed mailboxes and `allowed_mailboxes` patterns; `forUnrestricted()` skips that check and must only get authorized addresses.
- Attachments: `$attachment->stream()` / `contents()`, `safeFilename()` for disk paths, capped by `attachments.max_bytes`.
- Queued actions: `MoveMessage::dispatch($mailbox, $id, WellKnownFolder::Archive)` and siblings.
- Own queued listeners/jobs calling Graph: add `new ThrottleGraphRequests($event->mailbox)` as job middleware.
- HTML bodies are not sanitized.
- Replies quote the original, stay in the conversation and land in Sent Items; they need `Mail.Send`, attachments up to 3 MB each. Test with `$mailbox->assertReplied($message, fn ($html, $all, $attachments) => ...)`.

## Testing

```php
$mailbox = Mailbox::fake();

$message = $mailbox->receive('support@example.com', [
    'subject' => "Re: [TICKET-1042] My order hasn't arrived",
    'from' => ['emailAddress' => ['address' => 'customer@example.org']],
    'uniqueBody' => ['content' => 'It arrived today'],
]); // dispatches MessageReceived through the real listeners

$mailbox->assertMoved($message, WellKnownFolder::Archive); // assertions accept the Message or its id
```

Seeded messages use Microsoft Graph's message JSON (`body.content`, `attachments[].contentBytes`), plus `mime` for the raw message. The mailbox must be observed in the test config.

## Troubleshooting

- `php artisan msgraph-mailbox:status` lists every folder with subscription expiry, last notification and last sync, and exits non-zero when something needs attention.
- `MissingCredentialsException`: the `MICROSOFT_GRAPH_*` env values are empty; `php artisan msgraph-mailbox:install` adds them to `.env`.
- `FolderNotFoundException` on subscribe: a configured folder path does not exist in the mailbox.
- `MailboxNotAllowedException`: the address is neither observed nor in `allowed_mailboxes`.
- No events locally: webhooks cannot reach localhost; disable them or use a tunnel, then run `php artisan msgraph-mailbox:sync`.
- 403 from Graph: missing `Mail.ReadWrite`, missing admin consent, or the Application Access Policy excludes the mailbox.
