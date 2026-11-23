<?php
/**
 * Tests for the progress of a migration.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Tests\Migration;

use PHPUnit\Framework\TestCase;
use WM\Warp\Migration\Progress;

/**
 * Percentage from tables and files.
 */
final class ProgressTest extends TestCase {

	/**
	 * Table entry of the runner state.
	 *
	 * @param int      $bytes Estimated size.
	 * @param int      $rows  Estimated rows.
	 * @param int      $low   Smallest key.
	 * @param int      $high  Largest key.
	 * @param int|null $after Last read key, null before the first chunk.
	 * @param int      $done  Transferred rows.
	 * @param bool     $finished Whether the table is done.
	 * @return array<string, mixed>
	 */
	private static function table( int $bytes, int $rows, int $low, int $high, ?int $after, int $done, bool $finished = false ): array {
		return array(
			'info'   => array(
				'bytes' => $bytes,
				'rows'  => $rows,
				'low'   => $low,
				'high'  => $high,
			),
			'cursor' => null === $after ? array() : array( 'after' => array( base64_encode( (string) $after ) ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Cursor format.
			'rows'   => $done,
			'done'   => $finished,
		);
	}

	/**
	 * Inside a table the key position counts, even when the estimates are far off.
	 */
	public function test_key_position(): void {
		// Estimated 1 000 rows, really far more: the key range still says 50 %.
		self::assertEqualsWithDelta( 0.5, Progress::table_fraction( self::table( 100, 1000, 1, 10000, 5000, 4800 ) ), 0.001 );
		self::assertSame( 0.99, Progress::table_fraction( self::table( 100, 10, 1, 100, 100, 100 ) ) );
		self::assertSame( 1.0, Progress::table_fraction( self::table( 100, 10, 1, 100, 100, 100, true ) ) );
	}

	/**
	 * Without an integer key the rows against the estimate count.
	 */
	public function test_rows_without_key(): void {
		self::assertEqualsWithDelta( 0.25, Progress::table_fraction( self::table( 100, 400, 0, 0, null, 100 ) ), 0.001 );
		self::assertSame( 0.0, Progress::table_fraction( self::table( 100, 0, 0, 0, null, 100 ) ) );
	}

	/**
	 * Tables weigh by size.
	 */
	public function test_tables(): void {
		$state = array(
			'database' => true,
			'phase'    => 'tables',
			'files'    => null,
			'tables'   => array(
				self::table( 300, 10, 1, 10, 10, 10, true ),
				self::table( 700, 100, 1, 100, 50, 50 ),
				self::table( 0, 0, 0, 0, null, 0 ),
			),
		);

		// (300 + 700 × 0,5 + 1 × 0) / 1001 = 64,9 %.
		self::assertSame( 64, Progress::percent( $state ) );

		$state['phase'] = 'finish';
		self::assertSame( 99, Progress::percent( $state ) );
	}

	/**
	 * Before the files are compared the database is half of the work, then bytes decide.
	 */
	public function test_tables_and_files(): void {
		$state = array(
			'database' => true,
			'phase'    => 'tables',
			'files'    => array(
				'step'  => '',
				'total' => 0,
				'sent'  => 0,
			),
			'tables'   => array( self::table( 1000, 10, 1, 10, 5, 5 ) ),
		);

		self::assertSame( 25, Progress::percent( $state ) );

		$state['phase'] = 'files';
		self::assertSame( 50, Progress::percent( $state ) );

		$state['files'] = array(
			'step'  => 'transfer',
			'total' => 3000,
			'sent'  => 1500,
		);
		// (1000 + 1500) / 4000.
		self::assertSame( 62, Progress::percent( $state ) );

		$state['files']['total'] = 0;
		self::assertSame( 99, Progress::percent( $state ) );
	}

	/**
	 * Files only.
	 */
	public function test_files_only(): void {
		$state = array(
			'database' => false,
			'phase'    => 'files',
			'tables'   => array(),
			'files'    => array(
				'step'  => 'transfer',
				'total' => 200,
				'sent'  => 50,
			),
		);

		self::assertSame( 25, Progress::percent( $state ) );
	}
}
