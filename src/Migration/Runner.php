<?php
/**
 * Running a migration.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Migration;

use WM\Warp\Api\Client;
use WM\Warp\Api\ClientError;
use WM\Warp\Api\Endpoint;
use WM\Warp\Api\Environment;
use WM\Warp\Database\DatabaseError;
use WM\Warp\Database\Exporter;
use WM\Warp\Database\Importer;
use WM\Warp\Database\ReplaceRule;
use WM\Warp\Database\Replacer;
use WM\Warp\Database\RowCodec;
use WM\Warp\Database\RowTransformer;
use WM\Warp\Database\Schema;
use WM\Warp\Database\TableInfo;
use WM\Warp\Sites\Connection;
use WM\Warp\Sites\ConnectionStore;
use WM\Warp\Sites\Connector;
use WM\Warp\Sites\SiteIdentity;
use WM\Warp\Support\Storage;

/**
 * Drives a pull or push of the database on the site where it was started.
 *
 * The browser calls step() repeatedly; every step works for a few seconds and saves the state to a file
 * (it survives replacing the database). Rows are read in chunks whose size adapts to how long a chunk
 * takes. Find & replace runs here, on the site that started the migration (1.7).
 */
final class Runner {

	public const PULL = 'pull';
	public const PUSH = 'push';

	public const RUNNING     = 'running';
	public const DONE        = 'done';
	public const FAILED      = 'failed';
	public const CANCELLED   = 'cancelled';
	public const CONFIRMED   = 'confirmed';
	public const ROLLED_BACK = 'rolled_back';

	private const STATE_DIR  = 'runs';
	private const STATE_NAME = 'run';

	private const STEP_SECONDS = 5.0;
	private const MIN_BUDGET   = 256 * 1024;
	private const START_BUDGET = 1024 * 1024;
	private const MAX_BUDGET   = 32 * 1024 * 1024;
	private const MAX_RETRIES  = 3;

	/**
	 * Constructor.
	 *
	 * @param SiteIdentity    $identity Identity of this site.
	 * @param ConnectionStore $outgoing Sites this site connected to.
	 */
	public function __construct( private SiteIdentity $identity, private ConnectionStore $outgoing ) {}

	/**
	 * State of the last migration started on this site.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function current(): ?array {
		$state = Storage::read_json( self::STATE_DIR, self::STATE_NAME );

		return null !== $state && isset( $state['id'], $state['status'] ) ? $state : null;
	}

	/**
	 * Marks a finished migration older than the limit as confirmed. The target confirms its own
	 * set-aside tables after the same time (Maintenance), so no request is needed.
	 *
	 * @param int $limit Unix time; migrations finished before it expire.
	 */
	public static function expire( int $limit ): void {
		$state = self::current();

		if ( null !== $state && self::DONE === $state['status'] && (int) $state['finished'] < $limit ) {
			$state['status'] = self::CONFIRMED;
			Storage::write_json( self::STATE_DIR, self::STATE_NAME, $state );
		}
	}

