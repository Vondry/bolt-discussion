# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2026-06-30

### Upgrading

This is a Bolt extension, not an application, so it ships no migrations of its
own — your project owns its schema. After updating the package, re-run the diff
so the entity changes below are migrated into your database:

```bash
composer update tomvondracek/bolt-discussion
cd vendor/tomvondracek/bolt-discussion && npm install && npm run build && cd -
bin/console doctrine:migrations:diff        # picks up the entity/schema changes
bin/console doctrine:migrations:migrate
bin/console cache:clear
```

- **Reaction rate-limiting** adds a nullable `ip_hash` column to
  `bolt_discussion_reaction`. The migration only adds the column (no backfill,
  no downtime), but it **must run before** the new code serves traffic, since
  adding a reaction now writes that column. Existing rows are unaffected.
- New config keys (`reaction_rate_limit`, `reaction_rate_limit_seconds`) have
  built-in defaults, so existing `config/extensions/bolt-discussion.yaml` files
  keep working unchanged — add them only to tune or disable the cap.

### Added

- Cascade deletion: deleting a root comment now also deletes every reply in its
  thread and purges all of their reactions, so no reply or reaction is left
  orphaned.
- Per-IP reaction rate-limiting (`reaction_rate_limit`,
  `reaction_rate_limit_seconds`) to prevent reaction-count inflation. New
  reactions store the poster's hashed IP; logged-in users are exempt and
  removals are never throttled.

### Fixed

- Reaction toggling is now idempotent under concurrent identical adds. A fast
  double-click previously raced into a unique-constraint violation and surfaced
  as a 500; the duplicate insert is now treated as success.
- The admin moderation redirect no longer trusts the posted `reference`
  verbatim — a value outside the allowed pattern threw an
  `InvalidParameterException` (500). It is validated and falls back to the
  comment's own reference.
- `match.alwaysTrue` analysis error in the admin moderation action.

### Changed

- Dev tooling bumped: PHPStan `2.2.2`, phpstan-deprecation-rules `2.0.4`,
  Rector `2.5.2`.

### Security

- Anonymous reaction counts can no longer be inflated by cycling the
  client-supplied visitor token; additions are capped per IP (see above).
- Documented the `framework.trusted_proxies` requirement: the per-IP limits and
  reaction de-duplication only work when Symfony sees the real client IP.
