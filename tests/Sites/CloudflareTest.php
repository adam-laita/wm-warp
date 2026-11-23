<?php
/**
 * Tests for recognising Cloudflare.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Tests\Sites;

use PHPUnit\Framework\TestCase;
use WM\Warp\Sites\Cloudflare;

/**
 * IP addresses of Cloudflare and of ordinary servers.
 */
final class CloudflareTest extends TestCase {

	/**
	 * Addresses inside the published ranges.
	 */
	public function test_cloudflare_addresses(): void {
		self::assertTrue( Cloudflare::owns( '104.16.132.229' ) );
		self::assertTrue( Cloudflare::owns( '172.67.0.1' ) );
		self::assertTrue( Cloudflare::owns( '173.245.63.255' ) );
		self::assertTrue( Cloudflare::owns( '188.114.97.3' ) );
		self::assertTrue( Cloudflare::owns( '2606:4700:3030::6815:1' ) );
		self::assertTrue( Cloudflare::owns( '[2606:4700::1]' ) );
		self::assertTrue( Cloudflare::owns( '2a06:98c7:ffff::1' ) );
	}

	/**
	 * Addresses outside the ranges, invalid input.
	 */
	public function test_other_addresses(): void {
		self::assertFalse( Cloudflare::owns( '173.245.64.0' ) );
		self::assertFalse( Cloudflare::owns( '127.0.0.1' ) );
		self::assertFalse( Cloudflare::owns( '46.28.105.10' ) );
		self::assertFalse( Cloudflare::owns( '2a06:98c8::1' ) );
		self::assertFalse( Cloudflare::owns( '::1' ) );
		self::assertFalse( Cloudflare::owns( 'not an ip' ) );
		self::assertFalse( Cloudflare::owns( '' ) );
	}
}
