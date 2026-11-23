<?php
/**
 * Requests to another site.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Api;

use WM\Warp\Protocol;
use WM\Warp\Support\Base64Url;

/**
 * Sends signed and encrypted requests and turns every failure into a human readable ClientError.
 */
final class Client {

	private const TIMEOUT = 30;

	/**
	 * ID of this site, to recognise a request that ended up on this very site.
	 *
	 * @var string
	 */
	private string $own_site_id;

	/**
	 * Constructor.
	 *
	 * @param string $own_site_id ID of this site.
	 */
	public function __construct( string $own_site_id ) {
		$this->own_site_id = $own_site_id;
	}

	/**
	 * Sends a request and returns the decrypted response.
	 *
	 * @param Endpoint             $endpoint      Where to send it.
	 * @param string               $action        Route below the namespace, e.g. "handshake".
	 * @param array<string, mixed> $data          Request data.
	 * @param string               $secret        Pairing or connection secret.
	 * @param string               $connection_id Connection ID, or Protocol::PAIRING_CONNECTION.
	 * @param string               $site_id       Expected ID of the other site.
	 * @return array<string, mixed>
	 * @throws ClientError When the request fails.
	 */
	public function send( Endpoint $endpoint, string $action, array $data, string $secret, string $connection_id, string $site_id ): array {
		return $this->exchange( $endpoint, $action, $data, $secret, $connection_id, $site_id )['data'];
	}

	/**
	 * Sends a request with an optional binary payload and returns the decrypted response with its payload.
	 *
	 * @param Endpoint             $endpoint      Where to send it.
	 * @param string               $action        Route below the namespace.
	 * @param array<string, mixed> $data          Request data.
	 * @param string               $secret        Pairing or connection secret.
	 * @param string               $connection_id Connection ID, or Protocol::PAIRING_CONNECTION.
	 * @param string               $site_id       Expected ID of the other site.
	 * @param string               $payload       Binary payload (table rows), empty for none.
	 * @param int                  $timeout       Timeout in seconds.
	 * @return array{data: array<string, mixed>, payload: string}
	 * @throws ClientError When the request fails.
	 */
	public function exchange( Endpoint $endpoint, string $action, array $data, string $secret, string $connection_id, string $site_id, string $payload = '', int $timeout = self::TIMEOUT ): array {
		$body     = Envelope::seal( $data, $secret, Envelope::REQUEST, $payload );
		$route    = '/' . Protocol::REST_NAMESPACE . '/' . $action;
		$response = $this->post( $endpoint, $action, $route, $body, $secret, $connection_id, 0, $timeout );

		// The clocks of the two servers differ; retry once with the other server's time.
		$server_time = $this->clock_skew( $response );
		if ( null !== $server_time ) {
			$response = $this->post( $endpoint, $action, $route, $body, $secret, $connection_id, $server_time - time(), $timeout );
		}

		return $this->read( $response, $endpoint, $secret, $connection_id, $site_id );
	}

