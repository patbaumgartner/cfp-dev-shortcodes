# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

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
