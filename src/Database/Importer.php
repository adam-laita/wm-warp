<?php
/**
 * Writing tables on the target.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Database;

use WM\Warp\Support\Storage;
use const WM\Warp\PLUGIN_FILE;

/**
 * Loads migrated tables into temporary tables and swaps them in with one RENAME TABLE.
 *
 * Replaced tables are set aside (not deleted) until the migration is confirmed, so Undo restores
 * the previous state exactly. The state lives in a file, which survives replacing the database.
 * Only one migration into this site can be in progress or waiting for confirmation.
 */
final class Importer {

	public const LOADING = 'loading';
	public const SWAPPED = 'swapped';

	private const STATE_DIR  = 'runs';
	private const STATE_NAME = 'import';

	private const MAX_STATEMENT = 16 * 1024 * 1024;

	/**
	 * Starts loading: creates a temporary table for every table.
	 *
	 * A previous unfinished load is discarded; a previous migration waiting for confirmation is confirmed.
	 *
	 * @param string      $run_id        Run ID.
	 * @param string      $source        Name of the source site, for the confirmation notice.
	 * @param string      $source_prefix Table prefix of the source.
	 * @param TableInfo[] $tables        Tables of the source.
	 * @phpstan-param list<TableInfo> $tables
	 * @throws DatabaseError When the database reports an error.
	 */
	public static function begin( string $run_id, string $source, string $source_prefix, array $tables ): void {
		global $wpdb;

		$previous = self::state();
		if ( null !== $previous ) {
			self::SWAPPED === $previous['status'] ? self::confirm() : self::abort();
		}

		self::drop_leftovers();

		$entries = array();
		foreach ( $tables as $table ) {
			$live      = TableNames::retarget( $table->name, $source_prefix, $wpdb->base_prefix );
			$temp      = TableNames::temp( $live );
			$rewritten = CreateRewriter::rewrite( $table->create, $temp, Db::is_mariadb(), array( Db::class, 'has_collation' ), $source_prefix, $wpdb->base_prefix );

			Db::query( 'DROP TABLE IF EXISTS ' . Db::ident( $temp ) );
			Db::query( $rewritten['create'] );

			$entries[] = array(
				'source'       => $table->name,
				'live'         => $live,
				'temp'         => $temp,
				'backup'       => TableNames::backup( $live ),
				'columns'      => array_map( static fn( array $column ): array => array( $column['name'], $column['kind'] ), $table->data_columns() ),
				'foreign_keys' => $rewritten['foreign_keys'],
				'collations'   => $rewritten['collations'],
				'inserted'     => 0,
				'existed'      => false,
			);
		}

		self::save(
			array(
				'run_id'        => $run_id,
				'status'        => self::LOADING,
				'source'        => $source,
				'source_prefix' => $source_prefix,
				'started'       => time(),
				'finished'      => 0,
				'tables'        => $entries,
				'old_keys'      => array(),
				'external_keys' => array(),
			)
		);
	}

	/**
	 * Writes rows into the temporary table.
	 *
	 * @param string                  $run_id  Run ID.
	 * @param string                  $source  Source table name.
	 * @param string[]                $columns Column names in the order of the values.
	 * @phpstan-param list<string> $columns
	 * @param list<list<string|null>> $rows    Rows.
	 * @param int                     $seq     Number of the chunk within the table (from 1); a chunk that was
	 *                                         already written (a repeated request after a timeout) is skipped.
	 *                                         0 = no check.
	 * @return int Rows written so far into this table.
	 * @throws \UnexpectedValueException When the run or table is unknown or the columns differ.
	 */
	public static function insert( string $run_id, string $source, array $columns, array $rows, int $seq = 0 ): int {
		$state = self::require_state( $run_id, self::LOADING );
		$index = self::table_index( $state, $source );
		$entry = $state['tables'][ $index ];
		$kinds = array();

		if ( $seq > 0 && $seq <= (int) ( $entry['seq'] ?? 0 ) ) {
			return (int) $entry['inserted'];
		}

		foreach ( $entry['columns'] as [ $name, $kind ] ) {
			$kinds[] = $kind;
			if ( ! in_array( $name, $columns, true ) ) {
				throw new \UnexpectedValueException( 'The columns of the table changed during the migration.' );
			}
		}

		if ( count( $columns ) !== count( $kinds ) ) {
			throw new \UnexpectedValueException( 'The columns of the table changed during the migration.' );
		}

		if ( array() !== $rows ) {
			self::write_rows( (string) $entry['temp'], $columns, $kinds, $rows );
		}

		$state['tables'][ $index ]['inserted'] = (int) $entry['inserted'] + count( $rows );
		$state['tables'][ $index ]['seq']      = $seq;
		self::save( $state );

		return $state['tables'][ $index ]['inserted'];
	}

