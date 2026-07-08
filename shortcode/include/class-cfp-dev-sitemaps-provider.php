<?php
/**
 * CFP.DEV shortcodes
 *
 * Sitemap provider for CFP.DEV talk and speaker detail URLs. WordPress only
 * lists its own pages — the talk and speaker URLs are rendered from API data
 * and invisible to wp-sitemap.xml. This provider adds them (slug mode only),
 * using the same cached, offline-aware fetches as the shortcodes (see
 * cfp_dev_sitemap_urls() in the main plugin file).
 *
 * @package  CFP.DEV
 * @since    4.4.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( class_exists( 'WP_Sitemaps_Provider' ) && ! class_exists( 'CFP_Dev_Sitemaps_Provider' ) ) {

	/**
	 * Sitemap provider exposing /talk/<slug> and /speaker/<slug> URLs.
	 */
	class CFP_Dev_Sitemaps_Provider extends WP_Sitemaps_Provider {

		public function __construct() {
			$this->name        = 'cfp';
			$this->object_type = 'cfp';
		}

		/**
		 * Returns the sitemap entries (all fit in one page).
		 *
		 * @param int    $page_num        Sitemap page number.
		 * @param string $object_subtype  Unused.
		 * @return array[]
		 */
		public function get_url_list( $page_num, $object_subtype = '' ) {
			unset( $object_subtype );
			return ( $page_num > 1 ) ? [] : cfp_dev_sitemap_urls();
		}

		/**
		 * Returns the number of sitemap pages.
		 *
		 * @param string $object_subtype  Unused.
		 * @return int
		 */
		public function get_max_num_pages( $object_subtype = '' ) {
			unset( $object_subtype );
			return empty( cfp_dev_sitemap_urls() ) ? 0 : 1;
		}
	}
}
