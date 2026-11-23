<?php
/**
 * Test bootstrap: Composer autoloader and minimal stand-ins for WordPress functions used by pure classes.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

require dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * Stand-in for wp_json_encode().
	 *
	 * @param mixed $value Value.
	 * @param int   $flags Flags.
	 * @return string|false
	 */
	function wp_json_encode( $value, $flags = 0 ) {
		return json_encode( $value, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * Stand-in for wp_parse_url().
	 *
	 * @param string $url       URL.
	 * @param int    $component Component.
	 * @return mixed
	 */
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
	}
}
