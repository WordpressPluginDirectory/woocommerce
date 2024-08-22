<?php
/**
 * WCAdminHelper
 *
 * Helper class for generic WCAdmin functions.
 */

namespace Automattic\WooCommerce\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Class WCAdminHelper
 */
class WCAdminHelper {
	/**
	 * WC Admin timestamp option name.
	 */
	const WC_ADMIN_TIMESTAMP_OPTION = 'woocommerce_admin_install_timestamp';

	const WC_ADMIN_STORE_AGE_RANGES = array(
		'week-1'    => array(
			'start' => 0,
			'end'   => WEEK_IN_SECONDS,
		),
		'week-1-4'  => array(
			'start' => WEEK_IN_SECONDS,
			'end'   => WEEK_IN_SECONDS * 4,
		),
		'month-1-3' => array(
			'start' => MONTH_IN_SECONDS,
			'end'   => MONTH_IN_SECONDS * 3,
		),
		'month-3-6' => array(
			'start' => MONTH_IN_SECONDS * 3,
			'end'   => MONTH_IN_SECONDS * 6,
		),
		'month-6+'  => array(
			'start' => MONTH_IN_SECONDS * 6,
		),
	);

	/**
	 * Get the number of seconds that the store has been active.
	 *
	 * @return number Number of seconds.
	 */
	public static function get_wcadmin_active_for_in_seconds() {
		$install_timestamp = get_option( self::WC_ADMIN_TIMESTAMP_OPTION );

		if ( ! is_numeric( $install_timestamp ) ) {
			$install_timestamp = time();
			update_option( self::WC_ADMIN_TIMESTAMP_OPTION, $install_timestamp );
		}

		return time() - $install_timestamp;
	}


	/**
	 * Test how long WooCommerce Admin has been active.
	 *
	 * @param int $seconds Time in seconds to check.
	 * @return bool Whether or not WooCommerce admin has been active for $seconds.
	 */
	public static function is_wc_admin_active_for( $seconds ) {
		$wc_admin_active_for = self::get_wcadmin_active_for_in_seconds();

		return ( $wc_admin_active_for >= $seconds );
	}

	/**
	 * Test if WooCommerce Admin has been active within a pre-defined range.
	 *
	 * @param string $range range available in WC_ADMIN_STORE_AGE_RANGES.
	 * @param int    $custom_start custom start in range.
	 * @throws \InvalidArgumentException Throws exception when invalid $range is passed in.
	 * @return bool Whether or not WooCommerce admin has been active within the range.
	 */
	public static function is_wc_admin_active_in_date_range( $range, $custom_start = null ) {
		if ( ! array_key_exists( $range, self::WC_ADMIN_STORE_AGE_RANGES ) ) {
			throw new \InvalidArgumentException(
				sprintf(
					'"%s" range is not supported, use one of: %s',
					$range,
					implode( ', ', array_keys( self::WC_ADMIN_STORE_AGE_RANGES ) )
				)
			);
		}
		$wc_admin_active_for = self::get_wcadmin_active_for_in_seconds();

		$range_data = self::WC_ADMIN_STORE_AGE_RANGES[ $range ];
		$start      = null !== $custom_start ? $custom_start : $range_data['start'];
		if ( $range_data && $wc_admin_active_for >= $start ) {
			return isset( $range_data['end'] ) ? $wc_admin_active_for < $range_data['end'] : true;
		}
		return false;
	}

	/**
	 * Test if the site is fresh. A fresh site must meet the following requirements.
	 *
	 * - The current user was registered less than 1 month ago.
	 * - fresh_site option must be 1
	 *
	 * @return bool
	 */
	public static function is_site_fresh() {
		$fresh_site = get_option( 'fresh_site' );
		if ( '1' !== $fresh_site ) {
			return false;
		}

		$current_userdata = get_userdata( get_current_user_id() );
		// Return false if we can't get user meta data for some reason.
		if ( ! $current_userdata ) {
			return false;
		}

		$date      = new \DateTime( $current_userdata->user_registered );
		$month_ago = new \DateTime( '-1 month' );

		return $date > $month_ago;
	}

