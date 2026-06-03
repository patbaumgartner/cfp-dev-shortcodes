<?php
/**
 * CFP.DEV shortcodes
 *
 * [cfp_speakers]  List all CFP.DEV speakers.
 * [cfp_speaker_details]  List CFP.DEV speaker details.
 *
 * @package	 CFP.DEV
 * @since    1.0.0
 */

if ( ! function_exists( 'cfp_schedule_shortcode' ) ) {

    add_action( 'plugins_loaded', function() {

        if (!shortcode_exists('cfp_schedule')) {
            add_shortcode('cfp_schedule', 'cfp_schedule_shortcode');
        }
    });

    add_filter( 'query_vars', function( $vars ) {
        $vars[] = "id";
        return $vars;
    });

    add_action( 'wp_enqueue_scripts', function() {
        $plugin_url = plugin_dir_url( __FILE__ );

        wp_enqueue_style( 'style1', $plugin_url . CFP_DEV_CSS );
    });

    /**
     * Shortcode CFP Schedule
     *
     * @return string
     * @throws DateInvalidTimeZoneException
     * @throws DateMalformedStringException
     * @since  1.0.0
     */
    function cfp_schedule_shortcode( ) {
        $dayName = get_query_var('id');

        //----------------------------------------------------------------------------------------------------------------
        // Get the current event
        $currentEvent = getJSON('public/event');

        if (is_null($currentEvent)) {
            error_log('Failed to retrieve current event');
            echo 'Failed to retrieve current event';
            // Handle the error appropriately
            return 'Failed to retrieve current event';
        }

        error_log('currentEvent: ' . $currentEvent->id);
        error_log('currentEvent: ' . print_r($currentEvent, true));

        if (is_object($currentEvent) && !empty($currentEvent->timezone)) {
            try {
                $timeZone = new DateTimeZone($currentEvent->timezone);
                error_log('timeZone: ' . $timeZone->getName());
            } catch (Exception $e) {
                error_log('Error creating DateTimeZone: ' . $e->getMessage());
                // Handle the exception as needed
            }
        } else {
            error_log('Current event is not an array or timezone is not set');
            // Handle this situation as needed
        }

        //----------------------------------------------------------------------------------------------------------------
        // Get the rooms
        $rooms = getJSON('public/rooms');

        if (is_null($rooms)) {
            error_log('Failed to retrieve rooms');
            echo 'Failed to retrieve rooms';
            // Handle the error appropriately
            return 'Failed to retrieve rooms';
        }

        $timeZone = new DateTimeZone($currentEvent->timezone);
        $fromDate = new DateTime($currentEvent->fromDate);
        $fromDate->setTimezone($timeZone);

        if ($dayName === '') {
            $dayName = $fromDate->format("l");
        }

        //----------------------------------------------------------------------------------------------------------------
        // Get schedule items for day
        $day_schedule = getJSON('public/schedules/' . $dayName);

        if (is_null($day_schedule)) {
            error_log('Failed to retrieve day_schedule');
            echo 'Failed to retrieve day_schedule';
            // Handle the error appropriately
            return 'Failed to retrieve day_schedule';
        }

        $_cache_group = 'cfp_schedule_' . $dayName;

        if (CFP_DEV_CACHE == 0) {
            error_log('CFP_DEV_CACHE is disabled for speaker details');
            $content = generate_schedule_content($day_schedule, $rooms, $timeZone, $fromDate, $currentEvent, $dayName);
        } else {
            if (false === ($cache = get_transient($_cache_group))) {
                $content = generate_schedule_content($day_schedule, $rooms, $timeZone, $fromDate, $currentEvent, $dayName);
                set_transient($_cache_group, $content, CFP_DEV_CACHE);
            } else {
                $content = $cache;
            }
        }
        return $content;
    }

    /**
     * @throws DateMalformedStringException
     */
    function generate_schedule_content($day_schedule, $rooms, $timeZone, $fromDate, $currentEvent, $dayName) {
        //---------
        date_default_timezone_set($currentEvent->timezone);

        $toDate = new DateTime($currentEvent->toDate);
        $toDate->setTimezone($timeZone);

        $content = '<script>';
        $content .= 'const qs = document.querySelector(":root");';
        $content .= 'qs.classList.forEach(value => {';
        $content .= '   if (value.startsWith("cfp-")) {';
        $content .= '       qs.classList.remove(value);';
        $content .= '   }';
        $content .= '});';
        $content .= 'qs.classList.add("cfp-html");';
        $content .= 'qs.classList.add("cfp-theme:' . get_option('cfp_dev_default_theme', 'dark') .'");';
        $content .= 'qs.classList.add("cfp-page:schedule");';
        $content .= '</script>';

        $content .= '<main class="cfp-main">';

        if (!empty($rooms)) {

            // ----------------------------------------------------------------------------------------------------
            // Title
            $content .= '<section id="cfp-schedule" class="cfp-schedule cfp-general">';
            $content .= '    <div class="cfp-subject">';
            $content .= '        <div class="cfp-primary">';
            $content .= '            <div class="cfp-name">' . $currentEvent->name . '</div>';
            $content .= getSearchForm();
            $content .= '        </div>';

            // ----------------------------------------------------------------------------------------------------
            // Navigation bar
            $content .= '	<div class="cfp-secondary">';
            $content .= '		<nav class="cfp-tab">';
            while ($fromDate < $toDate) {
                if ($fromDate->format('l') === $dayName) {
                    $isActive = 'cfp-active';
                } else {
                    $isActive = '';
                }
                $content .= '		<a class="cfp-a ' . $isActive . '" href=".?id=' . $fromDate->format('l') . '">' .
                    $fromDate->format('l') . ' ' . $fromDate->format('j') . '<sup>' .
                    $fromDate->format('S') . '</sup> ' . $fromDate->format('M') . '</a>';
                $fromDate->modify('+1 day');
            }
            $content .= '		</nav>';
            $content .= '		<a class="cfp-button" style="color:white" href="https://mobile.devoxx.com/events/' . CFP_DEV_KEY . '/schedule">Mobile Schedule</a>';
            $content .= '	</div>';

            $content .= '</div>'; // End of cfp-subject

            // ----------------------------------------------------------------------------------------------------
            // Count total timeslot elements
            $count = 0;
            foreach ($day_schedule as $item) {
                $count++;
            }

            // ----------------------------------------------------------------------------------------------------
            // Calc start & end time (in hours) of event

            $hour_start = getTime($day_schedule[0]->fromDate, $timeZone, 'H');
            $hour_finish = getTime($day_schedule[$count - 1]->toDate, $timeZone, 'H');
            $content .= '<div class="cfp-area" style="--hour-start:' . $hour_start . '; --hour-finish:' . $hour_finish . ';">';

            $content .= '<div class="cfp-scroll">';

            // ----------------------------------------------------------------------------------------------------
            // Calc the horizontal now bar

            $time_now = time();
            $time_day = strtotime(date('Y-m-d 00:00:00', $time_now));
            $time_unit = (60 * 60);
            $time_start = ($hour_start * $time_unit);
            $time_finish = ($hour_finish * $time_unit);

    //                $hour = ($hour_finish - $hour_start);
    //                $time = explode(':', date('H:i', (($time_now - $time_day) - $time_start) - $time_unit));
    //                $offset = ($time[0] + ($time[1] / 60));

            // TODO Because this is cached it needs to be updated using Javascript
            //            $content .= '	<div class="cfp-now" style="--hour:' . $hour . '; --offset:' . $offset .'"></div>';

            $content .= '	<div class="cfp-scope">';

            // ----------------------------------------------------------------------------------------------------
            // Show the time in the left column
            $content .= '		<div class="cfp-column cfp-datetime">';

            for ($a = $time_start; $a <= $time_finish; $a += ($time_unit / 6)) {
                $time = ($time_day + $a);
                $content .= '<time class="cfp-time" datetime="' . date('c', $time) . '">' . date('H:i', $time) . '</time>';
            }

            $content .= '		</div>';

            // ----------------------------------------------------------------------------------------------------
            // Group all talks per room because schedule logic expects them sequentially per room column
            foreach ($rooms as $room) {

                $schedule_items = getJSON('public/schedules/' . $dayName . '/' . $room->id);

                if (!empty($schedule_items)) {
                    $content .= '<div class="cfp-column cfp-event">';

                    foreach ($schedule_items as $item) {

                        if (!empty($item)) {

                            $startSession = new DateTime($item->fromDate);
                            $startSession->setTimezone($timeZone);

                            $endSession = new DateTime($item->toDate);
                            $endSession->setTimezone($timeZone);

                            $event_start = $startSession->format('H:i');
                            $event_finish = $endSession->format('H:i');
                            // $event_duration = (strtotime(sprintf('00:%s', $event_finish)) - strtotime(sprintf('00:%s', $event_start)));

                            $hasProposal = false;
                            $overflow = $item->overflow;

                            if (!empty($item->proposal) &&
                                isset($item->proposal->title) &&
                                !empty($item->proposal->title) &&
                                !$overflow) {
                                $hasProposal = true;
                                $sessionType = 'cfp-session';
                            } else {
                                $sessionType = 'cfp-recess';
                            }

                            $content .= '<article class="cfp-article ' . $sessionType . '" data-event-start="' . $startSession->format('H:i') . '" data-event-finish="' . $endSession->format('H:i') . '" data-event-duration="' . $item->sessionType->duration . '">';

                            if ($hasProposal && !$overflow) {
                                if (get_option('cfp_dev_content_by_id', 'yes') == 'no') {
                                    $talk_slug = generate_slug($item->proposal->title);
                                    $content .= '        <a class="cfp-a" href="' . cfp_dev_url('/talk/' . $talk_slug) . '">';
                                } else {
                                    $content .= '        <a class="cfp-a" href="' . cfp_dev_url('/talk?id=' . $item->proposal->id) . '">';
                                }
                            }

                            $content .= '            <div class="cfp-content">';
                            $content .= '                <div class="cfp-meta">';

                            if ($hasProposal && !$overflow) {
                                if ($item->proposal->totalFavourites > 0) {
                                    $content .= '        <div id="dev-cfp-talk-' . $item->proposal->id . '" class="cfp-favourite">' . $item->proposal->totalFavourites . '</div>';
                                }
    //                                    else {
    //                                        $content .= '        <div id="dev-cfp-talk-' . $item->proposal->id . '" class="cfp-favourite">0</div>';
    //                                    }
                                $content .= '        <div class="cfp-track" style="background-image: url(' . $item->proposal->track->imageURL . ');filter: grayscale(100%);"></div>';
                            }

                            $content .= '                </div>';
                            if ($hasProposal && get_option('cfp_dev_show_rooms', 'yes') == 'yes') {
                                $content .= '                <div class="cfp-room">' . $item->room->name . '</div>';
                            }
                            $content .= '                <div class="cfp-name">' . $item->sessionType->name . '</div>';
                            $content .= '                <div class="cfp-datetime">';

                            $time = date('c', strtotime(sprintf('%s %s', date('Y-m-d', $time_now), $event_start)));
                            $content .= '                    <time class="cfp-time" datetime="' . $time . '">' . $event_start . '</time>';
                            $time = date('c', strtotime(sprintf('%s %s', date('Y-m-d', $time_now), $event_finish)));
                            $content .= '                    <time class="cfp-time" datetime="' . $time . '">' . $event_finish . '</time>';
                            $content .= '                </div>';
                            if ($hasProposal) {
                                $content .= '                <div class="cfp-name">' . $item->proposal->title . '</div>';
                            }
                            if ($overflow) {
                                $content .= '                <div class="cfp-name">OVERFLOW</div>';
                            }

                            if ($item->proposal && $item->proposal->speakers && (is_array($item->proposal->speakers) || is_object($item->proposal->speakers))) {
                                foreach( $item->proposal->speakers as $speaker ) {
                                    $fullName = $speaker->firstName . ' ' .$speaker->lastName;
                                    $content .= '<div class="cfp-speaker">' . $fullName . '</div>';
                                }
                            }

                            $content .= '            </div>';
                            if ($hasProposal) {
                                $content .= '        </a>';
                            }
                            $content .= '</article>';
                        }
                    }

                    $content .= '</div>';    // end of column
                }
            }

            $content .= '</section>';

            $content .= getFooter();

        }

        $content .= '</main>';
        return $content;
    }
}
