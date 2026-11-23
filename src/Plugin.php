<?php
/**
 * Plugin bootstrap.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp;

use WM\Warp\Admin\Actions;
use WM\Warp\Admin\AdminPage;
use WM\Warp\Admin\Flash;
use WM\Warp\Admin\MigrationAjax;
use WM\Warp\Api\RestController;
use WM\Warp\Security\Vault;
use WM\Warp\Sites\ConnectionStore;
use WM\Warp\Sites\SiteIdentity;

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
		add_action( 'plugins_loaded', array( $this, 'boot' ) );
	}

	/**
	 * Creates the services once WordPress has loaded the salts and plugins.
	 */
	public function boot(): void {
		$vault    = Vault::for_site();
		$identity = new SiteIdentity( $vault );
		$outgoing = new ConnectionStore( ConnectionStore::OUTGOING, $vault );
		$incoming = new ConnectionStore( ConnectionStore::INCOMING, $vault );

		( new RestController( $identity, $incoming ) )->register();

		if ( is_admin() ) {
			$flash = new Flash( $vault );
			( new AdminPage( $identity, $outgoing, $incoming, $flash ) )->register();
			( new Actions( $identity, $outgoing, $incoming, $flash ) )->register();
			( new MigrationAjax( $identity, $outgoing ) )->register();
		}

		Maintenance::register();
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
