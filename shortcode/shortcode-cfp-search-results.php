<?php
/**
 * CFP.DEV shortcodes
 *
 * [cfp_search_results]  Exact and semantic search results for the ?query= parameter.
 *
 * @package  CFP.DEV
 * @since    1.0.0
 */
if ( ! function_exists( 'cfp_dev_search_results_shortcode' ) ) {

	add_action(
		'plugins_loaded',
		function () {

			if ( ! shortcode_exists( 'cfp_search_results' ) ) {
				add_shortcode( 'cfp_search_results', 'cfp_dev_search_results_shortcode' );
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
	function cfp_dev_search_results_shortcode() {
		cfp_dev_shortcode_assets();
		$query = sanitize_text_field( (string) get_query_var( 'query' ) );

		$heading = '' !== $query
			? sprintf(
				/* translators: %s: search query. */
				__( 'Search results for <em>%s</em>', 'cfp-dev-shortcodes' ),
				esc_html( $query )
			)
			: esc_html__( 'Search the programme', 'cfp-dev-shortcodes' );

		$content  = cfp_dev_root_class_script( 'search' );
		$content .= '<div class="cfp-main">';
		$content .= '<section class="cfp-search">';
		$content .= '	<div class="cfp-subject">';
		$content .= '		<div class="cfp-primary">';
		$content .= '           <div class="cfp-name"' . cfp_dev_heading( 2 ) . '>' . $heading . '</div>';
		$content .= cfp_dev_search_form();
		$content .= '		</div>';
		$content .= '	</div>';
		$content .= '	<div class="cfp-content">';

		$content .= '' !== $query
			? cfp_dev_search_results_body( $query )
			: '<article class="cfp-article"><p>' . esc_html__( 'Enter a search term to find talks and speakers.', 'cfp-dev-shortcodes' ) . '</p></article>';

		$content .= '	</div>';
		$content .= '</section>';
		$content .= '</div>';
		$content .= cfp_dev_footer();
		return $content;
	}

	/**
	 * Renders the result articles: exact keyword matches (talks and speakers)
	 * followed by semantic similarity matches.
	 *
	 * @param string $query  Sanitised search term.
	 * @return string
	 */
	function cfp_dev_search_results_body( $query ) {
		$exact_search_result = cfp_dev_get_json( 'public/search?query=' . rawurlencode( $query ) );
		$semantic_result     = cfp_dev_search_json( $query );
		$content             = '';

		if ( ! empty( $exact_search_result->proposals ) ) {
			foreach ( $exact_search_result->proposals as $talk ) {
				$content .= '	<article class="cfp-article">';
				$content .= '		<div class="cfp-foreword">';
				$content .= '			<div class="cfp-name"' . cfp_dev_heading( 3 ) . '>' . esc_html( (string) ( $talk->title ?? '' ) ) . '</div>';
				/* translators: %s: audience level. */
				$content .= '			<div class="cfp-type">' . esc_html( (string) ( $talk->sessionType->name ?? '' ) ) . ' - <em>' . esc_html( sprintf( __( '%s LEVEL', 'cfp-dev-shortcodes' ), (string) ( $talk->audienceLevel ?? '' ) ) ) . '</em></div>';
				$content .= '        	<div class="cfp-track" style="background-image: url(\'' . esc_url( (string) ( $talk->track->imageURL ?? $talk->trackImageURL ?? '' ) ) . '\')"></div>';
				$content .= '		</div>';
				$content .= '		<div class="cfp-block">';
				foreach ( (array) ( $talk->speakers ?? [] ) as $speaker ) {
					$content .= cfp_dev_search_speaker_card( $speaker );
				}
				$content .= '		</div>';
				$content .= '        <a class="cfp-button" href="' . esc_url( cfp_dev_talk_url( $talk ) ) . '">' . esc_html__( 'View', 'cfp-dev-shortcodes' ) . '</a>';
				$content .= '	</article>';
			}
		}

		if ( ! empty( $exact_search_result->speakers ) ) {
			foreach ( $exact_search_result->speakers as $speaker ) {
				$content .= '	<article class="cfp-article">';
				$content .= '		<div class="cfp-block">';
				$content .= cfp_dev_search_speaker_card( $speaker );
				$content .= '		</div>';
				$content .= '	</article>';
			}
		}

		if ( empty( $semantic_result ) ) {
			$content .= '<article class="cfp-article">';
			$content .= '	<p>' . esc_html__( 'No semantic results', 'cfp-dev-shortcodes' ) . '</p>';
			$content .= '</article>';
		} else {
			foreach ( $semantic_result as $item ) {
				if ( str_contains( strtolower( (string) ( $item->title ?? '' ) ), 'overflow' ) ) {
					continue;
				}
				$content .= '<article class="cfp-article">';
				$content .= '	<div class="cfp-foreword">';
				$content .= '		<div class="cfp-name"' . cfp_dev_heading( 3 ) . '>' . esc_html( (string) ( $item->title ?? '' ) ) . '</div>';
				/* translators: %s: similarity score. */
				$content .= '		<div class="cfp-type">' . esc_html( sprintf( __( 'Similarity score = %s', 'cfp-dev-shortcodes' ), number_format( (float) ( $item->score ?? 0 ), 2 ) ) ) . '</div>';
				$content .= '   	<a class="cfp-button" href="' . esc_url( cfp_dev_talk_url( $item ) ) . '">' . esc_html__( 'More', 'cfp-dev-shortcodes' ) . '</a>';
				$content .= '	</div>';
				$content .= '</article>';
			}
		}

		$content .= '<article class="cfp-article">';
		$content .= '	<div class="cfp-foreword">';
		$content .= '       <div class="cfp-score-info">' . wp_kses_post( __( 'As the similarity <strong>score</strong> approaches zero, the match becomes increasingly accurate.', 'cfp-dev-shortcodes' ) ) . '</div>';
		$content .= '	</div>';
		$content .= '</article>';

		return $content;
	}

	/**
	 * Renders one linked speaker tile (photo, name, company).
	 *
	 * @param object $speaker  Speaker object from the search response.
	 * @return string
	 */
	function cfp_dev_search_speaker_card( $speaker ) {
		$content  = '		<div class="cfp-person">';
		$content .= '        	<a class="cfp-a" href="' . esc_url( cfp_dev_speaker_url( $speaker ) ) . '">';
		$content .= '    			<div class="cfp-picture" style="background-image: url(\'' . esc_url( (string) ( $speaker->imageUrl ?? '' ) ) . '\')"></div>';
		$content .= '				<div class="cfp-name"' . cfp_dev_heading( 3 ) . '>' . esc_html( trim( ( $speaker->firstName ?? '' ) . ' ' . ( $speaker->lastName ?? '' ) ) ) . '</div>';
		if ( ! empty( $speaker->company ) ) {
			$content .= '			<div class="cfp-company">' . esc_html( $speaker->company ) . '</div>';
		}
		$content .= '			</a>';
		$content .= '		</div>';
		return $content;
	}
}
