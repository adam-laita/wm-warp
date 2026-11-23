<?php
/**
 * Percentage of a running migration.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Migration;

/**
 * Estimates how much of a migration is done. Pure, covered by unit tests.
 *
 * Tables weigh by their estimated size; inside a table the progress comes from the position of
 * the reading in the range of an integer key (exact for the usual auto-increment tables),
 * otherwise from transferred rows against the estimated row count. Files weigh by bytes once
 * the comparison knows them; until then the database counts as half of the work.
 * The shown value never goes back (Runner keeps the highest one).
 */
final class Progress {

	private const IN_PROGRESS = 0.99;

	/**
	 * Percentage 0–99 of a running migration (100 is shown only when it ends).
	 *
	 * @param array<string, mixed> $state Runner state.
	 */
	public static function percent( array $state ): int {
		$tables     = is_array( $state['tables'] ?? null ) ? $state['tables'] : array();
		$files      = is_array( $state['files'] ?? null ) ? $state['files'] : null;
		$db_total   = 0.0;
		$db_done    = 0.0;
		$has_tables = true === ( $state['database'] ?? false );

		foreach ( $tables as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$weight    = (float) max( 1, (int) ( $entry['info']['bytes'] ?? 0 ) );
			$db_total += $weight;
			$db_done  += $weight * self::table_fraction( $entry );
		}

		$db_fraction = $db_total > 0 ? $db_done / $db_total : 0.0;

		if ( $has_tables && in_array( $state['phase'] ?? '', array( 'files', 'swap', 'finish' ), true ) ) {
			$db_fraction = 1.0;
		}

		if ( null === $files ) {
			$fraction = $db_fraction;
		} else {
			$files_total = (int) ( $files['total'] ?? 0 );
			$files_known = $files_total > 0 || in_array( $files['step'] ?? '', array( 'transfer', 'swap', 'swapped' ), true );
			$files_done  = $files_total > 0 ? min( 1.0, (int) ( $files['sent'] ?? 0 ) / $files_total ) : ( $files_known ? 1.0 : 0.0 );

			if ( ! $has_tables ) {
				$fraction = $files_done;
			} elseif ( ! $files_known || $db_total <= 0 ) {
				$fraction = $db_fraction / 2;
			} else {
				$fraction = ( $db_total * $db_fraction + $files_total * $files_done ) / ( $db_total + $files_total );
			}
		}

		return (int) min( 99, floor( 100 * $fraction ) );
	}

	/**
	 * How much of one table is transferred, 0–1.
	 *
	 * @param array<string, mixed> $entry Table entry of the state: info, cursor, rows, done.
	 */
	public static function table_fraction( array $entry ): float {
		if ( true === ( $entry['done'] ?? false ) ) {
			return 1.0;
		}

		$low   = (int) ( $entry['info']['low'] ?? 0 );
		$high  = (int) ( $entry['info']['high'] ?? 0 );
		$after = $entry['cursor']['after'][0] ?? null;

		if ( $high > $low && is_string( $after ) ) {
			$position = base64_decode( $after, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Transport encoding of key values.

			if ( false !== $position && 1 === preg_match( '/^-?\d+$/', $position ) ) {
				return min( self::IN_PROGRESS, max( 0.0, ( (int) $position - $low + 1 ) / ( $high - $low + 1 ) ) );
			}
		}

		$estimate = (int) ( $entry['info']['rows'] ?? 0 );

		return $estimate > 0 ? min( self::IN_PROGRESS, (int) ( $entry['rows'] ?? 0 ) / $estimate ) : 0.0;
	}
}
