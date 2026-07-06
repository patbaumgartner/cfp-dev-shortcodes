# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

---

## [4.3.0] — 2026-07-06

### Added
- Configurable page headings across all list shortcodes: `[cfp_schedule]`, `[cfp_talks_by_tracks]`, and `[cfp_talks_by_sessions]` now accept `title`, `hide_title`, and `hide_search` attributes (matching `[cfp_speakers]`, which also gains `hide_title`)
- Shared `cfp_dev_page_header()` helper renders the heading/subtitle/search block identically on every page — headings were previously hardcoded per shortcode

### Changed
- Detail pages (`[cfp_speaker_details]`, `[cfp_talk_details]`) intentionally render no page heading — the speaker name / talk title is the heading (now documented)
- Cache keys for schedule/tracks/sessions include the attribute set when customised, so different `title` configurations no longer share cached HTML

---

## [4.2.4] — 2026-07-06

### Fixed
- Speaker/talk pages rendered squeezed into a narrow column when the WordPress theme's page template uses a narrow content container (e.g. 747px in the voxxed-conference theme; the previously used Neve theme's 1170px container masked this). `main.cfp-main` now breaks out of the theme container to the plugin's design width (`--cfp-layout-x`, 1024px) capped at the viewport, centred on the page — verified live on vdz27.voxxeddays.ch speaker and talk pages

---

## [4.2.3] — 2026-07-06

### Fixed
- Dark theme rendered white-on-white on sites whose WordPress theme paints an opaque `<body>` background: the plugin only coloured the `html` element, so the theme's body background covered it. The active cfp theme now repaints `<body>` with `--cfp-background-primary` / `--cfp-text-primary` (verified live on vdz27.voxxeddays.ch)

---

## [4.2.2] — 2026-07-06

### Removed
- 19 unused image assets (~verified against all emitted markup): `gfx/track/*.png` (11 — track icons always come inline from the API), `gfx/store/{ios,android}.svg` (removed Home shortcode), `gfx/session/quality/*.svg` (removed star-rating feature), `gfx/pagination/{prev,next}.svg`, `gfx/video/play.svg`, `gfx/theme/system.svg` — plus their now-dead CSS rules

---

## [4.2.1] — 2026-07-06

### Changed
- Rewrote the plugin description shown in the WordPress admin — the old text referenced removed shortcodes (MySchedule, Home) and read like a release note
- Plugin URI now points to the GitHub repository (the old GitLab wiki link was dead)

---

## [4.2.0] — 2026-07-06

### Added
- `uninstall.php`: deleting the plugin now removes all options, legacy transients, cached content, and offline snapshot files
- `[cfp_speakers]` renders the documented `subtitle` attribute (was accepted but silently ignored)
- Directory-listing guard `index.php` files in `js/`, `images/`, and `shortcode/include/`
- `shortcode/include/` (offline crawler) and `uninstall.php` are now covered by PHPCS linting

### Security
- Fixed stored/reflected XSS in the speaker photo gallery: the `speaker_name` GET parameter is now escaped with `esc_attr()` before being written into the `alt` attribute (and into the transient cache)
- Removed the wide-open `Access-Control-Allow-Origin: *` CORS headers from the `get_speaker_photos` AJAX endpoint
- All `id` query vars are now `absint()`-validated and the schedule day name is whitelisted — user input can no longer reach API paths, snapshot file paths, or transient keys unchecked
- `getJSON()` rejects paths containing `..`; offline snapshot reads enforce a `realpath()` containment check
- Spotify podcast embeds now validate the URL host (`open.spotify.com`) instead of a substring match, and escape the iframe `src`
- Social links (`mastodonUsername` et al.) escaped with `esc_url()` (blocks `javascript:` URIs) and carry `rel="noopener noreferrer"`
- CFP.DEV key restricted to `[a-z0-9-]` so it cannot alter the API hostname
- Track/session descriptions, event/track/session names, room names and similarity scores are now escaped (`wp_kses_post` / `esc_html`)
- The speaker photo AJAX URL is built with `add_query_arg()` and injected as a JSON literal — speaker names can no longer break the inline script

