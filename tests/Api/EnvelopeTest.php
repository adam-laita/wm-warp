<?php
/**
 * Tests for the encrypted body and the key exchange.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Tests\Api;

use PHPUnit\Framework\TestCase;
use WM\Warp\Api\Envelope;
use WM\Warp\Security\Crypto;

/**
 * Envelope and key exchange.
 */
final class EnvelopeTest extends TestCase {

	/**
	 * Data survive sealing and opening, including Unicode and nested values.
	 */
	public function test_round_trip(): void {
		$secret = Crypto::random_secret();
		$data   = array(
			'text'   => 'Příliš žluťoučký kůň 😀 https://example.test/a/b',
			'nested' => array(
				'n'     => 1,
				'empty' => array(),
			),
			'long'   => str_repeat( 'abc', 10000 ),
		);

		self::assertSame( $data, Envelope::open( Envelope::seal( $data, $secret, Envelope::REQUEST ), $secret, Envelope::REQUEST ) );
	}

	/**
	 * Repetitive data are compressed.
	 */
	public function test_compression(): void {
		$body = Envelope::seal( array( 'long' => str_repeat( 'abc', 10000 ) ), Crypto::random_secret(), Envelope::REQUEST );

		self::assertLessThan( 1000, strlen( $body ) );
	}

	/**
	 * A wrong secret, a changed byte or the other direction cannot be opened.
	 */
	public function test_tampering_is_detected(): void {
		$secret = Crypto::random_secret();
		$body   = Envelope::seal( array( 'a' => 1 ), $secret, Envelope::REQUEST );
		$broken = $body;

		$broken[ strlen( $broken ) - 1 ] = chr( ord( $broken[ strlen( $broken ) - 1 ] ) ^ 1 );

		self::assertNull( Envelope::open( $body, Crypto::random_secret(), Envelope::REQUEST ) );
		self::assertNull( Envelope::open( $broken, $secret, Envelope::REQUEST ) );
		self::assertNull( Envelope::open( $body, $secret, Envelope::RESPONSE ) );
		self::assertNull( Envelope::open( 'not an envelope', $secret, Envelope::REQUEST ) );
	}

	/**
	 * Both sides of the key exchange derive the same secret; a third party does not.
	 */
	public function test_key_exchange(): void {
		$client = Crypto::exchange_keypair();
		$server = Crypto::exchange_keypair();
		$other  = Crypto::exchange_keypair();

		$client_secret = Crypto::client_shared_secret( $client, Crypto::exchange_public_key( $server ) );
		$server_secret = Crypto::server_shared_secret( $server, Crypto::exchange_public_key( $client ) );

		self::assertNotNull( $client_secret );
		self::assertSame( $client_secret, $server_secret );
		self::assertSame( Crypto::SECRET_BYTES, strlen( $client_secret ) );
		self::assertNotSame( $client_secret, Crypto::server_shared_secret( $other, Crypto::exchange_public_key( $client ) ) );
		self::assertNull( Crypto::client_shared_secret( $client, 'too short' ) );
	}
}
