<?php
/**
 * Replay protection.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Api;

use WM\Warp\Protocol;
use WM\Warp\Support\Storage;

/**
 * Remembers request nonces for the time window, so a captured request cannot be sent again.
 *
 * Nonces are kept in files, which survive replacing the database during a migration.
 */
final class NonceStore {

	/**
	 * Records a nonce.
	 *
	 * @param string $connection Connection ID or "pair".
	 * @param string $nonce      Nonce from the request.
	 * @return bool False when the nonce was already used or cannot be recorded.
	 */
	public function remember( string $connection, string $nonce ): bool {
		$dir = Storage::dir( 'nonces' );

		if ( null === $dir || 1 !== preg_match( '/^[A-Za-z0-9_-]{16,64}$/', $nonce ) ) {
			return false;
		}

		$this->maybe_cleanup( $dir );

		$file   = $dir . hash( 'sha256', $connection . '|' . $nonce );
		$handle = @fopen( $file, 'x' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Atomic create, fails when the file exists.

		if ( false === $handle ) {
			return false;
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return true;
	}

	/**
	 * Occasionally removes nonces older than twice the time window.
	 *
	 * @param string $dir Folder.
	 */
	private function maybe_cleanup( string $dir ): void {
		if ( 1 !== wp_rand( 1, 50 ) ) {
			return;
		}

		$limit = time() - 2 * Protocol::TIME_WINDOW;
		$files = glob( $dir . '*' );

		foreach ( false === $files ? array() : $files as $file ) {
			if ( 1 === preg_match( '/[a-f0-9]{64}$/', $file ) && filemtime( $file ) < $limit ) {
				wp_delete_file( $file );
			}
		}
	}
}
