<?php
/**
 * URL-safe Base64.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Support;

/**
 * Base64 with the URL-safe alphabet and without padding (RFC 4648, section 5).
 */
final class Base64Url {

	/**
	 * Encodes binary data.
	 *
	 * @param string $data Binary data.
	 */
	public static function encode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding of keys and binary values.
	}

	/**
	 * Decodes data, returns null for invalid input.
	 *
	 * @param string $data Encoded data.
	 */
	public static function decode( string $data ): ?string {
		if ( '' === $data || 1 !== preg_match( '/^[A-Za-z0-9_-]+$/', $data ) ) {
			return null;
		}

		$decoded = base64_decode( strtr( $data, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding of keys and binary values.

		return false === $decoded ? null : $decoded;
	}
}
