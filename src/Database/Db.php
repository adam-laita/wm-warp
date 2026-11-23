<?php
/**
 * Thin layer over wpdb.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Database;

/**
 * Runs queries and turns every database error into a DatabaseError (WP Migrate ignores some of them,
 * which ends with missing or empty tables after a "successful" migration).
 *
 * Table names and values are always quoted here; no SQL from another site is ever executed.
 */
final class Db {

	/**
	 * Collations supported by this server.
	 *
	 * @var array<string, true>|null
	 */
	private static ?array $collations = null;

	/**
	 * Runs a statement.
	 *
	 * @param string $sql SQL.
	 * @return int Affected rows.
	 * @throws DatabaseError When the database reports an error.
	 */
	public static function query( string $sql ): int {
		global $wpdb;

		$wpdb->last_error = '';
		$result           = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Identifiers and values are quoted by this class.

		$error = self::last_error();

		if ( false === $result || '' !== $error ) {
			throw new DatabaseError( '' !== $error ? $error : 'Unknown database error.', $sql );
		}

		return is_int( $result ) ? $result : 0;
	}

	/**
	 * Rows of a query, values exactly as the database returned them.
	 *
	 * @param string $sql SQL.
	 * @return list<list<string|null>>
	 * @throws DatabaseError When the database reports an error.
	 */
	public static function rows( string $sql ): array {
		global $wpdb;

		$wpdb->last_error = '';
		$rows             = $wpdb->get_results( $sql, ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Identifiers and values are quoted by this class.
		$error            = self::last_error();

		if ( '' !== $error ) {
			throw new DatabaseError( $error, $sql );
		}

		$wpdb->flush();
		$result = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$values = array();
			foreach ( (array) $row as $value ) {
				$values[] = null === $value ? null : (string) $value;
			}
			$result[] = $values;
		}

		return $result;
	}

	/**
	 * Rows of a query as associative arrays.
	 *
	 * @param string $sql SQL.
	 * @return list<array<string, string|null>>
	 * @throws DatabaseError When the database reports an error.
	 */
	public static function assoc( string $sql ): array {
		global $wpdb;

		$wpdb->last_error = '';
		$rows             = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Identifiers and values are quoted by this class.
		$error            = self::last_error();

		if ( '' !== $error ) {
			throw new DatabaseError( $error, $sql );
		}

		$result = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$values = array();
			foreach ( (array) $row as $key => $value ) {
				$values[ strtoupper( (string) $key ) ] = null === $value ? null : (string) $value;
			}
			$result[] = $values;
		}

		return $result;
	}

	/**
	 * First value of a query.
	 *
	 * @param string $sql SQL.
	 * @throws DatabaseError When the database reports an error.
	 */
	public static function value( string $sql ): ?string {
		$rows = self::rows( $sql );

		return $rows[0][0] ?? null;
	}

	/**
	 * Error of the last query, empty when it succeeded.
	 */
	private static function last_error(): string {
		global $wpdb;

		return $wpdb->last_error;
	}

	/**
	 * Quoted string value.
	 *
	 * @param string $value Value.
	 */
	public static function quote( string $value ): string {
		global $wpdb;

		return "'" . $wpdb->_real_escape( $value ) . "'";
	}

	/**
	 * Binary value as a hexadecimal literal (keeps every byte, also for BIT and geometry).
	 *
	 * @param string $value Value.
	 */
	public static function hex( string $value ): string {
		return '' === $value ? "''" : '0x' . bin2hex( $value );
	}

	/**
	 * Quoted identifier.
	 *
	 * @param string $name Table or column name.
	 */
	public static function ident( string $name ): string {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}

	/**
	 * Name of the current database.
	 */
	public static function database(): string {
		global $wpdb;

		return (string) $wpdb->dbname;
	}

	/**
	 * Whether the server is MariaDB.
	 */
	public static function is_mariadb(): bool {
		global $wpdb;

		return str_contains( strtolower( (string) $wpdb->db_server_info() ), 'mariadb' );
	}

	/**
	 * Whether this server knows a collation.
	 *
	 * @param string $collation Collation.
	 * @throws DatabaseError When the database reports an error.
	 */
	public static function has_collation( string $collation ): bool {
		if ( null === self::$collations ) {
			self::$collations = array();
			foreach ( self::rows( 'SHOW COLLATION' ) as $row ) {
				self::$collations[ strtolower( (string) $row[0] ) ] = true;
			}
		}

		return isset( self::$collations[ strtolower( $collation ) ] );
	}

	/**
	 * Largest statement the server accepts, in bytes.
	 *
	 * @throws DatabaseError When the database reports an error.
	 */
	public static function max_packet(): int {
		return (int) self::value( 'SELECT @@max_allowed_packet' );
	}
}
