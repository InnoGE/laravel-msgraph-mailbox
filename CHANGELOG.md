# Changelog

All notable changes to `laravel-msgraph-mailbox` will be documented in this file.

## v1.0.1 - 2026-11-02

### What's Changed

* Fix subscribe, sync reset and loop guard bugs by @geisi in https://github.com/InnoGE/laravel-msgraph-mailbox/pull/2

**Full Changelog**: https://github.com/InnoGE/laravel-msgraph-mailbox/compare/v1.0.0...v1.0.1

## v1.0.0 - 2026-11-02

Initial release.

- Observe Microsoft 365 mailbox folders through Graph change notifications with a delta sync safety net: `MessageReceived`, `MessageMoved`, `MessageDeleted`, `MessagePermanentlyDeleted` (all three `MessageRemoved`) and the opt-in `MessageChanged`.
- Mailboxes from config or `Mailbox::observe()`.
- Read, archive, move, copy, flag, categorize and delete messages through `Mailbox::for()` with chainable actions, or as queued jobs.
- `Mailbox::fake()->receive()` for testing listeners end to end.
- `msgraph-mailbox:install` (publishes config and migration, adds the env variables, asks for the first mailbox), `:subscribe`, `:sync`, `:unsubscribe` and `:status` commands, and a Laravel Boost skill.
- Automatic scheduling of the subscribe and sync commands (opt out with `MSGRAPH_MAILBOX_SCHEDULE=false`) and an automatically registered webhook route (opt out with `webhooks.register_route` and register it yourself with `Mailbox::routes()`).
- Requires PHP 8.3+, Laravel 12 or 13 and `innoge/laravel-msgraph-mail` ^3.0.
