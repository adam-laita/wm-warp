<?php
/**
 * Must-use plugin that speeds up Warp requests.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp;

/**
 * Installs wp-content/mu-plugins/wm-warp-loader.php. For POST requests to the REST namespace
 * wm-warp/v1 it loads only Warp: no other plugins and an empty theme. A request then costs less
 * and security, cache or multilingual plugins cannot change it. It also keeps Warp loaded when
 * a migrated database does not list Warp among active plugins.
 *
 * Without the file (read-only mu-plugins folder) Warp works the same way, only slower.
 */
final class Loader {

	public const FILE = 'wm-warp-loader.php';

	/**
	 * Version of the generated code; a different version is replaced.
	 */
	private const VERSION = 1;

	/**
	 * Registers hooks.
	 */
	public static function register(): void {
		add_action( 'admin_init', array( self::class, 'ensure' ) );
	}

	/**
	 * Installs or updates the file when it is missing or outdated.
	 */
	public static function ensure(): void {
		$file = self::path();
		$code = self::code( plugin_basename( PLUGIN_FILE ) );

		if ( ! is_readable( $file ) || file_get_contents( $file ) !== $code ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
			self::install( $code );
		}
	}

	/**
	 * Removes the file (plugin deactivation).
	 */
	public static function remove(): void {
		$file = self::path();

		if ( file_exists( $file ) && str_contains( (string) file_get_contents( $file ), 'WM_WARP_LOADER' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
			wp_delete_file( $file );
		}
	}

	/**
	 * Whether this request runs with the loader.
	 */
	public static function active(): bool {
		return defined( 'WM_WARP_LOADER' );
	}

	/**
	 * Code of the must-use plugin.
	 *
	 * @param string $basename Plugin basename of Warp, e.g. "wm-warp/wm-warp.php".
	 */
	public static function code( string $basename ): string {
		$plugin = var_export( $basename, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Generates a PHP string literal.
		$theme  = var_export( dirname( $basename ) . '/assets/empty-theme', true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Generates a PHP string literal.
		$ver    = self::VERSION;

		return <<<PHP
<?php
/**
 * Plugin Name: WM Warp Loader
 * Description: Loads only WM Warp for its own requests between sites, so migrations are faster and other plugins cannot interfere. Created by WM Warp and removed when WM Warp is deactivated.
 * Version:     {$ver}
 * Author:      WebManiak
 * License:     GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

( static function (): void {
	\$method = isset( \$_SERVER['REQUEST_METHOD'] ) ? (string) \$_SERVER['REQUEST_METHOD'] : '';
	\$uri    = isset( \$_SERVER['REQUEST_URI'] ) ? rawurldecode( (string) \$_SERVER['REQUEST_URI'] ) : '';
	\$plugin = {$plugin};

	if ( 'POST' !== \$method || ! str_contains( \$uri, '/wm-warp/v1/' ) || ! is_file( WP_PLUGIN_DIR . '/' . \$plugin ) ) {
		return;
	}

	define( 'WM_WARP_LOADER', true );

	add_filter( 'option_active_plugins', static fn() => array( \$plugin ), PHP_INT_MAX );
	add_filter(
		'site_option_active_sitewide_plugins',
		static fn( \$plugins ) => is_array( \$plugins ) && isset( \$plugins[ \$plugin ] ) ? array( \$plugin => \$plugins[ \$plugin ] ) : array(),
		PHP_INT_MAX
	);

	\$theme = WP_PLUGIN_DIR . '/' . {$theme};
	add_filter( 'template_directory', static fn() => \$theme, PHP_INT_MAX );
	add_filter( 'stylesheet_directory', static fn() => \$theme, PHP_INT_MAX );
} )();

PHP;
	}

	/**
	 * Path of the must-use plugin.
	 */
	private static function path(): string {
		return trailingslashit( WPMU_PLUGIN_DIR ) . self::FILE;
	}

	/**
	 * Writes the file; a failure only means slower requests.
	 *
	 * @param string $code Code.
	 */
	private static function install( string $code ): void {
		$dir = WPMU_PLUGIN_DIR;

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return;
		}

		$file = self::path();
		$temp = $file . '.' . bin2hex( random_bytes( 4 ) ) . '.tmp';

		// A read-only folder is a normal case, hence the silenced warnings; atomic write of a must-use plugin.
		if ( false === @file_put_contents( $temp, $code ) || ! @rename( $temp, $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.rename_rename
			wp_delete_file( $temp );
		}
	}
}
