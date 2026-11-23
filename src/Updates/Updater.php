<?php
/**
 * Updates from GitHub Releases.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Updates;

use const WM\Warp\PLUGIN_FILE;
use const WM\Warp\VERSION;

/**
 * Connects the plugin to the standard WordPress updates (8.1).
 *
 * - "Update URI: https://github.com/…" in the plugin header makes WordPress ask the filter
 *   update_plugins_github.com instead of WordPress.org; the answer comes from the latest stable release.
 * - The release is cached for 12 hours (a failed check for 1 hour), so the public API limit of
 *   60 requests per hour and IP address is never reached, even on shared hosting.
 * - The downloaded ZIP is checked against its SHA-256 before WordPress unpacks it.
 * - "Check for updates" in the plugin row checks right away.
 *
 * The constant WM_WARP_UPDATE_API (wp-config.php) replaces the API address, for tests only.
 */
final class Updater {

	public const ACTION = 'wm_warp_check_updates';

	private const API       = 'https://api.github.com/repos/adam-laita/wm-warp/releases/latest';
	private const HOMEPAGE  = 'https://github.com/adam-laita/wm-warp';
	private const CACHE     = 'wm_warp_release';
	private const CACHE_OK  = 12 * HOUR_IN_SECONDS;
	private const CACHE_BAD = HOUR_IN_SECONDS;
	private const SLUG      = 'wm-warp';

