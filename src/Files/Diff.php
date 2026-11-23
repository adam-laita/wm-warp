<?php
/**
 * Comparing the files of the source and the target.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Files;

/**
 * Compares two manifests (path → size and modification time) and decides what happens to every file.
 *
 * - transfer: missing on the target, a different size, or the same name with different letter case;
 * - check: the same size but a different modification time; the contents are compared by hash;
 * - same: identical size and time;
 * - delete: only on the target (only when the target becomes an exact copy);
 * - skipped: cannot be stored on the target (name not allowed on Windows, two names differing only in case).
 *
 * Pure logic without WordPress calls.
 */
final class Diff {

	/**
	 * Compares manifests.
	 *
	 * @param array<string, array{0: int, 1: int}> $source      Source: path → [size, mtime].
	 * @param array<string, array{0: int, 1: int}> $target      Target: path → [size, mtime].
	 * @param bool                                 $mirror      Delete files that are only on the target.
	 * @param int                                  $since       Only source files modified at or after this Unix time; 0 = all.
	 * @param bool                                 $insensitive The target ignores letter case in names.
	 * @param bool                                 $windows     The target runs on Windows.
	 * @return array{
	 *     transfer: list<array{0: string, 1: int, 2: int}>,
	 *     check: list<array{0: string, 1: int, 2: int}>,
	 *     same: list<array{0: string, 1: int, 2: int}>,
	 *     delete: list<string>,
	 *     skipped: list<array{0: string, 1: string}>
	 * }
	 */
	public static function compare( array $source, array $target, bool $mirror, int $since, bool $insensitive, bool $windows ): array {
		$result = array(
			'transfer' => array(),
			'check'    => array(),
			'same'     => array(),
			'delete'   => array(),
			'skipped'  => array(),
		);

		$folded = array();
		if ( $insensitive ) {
			foreach ( $target as $path => $entry ) {
				$folded[ Paths::fold( (string) $path ) ] = (string) $path;
			}
		}

		$seen = array();

		foreach ( $source as $path => [ $size, $mtime ] ) {
			$path = (string) $path;
			$key  = $insensitive ? Paths::fold( $path ) : $path;

			if ( $windows && Paths::invalid_on_windows( $path ) ) {
				$result['skipped'][] = array( $path, 'windows' );
				continue;
			}

			if ( isset( $seen[ $key ] ) ) {
				$result['skipped'][] = array( $path, 'case' );
				continue;
			}

			$seen[ $key ] = true;

			if ( $since > 0 && $mtime < $since ) {
				continue;
			}

			$match = $insensitive ? ( $folded[ $key ] ?? null ) : ( isset( $target[ $path ] ) ? $path : null );

			if ( null === $match || $match !== $path || $target[ $match ][0] !== $size ) {
				$result['transfer'][] = array( $path, $size, $mtime );
			} elseif ( $target[ $match ][1] !== $mtime ) {
				$result['check'][] = array( $path, $size, $mtime );
			} else {
				$result['same'][] = array( $path, $size, $mtime );
			}
		}

		if ( $mirror && 0 === $since ) {
			foreach ( $target as $path => $entry ) {
				$path = (string) $path;

				if ( ! isset( $seen[ $insensitive ? Paths::fold( $path ) : $path ] ) ) {
					$result['delete'][] = $path;
				}
			}
		}

		return $result;
	}

	/**
	 * Total size of entries.
	 *
	 * @param list<array{0: string, 1: int, 2: int}> $entries Entries: path, size, mtime.
	 */
	public static function bytes( array $entries ): int {
		return (int) array_sum( array_column( $entries, 1 ) );
	}
}
