<?php
/**
 * Writing files on the target.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Files;

use WM\Warp\Support\Storage;

/**
 * Receives files into staging folders next to the live ones and swaps them in at the end (3.4, 3.7).
 *
 * Every group root gets a folder ".wm-warp-<run>" with "in" (received files) and "old" (replaced and
 * deleted files kept for Undo). Media files are swapped one by one; a plugin or theme is replaced as
 * a whole folder, so no file of the old version stays behind. The swap is recorded in a journal,
 * which Undo plays backwards. The state lives in files, which survive replacing the database.
 * Warnings are codes with a path; the site that started the migration turns them into text.
 */
final class Stager {

	public const LOADING  = 'loading';
	public const SWAPPING = 'swapping';
	public const SWAPPED  = 'swapped';

	public const MODE_FILES = 'files';
	public const MODE_ITEMS = 'items';

	private const STATE_DIR = 'runs';
	private const STATE     = 'files';
	private const JOURNAL   = 'files-journal';
	private const SWAPLIST  = 'files-swaplist';
	private const PLAN      = 'files-plan';

	private const PART_SUFFIX = '.wm-warp-part';

	/**
	 * Starts receiving files.
	 *
	 * @param string                                                                   $run_id Run ID.
	 * @param string                                                                   $source Name of the source site.
	 * @param array<string, array{mode: string, items: list<string>, allow_php: bool}> $groups Groups of the run.
	 * @return array{free: array<string, int>}
	 * @throws \UnexpectedValueException When a group is unknown.
	 */
	public static function begin( string $run_id, string $source, array $groups ): array {
		$previous = self::state();
		if ( null !== $previous ) {
			self::SWAPPED === $previous['status'] ? self::confirm() : self::abort();
		}

		$clean = array();
		$free  = array();

		foreach ( $groups as $group => $spec ) {
			if ( ! in_array( $group, Groups::ALL, true ) ) {
				throw new \UnexpectedValueException( 'Unknown file group.' );
			}

			$items = array();
			foreach ( $spec['items'] as $item ) {
				if ( null !== Paths::normalize( $item ) && ! str_contains( $item, '/' ) && ! Groups::is_protected( $group, $item ) ) {
					$items[] = $item;
				}
			}

			$clean[ $group ] = array(
				'mode'      => self::MODE_ITEMS === $spec['mode'] ? self::MODE_ITEMS : self::MODE_FILES,
				'items'     => $items,
				'allow_php' => $spec['allow_php'],
			);

			self::remove_leftovers( $group );

			$staging = Groups::staging( $group, $run_id );
			Fs::mkdir( $staging . '/in' );
			Fs::mkdir( $staging . '/old' );
			self::protect( $staging );

			$free[ $group ] = Fs::free( $staging );
		}

		Storage::delete_json( self::STATE_DIR, self::JOURNAL );
		Storage::delete_json( self::STATE_DIR, self::SWAPLIST );
		Storage::delete_json( self::STATE_DIR, self::PLAN );
		self::save(
			array(
				'run_id'   => $run_id,
				'status'   => self::LOADING,
				'source'   => $source,
				'started'  => time(),
				'finished' => 0,
				'groups'   => $clean,
				'swap'     => array(
					'stage' => 'list',
					'index' => 0,
				),
				'counts'   => array(
					'files'   => 0,
					'deleted' => 0,
					'items'   => 0,
				),
				'warnings' => array(),
			)
		);

		return array( 'free' => $free );
	}

