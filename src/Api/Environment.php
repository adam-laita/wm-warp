<?php
/**
 * Description of this site for the handshake.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Api;

use WM\Warp\Protocol;
use WM\Warp\Support\Bytes;
use const WM\Warp\VERSION;

/**
 * Versions, limits and capabilities of this site, exchanged when a connection is made or a run starts.
 */
final class Environment {

	/**
	 * Description of this site.
	 *
	 * @param string $site_id      ID of this site.
	 * @param bool   $with_plugins Add active plugins with versions (for the log).
	 * @return array<string, mixed>
	 */
	public static function describe( string $site_id, bool $with_plugins = false ): array {
		global $wpdb;

		$plugins = $with_plugins ? array( 'plugins' => self::plugins() ) : array();

		return array(
			'protocol'    => Protocol::VERSION,
			'version'     => VERSION,
			'site_id'     => $site_id,
			'name'        => get_bloginfo( 'name' ),
			'home'        => home_url(),
			'path'        => untrailingslashit( ABSPATH ),
			'wp'          => get_bloginfo( 'version' ),
			'php'         => PHP_VERSION,
			'db'          => (string) $wpdb->db_server_info(),
			'charset'     => $wpdb->charset,
			'collate'     => $wpdb->collate,
			'prefix'      => $wpdb->base_prefix,
			'multisite'   => is_multisite(),
			'os'          => PHP_OS_FAMILY,
			'limits'      => array(
				'post_max_size'       => Bytes::from_ini( (string) ini_get( 'post_max_size' ) ),
				'upload_max_filesize' => Bytes::from_ini( (string) ini_get( 'upload_max_filesize' ) ),
				'memory_limit'        => Bytes::from_ini( (string) ini_get( 'memory_limit' ) ),
				'max_execution_time'  => (int) ini_get( 'max_execution_time' ),
			),
			'compression' => array_values(
				array_filter(
					array(
						function_exists( 'gzdeflate' ) ? 'zlib' : '',
						function_exists( 'zstd_compress' ) ? 'zstd' : '',
					)
				)
			),
			'time'        => time(),
			'loader'      => defined( 'WM_WARP_LOADER' ),
		) + $plugins;
	}

	/**
	 * Active plugins with versions ("folder/file.php" => version).
	 *
	 * Read from the database, because during a Warp request the loader (MU plugin) hides the other plugins.
	 *
	 * @return array<string, string>
	 */
	private static function plugins(): array {
		global $wpdb;

		$active = maybe_unserialize( (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'active_plugins' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The option is filtered during Warp requests.
		$active = is_array( $active ) ? array_values( array_filter( $active, 'is_string' ) ) : array();

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all     = get_plugins();
		$plugins = array();

		foreach ( $active as $plugin ) {
			$plugins[ $plugin ] = isset( $all[ $plugin ]['Version'] ) ? (string) $all[ $plugin ]['Version'] : '?';
		}

		return $plugins;
	}
}
