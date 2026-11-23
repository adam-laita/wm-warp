<?php
/**
 * Tests for log entries.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Tests\Support;

use PHPUnit\Framework\TestCase;
use WM\Warp\Support\LogEntry;

/**
 * Secrets, size limits, repeated errors and pruning of the log.
 */
final class LogEntryTest extends TestCase {

	/**
	 * Secrets never reach the log, other values stay.
	 */
	public function test_redact_hides_secrets(): void {
		$entry = LogEntry::redact(
			array(
				'message' => 'Key warp1.eyJ1IjoiaHR0cHM6Ly9leGFtcGxlLmNvbSJ9abc is invalid',
				'details' => array(
					'auth_password' => 'hunter2',
					'secret'        => 'abc',
					'status'        => 401,
					'header'        => 'Authorization: Basic dXNlcjpwYXNzd29yZA==',
					'empty_token'   => '',
					'nested'        => array( 'connection_secret' => 'x' ),
				),
			)
		);

		self::assertIsArray( $entry );
		self::assertSame( 'Key warp1.[hidden] is invalid', $entry['message'] );
		self::assertSame( '[hidden]', $entry['details']['auth_password'] );
		self::assertSame( '[hidden]', $entry['details']['secret'] );
		self::assertSame( 401, $entry['details']['status'] );
		self::assertSame( 'Authorization: Basic [hidden]', $entry['details']['header'] );
		self::assertSame( '', $entry['details']['empty_token'] );
		self::assertSame( '[hidden]', $entry['details']['nested']['connection_secret'] );
	}

	/**
	 * Transferred data in an SQL statement never reach the log.
	 */
	public function test_statement_without_values(): void {
		$sql = "INSERT INTO `wmwt_wp_options` (`option_id`, `option_value`) VALUES ('1', 'api-key-123')";

		self::assertSame( 'INSERT INTO `wmwt_wp_options` (`option_id`, `option_value`) VALUES … (28 bytes left out)', LogEntry::statement( $sql ) );
		self::assertSame( 'RENAME TABLE `a` TO `b`', LogEntry::statement( 'RENAME TABLE `a` TO `b`' ) );
	}

	/**
	 * A large entry is cut to the limit, the message stays whole.
	 */
	public function test_limit_keeps_entry_small(): void {
		$entry = array(
			'message'     => 'Failed',
			'details'     => array( 'excerpt' => str_repeat( 'x', 5000 ) ),
			'remote_log'  => array( array( 'message' => str_repeat( 'y', 3000 ) ) ),
			'environment' => array( 'local' => array( 'plugins' => array_fill_keys( range( 1, 200 ), '1.0.0' ) ) ),
		);

		$limited = LogEntry::limit( $entry, 8192 );

		self::assertLessThanOrEqual( 8192, strlen( LogEntry::encode( $limited ) ) );
		self::assertSame( 'Failed', $limited['message'] );
		self::assertTrue( $limited['truncated'] );

		$small = array( 'message' => 'ok' );
		self::assertSame( $small, LogEntry::limit( $small, 8192 ) );
	}

	/**
	 * The same error of the same run repeats; another table or run does not.
	 */
	public function test_repeats(): void {
		$a = array(
			'level'   => 'warning',
			'action'  => 'pull',
			'run'     => 'r1',
			'phase'   => 'tables',
			'table'   => 'wp_posts',
			'reason'  => 'timeout',
			'message' => 'Timeout',
			'time'    => '2026-11-23T10:00:00Z',
		);

		self::assertTrue( LogEntry::repeats( $a, array( 'time' => '2026-11-23T10:00:05Z' ) + $a ) );
		self::assertFalse( LogEntry::repeats( $a, array( 'table' => 'wp_options' ) + $a ) );
		self::assertFalse( LogEntry::repeats( $a, array( 'run' => 'r2' ) + $a ) );
	}

	/**
	 * Old entries go away and a large log loses its older half.
	 */
	public function test_prune(): void {
		$now   = (int) strtotime( '2026-11-23T12:00:00Z' );
		$lines = array(
			'{"time":"2026-07-01T00:00:00Z","message":"old"}',
			'{"time":"2026-08-01T00:00:00Z","last":"2026-11-20T00:00:00Z","message":"repeated recently"}',
			'not json',
			'{"time":"2026-11-23T11:00:00Z","message":"new"}',
		);

		$kept = LogEntry::prune( $lines, $now, 90 * 86400, 1048576 );
		self::assertSame( array( $lines[1], $lines[3] ), $kept );

		$many = array();
		for ( $i = 0; $i < 100; $i++ ) {
			$many[] = '{"time":"2026-11-23T11:00:00Z","n":' . $i . ',"pad":"' . str_repeat( 'p', 90 ) . '"}';
		}

		$kept = LogEntry::prune( $many, $now, 90 * 86400, 5000 );
		self::assertLessThanOrEqual( 5000, array_sum( array_map( 'strlen', $kept ) ) + count( $kept ) );
		self::assertSame( end( $many ), end( $kept ) );
	}
}