	/**
	 * Writes received parts of files.
	 *
	 * @param string $run_id  Run ID.
	 * @param array  $parts   Parts: group, path, offset, length, size, mtime, final, hash.
	 * @phpstan-param list<mixed> $parts
	 * @param string $payload Bytes of all parts one after another.
	 * @return array{written: int}
	 * @throws \UnexpectedValueException When a part is invalid or a file arrived damaged.
	 */
	public static function write( string $run_id, array $parts, string $payload ): array {
		$state    = self::require_state( $run_id, self::LOADING );
		$position = 0;
		$written  = 0;

		foreach ( $parts as $part ) {
			$part   = self::part( $part );
			$group  = $part['group'];
			$path   = self::allowed( $state, $group, $part['path'] );
			$data   = (string) substr( $payload, $position, $part['length'] );
			$target = Groups::staging( $group, $run_id ) . '/in/' . $path;

			if ( strlen( $data ) !== $part['length'] ) {
				throw new \UnexpectedValueException( 'A part of a file is missing in the request.' );
			}

			$position += $part['length'];

			Fs::write( $target . self::PART_SUFFIX, $part['offset'], $data );

			if ( ! $part['final'] ) {
				continue;
			}

			$stat = Fs::stat( $target . self::PART_SUFFIX );
			$hash = '' === $part['hash'] ? '' : (string) Fs::hash( $target . self::PART_SUFFIX );

			if ( null === $stat || $stat[0] !== $part['size'] || $hash !== $part['hash'] ) {
				throw new \UnexpectedValueException( sprintf( 'The file %s arrived damaged (its size or contents differ from the source).', $path ) );
			}

			Fs::delete( $target );
			Fs::move( $target . self::PART_SUFFIX, $target );
			Fs::touch( $target, $part['mtime'] );
			++$written;
		}

		return array( 'written' => $written );
	}

	/**
	 * Copies unchanged files of a plugin or theme from the live folder into the new folder.
	 *
	 * @param string $run_id Run ID.
	 * @param string $group  Group.
	 * @param array  $files  Files: path and modification time of the source.
	 * @phpstan-param list<mixed> $files
	 * @return array{copied: int}
	 * @throws \UnexpectedValueException When a file is not part of the run.
	 */
	public static function copy( string $run_id, string $group, array $files ): array {
		$state  = self::require_state( $run_id, self::LOADING );
		$root   = Groups::root( $group );
		$copied = 0;

		if ( self::MODE_ITEMS !== ( $state['groups'][ $group ]['mode'] ?? '' ) ) {
			throw new \UnexpectedValueException( 'Only files of plugins and themes are copied.' );
		}

		foreach ( $files as $file ) {
			$path = self::allowed( $state, $group, is_array( $file ) ? (string) ( $file[0] ?? '' ) : '' );
			$live = $root . '/' . $path;

			if ( ! Fs::inside( $root, $live ) || null === Fs::stat( $live ) ) {
				throw new \UnexpectedValueException( sprintf( 'The file %s is missing on the target.', $path ) );
			}

			Fs::copy( $live, Groups::staging( $group, $run_id ) . '/in/' . $path, (int) ( is_array( $file ) ? ( $file[1] ?? 0 ) : 0 ) );
			++$copied;
		}

		return array( 'copied' => $copied );
	}

	/**
	 * Adds what happens at the swap besides the received files: deletions and modification times
	 * (media) and the plugins and themes to replace.
	 *
	 * @param string $run_id Run ID.
	 * @param string $group  Group.
	 * @param array  $plan   Keys delete (paths), touch (path and time), items (names).
	 * @phpstan-param array<string, mixed> $plan
	 * @throws \UnexpectedValueException When an entry is invalid.
	 */
	public static function plan( string $run_id, string $group, array $plan ): void {
		$state = self::require_state( $run_id, self::LOADING );
		$all   = self::plan_data();
		$entry = is_array( $all[ $group ] ?? null ) ? $all[ $group ] : array();

		foreach ( array( 'delete', 'touch', 'items' ) as $key ) {
			$list = is_array( $entry[ $key ] ?? null ) ? $entry[ $key ] : array();

			foreach ( is_array( $plan[ $key ] ?? null ) ? $plan[ $key ] : array() as $value ) {
				if ( 'touch' === $key ) {
					$path   = self::allowed( $state, $group, is_array( $value ) ? (string) ( $value[0] ?? '' ) : '' );
					$list[] = array( $path, (int) ( is_array( $value ) ? ( $value[1] ?? 0 ) : 0 ) );
				} else {
					$list[] = self::allowed( $state, $group, is_string( $value ) ? $value : '' );
				}
			}

			$entry[ $key ] = $list;
		}

		$all[ $group ] = $entry;
		Storage::write_json( self::STATE_DIR, self::PLAN, $all );
	}

