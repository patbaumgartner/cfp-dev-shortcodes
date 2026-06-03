=== CFP.DEV shortcodes ===
Contributors: sjadevoxx
Donate link: https://gitlab.com/voxxed/cfp.dev/wikis/Wordpress-Plugin
Tags: CFP, Speakers, Schedule, Devoxx, VoxxedDays
Requires at least: 4.6
Tested up to: 6.1.0
Stable tag: 4.3
Requires PHP: 5.2.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The CFP.DEV shortcodes plugin provides several short codes to list speakers, talks and much more from your CFP.DEV server.
Version 3.0 uses the new PWA mobile app available @ https://mobile.devoxx.com

== Description ==

When you have a CFP.DEV instance running you might want to show a list of speakers on your Wordpress instance.
This plugin provides several short codes to list speakers, show speaker details, the schedule per day and talk details and search results.

First thing you need to provide is the CFP.DEV key, which is the subdomain of your CFP.DEV instance (for example dvbe23).
Go to the CFP.DEV admin settings page and enter the key.

The available short codes are :
* [cfp_speakers size=10 random=yes title="Speakers" subtitle="This list will grow" hide_search=true hide_footer_true]     list of speakers
* [cfp_speaker_details]        Speaker details page
* [cfp_talk_details]           talk details page
* [cfp_schedule day=yyyyyyy]   yyyyy is the day name, for example monday.
* [cfp_talks_by_tracks all=true]        list all the talks when all=true is set and query param id is not set
* [cfp_talks_by_tracks]        List all the talks by the track id
* [cfp_talks_by_sessions]      list all the talks by session types (conference, bof, etc.)
* [cfp_search_results]         Shows the search results which can include speakers and talks.  This page is triggered by the search request on the schedule page.

All rendered CFP.DEV Wordpress pages are cached, you can specific the cache duration in the CFP.DEV settings page.
You can manually clear the cache on the Wordpress CFP.DEV settings page if you want to force a refresh of the cache.

== Installation ==

This section describes how to install the plugin and get it working.

e.g.

1. Upload the plugin files to the `/wp-content/plugins/plugin-name` directory, or install the plugin through the WordPress plugins screen directly.

2. Activate the plugin through the 'Plugins' screen in WordPress

3. Provide the CFP.DEV key on the Wordpress CFP.DEV Settings page.

4. You can now add the speakers list short code to a Wordpress page as follows [cfp_speakers]

4.1 The speaker list is ordered by speaker last name, you can also decide to show a random list by adding the short code parameter random=true, as follows [cfp_speakers random=true]
4.2 You can also specify how many speakers you want to show on the page by using the size param. For example [cfp_speakers size=20 random=true] will show 20 random speakers.
4.3 You can also add a title and subtitle above the list of speakers by using the related params: title and subtitle

5. To display speaker details you need to create a Wordpress page named 'speaker_details' and add the short code [cfp_speaker_details]

6. To display a schedule you can use the short code [cfp_schedule day=monday], this will show the schedule for Monday.

7. To display a talk details a Wordpress page named 'talk_details' must exist.  In this page you need to add the short code [cfp_talk_details]

8. To show search results a Wordpress page named 'search_results' must exist.

=== Recommended pages structure ===

The plugin will create AUTOMATICALLY the following required pages when the plugin is activated.
Each page will include the required shortcode.

* `/schedule` uses `[cfp_schedule]`
* `/speakers` uses `[cfp_speakers]`
* `/speaker` uses `[cfp_speaker_details]`
* `/talks-by-tracks` uses `[cfp_talks_by_tracks]`
* `/talks-by-sessions` uses `[cfp_talks_by_sessions]`
* `/search-results` uses `[cfp_search_results]`
* `/talk uses` uses `[cfp_talk_details]`

All the above pages must have no parent page!

== Frequently Asked Questions ==

= Can I use this plugin without having a CFP.DEV instance? =

No, you need to have a CFP.DEV instance running.

= Can I change the look and feel? =

Yes, you can override the used cfp-dev CSS properties to override the look and feel.

== Screenshots ==

