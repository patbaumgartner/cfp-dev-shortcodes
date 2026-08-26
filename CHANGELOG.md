# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

---

## [4.9.0] — 2026-08-26

### Added
- **The Snapshot Status box now explains its fetch errors.** "36 errors (see manifest.json)" sent the operator to a JSON file on the server's filesystem to learn that most of them were speakers without a Flickr photo album. The box now sorts the errors into what they mean — speakers with no photo album (normal, nothing is missing on the pages), images that could not be saved locally (pages still show them, but from the CDN), and endpoints that could not be captured — and a collapsed list names every failed URL with its reason, read from the snapshot's own manifest. The crawl tally, the crawl state and the manifest now carry the same breakdown

## [4.8.1] — 2026-08-26

### Fixed
- **A speaker photo served with a generic content type was refused by the crawler.** S3 buckets serve files uploaded without a content type as `binary/octet-stream` — on one instance that was 35 of 52 speaker photos, each counted as a crawl error and left pointing at the CDN, quietly defeating offline mode's no-external-requests promise. A generic type carries no information, so the bytes now decide: the body must parse as one of the allowed raster formats (the same allow-list as before). A server that names a real non-image type — `text/html`, `image/svg+xml` — is still taken at its word and refused, so the sniff does not become an SVG smuggling path

## [4.8.0] — 2026-08-26

### Added
- **A "Delete All Caches" button on the settings page.** Every cached speaker, talk, schedule and photo could only be deleted one entry at a time; a full wipe happened only as a side effect of saving the settings. The Manage Caches section now starts with a single button that invalidates everything at once (with a confirmation dialog), using the same O(1) version bump the settings save already used
- **Offline mode can pin a dated snapshot.** Offline reads always served the newest snapshot, so once the CFP.DEV instance behind an event was gone, the next crawl — or retention pruning — could silently replace or delete the only copy worth keeping. The Offline Mode form now lists every completed snapshot and lets the operator pin one: pinned reads keep serving that date's speakers, talks and schedule, the pruner never deletes a pinned snapshot, and a pin that disappears from disk falls back to the latest rather than taking the site down. Changing the selection re-renders all cached pages, since their HTML embeds the snapshot's own image URLs. A pinned snapshot even survives uninstalling the plugin — it may be data no re-crawl can ever recreate — and reappears in the picker after a re-install, ready to be pinned anew; only the unpinned snapshots are cleaned up

### Changed
- **The settings page now follows the WordPress admin conventions.** One `.wrap` instead of two, proper `h2`/`h3` section headings, `notice-success is-dismissible` for notices, labelled form fields (`for`/`id`) with `description` paragraphs instead of `<small>` tags, and no more inline-styled black `<hr>` separators. Empty cache tables are no longer rendered — the "nothing cached" message stands alone — and talk detail caches now delete via AJAX without a page reload, exactly like speaker caches

## [4.7.1] — 2026-08-25

### Fixed
- **The README still promised 4.6.0 was the newest release.** The release workflow verifies the plugin header, the version constant and the changelog against the tag, but the README's stable badge is none of those, so nothing noticed when it stopped being updated. The badge now reads the current version — and a structure test ties it to the version constant, so the next stale badge fails the build instead of misleading visitors
- **A talk whose tags were not a list raised a PHP warning.** `timeSlots` and `speakers` already guard against the API changing shape under them; the tag pills now do the same
- **A snapshot file the crawler could not re-read was skipped without a word.** Step 2 of a crawl re-reads every saved JSON file to collect image URLs; a file that fails to read or decode only costs image localisation — step 3 keeps the CDN URL for anything it could not localise — but the silence left nothing to diagnose a partially localised snapshot with. The skip is now logged

### CI
- **Every workflow job now has a timeout, and no checkout keeps its credentials.** A hung job used to hold its runner for GitHub's six-hour default, and every checkout persisted a token into the working copy that no later step needed
- **Releases are prepared by a script instead of from memory.** `composer prepare-release X.Y.Z` bumps the four places the version lives — plugin header, version constant, README badge and translation template — after refusing a dirty tree, a branch other than `main`, a stale local `main`, and a changelog whose newest entry is not a dated entry for the new version. It then runs the same lint-and-test gate as CI and prints the commit, push and tag steps that remain. The new maintainer `bin/` directory is excluded from the release ZIP, and the ZIP verification now refuses it alongside the other development files

## [4.7.0] — 2026-08-14

