<?php
/**
 * Excluded files in the .gitignore format.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Files;

/**
 * Matches relative paths against exclusion patterns written like .gitignore:
 *
 * - "*.log" or "Thumbs.db" (no slash) matches the name at any depth;
 * - "/cache" or "a/b" (a slash at the start or inside) matches from the root of the group;
 * - "cache/" (trailing slash) matches only folders;
 * - "*" and "?" do not cross a slash, "**" does; lines starting with "#" are comments.
 *
 * Letter case is ignored ("*.LOG" is excluded too). Negation ("!") is not supported.
 * Pure logic without WordPress calls.
 */
final class Excludes {

	/**
	 * Compiled patterns.
	 *
	 * @var list<array{regex: string, dir: bool}>
	 */
	private array $patterns = array();

	/**
	 * Constructor.
	 *
	 * @param string[] $patterns Patterns, one per item.
	 * @phpstan-param list<string> $patterns
	 */
	public function __construct( array $patterns ) {
		foreach ( $patterns as $pattern ) {
			$pattern = trim( $pattern );

			if ( '' === $pattern || str_starts_with( $pattern, '#' ) || str_starts_with( $pattern, '!' ) ) {
				continue;
			}

			$dir     = str_ends_with( $pattern, '/' );
			$pattern = rtrim( $pattern, '/' );

			if ( '' === $pattern ) {
				continue;
			}

			$anchored = str_contains( $pattern, '/' );
			$pattern  = ltrim( $pattern, '/' );
			$body     = self::glob_to_regex( $pattern );

			$this->patterns[] = array(
				'regex' => '#' . ( $anchored ? '^' : '(?:^|/)' ) . $body . '$#iu',
				'dir'   => $dir,
			);
		}
	}

	/**
	 * Patterns from text with one pattern per line.
	 *
	 * @param string $text Text.
	 * @return list<string>
	 */
	public static function lines( string $text ): array {
		$lines = preg_split( '/\R/', $text );

		return array_values( array_filter( array_map( 'trim', false === $lines ? array() : $lines ), static fn( string $line ): bool => '' !== $line ) );
	}

	/**
	 * Whether a path is excluded.
	 *
	 * @param string $path   Path relative to the group root.
	 * @param bool   $is_dir Whether the path is a folder.
	 */
	public function matches( string $path, bool $is_dir ): bool {
		foreach ( $this->patterns as $pattern ) {
			if ( ( $is_dir || ! $pattern['dir'] ) && 1 === preg_match( $pattern['regex'], $path ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a file path or any of its folders is excluded.
	 *
	 * @param string $path File path relative to the group root.
	 */
	public function covers( string $path ): bool {
		$segments = explode( '/', $path );
		$count    = count( $segments );

		for ( $i = 1; $i < $count; $i++ ) {
			if ( $this->matches( implode( '/', array_slice( $segments, 0, $i ) ), true ) ) {
				return true;
			}
		}

		return $this->matches( $path, false );
	}

	/**
	 * Regular expression body of a glob.
	 *
	 * @param string $glob Glob.
	 */
	private static function glob_to_regex( string $glob ): string {
		$regex  = '';
		$length = strlen( $glob );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $glob[ $i ];

			if ( '*' === $char && '*' === ( $glob[ $i + 1 ] ?? '' ) ) {
				if ( '/' === ( $glob[ $i + 2 ] ?? '' ) ) {
					$regex .= '(?:.*/)?';
					$i     += 2;
				} else {
					$regex .= '.*';
					++$i;
				}
			} elseif ( '*' === $char ) {
				$regex .= '[^/]*';
			} elseif ( '?' === $char ) {
				$regex .= '[^/]';
			} else {
				$regex .= preg_quote( $char, '#' );
			}
		}

		return $regex;
	}
}
