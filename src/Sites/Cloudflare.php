<?php
/**
 * Recognising Cloudflare.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Sites;

/**
 * A site behind Cloudflare needs the IP address of its hosting, not of Cloudflare, when it moves
 * to another server with the same domain (1.9). Pure helpers, covered by unit tests.
 *
 * The ranges are published at https://www.cloudflare.com/ips-v4 and /ips-v6 (checked 2026-11-23)
 * and change rarely.
 */
final class Cloudflare {

	private const RANGES = array(
		'173.245.48.0/20',
		'103.21.244.0/22',
		'103.22.200.0/22',
		'103.31.4.0/22',
		'141.101.64.0/18',
		'108.162.192.0/18',
		'190.93.240.0/20',
		'188.114.96.0/20',
		'197.234.240.0/22',
		'198.41.128.0/17',
		'162.158.0.0/15',
		'104.16.0.0/13',
		'104.24.0.0/14',
		'172.64.0.0/13',
		'131.0.72.0/22',
		'2400:cb00::/32',
		'2606:4700::/32',
		'2803:f800::/32',
		'2405:b500::/32',
		'2405:8100::/32',
		'2a06:98c0::/29',
		'2c0f:f248::/32',
	);

	/**
	 * Whether an IP address belongs to Cloudflare.
	 *
	 * @param string $ip IPv4 or IPv6 address (IPv6 also in brackets).
	 */
	public static function owns( string $ip ): bool {
		$address = @inet_pton( trim( $ip, " []\t\n\r" ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- An invalid address is a normal input.

		if ( false === $address ) {
			return false;
		}

		foreach ( self::RANGES as $range ) {
			[ $network, $bits ] = explode( '/', $range );
			$network            = (string) inet_pton( $network );

			if ( strlen( $network ) === strlen( $address ) && self::in_network( $address, $network, (int) $bits ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a binary address starts with the first bits of a network.
	 *
	 * @param string $address Binary address.
	 * @param string $network Binary network address.
	 * @param int    $bits    Prefix length.
	 */
	private static function in_network( string $address, string $network, int $bits ): bool {
		$bytes = intdiv( $bits, 8 );

		if ( substr( $address, 0, $bytes ) !== substr( $network, 0, $bytes ) ) {
			return false;
		}

		$rest = $bits % 8;

		if ( 0 === $rest ) {
			return true;
		}

		$mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;

		return ( ord( $address[ $bytes ] ) & $mask ) === ( ord( $network[ $bytes ] ) & $mask );
	}
}