	/**
	 * Starts a migration.
	 *
	 * @param Connection    $connection Other site.
	 * @param string        $direction  self::PULL or self::PUSH.
	 * @param string        $mode       Table selection (Schema::MODE_*).
	 * @param string[]      $selected   Chosen tables (names on the source).
	 * @phpstan-param list<string> $selected
	 * @param bool          $protocol   Whether to apply the protocol rule.
	 * @param ReplaceRule[] $custom     Custom find & replace rules.
	 * @phpstan-param list<ReplaceRule> $custom
	 * @return array<string, mixed> State.
	 * @throws \RuntimeException When another migration is running.
	 */
	public function start( Connection $connection, string $direction, string $mode, array $selected, bool $protocol, array $custom ): array {
		$previous = self::current();

		if ( null !== $previous && self::RUNNING === $previous['status'] && time() - (int) $previous['updated'] < 120 ) {
			throw new \RuntimeException( __( 'Another migration is running on this site. Wait until it ends or cancel it.', 'wm-warp' ) );
		}

		if ( null !== $previous && self::DONE === $previous['status'] ) {
			// A new migration confirms the previous one (its set-aside tables are deleted).
			$this->confirm();
		}

		( new Connector( $this->identity, $this->outgoing ) )->handshake( $connection );
		$this->outgoing->save( $connection );

		$local  = Environment::describe( $this->identity->site_id() );
		$remote = $connection->remote;
		$source = self::PULL === $direction ? $remote : $local;
		$target = self::PULL === $direction ? $local : $remote;

		$rules = array_merge(
			Rules::domain( self::text( $source, 'home' ), self::text( $target, 'home' ) ),
			Rules::path( self::text( $source, 'path' ), self::text( $target, 'path' ) ),
			$protocol ? Rules::protocol( self::text( $target, 'home' ) ) : array(),
			$custom
		);

		$state = array(
			'id'            => bin2hex( random_bytes( 16 ) ),
			'direction'     => self::PULL === $direction ? self::PULL : self::PUSH,
			'connection'    => $connection->id,
			'site'          => $connection->name,
			'host'          => $connection->host(),
			'status'        => self::RUNNING,
			'phase'         => 'prepare',
			'mode'          => $mode,
			'selected'      => $selected,
			'rules'         => array_map( static fn( ReplaceRule $rule ): array => $rule->to_array(), $rules ),
			'source_prefix' => self::text( $source, 'prefix' ),
			'target_prefix' => self::text( $target, 'prefix' ),
			'tables'        => array(),
			'index'         => 0,
			'budget'        => self::START_BUDGET,
			'max_budget'    => self::max_budget( $direction, $remote ),
			'retries'       => 0,
			'bytes'         => 0,
			'rows'          => 0,
			'requests'      => 0,
			'started'       => time(),
			'updated'       => time(),
			'finished'      => 0,
			'error'         => null,
			'summary'       => null,
		);

		$this->save( $state );

		return $state;
	}

	/**
	 * Works on the current migration for a few seconds.
	 *
	 * @return array<string, mixed> State.
	 * @throws \RuntimeException When there is no migration.
	 */
	public function step(): array {
		$state = self::require_current();

		if ( self::RUNNING !== $state['status'] ) {
			return $state;
		}

		$deadline = microtime( true ) + self::STEP_SECONDS;

		try {
			if ( 'prepare' === $state['phase'] ) {
				$state = $this->prepare( $state );
			} elseif ( 'tables' === $state['phase'] ) {
				do {
					$state = $this->transfer( $state );
					$this->save( $state );
				} while ( 'tables' === $state['phase'] && microtime( true ) < $deadline );
			} elseif ( 'finish' === $state['phase'] ) {
				$state = $this->finish( $state );
			}
		} catch ( ClientError $error ) {
			$state = $this->retry_or_fail( $state, $error );
		} catch ( \Throwable $error ) {
			$state = $this->fail( $state, $error );
		}

		$this->save( $state );

		return $state;
	}

	/**
	 * Cancels the running migration; the target keeps its data.
	 *
	 * @return array<string, mixed> State.
	 * @throws \RuntimeException When there is no migration.
	 */
	public function cancel(): array {
		$state = self::require_current();

		if ( self::RUNNING === $state['status'] ) {
			$this->abort_target( $state );
			$state['status']   = self::CANCELLED;
			$state['finished'] = time();
			$this->save( $state );
		}

		return $state;
	}

	/**
	 * Confirms the finished migration: the set-aside tables on the target are deleted.
	 *
	 * @return array<string, mixed> State.
	 * @throws \RuntimeException When there is no migration.
	 * @throws ClientError When the other site cannot be reached.
	 */
	public function confirm(): array {
		$state = self::require_current();

		if ( self::DONE === $state['status'] ) {
			if ( self::PULL === $state['direction'] ) {
				Importer::confirm();
			} else {
				$this->remote( $state, 'db/confirm', array( 'run_id' => $state['id'] ) );
			}

			$state['status'] = self::CONFIRMED;
			$this->save( $state );
		}

		return $state;
	}

