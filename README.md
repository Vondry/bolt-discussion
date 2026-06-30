# Bolt Discussion

A pluggable, themeable discussion / comments module for **Bolt CMS 6**.

- Anyone (logged-in or anonymous) can post comments
- One-level threaded replies (no deep nesting)
- Emoji reactions (configurable set, deduped per visitor)
- PHP-rendered initial page to avoid layout shift, then async submit and REST polling for live updates
- Editor-level Bolt users (`ROLE_EDITOR`) can delete from the frontend and moderate in the Bolt admin
- Decoupled from contenttypes: a discussion is identified by an arbitrary string
  `reference`, so you can place many independent discussions anywhere
- Fully themeable per project via `--bd-*` CSS custom properties
- Honeypot + optional spam regex + IP rate-limiting; `auto` or `queue` moderation

## Requirements

- Bolt CMS `^6.0`, PHP `>=8.2`
- Node `>=22.13` (to build the frontend assets with Symfony Encore)

## Installation

1. **Require the package** (published, or as a local Composer `path` repository):

   ```bash
   composer require tomvondracek/bolt-discussion
   ```

2. **Build the frontend assets** (shipped source builds to `public/build/`):

   ```bash
   cd vendor/tomvondracek/bolt-discussion
   npm install && npm run build
   ```

3. **Register the entity mapping.** Extensions aren't bundles, so add this under
   `doctrine.orm.mappings` in your project's `config/packages/doctrine.yaml`:

   ```yaml
   BoltDiscussion:
     is_bundle: false
     type: attribute
     dir: '%kernel.project_dir%/vendor/tomvondracek/bolt-discussion/src/Entity'
     prefix: 'Bolt\Discussion\Entity'
     alias: BoltDiscussion
   ```

4. **Copy routes/services and create the tables:**

   ```bash
   bin/console extensions:configure
   bin/console doctrine:migrations:diff        # generates the create-tables migration
   bin/console doctrine:migrations:migrate
   bin/console cache:clear
   ```

## Upgrading

This is a Bolt extension, not an application, so it ships no migrations of its
own — your project owns its schema. After updating the package, re-run the diff
so any entity changes are migrated into your database:

```bash
composer update tomvondracek/bolt-discussion
cd vendor/tomvondracek/bolt-discussion && npm install && npm run build && cd -
bin/console doctrine:migrations:diff        # picks up any entity/schema changes
bin/console doctrine:migrations:migrate
bin/console cache:clear
```

Notable schema changes:

- **Reaction rate-limiting** added a nullable `ip_hash` column to
  `bolt_discussion_reaction`. The migration only adds the column (no backfill,
  no downtime), but it **must run before** the new code serves traffic, since
  adding a reaction now writes that column. Existing rows are unaffected.

