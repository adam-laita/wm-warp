<?php
/**
 * Reading table rows on the source.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Database;

/**
 * Reads a table in chunks, in the order of its primary or unique key (keyset paging: every chunk
 * continues after the last key, so it stays fast on large tables). Tables without such a key are read
 * with LIMIT/OFFSET.
 */
final class Exporter {

	private const MAX_ROWS = 20000;

	/**
	 * Most tables in one batch.
	 */
	public const MAX_PARTS = 64;

	/**
	 * Reads chunks of several tables in turn until the budget is used: small tables share one request.
	 *
	 * Every part except the last one is a finished table. The rows of the first part follow its cursor;
	 * later tables start with the budget that is left.
	 *
	 * @param list<array{0: TableInfo, 1: array<string, mixed>}> $parts  Tables with their cursors, in order.
	 * @param int                                                $budget Target size of the batch in bytes.
	 * @param float                                              $seconds Stop starting new tables after this time.
	 * @return list<array{table: TableInfo, rows: list<list<string|null>>, cursor: array<string, mixed>, done: bool, bytes: int}>
	 * @throws DatabaseError When the database reports an error.
	 */
	public static function batch( array $parts, int $budget, float $seconds = 3.0 ): array {
		$started = microtime( true );
		$result  = array();
		$used    = 0;

		foreach ( array_slice( $parts, 0, self::MAX_PARTS ) as [ $table, $cursor ] ) {
			$chunk    = self::chunk( $table, $cursor, max( 16 * 1024, $budget - $used ) );
			$used    += $chunk['bytes'];
			$result[] = array( 'table' => $table ) + $chunk;

			if ( ! $chunk['done'] || $used >= $budget || microtime( true ) - $started > $seconds ) {
				break;
			}
		}

		return $result;
	}

	/**
	 * Reads one chunk.
	 *
	 * @param TableInfo            $table  Table.
	 * @param array<string, mixed> $cursor Cursor from the previous chunk, empty for the first one.
	 * @param int                  $budget Target size of the chunk in bytes.
	 * @return array{rows: list<list<string|null>>, cursor: array<string, mixed>, done: bool, bytes: int}
	 * @throws DatabaseError When the database reports an error.
	 */
	public static function chunk( TableInfo $table, array $cursor, int $budget ): array {
		$columns = $table->data_columns();
		$names   = array_map( static fn( array $column ): string => $column['name'], $columns );
		$limit   = isset( $cursor['n'] ) && is_int( $cursor['n'] ) ? $cursor['n'] : self::initial_rows( $table, $budget );
		$limit   = max( 1, min( self::MAX_ROWS, $limit ) );
		$where   = self::filter( $table->name );
		$keyed   = $table->has_usable_key();

		$select = array_map(
			static fn( array $column ): string => TableInfo::BIT === $column['kind']
				? 'CAST(' . Db::ident( $column['name'] ) . ' AS UNSIGNED)'
				: Db::ident( $column['name'] ),
			$columns
		);

		$sql = 'SELECT ' . implode( ', ', $select ) . ' FROM ' . Db::ident( $table->name );

		if ( $keyed && isset( $cursor['after'] ) && is_array( $cursor['after'] ) ) {
			$where[] = self::after( $table, $columns, array_values( $cursor['after'] ) );
		}

		if ( array() !== $where ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}

		if ( $keyed ) {
			$sql .= ' ORDER BY ' . implode( ', ', array_map( array( Db::class, 'ident' ), $table->key ) ) . ' LIMIT ' . $limit;
		} else {
			$offset = isset( $cursor['offset'] ) && is_int( $cursor['offset'] ) ? $cursor['offset'] : 0;
			$sql   .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
		}

		$rows  = Db::rows( $sql );
		$bytes = 0;

		foreach ( $rows as $row ) {
			foreach ( $row as $value ) {
				$bytes += null === $value ? 1 : strlen( $value ) + 4;
			}
		}

		$next = array( 'n' => max( 1, min( self::MAX_ROWS, (int) round( $limit * $budget / max( 1, $bytes ) ) ) ) );

		if ( array() === $rows ) {
			$next['n'] = $limit;
		} elseif ( $keyed ) {
			$last = $rows[ count( $rows ) - 1 ];
			// Base64: key values may be binary (e.g. IP addresses in VARBINARY) and the cursor travels as JSON.
			$next['after'] = array_map( static fn( string $column ): string => base64_encode( (string) $last[ (int) array_search( $column, $names, true ) ] ), $table->key ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Transport encoding of key values.
		} else {
			$next['offset'] = ( isset( $cursor['offset'] ) && is_int( $cursor['offset'] ) ? $cursor['offset'] : 0 ) + count( $rows );
		}

		return array(
			'rows'   => $rows,
			'cursor' => $next,
			'done'   => count( $rows ) < $limit,
			'bytes'  => $bytes,
		);
	}

	/**
	 * Number of rows for the first chunk from the average row size.
	 *
	 * @param TableInfo $table  Table.
	 * @param int       $budget Target size in bytes.
	 */
	private static function initial_rows( TableInfo $table, int $budget ): int {
		$average = $table->rows > 0 ? intdiv( $table->bytes, $table->rows ) : 1000;

		return intdiv( $budget, max( 64, $average ) );
	}

	/**
	 * Condition for rows after the last key of the previous chunk.
	 *
	 * @param TableInfo                                                $table   Table.
	 * @param list<array{name: string, kind: string, generated: bool}> $columns Data columns.
	 * @param array                                                    $after   Last key values.
	 * @phpstan-param list<mixed> $after
	 * @throws \UnexpectedValueException When the cursor does not match the key.
	 */
	private static function after( TableInfo $table, array $columns, array $after ): string {
		if ( count( $after ) !== count( $table->key ) ) {
			throw new \UnexpectedValueException( 'Invalid cursor.' );
		}

		$kinds = array();
		foreach ( $columns as $column ) {
			$kinds[ $column['name'] ] = $column['kind'];
		}

		$left  = array();
		$right = array();
		foreach ( $table->key as $index => $column ) {
			$value = base64_decode( (string) $after[ $index ], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Transport encoding of key values.

			if ( false === $value ) {
				throw new \UnexpectedValueException( 'Invalid cursor.' );
			}

			$left[]  = Db::ident( $column );
			$right[] = TableInfo::BINARY === ( $kinds[ $column ] ?? '' ) ? Db::hex( (string) $value ) : Db::quote( (string) $value );
		}

		if ( 1 === count( $left ) ) {
			return $left[0] . ' > ' . $right[0];
		}

		return '(' . implode( ', ', $left ) . ') > (' . implode( ', ', $right ) . ')';
	}

	/**
	 * Rows that never leave the site: Warp's own options (identity and connections of this site).
	 *
	 * @param string $table Table name.
	 * @return list<string>
	 */
	private static function filter( string $table ): array {
		global $wpdb;

		if ( $wpdb->base_prefix . 'options' === $table ) {
			return array( "`option_name` NOT LIKE 'wm\\\\_warp\\\\_%'" );
		}

		return array();
	}
}