1. Screenshot of [the speakers list](https://gitlab.com/voxxed/cfp.dev/wikis/uploads/4134002f50cf28c7abbc60915abf74f7/image.png)

2. Screenshot of the [speaker details page](https://gitlab.com/voxxed/cfp.dev/wikis/uploads/070328301772e63aa363f128e98b08af/image.png)

== Changelog ==

= 3.6.2 =

- (09 June 2025). Use CSS Uppercase for speaker names on the agenda page

= 3.6.1 =

- (15 March 2025). Enforce transparent background for text

= 3.6.0 =
- (9 March 2025). Add configuration setting to show/hide rooms + Add support for subfolders + Fix some broken links

= 3.5.1 =
- (9 Feb 2025). Fix list speakers name on talks by track/session

= 3.5.0 =
- (18 Nov 2024). Added Bluesky and Mastodon to speaker profile

= 3.4.4 =
- (2 Oct 2024). Introduced permalink options using slug or id

= 3.4.3 =
- (27 Sep 2024). Removed home_url() which used sometimes IP instead of domain name

= 3.4.2 =
- (21 Sep 2024). Support for Spotify podcast embeds

= 3.4.1 =
- (19 Sep 2024). Slug caching + Speaker photos ALT text

= 3.4.0 =
- (15 Sep 2024). Support for talk and speaker slugs in the URL + Cleanup of unused methods

= 3.3.6 =
- (14 Aug 2024). Keywords are now shown on the talk details page + Get speaker photos fix using more robust getJSON method

= 3.3.5 =
- (12 Aug 2024). Show speaker photos loading label + Increased read timeout + Update "Delete Cache" button when pressed

= 3.3.4 =
- (12 Aug 2024). Improved caching logic + admin view for cache management

= 3.3.3 =
- (7 Aug 2024) Speaker images are retrieved async to speed up the page load and cached.
- (13 May 2024) Removed strip_tags for Speaker bio, so we can have links in the bio and HTML rendering

= 3.3.1 =
- (7 Mar 2024). Added array check before forEach.  Increase speakers size to 400

= 3.3.0 =
- (19 Jan 2024). Use curl in getJSON() with keep-alive

= 3.2.0 =

- (14 Nov 2023). Added 'hide_search' param for the speakers shortcode

= 3.1.1 =
- (10 Oct 2023). Show room name

= 3.1.0 =
- (5 Sep 2023). Default theme can now be defined by Admin

= 3.0.0 =
- (5 Sep 2023). Support the new mobile app.  Removed MySchedule and Home page shortcodes.  Dark theme is now default.

= 2.5.0 =
- (1 Aug 2023). Support light / dark theme option

= 2.4.1 =
- (8 April 2023).  Div not properly closed for similar talks

= 2.4.0 =
- (27 March 2023). Added support for GPT generated summaries on YouTube transcripts with help of Devoxx Insights

= 2.3.1 =
- (25 March 2023). Fix for clear cache of talks

= 2.3.0 =
- (15 March 2023). Show all talks for talks_by_tracks when attribute 'all' is set to true

= 2.2.3 =
- (6 March 2023). Schedule link fix using relative paths

= 2.2.2 =
- (4 March 2023). Show event days on the "overview" home page

= 2.2.1 =
- (3 March 2023). The Register button on MySchedule now uses a relative path which was a problem for some VoxxedDays websites.

= 2.2.0 =
- (2 March 2023). Support cache selection for CFP.DEV pages

= 2.1.11 =
- (1 March 2023). Fixed clear cache URL issue

= 2.1.10 =

- (28 Feb 2023). Check if proposal has speakers
- (27 Feb 2023). CSS svg image URL fix + Relative URL fix
- (26 Feb 2023). CSS and cache fix
- (Jan 2023). Similarity search, show similar talks and related books
- (25 July 2022). Brand new design
- (30 May 2022). Corrected the schedule tag search href.
- (22 May 2022). Centralize the CFP.DEV REST URL. Clear cach also includes the talks and speaker pages.
- (17 May 2022). Include session type name and track logo in search results.

= 2.1.3 =
* New design for all pages, including similarity search & talks, books etc.

= 1.5.4 =
* Support proposal ratings

= 1.5.2 =
* Social card fix for speaker details page

= 1.2.61 =
* Corrected error handling for wp_remote_get

= 1.2.48 =
* Corrected documentation and cache issue

= 1.2.47 =
* Include speaker-img-[index] for each Flickr image of speaker

= 1.2.46 =
* Embed YouTube video when viewing speaker details page

= 1.2.45 =
* Embed YouTube video when available

= 1.2.41 =
* Show mobile app links in footer

= 1.2.40 =
* Removed 'Error' check which blocks talks with error in their talk description

= 1.2.39 =
* Clear cache manually for speaker or talk details page

= 1.2.38 =
* Use thumbnail flickr images for overview with link to high-res version

= 1.2.37 =
* Show Flickr speaker images

= 1.2.36 =
* Show total favs on schedule and talk details page

= 1.2.35 =
* Added CSS media queries to make grid responsive

= 1.2.34 =
* Fix: My Schedule remove and link

= 1.2.32 =
* Increased REST timeouts from 5 (default) to 30 seconds

= 1.2.30 =
* My Schedule link uses /talk instead of talk (this will break for the voxxed days events - for now)

= 1.2.29 =
* Search HTTP GET timeout of 30 seconds added

= 1.2.28 =
* CSS updates and show session type name on schedule

= 1.2.27 =
* Show time slot details on talk when available

= 1.2.26 =
* Introduced search results shortcode

= 1.2.25 =
* Introduced my schedule shortcode

= 1.2.22 =
* Include link to speaker in talk lists

= 1.2.21 =
* Show message when key parameter is not provided

= 1.2.20 =
* Support URL suffix

= 1.2.19 =
* Cache items are now valid for 24 hours

= 1.2.7 =
* CSS enhancements

= 1.2.5 =
* Added tags to talk abstracts

= 1.2.4 =
* List talks by tracks and session types.

= 1.2 =
* All CFP.DEV rendered wordpress pages are now cached in Wordpress for one hour.

= 1.1 =
* Added two new shortcodes to display the schedule and proposal details page.

= 1.0 =
* First version of CFP.DEV shortcodes to list speakers and speaker details

== Upgrade Notice ==
* Not applicable
