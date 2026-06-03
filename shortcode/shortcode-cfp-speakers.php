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

if ( ! function_exists( 'cfp_speakers_shortcode' ) ) {

    add_action( 'plugins_loaded', function() {

        if (!shortcode_exists('cfp_speakers')) {
            add_shortcode('cfp_speakers', 'cfp_speakers_shortcode');
        }
    });

    add_action( 'wp_enqueue_scripts', function() {
        $plugin_url = plugin_dir_url( __FILE__ );

        wp_enqueue_style( 'style1', $plugin_url . CFP_DEV_CSS );
    });

    function cfp_speakers_shortcode( $atts ) {
        $boolean_default = __( false );
        $size_default = __( 300 );
        $title_default = __( '' );
        $subTitle_default = __( '' );

        $_atts = shortcode_atts( array(
            'random'  => $boolean_default,
            'size' => $size_default,
            'title' => $title_default,
            'subtitle' => $subTitle_default,
            'hide_search'=> $boolean_default
        ), $atts );

        $_size = $_atts['size'];

        // Check if caching is disabled
        if (CFP_DEV_CACHE == 0) {
            $data = getJSON('public/speakers?size='. $_size);
            $content = generate_speakers_content($data, $_atts);
        } else {
            $_cache_group = 'speakers_cache_group';

            if ( false === ( $cache = get_transient( $_cache_group ) ) ) {
                $data = getJSON('public/speakers?size='. $_size);
                $content = generate_speakers_content($data, $_atts);
                set_transient( $_cache_group, $content, CFP_DEV_CACHE );
            } else {
                $content = $cache;
            }
        }

        return $content;
    }

    function generate_speakers_content($data, $_atts) {
        $content = '';
        if ( !empty($data) ) {

            $_random = $_atts['random'];
            if ( $_random ) {
                shuffle($data);
            } else {
                setlocale(LC_CTYPE, 'en_US.UTF8');
                usort($data, 'compareLastName');
            }
            $content .= '<script>';
            $content .= 'const qs = document.querySelector(":root");';
            $content .= 'qs.classList.forEach(value => {';
            $content .= '   if (value.startsWith("cfp-")) {';
            $content .= '       qs.classList.remove(value);';
            $content .= '   }';
            $content .= '});';
            $content .= 'qs.classList.add("cfp-page:speaker");';
            $content .= 'qs.classList.add("cfp-html");';
            $content .= 'qs.classList.add("cfp-theme:' . get_option('cfp_dev_default_theme', 'dark') .'");';
            $content .= '</script>';

            $content .= '<main class="cfp-main">';
            $content .= '<section class="cfp-speaker">';
            $content .= '    <div class="cfp-subject">';
            $content .= '        <div class="cfp-primary">';

            $_title = $_atts['title'];
            if ($_title !== null) {
                $content .= '            <div class="cfp-name">' . $_title . '</div>';
            } else {
                $content .= '            <div class="cfp-name">Speakers</div>';
            }

            $hide_search = $_atts['hide_search'];

            if (!$hide_search) {
                $content .= getSearchForm();
            }

            $content .= '        </div>';
            $content .= '    </div>';
            $content .= '    <div class="cfp-block">';

            foreach ($data as $speaker) {
                $content .= ' <div class="cfp-person">';
                $speaker_slug = generate_slug($speaker->firstName . '-' . $speaker->lastName);
                $get_option = get_option('cfp_dev_content_by_id', 'yes');
                if ($get_option == 'no') {
                    $content .= '<a class="cfp-a" href="' . cfp_dev_url("/speaker/{$speaker_slug}") . '">';
                } else {
                    $content .= '<a class="cfp-a" href="' . cfp_dev_url("/speaker?id=$speaker->id") . '">';
                }
                $content .= '           <div class="cfp-picture" style="background-image: url(' . esc_url($speaker->imageUrl) . ')"></div>';
                $content .= '        <div class="cfp-name">' . $speaker->firstName . ' ' . $speaker->lastName. '</div>';
                if (!empty($speaker->company)) {
                    $content .= '        <div class="cfp-company">' . $speaker->company . '</div>';
                }
                $content .= '    </a>';
                $content .= ' </div>';
            }

            $content .= '</div>';
            $content .= '</section>';
            $content .= '</main>';

            $content .= getFooter();
        }

        return $content;
    }
} // End if().
