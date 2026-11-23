<?php
/**
 * Database part of the Warp API.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Api;

use WM\Warp\Database\Exporter;
use WM\Warp\Database\Importer;
use WM\Warp\Database\RowCodec;
use WM\Warp\Database\Schema;
use WM\Warp\Database\TableInfo;
use WM\Warp\Sites\Connection;

/**
 * Requests of a migration: this site is the source (pull: tables, export) or the target (push: begin,
 * import, finish, confirm, rollback, abort). Requests are already authenticated by RestController.
 */
final class DatabaseRoutes {

	public const ROUTES = array( 'db/tables', 'db/export', 'db/begin', 'db/import', 'db/finish', 'db/confirm', 'db/rollback', 'db/abort' );

	/**
	 * Handles a request.
	 *
	 * @param string               $route      Route below the namespace.
	 * @param array<string, mixed> $data       Request data.
	 * @param string               $payload    Binary payload.
	 * @param Connection           $connection Connection of the requesting site.
	 * @return array{data: array<string, mixed>, payload: string}
	 * @throws \UnexpectedValueException When the request is invalid.
	 */
	public static function handle( string $route, array $data, string $payload, Connection $connection ): array {
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Large chunks of rows.
		}
		wp_raise_memory_limit( 'admin' );

		$result = match ( $route ) {
			'db/tables'   => self::tables( $data ),
			'db/export'   => self::export( $data ),
			'db/begin'    => self::begin( $data, $connection ),
			'db/import'   => self::import( $data, $payload ),
			'db/finish'   => array( 'data' => Importer::finish( self::run_id( $data ), self::expected( $data ) ) ),
			'db/confirm'  => self::confirm(),
			'db/rollback' => self::rollback(),
			'db/abort'    => self::abort( $data ),
			default       => throw new \UnexpectedValueException( 'Unknown route.' ),
		};

		return array(
			'data'    => $result['data'],
			'payload' => $result['payload'] ?? '',
		);
	}

	/**
	 * Tables chosen for a migration with their structure.
	 *
	 * @param array<string, mixed> $data Request data: mode, selected.
	 * @return array{data: array<string, mixed>}
	 */
	private static function tables( array $data ): array {
		global $wpdb;

		$mode     = is_string( $data['mode'] ?? null ) ? $data['mode'] : Schema::MODE_PREFIX;
		$selected = array_values( array_filter( is_array( $data['selected'] ?? null ) ? $data['selected'] : array(), 'is_string' ) );
		$names    = Schema::choose( $mode, $selected, $wpdb->base_prefix );
		$describe = true === ( $data['describe'] ?? true );

		return array(
			'data' => array(
				'all'    => Schema::tables(),
				'tables' => $describe ? array_map( static fn( string $name ): array => Schema::describe( $name )->to_array(), $names ) : array(),
			),
		);
	}

	/**
	 * One chunk of rows of a table.
	 *
	 * @param array<string, mixed> $data Request data: table, cursor, budget.
	 * @return array{data: array<string, mixed>, payload: string}
	 * @throws \UnexpectedValueException When the table does not exist.
	 */
	private static function export( array $data ): array {
		$name  = is_string( $data['table'] ?? null ) ? $data['table'] : '';
		$names = array_map( static fn( array $table ): string => $table['name'], Schema::tables() );

		if ( ! in_array( $name, $names, true ) ) {
			throw new \UnexpectedValueException( 'The table does not exist on the source.' );
		}

		$table  = Schema::describe( $name );
		$cursor = is_array( $data['cursor'] ?? null ) ? self::string_keys( $data['cursor'] ) : array();
		$budget = is_int( $data['budget'] ?? null ) ? max( 64 * 1024, min( 64 * 1024 * 1024, $data['budget'] ) ) : 1024 * 1024;
		$chunk  = Exporter::chunk( $table, $cursor, $budget );

		return array(
			'data'    => array(
				'columns' => $table->data_column_names(),
				'count'   => count( $chunk['rows'] ),
				'cursor'  => $chunk['cursor'],
				'done'    => $chunk['done'],
				'bytes'   => $chunk['bytes'],
			),
			'payload' => RowCodec::encode( $chunk['rows'] ),
		);
	}

	/**
	 * Starts loading on this site.
	 *
	 * @param array<string, mixed> $data       Request data: run_id, source_prefix, tables.
	 * @param Connection           $connection Requesting site.
	 * @return array{data: array<string, mixed>}
	 */
	private static function begin( array $data, Connection $connection ): array {
		$tables = array_map( array( TableInfo::class, 'from_array' ), is_array( $data['tables'] ?? null ) ? array_values( $data['tables'] ) : array() );

		Importer::begin( self::run_id( $data ), $connection->name, is_string( $data['source_prefix'] ?? null ) ? $data['source_prefix'] : '', $tables );

		return array( 'data' => array( 'started' => true ) );
	}

	/**
	 * Writes rows on this site.
	 *
	 * @param array<string, mixed> $data    Request data: run_id, table, columns.
	 * @param string               $payload Encoded rows.
	 * @return array{data: array<string, mixed>}
	 */
	private static function import( array $data, string $payload ): array {
		$columns = array_values( array_filter( is_array( $data['columns'] ?? null ) ? $data['columns'] : array(), 'is_string' ) );
		$rows    = '' === $payload ? array() : RowCodec::decode( $payload, count( $columns ) );

		return array(
			'data' => array(
				'inserted' => Importer::insert( self::run_id( $data ), is_string( $data['table'] ?? null ) ? $data['table'] : '', $columns, $rows, is_int( $data['seq'] ?? null ) ? $data['seq'] : 0 ),
			),
		);
	}

	/**
	 * Confirms the migration on this site.
	 *
	 * @return array{data: array<string, mixed>}
	 */
	private static function confirm(): array {
		Importer::confirm();

		return array( 'data' => array( 'confirmed' => true ) );
	}

	/**
	 * Undoes the migration on this site.
	 *
	 * @return array{data: array<string, mixed>}
	 */
	private static function rollback(): array {
		Importer::rollback();

		return array( 'data' => array( 'rolled_back' => true ) );
	}

	/**
	 * Discards an unfinished load on this site.
	 *
	 * @param array<string, mixed> $data Request data: run_id.
	 * @return array{data: array<string, mixed>}
	 */
	private static function abort( array $data ): array {
		Importer::abort( self::run_id( $data ) );

		return array( 'data' => array( 'aborted' => true ) );
	}

	/**
	 * Run ID from request data.
	 *
	 * @param array<string, mixed> $data Request data.
	 * @throws \UnexpectedValueException When it is missing.
	 */
	private static function run_id( array $data ): string {
		$run_id = is_string( $data['run_id'] ?? null ) ? $data['run_id'] : '';

		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $run_id ) ) {
			throw new \UnexpectedValueException( 'Invalid run ID.' );
		}

		return $run_id;
	}

	/**
	 * Expected row counts from request data.
	 *
	 * @param array<string, mixed> $data Request data.
	 * @return array<string, int>
	 */
	private static function expected( array $data ): array {
		$expected = array();

		foreach ( is_array( $data['expected'] ?? null ) ? $data['expected'] : array() as $table => $count ) {
			$expected[ (string) $table ] = (int) $count;
		}

		return $expected;
	}

	/**
	 * Array with string keys.
	 *
	 * @param array<mixed> $data Data.
	 * @return array<string, mixed>
	 */
	private static function string_keys( array $data ): array {
		$result = array();
		foreach ( $data as $key => $value ) {
			$result[ (string) $key ] = $value;
		}

		return $result;
	}
}
