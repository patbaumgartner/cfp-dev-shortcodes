<?php
/**
 * Plugin Name:       CFP.DEV shortcodes
 * Plugin URI:        https://gitlab.com/voxxed/cfp.dev/wikis/Wordpress-Plugin
 * Description:       The CFP.DEV wordpress shortcodes (DARK MODE). This version supports the new PWA mobile app! (MySchedule and Home shortcodes have been removed)
 * Version:           3.6.2
 * Author:            Stephan Janssen
 * Author URI:        https://twitter.com/stephan007
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Define global constants.
 *
 * @since 2.0.1
 */

if (!defined('APPLICATION_JSON')) {
    define('APPLICATION_JSON', 'application/json; charset=utf-8');
}

// Plugin version.
if (!defined('CFP_DEV_VERSION')) {
    define('CFP_DEV_VERSION', '3.6.2');
}

if (!defined('CFP_DEV_NAME')) {
    define('CFP_DEV_NAME', trim(dirname(plugin_basename(__FILE__)), '/'));
}

if (!defined('CFP_DEV_DIR')) {
    define('CFP_DEV_DIR', WP_PLUGIN_DIR . '/' . CFP_DEV_NAME);
}

if (!defined('CFP_DEV_URL')) {
    define('CFP_DEV_URL', WP_PLUGIN_URL . '/' . CFP_DEV_NAME);
}

if (!defined('CFP_DEV_KEY')) {
    define('CFP_DEV_KEY', get_transient('CFP_DEV_KEY'));
}

if (!defined('CFP_DEV_THEME')) {
    define('CFP_DEV_THEME', get_transient('CFP_DEV_THEME'));
}

if (!defined('CFP_DEV_CACHE')) {
    define('CFP_DEV_CACHE', get_transient('CFP_DEV_CACHE'));
}

if (!defined('CFP_DEV_EVENT_NAME')) {
    define('CFP_DEV_EVENT_NAME', get_transient('CFP_DEV_EVENT_NAME'));
}

if (!defined('CFP_DEV_URL_DOMAIN')) {
    define('CFP_DEV_URL_DOMAIN', 'https://' . CFP_DEV_KEY . '.cfp.dev/api/');
//    define('CFP_DEV_URL_DOMAIN', 'http://localhost:4200/api/');
}

if (!defined('CFP_DEV_SEARCH_DOMAIN')) {
    define('CFP_DEV_SEARCH_DOMAIN', 'https://search.cfp.dev?cfp=' . CFP_DEV_KEY . '&accepted=true&total=5&query=');
}

if (!defined('CFP_DEV_SEARCH_BOOKS')) {
    define('CFP_DEV_SEARCH_BOOKS', 'https://search.cfp.dev?cfp=books&total=4&query=');
}

if (!defined('CFP_DEV_CSS')) {
    define('CFP_DEV_CSS', 'css/cfp_dev_v3_6_2.css');
}

/**
 * CFP Speakers list.
 *
 * @since 1.0.0
 */
if (file_exists(CFP_DEV_DIR . '/shortcode/shortcode-cfp-speakers.php')) {
    require_once(CFP_DEV_DIR . '/shortcode/shortcode-cfp-speakers.php');
}

/**
 * CFP Speaker details.
 *
 * @since 1.0.0
 */
if (file_exists(CFP_DEV_DIR . '/shortcode/shortcode-cfp-speaker-details.php')) {
    require_once(CFP_DEV_DIR . '/shortcode/shortcode-cfp-speaker-details.php');
}

/**
 * CFP Schedule
 *
 * @since 1.0.0
 */
if (file_exists(CFP_DEV_DIR . '/shortcode/shortcode-cfp-schedule.php')) {
    require_once(CFP_DEV_DIR . '/shortcode/shortcode-cfp-schedule.php');
}

/**
 * CFP Talk details
 *
 * @since 1.0.0
 */
if (file_exists(CFP_DEV_DIR . '/shortcode/shortcode-cfp-talk-details.php')) {
    require_once(CFP_DEV_DIR . '/shortcode/shortcode-cfp-talk-details.php');
}

/**
 * CFP Talks by Tracks
 *
 * @since 1.0.0
 */
if (file_exists(CFP_DEV_DIR . '/shortcode/shortcode-cfp-talks-by-tracks.php')) {
    require_once(CFP_DEV_DIR . '/shortcode/shortcode-cfp-talks-by-tracks.php');
}

/**
 * CFP Talks by Session Types
 *
 * @since 1.0.0
 */
if (file_exists(CFP_DEV_DIR . '/shortcode/shortcode-cfp-talks-by-sessions.php')) {
    require_once(CFP_DEV_DIR . '/shortcode/shortcode-cfp-talks-by-sessions.php');
}

/**
 * CFP Search results
 *
 * @since 1.0.0
 */
if (file_exists(CFP_DEV_DIR . '/shortcode/shortcode-cfp-search-results.php')) {
    require_once(CFP_DEV_DIR . '/shortcode/shortcode-cfp-search-results.php');
}
//
// *******************************************************************************************************************
//

function cfp_ajax_load_scripts()
{
    wp_enqueue_script("jquery-ui.min", plugin_dir_url(__FILE__) . 'js/jquery-ui.min.js', array('jquery'));
    wp_enqueue_script("moment.min", plugin_dir_url(__FILE__) . 'js/moment.min.js');
    wp_enqueue_script("luxon.min", plugin_dir_url(__FILE__) . 'js/luxon2.0.min.js');

    wp_register_style('jquery-style', plugin_dir_url(__FILE__) . 'css/jquery-ui.min.css');
    wp_enqueue_style('jquery-style');

    // load the design related jquery file
    wp_enqueue_script("site-cfp", plugin_dir_url(__FILE__) . 'js/site.js');

    // load our jquery file that sends the $.post request
    wp_enqueue_script("ajax-cfp", plugin_dir_url(__FILE__) . 'js/ajax-cfp-v3.4.js');

    // load the CFP.DEV star function
    wp_enqueue_script("rating-cfp", plugin_dir_url(__FILE__) . 'js/rating-cfp.js');

    // make the ajaxurl var available to the above script
    wp_localize_script('ajax-cfp', 'the_ajax_script', array('ajaxurl' => admin_url('admin-ajax.php')));
}

add_action('wp_print_scripts', 'cfp_ajax_load_scripts');

//---------------------------------------------------------------------------------------------------------------------
function rating_process_request()
{
    if (isset($_POST["talkId"]) && isset($_POST["token"]) && $_POST["rating"]) {
        setRating($_POST["token"], $_POST["talkId"], $_POST["rating"]);
        die();
    }
}

add_action('wp_ajax_rating', 'rating_process_request');
add_action('wp_ajax_nopriv_rating', 'rating_process_request');

