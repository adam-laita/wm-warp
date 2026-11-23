<?php
/**
 * Automatic find & replace rules.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Migration;

use WM\Warp\Database\ReplaceRule;

/**
 * Rules every migration offers: the domain (with the site path, without protocol), the path on disk
 * and the protocol of the target. Pure logic without WordPress calls.
 */
final class Rules {

	/**
	 * Domain rule: "//source.cz/sub" → "//target.cz". Empty when both sites have the same address.
	 *
	 * @param string $source_home Home URL of the source.
	 * @param string $target_home Home URL of the target.
	 * @return list<ReplaceRule>
	 */
	public static function domain( string $source_home, string $target_home ): array {
		$source = self::without_scheme( $source_home );
		$target = self::without_scheme( $target_home );

		if ( '' === $source || '' === $target || strtolower( $source ) === strtolower( $target ) ) {
			return array();
		}

		return array( new ReplaceRule( $source, $target, false, false, true ) );
	}

	/**
	 * Path rules: the WordPress folder of the source → that of the target, with / and \ variants on Windows.
	 *
	 * @param string $source_path Path of the source.
	 * @param string $target_path Path of the target.
	 * @return list<ReplaceRule>
	 */
	public static function path( string $source_path, string $target_path ): array {
		$source = rtrim( $source_path, '/\\' );
		$target = rtrim( $target_path, '/\\' );

		if ( '' === $source || '' === $target || $source === $target ) {
			return array();
		}

		$variants = array( $source => $target );

		// A Windows source path may be stored with either kind of slash.
		if ( str_contains( $source, '\\' ) ) {
			$variants += array(
				str_replace( '\\', '/', $source ) => str_replace( '\\', '/', $target ),
				str_replace( '/', '\\', $source ) => str_replace( '/', '\\', $target ),
			);
		}

		$rules = array();
		foreach ( $variants as $find => $replace ) {
			$rules[] = new ReplaceRule( (string) $find, $replace, false, true, true );
		}

		return $rules;
	}

	/**
	 * Protocol rule: addresses of the target get the protocol of the target ("http://target" → "https://target").
	 *
	 * Only the target's own domain changes; links to other sites and identifiers such as
	 * http://www.w3.org/2000/svg stay as they are.
	 *
	 * @param string $target_home Home URL of the target.
	 * @return list<ReplaceRule>
	 */
	public static function protocol( string $target_home ): array {
		$scheme = strtolower( (string) strstr( $target_home, '://', true ) );
		$target = self::without_scheme( $target_home );

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $target ) {
			return array();
		}

		$other = 'https' === $scheme ? 'http' : 'https';

		return array( new ReplaceRule( $other . ':' . $target, $scheme . ':' . $target, false, false, true ) );
	}

	/**
	 * Address without protocol and trailing slash: "//example.com/sub".
	 *
	 * @param string $url URL.
	 */
	public static function without_scheme( string $url ): string {
		$position = strpos( $url, '//' );

		return false === $position ? '' : rtrim( substr( $url, $position ), '/' );
	}
}
