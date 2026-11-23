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
	 * Reads a JSON state file.
	 *
	 * @param string $dir  Subfolder.
	 * @param string $name Logical name; the file name is derived from it and the salts, so it cannot be guessed.
	 * @return array<string, mixed>|null
	 */
	public static function read_json( string $dir, string $name ): ?array {
		$file = self::state_file( $dir, $name );

		if ( null === $file || ! is_readable( $file ) ) {
			return null;
		}

		$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.

		return is_array( $data ) ? self::string_keys( $data ) : null;
	}

	/**
	 * Writes a JSON state file atomically.
	 *
	 * @param string               $dir  Subfolder.
	 * @param string               $name Logical name.
	 * @param array<string, mixed> $data Data.
	 * @throws \RuntimeException When the file cannot be written.
	 */
	public static function write_json( string $dir, string $name, array $data ): void {
		$file = self::state_file( $dir, $name );
		$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );

		if ( null === $file || false === $json ) {
			throw new \RuntimeException( 'The folder wp-content/wm-warp is not writable.' );
		}

		$temp = $file . '.' . bin2hex( random_bytes( 4 ) ) . '.tmp';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.rename_rename -- Atomic replace of a local state file.
		if ( false === file_put_contents( $temp, $json ) || ! rename( $temp, $file ) ) {
			wp_delete_file( $temp );
			throw new \RuntimeException( 'The folder wp-content/wm-warp is not writable.' );
		}
	}

	/**
	 * Deletes a JSON state file.
	 *
	 * @param string $dir  Subfolder.
	 * @param string $name Logical name.
	 */
	public static function delete_json( string $dir, string $name ): void {
		$file = self::state_file( $dir, $name );

		if ( null !== $file && file_exists( $file ) ) {
			wp_delete_file( $file );
		}
	}

	/**
	 * Path of a JSON state file.
	 *
	 * @param string $dir  Subfolder.
	 * @param string $name Logical name.
	 */
	private static function state_file( string $dir, string $name ): ?string {
		return self::file( $dir, $name, '', '.json' );
	}

	/**
	 * Path of a file whose name cannot be guessed from outside (nginx serves files by exact name).
	 *
	 * @param string $dir       Subfolder.
	 * @param string $name      Logical name.
	 * @param string $prefix    Readable start of the file name, e.g. "log-".
	 * @param string $extension Extension with the dot.
	 */
	public static function file( string $dir, string $name, string $prefix, string $extension ): ?string {
		$path = self::dir( $dir );

		return null === $path ? null : $path . $prefix . substr( hash_hmac( 'sha256', $dir . '/' . $name, wp_salt( 'auth' ) ), 0, 32 ) . $extension;
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
