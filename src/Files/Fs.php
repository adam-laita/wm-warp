<?php
/**
 * Direct access to the local file system.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Files;

// WP_Filesystem may ask for FTP credentials and cannot write at an offset or rename atomically, which
// chunked transfers and the folder swap need. All direct file system calls of Warp's file transfer are
// therefore kept in this class.
// phpcs:disable WordPress.WP.AlternativeFunctions

/**
 * Small wrappers around PHP file functions that throw on failure.
 */
final class Fs {

	/**
	 * Whether a path is a symbolic link or a Windows junction (is_link() does not see junctions).
	 *
	 * @param string $path Absolute path.
	 */
	public static function is_link( string $path ): bool {
		if ( is_link( $path ) ) {
			return true;
		}

		if ( ! file_exists( $path ) ) {
			return false;
		}

		$real   = realpath( $path );
		$parent = realpath( dirname( $path ) );

		if ( false === $real || false === $parent ) {
			return true;
		}

		$expected = self::slashes( $parent ) . '/' . basename( $path );
		$real     = self::slashes( $real );

		return 'Windows' === PHP_OS_FAMILY || 'Darwin' === PHP_OS_FAMILY ? 0 !== strcasecmp( $real, $expected ) : $real !== $expected;
	}

	/**
	 * Whether a path, after resolving links, lies inside a root.
	 *
	 * @param string $root Root.
	 * @param string $path Absolute path.
	 */
	public static function inside( string $root, string $path ): bool {
		$real_root = realpath( $root );
		$real      = realpath( $path );

		if ( false === $real_root || false === $real ) {
			return false;
		}

		$real_root = self::slashes( $real_root ) . '/';
		$real      = self::slashes( $real );

		return 'Windows' === PHP_OS_FAMILY || 'Darwin' === PHP_OS_FAMILY ? 0 === stripos( $real, $real_root ) : str_starts_with( $real, $real_root );
	}

	/**
	 * Names in a folder, sorted.
	 *
	 * @param string $dir Absolute path.
	 * @return list<string>
	 * @throws \RuntimeException When the folder cannot be read.
	 */
	public static function names( string $dir ): array {
		$names = scandir( $dir, SCANDIR_SORT_ASCENDING );

		if ( false === $names ) {
			throw new \RuntimeException( sprintf( 'The folder %s cannot be read.', $dir ) );
		}

		return array_values( array_filter( $names, static fn( string $name ): bool => '.' !== $name && '..' !== $name ) );
	}

	/**
	 * Size and modification time of a file.
	 *
	 * @param string $path Absolute path.
	 * @return array{0: int, 1: int}|null Null when it is not a readable regular file.
	 */
	public static function stat( string $path ): ?array {
		clearstatcache( true, $path );

		if ( ! is_file( $path ) ) {
			return null;
		}

		$size  = filesize( $path );
		$mtime = filemtime( $path );

		return false === $size || false === $mtime ? null : array( $size, $mtime );
	}

	/**
	 * Reads part of a file.
	 *
	 * @param string $path   Absolute path.
	 * @param int    $offset Start.
	 * @param int    $length Number of bytes.
	 * @throws \RuntimeException When the file cannot be read.
	 */
	public static function read( string $path, int $offset, int $length ): string {
		$handle = fopen( $path, 'rb' );

		if ( false === $handle ) {
			throw new \RuntimeException( sprintf( 'The file %s cannot be read.', $path ) );
		}

		try {
			if ( 0 !== fseek( $handle, $offset ) ) {
				throw new \RuntimeException( sprintf( 'The file %s cannot be read.', $path ) );
			}

			$data = '';
			$read = 0;
			while ( $read < $length && ! feof( $handle ) ) {
				$chunk = fread( $handle, max( 1, min( 8 * 1024 * 1024, $length - $read ) ) );

				if ( false === $chunk ) {
					throw new \RuntimeException( sprintf( 'The file %s cannot be read.', $path ) );
				}

				$data .= $chunk;
				$read += strlen( $chunk );
			}

			return $data;
		} finally {
			fclose( $handle );
		}
	}

	/**
	 * Writes data at an offset; offset 0 starts the file anew.
	 *
	 * @param string $path   Absolute path.
	 * @param int    $offset Start.
	 * @param string $data   Bytes.
	 * @throws \RuntimeException When the file cannot be written.
	 */
	public static function write( string $path, int $offset, string $data ): void {
		if ( $offset < 0 ) {
			throw new \RuntimeException( sprintf( 'The file %s cannot be written at a negative offset.', $path ) );
		}

		self::mkdir( dirname( $path ) );

		$handle = fopen( $path, 'c+b' );

		if ( false === $handle ) {
			throw new \RuntimeException( sprintf( 'The file %s cannot be written.', $path ) );
		}

		try {
			if ( ( 0 === $offset && ! ftruncate( $handle, 0 ) ) || 0 !== fseek( $handle, $offset ) ) {
				throw new \RuntimeException( sprintf( 'The file %s cannot be written.', $path ) );
			}

			$written = 0;
			$length  = strlen( $data );

			while ( $written < $length ) {
				$bytes = fwrite( $handle, substr( $data, $written, 8 * 1024 * 1024 ) );

				if ( false === $bytes || 0 === $bytes ) {
					throw new \RuntimeException( sprintf( 'The file %s cannot be written. The disk may be full.', $path ) );
				}

				$written += $bytes;
			}

			// A repeated chunk after a timeout must not leave bytes of a longer previous attempt behind.
			if ( ! ftruncate( $handle, $offset + $length ) ) {
				throw new \RuntimeException( sprintf( 'The file %s cannot be written.', $path ) );
			}
		} finally {
			fclose( $handle );
		}
	}