	/**
	 * Checks row counts and swaps the temporary tables in.
	 *
	 * @param string             $run_id   Run ID.
	 * @param array<string, int> $expected Number of rows read on the source, by source table.
	 * @return array{tables: int, rows: int, collations: array<string, string>}
	 * @throws DatabaseError When the database reports an error.
	 * @throws \UnexpectedValueException When the run is unknown or a row count does not match.
	 */
	public static function finish( string $run_id, array $expected ): array {
		global $wpdb;

		$state      = self::require_state( $run_id, self::LOADING );
		$rows       = 0;
		$collations = array();

		foreach ( $state['tables'] as $entry ) {
			$count = (int) Db::value( 'SELECT COUNT(*) FROM ' . Db::ident( (string) $entry['temp'] ) );

			if ( ! isset( $expected[ $entry['source'] ] ) || $expected[ $entry['source'] ] !== $count ) {
				throw new \UnexpectedValueException(
					sprintf( 'Table %1$s: %2$d rows were read on the source, %3$d arrived.', $entry['source'], $expected[ $entry['source'] ] ?? 0, $count )
				);
			}

			$rows      += $count;
			$collations = array_merge( $collations, (array) $entry['collations'] );
		}

		$lives    = array_values( array_map( static fn( array $entry ): string => (string) $entry['live'], $state['tables'] ) );
		$existing = array_map( static fn( array $table ): string => $table['name'], Schema::tables() );

		foreach ( $state['tables'] as $index => $entry ) {
			$state['tables'][ $index ]['existed'] = in_array( $entry['live'], $existing, true );

			if ( $wpdb->base_prefix . 'options' === $entry['live'] ) {
				self::keep_options( (string) $entry['temp'], (string) $entry['live'], $state['tables'][ $index ]['existed'] );
			}
		}

		Db::query( 'SET SESSION foreign_key_checks = 0' );

		try {
			// Foreign keys of the replaced tables and of other tables pointing to them.
			$state['old_keys']      = array();
			$state['external_keys'] = self::external_keys( $lives );

			foreach ( $state['tables'] as $entry ) {
				if ( $entry['existed'] ) {
					$clauses = CreateRewriter::foreign_keys( (string) ( Db::rows( 'SHOW CREATE TABLE ' . Db::ident( (string) $entry['live'] ) )[0][1] ?? '' ) );
					foreach ( $clauses as $clause ) {
						$state['old_keys'][] = array( (string) $entry['live'], $clause );
					}
				}
			}

			$renames = array();
			foreach ( $state['tables'] as $entry ) {
				if ( $entry['existed'] ) {
					$renames[] = Db::ident( (string) $entry['live'] ) . ' TO ' . Db::ident( (string) $entry['backup'] );
				}
				$renames[] = Db::ident( (string) $entry['temp'] ) . ' TO ' . Db::ident( (string) $entry['live'] );
			}

			Db::query( 'DROP TABLE IF EXISTS ' . implode( ', ', array_map( static fn( array $entry ): string => Db::ident( (string) $entry['backup'] ), $state['tables'] ) ) );

			try {
				self::drop_keys( $state['old_keys'] );
				self::drop_keys( $state['external_keys'] );
				Db::query( 'RENAME TABLE ' . implode( ', ', $renames ) );
			} catch ( DatabaseError $error ) {
				// Nothing was swapped (RENAME TABLE is atomic): put the dropped keys back.
				self::add_keys_quietly( $state['old_keys'] );
				self::add_keys_quietly( $state['external_keys'] );
				throw $error;
			}

			$state['status']   = self::SWAPPED;
			$state['finished'] = time();
			self::save( $state );

			$new_keys = array();
			foreach ( $state['tables'] as $entry ) {
				foreach ( (array) $entry['foreign_keys'] as $clause ) {
					$new_keys[] = array( (string) $entry['live'], (string) $clause );
				}
			}

			self::add_keys( $new_keys );
			self::add_keys( $state['external_keys'] );
		} finally {
			Db::query( 'SET SESSION foreign_key_checks = 1' );
		}

		wp_cache_flush();

		return array(
			'tables'     => count( $state['tables'] ),
			'rows'       => $rows,
			'collations' => $collations,
		);
	}