### Accessibility
- **Neither theme met WCAG AA, and the light one failed almost everywhere.** One stylesheet serves both themes, and two greys were written into it as literals: `#a7a7a7` reads at 7.0:1 on the dark background and 2.3:1 on the light one, `#484848` is exactly the reverse (8.8:1 and 1.8:1). Between them they coloured the table column headers, every search placeholder, the session type on a talk page and the schedule's favourite count — each unreadable in one of the two themes. The light accent was too pale for either job it had: 3.3:1 as link and company text, 3.5:1 as a button background under white text. The schedule's "Mobile Schedule" button then forced `color:white` inline over a stylesheet that had already chosen a working colour, giving white on amber at 2.0:1 — the worst ratio on any page. All seven pages now pass in both themes
- **The plugin's pages had no headings at all.** Page titles, talk titles and speaker names are all `<div class="cfp-name">`, so screen-reader users met an undifferentiated run of text with no way to jump to the talk they came for. Each is now announced as a heading — level 2 for the subject of the page, level 3 for the speakers, talks and results within it, since the theme owns the `<h1>`. The rendering is unchanged: the stylesheet is compiled from a design system and styles these by class, so real heading elements would have outranked `.cfp-name` and restyled every one of them

### Performance
- **Text was hidden while the webfonts loaded.** The three Acumin faces declared no `font-display`, so the browser default applies and the text a page is about to draw stays invisible for up to three seconds — the talk titles, the speaker names, the whole schedule — although the HTML and CSS have already arrived. `swap` draws the fallback immediately and switches when the font is ready

### Security
- **The crawler could publish an SVG under the site's own origin.** Snapshots are written to `wp-content/uploads` and served from the site's origin, and an SVG is a document that can carry script — which is why WordPress core refuses SVG uploads. Both the extension allow-list and the content-type map admitted it, so an image URL from a hostile or compromised CFP.DEV instance could place active same-origin content at a path derived from that URL, to be executed by getting anyone to open it directly. SVG is no longer an image here; such a URL keeps pointing at the CDN, exactly like one that failed to download
- **A slow search could take the site down.** Every other API read waits up to 30 seconds because the answer populates a cache, so at most one visitor pays that cost. Search results are never cached — the query space is unbounded — so every request paid in full, on a public URL that takes its query from the visitor. Enough concurrent searches against a slow upstream occupied every PHP worker the site had. The search path now gives up after eight seconds, and `cfp_dev_api_timeout` receives the path it is deciding for