	/**
	 * Undoes the finished migration: the target gets its previous tables back.
	 *
	 * @return array<string, mixed> State.
	 * @throws \RuntimeException When there is no migration.
	 * @throws ClientError When the other site cannot be reached.
	 */
	public function rollback(): array {
		$state = self::require_current();

		if ( self::DONE === $state['status'] ) {
			if ( self::PULL === $state['direction'] ) {
				Importer::rollback();
			} else {
				$this->remote( $state, 'db/rollback', array( 'run_id' => $state['id'] ) );
			}

			$state['status'] = self::ROLLED_BACK;
			$this->save( $state );
		}

		return $state;
	}

	/**
	 * Lists the tables on the source and creates temporary tables on the target.
	 *
	 * @param array<string, mixed> $state State.
	 * @return array<string, mixed>
	 * @throws \RuntimeException When no table is chosen.
	 */
	private function prepare( array $state ): array {
		$selected = array_values( array_filter( (array) $state['selected'], 'is_string' ) );

		if ( self::PULL === $state['direction'] ) {
			$response = $this->remote(
				$state,
				'db/tables',
				array(
					'mode'     => $state['mode'],
					'selected' => $selected,
				)
			);
			$tables   = array_map( array( TableInfo::class, 'from_array' ), array_values( (array) ( $response['data']['tables'] ?? array() ) ) );
		} else {
			$tables = array_map( array( Schema::class, 'describe' ), Schema::choose( (string) $state['mode'], $selected, (string) $state['source_prefix'] ) );
		}

		if ( array() === $tables ) {
			throw new \RuntimeException( __( 'No table is chosen for the migration.', 'wm-warp' ) );
		}

		if ( self::PULL === $state['direction'] ) {
			Importer::begin( (string) $state['id'], (string) $state['site'], (string) $state['source_prefix'], $tables );
		} else {
			$this->remote(
				$state,
				'db/begin',
				array(
					'run_id'        => $state['id'],
					'source_prefix' => $state['source_prefix'],
					'tables'        => array_map( static fn( TableInfo $table ): array => $table->to_array(), $tables ),
				)
			);
		}

		$state['tables'] = array_map(
			static fn( TableInfo $table ): array => array(
				'info'   => $table->to_array(),
				'cursor' => array(),
				'rows'   => 0,
				'bytes'  => 0,
				'done'   => false,
			),
			$tables
		);
		$state['phase']  = 'tables';

		return $state;
	}

	/**
	 * Transfers one chunk of the current table.
	 *
	 * @param array<string, mixed> $state State.
	 * @return array<string, mixed>
	 */
	private function transfer( array $state ): array {
		$index = (int) $state['index'];

		if ( ! isset( $state['tables'][ $index ] ) ) {
			$state['phase'] = 'finish';

			return $state;
		}

		$entry       = $state['tables'][ $index ];
		$table       = TableInfo::from_array( $entry['info'] );
		$transformer = new RowTransformer( $this->replacer( $state ), $table, (string) $state['source_prefix'], (string) $state['target_prefix'] );
		$budget      = (int) $state['budget'];
		$started     = microtime( true );

		if ( self::PULL === $state['direction'] ) {
			$response = $this->remote(
				$state,
				'db/export',
				array(
					'table'  => $table->name,
					'cursor' => (object) $entry['cursor'],
					'budget' => $budget,
				)
			);
			$data     = $response['data'];
			$columns  = array_values( array_filter( (array) ( $data['columns'] ?? array() ), 'is_string' ) );
			$rows     = '' === $response['payload'] ? array() : RowCodec::decode( $response['payload'], count( $columns ) );

			Importer::insert( (string) $state['id'], $table->name, $columns, $transformer->apply( $rows ) );

			$cursor = is_array( $data['cursor'] ?? null ) ? $data['cursor'] : array();
			$done   = true === ( $data['done'] ?? false );
			$bytes  = (int) ( $data['bytes'] ?? 0 );
		} else {
			$chunk = Exporter::chunk( $table, (array) $entry['cursor'], $budget );
			$rows  = $transformer->apply( $chunk['rows'] );

			$this->remote(
				$state,
				'db/import',
				array(
					'run_id'  => $state['id'],
					'table'   => $table->name,
					'columns' => $table->data_column_names(),
					'seq'     => (int) ( $entry['chunks'] ?? 0 ) + 1,
				),
				RowCodec::encode( $rows )
			);

			$cursor = $chunk['cursor'];
			$done   = $chunk['done'];
			$bytes  = $chunk['bytes'];
		}

		$entry['chunks']           = (int) ( $entry['chunks'] ?? 0 ) + 1;
		$entry['cursor']           = $cursor;
		$entry['rows']            += count( $rows );
		$entry['bytes']           += $bytes;
		$entry['done']             = $done;
		$state['tables'][ $index ] = $entry;
		$state['rows']            += count( $rows );
		$state['bytes']           += $bytes;
		$state['retries']          = 0;
		$state['budget']           = self::adapt( $budget, microtime( true ) - $started, (int) $state['max_budget'] );

		if ( $done ) {
			++$state['index'];
		}

		if ( ! isset( $state['tables'][ (int) $state['index'] ] ) ) {
			$state['phase'] = 'finish';
		}

		return $state;
	}

