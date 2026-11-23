<?php
/**
 * Listing the files of a group.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Files;

/**
 * Walks folders of a group in steps (uploads may hold hundreds of thousands of files) and returns a
 * manifest: path, size and modification time. Links and junctions are never followed, Warp's own
 * folders are skipped.
 */
final class Scanner {

	/**
	 * Scans for a limited time.
	 *
	 * @param string        $group    Group.
	 * @param string[]      $bases    Paths to scan relative to the root ('' = the whole root); plugins or themes.
	 * @phpstan-param list<string> $bases
	 * @param Excludes      $excludes Exclusions.
	 * @param string[]|null $stack    Folders left from the previous step, null to start.
	 * @phpstan-param list<string>|null $stack
	 * @param float         $seconds  Time limit.
	 * @param int           $max      Largest number of entries per step.
	 * @return array{entries: list<array{0: string, 1: int, 2: int}>, links: list<string>, stack: list<string>, done: bool}
	 */
	public static function scan( string $group, array $bases, Excludes $excludes, ?array $stack, float $seconds = 3.0, int $max = 20000 ): array {
		$root     = Groups::root( $group );
		$stack    = $stack ?? array_reverse( $bases );
		$deadline = microtime( true ) + $seconds;
		$entries  = array();
		$links    = array();
		$found    = 0;

		while ( array() !== $stack && microtime( true ) < $deadline && $found < $max ) {
			$relative = (string) array_pop( $stack );
			$absolute = '' === $relative ? $root : $root . '/' . $relative;

			if ( '' !== $relative ) {
				if ( null === Paths::normalize( $relative ) || Groups::is_protected( $group, $relative ) ) {
					continue;
				}

				if ( Fs::is_link( $absolute ) ) {
					$links[] = $relative;
					continue;
				}

				if ( is_file( $absolute ) ) {
					$stat = $excludes->matches( $relative, false ) ? null : Fs::stat( $absolute );
					if ( null !== $stat ) {
						$entries[] = array( $relative, $stat[0], $stat[1] );
						++$found;
					}
					continue;
				}

				if ( ! is_dir( $absolute ) ) {
					continue;
				}
			}

			$folders = array();

			foreach ( Fs::names( $absolute ) as $name ) {
				$path = '' === $relative ? $name : $relative . '/' . $name;
				$full = $absolute . '/' . $name;

				if ( Groups::is_protected( $group, $path ) ) {
					continue;
				}

				if ( is_dir( $full ) ) {
					if ( Fs::is_link( $full ) ) {
						$links[] = $path;
					} elseif ( ! $excludes->matches( $path, true ) ) {
						$folders[] = $path;
					}
				} elseif ( is_link( $full ) ) {
					$links[] = $path;
				} elseif ( ! $excludes->matches( $path, false ) ) {
					$stat = Fs::stat( $full );
					if ( null !== $stat ) {
						$entries[] = array( $path, $stat[0], $stat[1] );
						++$found;
					}
				}
			}

			foreach ( array_reverse( $folders ) as $folder ) {
				$stack[] = $folder;
			}
		}

		return array(
			'entries' => $entries,
			'links'   => $links,
			'stack'   => $stack,
			'done'    => array() === $stack,
		);
	}
}