	/**
	 * Swaps the received files in, for a limited time; call again until done.
	 *
	 * @param string $run_id  Run ID.
	 * @param float  $seconds Time limit.
	 * @return array{done: bool, files: int, deleted: int, items: int, warnings: list<mixed>}
	 * @throws \UnexpectedValueException When the run is unknown.
	 */
	public static function swap( string $run_id, float $seconds = 5.0 ): array {
		$state = self::state();

		if ( null === $state || $run_id !== $state['run_id'] || ! in_array( $state['status'], array( self::LOADING, self::SWAPPING ), true ) ) {
			throw new \UnexpectedValueException( 'The migration is no longer in progress on this site; it was cancelled or another migration started.' );
		}

		if ( self::LOADING === $state['status'] ) {
			$state['status'] = self::SWAPPING;
			self::save( $state );
			Storage::write_json( self::STATE_DIR, self::SWAPLIST, array( 'list' => self::swap_list( $state ) ) );
		}

		$list     = Storage::read_json( self::STATE_DIR, self::SWAPLIST );
		$list     = is_array( $list['list'] ?? null ) ? array_values( $list['list'] ) : array();
		$journal  = self::journal();
		$index    = (int) $state['swap']['index'];
		$deadline = microtime( true ) + $seconds;
		$count    = count( $list );

		try {
			while ( $index < $count && microtime( true ) < $deadline ) {
				$operation = is_array( $list[ $index ] ) ? $list[ $index ] : array();
				self::apply( $state, $operation, $journal );
				++$index;

				if ( 0 === $index % 200 ) {
					$state['swap']['index'] = $index;
					self::save_journal( $journal );
					self::save( $state );
				}
			}
		} finally {
			$state['swap']['index'] = $index;
			self::save_journal( $journal );
			self::save( $state );
		}

		$done = $index >= $count;

		if ( $done ) {
			$state['status']   = self::SWAPPED;
			$state['finished'] = time();
			self::save( $state );
			Storage::delete_json( self::STATE_DIR, self::SWAPLIST );
			wp_cache_flush();
		}

		return array(
			'done'     => $done,
			'files'    => (int) $state['counts']['files'],
			'deleted'  => (int) $state['counts']['deleted'],
			'items'    => (int) $state['counts']['items'],
			'warnings' => array_values( (array) $state['warnings'] ),
		);
	}

	/**
	 * Confirms: deletes the staging folders with the replaced files.
	 */
	public static function confirm(): void {
		$state = self::state();

		if ( null === $state ) {
			return;
		}

		if ( self::SWAPPED !== $state['status'] ) {
			self::abort();

			return;
		}

		self::delete_staging( $state );
		self::delete_state();
	}

	/**
	 * Undoes the swap: plays the journal backwards.
	 *
	 * @throws \UnexpectedValueException When there is nothing to undo.
	 */
	public static function rollback(): void {
		$state = self::state();

		if ( null === $state || ! in_array( $state['status'], array( self::SWAPPING, self::SWAPPED ), true ) ) {
			throw new \UnexpectedValueException( 'There is no migration to undo.' );
		}

		self::undo( $state );
	}

	/**
	 * Discards an unfinished run; files already swapped in by this run are put back.
	 *
	 * @param string $run_id Run ID, empty for any unfinished run (a swapped run is then left alone).
	 */
	public static function abort( string $run_id = '' ): void {
		$state = self::state();

		if ( null === $state || ( '' !== $run_id && $run_id !== $state['run_id'] ) || ( '' === $run_id && self::SWAPPED === $state['status'] ) ) {
			return;
		}

		// A run of this ID that failed after its files were swapped in (e.g. the database swap failed) is undone.
		if ( self::LOADING !== $state['status'] ) {
			self::undo( $state );

			return;
		}

		self::delete_staging( $state );
		self::delete_state();
	}