	/**
	 * Checks row counts on the target and swaps the tables.
	 *
	 * @param array<string, mixed> $state State.
	 * @return array<string, mixed>
	 */
	private function finish( array $state ): array {
		$expected = array();
		foreach ( $state['tables'] as $entry ) {
			$expected[ (string) $entry['info']['name'] ] = (int) $entry['rows'];
		}

		if ( self::PULL === $state['direction'] ) {
			$summary = Importer::finish( (string) $state['id'], $expected );
		} else {
			$summary = $this->remote(
				$state,
				'db/finish',
				array(
					'run_id'   => $state['id'],
					'expected' => $expected,
				),
				'',
				300
			)['data'];
		}

		$state['status']   = self::DONE;
		$state['phase']    = 'done';
		$state['finished'] = time();
		$state['summary']  = $summary;

		return $state;
	}

	/**
	 * After a network problem a smaller chunk is tried again; other problems end the migration.
	 *
	 * @param array<string, mixed> $state State.
	 * @param ClientError          $error Error.
	 * @return array<string, mixed>
	 */
	private function retry_or_fail( array $state, ClientError $error ): array {
		$transient = in_array( $error->reason, array( 'timeout', 'too_large', 'remote_error', 'unreachable', 'bad_response' ), true );

		if ( 'tables' === $state['phase'] && $transient && (int) $state['retries'] < self::MAX_RETRIES ) {
			++$state['retries'];
			$state['budget'] = max( self::MIN_BUDGET, intdiv( (int) $state['budget'], 4 ) );

			return $state;
		}

		return $this->fail( $state, $error );
	}

	/**
	 * Ends the migration with an error and removes the temporary tables on the target.
	 *
	 * @param array<string, mixed> $state State.
	 * @param \Throwable           $error Error.
	 * @return array<string, mixed>
	 */
	private function fail( array $state, \Throwable $error ): array {
		$details = array( 'error' => $error->getMessage() );

		if ( $error instanceof ClientError ) {
			$details = array_merge( $details, $error->details );
		} elseif ( $error instanceof DatabaseError ) {
			$details['sql'] = $error->sql;
		}

		$table = $state['tables'][ (int) $state['index'] ]['info']['name'] ?? '';

		$state['status']   = self::FAILED;
		$state['finished'] = time();
		$state['error']    = array(
			'message' => self::message( $error ),
			'phase'   => $state['phase'],
			'table'   => is_string( $table ) ? $table : '',
			'details' => $details,
		);

		$this->abort_target( $state );

		return $state;
	}

	/**
	 * Human readable message of an error.
	 *
	 * @param \Throwable $error Error.
	 */
	private static function message( \Throwable $error ): string {
		if ( $error instanceof DatabaseError ) {
			/* translators: %s: error reported by the database. */
			return sprintf( __( 'The database refused a statement: %s', 'wm-warp' ), $error->getMessage() );
		}

		return $error->getMessage();
	}