	/**
	 * Registers hooks.
	 */
	public static function register(): void {
		add_filter( 'update_plugins_github.com', array( self::class, 'update' ), 10, 3 );
		add_filter( 'plugins_api', array( self::class, 'details' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( self::class, 'download' ), 10, 2 );
		add_filter( 'plugin_row_meta', array( self::class, 'row_meta' ), 10, 2 );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'check_now' ) );
		add_action( 'admin_notices', array( self::class, 'notice' ) );
	}

	/**
	 * Update data for WordPress, or false when there is nothing newer.
	 *
	 * @param mixed  $update      Update data from earlier filters.
	 * @param mixed  $plugin_data Plugin header data.
	 * @param string $plugin_file Plugin file relative to the plugins folder.
	 * @return mixed
	 */
	public static function update( mixed $update, mixed $plugin_data, string $plugin_file ): mixed {
		unset( $plugin_data );

		if ( plugin_basename( PLUGIN_FILE ) !== $plugin_file ) {
			return $update;
		}

		$release = self::release();

		if ( null === $release || ! $release->newer_than( VERSION ) ) {
			return $update;
		}

		return array(
			'id'           => self::HOMEPAGE,
			'slug'         => self::SLUG,
			'version'      => $release->version,
			'url'          => '' !== $release->url ? $release->url : self::HOMEPAGE,
			'package'      => $release->package,
			'requires'     => '6.6',
			'requires_php' => '8.3',
		);
	}

	/**
	 * "View details" window of the plugin.
	 *
	 * @param mixed  $result Result from earlier filters.
	 * @param string $action API action.
	 * @param mixed  $args   Arguments.
	 * @return mixed
	 */
	public static function details( mixed $result, string $action, mixed $args ): mixed {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || self::SLUG !== ( $args->slug ?? '' ) ) {
			return $result;
		}

		$release = self::release();
		$notes   = null === $release ? '' : wp_kses_post( Release::notes_html( $release->notes ) );

		return (object) array(
			'name'          => 'WM Warp',
			'slug'          => self::SLUG,
			'version'       => null === $release ? VERSION : $release->version,
			'author'        => '<a href="https://webmaniak.cz">WebManiak</a>',
			'homepage'      => self::HOMEPAGE,
			'requires'      => '6.6',
			'requires_php'  => '8.3',
			'last_updated'  => null === $release ? '' : $release->published,
			'download_link' => null === $release ? '' : $release->package,
			'sections'      => array(
				'description' => '<p>' . esc_html__( 'Data migration and synchronization between WordPress sites: database, media, themes and plugins, with find & replace and undo.', 'wm-warp' ) . '</p>',
				'changelog'   => '' !== $notes ? $notes : '<p>' . esc_html__( 'No release notes.', 'wm-warp' ) . '</p>',
			),
		);
	}

	/**
	 * Downloads the update package and checks its SHA-256.
	 *
	 * @param mixed $reply   False to let WordPress download, a file path or WP_Error.
	 * @param mixed $package Package URL.
	 * @return mixed
	 */
	public static function download( mixed $reply, mixed $package ): mixed {
		$release = self::release();

		if ( false !== $reply || null === $release || $package !== $release->package ) {
			return $reply;
		}

		$expected = $release->sha256 ?? self::remote_checksum( (string) $release->sha256_url );

		if ( null === $expected ) {
			return new \WP_Error( 'wm_warp_checksum', __( 'The checksum of the WM Warp update could not be downloaded. Try again later.', 'wm-warp' ) );
		}

		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$file = download_url( $release->package, 300 );

		if ( is_wp_error( $file ) ) {
			return $file;
		}

		if ( ! hash_equals( $expected, (string) hash_file( 'sha256', $file ) ) ) {
			wp_delete_file( $file );

			return new \WP_Error( 'wm_warp_checksum', __( 'The downloaded update of WM Warp is damaged (its checksum does not match), so it was not installed. Try again later.', 'wm-warp' ) );
		}

		return $file;
	}

	/**
	 * "Check for updates" link in the plugin row.
	 *
	 * @param mixed  $meta Links.
	 * @param string $file Plugin file.
	 * @return mixed
	 */
	public static function row_meta( mixed $meta, string $file ): mixed {
		if ( ! is_array( $meta ) || plugin_basename( PLUGIN_FILE ) !== $file || ! current_user_can( 'update_plugins' ) ) {
			return $meta;
		}

		$url    = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION ), self::ACTION );
		$meta[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Check for updates', 'wm-warp' ) . '</a>';

		return $meta;
	}

	/**
	 * Checks for a new release right away and returns to the plugin list.
	 */
	public static function check_now(): void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You are not allowed to update plugins.', 'wm-warp' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::ACTION );

		delete_site_transient( self::CACHE );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();

		$cached = get_site_transient( self::CACHE );
		$result = 'failed';

		if ( is_array( $cached ) && isset( $cached['release'] ) ) {
			$release = self::release();
			$result  = null !== $release && $release->newer_than( VERSION ) ? 'available' : 'current';
		}

		wp_safe_redirect( add_query_arg( 'wm-warp-update', $result, self_admin_url( 'plugins.php' ) ) );
		exit;
	}

	/**
	 * Result of "Check for updates" on the plugin list.
	 */
	public static function notice(): void {
		$result = isset( $_GET['wm-warp-update'] ) ? sanitize_key( wp_unslash( $_GET['wm-warp-update'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.

		if ( '' === $result || ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		$release = self::release();

		if ( 'available' === $result && null !== $release ) {
			$class = 'notice-warning';
			/* translators: %s: version number. */
			$text = sprintf( __( 'WM Warp %s is available. Update it in the list below.', 'wm-warp' ), $release->version );
		} elseif ( 'current' === $result ) {
			$class = 'notice-success';
			/* translators: %s: version number. */
			$text = sprintf( __( 'WM Warp is up to date (version %s).', 'wm-warp' ), VERSION );
		} else {
			$class  = 'notice-error';
			$cached = get_site_transient( self::CACHE );
			$reason = is_array( $cached ) && is_string( $cached['error'] ?? null ) ? $cached['error'] : '';
			/* translators: %s: reason, e.g. "HTTP 404". */
			$text = sprintf( __( 'Checking for WM Warp updates failed: %s', 'wm-warp' ), $reason );
		}

		printf( '<div class="notice %1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $text ) );
	}

	/**
	 * The latest stable release, cached.
	 */
	public static function release(): ?Release {
		$cached = get_site_transient( self::CACHE );

		if ( ! is_array( $cached ) ) {
			$cached = self::fetch();
			set_site_transient( self::CACHE, $cached, isset( $cached['release'] ) ? self::CACHE_OK : self::CACHE_BAD );
		}

		return isset( $cached['release'] ) ? Release::from_api( $cached['release'] ) : null;
	}

	/**
	 * Asks GitHub for the latest release.
	 *
	 * @return array{release?: array<string, mixed>, error?: string}
	 */
	private static function fetch(): array {
		$api      = defined( 'WM_WARP_UPDATE_API' ) && is_string( WM_WARP_UPDATE_API ) ? WM_WARP_UPDATE_API : self::API;
		$response = wp_remote_get(
			$api,
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'WM-Warp/' . VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'error' => $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			return array( 'error' => 404 === $code ? __( 'no published release was found', 'wm-warp' ) : 'HTTP ' . $code );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( null === Release::from_api( $data ) || ! is_array( $data ) ) {
			return array( 'error' => __( 'the latest release has no wm-warp.zip with a checksum', 'wm-warp' ) );
		}

		// Only the fields Release reads, so the cache stays small.
		$release           = array_intersect_key( $data, array_flip( array( 'tag_name', 'draft', 'prerelease', 'body', 'html_url', 'published_at' ) ) );
		$release['assets'] = array();

		foreach ( is_array( $data['assets'] ?? null ) ? $data['assets'] : array() as $asset ) {
			if ( is_array( $asset ) ) {
				$release['assets'][] = array_intersect_key( $asset, array_flip( array( 'name', 'browser_download_url', 'digest' ) ) );
			}
		}

		return array( 'release' => $release );
	}

	/**
	 * Reads the checksum file attached to the release.
	 *
	 * @param string $url Download URL.
	 */
	private static function remote_checksum( string $url ): ?string {
		if ( '' === $url ) {
			return null;
		}

		$response = wp_remote_get( $url, array( 'timeout' => 15 ) );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		return Release::parse_checksum( wp_remote_retrieve_body( $response ) );
	}
}