	/**
	 * Confirms a migration: deletes the set-aside tables.
	 *
	 * @throws DatabaseError When the database reports an error.
	 */
	public static function confirm(): void {
		$state = self::state();

		if ( null === $state ) {
			return;
		}

		if ( self::SWAPPED === $state['status'] ) {
			$backups = array();
			foreach ( $state['tables'] as $entry ) {
				if ( $entry['existed'] ) {
					$backups[] = Db::ident( (string) $entry['backup'] );
				}
			}

			if ( array() !== $backups ) {
				Db::query( 'SET SESSION foreign_key_checks = 0' );
				Db::query( 'DROP TABLE IF EXISTS ' . implode( ', ', $backups ) );
				Db::query( 'SET SESSION foreign_key_checks = 1' );
			}
		}

		self::delete_state();
	}

	/**
	 * Undoes a migration: the set-aside tables return, tables new in the migration are removed.
	 *
	 * @throws \UnexpectedValueException When there is nothing to undo.
	 */
	public static function rollback(): void {
		$state = self::state();

		if ( null === $state || self::SWAPPED !== $state['status'] ) {
			throw new \UnexpectedValueException( 'There is no migration to undo.' );
		}

		$new_keys = array();
		foreach ( $state['tables'] as $entry ) {
			foreach ( (array) $entry['foreign_keys'] as $clause ) {
				$new_keys[] = array( (string) $entry['live'], (string) $clause );
			}
		}

		Db::query( 'SET SESSION foreign_key_checks = 0' );

		try {
			self::drop_keys( $state['external_keys'], true );
			self::drop_keys( $new_keys, true );

			$renames = array();
			foreach ( $state['tables'] as $entry ) {
				$renames[] = Db::ident( (string) $entry['live'] ) . ' TO ' . Db::ident( (string) $entry['temp'] );
				if ( $entry['existed'] ) {
					$renames[] = Db::ident( (string) $entry['backup'] ) . ' TO ' . Db::ident( (string) $entry['live'] );
				}
			}

			Db::query( 'RENAME TABLE ' . implode( ', ', $renames ) );
			Db::query( 'DROP TABLE IF EXISTS ' . implode( ', ', array_map( static fn( array $entry ): string => Db::ident( (string) $entry['temp'] ), $state['tables'] ) ) );

			self::add_keys( $state['old_keys'] );
			self::add_keys( $state['external_keys'] );
		} finally {
			Db::query( 'SET SESSION foreign_key_checks = 1' );
		}

		self::delete_state();
		wp_cache_flush();
	}

	/**
	 * Discards an unfinished load: drops the temporary tables. A swapped migration is left alone.
	 *
	 * @param string $run_id Run ID, empty for any run.
	 * @throws DatabaseError When the database reports an error.
	 */
	public static function abort( string $run_id = '' ): void {
		$state = self::state();

		if ( null === $state || self::LOADING !== $state['status'] || ( '' !== $run_id && $run_id !== $state['run_id'] ) ) {
			return;
		}

		Db::query( 'DROP TABLE IF EXISTS ' . implode( ', ', array_map( static fn( array $entry ): string => Db::ident( (string) $entry['temp'] ), $state['tables'] ) ) );
		self::delete_state();
	}

	/**
	 * Migration waiting for confirmation.
	 *
	 * @return array{run_id: string, source: string, finished: int, tables: int}|null
	 */
	public static function pending(): ?array {
		$state = self::state();

		if ( null === $state || self::SWAPPED !== $state['status'] ) {
			return null;
		}

		return array(
			'run_id'   => (string) $state['run_id'],
			'source'   => (string) $state['source'],
			'finished' => (int) $state['finished'],
			'tables'   => count( $state['tables'] ),
		);
	}

