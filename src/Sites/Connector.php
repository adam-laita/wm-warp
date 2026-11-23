<?php
/**
 * Connecting another site.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Sites;

use WM\Warp\Api\Client;
use WM\Warp\Api\ClientError;
use WM\Warp\Api\Endpoint;
use WM\Warp\Api\Environment;
use WM\Warp\Protocol;
use WM\Warp\Security\Crypto;
use WM\Warp\Support\Base64Url;

/**
 * Pairs this site with another site using its key, then runs the handshake.
 *
 * Connecting is also the connection test: every problem (address, certificate, server password,
 * REST API, Warp on the other site, key) ends with a ClientError with a human readable message.
 */
final class Connector {

	/**
	 * Identity of this site.
	 *
	 * @var SiteIdentity
	 */
	private SiteIdentity $identity;

	/**
	 * Sites this site connected to.
	 *
	 * @var ConnectionStore
	 */
	private ConnectionStore $outgoing;

	/**
	 * Constructor.
	 *
	 * @param SiteIdentity    $identity Identity of this site.
	 * @param ConnectionStore $outgoing Sites this site connected to.
	 */
	public function __construct( SiteIdentity $identity, ConnectionStore $outgoing ) {
		$this->identity = $identity;
		$this->outgoing = $outgoing;
	}

	/**
	 * Connects a site.
	 *
	 * @param string $key_text       Pasted key of the other site.
	 * @param string $auth_user      HTTP Basic Auth user, empty for none.
	 * @param string $auth_password  HTTP Basic Auth password.
	 * @param string $ip             Server IP address instead of DNS, empty for DNS.
	 * @param bool   $allow_insecure Connect even when the HTTPS certificate of a public domain is invalid.
	 * @return Connection New connection.
	 * @throws ClientError When the site cannot be connected.
	 */
	public function connect( string $key_text, string $auth_user, string $auth_password, string $ip, bool $allow_insecure ): Connection {
		$key = PairingKey::decode( $key_text );

		if ( null === $key ) {
			throw new ClientError( 'invalid_key', __( 'This is not a valid Warp key. Copy the whole key from the Settings tab of the other site.', 'wm-warp' ), 'key' );
		}

		if ( $key->site_id === $this->identity->site_id() ) {
			throw new ClientError( 'own_key', __( 'This is the key of this site. Paste the key of the other site.', 'wm-warp' ), 'key' );
		}

		if ( '' !== $ip && false === filter_var( trim( $ip, '[]' ), FILTER_VALIDATE_IP ) ) {
			throw new ClientError( 'invalid_ip', __( 'The IP address is not valid.', 'wm-warp' ), 'ip' );
		}

		$client   = new Client( $this->identity->site_id() );
		$endpoint = new Endpoint( $key->rest_url, $auth_user, $auth_password, $ip, true );
		$keypair  = Crypto::exchange_keypair();
		$request  = array(
			'site_id'    => $this->identity->site_id(),
			'url'        => home_url(),
			'name'       => get_bloginfo( 'name' ),
			'public_key' => Base64Url::encode( Crypto::exchange_public_key( $keypair ) ),
		);

		try {
			$response = $client->send( $endpoint, 'pair', $request, $key->secret, Protocol::PAIRING_CONNECTION, $key->site_id );
		} catch ( ClientError $error ) {
			if ( 'tls' !== $error->reason || ( ! $endpoint->is_local() && ! $allow_insecure ) ) {
				throw $error;
			}

			// Local development domains use self-signed certificates; requests stay signed and encrypted.
			$endpoint = $endpoint->with_verify_tls( false );
			$response = $client->send( $endpoint, 'pair', $request, $key->secret, Protocol::PAIRING_CONNECTION, $key->site_id );
		}

		$connection_id = isset( $response['connection_id'] ) && is_string( $response['connection_id'] ) ? $response['connection_id'] : '';
		$public_key    = Base64Url::decode( isset( $response['public_key'] ) && is_string( $response['public_key'] ) ? $response['public_key'] : '' );
		$secret        = null === $public_key ? null : Crypto::client_shared_secret( $keypair, $public_key );

		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $connection_id ) || null === $secret ) {
			throw new ClientError( 'bad_response', __( 'The other site sent an unexpected response. A firewall, proxy or security plugin may be changing the communication.', 'wm-warp' ) );
		}

		$connection = new Connection(
			$connection_id,
			$key->site_id,
			$key->url,
			$key->rest_url,
			(string) wp_parse_url( $key->url, PHP_URL_HOST ),
			$secret,
			time(),
			0,
			$auth_user,
			$auth_password,
			$ip,
			$endpoint->verify_tls
		);

		$this->handshake( $connection );

		$previous = $this->outgoing->find_by_site( $connection->site_id );
		if ( null !== $previous ) {
			$this->outgoing->delete( $previous->id );
		}

		$this->outgoing->save( $connection );

		return $connection;
	}

	/**
	 * Exchanges versions and limits with a connected site and stores them.
	 *
	 * @param Connection $connection Connection; updated in place.
	 * @throws ClientError When the request fails or the protocol differs.
	 */
	public function handshake( Connection $connection ): void {
		$client   = new Client( $this->identity->site_id() );
		$response = $client->send(
			Endpoint::of( $connection ),
			'handshake',
			array( 'environment' => Environment::describe( $this->identity->site_id() ) ),
			$connection->secret,
			$connection->id,
			$connection->site_id
		);

		$remote = isset( $response['environment'] ) && is_array( $response['environment'] ) ? $response['environment'] : array();

		if ( Protocol::VERSION !== ( $remote['protocol'] ?? null ) ) {
			throw new ClientError( 'protocol', __( 'The versions of Warp on the two sites are not compatible. Update Warp on both sites to the same version.', 'wm-warp' ) );
		}

		$connection->remote    = $remote;
		$connection->last_seen = time();

		if ( isset( $remote['name'] ) && is_string( $remote['name'] ) && '' !== $remote['name'] ) {
			$connection->name = $remote['name'];
		}
	}

	/**
	 * Disconnects a site: tells the other site to forget the connection, then forgets it here.
	 *
	 * The connection is removed here even when the other site cannot be reached.
	 *
	 * @param Connection $connection Connection.
	 * @return bool Whether the other site confirmed it.
	 */
	public function disconnect( Connection $connection ): bool {
		$confirmed = true;

		try {
			( new Client( $this->identity->site_id() ) )->send( Endpoint::of( $connection ), 'disconnect', array(), $connection->secret, $connection->id, $connection->site_id );
		} catch ( ClientError $error ) {
			$confirmed = 'revoked' === $error->reason;
		}

		$this->outgoing->delete( $connection->id );

		return $confirmed;
	}
}
