<?php
/**
 * The file part of a migration.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Migration;

use WM\Warp\Files\Diff;
use WM\Warp\Files\Groups;
use WM\Warp\Files\Paths;
use WM\Warp\Files\Reader;
use WM\Warp\Files\Stager;
use WM\Warp\Support\Storage;

/**
 * Transfers files on the site that started the migration, in steps:
 * scan (manifests of both sides) → compare → hash (same size, other time) → space check →
 * transfer → copy (unchanged files of replaced plugins and themes) → plan → swap.
 *
 * The source and the target are callables with the same signature for this site (direct call) and
 * for the other site (signed request), so pull and push share one code path.
 */
final class FileRun {

	private const DIR = 'runs';

	private const MAX_PARTS    = 500;
	private const HASH_BATCH   = 300;
	private const COPY_BATCH   = 1000;
	private const PLAN_BATCH   = 5000;
	private const SPACE_MARGIN = 20 * 1024 * 1024;

	/**
	 * Source side: (route, data, payload, deflate) → response.
	 *
	 * @var \Closure(string, array<string, mixed>, string, bool): array{data: array<string, mixed>, payload: string}
	 */
	private \Closure $source;

	/**
	 * Target side.
	 *
	 * @var \Closure(string, array<string, mixed>, string, bool): array{data: array<string, mixed>, payload: string}
	 */
	private \Closure $target;

	/**
	 * Constructor.
	 *
	 * @param \Closure $source      Source side.
	 * @param \Closure $target      Target side.
	 * @phpstan-param \Closure(string, array<string, mixed>, string, bool): array{data: array<string, mixed>, payload: string} $source
	 * @phpstan-param \Closure(string, array<string, mixed>, string, bool): array{data: array<string, mixed>, payload: string} $target
	 * @param string   $run_id      Run ID.
	 * @param string   $host        Domain of the target, for messages.
	 * @param string   $os          Operating system of the target (PHP_OS_FAMILY).
	 */
	public function __construct( \Closure $source, \Closure $target, private string $run_id, private string $host, private string $os ) {
		$this->source = $source;
		$this->target = $target;
	}

	/**
	 * Initial file state of a run.
	 *
	 * @param list<array<string, mixed>> $jobs Jobs: group, mode, items, mirror, since, excludes, allow_php.
	 * @return array<string, mixed>
	 */
	public static function initial( array $jobs ): array {
		foreach ( $jobs as $index => $job ) {
			$jobs[ $index ] += array(
				'src_stack' => null,
				'dst_stack' => null,
				'src_done'  => false,
				'dst_done'  => false,
				'dirty'     => array(),
				'skip'      => array(),
			);
		}

		return array(
			'jobs'     => $jobs,
			'step'     => 'scan',
			'job'      => 0,
			'qi'       => 0,
			'qoff'     => 0,
			'ci'       => 0,
			'ki'       => 0,
			'pi'       => 0,
			'scanned'  => 0,
			'total'    => 0,
			'sent'     => 0,
			'count'    => 0,
			'moved'    => 0,
			'checks'   => 0,
			'free'     => array(),
			'warnings' => array(),
			'result'   => null,
		);
	}

	/**
	 * Groups of the run for files/begin on the target.
	 *
	 * @param array<string, mixed> $files File state.
	 * @return array<string, array{mode: string, items: list<string>, allow_php: bool}>
	 */
	public static function groups( array $files ): array {
		$groups = array();

		foreach ( (array) $files['jobs'] as $job ) {
			$groups[ (string) $job['group'] ] = array(
				'mode'      => (string) $job['mode'],
				'items'     => array_values( (array) $job['items'] ),
				'allow_php' => (bool) $job['allow_php'],
			);
		}

		return $groups;
	}

	/**
	 * Works on the files until the deadline. Returns when the plan is complete (step "swap").
	 *
	 * @param array<string, mixed> $state    Runner state.
	 * @param float                $deadline Microtime to stop at.
	 * @param callable             $save     Saves the state between requests.
	 * @return array<string, mixed>
	 */
	public function work( array $state, float $deadline, callable $save ): array {
		do {
			$files = $state['files'];
			$step  = (string) $files['step'];

			if ( 'scan' === $step ) {
				$files = $this->scan( $files );
			} elseif ( 'compare' === $step ) {
				$files = $this->compare( $files );
			} elseif ( 'hash' === $step ) {
				$files = $this->hash( $files );
			} elseif ( 'transfer' === $step ) {
				$state = $this->transfer( $state );
				$files = $state['files'];
			} elseif ( 'copy' === $step ) {
				$files = $this->copy( $files );
			} elseif ( 'plan' === $step ) {
				$files = $this->plan( $files );
			}

			$state['files'] = $files;
			$save( $state );
		} while ( 'swap' !== $state['files']['step'] && microtime( true ) < $deadline );

		return $state;
	}

