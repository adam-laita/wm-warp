<?php
/**
 * REST API of this site for other Warp sites.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Api;

use WM\Warp\Protocol;
use WM\Warp\Security\Crypto;
use WM\Warp\Sites\Connection;
use WM\Warp\Sites\ConnectionStore;
use WM\Warp\Sites\SiteIdentity;
use WM\Warp\Support\Base64Url;
use WM\Warp\Support\Log;
use WM\Warp\Support\LogEntry;
use WM\Warp\Support\Storage;

/**
 * Routes under wm-warp/v1. Every request must be signed with the pairing secret (pairing) or the
 * secret of a connection; the body is encrypted. Cookies and logged-in users play no role.
 */
final class RestController {

	/**
	 * Identity of this site.
	 *
	 * @var SiteIdentity
	 */
	private SiteIdentity $identity;

	/**
	 * Sites connected to this site.
	 *
	 * @var ConnectionStore
	 */
	private ConnectionStore $incoming;

	/**
	 * Verified secrets and connections by request.
	 *
	 * @var array<int, array{secret: string, connection: ?Connection}>
	 */
	private array $verified = array();

	/**
	 * Constructor.
	 *
	 * @param SiteIdentity    $identity Identity of this site.
	 * @param ConnectionStore $incoming Sites connected to this site.
	 */
	public function __construct( SiteIdentity $identity, ConnectionStore $incoming ) {
		$this->identity = $identity;
		$this->incoming = $incoming;
	}

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_post_dispatch', array( $this, 'add_headers' ), 10, 3 );
		add_filter( 'rest_pre_serve_request', array( $this, 'serve_binary' ), 10, 2 );
	}

	/**
	 * Registers routes.
	 */
	public function register_routes(): void {
		foreach ( array( 'pair', 'handshake', 'disconnect' ) as $route ) {
			register_rest_route(
				Protocol::REST_NAMESPACE,
				'/' . $route,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, $route ),
					'permission_callback' => array( $this, 'authenticate' ),
				)
			);
		}

		foreach ( array_merge( DatabaseRoutes::ROUTES, FileRoutes::ROUTES, array( 'log/run' ) ) as $route ) {
			register_rest_route(
				Protocol::REST_NAMESPACE,
				'/' . $route,
				array(
					'methods'             => 'POST',
					'callback'            => fn( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error => $this->migration( $request, $route ),
					'permission_callback' => array( $this, 'authenticate' ),
				)
			);
		}
	}

	/**
	 * Migration request, handled by DatabaseRoutes (db/*) or FileRoutes (files/*, run/*).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param string           $route   Route below the namespace.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function migration( \WP_REST_Request $request, string $route ): \WP_REST_Response|\WP_Error {
		$auth   = $this->verified_request( $request );
		$opened = null === $auth ? null : Envelope::open_with_payload( $request->get_body(), $auth['secret'], Envelope::REQUEST );

		if ( null === $auth || null === $auth['connection'] || null === $opened ) {
			return new \WP_Error( 'wm_warp_bad_body', 'Invalid request body.', array( 'status' => 400 ) );
		}

		$run_id = is_string( $opened['data']['run_id'] ?? null ) && 1 === preg_match( '/^[a-f0-9]{32}$/', $opened['data']['run_id'] ) ? $opened['data']['run_id'] : '';
		$peer   = $auth['connection']->host();

		if ( 'log/run' === $route ) {
			$entries = '' === $run_id ? array() : Log::entries( $run_id, 5 );
			$entries = array_map(
				static function ( array $entry ): array {
					unset( $entry['environment'], $entry['remote_log'] );
					return $entry;
				},
				array_values( array_filter( $entries, static fn( array $entry ): bool => Log::RESULT !== ( $entry['level'] ?? '' ) ) )
			);

			return new BinaryResponse( Envelope::seal( array( 'entries' => $entries ), $auth['secret'], Envelope::RESPONSE ) );
		}

		// A fatal error (memory, time) ends PHP without an exception; log it before the response is lost.
		register_shutdown_function( array( self::class, 'log_fatal' ), $route, $run_id, $peer );

		try {
			$result = str_starts_with( $route, 'db/' )
				? DatabaseRoutes::handle( $route, $opened['data'], $opened['payload'], $auth['connection'] )
				: FileRoutes::handle( $route, $opened['data'], $opened['payload'], $auth['connection']->name );
		} catch ( \Throwable $error ) {
			Log::error(
				'remote',
				$error->getMessage(),
				array(
					'run'     => $run_id,
					'peer'    => $peer,
					'route'   => $route,
					'details' => array(
						'exception' => get_class( $error ),
						'where'     => basename( $error->getFile() ) . ':' . $error->getLine(),
						'sql'       => $error instanceof \WM\Warp\Database\DatabaseError ? LogEntry::statement( $error->sql ) : '',
					),
				)
			);

			return new \WP_Error( 'wm_warp_migration', $error->getMessage(), array( 'status' => 500 ) );
		}

		return new BinaryResponse( Envelope::seal( $result['data'], $auth['secret'], Envelope::RESPONSE, $result['payload'], $result['deflate'] ?? true ) );
	}

	/**
	 * Logs a fatal PHP error that ended a migration request (shutdown function).
	 *
	 * @param string $route  Route.
	 * @param string $run_id Run.
	 * @param string $peer   Site that sent the request.
	 */
	public static function log_fatal( string $route, string $run_id, string $peer ): void {
		$error = error_get_last();

		if ( null === $error || 0 === ( $error['type'] & ( E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR ) ) ) {
			return;
		}

		Log::error(
			'remote',
			$error['message'],
			array(
				'run'     => $run_id,
				'peer'    => $peer,
				'route'   => $route,
				'reason'  => 'fatal',
				'details' => array(
					'where'  => basename( $error['file'] ) . ':' . $error['line'],
					'memory' => memory_get_peak_usage( true ),
					'limit'  => (string) ini_get( 'memory_limit' ),
					'time'   => (int) ini_get( 'max_execution_time' ),
				),
			)
		);
	}

	/**
	 * Verifies the signature, time and nonce of a request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function authenticate( \WP_REST_Request $request ): bool|\WP_Error { // phpcs:ignore Squiz.Commenting.FunctionComment.InvalidReturn -- Documented precisely above.
		$ip            = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$limiter       = new RateLimiter();
		$connection_id = (string) $request->get_header( Protocol::HEADER_CONNECTION );
		$time          = (string) $request->get_header( Protocol::HEADER_TIME );
		$nonce         = (string) $request->get_header( Protocol::HEADER_NONCE );
		$signature     = (string) $request->get_header( Protocol::HEADER_SIGNATURE );

		if ( $limiter->is_blocked( $ip ) ) {
			return new \WP_Error( 'wm_warp_blocked', 'Too many failed requests.', array( 'status' => 429 ) );
		}

		if ( '' === $connection_id || 1 !== preg_match( '/^\d+$/', $time ) || '' === $nonce || '' === $signature ) {
			return new \WP_Error( 'wm_warp_unsigned', 'The request is not signed.', array( 'status' => 401 ) );
		}

		if ( (string) Protocol::VERSION !== (string) $request->get_header( Protocol::HEADER_PROTOCOL ) ) {
			return new \WP_Error( 'wm_warp_protocol', 'Unsupported protocol version.', array( 'status' => 400 ) );
		}

		if ( ! Signature::is_fresh( (int) $time, time() ) ) {
			return new \WP_Error(
				'wm_warp_clock',
				'The request time is outside the allowed window.',
				array(
					'status'      => 401,
					'server_time' => time(),
				)
			);
		}

		$connection = null;

		if ( Protocol::PAIRING_CONNECTION === $connection_id ) {
			$secret = $this->identity->pairing_secret();
		} else {
			$connection = $this->incoming->get( $connection_id );

			if ( null === $connection ) {
				$limiter->fail( $ip );

				return new \WP_Error( 'wm_warp_unknown_connection', 'Unknown connection.', array( 'status' => 401 ) );
			}

			$secret = $connection->secret;
		}

		$canonical = Signature::canonical( $request->get_method(), $request->get_route(), (int) $time, $nonce, $connection_id, $request->get_body() );

		if ( ! Signature::verify( $secret, $canonical, $signature ) ) {
			$limiter->fail( $ip );

			return new \WP_Error( 'wm_warp_bad_signature', 'Invalid signature.', array( 'status' => 401 ) );
		}

		if ( null === Storage::dir( 'nonces' ) ) {
			return new \WP_Error( 'wm_warp_storage', 'The folder wp-content/wm-warp is not writable.', array( 'status' => 500 ) );
		}

		if ( ! ( new NonceStore() )->remember( $connection_id, $nonce ) ) {
			return new \WP_Error( 'wm_warp_replay', 'The request was already processed.', array( 'status' => 401 ) );
		}

		$this->verified[ spl_object_id( $request ) ] = array(
			'secret'     => $secret,
			'connection' => $connection,
		);

		return true;
	}

	/**
	 * Pairing: another site connects using the key of this site.
	 *
	 * Both sites derive a new secret for this connection from an X25519 key exchange; the secret never
	 * travels over the network.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function pair( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$auth = $this->verified_request( $request );
		$data = null === $auth ? null : Envelope::open( $request->get_body(), $auth['secret'], Envelope::REQUEST );

		if ( null === $auth || null === $data ) {
			return new \WP_Error( 'wm_warp_bad_body', 'Invalid request body.', array( 'status' => 400 ) );
		}

		$site_id    = self::field( $data, 'site_id' );
		$url        = self::field( $data, 'url' );
		$public_key = Base64Url::decode( self::field( $data, 'public_key' ) );

		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $site_id ) || 1 !== preg_match( '#^https?://[^\s/?\#]+#i', $url ) || null === $public_key ) {
			return new \WP_Error( 'wm_warp_bad_body', 'Invalid pairing data.', array( 'status' => 400 ) );
		}

		if ( $site_id === $this->identity->site_id() ) {
			return new \WP_Error( 'wm_warp_self', 'A site cannot connect to itself.', array( 'status' => 409 ) );
		}

		$keypair = Crypto::exchange_keypair();
		$secret  = Crypto::server_shared_secret( $keypair, $public_key );

		if ( null === $secret ) {
			return new \WP_Error( 'wm_warp_bad_body', 'Invalid public key.', array( 'status' => 400 ) );
		}

		// Connecting again from the same site replaces the previous connection.
		$previous = $this->incoming->find_by_site( $site_id );
		if ( null !== $previous ) {
			$this->incoming->delete( $previous->id );
		}

		$connection = new Connection(
			bin2hex( random_bytes( 16 ) ),
			$site_id,
			esc_url_raw( $url ),
			'',
			sanitize_text_field( self::field( $data, 'name' ) ),
			$secret,
			time(),
			time()
		);
		$this->incoming->save( $connection );

		return $this->respond(
			array(
				'connection_id' => $connection->id,
				'public_key'    => Base64Url::encode( Crypto::exchange_public_key( $keypair ) ),
			),
			$auth['secret']
		);
	}

	/**
	 * Handshake: both sites exchange versions, limits and capabilities.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handshake( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$auth = $this->verified_request( $request );

		if ( null === $auth || null === $auth['connection'] || null === Envelope::open( $request->get_body(), $auth['secret'], Envelope::REQUEST ) ) {
			return new \WP_Error( 'wm_warp_bad_body', 'Invalid request body.', array( 'status' => 400 ) );
		}

		$connection            = $auth['connection'];
		$connection->last_seen = time();
		$this->incoming->save( $connection );

		return $this->respond( array( 'environment' => Environment::describe( $this->identity->site_id(), true ) ), $auth['secret'] );
	}

	/**
	 * Disconnect: the other site removed the connection, so this site forgets it too.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function disconnect( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$auth = $this->verified_request( $request );

		if ( null === $auth || null === $auth['connection'] ) {
			return new \WP_Error( 'wm_warp_bad_body', 'Invalid request.', array( 'status' => 400 ) );
		}

		$this->incoming->delete( $auth['connection']->id );

		return $this->respond( array( 'disconnected' => true ), $auth['secret'] );
	}

	/**
	 * Adds the site ID and protocol version to every response of the Warp API.
	 *
	 * The site ID lets the other site recognise that a request ended up on the wrong site
	 * (for example on itself when moving a site with the same domain).
	 *
	 * @param \WP_HTTP_Response $response Response.
	 * @param \WP_REST_Server   $server   Server.
	 * @param \WP_REST_Request  $request  Request.
	 */
	public function add_headers( \WP_HTTP_Response $response, \WP_REST_Server $server, \WP_REST_Request $request ): \WP_HTTP_Response {
		unset( $server );

		if ( str_starts_with( $request->get_route(), '/' . Protocol::REST_NAMESPACE . '/' ) ) {
			$response->header( Protocol::HEADER_SITE, $this->identity->site_id() );
			$response->header( Protocol::HEADER_PROTOCOL, (string) Protocol::VERSION );
		}

		return $response;
	}

	/**
	 * Sends binary responses as they are instead of JSON.
	 *
	 * @param bool              $served Whether the response was already sent.
	 * @param \WP_HTTP_Response $result Response.
	 */
	public function serve_binary( bool $served, \WP_HTTP_Response $result ): bool {
		if ( $served || ! $result instanceof BinaryResponse ) {
			return $served;
		}

		echo $result->bytes(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Encrypted binary body, sent as application/octet-stream.

		return true;
	}

	/**
	 * Verified secret and connection of a request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array{secret: string, connection: ?Connection}|null
	 */
	private function verified_request( \WP_REST_Request $request ): ?array {
		return $this->verified[ spl_object_id( $request ) ] ?? null;
	}

	/**
	 * Encrypted response.
	 *
	 * @param array<string, mixed> $data   Data.
	 * @param string               $secret Secret.
	 */
	private function respond( array $data, string $secret ): BinaryResponse {
		return new BinaryResponse( Envelope::seal( $data, $secret, Envelope::RESPONSE ) );
	}

	/**
	 * String field of request data.
	 *
	 * @param array<string, mixed> $data  Data.
	 * @param string               $field Field.
	 */
	private static function field( array $data, string $field ): string {
		return isset( $data[ $field ] ) && is_string( $data[ $field ] ) ? $data[ $field ] : '';
	}
}