	/**
	 * Sends the HTTP request.
	 *
	 * @param Endpoint $endpoint      Endpoint.
	 * @param string   $action        Route below the namespace.
	 * @param string   $route         Full route.
	 * @param string   $body          Encrypted body.
	 * @param string   $secret        Secret.
	 * @param string   $connection_id Connection ID.
	 * @param int      $offset        Clock offset in seconds.
	 * @param int      $timeout       Timeout in seconds.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function post( Endpoint $endpoint, string $action, string $route, string $body, string $secret, string $connection_id, int $offset, int $timeout ): array|\WP_Error {
		$time  = time() + $offset;
		$nonce = Base64Url::encode( random_bytes( 18 ) );

		$headers = array(
			'Content-Type'              => 'application/octet-stream',
			Protocol::HEADER_CONNECTION => $connection_id,
			Protocol::HEADER_TIME       => (string) $time,
			Protocol::HEADER_NONCE      => $nonce,
			Protocol::HEADER_SIGNATURE  => Signature::sign( $secret, Signature::canonical( 'POST', $route, $time, $nonce, $connection_id, $body ) ),
			Protocol::HEADER_PROTOCOL   => (string) Protocol::VERSION,
		);

		if ( '' !== $endpoint->auth_user ) {
			$headers['Authorization'] = 'Basic ' . base64_encode( $endpoint->auth_user . ':' . $endpoint->auth_password ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic Auth header.
		}

		$resolver = $this->resolver( $endpoint );
		if ( null !== $resolver ) {
			add_action( 'http_api_curl', $resolver, 10, 3 );
		}

		$response = wp_remote_post(
			$endpoint->rest_url . $action,
			array(
				'headers'     => $headers,
				'body'        => $body,
				'timeout'     => $timeout,
				'redirection' => 0,
				'sslverify'   => $endpoint->verify_tls,
				'user-agent'  => 'WM-Warp/' . \WM\Warp\VERSION,
			)
		);

		if ( null !== $resolver ) {
			remove_action( 'http_api_curl', $resolver, 10 );
		}

		return $response;
	}

	/**
	 * Hook for cURL that sends the request to a given IP address instead of the address from DNS.
	 *
	 * The domain stays in the request, so the server picks the right site and the certificate
	 * is checked for the domain (like an entry in the hosts file, only on the server).
	 *
	 * @param Endpoint $endpoint Endpoint.
	 */
	private function resolver( Endpoint $endpoint ): ?\Closure {
		if ( '' === $endpoint->ip ) {
			return null;
		}

		$host   = $endpoint->host();
		$scheme = wp_parse_url( $endpoint->rest_url, PHP_URL_SCHEME );
		$port   = wp_parse_url( $endpoint->rest_url, PHP_URL_PORT );
		$port   = is_int( $port ) ? $port : ( 'http' === $scheme ? 80 : 443 );
		$ip     = str_contains( $endpoint->ip, ':' ) ? '[' . trim( $endpoint->ip, '[]' ) . ']' : $endpoint->ip;

		return static function ( $handle, $args, $url ) use ( $host, $port, $ip ): void {
			unset( $args );
			$url_host = is_string( $url ) ? wp_parse_url( $url, PHP_URL_HOST ) : null;

			if ( ( $handle instanceof \CurlHandle ) && is_string( $url_host ) && strtolower( $url_host ) === $host ) {
				curl_setopt( $handle, CURLOPT_RESOLVE, array( $host . ':' . $port . ':' . $ip ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- No WordPress API for a custom DNS resolution.
			}
		};
	}

	/**
	 * Server time from a clock skew error, if that is what the response is.
	 *
	 * @param array<string, mixed>|\WP_Error $response Response.
	 */
	private function clock_skew( array|\WP_Error $response ): ?int {
		if ( is_wp_error( $response ) || 401 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$error = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( is_array( $error ) && 'wm_warp_clock' === ( $error['code'] ?? '' ) && isset( $error['data']['server_time'] ) && is_int( $error['data']['server_time'] ) ) {
			return $error['data']['server_time'];
		}

		return null;
	}

	/**
	 * Reads the response or turns it into a ClientError.
	 *
	 * @param array<string, mixed>|\WP_Error $response      Response.
	 * @param Endpoint                       $endpoint      Endpoint.
	 * @param string                         $secret        Secret.
	 * @param string                         $connection_id Connection ID.
	 * @param string                         $site_id       Expected ID of the other site.
	 * @return array{data: array<string, mixed>, payload: string}
	 * @throws ClientError When the request failed.
	 */
	private function read( array|\WP_Error $response, Endpoint $endpoint, string $secret, string $connection_id, string $site_id ): array {
		if ( is_wp_error( $response ) ) {
			throw $this->transport_error( $response, $endpoint );
		}

		$status    = (int) wp_remote_retrieve_response_code( $response );
		$body      = wp_remote_retrieve_body( $response );
		$answering = self::header( $response, strtolower( Protocol::HEADER_SITE ) );
		$details   = array(
			'status'  => $status,
			'site'    => $answering,
			'server'  => self::header( $response, 'server' ),
			'excerpt' => mb_substr( wp_strip_all_tags( $body ), 0, 300 ),
		);

		if ( '' !== $answering && $answering === $this->own_site_id ) {
			throw new ClientError(
				'self',
				__( 'The address of the other site leads back to this site. If you are moving a site to another hosting with the same domain, enter the IP address of the other server.', 'wm-warp' ),
				'ip',
				$details
			);
		}

		if ( '' !== $answering && $answering !== $site_id ) {
			throw new ClientError(
				'other_site',
				__( 'A different site with Warp answered at this address. The domain probably leads to another server; enter the IP address of the right server.', 'wm-warp' ),
				'ip',
				$details
			);
		}

		if ( $status >= 200 && $status < 300 ) {
			$data = Envelope::open_with_payload( $body, $secret, Envelope::RESPONSE );

			if ( null === $data ) {
				throw new ClientError(
					'bad_response',
					__( 'The other site sent an unexpected response. A firewall, proxy or security plugin may be changing the communication.', 'wm-warp' ),
					'',
					$details
				);
			}

			return $data;
		}

		throw $this->http_error( $status, $response, $connection_id, $endpoint, $details );
	}

	/**
	 * Error for a request that did not get any HTTP response.
	 *
	 * @param \WP_Error $error    Error from the HTTP API.
	 * @param Endpoint  $endpoint Endpoint.
	 */
	private function transport_error( \WP_Error $error, Endpoint $endpoint ): ClientError {
		$message = $error->get_error_message();
		$details = array( 'error' => $message );
		$host    = $endpoint->host();

		if ( 1 === preg_match( '/cURL error (35|51|53|54|58|59|60|77|80|82|83|90|91)\b|SSL|certificate/i', $message ) ) {
			return new ClientError(
				'tls',
				/* translators: %s: domain of the other site. */
				sprintf( __( 'The HTTPS certificate of %s could not be verified (expired, self-signed or issued for another domain).', 'wm-warp' ), $host ),
				'tls',
				$details
			);
		}

		if ( 1 === preg_match( '/cURL error 6\b|resolve host/i', $message ) ) {
			return new ClientError(
				'dns',
				/* translators: %s: domain of the other site. */
				sprintf( __( 'The domain %s cannot be found. Check the key; if the site is not in public DNS yet, enter the IP address of its server.', 'wm-warp' ), $host ),
				'ip',
				$details
			);
		}

		if ( 1 === preg_match( '/cURL error 28\b|timed out/i', $message ) ) {
			return new ClientError(
				'timeout',
				/* translators: %s: domain of the other site. */
				sprintf( __( 'The site %s did not answer in time. Check that it is running and reachable from this server.', 'wm-warp' ), $host ),
				'',
				$details
			);
		}

		return new ClientError(
			'unreachable',
			/* translators: %s: domain of the other site. */
			sprintf( __( 'This server cannot reach %s. Check that the site is running and reachable from the internet; a local site can only connect to others, it cannot be connected to.', 'wm-warp' ), $host ),
			'',
			$details
		);
	}

	/**
	 * Error for an HTTP error status.
	 *
	 * @param int                  $status        HTTP status.
	 * @param array<string, mixed> $response      Response.
	 * @param string               $connection_id Connection ID.
	 * @param Endpoint             $endpoint      Endpoint.
	 * @param array<string, mixed> $details       Details for the log.
	 */
	private function http_error( int $status, array $response, string $connection_id, Endpoint $endpoint, array $details ): ClientError {
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		$code = is_array( $json ) && isset( $json['code'] ) && is_string( $json['code'] ) ? $json['code'] : '';
		$auth = self::header( $response, 'www-authenticate' );

		if ( $status >= 300 && $status < 400 ) {
			$location = self::header( $response, 'location' );

			return new ClientError(
				'redirect',
				/* translators: %s: address the other site redirects to. */
				sprintf( __( 'The other site redirects to %s. Copy the key again from the site at its current address.', 'wm-warp' ), $location ),
				'key',
				$details
			);
		}

		if ( 401 === $status && 1 === preg_match( '/^\s*basic/i', $auth ) ) {
			return new ClientError(
				'basic_auth',
				'' === $endpoint->auth_user
					? __( 'The other site is protected by a server password (HTTP authentication). Enter the user name and password.', 'wm-warp' )
					: __( 'The server password (HTTP authentication) of the other site is wrong. Check the user name and password.', 'wm-warp' ),
				'auth',
				$details
			);
		}

		if ( 'wm_warp_bad_signature' === $code || 'wm_warp_unknown_connection' === $code ) {
			return Protocol::PAIRING_CONNECTION === $connection_id
				? new ClientError( 'key', __( 'The key is no longer valid; a new key was probably generated on the other site. Copy the current key from its Settings tab.', 'wm-warp' ), 'key', $details )
				: new ClientError( 'revoked', __( 'The other site no longer accepts this connection; it was probably removed there. Disconnect the site and connect it again.', 'wm-warp' ), '', $details );
		}

		if ( 'wm_warp_self' === $code ) {
			return new ClientError( 'own_key', __( 'This is the key of this site. Paste the key of the other site.', 'wm-warp' ), 'key', $details );
		}

		if ( 'wm_warp_blocked' === $code || 429 === $status ) {
			return new ClientError( 'blocked', __( 'The other site temporarily blocked requests from this server after too many failed attempts. Try again in 15 minutes.', 'wm-warp' ), '', $details );
		}

		if ( 'wm_warp_protocol' === $code ) {
			return new ClientError( 'protocol', __( 'The versions of Warp on the two sites are not compatible. Update Warp on both sites to the same version.', 'wm-warp' ), '', $details );
		}

		if ( in_array( $code, array( 'rest_not_logged_in', 'rest_forbidden', 'rest_cannot_access', 'rest_login_required', 'rest_authentication_required' ), true ) ) {
			return new ClientError( 'rest_locked', __( 'The REST API of the other site is open only to logged-in users (a plugin or setting restricts it), or Warp is not active there. Check that Warp is active and allow the wm-warp/v1 namespace in that plugin.', 'wm-warp' ), '', $details );
		}

		if ( 413 === $status ) {
			return new ClientError( 'too_large', __( 'The other site refused a request because it is too large (HTTP 413). Increase the upload limits of its server (post_max_size, client_max_body_size) or contact the hosting.', 'wm-warp' ), '', $details );
		}

		if ( str_starts_with( $code, 'wm_warp_migration' ) ) {
			$message = isset( $json['message'] ) && is_string( $json['message'] ) ? $json['message'] : '';

			return new ClientError(
				'migration',
				/* translators: %s: technical error from the other site. */
				sprintf( __( 'The migration failed on the other site: %s', 'wm-warp' ), '' !== $message ? $message : $code ),
				'',
				$details
			);
		}

		if ( 'wm_warp_storage' === $code ) {
			return new ClientError( 'storage', __( 'The other site cannot write to the folder wp-content/wm-warp. Check the folder permissions on its server.', 'wm-warp' ), '', $details );
		}

		if ( 404 === $status ) {
			return 'rest_no_route' === $code
				? new ClientError( 'not_installed', __( 'Warp is not active on the other site, or it is an older version. Install and activate Warp there.', 'wm-warp' ), '', $details )
				: new ClientError( 'no_rest', __( 'The REST API of the other site is not available. Check that Warp is active there and that the REST API is not disabled by a plugin or the server.', 'wm-warp' ), '', $details );
		}

		if ( 401 === $status || 403 === $status ) {
			return new ClientError( 'forbidden', __( 'The other site refused the request. A security plugin or the hosting firewall (WAF) may block the REST API or requests from this server.', 'wm-warp' ), '', $details );
		}

		return new ClientError(
			'remote_error',
			/* translators: %d: HTTP status code. */
			sprintf( __( 'The other site responded with an error (HTTP %d). Check its PHP error log.', 'wm-warp' ), $status ),
			'',
			$details
		);
	}

	/**
	 * Response header as a string (the first value when it repeats).
	 *
	 * @param array<string, mixed> $response Response.
	 * @param string               $name     Header name.
	 */
	private static function header( array $response, string $name ): string {
		$value = wp_remote_retrieve_header( $response, $name );

		if ( is_array( $value ) ) {
			$value = reset( $value );
		}

		return is_string( $value ) ? $value : '';
	}
}