	/**
	 * Swaps the files in on the target; call until the step is "swapped".
	 *
	 * @param array<string, mixed> $files File state.
	 * @return array<string, mixed>
	 */
	public function swap( array $files ): array {
		$result = $this->call( $this->target, 'files/swap', array( 'run_id' => $this->run_id ) );

		if ( true === ( $result['done'] ?? false ) ) {
			$files['step']   = 'swapped';
			$files['result'] = array(
				'files'   => (int) ( $result['files'] ?? 0 ),
				'deleted' => (int) ( $result['deleted'] ?? 0 ),
				'items'   => (int) ( $result['items'] ?? 0 ),
			);
			foreach ( (array) ( $result['warnings'] ?? array() ) as $warning ) {
				$files['warnings'][] = self::warning( is_array( $warning ) ? $warning : array() );
			}
		}

		return $files;
	}

	/**
	 * Text of a warning the target reported during the swap.
	 *
	 * @param array<mixed> $warning Code and path.
	 */
	private static function warning( array $warning ): string {
		$path = (string) ( $warning[1] ?? '' );

		return match ( (string) ( $warning[0] ?? '' ) ) {
			/* translators: %s: path of a plugin or theme. */
			'item_link'   => sprintf( __( 'Not replaced, it is or contains a link on the target: %s', 'wm-warp' ), $path ),
			/* translators: %s: path of a file. */
			'folder_link' => sprintf( __( 'Skipped, its folder is a link on the target: %s', 'wm-warp' ), $path ),
			/* translators: %s: path of a file. */
			default       => sprintf( __( 'Not replaced, it is a link or a folder on the target: %s', 'wm-warp' ), $path ),
		};
	}

	/**
	 * Deletes the lists of a run on this site.
	 */
	public static function cleanup(): void {
		foreach ( array( 'queue', 'checks', 'keeps', 'plans' ) as $name ) {
			Storage::delete_json( self::DIR, 'run-files-' . $name );
		}

		foreach ( array_keys( Groups::ALL ) as $index ) {
			Storage::delete_json( self::DIR, 'run-files-src-' . $index );
			Storage::delete_json( self::DIR, 'run-files-dst-' . $index );
		}
	}

	/**
	 * One step of listing the files of the current job on both sides.
	 *
	 * @param array<string, mixed> $files File state.
	 * @return array<string, mixed>
	 */
	private function scan( array $files ): array {
		$index = (int) $files['job'];
		$job   = $files['jobs'][ $index ] ?? null;

		if ( ! is_array( $job ) ) {
			$files['step'] = 'compare';

			return $files;
		}

		foreach ( array( 'src', 'dst' ) as $side ) {
			if ( true === $job[ $side . '_done' ] ) {
				continue;
			}

			$result = $this->call(
				'src' === $side ? $this->source : $this->target,
				'files/scan',
				array(
					'group'     => $job['group'],
					'bases'     => Stager::MODE_ITEMS === $job['mode'] ? array_values( (array) $job['items'] ) : array( '' ),
					'excludes'  => array_values( (array) $job['excludes'] ),
					'allow_php' => (bool) $job['allow_php'],
					'stack'     => $job[ $side . '_stack' ],
				)
			);

			$manifest = self::load( $side . '-' . $index );
			foreach ( (array) ( $result['entries'] ?? array() ) as $entry ) {
				if ( is_array( $entry ) && is_string( $entry[0] ?? null ) ) {
					$manifest[ $entry[0] ] = array( (int) ( $entry[1] ?? 0 ), (int) ( $entry[2] ?? 0 ) );
				}
			}
			self::store( $side . '-' . $index, $manifest );

			foreach ( (array) ( $result['links'] ?? array() ) as $link ) {
				$link = (string) $link;

				if ( 'dst' === $side && Stager::MODE_ITEMS === $job['mode'] ) {
					$job['skip'][] = Paths::item( $link );
					/* translators: %s: path of a plugin or theme. */
					$files['warnings'][] = sprintf( __( 'Not replaced, it is or contains a link on the target: %s', 'wm-warp' ), $job['group'] . '/' . Paths::item( $link ) );
				} elseif ( 'src' === $side ) {
					/* translators: %s: path of a file or folder. */
					$files['warnings'][] = sprintf( __( 'Skipped, it is a link on the source: %s', 'wm-warp' ), $job['group'] . '/' . $link );
				}
			}

			$files['scanned']        = (int) $files['scanned'] + count( (array) ( $result['entries'] ?? array() ) );
			$job[ $side . '_stack' ] = array_values( (array) ( $result['stack'] ?? array() ) );
			$job[ $side . '_done' ]  = true === ( $result['done'] ?? false );
		}

		$files['jobs'][ $index ] = $job;

		if ( $job['src_done'] && $job['dst_done'] ) {
			++$files['job'];
		}

		return $files;
	}

