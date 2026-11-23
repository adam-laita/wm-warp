<?php
/**
 * Where and how to reach another site.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Api;

use WM\Warp\Sites\Connection;

/**
 * Network settings of a remote site: REST address, HTTP Basic Auth, server IP and certificate check.
 */
final class Endpoint {

	/**
	 * Constructor.
	 *
	 * @param string $rest_url      REST base URL of the Warp API, with a trailing slash.
	 * @param string $auth_user     HTTP Basic Auth user, empty for none.
	 * @param string $auth_password HTTP Basic Auth password.
	 * @param string $ip            Server IP address to use instead of DNS, empty for DNS.
	 * @param bool   $verify_tls    Whether to verify the HTTPS certificate.
	 */
	public function __construct(
		public readonly string $rest_url,
		public readonly string $auth_user = '',
		public readonly string $auth_password = '',
		public readonly string $ip = '',
		public readonly bool $verify_tls = true
	) {}

	/**
	 * Endpoint of a stored connection.
	 *
	 * @param Connection $connection Connection.
	 */
	public static function of( Connection $connection ): self {
		return new self( $connection->rest_url, $connection->auth_user, $connection->auth_password, $connection->ip, $connection->verify_tls );
	}

	/**
	 * Same endpoint with a different certificate check.
	 *
	 * @param bool $verify_tls Whether to verify the certificate.
	 */
	public function with_verify_tls( bool $verify_tls ): self {
		return new self( $this->rest_url, $this->auth_user, $this->auth_password, $this->ip, $verify_tls );
	}

	/**
	 * Host name of the REST address.
	 */
	public function host(): string {
		$host = wp_parse_url( $this->rest_url, PHP_URL_HOST );

		return is_string( $host ) ? strtolower( $host ) : '';
	}

	/**
	 * Whether the host is a local development domain or a private address.
	 *
	 * LocalWP and similar tools use self-signed certificates there, so the certificate check is skipped
	 * automatically; requests are still signed and encrypted by Warp.
	 */
	public function is_local(): bool {
		$host = $this->host();

		if ( 'localhost' === $host || 1 === preg_match( '/\.(local|test|localhost|lan|internal)$/', $host ) ) {
			return true;
		}

		$ip = trim( $host, '[]' );

		return false !== filter_var( $ip, FILTER_VALIDATE_IP )
			&& false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}
}