### Fixed
- **An incomplete offline snapshot was published as a complete one.** The crawl only refused to finish when it had captured neither talks nor speakers, so a 503 on `public/rooms` still wrote `manifest.json`, switched offline mode on, and served a site with no schedule until somebody crawled again. A 200 was trusted as JSON too, so a proxy error page was stored as though it were data and the failure surfaced later, from the snapshot, on a live page. Failures are now counted in two columns: anything the site needs blocks publication and leaves the previous snapshot serving, while images do not, because one that fails keeps its CDN URL and still renders
- **An API outage reported every talk and speaker as permanently gone.** The soft-404 fix in 4.6.0 answered HTTP 404 whenever a detail lookup came back empty, and a removed talk looks exactly like an unreachable API. A minute of downtime therefore told every crawler that visited that the whole programme had been deleted. The API client now separates the two — a 404 is an answer; a transport error, a 5xx, a body that is not JSON or a missing key is the absence of one — and a detail page answers 404 only when a successful lookup proved the entity absent, and 503 with `Retry-After` while it cannot tell
- **The schedule grid disagreed with the day it was showing.** A single-day event, which carries only `fromDate`, was reported as "Event dates are not set." and rendered nothing, although the crawler had always read an absent end that way. The grid's rows stopped at the hour the last session ends *in*, so a day running to 11:20 laid down rows to 11:00 and let that session spill out of them. Those bounds were taken from the first and last entries of a list that is the API's order rather than a chronological one, and a single unparseable entry at either end cost the whole grid. And a session whose `sessionType.duration` the API omits was rendered with no height at all, because the stylesheet maps that field to a row span in five-minute steps and has no rule for zero — the slot's own timestamps now answer when the API does not
- **The crawler fetched the wrong day for late-evening events.** It derived weekday names in UTC while the schedule page resolves them in the event timezone, so an event starting at 23:30 local was crawled as the previous day and the page then asked for a day the snapshot did not contain
- **A crawl that was killed could not be recovered from the settings screen.** It never writes a terminal state, so its status read "running" for ever: the screen showed a progress bar that would never move, and enabling offline mode became a no-op because the handler declines to start a crawl while one is "already" running. A crawl past its deadline is no longer in progress, and the screen says it stopped. Within the deadline a second crawl is refused rather than started, since the two share one progress state and would race to publish
- **Two crawls in the same second shared a snapshot directory**, because the name has one-second resolution — the second wrote over a good snapshot's files and left its manifest behind, marking the wreckage complete
- **Retention could delete the last working snapshot.** It counted every timestamped directory rather than the completed ones, so two abandoned crawls pushed the only usable snapshot out of the window
- **The canonical URL advertised permalinks the site had turned off.** In id mode — which multisite installs are required to use — every internal link is `/talk?id=200` while the canonical, `og:url` and JSON-LD `url` all named `/talk/<slug>/`. The canonical is the URL a search engine indexes, so the plugin was asking to be indexed under URLs the site does not serve. All three now go through the same permalink helpers the rest of the plugin links with; slug-mode sites are unaffected
- **The highlighted track was not the track being listed.** The filter navigation on `[cfp_talks_by_tracks]` is sorted by track name, but the default track was taken from the API's own order. Whenever the two disagreed — which is most of the time — the page opened with one tab marked active and a different tab's talks below it
- **An API record missing a field printed PHP warnings into the page.** The plugin treats a handful of fields as always-present — a track's name and id, a tag's name, a speaker's last name, a Flickr photo's ids, the event's name — and a CFP.DEV instance that omits one turned every read of it into a warning rendered in the middle of the page being built. That included the Settings → CFP.DEV cache tables, which is where an administrator goes when something is already wrong
- **One talk's timing ids appeared once per talk on a speaker's page.** The time-slot block ends in three hidden inputs — `cfpTimezone`, `cfpTalkFrom`, `cfpTalkExpiry` — that let a theme localise or count down the talk on screen. An `id` is a document-wide handle, so a speaker with two scheduled talks emitted each id twice and `getElementById()` answered every lookup with whichever talk came first. They describe *the* talk of a page, so only the talk detail page emits them now; the speaker page keeps the visible date, time and room
- **The "no tracks / no session types" page was unstyled.** Both list shortcodes fell back to a message wrapped in `dev-cfp-row` and `dev-cfp-column` — classes with no rules anywhere in the stylesheet, left behind by an older naming scheme
- **The admin scripts spoke English on translated sites.** 4.6.0 made the plugin translatable, but both admin scripts carried about twenty strings of their own. The crawler script is the worse case: it does not add text beside the translated status box, it replaces that box outright, so the status reverted to English a second after the page loaded. Both are handed their strings from PHP now, reusing the msgids the screen already uses
- **The "Delete Cache" button never said anything.** It is an `<input type="submit">`, whose label is its value, and the script set `.text()`. Nobody ever saw "Deleting...", and no error from the endpoint ever reached the screen — a failed deletion looked exactly like a successful one apart from the row staying put
- **The progress poller repainted a dead crawl as running.** The settings screen learned to report a killed crawl as stopped, but the status the admin script starts from and the endpoint it polls still handed it the stored "running", so the script began polling and overwrote the message about a second later — then polled for ever
- **A user-facing string could not be translated.** `languages/cfp-dev-shortcodes.pot` had been regenerated by hand and drifted: it still offered "Invalid event timezone.", which the plugin no longer shows, and was missing "Event dates are not set.", which replaced it

### Changed
- The speaker detail page carried its own copy of the TTL check, transient read, miss path and write that `cfp_dev_cached_markup()` already performs for every other page. Same behaviour, twenty-five fewer lines, and now shared with the talk page it mirrors
- `cfp_dev_get_json_offline()` is gone. Offline reads go through `cfp_dev_read_snapshot_body()`, so the function had no caller in the plugin — only the two tests written for it
- The extra `/talk/` rewrite alias for prefixed installs is kept, but the file now records what is known about it: the analysis says it is redundant, it has not been checked against the deployment it was written for, and the duplicate URLs it creates are resolved by the canonical

### Added
- `composer i18n` regenerates the translation template with the flags that keep its bug-report URL pointing at this repository, and the suite now fails when template and source disagree — which is what found the drift above
- The README documents the plugin's whole filter surface. Three of its four filters were undocumented, including `cfp_dev_video_embed_hosts`, which decides what may be framed on a talk page

