<?php
/**
 * Encrypted message body.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Api;

use WM\Warp\Protocol;
use WM\Warp\Security\Crypto;

/**
 * Body format of requests and responses: JSON (+ binary payload) → deflate → XChaCha20-Poly1305.
 *
 * Layout: 1 byte format version, 1 byte flags, then nonce and ciphertext. With a payload (table rows),
 * the plaintext is a 4-byte JSON length, the JSON and the raw payload bytes. The direction is part of
 * the associated data, so a response cannot be replayed as a request.
 * Pure logic without WordPress calls.
 */
final class Envelope {

	public const REQUEST  = 'request';
	public const RESPONSE = 'response';

	private const FORMAT        = 1;
	private const FLAG_DEFLATED = 1;
	private const FLAG_PAYLOAD  = 2;

	/**
	 * Encodes data.
	 *
	 * @param array<string, mixed> $data      Data.
	 * @param string               $secret    Connection or pairing secret.
	 * @param string               $direction self::REQUEST or self::RESPONSE.
	 * @param string               $payload   Binary payload, empty for none.
	 * @throws \JsonException When the data cannot be encoded.
	 */
	public static function seal( array $data, string $secret, string $direction, string $payload = '' ): string {
		$json  = json_encode( $data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure class, runs without WordPress in tests.
		$flags = 0;

		if ( '' !== $payload ) {
			$json   = pack( 'N', strlen( $json ) ) . $json . $payload;
			$flags |= self::FLAG_PAYLOAD;
		}

		$deflated = gzdeflate( $json, 1 );

		if ( false !== $deflated && strlen( $deflated ) < strlen( $json ) ) {
			$json   = $deflated;
			$flags |= self::FLAG_DEFLATED;
		}

		return chr( self::FORMAT ) . chr( $flags ) . Crypto::encrypt( $json, Crypto::subkey( $secret, 'enc' ), self::context( $direction ) );
	}

	/**
	 * Decodes data.
	 *
	 * @param string $body      Body from seal().
	 * @param string $secret    Secret.
	 * @param string $direction self::REQUEST or self::RESPONSE.
	 * @return array<string, mixed>|null Data, or null when the body is invalid, altered or for another secret.
	 */
	public static function open( string $body, string $secret, string $direction ): ?array {
		$opened = self::open_with_payload( $body, $secret, $direction );

		return null === $opened ? null : $opened['data'];
	}

	/**
	 * Decodes data and the binary payload.
	 *
	 * @param string $body      Body from seal().
	 * @param string $secret    Secret.
	 * @param string $direction self::REQUEST or self::RESPONSE.
	 * @return array{data: array<string, mixed>, payload: string}|null Null when the body is invalid, altered or for another secret.
	 */
	public static function open_with_payload( string $body, string $secret, string $direction ): ?array {
		if ( strlen( $body ) < 3 || self::FORMAT !== ord( $body[0] ) ) {
			return null;
		}

		$flags   = ord( $body[1] );
		$payload = Crypto::decrypt( substr( $body, 2 ), Crypto::subkey( $secret, 'enc' ), self::context( $direction ) );

		if ( null !== $payload && 0 !== ( $flags & self::FLAG_DEFLATED ) ) {
			$payload = gzinflate( $payload );
		}

		if ( ! is_string( $payload ) ) {
			return null;
		}

		$binary = '';

		if ( 0 !== ( $flags & self::FLAG_PAYLOAD ) ) {
			$length = strlen( $payload ) >= 4 ? unpack( 'N', $payload ) : false;
			$length = is_array( $length ) ? (int) $length[1] : -1;

			if ( $length < 0 || 4 + $length > strlen( $payload ) ) {
				return null;
			}

			$binary  = (string) substr( $payload, 4 + $length );
			$payload = substr( $payload, 4, $length );
		}

		$data = json_decode( $payload, true );

		if ( ! is_array( $data ) ) {
			return null;
		}

		$result = array();
		foreach ( $data as $key => $value ) {
			$result[ (string) $key ] = $value;
		}

		return array(
			'data'    => $result,
			'payload' => $binary,
		);
	}

	/**
	 * Associated data for a direction.
	 *
	 * @param string $direction Direction.
	 */
	private static function context( string $direction ): string {
		return 'wm-warp/' . Protocol::VERSION . '/' . $direction;
	}
}
