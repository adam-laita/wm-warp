<?php
/**
 * Encryption of secrets stored in the database.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Security;

use WM\Warp\Support\Base64Url;

/**
 * Seals secrets with a key derived from the salts in wp-config.php.
 *
 * A database dump alone therefore does not reveal the secrets. When the salts change,
 * stored secrets can no longer be opened and the connections have to be made again.
 */
final class Vault {

	private const CONTEXT = 'wm-warp/vault/1';

	/**
	 * Encryption key.
	 *
	 * @var string
	 */
	private string $key;

	/**
	 * Constructor.
	 *
	 * @param string $key_material Secret material, e.g. wp_salt( 'auth' ).
	 */
	public function __construct( string $key_material ) {
		$this->key = Crypto::subkey( $key_material, 'vault' );
	}

	/**
	 * Vault for this WordPress site.
	 */
	public static function for_site(): self {
		return new self( wp_salt( 'auth' ) . wp_salt( 'secure_auth' ) );
	}

	/**
	 * Encrypts a value for storage.
	 *
	 * @param string $value Secret.
	 */
	public function seal( string $value ): string {
		return Base64Url::encode( Crypto::encrypt( $value, $this->key, self::CONTEXT ) );
	}

	/**
	 * Decrypts a stored value.
	 *
	 * @param string $sealed Value from seal().
	 * @return string|null Secret, or null when it cannot be opened.
	 */
	public function open( string $sealed ): ?string {
		$data = Base64Url::decode( $sealed );

		return null === $data ? null : Crypto::decrypt( $data, $this->key, self::CONTEXT );
	}
}