### Tests
- 263 → 356. The settings screen had no test at all and now covers the cache tables, the empty case, an unreachable API, records missing the fields it reads, the strings both admin scripts are handed, and the status they are started from. New coverage for snapshot publication and retention, crawl recovery and collision, the 404-versus-503 decision, the schedule grid's bounds and durations, canonical URLs in both permalink modes, repeated element ids and the heading outline of every shortcode page, the talk timing contract a theme depends on, the speaker page's caching promises, search timeouts, and every cache key uninstall has to reach
- **Tests no longer decide each other's results.** Hooks are global and nothing removed the ones a test registered, so a filter added to prove one behaviour went on changing every test that ran after it — the suite passed only because of the order it ran in, and three tests fail when told to shuffle. A test's hooks are now dropped between tests, the plugin's are kept, and execution order is random by default so this stays true
- Uninstall was checked against three hand-written cache keys out of the fourteen shapes the plugin produces; the keys now come from the plugin's own key functions
- The stylesheet is checked for colours hardcoded for one theme and for webfonts that hide their text while loading
- The test asserting that a failing endpoint should still finish a crawl as "done" was pinning that defect in place, and now asserts the opposite

### CI
- The three scripts the plugin ships were never checked by anything, while PHP got a syntax pass on two versions plus PHPCS. A typo in the admin bundle would have reached a release as a settings screen whose buttons silently do nothing; `node --check` now runs on every push

---

## [4.6.0] — 2026-08-14

### Security
- **Stored XSS through JSON-LD.** Structured data was encoded with `JSON_UNESCAPED_SLASHES` and written straight into a `<script type="application/ld+json">` block. The HTML parser does not know it is looking at JSON, so a `</script>` anywhere in a speaker name, talk title, company or track description closed the element and everything after it was parsed as markup — on every public talk and speaker page, from data the plugin does not control. The payload is now hex-escaped (`JSON_HEX_TAG` and friends), which neutralises the breakout while still round-tripping the original text
- **Attacker-named files written into `wp-content/uploads`.** The offline crawler derived each downloaded image's filename from the URL's own extension, admitted by the pattern `/^[a-z]{2,4}$/` — which accepts `php`. A hostile or compromised CFP.DEV instance could therefore place a response body of its choosing at a predictable uploads path under a name the web server may execute. Extensions now come from an allow-list, and the served `Content-Type` must be a real image type or the download is rejected
- **Server-side request forgery in the crawler.** Image URLs came from the upstream API and went to `wp_remote_get()` unchecked, so a crawl could be pointed at loopback, link-local or private-range addresses and the response published under a public uploads URL. Downloads now use `wp_safe_remote_get()`, reject non-HTTP(S) schemes, and cap the response size
- **Path traversal on snapshot writes.** Snapshot *reads* were containment-checked; writes were not. Every id interpolated into a crawl path comes from the API, so traversal segments could steer a response body outside the snapshot directory. Paths are now normalised and verified to stay inside `{snapshot}/api/`, and every API-supplied id is narrowed to a positive integer before use
- **Unauthenticated request amplification.** The public speaker-photo endpoint accepted any numeric id, and each new value cost an upstream request plus a fresh transient — unbounded, from anonymous visitors. Ids are now checked against the event's actual speaker list, which is itself cached. A nonce was rejected deliberately: the endpoint URL is embedded in page HTML that full-page caches serve to everyone, so a nonce would be stale or foreign
- **Offline snapshots were browsable.** Snapshots are written under `wp-content/uploads`, which is web-served, and carried no index or listing protection — on a host with directory indexing enabled the entire crawl was enumerable, including `manifest.json`, which records every URL fetched. Snapshot directories now get silence files and the snapshot root an `Options -Indexes` rule

### Fixed
- **A failed crawl no longer takes the site down.** With no CFP.DEV key, an unreachable API or a full disk, the crawler still wrote `manifest.json` and switched offline mode on — and the front end then served that empty snapshot as the site's content. A crawl that captures no talks and no speakers now stops, leaves offline mode off, and reports why. Failed writes are detected instead of being silently ignored
- **The settings screen could hang for a minute.** It fetches the full speaker and talk lists purely to show which caches exist, each with a 30 second timeout, so an unreachable API blocked the page for up to 60 seconds before rendering anything. Admin-side requests now use a 10 second timeout, and the value is filterable through `cfp_dev_api_timeout`
- **Deactivating the plugin left work scheduled.** There was no deactivation hook, so a pending `cfp_dev_do_crawl` cron event and the plugin's rewrite rules outlived deactivation. Both are now cleaned up; settings, caches and snapshots are deliberately kept, since deactivation is not uninstallation
- **Uninstalling a multisite network only cleaned one site.** Options and transients are per-site, so every other site kept its rows. Cleanup now runs for each site in the network, and also unschedules the crawl event
- `CFP_DEV_DIR` and `CFP_DEV_URL` were assembled from `WP_PLUGIN_DIR` and the plugin folder name; they are now derived from the plugin file itself, so a symlinked or renamed plugin directory resolves correctly

