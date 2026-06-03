<?php
/**
 * CFP.DEV shortcodes
 *
 * [cfp_talk_by_sessions]  List all talks grouped by session types
 *
 * @package     CFP.DEV
 * @since    1.0.0
 */
if (!function_exists('cfp_talks_by_sessions_shortcode')) {

    add_action('plugins_loaded', function () {

        if (!shortcode_exists('cfp_talks_by_sessions')) {
            // Add the shortcode.
            add_shortcode('cfp_talks_by_sessions', 'cfp_talks_by_sessions_shortcode');
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
     * Shortcode CFP talks by session types
     *
     * @return string
     * @since  1.0.0
     */
    function cfp_talks_by_sessions_shortcode() {
        $sessionId = get_query_var('id');

        if (CFP_DEV_CACHE == 0) {
            return get_talks_by_sessions($sessionId);
        } else {
            $_cache_group = 'talks_by_sessions_cache_group_' . $sessionId;
            if (false === ($cache = get_transient($_cache_group))) {
                $content = get_talks_by_sessions($sessionId);
                set_transient($_cache_group, $content, CFP_DEV_CACHE);
            } else {
                $content = $cache;
            }
        }
        return $content;
    }

    function get_talks_by_sessions($sessionId) {
        // The Session Types
        $sessions = getJSON('public/session-types');

        if (empty($sessionId)) {
            foreach ($sessions as $session) {
                if (!$session->pause) {
                    $sessionId = $session->id;
                    $sessionDescr = $session->description;
                    break;
                }
            }
        } else {
            foreach ($sessions as $session) {
                if ($session->id == $sessionId) {
                    $sessionDescr = $session->description;
                    break;
                }
            }
        }

        // Get talks by session type
        $talks = getJSON('public/talks/session-type/' . $sessionId);

        // ------------------------------------------------------------------------------------------------
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

        $content .= '<main class="cfp-main">';

        $content .= '<section class="cfp-list">';

        // ------------------------------------------------------------------------------------------------

        if (!empty($sessions)) {

            // array_filter($data, "nonBreakSessionsTypes");
            // usort($sessions, 'compareName');

            $content .= '<div class="cfp-subject">';
            $content .= '    <div class="cfp-primary">';
            $content .= '        <div class="cfp-name">Talks grouped by Session Types</div>';
            $content .= getSearchForm();
            $content .= '    </div>';
            $content .= '    <nav class="cfp-filter">';
            foreach ($sessions as $session) {

                if ($session->pause) {
                    continue;
                }

                if ($session->id == $sessionId) {
                    $isActive = 'cfp-active';
                } else {
                    $isActive = '';
                }

                $content .= '<a class="cfp-a ' . $isActive . '" href="?id=' . $session->id . '">';
                $content .= $session->name . '</a>';
            }
            $content .= '    </nav>';

        } else {
            $content .= '<div class="dev-cfp-row">';
            $content .= '    <div class="dev-cfp-column">';
            $content .= '        <p>No session types found</p>';
            $content .= '    </div>';
        }
        $content .= '</div>';

        $content .= '<div class="cfp-group">';
        $content .= '    <div class="cfp-foreword">';
        $content .= '       <div class="cfp-text">' . $sessionDescr . '</div>';
        $content .= '    </div>';

        // Table heading
        $content .= '    <div class="cfp-row cfp-headline">';
        $content .= '        <div class="cfp-field">Title</div>';
        $content .= '        <div class="cfp-field cfp-speaker">Speakers</div>';
        $content .= '        <div class="cfp-field">Track</div>';
        //             $content .= '        <div class="cfp-field">Time</div>';
        //             $content .= '        <div class="cfp-field">Room</div>';
        $content .= '        <div class="cfp-field"></div>';
        $content .= '    </div>';

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
            $content .= '        <div class="cfp-track" style="background-image: url(' . esc_url($talk->trackImageURL) . ')"></div>';
            $content .= '    </div>';
            //                 $content .= '    <div class="cfp-field">';
            //                 $content .= '        <div class="cfp-datetime">';
            // 				$content .= '            <time class="cfp-time" datetime="">NA</time>';
            // 				$content .= '            <time class="cfp-time" datetime="">NA</time>';
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

        $content .= '</div>';   // End of cfp-group

        $content .= '</section>';
        $content .= '</div>';    // End main

        $content .= getFooter();

        return $content;
    }
}