	/**
	 * Compares the manifests of all jobs and builds the lists of the run.
	 *
	 * @param array<string, mixed> $files File state.
	 * @return array<string, mixed>
	 */
	private function compare( array $files ): array {
		$queue  = array();
		$checks = array();
		$keeps  = array();
		$plans  = array();

		$insensitive = in_array( $this->os, array( 'Windows', 'Darwin' ), true );

		foreach ( (array) $files['jobs'] as $index => $job ) {
			$items  = Stager::MODE_ITEMS === $job['mode'];
			$skip   = array_values( array_unique( (array) $job['skip'] ) );
			$source = self::load( 'src-' . $index );
			$target = self::load( 'dst-' . $index );
			$diff   = Diff::compare( $source, $target, $items || (bool) $job['mirror'], $items ? 0 : (int) $job['since'], $insensitive, 'Windows' === $this->os );
			$dirty  = array();

			foreach ( $diff['skipped'] as [ $path, $reason ] ) {
				$files['warnings'][] = 'windows' === $reason
					/* translators: %s: path of a file. */
					? sprintf( __( 'Skipped, the name is not allowed on Windows: %s', 'wm-warp' ), $job['group'] . '/' . $path )
					/* translators: %s: path of a file. */
					: sprintf( __( 'Skipped, another file has the same name in different letter case: %s', 'wm-warp' ), $job['group'] . '/' . $path );
			}

			foreach ( array( 'transfer', 'check', 'same' ) as $kind ) {
				foreach ( $diff[ $kind ] as [ $path, $size, $mtime ] ) {
					if ( $items && in_array( Paths::item( $path ), $skip, true ) ) {
						continue;
					}

					$entry = array( $index, $path, $size, $mtime );

					if ( 'transfer' === $kind ) {
						$queue[] = $entry;
						if ( $items ) {
							$dirty[ Paths::item( $path ) ] = true;
						}
					} elseif ( 'check' === $kind ) {
						$checks[] = $entry;
					} elseif ( $items ) {
						$keeps[] = $entry;
					}
				}
			}

			if ( $items ) {
				foreach ( $diff['delete'] as $path ) {
					if ( ! in_array( Paths::item( $path ), $skip, true ) ) {
						$dirty[ Paths::item( $path ) ] = true;
					}
				}
			} elseif ( array() !== $diff['delete'] ) {
				foreach ( array_chunk( $diff['delete'], self::PLAN_BATCH ) as $chunk ) {
					$plans[] = array(
						'job'    => $index,
						'delete' => $chunk,
					);
				}
			}

			$files['jobs'][ $index ]['dirty'] = array_keys( $dirty );

			Storage::delete_json( self::DIR, 'run-files-src-' . $index );
			Storage::delete_json( self::DIR, 'run-files-dst-' . $index );
		}

		self::store_list( 'queue', $queue );
		self::store_list( 'checks', $checks );
		self::store_list( 'keeps', $keeps );
		self::store_list( 'plans', $plans );

		$files['checks'] = count( $checks );
		$files['step']   = 'hash';

		return $files;
	}

