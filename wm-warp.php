<?php
/**
 * Plugin Name:       WM Warp
 * Plugin URI:        https://github.com/adam-laita/wm-warp
 * Description:       Data migration and synchronization between WordPress sites.
 * Version:           0.1.1
 * Requires at least: 6.6
 * Requires PHP:      8.3
 * Author:            WebManiak
 * Author URI:        https://webmaniak.cz
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wm-warp
 * Domain Path:       /languages
 * Update URI:        https://github.com/adam-laita/wm-warp
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp;

defined( 'ABSPATH' ) || exit;

const VERSION     = '0.1.1';
const PLUGIN_FILE = __FILE__;

/*
 * Class autoloader for the WM\Warp namespace (PSR-4, classes in src/).
 * The plugin has no runtime Composer dependencies, so vendor/ is never loaded here.
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = __NAMESPACE__ . '\\';

		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = __DIR__ . '/src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, array( Loader::class, 'ensure' ) );
register_deactivation_hook(
	__FILE__,
	static function (): void {
		Maintenance::unschedule();
		Loader::remove();
	}
);

Plugin::instance()->register();
