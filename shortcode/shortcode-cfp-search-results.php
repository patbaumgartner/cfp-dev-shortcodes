<?php
/**
 * CFP.DEV shortcodes
 *
 * [cfp_search_results]  Search results place holder
 *
 * @package	 CFP.DEV
 * @since    1.0.0
 */
if ( ! function_exists( 'cfp_search_results_shortcode' ) ) {

    add_action('plugins_loaded', function () {

        if (!shortcode_exists('cfp_search_results_shortcode')) {
            // Add the shortcode.
            add_shortcode('cfp_search_results', 'cfp_search_results_shortcode');
        }
    });

    add_filter('query_vars', function ($vars) {
        $vars[] = "query";
        return $vars;
    });

    add_action('wp_enqueue_scripts', function () {
        $plugin_url = plugin_dir_url(__FILE__);

        wp_enqueue_style('style1', $plugin_url . CFP_DEV_CSS);
    });

    /**
     * Shortcode CFP search results
     *
     * @return string
     * @since  1.0.0
     */
    function cfp_search_results_shortcode( ) {

        $query = get_query_var('query');

        if ( !empty( $query )) {

            $exactSearchResult = getJSON('public/search?query=' . urlencode($query));
            $semanticResult = searchJSON($query);

            $content = '<script>';
            $content .= 'const qs = document.querySelector(":root");';
            $content .= 'qs.classList.forEach(value => {';
            $content .= '   if (value.startsWith("cfp-")) {';
            $content .= '       qs.classList.remove(value);';
            $content .= '   }';
            $content .= '});';
            $content .= 'qs.classList.add("cfp-page:search");';
            $content .= 'qs.classList.add("cfp-html");';
            $content .= 'qs.classList.add("cfp-theme:' . get_option('cfp_dev_default_theme', 'dark') . '");';
            $content .= '</script>';

            $content .= '<main class="cfp-main">';

            $content .= '<!-- search -->';
            $content .= '<section class="cfp-search">';
            $content .= '	<div class="cfp-subject">';
            $content .= '		<div class="cfp-primary">';

            $content .= '           <div class="cfp-name">Search results for <em>' . $query . '</em></div>';

            $content .= getSearchForm();

            $content .= '		</div>';
            $content .= '	</div>';

            $content .= '	<div class="cfp-content">';

            if (!empty($exactSearchResult->proposals)) {
                foreach ($exactSearchResult->proposals as $talk) {
                    $content .= '	<article class="cfp-article">';
                    $content .= '		<div class="cfp-foreword">';
                    $content .= '			<div class="cfp-name">' . $talk->title . '</div>';
                    $content .= '			<div class="cfp-type">' . $talk->sessionType->name . ' - <em>' . $talk->audienceLevel . ' LEVEL</em></div>';
                    $content .= '        	<div class="cfp-track" style="background-image: url(' . esc_url($talk->track->imageURL) . ')"></div>';

                    $content .= '		</div>';

// 					if (!empty($talk->afterVideoURL)) {
// 	                    $content .= '		<div class="cfp-video">';
//     	                $content .= '			<button class="cfp-play"></button>';
//         	            $content .= '			<div class="cfp-picture"></div>';
//             	        $content .= '			<div class="cfp-player"></div>';
//                 	    $content .= '		</div>';
// 					}

                    $content .= '		<div class="cfp-block">';
                    if (!empty($talk->speakers)) {
                        foreach ($talk->speakers as $speaker) {
                            $content .= '		<div class="cfp-person">';
                            if (get_option('cfp_dev_content_by_id', 'yes') == 'no') {
                                $speaker_slug = generate_slug($speaker->firstName . '-' . $speaker->lastName);
                                $content .= '<a class="cfp-a" href="' . cfp_dev_url("/speaker/{$speaker_slug}") . '">';
                            } else {
                                $content .= '<a class="cfp-a" href="' . cfp_dev_url("/speaker?id=$speaker->id") . '">';
                            }
                            $content .= '    			<div class="cfp-picture" style="background-image: url(' . esc_url($speaker->imageUrl) . ')"></div>';
                            $content .= '				<div class="cfp-name">' . $speaker->firstName . ' ' . $speaker->lastName . '</div>';
                            if (!empty($speaker->company)) {
                                $content .= '			<div class="cfp-company">' . $speaker->company . '</div>';
                            }
                            $content .= '			</a>';
                            $content .= '		</div>';
                        }
                    }
                    $content .= '		</div>';
                    if (get_option('cfp_dev_content_by_id', 'yes') == 'no') {
                        $content .= '        <a class="cfp-button" href="' . cfp_dev_url('/talk/' . generate_slug($talk->title)) . '">View</a>';
                    } else {
                        $content .= '        <a class="cfp-button" href="' . cfp_dev_url('/talk?id=' . $talk->id) . '">View</a>';
                    }
                    $content .= '	</article>';
                }
            }

            if (!empty($exactSearchResult->speakers)) {
                foreach ($exactSearchResult->speakers as $speaker) {
                    $content .= '	<article class="cfp-article">';
                    $content .= '		<div class="cfp-block">';
                    $content .= '			<div class="cfp-person">';
                    if (get_option('cfp_dev_content_by_id', 'yes') == 'no') {
                      $speaker_slug = generate_slug($speaker->firstName . '-' . $speaker->lastName);
                      $content .= '        	<a class="cfp-a" href="' . cfp_dev_url("/speaker/{$speaker_slug}") . '">';
                    } else {
                      $content .= '        	<a class="cfp-a" href="' . cfp_dev_url("/speaker?id=$speaker->id") . '">';
                    }
                    $content .= '    			<div class="cfp-picture" style="background-image: url(' . esc_url($speaker->imageUrl) . ')"></div>';
                    $content .= '				<div class="cfp-name">' . $speaker->firstName . ' ' . $speaker->lastName . '</div>';
                    if (!empty($speaker->company)) {
                        $content .= '<div class="cfp-company">' . $speaker->company . '</div>';
                    }
                    $content .= '				</a>';
                    $content .= '			</div>';
                    $content .= '		</div>';
                    $content .= '	</article>';
                }
            }

            if (empty($semanticResult)) {
                $content .= '<article class="cfp-article">';
                $content .= '	<p>No semantic results</p>';
                $content .= '</article>';
            } else {
                foreach ($semanticResult as $item) {
                    if (strpos(strtolower($item->title), 'overflow') === false)
                        $content .= '<article class="cfp-article">';
                    $content .= '	<div class="cfp-foreword">';
                    $content .= '		<div class="cfp-name">' . $item->title . '</div>';
                    $content .= '		<div class="cfp-type">Similarity score = ' . number_format($item->score, 2) . '</div>';
                    if (get_option('cfp_dev_content_by_id', 'yes') == 'no') {
                      $content .= '   	<a class="cfp-button" href="' . cfp_dev_url('/talk/' . generate_slug($item->title)) . '">More</a>';
                    } else {
                      $content .= '   	<a class="cfp-button" href="' . cfp_dev_url('/talk?id=' . $item->id) . '">More</a>';
                    }

                  $content .= '	</div>';
                    $content .= '</article>';
                }
            }

            $content .= '<article class="cfp-article">';
            $content .= '	<div class="cfp-foreword">';
            $content .= '       <div class="cfp-score-info">As the similarity <strong>score</strong> approaches zero, the match becomes increasingly accurate.</div>';
            $content .= '	</div>';
            $content .= '</article>';
        }

        else {
            $content = '<p>No search query provided.</p>';
        }
        $content .= '</div>';	// cfp-content

        $content .= '</section>';
        $content .= '</main>';

        $content .= getFooter();
        return $content;
    }
} // End if().
?>