	/**
	 * Compares contents of files with the same size and another time; at the end checks the free space.
	 *
	 * @param array<string, mixed> $files File state.
	 * @return array<string, mixed>
	 */
	private function hash( array $files ): array {
		$checks = self::load_list( 'checks' );
		$start  = (int) $files['ci'];

		if ( $start >= count( $checks ) ) {
			return $this->ready( $files );
		}

		$batch = array();
		foreach ( array_slice( $checks, $start, self::HASH_BATCH ) as $entry ) {
			if ( isset( $batch[0] ) && $batch[0][0] !== $entry[0] ) {
				break; // One group per request.
			}
			$batch[] = $entry;
		}

		$job     = $files['jobs'][ (int) $batch[0][0] ];
		$paths   = array_map( static fn( array $entry ): string => (string) $entry[1], $batch );
		$request = array(
			'group' => $job['group'],
			'paths' => $paths,
		);
		$theirs  = (array) ( $this->call( $this->source, 'files/hash', $request )['hashes'] ?? array() );
		$ours    = (array) ( $this->call( $this->target, 'files/hash', $request )['hashes'] ?? array() );
		$queue   = self::load_list( 'queue' );
		$keeps   = self::load_list( 'keeps' );
		$plan    = array();
		$items   = Stager::MODE_ITEMS === $job['mode'];

		foreach ( $batch as $i => $entry ) {
			$same = null !== ( $theirs[ $i ] ?? null ) && ( $theirs[ $i ] ?? null ) === ( $ours[ $i ] ?? null );

			if ( ! $same ) {
				$queue[] = $entry;
				if ( $items ) {
					$files['jobs'][ (int) $entry[0] ]['dirty'][] = Paths::item( (string) $entry[1] );
				}
			} elseif ( $items ) {
				$keeps[] = $entry;
			} else {
				$plan[] = array( (string) $entry[1], (int) $entry[3] );
			}
		}

		self::store_list( 'queue', $queue );
		self::store_list( 'keeps', $keeps );

		if ( array() !== $plan ) {
			$plans   = self::load_list( 'plans' );
			$plans[] = array(
				'job'   => (int) $batch[0][0],
				'touch' => $plan,
			);
			self::store_list( 'plans', $plans );
		}

		$files['ci'] = $start + count( $batch );

		return $files;
	}

	/**
	 * After comparing: keeps only files of plugins and themes that change, adds them to the plan,
	 * sums up and checks the free space on the target.
	 *
	 * @param array<string, mixed> $files File state.
	 * @return array<string, mixed>
	 * @throws \RuntimeException When the target lacks space.
	 */
	private function ready( array $files ): array {
		$queue  = self::load_list( 'queue' );
		$keeps  = array();
		$plans  = self::load_list( 'plans' );
		$needed = array();

		foreach ( self::load_list( 'keeps' ) as $entry ) {
			if ( in_array( Paths::item( (string) $entry[1] ), (array) $files['jobs'][ (int) $entry[0] ]['dirty'], true ) ) {
				$keeps[] = $entry;
			}
		}

		foreach ( (array) $files['jobs'] as $index => $job ) {
			$dirty                            = array_values( array_diff( array_unique( (array) $job['dirty'] ), (array) $job['skip'] ) );
			$files['jobs'][ $index ]['dirty'] = $dirty;

			if ( Stager::MODE_ITEMS === $job['mode'] && array() !== $dirty ) {
				$plans[] = array(
					'job'   => $index,
					'items' => $dirty,
				);
			}
		}

		foreach ( array_merge( $queue, $keeps ) as $entry ) {
			$group            = (string) $files['jobs'][ (int) $entry[0] ]['group'];
			$needed[ $group ] = ( $needed[ $group ] ?? 0 ) + (int) $entry[2];
		}

		foreach ( $needed as $group => $bytes ) {
			$free = (int) ( $files['free'][ $group ] ?? -1 );

			if ( $free >= 0 && $free < (int) ( $bytes * 1.05 ) + self::SPACE_MARGIN ) {
				throw new \RuntimeException(
					sprintf(
						/* translators: 1: domain of the target, 2: size needed, 3: free size. */
						__( 'There is not enough free disk space on %1$s: the files need %2$s, only %3$s is free.', 'wm-warp' ),
						$this->host,
						html_entity_decode( (string) size_format( $bytes, 1 ), ENT_QUOTES, 'UTF-8' ),
						html_entity_decode( (string) size_format( $free, 1 ), ENT_QUOTES, 'UTF-8' )
					)
				);
			}
		}

		self::store_list( 'keeps', $keeps );
		self::store_list( 'plans', $plans );

		$files['total'] = (int) array_sum( array_column( $queue, 2 ) );
		$files['count'] = count( $queue );
		$files['step']  = 'transfer';

		return $files;
	}

