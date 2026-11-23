<?php
/**
 * Structure of one table.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Database;

/**
 * Table definition, columns and the key used for reading it in order.
 * Pure value object; travels between sites as an array.
 */
final class TableInfo {

	public const TEXT   = 'text';
	public const BINARY = 'binary';
	public const OTHER  = 'other';
	public const BIT    = 'bit';

	private const BINARY_TYPES = array( 'binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'geometry', 'point', 'linestring', 'polygon', 'multipoint', 'multilinestring', 'multipolygon', 'geometrycollection', 'geomcollection' );
	private const TEXT_TYPES   = array( 'char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext', 'enum', 'set', 'json' );

	/**
	 * Constructor.
	 *
	 * @param string                                                   $name    Table name.
	 * @param string                                                   $create  CREATE TABLE statement.
	 * @param list<array{name: string, kind: string, generated: bool}> $columns Columns in table order.
	 * @param string[]                                                 $key     Columns of the primary key or a unique NOT NULL key; empty = none.
	 * @phpstan-param list<string> $key
	 * @param int                                                      $rows    Estimated number of rows.
	 * @param int                                                      $bytes   Estimated size of data in bytes.
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $create,
		public readonly array $columns,
		public readonly array $key,
		public readonly int $rows,
		public readonly int $bytes
	) {}

	/**
	 * Kind of a column for find & replace and quoting.
	 *
	 * Binary columns (BLOB, BINARY, geometry) travel byte for byte and are never searched;
	 * text columns are searched; numbers and dates are left alone.
	 *
	 * @param string $data_type DATA_TYPE from information_schema.
	 */
	public static function kind_of( string $data_type ): string {
		$type = strtolower( $data_type );

		// PHP returns BIT values as decimal numbers, so they travel as numbers (F1c).
		if ( 'bit' === $type ) {
			return self::BIT;
		}

		if ( in_array( $type, self::BINARY_TYPES, true ) ) {
			return self::BINARY;
		}

		return in_array( $type, self::TEXT_TYPES, true ) ? self::TEXT : self::OTHER;
	}

	/**
	 * Columns that carry data (generated columns are computed by the database).
	 *
	 * @return list<array{name: string, kind: string, generated: bool}>
	 */
	public function data_columns(): array {
		return array_values( array_filter( $this->columns, static fn( array $column ): bool => ! $column['generated'] ) );
	}

	/**
	 * Names of the data columns.
	 *
	 * @return list<string>
	 */
	public function data_column_names(): array {
		return array_map( static fn( array $column ): string => $column['name'], $this->data_columns() );
	}

	/**
	 * Whether the key can be used for reading in order (all its columns carry data).
	 */
	public function has_usable_key(): bool {
		if ( array() === $this->key ) {
			return false;
		}

		$names = $this->data_column_names();

		foreach ( $this->key as $column ) {
			if ( ! in_array( $column, $names, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Array for transport.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'name'    => $this->name,
			'create'  => $this->create,
			'columns' => $this->columns,
			'key'     => $this->key,
			'rows'    => $this->rows,
			'bytes'   => $this->bytes,
		);
	}

	/**
	 * Table from transported data.
	 *
	 * @param mixed $data Data from to_array().
	 * @throws \UnexpectedValueException When the data are invalid.
	 */
	public static function from_array( mixed $data ): self {
		if ( ! is_array( $data ) || ! is_string( $data['name'] ?? null ) || ! is_string( $data['create'] ?? null ) || ! is_array( $data['columns'] ?? null ) || ! is_array( $data['key'] ?? null ) ) {
			throw new \UnexpectedValueException( 'Invalid table data.' );
		}

		$columns = array();
		foreach ( $data['columns'] as $column ) {
			if ( ! is_array( $column ) || ! is_string( $column['name'] ?? null ) || ! in_array( $column['kind'] ?? null, array( self::TEXT, self::BINARY, self::OTHER, self::BIT ), true ) ) {
				throw new \UnexpectedValueException( 'Invalid column data.' );
			}

			$columns[] = array(
				'name'      => $column['name'],
				'kind'      => (string) $column['kind'],
				'generated' => true === ( $column['generated'] ?? false ),
			);
		}

		$key = array();
		foreach ( $data['key'] as $column ) {
			if ( ! is_string( $column ) ) {
				throw new \UnexpectedValueException( 'Invalid key data.' );
			}
			$key[] = $column;
		}

		return new self( $data['name'], $data['create'], $columns, $key, (int) ( $data['rows'] ?? 0 ), (int) ( $data['bytes'] ?? 0 ) );
	}
}