	/**
	 * Run waiting for confirmation.
	 *
	 * @return array{run_id: string, source: string, finished: int}|null
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
		);
	}

	/**
	 * Status of the current state.
	 *
	 * @return array{run_id: string, status: string}|null
	 */
	public static function status(): ?array {
		$state = self::state();

		return null === $state ? null : array(
			'run_id' => (string) $state['run_id'],
			'status' => (string) $state['status'],
		);
	}

	/**
	 * Operations of the swap: received media files, modification times, deletions, plugins and themes.
	 *
	 * @param array<string, mixed> $state State.
	 * @return list<array{0: string, 1: string, 2: string, 3: int}> Operation, group, path, time.
	 */
	private static function swap_list( array $state ): array {
		$plan = self::plan_data();
		$list = array();

		foreach ( (array) $state['groups'] as $group => $spec ) {
			$group = (string) $group;
			$entry = is_array( $plan[ $group ] ?? null ) ? $plan[ $group ] : array();

			if ( self::MODE_ITEMS === $spec['mode'] ) {
				foreach ( (array) ( $entry['items'] ?? array() ) as $item ) {
					$list[] = array( 'item', $group, (string) $item, 0 );
				}
				continue;
			}

			$in    = Groups::staging( $group, (string) $state['run_id'] ) . '/in';
			$stack = array( '' );

			while ( array() !== $stack ) {
				$relative = (string) array_pop( $stack );
				$absolute = '' === $relative ? $in : $in . '/' . $relative;

				foreach ( Fs::names( $absolute ) as $name ) {
					$path = '' === $relative ? $name : $relative . '/' . $name;

					if ( is_dir( $absolute . '/' . $name ) ) {
						$stack[] = $path;
					} elseif ( ! str_ends_with( $name, self::PART_SUFFIX ) ) {
						$list[] = array( 'file', $group, $path, 0 );
					}
				}
			}

			foreach ( (array) ( $entry['touch'] ?? array() ) as $touch ) {
				$list[] = array( 'touch', $group, (string) $touch[0], (int) $touch[1] );
			}

			foreach ( (array) ( $entry['delete'] ?? array() ) as $path ) {
				$list[] = array( 'delete', $group, (string) $path, 0 );
			}
		}

		return $list;
	}

	/**
	 * Applies one operation of the swap and records it.
	 *
	 * @param array<string, mixed>                                 $state     State (counts and warnings change).
	 * @param array<mixed>                                         $operation Operation, group, path, time.
	 * @param list<array{0: string, 1: string, 2: string, 3: int}> $journal Journal.
	 */
	private static function apply( array &$state, array $operation, array &$journal ): void {
		[ $kind, $group, $path, $time ] = array( (string) ( $operation[0] ?? '' ), (string) ( $operation[1] ?? '' ), (string) ( $operation[2] ?? '' ), (int) ( $operation[3] ?? 0 ) );

		$root    = Groups::root( $group );
		$staging = Groups::staging( $group, (string) $state['run_id'] );
		$live    = $root . '/' . $path;

		if ( null === Paths::normalize( $path ) || Groups::is_protected( $group, $path ) ) {
			return;
		}

		if ( 'item' === $kind ) {
			if ( ! is_dir( $staging . '/in/' . $path ) && ! is_file( $staging . '/in/' . $path ) ) {
				return;
			}

			if ( Fs::is_link( $live ) ) {
				$state['warnings'][] = array( 'item_link', $group . '/' . $path );

				return;
			}

			$existed = file_exists( $live );
			if ( $existed ) {
				Fs::move( $live, $staging . '/old/' . $path );
			}

			Fs::move( $staging . '/in/' . $path, $live );
			$journal[] = array( $existed ? 'item_replace' : 'item_new', $group, $path, 0 );
			self::invalidate( $live );
			++$state['counts']['items'];

			return;
		}

		if ( ! self::inside_parent( $root, $live ) ) {
			$state['warnings'][] = array( 'folder_link', $group . '/' . $path );

			return;
		}

		if ( 'touch' === $kind ) {
			$stat = Fs::stat( $live );
			if ( null !== $stat && ! is_link( $live ) ) {
				Fs::touch( $live, $time );
				$journal[] = array( 'touch', $group, $path, $stat[1] );
			}

			return;
		}

		if ( 'delete' === $kind ) {
			if ( null !== Fs::stat( $live ) && ! is_link( $live ) ) {
				Fs::move( $live, $staging . '/old/' . $path );
				$journal[] = array( 'delete', $group, $path, 0 );
				++$state['counts']['deleted'];
			}

			return;
		}

		if ( is_link( $live ) || is_dir( $live ) ) {
			$state['warnings'][] = array( 'not_file', $group . '/' . $path );

			return;
		}

		foreach ( self::make_parents( $root, $path ) as $created ) {
			$journal[] = array( 'dir', $group, $created, 0 );
		}

		$existed = null !== Fs::stat( $live );
		$perms   = $existed ? Fs::perms( $live ) : self::file_mode();

		if ( $existed ) {
			Fs::move( $live, $staging . '/old/' . $path );
		}

		Fs::move( $staging . '/in/' . $path, $live );
		Fs::chmod( $live, $perms );
		$journal[] = array( $existed ? 'replace' : 'new', $group, $path, 0 );
		self::invalidate( $live );
		++$state['counts']['files'];
	}