	/**
	 * Transfers one batch of files (or one part of a large file).
	 *
	 * @param array<string, mixed> $state Runner state.
	 * @return array<string, mixed>
	 */
	private function transfer( array $state ): array {
		$files  = $state['files'];
		$queue  = self::load_list( 'queue' );
		$qi     = (int) $files['qi'];
		$qoff   = (int) $files['qoff'];
		$budget = (int) $state['budget'];

		if ( $qi >= count( $queue ) ) {
			$files['step']  = 'copy';
			$state['files'] = $files;

			return $state;
		}

		$parts   = array();
		$bytes   = 0;
		$started = microtime( true );

		$length_queue = count( $queue );

		$taken = 0;

		while ( $qi < $length_queue && $taken < self::MAX_PARTS ) {

			[ $index, $path, $size ] = $queue[ $qi ];
			$length                  = min( (int) $size - $qoff, $budget - $bytes );

			if ( $length <= 0 && (int) $size > 0 ) {
				break;
			}

			$parts[] = array(
				'group'  => (string) $files['jobs'][ (int) $index ]['group'],
				'path'   => (string) $path,
				'offset' => $qoff,
				'length' => max( 0, $length ),
				'size'   => (int) $size,
			);
			$bytes  += max( 0, $length );
			++$taken;

			if ( $qoff + $length >= (int) $size ) {
				++$qi;
				$qoff = 0;
			} else {
				$qoff += $length;
				break;
			}
		}

		$compressible = 0;
		foreach ( $parts as $part ) {
			$compressible += Reader::compressible( $part['path'] ) ? $part['length'] : 0;
		}

		$read  = ( $this->source )( 'files/read', array( 'parts' => $parts ), '', true );
		$heads = array();

		foreach ( (array) ( $read['data']['parts'] ?? array() ) as $head ) {
			if ( ! is_array( $head ) ) {
				continue;
			}

			$where = (string) ( $head['group'] ?? '' ) . '/' . (string) ( $head['path'] ?? '' );

			if ( 'ok' === ( $head['status'] ?? '' ) ) {
				$heads[] = $head;
			} elseif ( 'missing' === ( $head['status'] ?? '' ) ) {
				/* translators: %s: path of a file. */
				$files['warnings'][] = sprintf( __( 'Skipped, it was deleted on the source during the migration: %s', 'wm-warp' ), $where );
				$qoff                = self::skip_rest( $queue, $qi, $qoff, (string) ( $head['path'] ?? '' ) );
			} else {
				/* translators: %s: path of a file. */
				$files['warnings'][] = sprintf( __( 'Skipped, it changed on the source during the migration: %s', 'wm-warp' ), $where );
				$qoff                = self::skip_rest( $queue, $qi, $qoff, (string) ( $head['path'] ?? '' ) );
			}
		}

		if ( array() !== $heads ) {
			( $this->target )(
				'files/write',
				array(
					'run_id' => $this->run_id,
					'parts'  => $heads,
				),
				$read['payload'],
				$compressible * 2 >= $bytes
			);
		}

		$finished = 0;
		foreach ( $heads as $head ) {
			$finished += true === ( $head['final'] ?? false ) ? 1 : 0;
		}

		$files['qi']       = $qi;
		$files['qoff']     = $qoff;
		$files['sent']     = (int) $files['sent'] + $bytes;
		$files['moved']    = (int) $files['moved'] + $finished;
		$state['bytes']   += $bytes;
		$state['retries']  = 0;
		$state['budget']   = Runner::adapt( $budget, microtime( true ) - $started, (int) $state['max_budget'] );
		$state['requests'] = (int) $state['requests'] + 2;
		$state['files']    = $files;

		return $state;
	}

	/**
	 * After a skipped part of a large file: continue with the next file.
	 *
	 * @param list<array{0: int, 1: string, 2: int, 3: int}> $queue Queue.
	 * @param int                                            $qi    Current index (moved past the file).
	 * @param int                                            $qoff  Offset in the current file.
	 * @param string                                         $path  Path of the skipped part.
	 * @param-out int $qi
	 * @return int New offset.
	 */
	private static function skip_rest( array $queue, int &$qi, int $qoff, string $path ): int {
		if ( $qoff > 0 && isset( $queue[ $qi ] ) && (string) $queue[ $qi ][1] === $path ) {
			++$qi;
		}

		return 0;
	}