	/**
	 * Test if a URL is a store page. This function ignores the domain and protocol of the URL and only checks the path and query string.
	 *
	 * Store pages are defined as:
	 *
	 * - Shop
	 * - Cart
	 * - Checkout
	 * - Privacy Policy
	 * - Terms and Conditions
	 *
	 * Additionally, the following autogenerated pages should be included:
	 * - Product pages
	 * - Product Category pages
	 * - Product Tag pages
	 *
	 * @param string $url URL to check. If not provided, the current URL will be used.
	 * @return bool Whether or not the URL is a store page.
	 */
	public static function is_store_page( $url = '' ) {
		$url = $url ? $url : esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );

		if ( ! $url ) {
			return false;
		}
		$normalized_path = self::get_normalized_url_path( $url );

		// WC store pages.
		$store_pages = array(
			'shop'        => wc_get_page_id( 'shop' ),
			'cart'        => wc_get_page_id( 'cart' ),
			'checkout'    => wc_get_page_id( 'checkout' ),
			'terms'       => wc_terms_and_conditions_page_id(),
			'coming_soon' => wc_get_page_id( 'coming_soon' ),
		);

		/**
		 * Filter the store pages array to check if a URL is a store page.
		 *
		 * @since 8.8.0
		 * @param array $store_pages The store pages array. The keys are the page slugs and the values are the page IDs.
		 */
		$store_pages = apply_filters( 'woocommerce_store_pages', $store_pages );

		// If the shop page is not set, we will still show the product archive page.
		// Therefore, we need to check if the URL is a product archive page when the shop page is not set.
		if ( $store_pages['shop'] <= 0 ) {
			$product_post_archive_link = get_post_type_archive_link( 'product' );

			if ( is_string( $product_post_archive_link ) &&
				0 === strpos( $normalized_path, self::get_normalized_url_path( $product_post_archive_link ) )
			) {
				return true;
			}
		}

		foreach ( $store_pages as $page => $page_id ) {
			if ( 0 >= $page_id ) {
				continue;
			}

			$permalink = get_permalink( $page_id );
			if ( ! $permalink ) {
				continue;
			}

			if ( 0 === strpos( $normalized_path, self::get_normalized_url_path( $permalink ) ) ) {
				return true;
			}
		}

		// Check product, category and tag pages.
		$permalink_structure = wc_get_permalink_structure();
		$permalink_keys      = array(
			'category_base',
			'tag_base',
			'product_base',
		);

		foreach ( $permalink_keys as $key ) {
			if ( ! isset( $permalink_structure[ $key ] ) || ! is_string( $permalink_structure[ $key ] ) ) {
				continue;
			}

			// Check if the URL path starts with the matching base.
			if ( 0 === strpos( $normalized_path, trim( $permalink_structure[ $key ], '/' ) ) ) {
				return true;
			}

			// If the permalink structure contains placeholders, we need to check if the URL matches the structure using regex.
			if ( strpos( $permalink_structure[ $key ], '%' ) !== false ) {
				global $wp_rewrite;
				$rules = $wp_rewrite->generate_rewrite_rule( $permalink_structure[ $key ] );

				if ( is_array( $rules ) && ! empty( $rules ) ) {
					// rule key is the regex pattern.
					$rule = array_keys( $rules )[0];
					$rule = '#^' . str_replace( '?$', '', $rule ) . '#';

					if ( preg_match( $rule, $normalized_path ) ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Get normalized URL path.
	 * 1. Only keep the path and query string (if any).
	 * 2. Remove wp home path from the URL path if WP is installed in a subdirectory.
	 * 3. Remove leading and trailing slashes.
	 *
	 * For example:
	 *
	 * - https://example.com/wordpress/shop/uncategorized/test/?add-to-cart=123 => shop/uncategorized/test/?add-to-cart=123
	 *
	 * @param string $url URL to normalize.
	 */
	private static function get_normalized_url_path( $url ) {
		$query           = wp_parse_url( $url, PHP_URL_QUERY );
		$path            = wp_parse_url( $url, PHP_URL_PATH ) . ( $query ? '?' . $query : '' );
		$home_path       = wp_parse_url( site_url(), PHP_URL_PATH ) ?? '';
		$normalized_path = trim( substr( $path, strlen( $home_path ) ), '/' );
		return $normalized_path;
	}
}
