<?php
/**
 * CFP.DEV shortcodes
 *
 * [cfp_talk_by_sessions]  List all talks grouped by session types
 *
 * @package     CFP.DEV
 * @since    1.0.0
 */
if (!function_exists('cfp_talks_by_tracks_shortcode')) {

    add_action('plugins_loaded', function () {

        if (!shortcode_exists('cfp_talks_by_tracks')) {
            // Add the shortcode.
            add_shortcode('cfp_talks_by_tracks', 'cfp_talks_by_tracks_shortcode');
        }
    });

    add_filter('query_vars', function ($vars) {
        $vars[] = 'id';
        return $vars;
    });

    add_action('wp_enqueue_scripts', function () {
        $plugin_url = plugin_dir_url(__FILE__);
        wp_enqueue_style('style1', $plugin_url . CFP_DEV_CSS);
    });

    /**
     * Shortcode CFP talks by tracks
     * @return string
     * @since  1.0.0
     */
    function cfp_talks_by_tracks_shortcode($atts) {
        $trackId = get_query_var('id');

        if (CFP_DEV_CACHE == 0) {
            return cfp_get_talks_by_tracks($trackId, $atts);
        } else {
            $cacheGroup = 'talks_by_tracks_cache_group_' . $trackId;
            if (false === ($cache = get_transient($cacheGroup))) {
                $content = cfp_get_talks_by_tracks($trackId, $atts);
                set_transient($cacheGroup, $content, CFP_DEV_CACHE);
            } else {
                $content = $cache;
            }
        }
        return $content;
    }

    function cfp_get_talks_by_tracks($trackId, $atts) {
        // Get the Tracks
         $tracks = getJSON('public/tracks');

        // Track id was not given
        if (empty($trackId)) {
            $booleanDefault = false;

            // Save $atts.
            $_atts = shortcode_atts( array(
                'all'  => $booleanDefault
            ), $atts );

            $showAll = $_atts['all'];

            if ($showAll) {
                $trackId = -1;
            } else {
                // Take the first one from the list
                $trackId = $tracks[0]->id;
                $trackDescr = $tracks[0]->description;
            }
        } else {
            // Filter on track
            foreach ($tracks as $track) {
                if ($track->id == $trackId) {
                    $trackDescr = $track->description;
                    break;
                }
            }
        }

        if ($trackId == -1) {
            $talks = getJSON('public/talks');
        } else {
            $talks = getJSON('public/talks/track/' . $trackId);
        }

        // ------------------------------------------------------------------------------------------------

        $content = modifyCfpClasses();
        $content .= '<main class="cfp-main">';
        $content .= '<section class="cfp-list">';

        if (!empty($tracks)) {
            usort($tracks, 'compareName');
            $content .= displayTalksByTrack($tracks, $trackId);
        } else {
            $content .= displayNoTracksMessage();
        }

        $content .= '<div class="cfp-group">';
        $content .= '    <div class="cfp-foreword">';
        if (!empty($trackDescr)) {
            $content .= '       <div class="cfp-text">' . $trackDescr . '</div>';
        }
        $content .= '    </div>';

        $content .= generateTableHeading();
        $content .= generateTalkArticles($talks);

        $content .= '</div>';   // End of cfp-group
        $content .= '</section>';
        $content .= '</div>';    // End main

        $content .= getFooter();
        return $content;
    }

    /**
     * @return string
     */
    function modifyCfpClasses() {
        $content = '<script>';
        $content .= 'const qs = document.querySelector(":root");';
        $content .= 'qs.classList.forEach(value => {';
        $content .= '   if (value.startsWith("cfp-")) {';
        $content .= '       qs.classList.remove(value);';
        $content .= '   }';
        $content .= '});';
        $content .= 'qs.classList.add("cfp-html");';
        $content .= 'qs.classList.add("cfp-theme:' . get_option('cfp_dev_default_theme', 'dark') .'");';
        $content .= 'qs.classList.add("cfp-page:session");';
        $content .= '</script>';
        return $content;
    }

    /**
     * @return string
     */
    function generateTableHeading() {
        $content = '    <div class="cfp-row cfp-headline">';
        $content .= '        <div class="cfp-field">Title</div>';
        $content .= '        <div class="cfp-field cfp-speaker">Speakers</div>';
        $content .= '        <div class="cfp-field">Track</div>';
        //             $content .= '        <div class="cfp-field">Time</div>';
        //             $content .= '        <div class="cfp-field">Room</div>';
        $content .= '        <div class="cfp-field"></div>';
        $content .= '    </div>';
        return $content;
    }

    /**
     * @param $talks
     * @return mixed|string
     */
    function generateTalkArticles($talks) {
        $content = '';
        foreach ($talks as $talk) {
            $content .= '<article class="cfp-article cfp-row cfp-event">';
            $content .= '    <div class="cfp-field">' . $talk->title . '</div>';
            $content .= '    <div class="cfp-field cfp-speaker">';
            foreach ($talk->speakers as $speaker) {
                if (get_option('cfp_dev_content_by_id', 'yes') == 'no') {
                    $speaker_slug = generate_slug($speaker->firstName . '-' . $speaker->lastName);
                    $content .= '<a class="cfp-a" href="' . cfp_dev_url("/speaker/{$speaker_slug}") . '">' . $speaker->firstName . '&nbsp;' . $speaker->lastName . '</a>';
                } else {
                    $content .= '<a class="cfp-a" href="' . cfp_dev_url("/speaker?id=$speaker->id") . '">' . $speaker->firstName . '&nbsp;' . $speaker->lastName . '</a>';
                }
            }
            $content .= '    </div>';
            $content .= '    <div class="cfp-field">';
            if (empty($talk->trackImageURL)) {
                $trackImageURL = $talk->track->imageURL;
            } else {
                $trackImageURL = $talk->trackImageURL;
            }
            $content .= '        <div class="cfp-track" style="background-image: url(' . $trackImageURL . ')"></div>';
            $content .= '    </div>';
            //                 $content .= '    <div class="cfp-field">';
            //                 $content .= '        <div class="cfp-datetime">';
            // 				   $content .= '            <time class="cfp-time" datetime="">NA</time>';
            // 				   $content .= '            <time class="cfp-time" datetime="">NA</time>';
            //                 $content .= '        </div>';
            //                 $content .= '    </div>';
            //                 $content .= '    <div class="cfp-field">NA</div>';
            $content .= '    <div class="cfp-field">';
            if (get_option('cfp_dev_content_by_id', 'yes') == 'no') {
                $content .= '        <a class="cfp-a" href="' . cfp_dev_url('/talk/' . generate_slug($talk->title)) . '">View</a>';
            } else {
                $content .= '        <a class="cfp-a" href="' . cfp_dev_url('/talk?id=' . $talk->id) . '">View</a>';
            }
            $content .= '    </div>';
            $content .= '</article>';
        }
        return $content;
    }

    /**
     * @return string
     */
    function displayNoTracksMessage() {
        $content = '<div class="dev-cfp-row">';
        $content .= '    <div class="dev-cfp-column">';
        $content .= '        <p>No tracks found</p>';
        $content .= '    </div>';
        $content .= '</div>';
        return $content;
    }

    /**
     * @param $tracks
     * @param $trackId
     * @return string
     */
    function displayTalksByTrack($tracks, $trackId) {
        $content = '<div class="cfp-subject">';
        $content .= '    <div class="cfp-primary">';
        $content .= '        <div class="cfp-name">Talks grouped by Track</div>';
        $content .= getSearchForm();
        $content .= '    </div>';
        $content .= '    <nav class="cfp-filter">';
        foreach ($tracks as $track) {
            if ($track->id == $trackId) {
                $isActive = 'cfp-active';
            } else {
                $isActive = '';
            }
            $content .= '<a class="cfp-a ' . $isActive . '" href="?id=' . $track->id . '">';
            $content .= $track->name . '</a>';
        }
        $content .= '    </nav>';
        $content .= '</div>';
        return $content;
    }
} // End if().
