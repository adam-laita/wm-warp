<?php
/**
 * Plugin bootstrap.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp;

use WM\Warp\Admin\AdminPage;

/**
 * Wires the plugin into WordPress.
 */
final class Plugin {

	/**
	 * Shared instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Returns the shared instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		if ( is_admin() ) {
			( new AdminPage() )->register();
		}
	}

	/**
	 * Loads translations bundled with the plugin.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'wm-warp', false, dirname( plugin_basename( PLUGIN_FILE ) ) . '/languages' );
	}

	/**
	 * Absolute URL of a file inside the plugin folder.
	 *
	 * @param string $path Path relative to the plugin folder.
	 */
	public static function url( string $path ): string {
		return plugins_url( $path, PLUGIN_FILE );
	}
}
