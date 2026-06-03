# ChangeLog

### Version 4.1.0

- (03 June 2026). Add Offline Mode: new admin setting that crawls all API endpoints and CDN images into a local dated snapshot; once active, every `getJSON()` call is served from the snapshot and no external requests are made
- (03 June 2026). Add background crawler (`shortcode/include/offline-crawler.php`): fetches event, tracks, session types, rooms, all speakers + detail pages, photo albums, all talks + detail pages, talks-by-track, talks-by-session-type, full-day and per-room schedules for every event day
- (03 June 2026). Crawler step 2–4: collect all external CDN image URLs (`imageUrl`, `imageURL`, `trackImageURL`, `thumbnailUrl`), download to `wp-content/uploads/cfp-dev-offline/{snapshot}/images/`, rewrite every URL in every saved JSON file in a single pass
- (03 June 2026). CDN coverage: speaker photos (AWS S3, Google, GitHub avatars), track images (AWS S3), Flickr album thumbnails (`live.staticflickr.com`) — all downloaded and served locally
- (03 June 2026). Crawl progress polled live in the admin UI via AJAX (`cfp_dev_crawl_progress`); progress bar and step label update every 3 seconds
- (03 June 2026). Admin: Re-crawl Now button triggers a new snapshot without touching the existing one; disabling offline mode preserves snapshot data
- (03 June 2026). Search (`searchJSON`) returns an empty result set in offline mode — no broken requests to search.cfp.dev
- (03 June 2026). Manifest file (`manifest.json`) written at crawl completion with per-URL fetch log and summary stats

### Version 4.0.1

- (03 June 2026). Modernise for PHP 8+ and WordPress 6.0+: replace cURL with `wp_remote_get()`, `wp_date()`, spaceship operator, `str_contains()`
- (03 June 2026). Security: add `esc_html`/`esc_url`/`esc_attr`/`wp_kses_post`/`absint` throughout all shortcode output
- (03 June 2026). Security: add CSRF nonce to admin settings form; sanitize all `$_POST` inputs with `sanitize_text_field(wp_unslash(...))`
- (03 June 2026). Add `cfp_dev_log()` debug helper gated on `WP_DEBUG_LOG`
- (03 June 2026). Fix uninitialized variables (`$content`, `$trackDescr`, `$sessionDescr`); remove deprecated `COUNT_NORMAL` and `date_default_timezone_set()`
- (03 June 2026). Add PHP_CodeSniffer + WordPress Coding Standards linter via Composer (`composer lint`)
- (03 June 2026). Add GitHub Actions CI workflow for syntax check and phpcs
- (03 June 2026). CSS: fix missing space after comma in multi-speaker separator (`content: ', '`)
- (03 June 2026). Add Patrick Baumgartner as co-author
- (03 June 2026). Fix fatal PHP 8 error: rename `CFP_DEV_CFP_DEV_APPLICATION_JSON` constant to `CFP_DEV_APPLICATION_JSON` (all API calls were crashing)
- (03 June 2026). Fix silent bug in `get_speaker_photos()`: `echo $content;` was accidentally on the same line as a phpcs:ignore comment and was never executed; speaker photo albums now render correctly
- (03 June 2026). Inline all external resources: jQuery UI 1.14.2 (JS + CSS), Luxon 3.7.2 (renamed from `luxon2.0.min.js`), Moment.js 2.30.1 — no longer loaded from CDN
- (03 June 2026). Convert all indentation to tabs in PHP, CSS, and JS files; zero phpcs errors/warnings across all 9 PHP files
- (03 June 2026). Remove dead code: `isCurrentCache()`, `currentSummaryFlag()`, `storeCfpDevSummary()`, `getEventDetails()`, `getHTMLSummary()`, `searchBooks()`, `register_cfp_shortcodes()` duplicate, `cfp_speaker_details_template()`, unused constants (`CFP_DEV_THEME`, `CFP_DEV_SEARCH_BOOKS`), and commented-out JS code in `ajax-cfp-v3.4.js`

### Version 3.6.2

- (09 June 2025). Use CSS Uppercase for speaker names on the agenda page

### Version 3.6.1

- (15 March 2025). Enforce transparent background for text

### Version 3.6.0

- (9 March 2025). Add configuration setting to show/hide rooms
- (8 March 2025). Add support for subfolders + fix some broken links
- (8 March 2025). Fix some broken links

### Version 3.5.0