	/**
	 * Writes rows with multi-row INSERT statements in one transaction.
	 *
	 * @param string                  $table   Temporary table.
	 * @param string[]                $columns Column names.
	 * @phpstan-param list<string> $columns
	 * @param string[]                $kinds   Column kinds.
	 * @phpstan-param list<string> $kinds
	 * @param list<list<string|null>> $rows    Rows.
	 * @throws DatabaseError When the database reports an error.
	 */
	private static function write_rows( string $table, array $columns, array $kinds, array $rows ): void {
		$limit  = max( 1024 * 1024, min( self::MAX_STATEMENT, Db::max_packet() - 64 * 1024 ) );
		$head   = 'INSERT INTO ' . Db::ident( $table ) . ' (' . implode( ', ', array_map( array( Db::class, 'ident' ), $columns ) ) . ') VALUES ';
		$mode   = (string) Db::value( 'SELECT @@SESSION.sql_mode' );
		$values = array();
		$size   = 0;

		// Strict mode: a value that does not fit is an error, not a silent change. Zero dates stay allowed,
		// an explicit 0 in an AUTO_INCREMENT column stays 0.
		Db::query( "SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO,STRICT_ALL_TABLES', foreign_key_checks = 0, unique_checks = 0" );
		Db::query( 'START TRANSACTION' );

		try {
			foreach ( $rows as $row ) {
				$quoted = array();
				foreach ( $row as $i => $value ) {
					if ( null === $value ) {
						$quoted[] = 'NULL';
					} elseif ( TableInfo::BINARY === ( $kinds[ $i ] ?? '' ) ) {
						$quoted[] = Db::hex( $value );
					} elseif ( TableInfo::BIT === ( $kinds[ $i ] ?? '' ) && ctype_digit( $value ) ) {
						$quoted[] = $value;
					} else {
						$quoted[] = Db::quote( $value );
					}
				}

				$tuple = '(' . implode( ', ', $quoted ) . ')';

				if ( array() !== $values && $size + strlen( $tuple ) + strlen( $head ) > $limit ) {
					Db::query( $head . implode( ', ', $values ) );
					$values = array();
					$size   = 0;
				}

				$values[] = $tuple;
				$size    += strlen( $tuple ) + 2;
			}

			if ( array() !== $values ) {
				Db::query( $head . implode( ', ', $values ) );
			}

			Db::query( 'COMMIT' );
		} catch ( DatabaseError $error ) {
			Db::query( 'ROLLBACK' );
			throw $error;
		} finally {
			Db::query( 'SET SESSION sql_mode = ' . Db::quote( $mode ) . ', foreign_key_checks = 1, unique_checks = 1' );
		}
	}