function setRating($token, $talkId, $rating)
{
    $link = CFP_DEV_URL_DOMAIN . '/votes/wordpress/' . $talkId . '/' . $rating;

    $response = wp_remote_post($link,
        array(
            'headers' => array('Content-Type' => APPLICATION_JSON,
                'Authorization' => 'Bearer ' . $token
            ),
            'timeout' => 10
        )
    );
    handleResponse($response);
}

//---------------------------------------------------------------------------------------------------------------------
function favourites_process_request()
{
    if (isset($_POST["favs"]) && isset($_POST["token"])) {
        getUserFavs($_POST["token"]);
        die();
    } else if (isset($_POST["talkId"]) && isset($_POST["token"])) {
        setFavourite($_POST["talkId"], $_POST["token"]);
        die();
    }
}

add_action('wp_ajax_favourites', 'favourites_process_request');
add_action('wp_ajax_nopriv_favourites', 'favourites_process_request');

function getUserFavs($token)
{

    $link = CFP_DEV_URL_DOMAIN . 'favourites/talk';

    $response = wp_remote_get($link,
        array('headers' => array('Content-Type' => APPLICATION_JSON,
                                 'Authorization' => 'Bearer ' . $token),
                                 'timeout' => 10));
    handleResponse($response);
}

function setFavourite($talkId, $token)
{
    $link = CFP_DEV_URL_DOMAIN . 'favourites/talk/' . $talkId;

    $response = wp_remote_post($link,
        array(
            'headers' => array('Content-Type' => APPLICATION_JSON,
                'Authorization' => 'Bearer ' . $token),
            'timeout' => 10
        )
    );
    handleResponse($response);
}

//---------------------------------------------------------------------------------------------------------------------
function delete_fav_ajax_process_request()
{
    if (isset($_POST["talkId"]) && isset($_POST["token"])) {
        deleteFavourite($_POST["talkId"], $_POST["token"]);
        die();
    }
}

add_action('wp_ajax_delete_fav', 'delete_fav_ajax_process_request');
add_action('wp_ajax_nopriv_delete_fav', 'delete_fav_ajax_process_request');

//---------------------------------------------------------------------------------------------------------------------
function my_schedule_ajax_process_request()
{
    if (isset($_POST["token"])) {
        getMySchedule($_POST["token"]);
        die();
    }
}

add_action('wp_ajax_my_schedule', 'my_schedule_ajax_process_request');
add_action('wp_ajax_nopriv_my_schedule', 'my_schedule_ajax_process_request');

function getMySchedule($token)
{
    $link = CFP_DEV_URL_DOMAIN . 'favourites/schedule';

    $response = wp_remote_get($link,
        array(
            'headers' => array('Content-Type' => APPLICATION_JSON,
                'Authorization' => 'Bearer ' . $token),
            'timeout' => 10
        )
    );
    handleResponse($response);
}

//---------------------------------------------------------------------------------------------------------------------
function activation_process_request()
{
    if (isset($_POST["email"])) {
        activation($_POST["email"]);
    } else {
        error_log('wrong authenticate params');
    }
}

add_action('wp_ajax_activation', 'activation_process_request');
add_action('wp_ajax_nopriv_activation', 'activation_process_request');

function activation($email)
{
    $link = CFP_DEV_URL_DOMAIN . 'activation/' . $email;

    $response = wp_remote_post($link,
        array(
            'headers' => array('Content-Type' => APPLICATION_JSON),
            'timeout' => 10
        )
    );
    if ( is_wp_error($response) ) {
        echo 'Something went wrong: ' . $response->get_error_message();
    } else {
        $body = wp_remote_retrieve_body( $response );
        $errorPos = stripos(strtolower($body), "error");
        if ($errorPos) {
            echo http_response_code(400);
        } else {
            echo $body;
        }
    }
    die();
}


//---------------------------------------------------------------------------------------------------------------------
function verification_process_request()
{
    if (isset($_POST["email"]) && isset($_POST["digit"])) {
        verifyActivationCode($_POST["email"], $_POST["digit"]);
    } else {
        error_log('wrong verification params');
    }
}

add_action('wp_ajax_verify', 'verification_process_request');
add_action('wp_ajax_nopriv_verify', 'verification_process_request');

function verifyActivationCode($email, $digit) {

    $link = CFP_DEV_URL_DOMAIN . 'authenticate';

    $response = wp_remote_post($link,
        array(
            'headers' => array('Content-Type' => APPLICATION_JSON),
            'body' => json_encode(array(
                'username' => $email,
                'password' => $digit,
                'userRole' => true )),
            'timeout' => 10
        )
    );
    if ( is_wp_error($response) ) {
        echo 'Something went wrong: ' . $response->get_error_message();
    } else {
        $body = wp_remote_retrieve_body( $response );
        $errorPos = stripos(strtolower($body), "error");
        if ($errorPos) {
            echo http_response_code(400);
        } else {
            echo $body;
        }
    }
    die();
}

//---------------------------------------------------------------------------------------------------------------------
function deleteFavourite($talkId, $token)
{

    $link = CFP_DEV_URL_DOMAIN . 'favourites/talk/' . $talkId;

    $response = wp_remote_request($link,
        array(
            'headers' => array('Content-Type' => APPLICATION_JSON,
                'Authorization' => 'Bearer ' . $token),
            'method' => 'DELETE',
            'timeout' => 10
        )
    );
    handleResponse($response);
}

//---------------------------------------------------------------------------------------------------------------------
function handleResponse($response)
{
    if (is_wp_error($response)) {
        $error_message = $response->get_error_message();
        echo "Something went wrong: $error_message";
    } else {
        echo wp_remote_retrieve_body($response);
    }
    die();
}

//
// *******************************************************************************************************************
//

function cfp_dev_plugin_menu()
{
    add_options_page('My Plugin Options', 'CFP.DEV', 'manage_options', 'my-unique-identifier', 'cfp_dev_plugin_options');
}

add_action('admin_menu', 'cfp_dev_plugin_menu');

