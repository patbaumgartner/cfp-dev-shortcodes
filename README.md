# CFP.DEV WordPress Shortcodes Plugin

[![PHP Lint](https://github.com/patbaumgartner/cfp-dev-shortcodes/actions/workflows/lint.yml/badge.svg)](https://github.com/patbaumgartner/cfp-dev-shortcodes/actions/workflows/lint.yml)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D8.0-8892BF?logo=php)](https://php.net)
[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759B?logo=wordpress)](https://wordpress.org)
[![License: GPL v2+](https://img.shields.io/badge/License-GPL%20v2%2B-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Stable Tag](https://img.shields.io/badge/stable-4.1.0-brightgreen)](https://github.com/patbaumgartner/cfp-dev-shortcodes/releases)

> WordPress shortcodes plugin for [CFP.DEV](https://cfp.dev) — display speakers, talks, schedules, and search results from your CFP.DEV instance directly on your WordPress site (Devoxx, VoxxedDays, and more).

---

## Features

- **Speakers list** — grid view with photos, sorted by last name or random; includes live search
- **Speaker details** — full profile with bio, social links (Twitter, LinkedIn, Bluesky, Mastodon), and async Flickr photo gallery
- **Talk details** — description, speakers, track, schedule info, YouTube embed, Spotify embed, related talks (semantic search), and star ratings
- **Schedule** — time-grid per day with room columns, current-time indicator, and favorite counts
- **Talks by track / by session type** — filterable tables with navigation tabs
- **Search results** — exact keyword matches + semantic similarity results via [search.cfp.dev](https://search.cfp.dev)
- **Offline mode** — crawls all API endpoints and CDN images into a local snapshot; serves everything locally with zero external requests
- **Caching** — WordPress transient-based cache with configurable TTL (none, 1 h, 1 day, 1 week, 1 month)
- **Theming** — light / dark theme with optional user toggle
- **Admin UI** — API key, event settings, per-item cache management, offline crawl progress

---

## Requirements

| Requirement | Version |
|-------------|---------|
| PHP | ≥ 8.0 |
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
| `title` | — | Heading above the grid |
| `subtitle` | — | Sub-heading |
| `hide_search` | `false` | Hide the search form |

```
[cfp_speakers size=20 random=yes title="Our Speakers" subtitle="Meet the lineup"]
```

---

### `[cfp_speaker_details]`

Renders the full speaker profile page. Reads `speaker_slug` or `id` from the URL query string — no attributes needed.

---

### `[cfp_talk_details]`

Renders the full talk details page. Reads `talk_slug` or `id` from the URL query string — no attributes needed.

---

### `[cfp_schedule]`

Displays the conference schedule grid.

| Attribute | Default | Description |
|-----------|---------|-------------|
| `day` | *(from URL)* | Day name to display, e.g. `monday` |

```
[cfp_schedule]
```

---

### `[cfp_talks_by_tracks]`

Lists talks grouped by track.

| Attribute | Default | Description |
|-----------|---------|-------------|
| `all` | `false` | Show all tracks at once (`true`) instead of the tab selected by `id` |

```
[cfp_talks_by_tracks all=true]
```

---

### `[cfp_talks_by_sessions]`

Lists talks grouped by session type (Conference, Workshop, BOF, Lightning Talk, …).

---

### `[cfp_search_results]`

Displays keyword search results and semantic similarity results. Reads `query` from the URL — no attributes needed.

---

## Offline Mode

Enable **Offline Mode** in **Settings → CFP.DEV** to crawl the entire CFP.DEV API and all CDN images (speaker photos, track images, Flickr album thumbnails) into a local dated snapshot under `wp-content/uploads/cfp-dev-offline/`.

Once active:
- All `getJSON()` calls are served from the local snapshot
- No external requests to `*.cfp.dev` or CDN hosts are made
- A `manifest.json` is written at crawl completion with per-URL stats
- Click **Re-crawl Now** in the admin UI to refresh the snapshot at any time

---

## Development

### Prerequisites

- PHP ≥ 8.0
- [Composer](https://getcomposer.org)

### Setup

```bash
git clone https://github.com/patbaumgartner/cfp-dev-shortcodes.git
cd cfp-dev-shortcodes
composer install
```

### Lint

```bash
composer lint         # PHP_CodeSniffer (WordPress Coding Standards)
composer lint-fix     # phpcbf auto-fix
```

### CI

GitHub Actions runs PHP syntax check (`php -l`) and PHPCS on every push and pull request to `main`.

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
