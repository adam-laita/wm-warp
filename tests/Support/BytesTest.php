<?php
/**
 * Tests for byte sizes.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Tests\Support;

use PHPUnit\Framework\TestCase;
use WM\Warp\Api\Endpoint;
use WM\Warp\Support\Bytes;

/**
 * PHP ini sizes and local hosts.
 */
final class BytesTest extends TestCase {

	/**
	 * Ini sizes are converted to bytes.
	 */
	public function test_from_ini(): void {
		self::assertSame( 128 * 1024 * 1024, Bytes::from_ini( '128M' ) );
		self::assertSame( 2 * 1024 * 1024 * 1024, Bytes::from_ini( '2g' ) );
		self::assertSame( 512 * 1024, Bytes::from_ini( '512K' ) );
		self::assertSame( 1000, Bytes::from_ini( '1000' ) );
		self::assertSame( -1, Bytes::from_ini( '-1' ) );
		self::assertSame( 0, Bytes::from_ini( '' ) );
		self::assertSame( 0, Bytes::from_ini( 'lots' ) );
	}

	/**
	 * Local development hosts skip the certificate check, public ones do not.
	 */
	public function test_local_hosts(): void {
		foreach ( array( 'https://warpa.local/wp-json/', 'https://site.test/', 'http://localhost/', 'https://192.168.1.10/', 'https://[::1]/' ) as $url ) {
			self::assertTrue( ( new Endpoint( $url ) )->is_local(), $url );
		}

		foreach ( array( 'https://warp.forwhile.net/', 'https://example.com/', 'https://8.8.8.8/', 'https://local.example.com/' ) as $url ) {
			self::assertFalse( ( new Endpoint( $url ) )->is_local(), $url );
		}
	}
}