/** Step 3. */
function cfp_dev_plugin_options() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    $hidden_field_name = 'cfp_dev_clear_cache';

    if (isset($_POST[$hidden_field_name]) && $_POST[$hidden_field_name] == 'Y') {
        clearCache();
    }

    if (isset($_POST['cfp_dev_key'])) {
        storeCfpDevKey($_POST['cfp_dev_key']);
        clearCache();
    }

    if (isset($_POST['cfp_dev_event_name'])) {
        storeCfpDevEventName($_POST['cfp_dev_event_name']);
        clearCache();
    }

    if (isset($_POST['cfp_dev_cache'])) {
        storeCfpDevCache($_POST['cfp_dev_cache']);
        clearCache();
    }

    if (isset($_POST['cfp_dev_default_theme'])) {
        update_option('cfp_dev_default_theme', $_POST['cfp_dev_default_theme']);
    }

    if (isset($_POST['enable_theme_switch'])) {
        update_option('enable_theme_switch', $_POST['enable_theme_switch']);
    }

    if (isset($_POST['cfp_dev_path_prefix'])) {
        update_option('cfp_dev_path_prefix', sanitize_text_field($_POST['cfp_dev_path_prefix']));
    }

    if (isset($_POST['cfp_dev_content_by_id'])) {
        update_option('cfp_dev_content_by_id', sanitize_text_field($_POST['cfp_dev_content_by_id']));
    }

    if (isset($_POST['cfp_dev_show_rooms'])) {
        update_option('cfp_dev_show_rooms', sanitize_text_field($_POST['cfp_dev_show_rooms']));
    }

    echo '<div class="wrap">';
    echo '<h1>CFP.DEV Settings</h1>';

    // General Settings Section
    echo '<hr style="border-color: black">';
    echo '<h3>General Settings</h3>';
    echo '<form name="form1" method="post" action="">';
    echo '<table class="form-table">';
    echo '<tr>
            <th scope="row"><label>CFP.DEV Key</label></th>
            <td><input name="cfp_dev_key" size=20 value="' . esc_attr(CFP_DEV_KEY) . '" minlength="3" required="true"></td>
          </tr>';
    echo '<tr>
            <th scope="row"><label>Event name</label></th>
            <td><input name="cfp_dev_event_name" size=50 value="' . esc_attr(CFP_DEV_EVENT_NAME) . '" minlength="3" required="true"></td>
          </tr>';
    echo '<tr>
            <th scope="row"><label>URL Path Prefix</label></th>
            <td><input name="cfp_dev_path_prefix" size=20 value="' . esc_attr(get_option('cfp_dev_path_prefix', '')) . '"><br>
            <small>For example https://voxxeddays.com/trieste would have "trieste" as url path prefix</small>
            </td>
          </tr>';
    echo '<tr>
            <th scope="row"><label>Permalinks with Id</label></th>
            <td>
              <select name="cfp_dev_content_by_id">
                    <option value="yes" ' . selected(get_option('cfp_dev_content_by_id'), 'yes', false) . '>Yes</option>
                    <option value="no" ' . selected(get_option('cfp_dev_content_by_id'), 'no', false) . '>No</option>
              </select>
              <br>
              <strong>Must be "Yes" for multisite worpdress installs.</strong>
              <small>When "Yes" the content links look as follows https://voxxeddays.com/trieste/speaker?id=123</small>
            </td>
          </tr>';
    echo '<tr>
            <th scope="row"><label>Show Rooms</label></th>
            <td>
              <select name="cfp_dev_show_rooms">
                    <option value="yes" ' . selected(get_option('cfp_dev_show_rooms'), 'yes', false) . '>Yes</option>
                    <option value="no" ' . selected(get_option('cfp_dev_show_rooms'), 'no', false) . '>No</option>
              </select>
              <br>
              <small>When "No" rooms will not be displayed on any page</small>
            </td>
          </tr>';
    echo '<tr>
            <th scope="row"><label>Cache Duration</label></th>
            <td>
                <select name="cfp_dev_cache">
                    <option value="0" ' . selected(CFP_DEV_CACHE, 0, false) . '>No Cache</option>
                    <option value="3600" ' . selected(CFP_DEV_CACHE, 3600, false) . '>One Hour</option>
                    <option value="86400" ' . selected(CFP_DEV_CACHE, 86400, false) . '>One Day</option>
                    <option value="604800" ' . selected(CFP_DEV_CACHE, 604800, false) . '>One Week</option>
                    <option value="2592000" ' . selected(CFP_DEV_CACHE, 2592000, false) . '>One Month</option>
                </select>
            </td>
          </tr>';
    echo '<tr>
            <th scope="row"><label>Default Theme</label></th>
            <td>
                <select name="cfp_dev_default_theme">
                    <option value="light" ' . selected(get_option('cfp_dev_default_theme'), 'light', false) . '>Light</option>
                    <option value="dark" ' . selected(get_option('cfp_dev_default_theme'), 'dark', false) . '>Dark</option>
                </select>
            </td>
          </tr>';
    echo '<tr>
            <th scope="row"><label>Enable Theme Switching</label></th>
            <td><input type="checkbox" name="enable_theme_switch" value="1" ' . checked(1, get_option('enable_theme_switch'), false) . ' /></td>
          </tr>';
    echo '</table>';
    echo '<p class="submit"><input type="submit" name="Submit" class="button-primary" value="Save Changes" /></p>';
    echo '</form>';

    // Cache Management Section
    echo '<hr style="border-color: black">';
    echo '<h3>Manage Caches</h3>';
    echo '<p>Here you can view and delete various caches used by the plugin.</p>';

    // Speakers cache
    echo '<h4>Speakers Cache</h4>';
    $speakers_cache = get_transient('speakers_cache_group');
    if ($speakers_cache !== false) {
        echo '<form method="post" action="">
                <input type="hidden" name="delete_cache" value="speakers">
                <input type="submit" class="button" value="Delete Speakers Cache">
              </form>';
    } else {
        echo '<p>No speakers cache available.</p>';
    }

    // Schedule caches
    echo '<h4>Schedule Caches</h4>';
    $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
    $schedule_caches_exist = false;

    echo '<table class="wp-list-table widefat fixed striped">
            <thead><tr><th>Day</th><th>Action</th></tr></thead>
            <tbody>';

    foreach ($days as $day) {
        $cache_key = 'cfp_schedule_' . $day;
        if (get_transient($cache_key) !== false) {
            $schedule_caches_exist = true;
            echo '<tr>
                    <td>' . ucfirst($day) . '</td>
                    <td>
                        <form method="post" action="">
                            <input type="hidden" name="delete_cache" value="schedule">
                            <input type="hidden" name="cache_day" value="' . esc_attr($day) . '">
                            <input type="submit" class="button button-small" value="Delete Cache">
                        </form>
                    </td>
                  </tr>';
        }
    }

    echo '</tbody></table>';

    if (!$schedule_caches_exist) {
        echo '<p>No schedule caches available.</p>';
    }

    // Speaker detail caches
    echo '<h4>Speaker Detail Caches</h4>';
    $speakers = getJSON('public/speakers?size=500');
    $speaker_caches_exist = false;

    if (is_array($speakers) || is_object($speakers)) {
        echo '<table class="wp-list-table widefat fixed striped">
            <thead><tr><th>Speaker ID</th><th>Name</th><th>Action</th></tr></thead>
            <tbody>';

        foreach ($speakers as $speaker) {
            $transient_key = generate_cfp_cache_key('speaker', $speaker->id);
            if (get_transient($transient_key) !== false) {
                $speaker_caches_exist = true;
                echo '<tr id="speaker-row-' . esc_attr($speaker->id) . '">
                    <td>' . esc_html($speaker->id) . '</td>
                    <td>' . esc_html($speaker->firstName . ' ' . $speaker->lastName) . '</td>
                    <td>
                        <form method="post" action="" class="delete-cache-form">
                            <input type="hidden" name="delete_cache" value="speaker">
                            <input type="hidden" name="cache_id" value="' . esc_attr($speaker->id) . '">
                            <input type="submit" class="button button-small delete-cache-button" value="Delete Cache">
                        </form>
                    </td>
                  </tr>';
            }
        }

        echo '</tbody></table>';
    }

    if (!$speaker_caches_exist) {
        echo '<p>No speaker detail caches available.</p>';
    }

    // Talk detail caches
    echo '<h4>Talk Detail Caches</h4>';
    $talks = getJSON('public/talks');
    $talk_caches_exist = false;

    if (is_array($talks) || is_object($talks)) {
        echo '<table class="wp-list-table widefat fixed striped">
                <thead><tr><th>Talk ID</th><th>Title</th><th>Action</th></tr></thead>
                <tbody>';

        foreach ($talks as $talk) {
            $transient_key = generate_cfp_cache_key('talk', $talk->id);
            if (get_transient($transient_key) !== false) {
                $talk_caches_exist = true;
                echo '<tr>
                        <td>' . esc_html($talk->id) . '</td>
                        <td>' . esc_html($talk->title) . '</td>
                        <td>
                            <form method="post" action="">
                                <input type="hidden" name="delete_cache" value="talk">
                                <input type="hidden" name="cache_id" value="' . esc_attr($talk->id) . '">
                                <input type="submit" class="button button-small" value="Delete Cache">
                            </form>
                        </td>
                      </tr>';
            }
        }

        echo '</tbody></table>';
    }

    if (!$talk_caches_exist) {
        echo '<p>No talk detail caches available.</p>';
    }

    // Process cache deletion
    if (isset($_POST['delete_cache'])) {
        $cache_type = $_POST['delete_cache'];

        switch ($cache_type) {
            case 'speakers':
                delete_transient('speakers_cache_group');
                echo '<div class="updated"><p>Speakers cache deleted.</p></div>';
                break;
            case 'schedule':
                if (isset($_POST['cache_day'])) {
                    $day = $_POST['cache_day'];
                    delete_transient('cfp_schedule_' . $day);
                    echo '<div class="updated"><p>Schedule cache for ' . ucfirst($day) . ' deleted.</p></div>';
                }
                break;
            case 'speaker':
            case 'talk':
                if (isset($_POST['cache_id'])) {
                    $cache_id = $_POST['cache_id'];

                    // Delete speaker or talk cache
                    delete_transient(($cache_type === 'speaker') ? generate_cfp_cache_key('speaker', $cache_id) : generate_cfp_cache_key('talk', $cache_id));

                    // Delete photo speaker cache
                    delete_transient(generate_cfp_cache_key('photo', $cache_id));

                    echo '<div class="updated"><p>Cache deleted for speaker with ID: ' . esc_html($cache_id) . ' (including any photo cache).</p></div>';
                }
                break;
        }
    }

    echo '</div>'; // Close the wrap div
}