New config keys (`reaction_rate_limit`, `reaction_rate_limit_seconds`) have
built-in defaults, so existing `config/extensions/bolt-discussion.yaml` files
keep working unchanged — add them only to tune or disable the cap. See
[Configuration](#configuration).

## Usage

Place a discussion anywhere in a frontend template. The `reference` is any string
that scopes an independent thread:

```twig
{{ discussion('record-' ~ record.id, { title: 'Comments' }) }}

{# show a count somewhere else #}
{{ discussion_count('record-' ~ record.id) }} comments
```

### Per-instance copy

The second argument is an options map, so each discussion can carry its own
composer text (handy when one page hosts several). Each option falls back to the
translated default, and you translate it yourself — pass a literal, or your own
`|trans` call:

```twig
{{ discussion('event-2027', {
    title: 'Lineup ideas',
    namePlaceholder: 'name.placeholder'|trans,
    commentPlaceholder: 'What should we book?',
    replyPlaceholder: 'Write a reply…',
    submitLabel: 'Send it'
}) }}
```

| Option               | Overrides                         |
|----------------------|-----------------------------------|
| `title`              | Heading above the thread          |
| `namePlaceholder`    | The name field placeholder        |
| `commentPlaceholder` | The comment box placeholder       |
| `replyPlaceholder`   | The reply box placeholder         |
| `submitLabel`        | The post-comment button label     |

The extension injects its CSS/JS only on pages that actually contain a mount.
The initial comment page and composer are rendered during the PHP request, so
the widget has its final layout before JavaScript runs. JavaScript hydrates that
markup and is then used only for interactions, older pages, and live polling.

## Configuration

Edit `config/extensions/bolt-discussion.yaml` (created on first run):

| Key                  | Default                       | Description                                            |
|----------------------|-------------------------------|--------------------------------------------------------|
| `moderation`         | `auto`                        | `auto` publishes immediately; `queue` holds anonymous comments as *pending* (logged-in users always auto-publish) |
| `poll_interval`      | `10000`                       | Polling interval in ms; `0` disables polling           |
| `page_size`          | `10`                          | Root comments per page (newest first); "Load more" reveals older ones. Clamped to 1..100 |
| `reactions_enabled`  | `true`                        | Toggle reactions                                       |
| `reactions`          | `['👍','❤️','😂','🎉','😮']`  | Allowed reaction emoji                                 |
| `replies_enabled`    | `true`                        | Toggle one-level replies                               |
| `max_length`         | `2000`                        | Max comment length                                     |
| `require_name`       | `true`                        | Require a name from anonymous posters                  |
| `spam_regex`         | `''`                          | If set, matching bodies are stored as spam             |
| `rate_limit_seconds` | `10`                          | Min seconds between anonymous posts from one IP; `0` disables (logged-in users are never throttled) |
| `reaction_rate_limit` | `20`                         | Max new reactions per IP per window; `0` disables (logged-in users are never throttled) |
| `reaction_rate_limit_seconds` | `60`                 | Length of the reaction rate-limit window, in seconds   |

> **Behind a proxy or CDN?** The per-IP limits (`rate_limit_seconds`,
> `reaction_rate_limit`) and reaction de-duplication only work if Symfony sees
> the real client IP. Configure
> [`framework.trusted_proxies`](https://symfony.com/doc/current/deployment/proxies.html)
> for your environment — otherwise every visitor appears to share the proxy's IP
> (over-throttling) or a spoofed `X-Forwarded-For` could bypass the limits.

## Theming

The widget is designed to be re-skinned without touching the extension. **Every**
visual value resolves to a `--bd-*` custom property declared on `.bolt-discussion`,
covering colour, type, radii, spacing, shadow, the per-author avatars, and the
reply-thread connector. There are two ways to customize:

**1. Re-declare variables** (the usual path) — map them onto your design tokens:

```css
.bolt-discussion.bolt-discussion {
    /* colour */
    --bd-color-accent: var(--color-brand);
    --bd-color-surface: var(--color-white);
    --bd-color-border: var(--color-gray-200);
    /* type & shape */
    --bd-font: var(--font-body);
    --bd-radius: 12px;
    --bd-radius-pill: 999px;
    /* avatars (hue is set per-author; tune saturation/lightness/shape) */
    --bd-avatar-radius: 50%;
    --bd-avatar-sat: 58%;
    --bd-avatar-light: 48%;
    /* reply connector */
    --bd-thread-line: var(--color-gray-200);
}
```

(The doubled `.bolt-discussion` selector raises specificity so your overrides win
regardless of stylesheet load order.)

**2. Override BEM classes** for structural or typographic flourishes, e.g.:

```css
.bolt-discussion .bolt-discussion__author { text-transform: uppercase; }
.bolt-discussion .bolt-discussion__avatar { box-shadow: 0 0 0 2px var(--color-brand); }
```

See the rockfest theme (`public/theme/rockfest/css/discussion-theme.css`) for a
complete "gig-poster" example combining both. You can also override
`templates/mount.html.twig` by placing a same-named template in your active theme.

### Built-in UX

Modern thread UI out of the box: top composer with an auto-growing field,
colour-coded initial avatars, locale-aware relative timestamps
(`Intl.RelativeTimeFormat`), reaction chips with a `＋` emoji picker, and
collapsible reply threads with a connector line. Respects
`prefers-reduced-motion` and ships visible keyboard focus.

### Returning visitors

Anonymous visitors are remembered across visits without any login:

- **Name** — the last name posted is stored in `localStorage` (`bolt-discussion:name`)
  and prefilled into the composer and reply forms, so people don't retype it.
- **Reaction state** — whether you already reacted to a comment ("mine") is tracked
  per visitor. The identity lives in a year-long `bd_visitor` cookie *and* a
  `localStorage` id (`bolt-discussion:vid`) sent as an `X-BD-Visitor` header, so it
  survives even if cookies are cleared. Anonymous ids are validated and namespaced,
  so they can never impersonate a logged-in user's reactions.

All of this is per-browser and needs no personal data or account.

## Admin

A **Discussion** entry appears in the Bolt admin sidebar (`/bolt/extension/discussion`),
listing discussions grouped by reference with per-thread approve / delete / mark-spam.

## Translations

All user-facing text (frontend widget, validation/API errors, and the admin
screens) is translated via the `bolt_discussion` translation domain. Catalogs
ship for **English, Czech, German, Polish and Dutch** in `translations/`
(`bolt_discussion.<locale>.yaml`), using the English source string as the key.

`extensions:configure` registers the path automatically (it copies
`framework.translator.paths` into `config/packages/extension_bolt-discussion.yaml`).
Frontend JS strings are translated server-side and passed to the widget via a
`data-i18n` attribute. To add a locale, drop a `bolt_discussion.<locale>.yaml`
file in `translations/` and clear the cache.
