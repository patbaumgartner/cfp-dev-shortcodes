<?php
/**
 * CFP.DEV shortcodes
 *
 * [cfp_search_results]  Exact and semantic search results for the ?query= parameter.
 *
 * @package  CFP.DEV
 * @since    1.0.0
 */
if ( ! function_exists( 'cfp_search_results_shortcode' ) ) {

	add_action(
		'plugins_loaded',
		function () {

			if ( ! shortcode_exists( 'cfp_search_results' ) ) {
				add_shortcode( 'cfp_search_results', 'cfp_search_results_shortcode' );
			}
		}
	);

	/**
	 * Shortcode handler for [cfp_search_results].
	 *
	 * Reads the query parameter from the URL and renders exact and semantic
	 * search results.
	 *
	 * @return string
	 * @since  1.0.0
	 */
	function cfp_search_results_shortcode() {

		$query   = sanitize_text_field( (string) get_query_var( 'query' ) );
		$content = '';

		if ( ! empty( $query ) ) {

			$exactSearchResult = getJSON( 'public/search?query=' . rawurlencode( $query ) );
			$semanticResult    = searchJSON( $query );

			$content  = cfp_dev_root_class_script( 'search' );
			$content .= '<div class="cfp-main">';

			$content .= '<section class="cfp-search">';
			$content .= '	<div class="cfp-subject">';
			$content .= '		<div class="cfp-primary">';

			$content .= '           <div class="cfp-name">Search results for <em>' . esc_html( $query ) . '</em></div>';

			$content .= getSearchForm();

			$content .= '		</div>';
			$content .= '	</div>';

			$content .= '	<div class="cfp-content">';

			if ( ! empty( $exactSearchResult->proposals ) ) {
				$use_slugs = ( 'no' === get_option( 'cfp_dev_content_by_id', 'yes' ) );
				foreach ( $exactSearchResult->proposals as $talk ) {
					$content .= '	<article class="cfp-article">';
					$content .= '		<div class="cfp-foreword">';
					$content .= '			<div class="cfp-name">' . esc_html( $talk->title ) . '</div>';
					$content .= '			<div class="cfp-type">' . esc_html( $talk->sessionType->name ) . ' - <em>' . esc_html( $talk->audienceLevel ) . ' LEVEL</em></div>';
					$content .= '        	<div class="cfp-track" style="background-image: url(' . esc_url( $talk->track->imageURL ) . ')"></div>';

					$content .= '		</div>';
					$content .= '		<div class="cfp-block">';
					if ( ! empty( $talk->speakers ) ) {
						foreach ( $talk->speakers as $speaker ) {
							$content .= '		<div class="cfp-person">';
							if ( $use_slugs ) {
								$speaker_slug = generate_slug( $speaker->firstName . '-' . $speaker->lastName );
								$content     .= '<a class="cfp-a" href="' . esc_url( cfp_dev_url( "/speaker/{$speaker_slug}" ) ) . '">';
							} else {
								$content .= '<a class="cfp-a" href="' . esc_url( cfp_dev_url( '/speaker?id=' . absint( $speaker->id ) ) ) . '">';
							}
							$content .= '    			<div class="cfp-picture" style="background-image: url(' . esc_url( $speaker->imageUrl ) . ')"></div>';
							$content .= '				<div class="cfp-name">' . esc_html( $speaker->firstName . ' ' . $speaker->lastName ) . '</div>';
							if ( ! empty( $speaker->company ) ) {
								$content .= '			<div class="cfp-company">' . esc_html( $speaker->company ) . '</div>';
							}
							$content .= '			</a>';
							$content .= '		</div>';
						}
					}
					$content .= '		</div>';
					if ( $use_slugs ) {
						$content .= '        <a class="cfp-button" href="' . esc_url( cfp_dev_url( '/talk/' . generate_slug( $talk->title ) ) ) . '">View</a>';
					} else {
						$content .= '        <a class="cfp-button" href="' . esc_url( cfp_dev_url( '/talk?id=' . absint( $talk->id ) ) ) . '">View</a>';
					}
					$content .= '	</article>';
				}
			}

			if ( ! empty( $exactSearchResult->speakers ) ) {
				$use_slugs = ( 'no' === get_option( 'cfp_dev_content_by_id', 'yes' ) );
				foreach ( $exactSearchResult->speakers as $speaker ) {
					$content .= '	<article class="cfp-article">';
					$content .= '		<div class="cfp-block">';
					$content .= '			<div class="cfp-person">';
					if ( $use_slugs ) {
						$speaker_slug = generate_slug( $speaker->firstName . '-' . $speaker->lastName );
						$content     .= '        	<a class="cfp-a" href="' . esc_url( cfp_dev_url( "/speaker/{$speaker_slug}" ) ) . '">';
					} else {
						$content .= '        	<a class="cfp-a" href="' . esc_url( cfp_dev_url( '/speaker?id=' . absint( $speaker->id ) ) ) . '">';
					}
					$content .= '    			<div class="cfp-picture" style="background-image: url(' . esc_url( $speaker->imageUrl ) . ')"></div>';
					$content .= '				<div class="cfp-name">' . esc_html( $speaker->firstName . ' ' . $speaker->lastName ) . '</div>';
					if ( ! empty( $speaker->company ) ) {
						$content .= '<div class="cfp-company">' . esc_html( $speaker->company ) . '</div>';
					}
					$content .= '				</a>';
					$content .= '			</div>';
					$content .= '		</div>';
					$content .= '	</article>';
				}
			}

			if ( empty( $semanticResult ) ) {
				$content .= '<article class="cfp-article">';
				$content .= '	<p>No semantic results</p>';
				$content .= '</article>';
			} else {
				$use_slugs = ( 'no' === get_option( 'cfp_dev_content_by_id', 'yes' ) );
				foreach ( $semanticResult as $item ) {
					if ( ! str_contains( strtolower( $item->title ), 'overflow' ) ) {
						$content .= '<article class="cfp-article">';
						$content .= '	<div class="cfp-foreword">';
						$content .= '		<div class="cfp-name">' . esc_html( $item->title ) . '</div>';
						$content .= '		<div class="cfp-type">Similarity score = ' . esc_html( number_format( (float) $item->score, 2 ) ) . '</div>';
						if ( $use_slugs ) {
							$content .= '   	<a class="cfp-button" href="' . esc_url( cfp_dev_url( '/talk/' . generate_slug( $item->title ) ) ) . '">More</a>';
						} else {
							$content .= '   	<a class="cfp-button" href="' . esc_url( cfp_dev_url( '/talk?id=' . absint( $item->id ) ) ) . '">More</a>';
						}
						$content .= '	</div>';
						$content .= '</article>';
					}
				}
			}

			$content .= '<article class="cfp-article">';
			$content .= '	<div class="cfp-foreword">';
			$content .= '       <div class="cfp-score-info">As the similarity <strong>score</strong> approaches zero, the match becomes increasingly accurate.</div>';
			$content .= '	</div>';
			$content .= '</article>';
		} else {
			$content = '<p>No search query provided.</p>';
		}
		$content .= '</div>';

		$content .= '</section>';
		$content .= '</div>';

		$content .= getFooter();
		return $content;
	}
}