	/**
	 * Copies unchanged files of replaced plugins and themes on the target.
	 *
	 * @param array<string, mixed> $files File state.
	 * @return array<string, mixed>
	 */
	private function copy( array $files ): array {
		$keeps = self::load_list( 'keeps' );
		$start = (int) $files['ki'];

		if ( $start >= count( $keeps ) ) {
			$files['step'] = 'plan';

			return $files;
		}

		$batch = array();
		foreach ( array_slice( $keeps, $start, self::COPY_BATCH ) as $entry ) {
			if ( isset( $batch[0] ) && $batch[0][0] !== $entry[0] ) {
				break;
			}
			$batch[] = $entry;
		}

		$this->call(
			$this->target,
			'files/copy',
			array(
				'run_id' => $this->run_id,
				'group'  => $files['jobs'][ (int) $batch[0][0] ]['group'],
				'files'  => array_map( static fn( array $entry ): array => array( (string) $entry[1], (int) $entry[3] ), $batch ),
			)
		);

		$files['ki'] = $start + count( $batch );

		return $files;
	}

	/**
	 * Sends deletions, times and plugins and themes to replace to the target.
	 *
	 * @param array<string, mixed> $files File state.
	 * @return array<string, mixed>
	 */
	private function plan( array $files ): array {
		$plans = self::load_list( 'plans' );
		$index = (int) $files['pi'];

		if ( $index >= count( $plans ) ) {
			$files['step'] = 'swap';

			return $files;
		}

		$plan = $plans[ $index ];
		$this->call(
			$this->target,
			'files/plan',
			array(
				'run_id' => $this->run_id,
				'group'  => $files['jobs'][ (int) $plan['job'] ]['group'],
				'delete' => array_values( (array) ( $plan['delete'] ?? array() ) ),
				'touch'  => array_values( (array) ( $plan['touch'] ?? array() ) ),
				'items'  => array_values( (array) ( $plan['items'] ?? array() ) ),
			)
		);

		$files['pi'] = $index + 1;

		return $files;
	}

	/**
	 * Calls a side and returns the data of the response.
	 *
	 * @param \Closure             $side  Side.
	 * @param string               $route Route.
	 * @param array<string, mixed> $data  Data.
	 * @return array<string, mixed>
	 */
	private function call( \Closure $side, string $route, array $data ): array {
		return $side( $route, $data, '', true )['data'];
	}

	/**
	 * Manifest of a job: path → [size, mtime].
	 *
	 * @param string $name Name.
	 * @return array<string, array{0: int, 1: int}>
	 */
	private static function load( string $name ): array {
		$data     = Storage::read_json( self::DIR, 'run-files-' . $name );
		$manifest = array();

		foreach ( is_array( $data['map'] ?? null ) ? $data['map'] : array() as $path => $entry ) {
			$manifest[ (string) $path ] = array( (int) ( $entry[0] ?? 0 ), (int) ( $entry[1] ?? 0 ) );
		}

		return $manifest;
	}

	/**
	 * Saves a manifest.
	 *
	 * @param string                               $name     Name.
	 * @param array<string, array{0: int, 1: int}> $manifest Manifest.
	 */
	private static function store( string $name, array $manifest ): void {
		Storage::write_json( self::DIR, 'run-files-' . $name, array( 'map' => (object) $manifest ) );
	}

	/**
	 * A list of the run.
	 *
	 * @param string $name Name.
	 * @return list<mixed>
	 */
	private static function load_list( string $name ): array {
		$data = Storage::read_json( self::DIR, 'run-files-' . $name );

		return is_array( $data['list'] ?? null ) ? array_values( $data['list'] ) : array();
	}

	/**
	 * Saves a list of the run.
	 *
	 * @param string $name    Name.
	 * @param array  $entries Entries.
	 * @phpstan-param list<mixed> $entries
	 */
	private static function store_list( string $name, array $entries ): void {
		Storage::write_json( self::DIR, 'run-files-' . $name, array( 'list' => $entries ) );
	}
}
