<?php
/**
 * Tests for the site key.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Tests\Sites;

use PHPUnit\Framework\TestCase;
use WM\Warp\Sites\PairingKey;

/**
 * Encoding and parsing of the key.
 */
final class PairingKeyTest extends TestCase {

	/**
	 * A sample key.
	 */
	private function key(): PairingKey {
		return new PairingKey( 'https://example.test', 'https://example.test/wp-json/wm-warp/v1/', str_repeat( 'ab', 16 ), str_repeat( "\x01", 32 ) );
	}

	/**
	 * Decoding an encoded key gives the same values.
	 */
	public function test_round_trip(): void {
		$decoded = PairingKey::decode( $this->key()->encode() );

		self::assertNotNull( $decoded );
		self::assertSame( 'https://example.test', $decoded->url );
		self::assertSame( 'https://example.test/wp-json/wm-warp/v1/', $decoded->rest_url );
		self::assertSame( str_repeat( 'ab', 16 ), $decoded->site_id );
		self::assertSame( str_repeat( "\x01", 32 ), $decoded->secret );
	}

	/**
	 * Whitespace from copying (line breaks, spaces) is ignored.
	 */
	public function test_whitespace_is_ignored(): void {
		$text = $this->key()->encode();

		self::assertNotNull( PairingKey::decode( "  \n" . chunk_split( $text, 20, "\n" ) . ' ' ) );
	}

	/**
	 * Damaged or foreign text is rejected.
	 */
	public function test_invalid_keys_are_rejected(): void {
		$valid = $this->key()->encode();

		foreach ( array( '', 'warp1.', 'warp2.' . substr( $valid, 6 ), substr( $valid, 0, -5 ), 'warp1.!!!', 'https://example.test' ) as $text ) {
			self::assertNull( PairingKey::decode( $text ), $text );
		}
	}

	/**
	 * A key with a short secret or a wrong site ID is rejected.
	 */
	public function test_invalid_fields_are_rejected(): void {
		$short_secret = new PairingKey( 'https://example.test', 'https://example.test/wp-json/wm-warp/v1/', str_repeat( 'ab', 16 ), 'short' );
		$bad_id       = new PairingKey( 'https://example.test', 'https://example.test/wp-json/wm-warp/v1/', 'not-an-id', str_repeat( "\x01", 32 ) );
		$bad_url      = new PairingKey( 'ftp://example.test', 'https://example.test/wp-json/wm-warp/v1/', str_repeat( 'ab', 16 ), str_repeat( "\x01", 32 ) );

		self::assertNull( PairingKey::decode( $short_secret->encode() ) );
		self::assertNull( PairingKey::decode( $bad_id->encode() ) );
		self::assertNull( PairingKey::decode( $bad_url->encode() ) );
	}
}