	/**
	 * Creates a folder with its parents.
	 *
	 * @param string $dir Absolute path.
	 * @throws \RuntimeException When it cannot be created.
	 */
	public static function mkdir( string $dir ): void {
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			throw new \RuntimeException( sprintf( 'The folder %s cannot be created.', $dir ) );
		}
	}

	/**
	 * Moves a file or folder (same file system).
	 *
	 * @param string $from Absolute path.
	 * @param string $to   Absolute path.
	 * @throws \RuntimeException When it cannot be moved.
	 */
	public static function move( string $from, string $to ): void {
		self::mkdir( dirname( $to ) );

		if ( ! rename( $from, $to ) ) {
			throw new \RuntimeException( sprintf( '%1$s cannot be moved to %2$s.', $from, $to ) );
		}
	}

	/**
	 * Copies a file and keeps its modification time.
	 *
	 * @param string $from  Absolute path.
	 * @param string $to    Absolute path.
	 * @param int    $mtime Modification time to set.
	 * @throws \RuntimeException When it cannot be copied.
	 */
	public static function copy( string $from, string $to, int $mtime ): void {
		self::mkdir( dirname( $to ) );

		if ( ! copy( $from, $to ) ) {
			throw new \RuntimeException( sprintf( '%1$s cannot be copied to %2$s.', $from, $to ) );
		}

		self::touch( $to, $mtime );
	}

	/**
	 * Deletes a file (or a link to a folder).
	 *
	 * @param string $path Absolute path.
	 * @throws \RuntimeException When it cannot be deleted.
	 */
	public static function delete( string $path ): void {
		if ( ! is_link( $path ) && ! file_exists( $path ) ) {
			return;
		}

		$deleted = is_dir( $path ) && 'Windows' === PHP_OS_FAMILY ? rmdir( $path ) : unlink( $path );

		if ( ! $deleted ) {
			throw new \RuntimeException( sprintf( 'The file %s cannot be deleted.', $path ) );
		}
	}

	/**
	 * Deletes a folder with everything in it; links inside are removed, never followed.
	 *
	 * @param string $dir Absolute path.
	 * @throws \RuntimeException When something cannot be deleted.
	 */
	public static function delete_tree( string $dir ): void {
		if ( self::is_link( $dir ) || is_file( $dir ) ) {
			self::delete( $dir );

			return;
		}

		if ( ! is_dir( $dir ) ) {
			return;
		}

		foreach ( self::names( $dir ) as $name ) {
			self::delete_tree( $dir . '/' . $name );
		}

		if ( ! rmdir( $dir ) ) {
			throw new \RuntimeException( sprintf( 'The folder %s cannot be deleted.', $dir ) );
		}
	}

	/**
	 * Deletes a folder when it is empty.
	 *
	 * @param string $dir Absolute path.
	 */
	public static function delete_if_empty( string $dir ): void {
		if ( is_dir( $dir ) && ! self::is_link( $dir ) && array() === self::names( $dir ) ) {
			rmdir( $dir );
		}
	}

	/**
	 * Sets the modification time.
	 *
	 * @param string $path  Absolute path.
	 * @param int    $mtime Unix time.
	 */
	public static function touch( string $path, int $mtime ): void {
		touch( $path, $mtime, $mtime );
	}

	/**
	 * Permissions of a file.
	 *
	 * @param string $path Absolute path.
	 */
	public static function perms( string $path ): int {
		$perms = fileperms( $path );

		return false === $perms ? 0 : $perms & 0777;
	}

	/**
	 * Sets permissions (no effect on Windows).
	 *
	 * @param string $path Absolute path.
	 * @param int    $mode Mode.
	 */
	public static function chmod( string $path, int $mode ): void {
		if ( $mode > 0 && 'Windows' !== PHP_OS_FAMILY ) {
			chmod( $path, $mode );
		}
	}

	/**
	 * Hash of a file (xxh128: fast, enough to tell files apart).
	 *
	 * @param string $path Absolute path.
	 * @return string|null Null when the file cannot be read.
	 */
	public static function hash( string $path ): ?string {
		if ( ! is_file( $path ) ) {
			return null;
		}

		$hash = hash_file( 'xxh128', $path );

		return false === $hash ? null : $hash;
	}

	/**
	 * Free space on the disk of a folder.
	 *
	 * @param string $dir Absolute path.
	 * @return int Bytes, -1 when unknown.
	 */
	public static function free( string $dir ): int {
		$free = function_exists( 'disk_free_space' ) ? disk_free_space( $dir ) : false;

		return false === $free ? -1 : (int) $free;
	}

	/**
	 * Path with forward slashes and without a trailing slash.
	 *
	 * @param string $path Path.
	 */
	public static function slashes( string $path ): string {
		return rtrim( str_replace( '\\', '/', $path ), '/' );
	}

	/**
	 * Writes a small file (protection files of staging folders).
	 *
	 * @param string $path    Absolute path.
	 * @param string $content Content.
	 */
	public static function put( string $path, string $content ): void {
		if ( ! file_exists( $path ) ) {
			file_put_contents( $path, $content );
		}
	}
}
// phpcs:enable