function generate_cfp_cache_key($type, $id) {
    switch ($type) {
        case 'speaker':
            return 'cfp_speaker_details_' . md5($id);
        case 'talk':
            return 'cfp_talk_details_' . md5($id);
        case 'photo':
            return 'speaker_photos_' . md5($id);
        default:
            return 'cfp_' . $type . '_' . md5($id);
    }
}

function isCurrentCache($cache)
{
    if (CFP_DEV_CACHE == $cache) {
        return 'selected';
    }
}

function currentSummaryFlag($flag)
{
    if (CFP_DEV_SUMMARY == $flag) {
        return 'selected';
    }
}

function storeCfpDevKey($key)
{
    # Check Constant CFP_DEV_KEY already defined
    if (!defined('CFP_DEV_KEY')) {
        define('CFP_DEV_KEY', $key);
    }
    set_transient('CFP_DEV_KEY', $key);
}

function storeCfpDevCache($key)
{
    if (!defined('CFP_DEV_CACHE')) {
        define('CFP_DEV_CACHE', $key);
    }
    set_transient('CFP_DEV_CACHE', $key);
}

function storeCfpDevSummary($flag)
{
    if (!defined('CFP_DEV_SUMMARY')) {
        define('CFP_DEV_SUMMARY', $flag);
    }
    set_transient('CFP_DEV_SUMMARY', $flag);
}

function storeCfpDevEventName($cfpDevEventName)
{
    if (!defined('CFP_DEV_EVENT_NAME')) {
        define('CFP_DEV_EVENT_NAME', $cfpDevEventName);
    }
    set_transient('CFP_DEV_EVENT_NAME', $cfpDevEventName);
}

/**
 * Clear cache by day per page type
 * @return void
 */
function clearCache()
{
    $transientNames = ['speakers_cache_group', 'talks_by_tracks_cache_group_', 'talks_by_sessions_cache_group_'];
    array_map('delete_transient', $transientNames);

    $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    foreach ($days as $day) {
        deleteCacheByDay($day);
    }

    $speakers = getJSON('public/speakers?size=500');
    if (is_array($speakers) || is_object($speakers)) {
        foreach ($speakers as $speaker) {
            $slug = generate_slug($speaker->firstName . '-' . $speaker->lastName);
            $cache_key = 'cfp_speaker_slug_' . md5($slug);
            delete_transient($cache_key);

            // Remove speaker and photo cache
            $transient_key = generate_cfp_cache_key('speaker', $speaker->id);
            if (get_transient($transient_key) !== false) {
                delete_transient($transient_key);
            }
            $transient_key = generate_cfp_cache_key('photo', $speaker->id);
            if (get_transient($transient_key) !== false) {
                delete_transient($transient_key);
            }
        }
    }

    $talks = getJSON('public/talks');
    if (is_array($talks) || is_object($talks)) {
        foreach ($talks as $talk) {
            $slug = generate_slug($talk->title);
            $cache_key = 'cfp_talk_slug_' . md5($slug);
            delete_transient($cache_key);

            $transient_key = generate_cfp_cache_key('talk', $talk->id);
            if (get_transient($transient_key) !== false) {
                delete_transient($transient_key);
            }
        }
    }
}

/**
 * Delete transients
 * @param $transientName string
 * @param $dataArray array
 */
function deleteTransients($transientName, $dataArray) {
    foreach ($dataArray as $data) {
        $id = property_exists($data, 'id') ? $data->id : null;

        if (!empty($id)) {
            $cachedName = $transientName . $id;
            if (get_transient($cachedName)) {
                delete_transient($cachedName);
            }
        }
    }
}

/**
 * Delete cache by day
 * @param $day string
 */