### Fixed
- Cache-delete forms on the settings page were rejected by the nonce check (they had no nonce field); nonce fields added to every form
- AJAX cache deletion deleted the wrong transient keys (raw id instead of the hashed key) — now routed through `generate_cfp_cache_key()`
- Admin schedule-cache list used lowercase day names and never matched the capitalised keys written by the shortcode
- Removed rewrite rules referencing non-existent regex capture groups (`$matches[1]`/`$matches[2]`)
- Changing the URL path prefix now rebuilds and flushes rewrite rules immediately
- “Enable Theme Switching” could never be switched off (unchecked checkboxes are absent from POST)
- Settings are stored in options instead of transients (transients can be evicted by object caches, silently wiping the API key); legacy values migrate automatically, and the settings form no longer shows stale values after save
- Photo cache was stored with no expiry when caching was set to “No Cache”; caching is now skipped entirely in that mode
- Shortcode boolean attributes (`random=false`, `hide_search=no`, `all=false`) were treated as true; now parsed with `FILTER_VALIDATE_BOOLEAN`
- `[cfp_speakers]` cache is keyed per attribute set — two pages with different `size`/`title`/`random` no longer serve each other's HTML
- Numerous PHP 8 fatals when the API is unavailable (null dereferences in schedule, talk details, speaker details, talks-by-tracks/-sessions)
- `searchJSON()` returned an error string on failure, crashing callers that expect an array; it now returns `[]`
- `getFooter()` returned `null` when theme switching is off (PHP 8.1 deprecation on concatenation)
- Stray `</a>` producing malformed HTML in speaker-page talk cards
- The search form posted to a relative URL and 404ed on nested pages; it now posts to the absolute search-results URL
- Cache deletions on the settings page are processed before rendering, so tables reflect the new state
- Failed slug lookups are negative-cached (5 min) instead of re-downloading the full speaker/talk list on every hit
- `og:title`/`og:url` meta tags use the `property` attribute; descriptions are stripped of tags before truncation (multibyte-safe)
- Page auto-creation uses `get_page_by_path()` so existing drafts no longer cause duplicates

### Changed
- Cache invalidation redesigned: every transient key embeds a cache version, so “clear cache” is a single option increment (previously ~20+ blocking API round-trips enumerating keys — many of which missed)
- `sleep(5)` retry in the public photo endpoint replaced with a 250 ms pause (a pinned PHP-FPM worker per anonymous request was a DoS amplifier)
- Offline snapshots are pruned after each successful crawl (newest 2 kept) — disk usage no longer grows unboundedly
- Plugin CSS is registered once under the `cfp-dev-style` handle (was registered 7× as the collision-prone `style1`); `site.js` moved to the footer
- The duplicated inline root-class script (6 copies) is now a single shared helper, `cfp_dev_root_class_script()`
- Duplicate `query_vars` filters consolidated into `cfp_dev_add_query_vars()`
- Settings menu slug renamed from boilerplate `my-unique-identifier` to `cfp-dev-settings`
- Speaker-list fetch size unified into `CFP_DEV_SPEAKERS_FETCH_SIZE` (was 300/400/500 in different places)
- Documentation pass: corrected copy-pasted file headers, fixed inaccurate docblocks, removed commented-out code and stale comments; README documents the actual `[cfp_schedule]` URL parameter, cache behaviour, snapshot retention, and uninstall cleanup

---

## [4.1.0] — 2026-06-03

### Added
- Offline Mode: new admin setting that crawls all API endpoints and CDN images into a local dated snapshot; once active, every `getJSON()` call is served from the snapshot with zero external requests
- Background crawler (`shortcode/include/offline-crawler.php`): fetches event, tracks, session types, rooms, all speakers + detail pages, photo albums, all talks + detail pages, talks-by-track, talks-by-session-type, full-day and per-room schedules for every event day
- Crawler image rewriter: collects all CDN image URLs (`imageUrl`, `imageURL`, `trackImageURL`, `thumbnailUrl`), downloads to `wp-content/uploads/cfp-dev-offline/{snapshot}/images/`, rewrites every URL in every saved JSON file in a single pass
- CDN coverage: speaker photos (AWS S3, Google, GitHub avatars), track images (AWS S3), Flickr album thumbnails — all downloaded and served locally
- Crawl progress polled live in the admin UI via AJAX (`cfp_dev_crawl_progress`); progress bar and step label update every 3 seconds
- Admin Re-crawl Now button: triggers a new snapshot without touching the existing one; disabling offline mode preserves snapshot data
- Manifest file (`manifest.json`) written at crawl completion with per-URL fetch log and summary stats

### Changed
- `searchJSON()` returns an empty result set in offline mode — no broken requests to `search.cfp.dev`

---

## [4.0.1] — 2026-06-03

### Added
- `cfp_dev_log()` debug helper gated on `WP_DEBUG_LOG`
- PHP_CodeSniffer + WordPress Coding Standards linter via Composer (`composer lint` / `composer lint-fix`)
- GitHub Actions CI workflow for PHP syntax check (`php -l`) and PHPCS
- Patrick Baumgartner as co-author

### Changed
- Modernised for PHP 8+ and WordPress 6.0+: replaced cURL with `wp_remote_get()`, `wp_date()`, spaceship operator, `str_contains()`
- Inlined all external resources: jQuery UI 1.14.2 (JS + CSS), Luxon 3.7.2, Moment.js 2.30.1 — no longer loaded from CDN
- Converted all indentation to tabs in PHP, CSS, and JS files; zero PHPCS errors/warnings across all 9 PHP files