### Accessibility
- **The light/dark toggle was unreachable by keyboard.** It was an `<a>` with no `href`, which is neither focusable nor exposed with a role. It is now a `<button>` with `aria-pressed` reflecting the active theme, plus a visible focus ring. The stylesheet's `.cfp-active` rules were dead — nothing ever set that class — and have been replaced by state driven from `aria-pressed`
- Icon-only social links announced as just "link", because the icons are CSS backgrounds and the elements had no text. Each now carries an `aria-label` naming the network and the speaker, and the containing `<nav>` is labelled
- The programme search field had only a placeholder, which is not an accessible name. It now has a real (visually hidden) `<label>`, and the form is marked `role="search"`
- `autofocus` is gone from the search field. It fired on every page rendering a shortcode, scrolling past the content the visitor asked for and disorienting screen-reader users

### Added
- **The plugin is actually translatable.** It declared a text domain but shipped only two translatable strings; roughly 180 user-facing strings across the settings screen, the shortcodes, the crawler status UI and the SEO metadata were hardcoded English. All are now wrapped with the correct escaping for their context, sentences use `sprintf()` placeholders with translator comments instead of concatenated fragments, and `languages/cfp-dev-shortcodes.pot` ships with 160 entries. The header gained the missing `Domain Path`
- `SECURITY.md` with a private disclosure route, and `CODE_OF_CONDUCT.md`
- `.editorconfig` matching the repository's existing conventions

### Changed
- **The 2,089-line main file is now 202 lines.** Its contents moved into eight focused modules under `shortcode/include/` — `helpers`, `settings`, `cache`, `api-client`, `ui`, `rewrite`, `admin` and `seo`. The main file now holds the plugin header, constants, the module list and asset loading. Lifecycle hooks are registered through a new `CFP_DEV_PLUGIN_FILE` constant, because a hook registered from a subdirectory file silently never fires
- Minimum PHP is now 8.1, matching what CI can actually test — PHPUnit 10 requires 8.1, so the previously declared 8.0 floor only ever received a syntax check
- `composer.lock` is committed so CI resolves the same toolchain on every run; it is excluded from the release ZIP
- Local variables are snake_case throughout, and the blanket PHPCS suppressions for `StrictComparisons`, `VariableNotSnakeCase` and `CommentedOutCode` are gone. Two of the three turned out to be suppressing nothing at all

### CI
- **Releases now run lint and tests before publishing.** Tag pushes did not match the workflows' `branches` filter, so a tagged commit shipped without either ever running. The release workflow now gates on `composer check` and verifies the tag is an ancestor of `main`
- The release ZIP is built with `git archive`, which honours the `export-ignore` rules already declared in `.gitattributes` — the previous rsync build leaked `.gitattributes` and `.gitignore` into every release. The artifact is verified for correct structure, absence of dev files and presence of the runtime files, and ships with a SHA-256 checksum and a build provenance attestation
- Dependabot auto-merge no longer accepts major version bumps, and no longer treats an empty check list as success — it previously could merge before CI had started
- Test and lint matrices start at PHP 8.1; workflows gained concurrency groups and Composer download caching

### Tests
- 182 → 232. New coverage for the SSRF and content-type guards, path-traversal rejection, the empty-crawl abort, snapshot listing protection, HTTP timeout contracts, asset registration details, uninstall behaviour including the multisite path, lifecycle hooks being bound to the main plugin file, and the release-packaging contract encoded in `.gitattributes`
- The WordPress stand-in was strengthened where it was hiding bugs: `wp_clear_scheduled_hook()` was a no-op returning `0`, `wp_remote_get()` discarded its arguments, and the enqueue functions discarded source, dependencies and version — so the deactivation, HTTP hardening and asset-versioning defects were undetectable by construction. It now models request arguments, response headers, `wp_safe_remote_get()`'s private-address rejection, scheduled-event removal, asset registration and a minimal `$wpdb`
- A test that asserted `.php` was a valid downloaded-image extension has been corrected — it was pinning the vulnerability in place
- `composer coverage` added, and CI reports coverage on one PHP version (no threshold enforced until a baseline exists)
- Activation was entirely untested despite being what makes a fresh install work at all: page creation, its idempotency on reactivation, and the slug rewrite rules (including the subdirectory path prefix) are now covered