- (9 Feb 2025). Fix list speakers name on talks by track/session
- (18 Nov 2024). Added Bluesky and Mastodon to speaker profile
- (2 Oct 2024). Introduced permalink options using slug or id
- (27 Sep 2024). Removed home_url() which used sometimes IP instead of domain name
- (21 Sep 2024). Support for Spotify podcast embeds
- (19 Sep 2024). Slug caching + Speaker photos ALT text
- (15 Sep 2024). Support for talk and speaker slugs in the URL + Cleanup of unused methods
- (14 Aug 2024). Keywords are now shown on the talk details page + Get speaker photos fix using more robust getJSON method
- (12 Aug 2024). Show speaker photos loading label + Increased read timeout + Update "Delete Cache" button when pressed 
- (12 Aug 2024). Improved caching logic + admin view for cache management
- (7 Aug 2024). Speaker images are retrieved async to speed up the page load and cached.
- (13 May 2024). Removed strip_tags for Speaker bio, so we can have links in the bio and HTML rendering
- (7 Mar 2024). Fix encode search query. Added array check before forEach.  Increase speakers size to 400
- (19 Jan 2024). Use curl in getJSON() with keep-alive
- (14 Nov 2023). Added 'hide_search' param for the speakers shortcode
- (10 Oct 2023). Show room name
- (5 Sep 2023). Default theme can now be defined by Admin
- (5 Sep 2023). Support the new mobile app. Removed MySchedule and Home page shortcodes.
- (1 Aug 2023). Support light / dark theme option
- (8 April 2023). Div not properly closed for similar talks
- (27 March 2023). Added support for GPT generated summaries on YouTube transcripts with help of Devoxx Insights
- (25 March 2023). Fix for clear cache of talks
- (15 March 2023). Show all talks for talks_by_tracks when attribute 'all' is set to true
- (6 March 2023). Schedule link fix using relative paths
- (4 March 2023). Show event days on the "overview" home page
- (3 March 2023). The Register button on MySchedule now uses a relative path which was a problem for some VoxxedDays websites.
- (2 March 2023). Support cache selection for CFP.DEV pages
- (1 March 2023). Fixed Clear cache URL issue
- (28 February 2023). Check if proposal has speakers
- (27 February 2023). CSS svg image URL fix + Relative URL fix
- (26 February 2023). CSS and cache fix
- (Jan 2023). Similarity search, show similar talks and related books
- (25 July 2022). Brand new design
- (30 May 2022). Corrected the schedule tag search href.
- (22 May 2022). Centralize the CFP.DEV REST URL. Clear cach also includes the talks and speaker pages.
- (17 May 2022). Include session type name and track logo in search results.
- (10 May 2022). Show message when users authenticated using his Firebase OAuth credentials.
- (29 April 2022). Support proposal ratings
- (11 April 2022). Strip HTML from the speaker description field when using for social cards.
- (9 Nov 2021). Show the event timezone on schedule page
- (26 May 2021). Support for CFP.DEV v1.10 or higher
- (8 February 2020). Corrected error handling for wp_remote_get
- (31 January 2020). Corrected documentation and cache issue
- (29 November 2019). Include speaker-img-'index' for each Flickr image of speaker
- (25 November 2019). Embed YouTube video on speakers detail page
- (30 October 2019). Embed YouTube video when available
- (29 October 2019). Show mobile apps link in footer
- (18 October 2019). Removed 'Error' check which blocks talks with error in their talk description
- (8 October 2019). Clear cache manually for speaker or talk details page
- (8 October 2019). Use thumbnail flickr images for overview with link to high-res version
- (7 October 2019). Show Flickr speaker images
- (7 October 2019). Show total favs on schedule page
- (17 September 2019). Added CSS media queries to make grid responsive
- (17 September 2019). Search HTTP GET timeout of 30 seconds added
- (17 September 2019). CSS updates and show session type name on schedule
- (16 September 2019). Show time slot details on talk details page when available
- (12 September 2019). Introduced search results shortcode
- (12 September 2019). Introduced My schedule shortcode
- (11 September 2019). CFP_DEV_KEY global variable introduced
- (10 September 2019). Favouriting of talks on schedule
- (6 September 2019). Include link to speaker in talk lists
- (19 August 2019). Cache items are now valid for 24 hours
- (17 August 2019). Show content tags on talk details page
- (16 August 2019). Shortcodes added to show talks by track and session type
- (15 August 2019). Sort speakers lastname using iconv php method
- (15 August 2019). Fix for cfp_speakers size parameter, added title and subtitle params
- (9 August 2019). Introduce size param for cfp_speakers short code
- (7 August 2019). Uses /api/public/schedules instead of /api/public/schedule
- (4 August 2019). Added transient caching for speakers
- (2 August 2019). Two new shortcodes to show schedule for a given day and talk details.
- (30 July 2019). Initial Release.