function deleteCacheByDay($day)
{
    delete_transient('cfp_schedule_' . $day);

    $sessionTypes = getJSON('public/session-types');
    !empty($sessionTypes) && deleteTransients('talks_by_sessions_cache_group_', $sessionTypes);

    $tracks = getJSON('public/tracks');
    !empty($tracks) && deleteTransients('talks_by_tracks_cache_group_', $tracks);

    $data = getJSON('public/schedules/' . $day);

    if (!empty($data)) {
        foreach ($data as $timeSlot) {
            if (!empty($timeSlot->proposal->title)) {
                deleteTalkAndSpeakerDetails($timeSlot);
            }
        }
    }
}

/**
 * @param $timeSlot
 * @return void
 */
function deleteTalkAndSpeakerDetails($timeSlot)
{
    delete_transient('cfp_talk_details_' . $timeSlot->proposal->id);

    foreach ($timeSlot->proposal->speakers as $speaker) {
        delete_transient('cfp_speaker_details_' . $speaker->id);
    }
}

// Returns the event from/to day names.
// For example (monday & friday if the event is 5 days and starts on monday).
function getEventDetails()
{
    $link = CFP_DEV_URL_DOMAIN . 'public/event';

    $response = wp_remote_get($link,
        array(
            'headers' => array('Content-Type' => APPLICATION_JSON),
            'timeout' => 10
        )
    );
    if (is_wp_error($response)) {
        $error_message = $response->get_error_message();
        echo "Something went wrong: $error_message";
    } else {
        $body = wp_remote_retrieve_body($response);

        $errorPos = stripos(strtolower($body), "error");

        if ($errorPos) {
            echo http_response_code(400);
        } else {
            $data = json_decode($body, true);
            $fromDay = date("w", strtotime($data["fromDate"]));
            $toDay = date("w", strtotime($data["toDate"]));
            return [$fromDay, $toDay];
        }
    }
    return null;
}

function showTalk($talk, $showDescription, $showRating, $showVideo)
{
    $content = '<div class="dev-cfp-wrapper">';
    $content .= '    <div class="dev-cfp-row">';
    $content .= '        <div class="dev-cfp-column" style="flex-grow:7">';
    $content .= '            <h2>' . $talk->title . '</h2>';
    if (empty($talk->sessionTypeName)) {
        $content .= '            <small><i>' . $talk->sessionType->name . '</i></small>';
    } else {
        $content .= '            <small><i>' . $talk->sessionTypeName . '</i></small>';
    }
    $content .= '        </div>';
    $content .= '        <div class="dev-cfp-column dev-cfp-track-image" style="flex-grow:1">';
    if (empty($talk->trackImageURL)) {
        $content .= '<img src="' . $talk->track->imageURL . '" width="100px" alt="' . $talk->track->name . '" title="' . $talk->track->name . '">';
    } else {
        $content .= '<img src="' . $talk->trackImageURL . '" width="100px" alt="' . $talk->trackName . '" title="' . $talk->trackName . '">';
    }
    $content .= '        </div>';
    $content .= '    </div>';

    if ($showRating && count($talk->timeSlots, COUNT_NORMAL) > 0) {
        $slot = array_pop($talk->timeSlots);
        $timeZone = new DateTimeZone($slot->timezone);

        $fromDate = new DateTime($slot->fromDate, $timeZone);

        $expiryDate = clone $fromDate;
        $expiryDate->add(new DateInterval('P1D'));

        $content .= '<input type="hidden" id="cfpTimezone" value="' . $slot->timezone . '">';
        $content .= '<input type="hidden" id="cfpTalkId" value="' . $talk->id . '">';
        $content .= '<input type="hidden" id="cfpTalkFrom" value="' . strtotime($fromDate->format('Y-m-d H:i:s')) . '">';
        $content .= '<input type="hidden" id="cfpTalkExpiry" value="' . strtotime($expiryDate->format('Y-m-d H:i:s')) . '">';

        $content .= '<form id="rating-enabled">';
        $content .= '    <div class="dev-cfp-row">';
        $content .= '        <input type="hidden" id="dev-cfp-star1-hidden" value="1">';
        $content .= '        <img class="dev-cfp-star" src="https://s3.eu-west-1.amazonaws.com/cfp.dev/images/star1.png" onmouseover="change(this.id)" id="dev-cfp-star1">';
        $content .= '        <input type="hidden" id="dev-cfp-star2-hidden" value="2">';
        $content .= '        <img class="dev-cfp-star" src="https://s3.eu-west-1.amazonaws.com/cfp.dev/images/star1.png" onmouseover="change(this.id)" id="dev-cfp-star2">';
        $content .= '        <input type="hidden" id="dev-cfp-star3-hidden" value="3">';
        $content .= '        <img class="dev-cfp-star" src="https://s3.eu-west-1.amazonaws.com/cfp.dev/images/star1.png" onmouseover="change(this.id)" id="dev-cfp-star3">';
        $content .= '        <input type="hidden" id="dev-cfp-star4-hidden" value="4">';
        $content .= '        <img class="dev-cfp-star" src="https://s3.eu-west-1.amazonaws.com/cfp.dev/images/star1.png" onmouseover="change(this.id)" id="dev-cfp-star4">';
        $content .= '        <input type="hidden" id="dev-cfp-star5-hidden" value="5">';
        $content .= '        <img class="dev-cfp-star" src="https://s3.eu-west-1.amazonaws.com/cfp.dev/images/star1.png" onmouseover="change(this.id)" id="dev-cfp-star5">';
        $content .= '        <input type="hidden" name="rating" id="dev-cfp-star-rating" value="0">';
        $content .= '        <input type="hidden" name="talkId" id="dev-cfp-star-talk-id" value="' . $talk->id . '">';
        $content .= '        <button type="button" id="ratingSubmit">Vote</button>';
        $content .= '    </div>';
        $content .= '    <div class="dev-cfp-row">';
        $content .= '    <div id="dev-cfp-rating-txt"></div>';
        $content .= '    </div>';
        $content .= '</form>';
        $content .= '<div id="rating-disabled" class="dev-cfp-row">';
        $content .= '   <img class="dev-cfp-star" src="https://s3.eu-west-1.amazonaws.com/cfp.dev/images/star1.png">';
        $content .= '   <img class="dev-cfp-star" src="https://s3.eu-west-1.amazonaws.com/cfp.dev/images/star1.png">';
        $content .= '   <img class="dev-cfp-star" src="https://s3.eu-west-1.amazonaws.com/cfp.dev/images/star1.png">';
        $content .= '   <img class="dev-cfp-star" src="https://s3.eu-west-1.amazonaws.com/cfp.dev/images/star1.png">';
        $content .= '   <img class="dev-cfp-star" src="https://s3.eu-west-1.amazonaws.com/cfp.dev/images/star1.png">';
        $content .= '</div>';
        $content .= '<div id="dev-cfp-no-rating-txt" class="dev-cfp-row">';
        $content .= '    <div>Voting no longer possible</div>';
        $content .= '</div>';
        $content .= '<div id="dev-cfp-rating-txt" class="dev-cfp-row">';
        $content .= '    <div>Voting enabled when talk has started</div>';
        $content .= '</div>';
    }

    if ( $showDescription ) {
        $content .= '    <div class="dev-cfp-row">';
        $content .= '        <div class="dev-cfp-column">';
        $content .= '            <p>' . $talk->description . '</p>';
        $content .= '        </div>';
        $content .= '    </div>';
    }

    if (!empty($talk->videoURL) && $showVideo) {

        $content .= embedVideo($talk->videoURL);

    } else if (!empty($talk->afterVideoURL) && $showVideo) {

        $content .= embedVideo($talk->afterVideoURL);

    } else if (count($talk->timeSlots, COUNT_NORMAL) > 0) {

        $slot = array_pop($talk->timeSlots);
        if (!empty($slot->fromDate) && !empty($slot->toDate)) {
            $timeZone = new DateTimeZone($slot->timezone);
            $fromDate = new DateTime($slot->fromDate, new DateTimeZone($slot->timezone));
            $fromDate->setTimezone($timeZone);

            $toDate = new DateTime($slot->toDate, new DateTimeZone($slot->timezone));
            $toDate->setTimezone($timeZone);

            $content .= '    <div class="dev-cfp-row">';
            $content .= '        <div class="dev-cfp-column">';
            $content .= '            <p><strong>Scheduled on ' . $fromDate->format('l') . ' from ' . $fromDate->format('H:i') . ' to ' . $toDate->format('H:i') . ' (' . $slot->timezone . ') in ' . $slot->roomName . '</strong></p>';
            $content .= '        </div>';
            $content .= '    </div>';
        }
    }
    $content .= '</div>';

    if ($showRating) {
        $content .= getLoginDialog();
    }

    return $content;
}

