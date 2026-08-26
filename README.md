# CFP.DEV WordPress Shortcodes Plugin

[![PHP Lint](https://github.com/patbaumgartner/cfp-dev-shortcodes/actions/workflows/lint.yml/badge.svg)](https://github.com/patbaumgartner/cfp-dev-shortcodes/actions/workflows/lint.yml)
[![Tests](https://github.com/patbaumgartner/cfp-dev-shortcodes/actions/workflows/tests.yml/badge.svg)](https://github.com/patbaumgartner/cfp-dev-shortcodes/actions/workflows/tests.yml)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D8.1-8892BF?logo=php)](https://php.net)
[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759B?logo=wordpress)](https://wordpress.org)
[![License: GPL v2+](https://img.shields.io/badge/License-GPL%20v2%2B-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Stable Tag](https://img.shields.io/badge/stable-4.8.1-brightgreen)](https://github.com/patbaumgartner/cfp-dev-shortcodes/releases)

> WordPress shortcodes plugin for [CFP.DEV](https://cfp.dev) — display speakers, talks, schedules, and search results from your CFP.DEV instance directly on your WordPress site (Devoxx, VoxxedDays, and more).

---

## Features

- **Speakers list** — grid view with photos, sorted by last name or random; includes live search
- **Speaker details** — full profile with bio, social links (Twitter, LinkedIn, Bluesky, Mastodon), and async Flickr photo gallery
- **Talk details** — description, speakers, track, schedule info, YouTube embed, Spotify embed, and related talks (semantic search)
- **Schedule** — time-grid per day with room columns and favorite counts
- **Talks by track / by session type** — filterable tables with navigation tabs
- **Search results** — exact keyword matches + semantic similarity results via [search.cfp.dev](https://search.cfp.dev)
- **Offline mode** — crawls all API endpoints and CDN images into a local snapshot; serves everything locally with zero external requests
- **SEO head metadata** — server-rendered `<title>`, meta description, Open Graph / Twitter tags, slug-aware canonicals, JSON-LD (`Event` / `Person`), and an XML sitemap for talk/speaker URLs
- **Agentic browsing (WebMCP)** — the search form exposes declarative WebMCP tool metadata so AI agents can invoke the programme search as a structured tool
- **Caching** — WordPress transient-based cache with configurable TTL (none, 1 h, 1 day, 1 week, 1 month); each API endpoint is fetched at most once per request
- **Theming** — light / dark theme with optional user toggle, applied before first paint
- **Lightweight** — no jQuery on the front end, and the stylesheet and script load only on pages that actually use a shortcode
- **Admin UI** — API key, event settings, per-item cache management, offline crawl progress
- **Translatable** — every user-facing string is localisable, in the admin scripts as well as the PHP; a `.pot` template ships in `languages/`
- **Accessible** — WCAG AA contrast in both themes, a heading outline on every page, labelled search, keyboard-operable theme toggle, named social links

---

## Requirements

| Requirement | Version |
|-------------|---------|
| PHP | ≥ 8.1 |
| WordPress | ≥ 6.0 |
| CFP.DEV instance | any |

---

## Installation

### Option A — Upload manually

1. Download the latest release ZIP from the [Releases](https://github.com/patbaumgartner/cfp-dev-shortcodes/releases) page.
2. In WordPress admin go to **Plugins → Add New → Upload Plugin** and upload the ZIP.
3. Activate the plugin.

### Option B — Copy to plugins directory

```bash
cp -r cfp-dev-shortcodes /var/www/html/wp-content/plugins/
```

Activate the plugin in **Plugins → Installed Plugins**.

### First-time configuration

1. Go to **Settings → CFP.DEV**.
2. Enter your **CFP.DEV Key** (the subdomain of your CFP.DEV instance, e.g. `dvbe23`).
3. Enter the **Event Name** used in page titles and meta tags.
4. Set the desired **Cache Duration** and **Default Theme**.
5. Save. The plugin automatically creates all required WordPress pages on first activation.

### Settings reference

| Setting | Default | Description |
|---------|---------|-------------|
| **CFP.DEV Key** | — | Subdomain of your CFP.DEV instance (letters, digits, dashes) |
| **Event name** | — | Display name used in page titles, meta tags, and JSON-LD |
| **URL Path Prefix** | *(empty)* | Path segment for sites served from a subdirectory, e.g. `trieste` for `voxxeddays.com/trieste` |
| **Permalinks with Id** | `Yes` | `Yes` → links like `/speaker?id=123` (required for multisite); `No` → pretty slug URLs like `/speaker/jane-doe` |
| **Show Rooms** | `Yes` | Show or hide room names on all pages |
| **Cache Duration** | — | Transient TTL: none, 1 h, 1 day, 1 week, or 1 month |
| **Default Theme** | `dark` | Initial light/dark theme |
| **Enable Theme Switching** | off | Adds a light/dark toggle in the page footer |
| **Offline Mode** | off | Crawl a local snapshot and serve everything from it (see below) |

---

## Auto-created Pages

When the plugin is activated it creates the following pages, each with the corresponding shortcode already inserted:

| Page slug | Shortcode |
|-----------|-----------|
| `/speakers` | `[cfp_speakers]` |
| `/speaker` | `[cfp_speaker_details]` |
| `/talk` | `[cfp_talk_details]` |
| `/schedule` | `[cfp_schedule]` |
| `/talks-by-tracks` | `[cfp_talks_by_tracks]` |
| `/talks-by-sessions` | `[cfp_talks_by_sessions]` |
| `/search-results` | `[cfp_search_results]` |

> All pages must have **no parent page**.

---

## Shortcode Reference

### `[cfp_speakers]`

Displays a grid of all speakers.

| Attribute | Default | Description |
|-----------|---------|-------------|
| `size` | `300` | Maximum number of speakers to fetch |
| `random` | `false` | Randomise the order (`yes` / `true`) |
| `title` | `Speakers` | Heading above the grid |
| `subtitle` | — | Sub-heading |
| `hide_title` | `false` | Hide the heading |
| `hide_search` | `false` | Hide the search form |

```
[cfp_speakers size=20 random=yes title="Our Speakers" subtitle="Meet the lineup"]
```

---

### `[cfp_speaker_details]`

Renders the full speaker profile page. Reads `speaker_slug` or `id` from the URL query string — no attributes needed. No page heading is rendered by design (the speaker's name is the heading).

---

### `[cfp_talk_details]`

Renders the full talk details page. Reads `talk_slug` or `id` from the URL query string — no attributes needed. No page heading is rendered by design (the talk title is the heading).

---

### `[cfp_schedule]`

Displays the conference schedule grid. Reads the day from the URL query string
(`?id=Tuesday`); defaults to the first day of the event.

| Attribute | Default | Description |
|-----------|---------|-------------|
| `title` | *(event name)* | Heading above the schedule |
| `hide_title` | `false` | Hide the heading |
| `hide_search` | `false` | Hide the search form |

```
[cfp_schedule title="Programme"]
```

---

### `[cfp_talks_by_tracks]`

Lists talks grouped by track.

| Attribute | Default | Description |
|-----------|---------|-------------|
| `all` | `false` | Show all tracks at once (`true`) instead of the tab selected by `id` |
| `title` | `Talks grouped by Track` | Heading above the list |
| `hide_title` | `false` | Hide the heading |
| `hide_search` | `false` | Hide the search form |

```
[cfp_talks_by_tracks all=true title="Tracks"]
```

---

### `[cfp_talks_by_sessions]`

Lists talks grouped by session type (Conference, Workshop, BOF, Lightning Talk, …).

| Attribute | Default | Description |
|-----------|---------|-------------|
| `title` | `Talks grouped by Session Types` | Heading above the list |
| `hide_title` | `false` | Hide the heading |
| `hide_search` | `false` | Hide the search form |

---

### `[cfp_search_results]`

Displays keyword search results and semantic similarity results. Reads `query` from the URL — no attributes needed.

---

## Offline Mode

Enable **Offline Mode** in **Settings → CFP.DEV** to crawl the entire CFP.DEV API and all CDN images (speaker photos, track images, Flickr album thumbnails) into a local dated snapshot under `wp-content/uploads/cfp-dev-offline/`.

Once active:

- All API reads are served from the local snapshot
- No external requests to `*.cfp.dev` or CDN hosts are made
- A `manifest.json` is written at crawl completion with per-URL stats
- Click **Re-crawl Now** in the admin UI to refresh the snapshot at any time
- Only the two newest **completed** snapshots are kept — older ones, and any
  abandoned crawl they supersede, are pruned after each successful crawl. A
  **pinned** snapshot (see below) is never pruned

A crawl only publishes a snapshot it captured in full. If any endpoint the site
needs fails, or answers with something that is not JSON, the crawl stops,
reports what happened, and leaves offline mode — and the snapshot already being
served — exactly as they were. Images are the exception: one that cannot be
downloaded keeps pointing at the CDN, so the page still renders.

An SVG is never downloaded into a snapshot. Snapshots live under
`wp-content/uploads` and are served from your own origin, and an SVG is a
document that can carry script, so those URLs are left pointing at the CDN too.

### Pinning a snapshot

By default offline reads serve the newest completed snapshot. The **Serve From
Snapshot** picker in the Offline Mode section lets you pin a specific dated
snapshot instead — useful once the CFP.DEV instance behind an event is gone and
a snapshot is the only remaining copy of its data:

- Pinned reads keep serving that date's speakers, talks, and schedule
- A pinned snapshot is never pruned by retention and even survives
  uninstalling the plugin; after a re-install it reappears in the picker,
  ready to be pinned anew
- Changing the selection re-renders all cached pages, since their HTML embeds
  the snapshot's own image URLs
- If a pinned snapshot disappears from disk, reads fall back to the latest
  snapshot instead of taking the site down

---

## Caching

Rendered shortcode output is cached in WordPress transients for the configured
**Cache Duration**. Saving any setting (or clearing caches from the admin page)
invalidates every cached entry instantly via an internal cache-version bump —
superseded entries simply expire.

> **Note:** with `random=yes` on `[cfp_speakers]`, the order is shuffled once
> per cache period, not on every page view. Set **Cache Duration** to
> *No Cache* for a fresh shuffle on each request.

Within a single request each API endpoint is fetched at most once, so a page
that shows the same talk in its body, its `<title>`, its canonical URL and its
JSON-LD costs one API call, not four.

---

## Theme Integration

### Front-end assets

The stylesheet and script are enqueued only on pages whose content actually
contains one of the plugin's shortcodes — the rest of your site pays nothing.

If your theme renders a shortcode from somewhere the plugin cannot see (a
widget, a template part, a page builder), force the assets on:

```php
add_filter( 'cfp_dev_enqueue_assets', function ( $enqueue ) {
    return $enqueue || is_front_page();
} );
```

| Filter | Arguments | Purpose |
|--------|-----------|---------|
| `cfp_dev_enqueue_assets` | `bool $enqueue`, `WP_Post\|null $post` | Whether to load the plugin's CSS and JS on this request |
| `cfp_dev_video_embed_hosts` | `string[] $hosts` | Hostnames whose videos may be framed on a talk page. A talk's `videoURL` comes from the API, and an `<iframe src>` runs the framed origin's code in the visitor's browser, so anything not on this list is not embedded |
| `cfp_dev_api_timeout` | `int $timeout`, `string $query_path` | HTTP timeout per API request. Defaults to 30 s, 10 s in the admin, and 8 s for search — whose results are never cached, so every request waits in full on a public URL |
| `cfp_dev_crawl_deadline` | `int $seconds` | How long an offline crawl may run before it is presumed dead and the operator is allowed to start another. Defaults to 15 minutes |

### Headings

The plugin starts its heading outline at level 2, because the `<h1>` belongs to
your theme — it is the WordPress page title. Talk titles, speaker names and
page titles are announced as level 2, and the speakers, talks and results
within them as level 3. They are marked with `role="heading"` rather than
`<h2>`/`<h3>`: the stylesheet is compiled from a design system and styles them
by class, so heading elements would outrank those rules and restyle every one.

### Head metadata

If your theme (or an SEO plugin) renders its own meta tags, call
`add_theme_support( 'cfp-dev-head-meta' )`. The plugin then contributes only the
document title, canonical URL and JSON-LD, and you can render the rest yourself
from `cfp_dev_page_meta()`:

```php
$meta = cfp_dev_page_meta(); // null on pages the plugin does not own
if ( $meta ) {
    printf( '<meta name="description" content="%s">', esc_attr( $meta['description'] ) );
}
```

`cfp_dev_page_meta()` returns `title`, `description`, `url`, `image` and
`og_type`.

---

## SEO & Head Metadata

Every plugin page gets server-rendered head metadata built from the actual CFP.DEV data:

- Real `<title>` and meta description per page — talk/speaker detail pages use the talk title / speaker name and abstract/bio
- Open Graph and Twitter Card tags for rich link previews
- `rel="canonical"` on talk/speaker detail pages, in whichever permalink form **Permalinks with Id** is set to — the canonical always names a URL the site actually serves
- JSON-LD structured data: `Event` on talk pages, `Person` on speaker pages
- Talk and speaker detail URLs are listed in `wp-sitemap.xml` (slug mode, WP 5.5+)
- Search-results pages are marked `noindex,follow`
- A talk or speaker URL that cannot be resolved answers `404` only when the API said the entity is gone. While the API is unreachable it answers `503` with `Retry-After` instead, so an outage is not mistaken for a deletion and does not cost the whole programme its search rankings

All lookups use the cached, offline-aware API helpers, so metadata keeps working in offline mode without extra API round-trips.

**Theme opt-in:** see [Theme Integration](#theme-integration) to render the tags yourself and avoid duplicates.

---

## Agentic Browsing (WebMCP)

The search form ships declarative [WebMCP](https://github.com/webmachinelearning/webmcp) tool metadata, so agentic browsers and AI agents can discover and call the conference search as a structured tool instead of scraping the page:

- `toolname="search_conference_programme"` and a `tooldescription` on the search `<form>`
- `toolparamdescription` on the query input describing the expected keyword

No configuration is needed — the metadata is plain HTML attributes and is ignored by regular browsers.

---

## Uninstall

Deleting the plugin through the WordPress admin removes all of its data:
settings, cached transients, any scheduled crawl, and offline snapshot files.
On a multisite network every site is cleaned, not only the one the deletion
was triggered from.

The one deliberate exception is a **pinned** offline snapshot: it may be the
only remaining copy of an event whose CFP.DEV instance no longer exists, so it
is left on disk and reappears in the snapshot picker after a re-install.

---

## Development

### Prerequisites

- PHP ≥ 8.1
- [Composer](https://getcomposer.org)

### Setup

```bash
git clone https://github.com/patbaumgartner/cfp-dev-shortcodes.git
cd cfp-dev-shortcodes
composer install
```

### Lint and test

```bash
composer lint         # PHP_CodeSniffer (WordPress Coding Standards)
composer lint-fix     # phpcbf auto-fix
composer test         # PHPUnit
composer check        # both
composer i18n         # regenerate the .pot (needs WP-CLI)
```

### Test suite

The suite runs against a small in-memory stand-in for WordPress
(`tests/stubs/wordpress.php`) rather than a real installation, so it needs no
database, no web server and no `wp-content` — `composer install && composer test`
is the whole setup, and the full run takes well under a second.

That stand-in backs options, transients, query vars and HTTP with plain arrays,
which makes side effects directly assertable: tests can state that a given page
issued exactly one API request, that an option was migrated, or that a shortcode
produced balanced HTML.

Tests run in a random order, and the hooks a test registers are dropped before
the next one, so no test can pass because of what another left behind.

```
tests/
  bootstrap.php            boots the stand-in, then the plugin
  stubs/wordpress.php      the WordPress functions the plugin uses
  Support/                 base test case, fixtures shaped like real API responses
  Unit/                    helpers, API client, settings, SEO, offline mode,
                           uninstall, structure
  Integration/             end-to-end rendering of every shortcode
```

Fixtures deliberately include the awkward cases: a non-ASCII speaker name, a
speaker with no company or bio, a talk with no time slot, and an image URL that
tries to break out of a CSS `url()` value.

### CI

GitHub Actions runs on every push and pull request to `main`:

| Workflow | What it does |
|----------|--------------|
| `lint.yml` | `php -l` on PHP 8.1 (the declared minimum) and 8.4, plus PHPCS |
| `tests.yml` | PHPUnit on PHP 8.1, 8.2, 8.3 and 8.4 |
| `release.yml` | On a `v*` tag: runs `composer check`, verifies the tag is on `main` and that the plugin header, `CFP_DEV_VERSION` and the CHANGELOG all match it, builds the ZIP with `git archive`, fails if dev files leak in or runtime files are missing, and publishes it with a SHA-256 checksum and build provenance |

---

## Translations

All user-facing strings are translatable under the `cfp-dev-shortcodes` text
domain. The template lives at `languages/cfp-dev-shortcodes.pot` and is
regenerated with `composer i18n` (needs [WP-CLI](https://wp-cli.org)); the test
suite fails if it and the source ever disagree.

To add a language, place a compiled `.mo` file next to it:

```
languages/cfp-dev-shortcodes-nl_NL.mo
```

---

## Contributing

Contributions are welcome! Please read [CONTRIBUTING.md](.github/CONTRIBUTING.md) and open a pull request or issue.

---

## Authors

- **Stephan Janssen** — [@stephan007](https://x.com/stephan007)
- **Patrick Baumgartner** — [@patbaumgartner](https://x.com/patbaumgartner)

---

## License

GPL-2.0-or-later — see [LICENSE.txt](LICENSE.txt).
