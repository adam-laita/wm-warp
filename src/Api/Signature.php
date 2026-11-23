<?php
/**
 * Request signatures.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Api;

use WM\Warp\Protocol;
use WM\Warp\Security\Crypto;

/**
 * HMAC-SHA256 signature over the method, route, time, nonce, connection and body hash.
 *
 * Pure logic without WordPress calls.
 */
final class Signature {

	/**
	 * Text that is signed.
	 *
	 * @param string $method     HTTP method.
	 * @param string $route      REST route, e.g. "/wm-warp/v1/handshake".
	 * @param int    $time       Unix time of the request.
	 * @param string $nonce      Random request ID.
	 * @param string $connection Connection ID, or "pair".
	 * @param string $body       Raw request body.
	 */
	public static function canonical( string $method, string $route, int $time, string $nonce, string $connection, string $body ): string {
		return implode(
			"\n",
			array(
				'WMW' . Protocol::VERSION,
				strtoupper( $method ),
				'/' . ltrim( $route, '/' ),
				(string) $time,
				$nonce,
				$connection,
				hash( 'sha256', $body ),
			)
		);
	}

	/**
	 * Signature of a request.
	 *
	 * @param string $secret    Connection or pairing secret.
	 * @param string $canonical Text from canonical().
	 * @return string Hex signature.
	 */
	public static function sign( string $secret, string $canonical ): string {
		return hash_hmac( 'sha256', $canonical, Crypto::subkey( $secret, 'mac' ) );
	}

	/**
	 * Verifies a signature in constant time.
	 *
	 * @param string $secret    Secret.
	 * @param string $canonical Text from canonical().
	 * @param string $signature Received hex signature.
	 */
	public static function verify( string $secret, string $canonical, string $signature ): bool {
		return hash_equals( self::sign( $secret, $canonical ), strtolower( $signature ) );
	}

	/**
	 * Whether the request time is within the allowed window.
	 *
	 * @param int $time Request time.
	 * @param int $now  Current time.
	 */
	public static function is_fresh( int $time, int $now ): bool {
		return abs( $now - $time ) <= Protocol::TIME_WINDOW;
	}
}