function embedVideo($videoUrl)
{
    $content = '    <div class="dev-cfp-row">';
    $content .= '        <div class="dev-cfp-column dev-cfp-youtube-video">';
    $content .= '<iframe width="560" height="315" src="' . $videoUrl . '" frameborder="0" allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>';
    $content .= '        </div>';
    $content .= '    </div>';
    return $content;
}

function getFooter()
{
    if (get_option('enable_theme_switch', false)) {
        $content = '<footer class="cfp-footer">';
        $content .= '	<div class="cfp-theme">';
        $content .= '    	<a id="lightTheme" class="cfp-a cfp-light" data-theme-key="light">Light</a>';
        $content .= '    	<a id="darkTheme" class="cfp-a cfp-dark" data-theme-key="dark">Dark</a>';
        $content .= '</div>';
        $content .= '</footer>';
        return $content;
    }
}

function embedSocialSpeakerCard($speaker)
{
    $content = '<meta name="twitter:card" content="summary_large_image">';
    if (!empty($speaker->twitterHandle)) {
        $content .= '<meta name="twitter:site" content="' . $speaker->twitterHandle . '">';
    }

    if (!empty($speaker->imageUrl)) {
        $content .= '<meta name="twitter:image" content="' . $speaker->imageUrl . '">';
    }
    $speakerInfo = $speaker->firstName . ' ' . $speaker->lastName . ' at ' . CFP_DEV_EVENT_NAME;
    $content .= '<meta name="og:title" content="' . $speakerInfo . '">';
    $content .= '<meta name="twitter:title" content="' . $speaker->firstName . ' ' . $speaker->lastName . ' at ' . CFP_DEV_EVENT_NAME . '">';
    $content .= '<meta name="twitter:description" content="' . strip_tags(substr($speaker->bio, 0, 260)) . '">';

    return $content;
}

function embedSocialTalkCard($talk)
{
    $content = '<meta name="twitter:card" content="summary">';
    $content .= '<meta name="twitter:image" content="' . $talk->trackImageURL . '">';
    $content .= '<meta name="og:title" content="' . strip_tags($talk->title) . ' at ' . CFP_DEV_EVENT_NAME . '">';
    $content .= '<meta name="og:url" content="https://' . CFP_DEV_KEY . '.cfp.dev/talk?id=' . $talk->id . '">';
    $content .= '<meta name="twitter:title" content="' . strip_tags($talk->title) . ' at ' . CFP_DEV_EVENT_NAME . '">';
    $content .= '<meta name="twitter:description" content="' . strip_tags(substr($talk->description, 0, 260)) . '">';

    return $content;
}

function compareLastName($x, $y)
{
    if (iconv('utf-8', 'ascii//TRANSLIT', $x->lastName) ==
        iconv('utf-8', 'ascii//TRANSLIT', $y->lastName)) {
        return 0;
    } else if (iconv('utf-8', 'ascii//TRANSLIT', $x->lastName) >
        iconv('utf-8', 'ascii//TRANSLIT', $y->lastName)) {
        return 1;
    } else {
        return -1;
    }
}

function compareName($x, $y)
{
    if (iconv('utf-8', 'ascii//TRANSLIT', $x->name) ==
        iconv('utf-8', 'ascii//TRANSLIT', $y->name)) {
        return 0;
    } else if (iconv('utf-8', 'ascii//TRANSLIT', $x->name) >
        iconv('utf-8', 'ascii//TRANSLIT', $y->name)) {
        return 1;
    } else {
        return -1;
    }
}

/**
 * @param $content
 * @return string
 */
function getLoginDialog()
{
    $content = '<div id="loginDialog" class="cfp-dev-login-dialog" title="Login">';
    $content .= '    <p>Enter your ' . CFP_DEV_EVENT_NAME . ' <a href="https://' . CFP_DEV_KEY . '.cfp.dev/#/register">CFP.DEV credentials</a> to create your own schedule.</p>';
    $content .= '    <p id="errorMsg"></p>';
    $content .= '    <form>';
    $content .= '        <table class="cfp-dev-login-table">';
    $content .= '            <tr>';
    $content .= '                <td colspan="2"><input type="text" id="username" name="username" minlength="1" placeholder="username"/></td>';
    $content .= '            </tr>';
    $content .= '            <tr>';
    $content .= '                <td colspan="2"><input type="password" id="password" minlength="8" name="password" placeholder="password"/></td>';
    $content .= '            </tr>';
    $content .= '            <tr>';
    $content .= '                <td colspan="2"><button type="button" id="loginSubmit">Login</button></td>';
    $content .= '            </tr>';
    $content .= '            <tr>';
    $content .= '                <td style="text-align: left;"><small><a href="https://' . CFP_DEV_KEY . '.cfp.dev/#/reset/request" target="_blank">Forgot password?</a></small></td>';
    $content .= '                <td style="text-align: right;"><small><a href="https://' . CFP_DEV_KEY . '.cfp.dev/#/register" target="_blank">Register</a></small></td>';
    $content .= '            </tr>';
    $content .= '        </table>';
    $content .= '    </form>';
    $content .= '    <p><small>Syncs also with the Devoxx mobile apps.</small></p>';
    $content .= '</div>';
    return $content;
}

