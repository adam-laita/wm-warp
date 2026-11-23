<?php
/**
 * Cryptographic primitives (libsodium).
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Security;

/**
 * Thin wrapper around libsodium so the rest of the plugin never handles raw primitives.
 *
 * Pure logic without WordPress calls.
 */
final class Crypto {

	public const SECRET_BYTES = 32;

	/**
	 * Random secret.
	 */
	public static function random_secret(): string {
		return random_bytes( self::SECRET_BYTES );
	}

	/**
	 * Derives an independent subkey from a secret for one purpose.
	 *
	 * @param string $secret  Master secret.
	 * @param string $purpose Purpose label, e.g. "mac" or "enc".
	 */
	public static function subkey( string $secret, string $purpose ): string {
		return hash_hmac( 'sha256', 'wm-warp/' . $purpose, $secret, true );
	}

	/**
	 * Encrypts with XChaCha20-Poly1305 (confidentiality and integrity).
	 *
	 * @param string $plaintext Data.
	 * @param string $key       32-byte key.
	 * @param string $context   Associated data that must match on decryption.
	 * @return string Nonce followed by the ciphertext.
	 */
	public static function encrypt( string $plaintext, string $key, string $context ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES );

		return $nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt( $plaintext, $context, $nonce, $key );
	}

	/**
	 * Decrypts data from encrypt().
	 *
	 * @param string $data    Nonce followed by the ciphertext.
	 * @param string $key     32-byte key.
	 * @param string $context Associated data used on encryption.
	 * @return string|null Plaintext, or null when the data is invalid or was altered.
	 */
	public static function decrypt( string $data, string $key, string $context ): ?string {
		$nonce_length = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

		if ( strlen( $data ) <= $nonce_length ) {
			return null;
		}

		$plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
			substr( $data, $nonce_length ),
			$context,
			substr( $data, 0, $nonce_length ),
			$key
		);

		return false === $plaintext ? null : $plaintext;
	}

	/**
	 * New key pair for the key exchange.
	 */
	public static function exchange_keypair(): string {
		return sodium_crypto_kx_keypair();
	}

	/**
	 * Public part of a key pair.
	 *
	 * @param string $keypair Key pair.
	 */
	public static function exchange_public_key( string $keypair ): string {
		return sodium_crypto_kx_publickey( $keypair );
	}

	/**
	 * Shared connection secret on the side that started the pairing.
	 *
	 * The secret never travels over the network: each side derives it from its own key pair
	 * and the other side's public key (X25519).
	 *
	 * @param string $client_keypair    Key pair of the site that started the pairing.
	 * @param string $server_public_key Public key of the other site.
	 * @return string|null Secret, or null for an invalid public key.
	 */
	public static function client_shared_secret( string $client_keypair, string $server_public_key ): ?string {
		if ( SODIUM_CRYPTO_KX_PUBLICKEYBYTES !== strlen( $server_public_key ) ) {
			return null;
		}

		[ $rx, $tx ] = sodium_crypto_kx_client_session_keys( $client_keypair, $server_public_key );

		return self::combine( $tx, $rx );
	}

	/**
	 * Shared connection secret on the side that accepted the pairing.
	 *
	 * @param string $server_keypair    Key pair of the site that accepted the pairing.
	 * @param string $client_public_key Public key of the site that started the pairing.
	 * @return string|null Secret, or null for an invalid public key.
	 */
	public static function server_shared_secret( string $server_keypair, string $client_public_key ): ?string {
		if ( SODIUM_CRYPTO_KX_PUBLICKEYBYTES !== strlen( $client_public_key ) ) {
			return null;
		}

		[ $rx, $tx ] = sodium_crypto_kx_server_session_keys( $server_keypair, $client_public_key );

		return self::combine( $rx, $tx );
	}

	/**
	 * Combines the two session keys into one secret, identical on both sides.
	 *
	 * @param string $client_to_server Key for client → server.
	 * @param string $server_to_client Key for server → client.
	 */
	private static function combine( string $client_to_server, string $server_to_client ): string {
		return sodium_crypto_generichash( $client_to_server . $server_to_client, '', self::SECRET_BYTES );
	}
}