	/**
	 * Plays the journal backwards, then removes the staging folders and the state.
	 *
	 * @param array<string, mixed> $state State.
	 */
	private static function undo( array $state ): void {
		$journal = self::journal();

		foreach ( array_reverse( $journal ) as [ $kind, $group, $path, $time ] ) {
			$root    = Groups::root( $group );
			$staging = Groups::staging( $group, (string) $state['run_id'] );
			$live    = $root . '/' . $path;
			$old     = $staging . '/old/' . $path;

			try {
				match ( $kind ) {
					'new'          => Fs::delete( $live ),
					'replace'      => self::restore_file( $live, $old ),
					'delete'       => Fs::move( $old, $live ),
					'touch'        => Fs::touch( $live, $time ),
					'dir'          => Fs::delete_if_empty( $live ),
					'item_new'     => Fs::move( $live, $staging . '/in/' . $path ),
					'item_replace' => self::restore_item( $live, $old, $staging . '/in/' . $path ),
					default        => null,
				};

				if ( str_starts_with( $kind, 'item' ) || 'replace' === $kind ) {
					self::invalidate( $live );
				}
			} catch ( \RuntimeException $error ) {
				// Keep going; what could not be restored stays in the staging folder.
				unset( $error );
			}
		}

		self::delete_staging( $state );
		self::delete_state();
		wp_cache_flush();
	}

	/**
	 * Puts a replaced file back.
	 *
	 * @param string $live Live path.
	 * @param string $old  Kept copy.
	 */
	private static function restore_file( string $live, string $old ): void {
		Fs::delete( $live );
		Fs::move( $old, $live );
	}

	/**
	 * Puts a replaced plugin or theme folder back.
	 *
	 * @param string $live  Live path.
	 * @param string $old   Kept folder.
	 * @param string $aside Where the new folder goes.
	 */
	private static function restore_item( string $live, string $old, string $aside ): void {
		Fs::delete_tree( $aside );
		Fs::move( $live, $aside );
		Fs::move( $old, $live );
	}

	/**
	 * Creates missing parent folders of a path inside a root.
	 *
	 * @param string $root Root.
	 * @param string $path Relative file path.
	 * @return list<string> Created folders, relative, from the top.
	 */
	private static function make_parents( string $root, string $path ): array {
		$segments = explode( '/', $path );
		array_pop( $segments );
		$created = array();
		$current = '';

		foreach ( $segments as $segment ) {
			$current = '' === $current ? $segment : $current . '/' . $segment;

			if ( ! is_dir( $root . '/' . $current ) ) {
				Fs::mkdir( $root . '/' . $current );
				$created[] = $current;
			}
		}

		return $created;
	}

