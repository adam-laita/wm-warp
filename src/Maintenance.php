<?php
/**
 * Periodic housekeeping.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp;

use WM\Warp\Migration\Target;
use WM\Warp\Migration\Runner;
use WM\Warp\Support\Log;

/**
 * A migration that nobody confirmed is confirmed automatically after 7 days, so the set-aside tables
 * and files do not take up space forever. Runs daily through WP-Cron and on visits of the admin.
 */
final class Maintenance {

	public const HOOK = 'wm_warp_daily';

	private const CONFIRM_AFTER = 7 * DAY_IN_SECONDS;

	/**
	 * Registers hooks.
	 */
	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'run' ) );
		add_action( 'admin_init', array( self::class, 'run' ) );
		add_action(
			'init',
			static function (): void {
				if ( false === wp_next_scheduled( self::HOOK ) ) {
					wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
				}
			}
		);
	}

	/**
	 * Confirms old migrations.
	 */
	public static function run(): void {
		$limit  = time() - self::CONFIRM_AFTER;
		$import = Target::pending();

		try {
			if ( null !== $import && $import['finished'] < $limit ) {
				Target::confirm();
			}

			Runner::expire( $limit );

			if ( wp_doing_cron() ) {
				Log::cleanup();
			}
		} catch ( \Throwable $error ) {
			unset( $error );
		}
	}

	/**
	 * Removes the scheduled event (plugin deactivation).
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}
}