### Fixed
- Security: added `esc_html` / `esc_url` / `esc_attr` / `wp_kses_post` / `absint` throughout all shortcode output
- Security: added CSRF nonce to admin settings form; sanitized all `$_POST` inputs with `sanitize_text_field(wp_unslash(...))`
- Fatal PHP 8 error: renamed `CFP_DEV_CFP_DEV_APPLICATION_JSON` constant to `CFP_DEV_APPLICATION_JSON` (all API calls were crashing)
- Silent bug in `get_speaker_photos()`: `echo $content;` was on the same line as a `phpcs:ignore` comment and was never executed; speaker photo albums now render correctly
- Uninitialized variables (`$content`, `$trackDescr`, `$sessionDescr`); removed deprecated `COUNT_NORMAL` and `date_default_timezone_set()`
- CSS: missing space after comma in multi-speaker separator (`content: ', '`)

### Removed
- Dead code: `isCurrentCache()`, `currentSummaryFlag()`, `storeCfpDevSummary()`, `getEventDetails()`, `getHTMLSummary()`, `searchBooks()`, duplicate `register_cfp_shortcodes()`, `cfp_speaker_details_template()`, unused constants (`CFP_DEV_THEME`, `CFP_DEV_SEARCH_BOOKS`), and commented-out JS code in `ajax-cfp-v3.4.js`

---

## [3.6.2] — 2025-06-09

### Changed
- Use CSS uppercase for speaker names on the agenda page

---

## [3.6.1] — 2025-03-15

### Fixed
- Enforce transparent background for text

---

## [3.6.0] — 2025-03-09

### Added
- Configuration setting to show/hide rooms
- Support for subfolders

### Fixed
- Various broken links

---

## [3.5.0] — 2024-02-09

### Added
- Bluesky and Mastodon social links on speaker profile (2024-11-18)
- Permalink options using slug or id (2024-10-02)
- Support for Spotify podcast embeds (2024-09-21)
- Slug caching + speaker photos ALT text (2024-09-19)
- Support for talk and speaker slugs in the URL (2024-09-15)
- Keywords shown on talk details page (2024-08-14)
- Show speaker photos loading label (2024-08-12)
- Improved caching logic + admin view for cache management (2024-08-12)
- Speaker images retrieved async to speed up page load and cached (2024-08-07)
- `hide_search` param for `cfp_speakers` shortcode (2023-11-14)
- Show room name (2023-10-10)
- Admin-configurable default theme (2023-09-05)
- Light / dark theme option (2023-08-01)
- Support for GPT-generated summaries on YouTube transcripts via Devoxx Insights (2023-03-27)
- Show all talks for `cfp_talks_by_tracks` when attribute `all=true` (2023-03-15)
- Show event days on the overview home page (2023-03-04)
- Cache selection for CFP.DEV pages (2023-03-02)
- Similarity search — show similar talks and related books (2023-01)
- Support for CFP.DEV v1.10 or higher (2021-05-26)
- Support proposal ratings (2022-04-29)

### Changed
- Mobile app support; removed MySchedule and Home page shortcodes (2023-09-05)
- Removed `strip_tags` for speaker bio, enabling links and HTML in bios (2024-05-13)
- Increased speakers size to 400; fix encode search query; added array check before forEach (2024-03-07)
- Brand new design (2022-07-25)

### Fixed
- Speaker names in talks-by-track/session list (2025-02-09)
- `home_url()` sometimes returned IP instead of domain name (2024-09-27)
- Speaker photos fix using more robust `getJSON()` method (2024-08-14)
- "Delete Cache" button update when pressed (2024-08-12)
- Div not properly closed for similar talks (2023-04-08)
- Fix for clear cache of talks (2023-03-25)
- Schedule link fix using relative paths (2023-03-06)
- Register button on MySchedule now uses relative path (2023-03-03)
- Fixed Clear cache URL issue (2023-03-01)
- Check if proposal has speakers (2023-02-28)
- CSS SVG image URL fix + relative URL fix (2023-02-27)
- CSS and cache fix (2023-02-26)
- Schedule tag search href (2022-05-30)
- Centralize CFP.DEV REST URL; clear cache includes talks and speaker pages (2022-05-22)
- Strip HTML from speaker description for social cards (2022-04-11)
- Show event timezone on schedule page (2021-11-09)

---

## [1.0.0] — 2019-07-30

### Added
- Initial release
- `cfp_speakers` shortcode with size, title, subtitle params
- `cfp_speaker_details` shortcode with Flickr photo gallery and YouTube embed
- `cfp_schedule` shortcode with day-based time grid
- `cfp_talk_details` shortcode with YouTube embed, tags, schedule info
- `cfp_talks_by_tracks` and `cfp_talks_by_sessions` shortcodes
- `cfp_search_results` shortcode
- Transient-based caching (24 h TTL)
- Favouriting of talks on schedule page
- Responsive CSS grid with media queries