---

## [4.5.0] — 2026-08-14

### Security
- **Cache poisoning in the public photo endpoint**: `get_speaker_photos` took the speaker's display name from the query string, rendered it into the gallery's `alt` text, and cached that markup keyed by speaker id alone — so any visitor could choose the alt text every later visitor of that speaker's page received. The name is now resolved from the API and the parameter is gone from the URL the detail page builds (which also fixes a double-encoding bug that rendered "Jane Doe" as `Jane%2520Doe`)
- **Unauthenticated request amplification**: the same endpoint only cached successful lookups, so with Cache Duration set to *No Cache* every anonymous request re-queried the album endpoint — twice, because of the built-in retry. Empty results are now cached for at least five minutes regardless of the setting, while real galleries keep honouring it
- **CSS injection through API image URLs**: speaker photos and track images are rendered as inline `background-image: url(...)` with the URL unquoted. `esc_url()` preserves `(`, `)` and `;`, so a URL ending in `);background:red;` closed the `url()` token and appended its own declarations. All eleven sites now quote the value
- **Unvalidated settings**: every select and text field was stored verbatim. Choice fields (Default Theme, Permalinks with Id, Show Rooms) are now checked against an allow-list, and the URL Path Prefix — which is interpolated into rewrite-rule *regular expressions*, where a `.` or `(` silently changes which URLs match — is reduced to slash-separated slugs

### Fixed
- **Unbalanced markup broke the surrounding page.** `[cfp_schedule]` opened `.cfp-area`, `.cfp-scroll` and `.cfp-scope` and never closed them, and `[cfp_search_results]` without a `?query=` emitted three closing tags with nothing to close — which closed the *theme's* wrappers instead
- `/search-results/` without a query now renders the normal page shell with the search form instead of a bare "No search query provided" line, so it is a usable landing page
- **The schedule's time ruler was drawn in the wrong timezone and on the wrong day**: it was anchored to today at midnight UTC and formatted in the *site's* timezone, while the grid's hours came from the *event's* timezone. On any site not running on UTC every label was offset — a Brussels conference viewed from a New York site started its ruler at 05:00 instead of 09:00 — and the `datetime` attribute always claimed today's date regardless of which conference day was open
- The schedule day tabs dropped the closing day when the event ended earlier in the day than it started, because both ends were compared as raw timestamps rather than calendar days
- Talks with no speakers, and talks/speakers missing a title, track, audience level or bio, produced `Undefined property` notices, `foreach` over null, and a `wp_kses_post(null)` deprecation that becomes a fatal error in PHP 9
- Saving settings only invalidated rendered HTML when the key, event name or cache duration changed — toggling Show Rooms or Permalinks with Id left stale markup in place until the cache expired
- Updating the plugin no longer serves the previous version's cached HTML: the plugin records the version it last ran as and invalidates cached markup when that changes
- `enable_theme_switch` was an unprefixed option name that any other plugin could also be using, and the uninstaller deleted it. It is now `cfp_dev_enable_theme_switch`, migrated transparently on first read
- Theme switching flashed the default theme on every page load before the stored preference was applied from the footer script; the preference is now applied before first paint

### Changed
- **All global functions and hooks are prefixed.** The plugin declared `getJSON()`, `getFooter()`, `getVideo()`, `getTime()`, `clearCache()`, `generate_slug()`, `get_speaker_by_id()` and ~45 more unprefixed functions — unconditionally, so any theme or plugin declaring the same name took the site down with a fatal error on load. The AJAX action `get_speaker_photos` had the same problem. Everything now carries the `cfp_dev_` prefix. **Shortcode tags are unchanged** — they live in user content
- The `[cfp_talks_by_tracks]` and `[cfp_talks_by_sessions]` talk tables were two drifted copies of the same markup; both now render through one shared module
- Plugin header declares `Requires at least: 6.0` and `Requires PHP: 8.0`, which WordPress checks before installing or updating
- Stylesheet renamed `cfp_dev_v4_4.css` → `cfp_dev_v4_5.css`, following the minor version