function getJSON($queryPath) {

    $query_url = CFP_DEV_URL_DOMAIN . $queryPath;

    // Initialize cURL session
    $ch = curl_init($query_url);

    // Set cURL options
    curl_setopt($ch, CURLOPT_TIMEOUT, 180); // Set timeout to 60 seconds
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Connection: keep-alive',
        'Keep-Alive: timeout=1500, max=100'
    ));

    // Execute cURL session
    $response = curl_exec($ch);

    // Check for cURL errors
    if (curl_errno($ch)) {
        error_log('cURL error: ' . curl_error($ch));
        curl_close($ch);
        return null;
    }

    // Get HTTP status code
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($statusCode != 200) {
        error_log('cURL request returned status code ' . $statusCode);
        curl_close($ch);
        return null;
    }

    // Close cURL session
    curl_close($ch);

    // Decode the JSON response
    $decoded = json_decode($response);

    // Check if JSON decoding was successful
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('JSON decoding error: ' . json_last_error_msg());
        return null;
    }

    error_log('JSON decoding successful for : ' . $queryPath);
    return $decoded;
}

function getHTMLSummary($talkId) {
    $response = wp_remote_get("https://ask-devoxx-admin.cleverapps.io/api/public/conference/" . CFP_DEV_KEY . "/summary/" . $talkId,
        array('timeout' => 10, 'headers' => array('Accept' => 'text/plain')));
    if (is_wp_error($response)) {
        return 'REST error:' . $response->get_error_message();
    }
    return wp_remote_retrieve_body($response);
}

function searchJSON($query) {
    $response = wp_remote_get( CFP_DEV_SEARCH_DOMAIN . strip_tags($query), array( 'timeout' => 30 ) );
    if ( is_wp_error( $response ) ) {
        return 'REST error:' . $response->get_error_message();
    }
    return json_decode( wp_remote_retrieve_body( $response ) );
}

function searchBooks($query) {
    $response = wp_remote_get( CFP_DEV_SEARCH_BOOKS . $query, array( 'timeout' => 30 ) );
    if ( is_wp_error( $response ) ) {
        return 'REST error:' . $response->get_error_message();
    }
    return json_decode( wp_remote_retrieve_body( $response ) );
}

function getTime( $time, $timezone, $format ) {
    $dt = new DateTime($time, new DateTimeZone('UTC'));
    $dt->setTimezone($timezone);
    return $dt->format($format);
}

function getSearchForm() {
    $content = '<form class="cfp-search" action="search-results" method="GET">';
    $content .= '   <input class="cfp-input" id="dev-cfp-search-term" type="search" minlength="3" name="query" placeholder="Full search..." autofocus>';
    $content .= '</form>';
    return $content;
}

function cfp_dev_enqueue_admin_scripts($hook) {
    if ('settings_page_my-unique-identifier' !== $hook) {
        return;
    }
    wp_enqueue_script('cfp-dev-admin-cache', plugins_url('js/admin-cache-management.js', __FILE__), array('jquery'), '1.0', true);
    wp_localize_script('cfp-dev-admin-cache', 'cfp_dev_ajax', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('cfp_dev_delete_cache')
    ));
}
add_action('admin_enqueue_scripts', 'cfp_dev_enqueue_admin_scripts');

function cfp_dev_delete_cache_handler() {

    // Check for nonce for security
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'cfp_dev_delete_cache')) {
        wp_send_json_error(array('message' => 'Security check failed.'));
        return;
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'You do not have permission to perform this action.'));
        return;
    }

    if (!isset($_POST['delete_cache']) || !isset($_POST['cache_id'])) {
        wp_send_json_error(array('message' => 'Missing required parameters'));
        return;
    }

    $cache_type = sanitize_text_field($_POST['delete_cache']);
    $cache_id = sanitize_text_field($_POST['cache_id']);

    if ($cache_type === 'speaker') {
        $transient_key = 'cfp_speaker_details_' . $cache_id;
        $photo_transient_key = 'speaker_photos_' . $cache_id;

        $deleted_speaker = delete_transient($transient_key);
        $deleted_photo = delete_transient($photo_transient_key);

        error_log("Deleted speaker transient: " . ($deleted_speaker ? 'true' : 'false'));
        error_log("Deleted photo transient: " . ($deleted_photo ? 'true' : 'false'));

        wp_send_json_success(array('message' => 'Cache deleted for speaker with ID: ' . $cache_id));
    } else {
        wp_send_json_error(array('message' => 'Invalid cache type'));
    }
}
add_action('wp_ajax_cfp_dev_delete_cache', 'cfp_dev_delete_cache_handler');
add_action('wp_ajax_nopriv_cfp_dev_delete_cache', 'cfp_dev_delete_cache_handler');

function cfp_dev_add_rewrite_rules() {
  $prefix = get_option('cfp_dev_path_prefix', '');
  $prefix = $prefix ? $prefix . '/' : '';

  // Handle speaker URL with slug
  add_rewrite_rule(
    '^' . $prefix . 'speaker/([^/]+)/?$',
    'index.php?pagename=speaker&speaker_slug=$matches[1]',
    'top'
  );

  // Handle talk URL with slug
  add_rewrite_rule(
    '^' . $prefix . 'talk/([^/]+)/?$',
    'index.php?pagename=talk&talk_slug=$matches[1]',
    'top'
  );

  // Handle schedule URL
  add_rewrite_rule(
    '^' . $prefix . 'schedule/?$',
    'index.php?pagename=schedule',
    'top'
  );

  // Handle talk URL with ID parameter
  add_rewrite_rule(
    '^' . $prefix . 'talk/?$',
    'index.php?pagename=talk&id=$matches[1]',
    'top'
  );

  // Handle subdirectory before talk URL with slug (fix for subdirectory redirect)
  if (!empty($prefix)) {
    add_rewrite_rule(
      '([^/]+)/' . $prefix . 'talk/([^/]+)/?$',
      'index.php?pagename=talk&talk_slug=$matches[2]',
      'top'
    );

    // Handle subdirectory before talk URL with ID
    add_rewrite_rule(
      '([^/]+)/' . $prefix . 'talk/?$',
      'index.php?pagename=talk&id=$matches[2]',
      'top'
    );
  }
}
add_action('init', 'cfp_dev_add_rewrite_rules');

function cfp_dev_url($path) {
    $prefix = get_option('cfp_dev_path_prefix', '');
    $prefix = $prefix ? '/' . $prefix : '';
    return $prefix . $path;
}

function cfp_dev_add_query_vars($vars) {
    $vars[] = 'speaker_slug';
    $vars[] = 'talk_slug';
    $vars[] = 'id';
    return $vars;
}
add_filter('query_vars', 'cfp_dev_add_query_vars');

function cfp_dev_flush_rewrite_rules() {
    cfp_dev_add_rewrite_rules();
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'cfp_dev_flush_rewrite_rules');

