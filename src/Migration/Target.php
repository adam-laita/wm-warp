<?php
/**
 * The target side of a migration.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Migration;

use WM\Warp\Database\Importer;
use WM\Warp\Files\Stager;

/**
 * Confirm, Undo and Cancel of what a migration changed on this site: tables (Importer) and files
 * (Stager) together, so the two never get out of step.
 */
final class Target {

	/**
	 * Before a new run: a migration waiting for confirmation is confirmed, an unfinished one of another
	 * run is discarded.
	 *
	 * @param string $run_id ID of the new run.
	 */
	public static function settle( string $run_id ): void {
		$files = Stager::status();

		if ( null !== $files && $files['run_id'] !== $run_id ) {
			Stager::SWAPPED === $files['status'] ? Stager::confirm() : Stager::abort();
		}

		$import = Importer::pending();
		if ( null !== $import && $import['run_id'] !== $run_id ) {
			Importer::confirm();
		}
	}

	/**
	 * Confirms: the set-aside tables and files are deleted.
	 */
	public static function confirm(): void {
		Stager::confirm();
		Importer::confirm();
	}

	/**
	 * Undoes: the previous tables and files return.
	 *
	 * @throws \UnexpectedValueException When there is nothing to undo.
	 */
	public static function rollback(): void {
		$files  = Stager::pending();
		$tables = Importer::pending();

		if ( null === $files && null === $tables ) {
			throw new \UnexpectedValueException( 'There is no migration to undo.' );
		}

		if ( null !== $files ) {
			Stager::rollback();
		}

		if ( null !== $tables ) {
			Importer::rollback();
		}
	}

	/**
	 * Discards an unfinished run.
	 *
	 * @param string $run_id Run ID.
	 */
	public static function abort( string $run_id ): void {
		try {
			Stager::abort( $run_id );
		} finally {
			Importer::abort( $run_id );
		}
	}

	/**
	 * Migration into this site waiting for confirmation.
	 *
	 * @return array{run_id: string, source: string, finished: int}|null
	 */
	public static function pending(): ?array {
		$tables = Importer::pending();
		$files  = Stager::pending();

		if ( null !== $tables ) {
			return array(
				'run_id'   => $tables['run_id'],
				'source'   => $tables['source'],
				'finished' => $tables['finished'],
			);
		}

		return $files;
	}
}
