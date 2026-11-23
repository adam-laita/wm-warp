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
	 * @param string $site_id ID of this site.
	 * @return array<string, mixed>
	 */
	public static function describe( string $site_id ): array {
		global $wpdb;

		return array(
			'protocol'    => Protocol::VERSION,
			'version'     => VERSION,
			'site_id'     => $site_id,
			'name'        => get_bloginfo( 'name' ),
			'home'        => home_url(),
			'wp'          => get_bloginfo( 'version' ),
			'php'         => PHP_VERSION,
			'db'          => (string) $wpdb->db_server_info(),
			'charset'     => $wpdb->charset,
			'collate'     => $wpdb->collate,
			'prefix'      => $wpdb->base_prefix,
			'multisite'   => is_multisite(),
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
		);
	}
}