function get_speaker_by_id($id) {
    return getJSON('public/speakers/' . $id);
}

function get_talk_by_id($id) {
    return getJSON('public/talks/' . $id);
}

function get_speaker_id_from_slug($slug) {
    $cache_key = 'cfp_speaker_slug_' . md5($slug);
    $speaker_id = get_transient($cache_key);

    if ($speaker_id === false) {
        $speakers = getJSON('public/speakers?size=400');
        foreach ($speakers as $speaker) {
            $current_slug = generate_slug($speaker->firstName . '-' . $speaker->lastName);
            if ($current_slug === $slug) {
                $speaker_id = $speaker->id;
                set_transient($cache_key, $speaker_id, DAY_IN_SECONDS);
                break;
            }
        }
    }

    return $speaker_id;
}

function get_talk_by_slug($slug) {
    $talks = getJSON('public/talks');
    foreach ($talks as $talk) {
        $talk_slug = generate_slug($talk->title);
        if ($talk_slug === $slug) {
            return $talk;
        }
    }
    return null;
}

// Add this function to generate a slug
function generate_slug($string) {
    return strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $string)));
}

function cfp_dev_debug_request($wp) {
    error_log('WP Request: ' . print_r($wp->request, true));
    error_log('WP Matched Rule: ' . $wp->matched_rule);
    error_log('WP Matched Query: ' . $wp->matched_query);
    error_log('WP Query Vars: ' . print_r($wp->query_vars, true));
}
add_action('parse_request', 'cfp_dev_debug_request');

function register_cfp_shortcodes() {
    add_shortcode('cfp_speaker_details', 'cfp_speaker_details_shortcode');
}
add_action('init', 'register_cfp_shortcodes');

function debug_shortcode_processing($output, $tag, $attr, $m) {
    error_log("Shortcode processed: $tag");
    return $output;
}
add_filter('do_shortcode_tag', 'debug_shortcode_processing', 10, 4);


function cfp_speaker_details_template($template) {
    error_log('cfp_speaker_details_template: ' . $template);
    if (get_query_var('pagename') === 'speaker') {
        $new_template = plugin_dir_path(__FILE__) . 'speaker-template.php';
        if (file_exists($new_template)) {
            return $new_template;
        }
    } else if (get_query_var('pagename') === 'talk') {
        $new_template = plugin_dir_path(__FILE__) . 'talk-template.php';
        if (file_exists($new_template)) {
            return $new_template;
        }
    }
    return $template;
}
add_filter('template_include', 'cfp_speaker_details_template');

/**
 * Create required CFP.DEV enabled shortcode pages on plugin activation
 * @param $atts array
 * @return string HTML content
 */
function cfp_create_required_pages() {
    $pages = array(
        'speakers' => array(
            'title' => 'Speakers',
            'content' => '[cfp_speakers]'
        ),
        'speaker' => array(
            'title' => 'Speaker',
            'content' => '[cfp_speaker_details]'
        ),
        'talk' => array(
            'title' => 'Talks',
            'content' => '[cfp_talk_details]'
        ),
        'schedule' => array(
            'title' => 'Schedule',
            'content' => '[cfp_schedule]'
        ),
        'search-results' => array(
            'title' => 'Search Results',
            'content' => '[cfp_search_results]'
        ),
        'talks-by-tracks' => array(
            'title' => 'Talks by Tracks',
            'content' => '[cfp_talks_by_tracks]'
        ),
        'talks-by-sessions' => array(
            'title' => 'Talks by Sessions',
            'content' => '[cfp_talks_by_sessions]'
        )
    );

    foreach ($pages as $slug => $page_data) {
        $existing_page = get_page_by_path($slug);
        if ($existing_page === null) {
            $page_id = wp_insert_post(array(
                'post_title' => $page_data['title'],
                'post_name' => $slug,
                'post_status' => 'publish',
                'post_type' => 'page',
                'post_content' => $page_data['content']
            ));

            if ($page_id) {
                // Optionally, you can set a specific template for the page
                // update_post_meta($page_id, '_wp_page_template', 'template-file-name.php');
            }
        } else {
            // Optionally, update existing page content if needed
            // wp_update_post(array(
            //     'ID' => $existing_page->ID,
            //     'post_content' => $page_data['content']
            // ));
        }
    }

    // Flush rewrite rules after creating new pages
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'cfp_create_required_pages');


function add_speaker_title_script() {
    if (is_page('speaker')) {
        ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const ogTitle = document.querySelector('meta[name="og:title"]');
                if (ogTitle) {
                    document.title = ogTitle.getAttribute('content');
                }
            });
        </script>
        <?php
    }
}
add_action('wp_footer', 'add_speaker_title_script');


function add_meta_description() {
    if (is_page('speakers')) {
        echo '<meta name="description" content="Browse our lineup of expert speakers at ' . CFP_DEV_EVENT_NAME . '.">';
    } elseif (is_page('schedule')) {
        echo '<meta name="description" content="View the full schedule for ' . CFP_DEV_EVENT_NAME . '.">';
    } elseif (is_page('talks-by-tracks')) {
        echo '<meta name="description" content="Browse talks by track at ' . CFP_DEV_EVENT_NAME . '.">';
    } elseif (is_page('talks-by-sessions')) {
        echo '<meta name="description" content="Browse talks by session type at ' . CFP_DEV_EVENT_NAME . '.">';
    } elseif (is_page('search-results')) {
        echo '<meta name="description" content="Search results for ' . esc_html($_GET['query']) . ' at ' . CFP_DEV_EVENT_NAME . '.">';
    }
}
add_action('wp_head', 'add_meta_description');

function getSocialLinks($speaker) {
    $content = '';
    if (!empty($speaker->twitterHandle) ||
        !empty($speaker->linkedInUsername) ||
        !empty($speaker->blueskyUsername) ||
        !empty($speaker->mastodonUsername)) {
        $content .= '<nav class="cfp-social">';
        if (!empty($speaker->linkedInUsername)) {
            $content .= '<a class="cfp-a cfp-linkedIn" href="https://www.linkedin.com/in/' . esc_attr($speaker->linkedInUsername) . '" target="_blank"></a>';
        }
        if (!empty($speaker->blueskyUsername)) {
            $content .= '<a class="cfp-a cfp-bluesky" href="https://bsky.app/profile/' . esc_attr($speaker->blueskyUsername) . '" target="_blank"></a>';
        }
        if (!empty($speaker->mastodonUsername)) {
            $content .= '<a class="cfp-a cfp-mastodon" href="' . esc_attr($speaker->mastodonUsername) . '" target="_blank"></a>';
        }
        if (!empty($speaker->twitterHandle)) {
            $content .= '<a class="cfp-a cfp-twitter" href="https://twitter.com/' . esc_attr($speaker->twitterHandle) . '" target="_blank"></a>';
        }
        $content .= '</nav>';
    }
    return $content;
}