	/**
	 * Clears PHP files from the opcode cache, otherwise the server keeps running the old code.
	 *
	 * @param string $path File or folder.
	 */
	private static function invalidate( string $path ): void {
		if ( ! function_exists( 'opcache_invalidate' ) ) {
			return;
		}

		if ( is_file( $path ) ) {
			if ( 'php' === Paths::extension( $path ) ) {
				opcache_invalidate( $path, true );
			}

			return;
		}

		if ( ! is_dir( $path ) || Fs::is_link( $path ) ) {
			return;
		}

		foreach ( Fs::names( $path ) as $name ) {
			self::invalidate( $path . '/' . $name );
		}
	}

	/**
	 * Checks a path from the other site against the run.
	 *
	 * @param array<string, mixed> $state State.
	 * @param string               $group Group.
	 * @param string               $path  Relative path.
	 * @throws \UnexpectedValueException When the path is not allowed.
	 */
	private static function allowed( array $state, string $group, string $path ): string {
		$spec = is_array( $state['groups'][ $group ] ?? null ) ? $state['groups'][ $group ] : null;
		$safe = Paths::normalize( $path );

		if ( null === $spec || null === $safe || Groups::is_protected( $group, $safe ) || str_ends_with( $safe, self::PART_SUFFIX ) ) {
			throw new \UnexpectedValueException( sprintf( 'The path %s is not allowed.', $path ) );
		}

		if ( self::MODE_ITEMS === $spec['mode'] && ! in_array( Paths::item( $safe ), (array) $spec['items'], true ) ) {
			throw new \UnexpectedValueException( sprintf( 'The path %s is not part of the chosen plugins or themes.', $path ) );
		}

		if ( Groups::excludes( $group, array(), (bool) $spec['allow_php'] )->covers( $safe ) ) {
			throw new \UnexpectedValueException( sprintf( 'The path %s is excluded from transfers.', $path ) );
		}

		return $safe;
	}

	/**
	 * Part of a file from request data.
	 *
	 * @param mixed $part Raw part.
	 * @return array{group: string, path: string, offset: int, length: int, size: int, mtime: int, final: bool, hash: string}
	 * @throws \UnexpectedValueException When it is invalid.
	 */
	private static function part( mixed $part ): array {
		if ( ! is_array( $part ) || ! is_string( $part['group'] ?? null ) || ! is_string( $part['path'] ?? null ) ) {
			throw new \UnexpectedValueException( 'Invalid part of a file.' );
		}

		$result = array(
			'group'  => $part['group'],
			'path'   => $part['path'],
			'offset' => (int) ( $part['offset'] ?? -1 ),
			'length' => (int) ( $part['length'] ?? -1 ),
			'size'   => (int) ( $part['size'] ?? -1 ),
			'mtime'  => (int) ( $part['mtime'] ?? 0 ),
			'final'  => true === ( $part['final'] ?? false ),
			'hash'   => is_string( $part['hash'] ?? null ) ? $part['hash'] : '',
		);

		if ( $result['offset'] < 0 || $result['length'] < 0 || $result['size'] < 0 || $result['offset'] + $result['length'] > $result['size'] ) {
			throw new \UnexpectedValueException( 'Invalid part of a file.' );
		}

		return $result;
	}

	/**
	 * Whether the existing parent folders of a path are real folders inside the root (not links).
	 *
	 * @param string $root Root.
	 * @param string $live Absolute path.
	 */
	private static function inside_parent( string $root, string $live ): bool {
		$dir = Fs::slashes( dirname( $live ) );

		$floor = strlen( $root );
		$depth = strlen( $dir );

		while ( ! is_dir( $dir ) && $depth > $floor ) {
			$dir   = Fs::slashes( dirname( $dir ) );
			$depth = strlen( $dir );
		}

		$real_root = realpath( $root );
		$real      = realpath( $dir );

		if ( false === $real_root || false === $real ) {
			return false;
		}

		// The real path of the folder must be the root followed by the same folders: no link on the way.
		$expected = Fs::slashes( $real_root ) . substr( $dir, strlen( $root ) );
		$real     = Fs::slashes( $real );

		return Groups::case_insensitive() ? 0 === strcasecmp( $real, $expected ) : $real === $expected;
	}