	/**
	 * Removes the temporary tables on the target (best effort).
	 *
	 * @param array<string, mixed> $state State.
	 */
	private function abort_target( array $state ): void {
		try {
			if ( self::PULL === $state['direction'] ) {
				Importer::abort( (string) $state['id'] );
			} else {
				$this->remote( $state, 'db/abort', array( 'run_id' => $state['id'] ) );
			}
		} catch ( \Throwable $error ) {
			unset( $error );
		}
	}

	/**
	 * Sends a request to the other site of the migration.
	 *
	 * @param array<string, mixed> $state   State.
	 * @param string               $action  Route.
	 * @param array<string, mixed> $data    Data.
	 * @param string               $payload Binary payload.
	 * @param int                  $timeout Timeout in seconds.
	 * @return array{data: array<string, mixed>, payload: string}
	 * @throws ClientError When the request fails.
	 */
	private function remote( array $state, string $action, array $data, string $payload = '', int $timeout = 120 ): array {
		$connection = $this->outgoing->get( (string) $state['connection'] );

		if ( null === $connection ) {
			throw new ClientError( 'revoked', __( 'The site of this migration is no longer connected.', 'wm-warp' ) );
		}

		return ( new Client( $this->identity->site_id() ) )->exchange( Endpoint::of( $connection ), $action, $data, $connection->secret, $connection->id, $connection->site_id, $payload, $timeout );
	}

	/**
	 * Find & replace of the migration.
	 *
	 * @param array<string, mixed> $state State.
	 */
	private function replacer( array $state ): Replacer {
		$rules = array();
		foreach ( (array) $state['rules'] as $rule ) {
			if ( is_array( $rule ) ) {
				$rules[] = ReplaceRule::from_array( self::string_keys( $rule ) );
			}
		}

		return new Replacer( $rules );
	}

	/**
	 * Next chunk size: larger while chunks are quick, smaller when they are slow (target 3–8 s).
	 *
	 * @param int   $budget  Current size in bytes.
	 * @param float $seconds Duration of the last chunk.
	 * @param int   $max     Largest size.
	 */
	public static function adapt( int $budget, float $seconds, int $max ): int {
		if ( $seconds < 2.0 ) {
			$budget *= 2;
		} elseif ( $seconds > 8.0 ) {
			$budget = intdiv( $budget, 2 );
		}

		return max( self::MIN_BUDGET, min( $max, $budget ) );
	}

	/**
	 * Largest chunk from the limits of the other site.
	 *
	 * Push: the chunk is the body of a request, so post_max_size of the target limits it.
	 * Both: the other site keeps the chunk in memory several times.
	 *
	 * @param string               $direction Direction.
	 * @param array<string, mixed> $remote    Environment of the other site.
	 */
	private static function max_budget( string $direction, array $remote ): int {
		$limits = is_array( $remote['limits'] ?? null ) ? $remote['limits'] : array();
		$memory = (int) ( $limits['memory_limit'] ?? 0 );
		$max    = self::MAX_BUDGET;

		if ( $memory > 0 ) {
			$max = min( $max, intdiv( $memory, 8 ) );
		}

		$post = (int) ( $limits['post_max_size'] ?? 0 );
		if ( self::PUSH === $direction && $post > 0 ) {
			$max = min( $max, (int) ( $post * 0.7 ) );
		}

		return max( self::MIN_BUDGET, $max );
	}

	/**
	 * Current state, or an error when there is none.
	 *
	 * @return array<string, mixed>
	 * @throws \RuntimeException When there is no migration.
	 */
	private static function require_current(): array {
		$state = self::current();

		if ( null === $state ) {
			throw new \RuntimeException( __( 'There is no migration on this site.', 'wm-warp' ) );
		}

		return $state;
	}

	/**
	 * Saves the state.
	 *
	 * @param array<string, mixed> $state State.
	 */
	private function save( array $state ): void {
		$state['updated'] = time();
		Storage::write_json( self::STATE_DIR, self::STATE_NAME, $state );
	}

	/**
	 * Text value of an environment description.
	 *
	 * @param array<string, mixed> $environment Environment.
	 * @param string               $key         Key.
	 */
	private static function text( array $environment, string $key ): string {
		return is_string( $environment[ $key ] ?? null ) ? $environment[ $key ] : '';
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
