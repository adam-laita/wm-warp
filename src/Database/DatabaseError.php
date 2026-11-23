<?php
/**
 * Database error.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Database;

/**
 * A database statement failed. The statement is kept shortened for the log, never shown in full.
 */
final class DatabaseError extends \RuntimeException {

	/**
	 * Beginning of the failed statement.
	 *
	 * @var string
	 */
	public readonly string $sql;

	/**
	 * Constructor.
	 *
	 * @param string $message Error of the database.
	 * @param string $sql     Failed statement.
	 */
	public function __construct( string $message, string $sql = '' ) {
		parent::__construct( $message );
		$this->sql = substr( $sql, 0, 300 );
	}
}
