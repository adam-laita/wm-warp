<?php
/**
 * Site key used to connect another site.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Sites;

use WM\Warp\Security\Crypto;
use WM\Warp\Support\Base64Url;

/**
 * The key a user copies from one site and pastes into another: "warp1." + base64url(JSON).
 *
 * It carries the site address, its REST address, its ID and the pairing secret. The secret is used
 * only to establish a connection; every connection then gets its own secret.
 */
final class PairingKey {

	public const PREFIX = 'warp1.';

	/**
	 * Constructor.
	 *
	 * @param string $url      Home URL of the site.
	 * @param string $rest_url REST base URL of the Warp API, with a trailing slash.
	 * @param string $site_id  Site ID.
	 * @param string $secret   Pairing secret (binary).
	 */
	public function __construct(
		public readonly string $url,
		public readonly string $rest_url,
		public readonly string $site_id,
		public readonly string $secret
	) {}

	/**
	 * Text form of the key.
	 */
	public function encode(): string {
		$json = (string) wp_json_encode(
			array(
				'u' => $this->url,
				'r' => $this->rest_url,
				'i' => $this->site_id,
				's' => Base64Url::encode( $this->secret ),
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		return self::PREFIX . Base64Url::encode( $json );
	}

	/**
	 * Parses a pasted key.
	 *
	 * @param string $text Key as pasted by the user; surrounding whitespace is ignored.
	 * @return self|null Key, or null when the text is not a valid Warp key.
	 */
	public static function decode( string $text ): ?self {
		$text = (string) preg_replace( '/\s+/', '', $text );

		if ( ! str_starts_with( $text, self::PREFIX ) ) {
			return null;
		}

		$json = Base64Url::decode( substr( $text, strlen( self::PREFIX ) ) );
		$data = null === $json ? null : json_decode( $json, true );

		if ( ! is_array( $data ) ) {
			return null;
		}

		foreach ( array( 'u', 'r', 'i', 's' ) as $field ) {
			if ( ! isset( $data[ $field ] ) || ! is_string( $data[ $field ] ) || '' === $data[ $field ] ) {
				return null;
			}
		}

		$secret = Base64Url::decode( $data['s'] );

		if ( null === $secret || Crypto::SECRET_BYTES !== strlen( $secret ) ) {
			return null;
		}

		if ( ! self::is_http_url( $data['u'] ) || ! self::is_http_url( $data['r'] ) || 1 !== preg_match( '/^[a-f0-9]{32}$/', $data['i'] ) ) {
			return null;
		}

		return new self( $data['u'], $data['r'], $data['i'], $secret );
	}

	/**
	 * Whether the value is an absolute http(s) URL.
	 *
	 * @param string $url URL.
	 */
	private static function is_http_url( string $url ): bool {
		return 1 === preg_match( '#^https?://[^\s/?\#]+#i', $url );
	}
}
