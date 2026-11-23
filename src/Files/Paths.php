<?php
/**
 * Relative file paths received from the other site.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Files;

/**
 * Checks and compares paths relative to the root of a file group (uploads, plugins, themes).
 *
 * Every path from the other site goes through normalize() before it touches the disk: no absolute
 * paths, no "..", no empty segments, no backslashes and no control characters, so a path can never
 * leave its root. Pure logic without WordPress calls.
 */
final class Paths {

	private const WINDOWS_RESERVED = '/^(con|prn|aux|nul|com[1-9]|lpt[1-9])(\..*)?$/i';

	/**
	 * Safe form of a relative path.
	 *
	 * @param string $path Path with forward slashes, relative to the group root.
	 * @return string|null Path, or null when it is not a safe relative path.
	 */
	public static function normalize( string $path ): ?string {
		if ( '' === $path || str_starts_with( $path, '/' ) || str_contains( $path, '\\' ) || 1 === preg_match( '/[\x00-\x1F\x7F]/', $path ) ) {
			return null;
		}

		if ( 1 === preg_match( '/^[A-Za-z]:/', $path ) || 1 !== preg_match( '//u', $path ) ) {
			return null;
		}

		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return null;
			}
		}

		return $path;
	}

	/**
	 * Whether a name cannot be stored on Windows (forbidden characters, reserved names, trailing dot or space).
	 *
	 * @param string $path Relative path.
	 */
	public static function invalid_on_windows( string $path ): bool {
		foreach ( explode( '/', $path ) as $segment ) {
			if ( 1 === preg_match( '/[<>:"|?*]/', $segment ) || 1 === preg_match( self::WINDOWS_RESERVED, $segment ) || 1 === preg_match( '/[. ]$/', $segment ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Form for comparing paths on a file system that ignores letter case (Windows, macOS).
	 *
	 * @param string $path Relative path.
	 */
	public static function fold( string $path ): string {
		return mb_strtolower( $path, 'UTF-8' );
	}

	/**
	 * First segment: the plugin or theme a path belongs to.
	 *
	 * @param string $path Relative path.
	 */
	public static function item( string $path ): string {
		$slash = strpos( $path, '/' );

		return false === $slash ? $path : substr( $path, 0, $slash );
	}

	/**
	 * Lower case extension without the dot.
	 *
	 * @param string $path Relative path.
	 */
	public static function extension( string $path ): string {
		$name = basename( $path );
		$dot  = strrpos( $name, '.' );

		return false === $dot ? '' : strtolower( substr( $name, $dot + 1 ) );
	}
}