	/**
	 * Mode of new files.
	 */
	private static function file_mode(): int {
		if ( defined( 'FS_CHMOD_FILE' ) ) {
			return (int) FS_CHMOD_FILE;
		}

		return ( Fs::perms( ABSPATH . 'index.php' ) | 0644 ) & 0777;
	}

	/**
	 * Removes staging folders of runs that no state owns (a crashed run).
	 *
	 * @param string $group Group.
	 */
	private static function remove_leftovers( string $group ): void {
		$root = Groups::root( $group );

		if ( ! is_dir( $root ) ) {
			return;
		}

		foreach ( Fs::names( $root ) as $name ) {
			if ( str_starts_with( $name, Groups::STAGING_PREFIX ) && is_dir( $root . '/' . $name ) ) {
				Fs::delete_tree( $root . '/' . $name );
			}
		}
	}

	/**
	 * Deletes the staging folders of a run.
	 *
	 * @param array<string, mixed> $state State.
	 */
	private static function delete_staging( array $state ): void {
		foreach ( array_keys( (array) $state['groups'] ) as $group ) {
			try {
				Fs::delete_tree( Groups::staging( (string) $group, (string) $state['run_id'] ) );
			} catch ( \RuntimeException $error ) {
				unset( $error );
			}
		}

		Storage::delete_json( self::STATE_DIR, self::PLAN );
		Storage::delete_json( self::STATE_DIR, self::SWAPLIST );
		Storage::delete_json( self::STATE_DIR, self::JOURNAL );
	}

	/**
	 * Denies web access to a staging folder.
	 *
	 * @param string $dir Folder.
	 */
	private static function protect( string $dir ): void {
		Fs::put( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		Fs::put( $dir . '/.htaccess', "Require all denied\n" );
		Fs::put( $dir . '/web.config', "<?xml version=\"1.0\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n" );
	}

	/**
	 * Plan of the swap.
	 *
	 * @return array<string, mixed>
	 */
	private static function plan_data(): array {
		return Storage::read_json( self::STATE_DIR, self::PLAN ) ?? array();
	}

	/**
	 * Journal of the swap.
	 *
	 * @return list<array{0: string, 1: string, 2: string, 3: int}>
	 */
	private static function journal(): array {
		$data    = Storage::read_json( self::STATE_DIR, self::JOURNAL );
		$journal = array();

		foreach ( is_array( $data['list'] ?? null ) ? $data['list'] : array() as $entry ) {
			if ( is_array( $entry ) ) {
				$journal[] = array( (string) ( $entry[0] ?? '' ), (string) ( $entry[1] ?? '' ), (string) ( $entry[2] ?? '' ), (int) ( $entry[3] ?? 0 ) );
			}
		}

		return $journal;
	}

	/**
	 * Saves the journal.
	 *
	 * @param list<array{0: string, 1: string, 2: string, 3: int}> $journal Journal.
	 */
	private static function save_journal( array $journal ): void {
		Storage::write_json( self::STATE_DIR, self::JOURNAL, array( 'list' => $journal ) );
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
		$state = Storage::read_json( self::STATE_DIR, self::STATE );

		return null !== $state && isset( $state['run_id'], $state['status'], $state['groups'] ) && is_array( $state['groups'] ) ? $state : null;
	}

	/**
	 * Saves the state.
	 *
	 * @param array<string, mixed> $state State.
	 */
	private static function save( array $state ): void {
		Storage::write_json( self::STATE_DIR, self::STATE, $state );
	}

	/**
	 * Deletes the state.
	 */
	private static function delete_state(): void {
		Storage::delete_json( self::STATE_DIR, self::STATE );
	}
}
