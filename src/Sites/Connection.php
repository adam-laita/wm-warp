<?php
/**
 * One connection between this site and another site.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Sites;

/**
 * A connection has its own secret, agreed by both sites when it was made.
 *
 * Outgoing connections (sites this site connected to) carry everything needed to send requests;
 * incoming connections (sites connected to this site) only need the secret to verify requests.
 */
final class Connection {

	/**
	 * Constructor.
	 *
	 * @param string               $id            Connection ID, the same on both sites.
	 * @param string               $site_id       ID of the other site.
	 * @param string               $url           Home URL of the other site.
	 * @param string               $rest_url      REST base URL of the other site (outgoing only).
	 * @param string               $name          Name of the other site.
	 * @param string               $secret        Connection secret (binary).
	 * @param int                  $created       When the connection was made (Unix time).
	 * @param int                  $last_seen     Last successful request (Unix time).
	 * @param string               $auth_user     HTTP Basic Auth user of the other site (outgoing only).
	 * @param string               $auth_password HTTP Basic Auth password (outgoing only).
	 * @param string               $ip            Server IP address to use instead of DNS (outgoing only).
	 * @param bool                 $verify_tls    Whether to verify the HTTPS certificate (outgoing only).
	 * @param array<string, mixed> $remote        Environment of the other site from the last handshake.
	 */
	public function __construct(
		public string $id,
		public string $site_id,
		public string $url,
		public string $rest_url,
		public string $name,
		public string $secret,
		public int $created,
		public int $last_seen = 0,
		public string $auth_user = '',
		public string $auth_password = '',
		public string $ip = '',
		public bool $verify_tls = true,
		public array $remote = array()
	) {}

	/**
	 * Host name of the other site, for display.
	 */
	public function host(): string {
		$host = wp_parse_url( $this->url, PHP_URL_HOST );

		return is_string( $host ) ? $host : $this->url;
	}
}