### Performance
- **Each API endpoint is fetched once per request.** A talk detail page fetched the same talk for the shortcode, the head metadata, the canonical URL and the JSON-LD; a speaker page fetched a talk per proposal; the settings screen re-fetched the full speaker and talk lists on every load. Each was a blocking request with a 30 second timeout
- Front-end assets (a ~5,000-line stylesheet plus a script) were enqueued on **every page of the site**. They now load only where a shortcode is actually used, with a `cfp_dev_enqueue_assets` filter for shortcodes rendered from widgets or template parts
- `site.js` no longer needs jQuery — rewritten in ~40 lines of plain DOM code
- No requests are made at all when no CFP.DEV key is configured, which matters right after activation
- YouTube embeds and gallery thumbnails load lazily

### Accessibility
- YouTube and Spotify embeds carry a `title` attribute; an untitled iframe is unlabelled for screen readers (WCAG 4.1.2)

### Added
- **A test suite.** 164 tests covering slug generation and round-tripping, the API client (success, HTTP error, malformed JSON, path-traversal rejection), offline snapshots (discovery, containment, pruning), the settings handler, the SEO layer, and end-to-end rendering of every shortcode — including HTML well-formedness, CSS-containment and prefix-hygiene assertions. It runs against a small in-memory WordPress stand-in, so it needs no WordPress install and finishes in well under a second
- CI runs the suite on PHP 8.1–8.4 and syntax-checks on 8.0 (the declared minimum) and 8.4
- The release workflow verifies the plugin header, `CFP_DEV_VERSION` and the CHANGELOG all agree with the tag, and fails if test or dev files leak into the ZIP

---

## [4.4.3] — 2026-08-08

### Fixed
- Slug URLs built by `cfp_dev_url()` (canonicals, `og:url`, sitemap entries, and every rendered `/talk/<slug>` / `/speaker/<slug>` link) lacked the trailing slash while WordPress' `redirect_canonical` 301s the un-slashed form to the slashed one. The canonical therefore pointed at a redirect back to the page itself — Search Console reported all talk/speaker detail pages as "Alternate page with proper canonical tag" and kept them out of the index, and every internal click paid a needless 301 hop. `cfp_dev_url()` now applies `user_trailingslashit()` (query/fragment URLs excluded), so URLs follow the site's permalink style
- Unresolvable talk/speaker detail requests (removed entities, legacy pre-4.3.4 accent slugs, bare `/talk/` or `/speaker/` without parameters) rendered "not found" text with HTTP 200 — Search Console flagged them as soft 404s. They now serve a real 404 via the theme's 404 template

---

## [4.4.2] — 2026-07-09

### Changed
- Stylesheet renamed `cfp_dev_v4_0_1.css` → `cfp_dev_v4_4.css`: the file is now versioned by minor release (major.minor, no patch), matching the current 4.4 line
- Documentation cleanup, no functional changes:
  - Consistent file headers across all PHP modules (`CFP.DEV shortcodes` + purpose + `@package`/`@since`), including the previously header-less speaker-details module and the offline crawler/uninstall files
  - PHPDoc blocks for every previously undocumented function (settings accessors, cache-key helpers, `getJSON()`/`searchJSON()`, AJAX handlers, slug/rewrite helpers, render functions)
  - File-purpose headers for the admin/front-end JS files; conventions header for the stylesheet (namespacing, page/view root classes, theming, `:is(main, div).cfp-main` rationale)
  - Log messages aligned to one `context: message` format (`getJSON:`, `searchJSON:`, `crawl:`, `offline:`, per-page prefixes) — details are appended after an em dash
  - README: settings reference table for all admin options (URL Path Prefix, Permalinks with Id, Show Rooms, …)
  - Comment cleanup: removed all commented-out CSS declarations (including one left-over empty rule), the `<!-- profile/session/search -->` markers emitted into page HTML, and restatement/end-marker comments (`// Get the rooms.`, `// End of cfp-group`, `} // End if().`, …) across PHP and JS — rationale ("why") comments are kept

### Fixed
- Typo in the admin settings UI: "worpdress" → "WordPress"
- README no longer advertises features that do not exist: "star ratings" on talk details and the schedule "current-time indicator" (the `.cfp-now` style is never applied by any code)

---

## [4.4.1] — 2026-07-09

### Fixed
- Text was unselectable on the ENTIRE site: `html.cfp-html` (the class every page carries on its root element) set `user-select: none`, disabling selection globally — only the handful of elements with an explicit `user-select: text` opt-in (speaker names, bios) could be copied. The root-level rule is removed; buttons/tabs keep their scoped opt-outs

---

## [4.4.0] — 2026-07-08

