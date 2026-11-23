<?php
/**
 * Byte size helpers.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Support;

/**
 * Converts PHP ini sizes to bytes.
 */
final class Bytes {

	/**
	 * Parses a PHP ini size ("128M", "1G", "512K", "-1", "0").
	 *
	 * @param string $value Ini value.
	 * @return int Bytes; -1 means unlimited.
	 */
	public static function from_ini( string $value ): int {
		$value = trim( $value );

		if ( '' === $value ) {
			return 0;
		}

		if ( '-1' === $value ) {
			return -1;
		}

		if ( 1 !== preg_match( '/^(\d+)\s*([KMG]?)$/i', $value, $match ) ) {
			return 0;
		}

		$number = (int) $match[1];

		return match ( strtoupper( $match[2] ) ) {
			'G'     => $number * 1024 * 1024 * 1024,
			'M'     => $number * 1024 * 1024,
			'K'     => $number * 1024,
			default => $number,
		};
	}
}
