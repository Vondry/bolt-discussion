# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Development

- CI uses the same workflows as the other extensions: PHPStan once per PHP
  version, ECS, `composer validate`, `composer audit` and actionlint once, a
  syntax check on PHP 8.2 to 8.5 and at least 95 % line coverage; newer pushes
  cancel older runs.
- `SECURITY.md`, `.editorconfig`, and `keywords`, `support` and `funding` in
  `composer.json`.

## [2.1.5] - 2026-11-22

### Changed

- The Composer package leaves out the tests, CI and tool configuration (`.gitattributes` with
  `export-ignore`), as well as the Encore sources in `assets/`. The built files in `public/` still ship.

### Fixed

- Composer no longer installs the extension next to a `bolt/core` outside 6.x
  (`conflict: <6.0 || >=7.0`), like the other extensions.
- `DiscussionApiController` calls the `ExtensionController` constructor, so its Bolt
  configuration is set.

### Development

- PHPStan also analyses the tests, with `phpstan-strict-rules` and `phpstan-phpunit`; ECS and
  Rector check the tests too, and PHPUnit runs in random order and fails on warnings, notices
  and deprecations.
- Dependencies updated. The runtime requirements are unchanged; for development, PHPStan 2.3.7,
  phpstan-phpunit 2.1.2, phpstan-strict-rules 2.1.1 and Rector 2.8.5.
- ECS, PHPStan, PHPUnit and Rector use the same configuration as the other extensions.
- GitHub Actions are pinned to commit SHAs.

## [2.1.4] - 2026-11-21

### Changed

- Build tools updated: `@babel/core` 8.1, `sass-loader` 17.1.1, `webpack` 5.113,
  Encore 7.2.1, Sass 1.108 and `webpack-cli` 7.3. The shipped `discussion.js`
  and `discussion.css` are unchanged.

### Fixed

- Production builds keep `discussion.css` minified with Encore 7.2.1, which asks
  Sass for expanded output and leaves minification to a CSS minimizer that is
  off unless configured.

## [2.1.3] - 2026-11-21

### Changed

- Dependencies updated. The runtime requirements are unchanged; for
  development, PHPUnit 11.5.58 and ECS 13.3.23.
- Dependabot proposes updates to the Composer and npm dependencies and GitHub Actions
  once a month.

## [2.1.2] - 2026-10-30

### Changed

- `discussion.js` is built with Symfony Encore 7 and Babel 8, which compile for
  browserslist's `defaults` instead of ES5: the bundle shrinks from 144 KiB to
  60 KiB, and browsers outside `defaults` (such as Internet Explorer) are no
  longer supported. `discussion.css` is equivalent.
- A config value given as a list or map where a number or text is expected now
  falls back to its default instead of becoming `1` or `"Array"`, and non-text
  entries in `reactions` are ignored.
- A JSON request body only keeps its named fields.
- Requires `twig/twig` `^3.30` (was `^3.29`) and `symfony/polyfill-php84`
  `^1.43.0` (was `^1.38.1`).
- Development: Encore 7 (`webpack.config.mjs`), Babel 8 with
  `babel-plugin-polyfill-corejs3`, Node `>=22.18` to build the assets,
  PHPUnit 11, PHPStan 2.3.4 at level max, Rector 2.8.3.

## [2.0.1] - 2026-09-04

### Upgrading

Re-run `bin/console extensions:configure && bin/console cache:clear` so the
regenerated `config/packages/extension_bolt-discussion.yaml` picks up the Twig
namespace and entity mapping below. The manual `doctrine.orm.mappings` block
that earlier versions asked you to add to `config/packages/doctrine.yaml` is no
longer needed — it is harmless if left in place, but can be removed.

### Fixed

- **`@bolt-discussion` is now registered as a Twig path in the container**, not
  only at runtime from `Extension::initialize()`. `addTwigNamespace()` prepends
  the path to the Twig environment of the current web request, so the namespace
  did not exist on the CLI: `bin/console debug:twig @bolt-discussion/mount.html.twig`
  reported *No template paths configured for "@bolt-discussion" namespace*, and
  console/worker rendering of the mount template failed. The runtime call is
  kept as a fallback for projects that have not re-run `extensions:configure`.
- **The entity mapping ships with the extension.** `config/services.yaml` now
  carries the `doctrine.orm.mappings` entry (as `bolt/article` and
  `bolt/redactor` do), so `extensions:configure` registers
  `Bolt\Discussion\Entity` and installing no longer requires hand-editing
  `config/packages/doctrine.yaml`.
- **Theme overrides of the mount template work.** Bolt prepends the active theme
  to Twig's main namespace only, so a theme copy could never shadow
  `@bolt-discussion/mount.html.twig`. `discussion()` now renders
  `bolt-discussion/mount.html.twig` from the theme when that file exists.

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
- **PHP namespaces changed** from `BoltDiscussion\...` to
  `Bolt\Discussion\...`. Update any custom imports, service references, manual
  extension entrypoint references, and Doctrine mapping prefixes to the new
  namespace (`Bolt\Discussion\Entity` for the entity mapping).
- **The extension config file is now consistently `bolt-discussion.yaml`.** If
  an earlier `extensions:configure` run created `config/extensions/boltdiscussion.yaml`,
  merge any settings from it into `config/extensions/bolt-discussion.yaml` and
  remove the duplicate.
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
  as a 500; the duplicate insert is now treated as success and does not
  double-count when the aggregate snapshot already includes the winning insert.
- The admin moderation redirect no longer trusts the posted `reference`
  verbatim — a value outside the allowed pattern threw an
  `InvalidParameterException` (500). It is validated and falls back to the
  comment's own reference.
- Malformed request payload values are validated before processing. Non-scalar
  comment fields, parent IDs, CSRF tokens, and reaction emoji can no longer be
  coerced into unexpected strings.
- Installing/configuring the extension no longer creates conflicting
  `boltdiscussion.yaml` and `bolt-discussion.yaml` config files.
- `match.alwaysTrue` analysis error in the admin moderation action.

### Changed

- PHP namespaces, Composer autoloading, and the Bolt extension entrypoint now
  use the idiomatic `Bolt\Discussion\...` namespace.
- Dev tooling bumped: PHPStan `2.2.2`, phpstan-deprecation-rules `2.0.4`,
  Rector `2.5.2`.
- Automated coverage was expanded to 100% classes, methods, and lines with
  scenario coverage for controllers, services, repositories, Twig rendering,
  assets, entities, menus, and subscribers.

### Security

- Anonymous reaction counts can no longer be inflated by cycling the
  client-supplied visitor token; additions are capped per IP (see above).
- Documented the `framework.trusted_proxies` requirement: the per-IP limits and
  reaction de-duplication only work when Symfony sees the real client IP.
