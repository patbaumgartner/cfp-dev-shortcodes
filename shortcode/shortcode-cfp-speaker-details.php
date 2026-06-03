<?php

if (!function_exists('cfp_speaker_details_shortcode')) {
    add_action('plugins_loaded', function () {
        if (!shortcode_exists('cfp_speaker_details')) {
            add_shortcode('cfp_speaker_details', 'cfp_speaker_details_shortcode');
        }
    });

    add_action('wp_enqueue_scripts', function () {
        $plugin_url = plugin_dir_url(__FILE__);
        wp_enqueue_style('style1', $plugin_url . CFP_DEV_CSS);
    });

    function cfp_speaker_details_shortcode() {
        $speaker_slug = get_query_var('speaker_slug');
        $speaker_id = get_query_var('id');

        if (!empty($speaker_slug)) {
            error_log('Get speaker by slug: ' . $speaker_slug);
            $speaker_id = get_speaker_id_from_slug($speaker_slug);
        } elseif (!empty($speaker_id)) {
            error_log('Get speaker by id: ' . $speaker_id);
            $speaker_info = get_speaker_by_id($speaker_id);
        } else {
            return 'Speaker not found.';
        }

        if (CFP_DEV_CACHE == 0) {
            error_log('CFP_DEV_CACHE is disabled for speaker details');
            if (empty($speaker_info)) {
                error_log('Fetching speaker details: ' . $speaker_id);
                $speaker_info = get_speaker_by_id($speaker_id);
            }
            error_log('Generating speaker page: ' . $speaker_id);
            if (!empty($speaker_info)) {
                $content = generateSpeakerPage($speaker_info);
            } else {
                $content = 'Speaker not found.';
            }
        } else {
            error_log('CFP_DEV_CACHE is enabled for speaker details: ' . $speaker_id);
            $speakerCacheKey = generate_cfp_cache_key('speaker', $speaker_id);

            // Is speaker available in cache?
            if (false === ($cache = get_transient($speakerCacheKey))) {
                error_log('Speaker not found in cache');
                // Nope, lets fetch it from the API
                if (empty($speaker_info)) {
                    $speaker_info = get_speaker_by_id($speaker_id);
                }
                $content = generateSpeakerPage($speaker_info);
                set_transient($speakerCacheKey, $content, CFP_DEV_CACHE);
            } else {
                error_log('Speaker found in cache');
                // Yes, return the cached content
                $content = $cache;
            }
        }

        return $content;
    }

    function generateSpeakerPage($speaker) {
        $content = '<script>';
        $content .= 'const qs = document.querySelector(":root");';
        $content .= 'qs.classList.forEach(value => {';
        $content .= '   if (value.startsWith("cfp-")) {';
        $content .= '       qs.classList.remove(value);';
        $content .= '   }';
        $content .= '});';
        $content .= 'qs.classList.add("cfp-html");';
        $content .= 'qs.classList.add("cfp-theme:' . get_option('cfp_dev_default_theme', 'dark') .'");';
        $content .= 'qs.classList.add("cfp-page:speaker");';
        $content .= 'qs.classList.add("cfp-view:detail");';
        $content .= '</script>';

        $content .= '<main class="cfp-main">';

        $content .= embedSocialSpeakerCard($speaker);
        $content .= generateSpeakerContent($speaker);

        // Placeholder for photo album with loading message
 		$content .= '<div id="speaker-photo-album">';
 		$content .= '    <div id="loading-container">';
 		$content .= '        <div id="loading-spinner">';
 		$content .= '    ' . file_get_contents(CFP_DEV_DIR . '/images/loading-spinner.svg');
 		$content .= '        </div>';
 		$content .= '        <p id="photo-loading-message">Searching for speaker images...</p>';
 		$content .= '    </div>';
 		$content .= '</div>';

        // JavaScript to load photo album asynchronously
 		$content .= '<script>
                     document.addEventListener("DOMContentLoaded", function() {
                         const photoAlbum = document.getElementById("speaker-photo-album");
                         const loadingMessage = document.getElementById("photo-loading-message");
                         const loadingSpinner = document.getElementById("loading-spinner");

                         loadingSpinner.style.display = "block";

                         fetch("' . admin_url('admin-ajax.php') .
                                            '?action=get_speaker_photos&speaker_id=' . $speaker->id .
                                            '&speaker_name=' . $speaker->firstName . ' ' . $speaker->lastName . '")
                             .then(response => response.text())
                             .then(data => {
                                 loadingSpinner.style.display = "none";
                                 loadingMessage.style.display = "none";

 								if (data.trim() === "") {
                                     photoAlbum.innerHTML = "<p>Couldn\'t find any photos</p>";
                                 } else {
                                     photoAlbum.innerHTML = data;
                                 }
                             })
                             .catch(error => {
                                 loadingMessage.style.display = "none";
                                 loadingSpinner.style.display = "none";
 								photoAlbum.innerHTML = "<p>Error loading speaker photos</p>";
                             });
                     });
                 </script>';

        $content .= '</main>';
        $content .= getFooter();
        return $content;
    }

    function generateSpeakerContent($speaker) {
        $content = '<!-- profile -->';
        $content .= '<section class="cfp-profile">';
        $content .= '    <div class="cfp-picture" style="background-image: url(' . esc_url($speaker->imageUrl) . ')"></div>';
        $content .= '    <div class="cfp-content">';
        $content .= '        <div class="cfp-detail">';
        $content .= '            <div class="cfp-name">' . esc_html($speaker->firstName . ' ' . $speaker->lastName) . '</div>';
        $content .= getSocialLinks($speaker);
        $content .= '        </div>';
        if (!empty($speaker->company)) {
            $content .= '        <div class="cfp-company cfp-company-left">' . esc_html($speaker->company) . '</div>';
        }
        $content .= '        <div class="cfp-text">';
        $content .= wp_kses_post($speaker->bio);
        $content .= '        </div>';
        $content .= '    </div>';
        $content .= '</section>';

        if (!empty($speaker->proposals)) {
            foreach ($speaker->proposals as $talk) {
                $content .= generateTalkContent($talk);
            }
        }

        return $content;
    }

    /**
     * @throws DateMalformedStringException
     * @throws DateInvalidTimeZoneException
     */
    function generateTalkContent($talk) {
        $content = '<!-- session -->';
        $content .= '<section class="cfp-session">';
        $content .= '    <div class="cfp-foreword">';
        $content .= '        <a class="cfp-a" href="' . cfp_dev_url('talks-by-tracks/?id=' . esc_attr($talk->track->id)) . '">';
        $content .= '            <div class="cfp-track" title="' . esc_attr($talk->track->name) . '" style="background-image: url(' . esc_url($talk->track->imageURL) . ')"></div>';
        $content .= '        </a>';
        if (get_option('cfp_dev_content_by_id', 'yes') == 'no') {
            $content .= '        <a class="cfp-a" href="' . cfp_dev_url('/talk/' . generate_slug($talk->title)) . '">View</a>';
        } else {
            $content .= '        <a class="cfp-a" href="' . cfp_dev_url('/talk?id=' . $talk->id) . '">View</a>';
        }
        $content .= '            <div class="cfp-name">' . esc_html($talk->title) . '</div>';
        $content .= '        </a>';
        $content .= '        <div class="cfp-type">';
        $content .= '            <a href="' . cfp_dev_url('/talks-by-sessions/?id=' . esc_attr($talk->sessionType->id)) . '">' . esc_html($talk->sessionType->name) . '</a> <em>(' . esc_html($talk->audienceLevel) . ' level)</em>';
        $content .= '        </div>';
        $content .= '        <input type="hidden" id="cfpTalkId" value="' . esc_attr($talk->id) . '">';

        $content .= generateTalkScheduleInfo($talk);
        $content .= getTalkKeywords($talk);

        $content .= '    </div>';
        $content .= '    <div class="cfp-content">';
        $content .= '        <div class="cfp-text">';
        $content .= wp_kses_post(cleanupDescription($talk->description));
        $content .= '        </div>';
        if (get_option('cfp_dev_content_by_id', 'yes') == 'no') {
            $content .= '        <a class="cfp-a" href="' . cfp_dev_url('/talk/' . generate_slug($talk->title)) . '">More</a>';
        } else {
            $content .= '        <a class="cfp-a" href="' . cfp_dev_url('/talk?id=' . $talk->id) . '">More</a>';
        }

        $content .= '    </div>';

        $content .= generateTalkVideo($talk);

        $content .= '</section>';

        return $content;
    }

    /**
     * @throws DateInvalidTimeZoneException
     * @throws DateMalformedStringException
     */
    function generateTalkScheduleInfo($talk) {
        $content = '';
        $talkDetails = getJSON('public/talks/' . $talk->id);
        if (count($talkDetails->timeSlots, COUNT_NORMAL) > 0) {
            $slot = array_pop($talkDetails->timeSlots);
            if (!empty($slot->fromDate) && !empty($slot->toDate)) {
                $timeZone = new DateTimeZone($slot->timezone);
                $fromDate = new DateTime($slot->fromDate, new DateTimeZone($slot->timezone));
                $fromDate->setTimezone($timeZone);
                $toDate = new DateTime($slot->toDate, new DateTimeZone($slot->timezone));
                $toDate->setTimezone($timeZone);

                $content .= '        <div class="cfp-datetime">';
                $content .= '            <time class="cfp-time" datetime="' . $fromDate->format('c') . '">' . esc_html($fromDate->format('l') . ' from ' . $fromDate->format('H:i')) . '</time>';
                $content .= '            <time class="cfp-time" datetime="' . $toDate->format('c') . '">' . esc_html($toDate->format('H:i')) . '</time>';
                $content .= '        </div>';

                if (get_option('cfp_dev_show_rooms', 'yes') == 'yes') {
                  $content .= '        <div class="cfp-room">' . $slot->roomName . '</div>';
                }

                $content .= '        <input type="hidden" id="cfpTimezone" value="' . esc_attr($slot->timezone) . '">';
                $content .= '        <input type="hidden" id="cfpTalkFrom" value="' . esc_attr($fromDate->getTimestamp()) . '">';
                $content .= '        <input type="hidden" id="cfpTalkExpiry" value="' . esc_attr($toDate->getTimestamp()) . '">';
            }
        }
        return $content;
    }

    function getTalkKeywords($talk) {
        $content = '        <div class="cfp-category">';
        if (empty($talk->keywords)) {
            $content .= '        </div>';
            return $content;
        }
        foreach ($talk->keywords as $keyword) {
            $content .= '<span class="cfp-span">';
            $content .= '    <a href="' . cfp_dev_url('/search-results/?query=' . urlencode($keyword->name)) . '">' . esc_html(ucwords($keyword->name)) . '</a>';
            $content .= '</span>';
        }
        $content .= '        </div>';
        return $content;
    }

    function cleanupDescription($description) {
        $pattern = '/<p(?: class="ql-align-justify")?><br><\/p>/';
        $description = preg_replace($pattern, '', $description);
        return preg_replace('~<span[^>]*>|</span>~', '', $description);
    }

    function generateTalkVideo($talk) {
        $content = '';
        if (!empty($talk->videoURL)) {
            $content .= '    <div class="cfp-video">';
            $content .= '        <div class="cfp-picture"></div>';
            $content .= '        <iframe width="560" height="315" style="z-index: 9999999;" src="' . esc_url($talk->videoURL) .
                '" frameborder="0" allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>';
            $content .= '        <div class="cfp-player"></div>';
            $content .= '    </div>';
        }
        return $content;
    }

    function get_speaker_photos() {
        // Set CORS headers
        header("Access-Control-Allow-Origin: *");
        header("Access-Control-Allow-Methods: GET");
        header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept");

        // If this is a preflight OPTIONS request, send an OK response and exit
        if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
            http_response_code(200);
            exit();
        }

        $speakerId = isset($_GET['speaker_id']) ? intval($_GET['speaker_id']) : 0;
        if ($speakerId === 0) {
            wp_send_json_error('Invalid speaker ID');
            return;
        }

        $speakerName = $_GET['speaker_name'];
        if ($speakerName === 0) {
            wp_send_json_error('Invalid speaker name');
            return;
        }

        // Cache key for this speaker's photos
        $cache_key = generate_cfp_cache_key('photo', $speakerId);

        // Try to get cached content
        $cached_content = get_transient($cache_key);

        if ($cached_content !== false) {
            // If cache exists, return it
            echo $cached_content;
            wp_die();
        }

        $photos = getJSONWithRetry('public/album/' . $speakerId);

        $content = '';
        if (empty($photos)) {
            $content = '<p>No photos found</p>';
        } else {
            $content = displaySpeakerPhotos($content, $photos, $speakerName);
        }

        set_transient($cache_key, $content, CFP_DEV_CACHE);

        echo $content;
        wp_die();
    }

    function getJSONWithRetry($queryPath, $maxAttempts = 2, $delay = 5) {
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $result = getJSON($queryPath);
            if (!empty($result)) {
                return $result;
            }
            if ($attempt < $maxAttempts) {
                sleep($delay);
            }
        }
        return null;
    }

     /**
      * @param $content
      * @param $photos
      * @param $speakerName
      * @return string
      */
     function displaySpeakerPhotos($content, $photos, $speakerName) {
         $content .= '<section class="cfp-gallery">';
         $content .= '    <div class="cfp-frame">';
         foreach ($photos as $photo) {
             if (empty($photo->thumbnailUrl)) {
                 continue;
             }
             $content .= '<a href="' . esc_url('https://www.flickr.com/photos/bejug/' . $photo->photoId . '/in/album-' . $photo->albumId . '/') . '" target="_blank">';
             $speakerImageAlt = $speakerName . ' speaking at ' . CFP_DEV_EVENT_NAME;
             $content .= '<img class="cfp-picture" src="' . esc_url($photo->thumbnailUrl) . '" alt="' . $speakerImageAlt .'">';
             $content .= '</a>';
         }
         $content .= '    </div>';
         $content .= '</section>';
         return $content;
     }

     add_action('wp_ajax_get_speaker_photos', 'get_speaker_photos');
     add_action('wp_ajax_nopriv_get_speaker_photos', 'get_speaker_photos');
}
?>
