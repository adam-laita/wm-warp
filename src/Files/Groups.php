<?php
/**
 * File groups of a site.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Files;

use const WM\Warp\PLUGIN_FILE;

/**
 * Groups of files by role, not by absolute path: every site finds its own folders, so sites with a
 * different structure work too (3.1).
 */
final class Groups {

	public const UPLOADS = 'uploads';
	public const PLUGINS = 'plugins';
	public const THEMES  = 'themes';

	public const ALL = array( self::UPLOADS, self::THEMES, self::PLUGINS );

	/**
	 * Prefix of Warp's staging folders inside a group root.
	 */
	public const STAGING_PREFIX = '.wm-warp-';

	/**
	 * Patterns always excluded, in every group.
	 */
	private const FIXED = array( '.wm-warp-*/', '.git/', '.svn/', '.hg/', 'node_modules/', '.DS_Store', 'Thumbs.db', 'desktop.ini' );

	/**
	 * Executable files never transferred into uploads unless the user allows it (3.5).
	 */
	public const PHP_PATTERNS = array( '*.php', '*.phtml', '*.phar', '*.pht', '*.phps', '*.php3', '*.php4', '*.php5', '*.php7', '*.php8' );

	/**
	 * Editable exclusions offered in the form.
	 *
	 * @param string $group Group.
	 * @return list<string>
	 */
	public static function default_excludes( string $group ): array {
		return self::UPLOADS === $group
			? array( '.DS_Store', '*.log', '*backup*/', '*cache*/' )
			: array( '.DS_Store', '.git', 'node_modules' );
	}

	/**
	 * Absolute root of a group on this site, with forward slashes and without a trailing slash.
	 *
	 * @param string $group Group.
	 * @throws \UnexpectedValueException When the group is unknown.
	 */
	public static function root( string $group ): string {
		return Fs::slashes(
			match ( $group ) {
				self::UPLOADS => (string) wp_upload_dir( null, false )['basedir'],
				self::PLUGINS => WP_PLUGIN_DIR,
				self::THEMES  => get_theme_root(),
				default       => throw new \UnexpectedValueException( 'Unknown file group.' ),
			}
		);
	}

	/**
	 * Exclusions of a group.
	 *
	 * @param string   $group     Group.
	 * @param string[] $patterns  Patterns chosen by the user.
	 * @phpstan-param list<string> $patterns
	 * @param bool     $allow_php Transfer PHP files in uploads.
	 */
	public static function excludes( string $group, array $patterns, bool $allow_php ): Excludes {
		$all = array_merge( self::FIXED, $patterns );

		if ( self::UPLOADS === $group && ! $allow_php ) {
			$all = array_merge( $all, self::PHP_PATTERNS );
		}

		return new Excludes( $all );
	}

	/**
	 * Whether a path belongs to Warp itself (its plugin folder or a staging folder) and must never be touched.
	 *
	 * @param string $group Group.
	 * @param string $path  Relative path.
	 */
	public static function is_protected( string $group, string $path ): bool {
		$item = Paths::item( $path );

		return str_starts_with( $item, self::STAGING_PREFIX ) || ( self::PLUGINS === $group && self::own_folder() === $item );
	}

	/**
	 * Folder of this plugin in the plugins folder.
	 */
	public static function own_folder(): string {
		return dirname( plugin_basename( PLUGIN_FILE ) );
	}

	/**
	 * Staging folder of a run inside a group root.
	 *
	 * @param string $group  Group.
	 * @param string $run_id Run ID.
	 */
	public static function staging( string $group, string $run_id ): string {
		return self::root( $group ) . '/' . self::STAGING_PREFIX . substr( $run_id, 0, 16 );
	}

	/**
	 * Whether the file system of this site ignores letter case in names.
	 */
	public static function case_insensitive(): bool {
		return in_array( PHP_OS_FAMILY, array( 'Windows', 'Darwin' ), true );
	}
}