	/**
	 * Keeps the identity and connections of this site and keeps Warp active.
	 *
	 * @param string $temp    Temporary options table.
	 * @param string $live    Current options table.
	 * @param bool   $existed Whether the current table exists.
	 * @throws DatabaseError When the database reports an error.
	 */
	private static function keep_options( string $temp, string $live, bool $existed ): void {
		$like = "`option_name` LIKE 'wm\\\\_warp\\\\_%'";

		Db::query( 'DELETE FROM ' . Db::ident( $temp ) . ' WHERE ' . $like );

		if ( $existed ) {
			Db::query(
				'INSERT INTO ' . Db::ident( $temp ) . ' (`option_name`, `option_value`, `autoload`) SELECT `option_name`, `option_value`, `autoload` FROM '
				. Db::ident( $live ) . ' WHERE ' . $like
			);
		}

		$plugin  = plugin_basename( PLUGIN_FILE );
		$current = Db::value( 'SELECT `option_value` FROM ' . Db::ident( $temp ) . " WHERE `option_name` = 'active_plugins'" );
		$active  = null === $current ? array() : unserialize( $current, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Core option, objects disallowed.

		if ( ! is_array( $active ) || in_array( $plugin, $active, true ) ) {
			return;
		}

		$active[] = $plugin;
		$value    = serialize( array_values( $active ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Same format as core.

		if ( null === $current ) {
			Db::query( 'INSERT INTO ' . Db::ident( $temp ) . " (`option_name`, `option_value`, `autoload`) VALUES ('active_plugins', " . Db::quote( $value ) . ", 'yes')" );
		} else {
			Db::query( 'UPDATE ' . Db::ident( $temp ) . ' SET `option_value` = ' . Db::quote( $value ) . " WHERE `option_name` = 'active_plugins'" );
		}
	}

	/**
	 * Foreign keys of other tables that point to replaced tables.
	 *
	 * @param string[] $lives Replaced tables.
	 * @phpstan-param list<string> $lives
	 * @return list<array{0: string, 1: string}> Owner table and clause.
	 * @throws DatabaseError When the database reports an error.
	 */
	private static function external_keys( array $lives ): array {
		if ( array() === $lives ) {
			return array();
		}

		$owners = Db::rows(
			'SELECT DISTINCT TABLE_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ' . Db::quote( Db::database() )
			. ' AND REFERENCED_TABLE_NAME IN (' . implode( ', ', array_map( array( Db::class, 'quote' ), $lives ) ) . ')'
		);

		$keys = array();
		foreach ( $owners as $owner ) {
			$table = (string) $owner[0];

			if ( in_array( $table, $lives, true ) ) {
				continue;
			}

			$create = (string) ( Db::rows( 'SHOW CREATE TABLE ' . Db::ident( $table ) )[0][1] ?? '' );
			foreach ( CreateRewriter::foreign_keys( $create ) as $clause ) {
				foreach ( $lives as $live ) {
					if ( str_contains( $clause, 'REFERENCES ' . Db::ident( $live ) ) ) {
						$keys[] = array( $table, $clause );
						break;
					}
				}
			}
		}

		return $keys;
	}

	/**
	 * Drops foreign keys.
	 *
	 * @param array $keys       Owner table and clause pairs.
	 * @phpstan-param list<mixed> $keys
	 * @param bool  $if_present Ignore keys that are already gone.
	 * @throws DatabaseError When the database reports an error.
	 */
	private static function drop_keys( array $keys, bool $if_present = false ): void {
		foreach ( $keys as $key ) {
			if ( ! is_array( $key ) ) {
				continue;
			}

			try {
				Db::query( 'ALTER TABLE ' . Db::ident( (string) $key[0] ) . ' DROP FOREIGN KEY ' . Db::ident( CreateRewriter::constraint_name( (string) $key[1] ) ) );
			} catch ( DatabaseError $error ) {
				if ( ! $if_present ) {
					throw $error;
				}
			}
		}
	}

	/**
	 * Adds foreign keys.
	 *
	 * @param array $keys Owner table and clause pairs.
	 * @phpstan-param list<mixed> $keys
	 * @throws DatabaseError When the database reports an error.
	 */
	private static function add_keys( array $keys ): void {
		foreach ( $keys as $key ) {
			if ( is_array( $key ) ) {
				Db::query( 'ALTER TABLE ' . Db::ident( (string) $key[0] ) . ' ADD ' . (string) $key[1] );
			}
		}
	}

	/**
	 * Adds foreign keys that may already exist, ignoring errors.
	 *
	 * @param array $keys Owner table and clause pairs.
	 * @phpstan-param list<mixed> $keys
	 */
	private static function add_keys_quietly( array $keys ): void {
		foreach ( $keys as $key ) {
			try {
				self::add_keys( array( $key ) );
			} catch ( DatabaseError $error ) {
				unset( $error );
			}
		}
	}

	/**
	 * Drops temporary tables that no migration owns (left behind by a crashed run).
	 *
	 * @throws DatabaseError When the database reports an error.
	 */
	private static function drop_leftovers(): void {
		$rows = Db::rows(
			'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ' . Db::quote( Db::database() )
			. " AND TABLE_NAME LIKE 'wmwt\\\\_%'"
		);

		if ( array() === $rows ) {
			return;
		}

		Db::query( 'SET SESSION foreign_key_checks = 0' );
		Db::query( 'DROP TABLE IF EXISTS ' . implode( ', ', array_map( static fn( array $row ): string => Db::ident( (string) $row[0] ), $rows ) ) );
		Db::query( 'SET SESSION foreign_key_checks = 1' );
	}

	/**
	 * Index of a table in the state.
	 *
	 * @param array<string, mixed> $state  State.
	 * @param string               $source Source table name.
	 * @throws \UnexpectedValueException When the table is not part of the run.
	 */
	private static function table_index( array $state, string $source ): int {
		foreach ( $state['tables'] as $index => $entry ) {
			if ( $entry['source'] === $source ) {
				return (int) $index;
			}
		}

		throw new \UnexpectedValueException( 'The table is not part of this migration.' );
	}

	/**
	 * State of the current run with the given status.
	 *
	 * @param string $run_id Run ID.
	 * @param string $status Expected status.
	 * @return array<string, mixed>
	 * @throws \UnexpectedValueException When there is no such run.
	 */
	private static function require_state( string $run_id, string $status ): array {
		$state = self::state();

		if ( null === $state || $run_id !== $state['run_id'] || $status !== $state['status'] ) {
			throw new \UnexpectedValueException( 'The migration is no longer in progress on this site; it was cancelled or another migration started.' );
		}

		return $state;
	}

	/**
	 * Current state.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function state(): ?array {
		$state = Storage::read_json( self::STATE_DIR, self::STATE_NAME );

		return null !== $state && isset( $state['run_id'], $state['status'], $state['tables'] ) && is_array( $state['tables'] ) ? $state : null;
	}

	/**
	 * Saves the state.
	 *
	 * @param array<string, mixed> $state State.
	 */
	private static function save( array $state ): void {
		Storage::write_json( self::STATE_DIR, self::STATE_NAME, $state );
	}

	/**
	 * Deletes the state.
	 */
	private static function delete_state(): void {
		Storage::delete_json( self::STATE_DIR, self::STATE_NAME );
	}
}
