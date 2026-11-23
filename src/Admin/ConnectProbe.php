<?php
/**
 * Hints for the IP address field of the connect form.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Admin;

use WM\Warp\Api\Client;
use WM\Warp\Sites\Cloudflare;
use WM\Warp\Sites\PairingKey;

/**
 * Endpoint of admin-ajax.php the connect form asks while the IP address field is open:
 * whether the other site is behind Cloudflare (its homepage answers with Server: cloudflare or
 * CF-RAY) and whether the entered IP belongs to Cloudflare. Only the public homepage is
 * requested, without the key or any password.
 */
final class ConnectProbe {

	public const ACTION = 'wm_warp_probe';

	private const CACHE = 10 * MINUTE_IN_SECONDS;

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Handles a request.
	 */
	public function handle(): void {
		if ( ! current_user_can( AdminPage::CAPABILITY ) || false === check_ajax_referer( self::ACTION, 'nonce', false ) ) {
			wp_send_json_error( null, 403 );
		}

		$key = PairingKey::decode( isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '' );
		$ip  = isset( $_POST['ip'] ) ? sanitize_text_field( wp_unslash( $_POST['ip'] ) ) : '';

		wp_send_json_success(
			array(
				'site' => null === $key ? false : self::behind_cloudflare( $key->url ),
				'ip'   => '' !== $ip && Cloudflare::owns( $ip ),
			)
		);
	}

	/**
	 * Whether the homepage of a site answers through Cloudflare (cached for a while).
	 *
	 * @param string $url Address of the site.
	 */
	private static function behind_cloudflare( string $url ): bool {
		$host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$cache = 'wm_warp_cf_' . md5( $host );
		$known = get_transient( $cache );

		if ( false !== $known ) {
			return '1' === $known;
		}

		$response = wp_remote_head(
			$url,
			array(
				'timeout'     => 6,
				'redirection' => 0,
				'sslverify'   => false,
				'user-agent'  => 'WM-Warp/' . \WM\Warp\VERSION,
			)
		);
		$result   = ! is_wp_error( $response ) && Client::behind_cloudflare( $response );

		set_transient( $cache, $result ? '1' : '0', self::CACHE );

		return $result;
	}
}