### Added
- Server-side SEO head metadata for all plugin pages: real `<title>` (via `pre_get_document_title`), meta description, Open Graph and Twitter tags for talk/speaker detail pages built from the actual talk/speaker data — replaces the generic page meta and the JS `document.title` hack
- Slug-aware `rel="canonical"` for talk/speaker detail pages (previously every talk canonicalized to the bare `/talk/` page)
- JSON-LD structured data: `Event` (with performer, start/end date, room) on talk pages and `Person` (with worksFor, image) on speaker pages
- Enriched meta descriptions for `talks-by-tracks` and `talks-by-sessions`: name the selected track/session type (`?id=N`) or list all track/session-type names (deduplicated — events can define several types with the same display name)
- `cfp_dev_page_meta()` public helper + `add_theme_support( 'cfp-dev-head-meta' )` opt-in: themes can render the tags themselves from plugin data to avoid duplicate meta tags
- XML sitemap provider: talk and speaker detail URLs now appear in `wp-sitemap.xml` (`wp-sitemap-cfp-1.xml`, slug mode + WP 5.5+ only) — previously they were invisible to crawlers
- `noindex,follow` on the search-results page via the `wp_robots` filter (internal search pages should not be indexed)
- Talk `og:image` skips tiny Google-cache thumbnails (`gstatic.com`) so pages fall back to the site's proper default social image
- All lookups go through the existing cached, offline-aware `getJSON()` helpers, so metadata keeps working from a local snapshot in offline mode; entity data is transient-cached so head meta adds no extra API round-trip
- Agentic browsing (WebMCP): the search form carries declarative WebMCP tool metadata — `toolname="search_conference_programme"` and `tooldescription` on the `<form>`, `toolparamdescription` on the query input — so agentic browsers and AI agents can discover and invoke the programme search as a structured tool without scraping

### Changed
- The plugin's page wrapper is now `<div class="cfp-main">` instead of `<main class="cfp-main">`: themes render their own `<main>` landmark around the content, so plugin pages ended up with nested duplicate `main` landmarks — invalid HTML and ambiguous for assistive technology, crawlers, and AI agents. All CSS selectors accept both via `:is(main, div).cfp-main`, so existing markup keeps styling correctly

### Removed
- In-body social meta tags (`embedSocialSpeakerCard`/`embedSocialTalkCard`) — meta tags now live in `<head>` where crawlers expect them
- `add_speaker_title_script()` JS title hack (title is now server-rendered)

---

## [4.3.4] — 2026-07-08

### Fixed
- `generate_slug()` turned non-ASCII characters into dashes (`Georg Šumailov` → `georg--umailov`), and WordPress' `sanitize_title()` on the lookup side collapses double dashes — speakers/talks with accented names could never be resolved in slug mode. Accents are now transliterated with `remove_accents()` (→ `georg-sumailov`) and duplicate dashes collapsed; ASCII slugs are unchanged
- Talk-page `og:url` now respects slug mode (`/talk/<slug>` instead of `/talk?id=…` when "Content by ID" is off)

---

## [4.3.3] — 2026-07-08

### Fixed
- Stale rendered-HTML cache after enabling offline mode: completing a crawl (and disabling offline mode, manually or via the missing-snapshot fallback) now bumps the cache version, so all shortcodes re-render against the new data source — previously cached HTML kept serving external image URLs
- `[cfp_speakers size="N"]` in offline mode rendered the full speaker list: the `?size=` query only exists on the live API, so the size limit is now also enforced locally after fetching
- A single missing snapshot file (unknown `?id=`, uncrawlable endpoint like `public/search`) silently disabled offline mode for the whole site — offline mode is now only abandoned when no completed snapshot exists at all
- Talk-page `og:url` pointed at `https://<key>.cfp.dev/talk?id=…` — it now points at the site's own talk permalink

---

## [4.3.2] — 2026-07-07

### Fixed
- Broken day-tab links on the schedule page: the tab hrefs were built as `.?id=Day`, which `esc_url()` mangled into the invalid absolute URL `http://./?id=Day`. The hrefs now use `?id=Day`, which stays relative to the current page

---

## [4.3.1] — 2026-07-06

### Fixed
- Stray divider line above the speaker profile: the social-card `<meta>` tags were emitted inside `<main>` before the first section, so the between-sections `border-top` rule (`section:not(:first-child)`) matched the profile. Meta tags now sit outside `<main>` on both detail pages, and the divider selectors use `:not(:first-of-type)` so non-section siblings can never trigger them

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
