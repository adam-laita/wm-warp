<?php
/**
 * Tests for request signatures.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Tests\Api;

use PHPUnit\Framework\TestCase;
use WM\Warp\Api\Signature;
use WM\Warp\Protocol;

/**
 * Signing and verifying.
 */
final class SignatureTest extends TestCase {

	private const SECRET = '0123456789abcdef0123456789abcdef';

	/**
	 * Canonical text of a sample request.
	 *
	 * @param string $body Body.
	 */
	private function canonical( string $body = 'body' ): string {
		return Signature::canonical( 'post', 'wm-warp/v1/handshake', 1700000000, 'nonce-123', 'conn', $body );
	}

	/**
	 * A valid signature is accepted, also in upper case.
	 */
	public function test_valid_signature(): void {
		$signature = Signature::sign( self::SECRET, $this->canonical() );

		self::assertTrue( Signature::verify( self::SECRET, $this->canonical(), $signature ) );
		self::assertTrue( Signature::verify( self::SECRET, $this->canonical(), strtoupper( $signature ) ) );
	}

	/**
	 * Any change of the request or the secret breaks the signature.
	 */
	public function test_changes_break_the_signature(): void {
		$signature = Signature::sign( self::SECRET, $this->canonical() );

		self::assertFalse( Signature::verify( self::SECRET, $this->canonical( 'other body' ), $signature ) );
		self::assertFalse( Signature::verify( strrev( self::SECRET ), $this->canonical(), $signature ) );
		self::assertFalse( Signature::verify( self::SECRET, Signature::canonical( 'POST', '/wm-warp/v1/pair', 1700000000, 'nonce-123', 'conn', 'body' ), $signature ) );
		self::assertFalse( Signature::verify( self::SECRET, Signature::canonical( 'POST', '/wm-warp/v1/handshake', 1700000001, 'nonce-123', 'conn', 'body' ), $signature ) );
	}

	/**
	 * Method case and leading slash of the route do not matter.
	 */
	public function test_canonical_is_normalised(): void {
		self::assertSame( $this->canonical(), Signature::canonical( 'POST', '/wm-warp/v1/handshake', 1700000000, 'nonce-123', 'conn', 'body' ) );
	}

	/**
	 * Requests outside the time window are stale.
	 */
	public function test_time_window(): void {
		$now = 1700000000;

		self::assertTrue( Signature::is_fresh( $now - Protocol::TIME_WINDOW, $now ) );
		self::assertTrue( Signature::is_fresh( $now + Protocol::TIME_WINDOW, $now ) );
		self::assertFalse( Signature::is_fresh( $now - Protocol::TIME_WINDOW - 1, $now ) );
		self::assertFalse( Signature::is_fresh( $now + Protocol::TIME_WINDOW + 1, $now ) );
	}
}
