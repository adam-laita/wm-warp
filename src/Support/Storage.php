<?php
/**
 * Plugin working folder.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Support;

/**
 * Folder for files Warp keeps outside the database: wp-content/wm-warp.
 *
 * Files there survive replacing the database. Web access is denied for Apache and IIS and every
 * folder gets an empty index.php; on nginx the files are reachable only by exact name, so nothing
 * secret is ever stored under a guessable name.
 */
final class Storage {

	/**
	 * Absolute path of a subfolder, created on demand.
	 *
	 * @param string $name Subfolder name, e.g. "nonces".
	 * @return string|null Path with a trailing slash, or null when it cannot be created.
	 */
	public static function dir( string $name ): ?string {
		$base = trailingslashit( WP_CONTENT_DIR ) . 'wm-warp/';
		$dir  = $base . trim( $name, '/' ) . '/';

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return null;
		}

		self::protect( $base );
		self::protect( $dir );

		return $dir;
	}

	/**
	 * Adds files that deny web access to a folder.
	 *
	 * @param string $dir Folder with a trailing slash.
	 */
	private static function protect( string $dir ): void {
		$files = array(
			'index.php'  => "<?php\n// Silence is golden.\n",
			'.htaccess'  => "Require all denied\n",
			'web.config' => "<?xml version=\"1.0\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
		);

		foreach ( $files as $file => $content ) {
			if ( ! file_exists( $dir . $file ) ) {
				file_put_contents( $dir . $file, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Small local files, WP_Filesystem may require FTP credentials.
			}
		}
	}
}
