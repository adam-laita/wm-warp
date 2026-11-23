<?php
/**
 * Identity of this site.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Sites;

use WM\Warp\Protocol;
use WM\Warp\Security\Crypto;
use WM\Warp\Security\Vault;

/**
 * Site ID and pairing secret of this site, and the key built from them.
 */
final class SiteIdentity {

	public const OPTION = 'wm_warp_site';

	/**
	 * Vault for the pairing secret.
	 *
	 * @var Vault
	 */
	private Vault $vault;

	/**
	 * Constructor.
	 *
	 * @param Vault $vault Vault.
	 */
	public function __construct( Vault $vault ) {
		$this->vault = $vault;
	}

	/**
	 * Site ID (32 hex characters), created on first use.
	 */
	public function site_id(): string {
		return $this->data()['id'];
	}

	/**
	 * Pairing secret, created on first use.
	 *
	 * When the stored secret cannot be opened (the salts in wp-config.php changed), a new one is created;
	 * the old key then stops working, which is the same as generating a new key.
	 */
	public function pairing_secret(): string {
		$data   = $this->data();
		$secret = $this->vault->open( $data['secret'] );

		if ( null === $secret ) {
			$secret = $this->regenerate();
		}

		return $secret;
	}

	/**
	 * Key of this site for connecting another site.
	 */
	public function key(): PairingKey {
		return new PairingKey(
			home_url(),
			trailingslashit( rest_url( Protocol::REST_NAMESPACE ) ),
			$this->site_id(),
			$this->pairing_secret()
		);
	}

	/**
	 * Creates a new pairing secret. The old key stops working; existing connections keep working.
	 *
	 * @return string New secret.
	 */
	public function regenerate(): string {
		$data           = $this->data();
		$secret         = Crypto::random_secret();
		$data['secret'] = $this->vault->seal( $secret );
		update_option( self::OPTION, $data, false );

		return $secret;
	}

	/**
	 * Stored data, created on first use.
	 *
	 * @return array{id: string, secret: string}
	 */
	private function data(): array {
		$data = get_option( self::OPTION );

		if ( is_array( $data ) && isset( $data['id'], $data['secret'] ) && is_string( $data['id'] ) && is_string( $data['secret'] ) ) {
			return array(
				'id'     => $data['id'],
				'secret' => $data['secret'],
			);
		}

		$data = array(
			'id'     => bin2hex( random_bytes( 16 ) ),
			'secret' => $this->vault->seal( Crypto::random_secret() ),
		);
		update_option( self::OPTION, $data, false );

		return $data;
	}
}
