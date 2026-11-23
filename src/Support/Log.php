<?php
/**
 * Error log.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Support;

/**
 * Errors and warnings in wp-content/wm-warp/logs as JSON Lines (one entry per line), outside the
 * database, so the log survives a migration. Meant for finding the cause of a problem, also with
 * an AI agent: every entry carries what happened, where and why, without secrets.
 *
 * Limits keep the log small: only errors, warnings and one line per finished run; a repeated
 * error only raises "count"; an entry has at most 8 KB, the log at most 1 MB and 90 days.
 * With the constant WM_WARP_DEBUG every request goes to a separate debug log (at most 5 MB,
 * deleted 24 hours after the last write).
 */
final class Log {

	public const ERROR   = 'error';
	public const WARNING = 'warning';
	public const RESULT  = 'result';

	private const DIR         = 'logs';
	private const MAX_ENTRY   = 8192;
	private const MAX_BYTES   = 1048576;
	private const MAX_AGE     = 90 * DAY_IN_SECONDS;
	private const MAX_EXCERPT = 2048;
	private const DEBUG_BYTES = 5242880;
	private const DEBUG_AGE   = DAY_IN_SECONDS;

	/**
	 * Records an error.
	 *
	 * @param string               $action  What was being done: "connect", "pull", "push", "remote" etc.
	 * @param string               $message Human readable message (as shown to the user).
	 * @param array<string, mixed> $context Run, peer, phase, table, reason, details, environment etc.
	 */
	public static function error( string $action, string $message, array $context = array() ): void {
		self::write( self::ERROR, $action, $message, $context );
	}

	/**
	 * Records a warning.
	 *
	 * @param string               $action  What was being done.
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Context.
	 */
	public static function warning( string $action, string $message, array $context = array() ): void {
		self::write( self::WARNING, $action, $message, $context );
	}

	/**
	 * Records the result of a finished run.
	 *
	 * @param string               $action  "pull" or "push".
	 * @param string               $message Short result, e.g. "done".
	 * @param array<string, mixed> $context Context.
	 */
	public static function result( string $action, string $message, array $context = array() ): void {
		self::write( self::RESULT, $action, $message, $context );
	}

	/**
	 * Entries of the log, from the oldest.
	 *
	 * @param string $run  Only entries of this run, empty for all.
	 * @param int    $last At most this many of the newest entries, 0 for all.
	 * @return list<array<string, mixed>>
	 */
	public static function entries( string $run = '', int $last = 0 ): array {
		$entries = array();

		foreach ( self::lines( self::path() ) as $line ) {
			$entry = json_decode( $line, true );

			if ( is_array( $entry ) && ( '' === $run || ( $entry['run'] ?? '' ) === $run ) ) {
				$entries[] = $entry;
			}
		}

		return $last > 0 ? array_slice( $entries, -$last ) : $entries;
	}

	/**
	 * Path of the log file.
	 */
	public static function path(): ?string {
		return Storage::file( self::DIR, 'log', 'log-', '.jsonl' );
	}

	/**
	 * Records one request in the debug log, only when WM_WARP_DEBUG is on.
	 *
	 * @param array<string, mixed> $data What happened.
	 */
	public static function debug( array $data ): void {
		if ( ! defined( 'WM_WARP_DEBUG' ) || ! constant( 'WM_WARP_DEBUG' ) ) {
			return;
		}

		try {
			$file = Storage::file( self::DIR, 'debug', 'debug-', '.jsonl' );

			if ( null === $file ) {
				return;
			}

			$line  = LogEntry::encode( LogEntry::limit( (array) LogEntry::redact( array( 'time' => gmdate( 'Y-m-d\TH:i:s\Z' ) ) + $data ), self::MAX_ENTRY ) ) . "\n";
			$flags = file_exists( $file ) && filesize( $file ) + strlen( $line ) <= self::DEBUG_BYTES ? FILE_APPEND | LOCK_EX : LOCK_EX;

			file_put_contents( $file, $line, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local log file.
		} catch ( \Throwable $error ) {
			unset( $error );
		}
	}

	/**
	 * Deletes the debug log 24 hours after its last write and prunes old entries (maintenance).
	 */
	public static function cleanup(): void {
		$debug = Storage::file( self::DIR, 'debug', 'debug-', '.jsonl' );

		if ( null !== $debug && file_exists( $debug ) && time() - (int) filemtime( $debug ) > self::DEBUG_AGE ) {
			wp_delete_file( $debug );
		}

		$file = self::path();

		if ( null !== $file && file_exists( $file ) ) {
			$lines = self::lines( $file );
			$kept  = LogEntry::prune( $lines, time(), self::MAX_AGE, self::MAX_BYTES );

			if ( count( $kept ) !== count( $lines ) ) {
				self::store( $file, $kept );
			}
		}
	}

	/**
	 * Excerpt of a server response for the log.
	 *
	 * @param string $text Response body.
	 */
	public static function excerpt( string $text ): string {
		$text = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $text ) ) );

		return strlen( $text ) > self::MAX_EXCERPT ? mb_strcut( $text, 0, self::MAX_EXCERPT ) . '…' : $text;
	}

	/**
	 * Writes an entry; logging never interrupts the work.
	 *
	 * @param string               $level   Level.
	 * @param string               $action  Action.
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Context.
	 */
	private static function write( string $level, string $action, string $message, array $context ): void {
		try {
			$file = self::path();

			if ( null === $file ) {
				return;
			}

			$entry = array(
				'time'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'level'   => $level,
				'site'    => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
				'action'  => $action,
				'message' => $message,
			) + array_filter( $context, static fn( $value ): bool => null !== $value && '' !== $value && array() !== $value );

			$entry = LogEntry::limit( (array) LogEntry::redact( $entry ), self::MAX_ENTRY );
			$lines = self::lines( $file );
			$last  = array() === $lines ? null : json_decode( (string) end( $lines ), true );

			if ( is_array( $last ) && LogEntry::repeats( $last, $entry ) ) {
				$last['count']                = (int) ( $last['count'] ?? 1 ) + 1;
				$last['last']                 = $entry['time'];
				$lines[ count( $lines ) - 1 ] = LogEntry::encode( $last );
			} else {
				$lines[] = LogEntry::encode( $entry );
			}

			self::store( $file, LogEntry::prune( $lines, time(), self::MAX_AGE, self::MAX_BYTES ) );
		} catch ( \Throwable $error ) {
			unset( $error );
		}
	}

	/**
	 * Lines of a log file.
	 *
	 * @param string|null $file File.
	 * @return list<string>
	 */
	private static function lines( ?string $file ): array {
		if ( null === $file || ! is_readable( $file ) ) {
			return array();
		}

		$lines = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );

		return false === $lines ? array() : $lines;
	}

	/**
	 * Replaces the log file.
	 *
	 * @param string   $file  File.
	 * @param string[] $lines Lines.
	 * @phpstan-param list<string> $lines
	 */
	private static function store( string $file, array $lines ): void {
		$temp = $file . '.' . bin2hex( random_bytes( 4 ) ) . '.tmp';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.rename_rename -- Atomic replace of a local log file.
		if ( false === file_put_contents( $temp, array() === $lines ? '' : implode( "\n", $lines ) . "\n" ) || ! rename( $temp, $file ) ) {
			wp_delete_file( $temp );
		}
	}
}
