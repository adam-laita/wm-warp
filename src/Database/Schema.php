<?php
/**
 * Tables of this site's database.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Database;

/**
 * Lists and describes tables. Views are not tables and are not migrated.
 */
final class Schema {

	public const MODE_PREFIX   = 'prefix';
	public const MODE_ALL      = 'all';
	public const MODE_SELECTED = 'selected';

	/**
	 * Tables of the database (without Warp's temporary and set-aside tables).
	 *
	 * @return list<array{name: string, rows: int, bytes: int}>
	 * @throws DatabaseError When the database reports an error.
	 */
	public static function tables(): array {
		$rows = Db::assoc(
			'SELECT TABLE_NAME, TABLE_ROWS, DATA_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA = '
			. Db::quote( Db::database() ) . " AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME"
		);

		$tables = array();
		foreach ( $rows as $row ) {
			$name = (string) $row['TABLE_NAME'];

			if ( TableNames::is_internal( $name ) ) {
				continue;
			}

			$tables[] = array(
				'name'  => $name,
				'rows'  => (int) $row['TABLE_ROWS'],
				'bytes' => (int) $row['DATA_LENGTH'],
			);
		}

		return $tables;
	}

	/**
	 * Names of tables chosen for a migration.
	 *
	 * @param string   $mode     self::MODE_PREFIX, self::MODE_ALL or self::MODE_SELECTED.
	 * @param string[] $selected Chosen tables for self::MODE_SELECTED.
	 * @phpstan-param list<string> $selected
	 * @param string   $prefix   Table prefix of this site.
	 * @return list<string>
	 * @throws DatabaseError When the database reports an error.
	 */
	public static function choose( string $mode, array $selected, string $prefix ): array {
		$names = array_map( static fn( array $table ): string => $table['name'], self::tables() );

		return array_values(
			array_filter(
				$names,
				static fn( string $name ): bool => match ( $mode ) {
					self::MODE_ALL      => true,
					self::MODE_SELECTED => in_array( $name, $selected, true ),
					default             => str_starts_with( $name, $prefix ),
				}
			)
		);
	}

	/**
	 * Structure of a table.
	 *
	 * @param string $table Table name.
	 * @throws DatabaseError When the table does not exist or the database reports an error.
	 */
	public static function describe( string $table ): TableInfo {
		$create = Db::rows( 'SHOW CREATE TABLE ' . Db::ident( $table ) );
		$where  = ' WHERE TABLE_SCHEMA = ' . Db::quote( Db::database() ) . ' AND TABLE_NAME = ' . Db::quote( $table );

		$columns = array();
		$types   = array();
		foreach ( Db::assoc( 'SELECT COLUMN_NAME, DATA_TYPE, EXTRA FROM information_schema.COLUMNS' . $where . ' ORDER BY ORDINAL_POSITION' ) as $column ) {
			$types[ (string) $column['COLUMN_NAME'] ] = strtolower( (string) $column['DATA_TYPE'] );

			$columns[] = array(
				'name'      => (string) $column['COLUMN_NAME'],
				'kind'      => TableInfo::kind_of( (string) $column['DATA_TYPE'] ),
				// "DEFAULT_GENERATED" only marks an expression default; generated columns are VIRTUAL, STORED or PERSISTENT.
				'generated' => 1 === preg_match( '/\b(VIRTUAL|STORED|PERSISTENT)\b/i', (string) $column['EXTRA'] ),
			);
		}

		$info  = Db::assoc( 'SELECT TABLE_ROWS, DATA_LENGTH FROM information_schema.TABLES' . $where );
		$key   = self::key( $where );
		$range = self::key_range( $table, $key, $types );

		return new TableInfo(
			$table,
			(string) ( $create[0][1] ?? '' ),
			$columns,
			$key,
			(int) ( $info[0]['TABLE_ROWS'] ?? 0 ),
			(int) ( $info[0]['DATA_LENGTH'] ?? 0 ),
			$range[0],
			$range[1]
		);
	}

	/**
	 * Smallest and largest value of a key made of one integer column (read from the index, fast).
	 * Shows how far the reading of a table got; other keys give [0, 0].
	 *
	 * @param string                $table Table.
	 * @param string[]              $key   Key columns.
	 * @param array<string, string> $types Data types by column.
	 * @return array{0: int, 1: int}
	 * @throws DatabaseError When the database reports an error.
	 */
	private static function key_range( string $table, array $key, array $types ): array {
		if ( 1 !== count( $key ) || ! in_array( $types[ $key[0] ] ?? '', array( 'tinyint', 'smallint', 'mediumint', 'int', 'bigint' ), true ) ) {
			return array( 0, 0 );
		}

		$row  = Db::assoc( 'SELECT MIN(' . Db::ident( $key[0] ) . ') AS low, MAX(' . Db::ident( $key[0] ) . ') AS high FROM ' . Db::ident( $table ) );
		$low  = (string) ( $row[0]['low'] ?? '' );
		$high = (string) ( $row[0]['high'] ?? '' );

		// Values beyond PHP integers (BIGINT UNSIGNED) are left out.
		if ( 1 !== preg_match( '/^-?\d{1,18}$/', $low ) || 1 !== preg_match( '/^-?\d{1,18}$/', $high ) ) {
			return array( 0, 0 );
		}

		return array( (int) $low, (int) $high );
	}

	/**
	 * Columns of the primary key, or of the first unique key whose columns are all NOT NULL.
	 *
	 * @param string $where Condition selecting the table in information_schema.
	 * @return list<string>
	 * @throws DatabaseError When the database reports an error.
	 */
	private static function key( string $where ): array {
		$indexes = array();
		$rows    = Db::assoc( 'SELECT INDEX_NAME, COLUMN_NAME, NULLABLE, SUB_PART FROM information_schema.STATISTICS' . $where . ' AND NON_UNIQUE = 0 ORDER BY INDEX_NAME, SEQ_IN_INDEX' );

		foreach ( $rows as $row ) {
			$name = (string) $row['INDEX_NAME'];

			if ( ! isset( $indexes[ $name ] ) ) {
				$indexes[ $name ] = array();
			}

			// A nullable or prefix column makes the key unusable for reading in order.
			$indexes[ $name ][] = ( 'YES' === $row['NULLABLE'] || null !== $row['SUB_PART'] ) ? null : (string) $row['COLUMN_NAME'];
		}

		if ( isset( $indexes['PRIMARY'] ) && ! in_array( null, $indexes['PRIMARY'], true ) ) {
			return array_values( array_filter( $indexes['PRIMARY'], 'is_string' ) );
		}

		foreach ( $indexes as $columns ) {
			if ( ! in_array( null, $columns, true ) ) {
				return array_values( array_filter( $columns, 'is_string' ) );
			}
		}

		return array();
	}
}
