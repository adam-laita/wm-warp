<?php
/**
 * Names of tables during a migration.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Database;

/**
 * Temporary tables (new data being loaded) and set-aside tables (replaced data kept for Undo).
 * Pure logic without WordPress calls.
 */
final class TableNames {

	public const TEMP_PREFIX   = 'wmwt_';
	public const BACKUP_PREFIX = 'wmwb_';

	private const MAX_LENGTH = 64;

	/**
	 * Name of the temporary table for a table.
	 *
	 * @param string $table Final table name.
	 */
	public static function temp( string $table ): string {
		return self::prefixed( self::TEMP_PREFIX, $table );
	}

	/**
	 * Name of the set-aside table for a replaced table.
	 *
	 * @param string $table Table name.
	 */
	public static function backup( string $table ): string {
		return self::prefixed( self::BACKUP_PREFIX, $table );
	}

	/**
	 * Whether a table belongs to Warp's migration machinery.
	 *
	 * @param string $table Table name.
	 */
	public static function is_internal( string $table ): bool {
		return str_starts_with( $table, self::TEMP_PREFIX ) || str_starts_with( $table, self::BACKUP_PREFIX );
	}

	/**
	 * Name of a source table on the target: the table prefix of the source is replaced by that of the target.
	 *
	 * @param string $table         Source table name.
	 * @param string $source_prefix Table prefix of the source.
	 * @param string $target_prefix Table prefix of the target.
	 */
	public static function retarget( string $table, string $source_prefix, string $target_prefix ): string {
		if ( '' === $source_prefix || ! str_starts_with( $table, $source_prefix ) ) {
			return $table;
		}

		return $target_prefix . substr( $table, strlen( $source_prefix ) );
	}

	/**
	 * Prefixed name within the identifier length limit (a hash keeps long names unique).
	 *
	 * @param string $prefix Prefix.
	 * @param string $table  Table name.
	 */
	private static function prefixed( string $prefix, string $table ): string {
		$name = $prefix . $table;

		if ( strlen( $name ) <= self::MAX_LENGTH ) {
			return $name;
		}

		$hash = substr( md5( $table ), 0, 8 ) . '_';

		return $prefix . $hash . substr( $table, -( self::MAX_LENGTH - strlen( $prefix ) - strlen( $hash ) ) );
	}
}
