<?php
/**
 * Rules for log entries.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Support;

/**
 * Pure helpers of the log: hiding secrets, size limits, merging repeated errors and pruning.
 * No WordPress functions, so they are covered by unit tests.
 */
final class LogEntry {

	public const HIDDEN = '[hidden]';

	/**
	 * Keys whose values never go to the log.
	 */
	private const SECRET_KEY = '/(secret|password|passwd|pwd|token|cookie|authorization|signature|nonce|salt|private|public_key|pairing)/i';

	/**
	 * Strings that look like a Warp key or an HTTP authorization header.
	 *
	 * @var array<string, string>
	 */
	private const SECRET_TEXT = array(
		'/warp1\.[A-Za-z0-9_-]{16,}/'                 => 'warp1.' . self::HIDDEN,
		'/\b(Basic|Bearer)\s+[A-Za-z0-9+\/=._-]{8,}/' => '$1 ' . self::HIDDEN,
	);

	/**
	 * Copy of a value without secrets.
	 *
	 * @param mixed  $value Value.
	 * @param string $key   Key of the value in its array.
	 * @return mixed
	 */
	public static function redact( mixed $value, string $key = '' ): mixed {
		if ( '' !== $key && 1 === preg_match( self::SECRET_KEY, $key ) && null !== $value && '' !== $value ) {
			return self::HIDDEN;
		}

		if ( is_array( $value ) ) {
			$result = array();
			foreach ( $value as $child_key => $child ) {
				$result[ $child_key ] = self::redact( $child, (string) $child_key );
			}

			return $result;
		}

		if ( is_string( $value ) ) {
			return (string) preg_replace( array_keys( self::SECRET_TEXT ), array_values( self::SECRET_TEXT ), $value );
		}

		return is_scalar( $value ) || null === $value ? $value : get_debug_type( $value );
	}

	/**
	 * SQL statement for the log without the transferred data: the values of INSERT are left out.
	 *
	 * @param string $sql Statement.
	 */
	public static function statement( string $sql ): string {
		$position = stripos( $sql, ' VALUES ' );

		if ( false !== $position ) {
			return substr( $sql, 0, $position ) . ' VALUES … (' . ( strlen( $sql ) - $position ) . ' bytes left out)';
		}

		return strlen( $sql ) > 500 ? substr( $sql, 0, 500 ) . '…' : $sql;
	}

	/**
	 * Entry no longer than the limit once encoded as JSON.
	 *
	 * Shortens long texts first, then drops the log of the other site, the plugin lists and
	 * finally the details; the entry keeps "truncated" so the reader knows.
	 *
	 * @param array<string, mixed> $entry Entry.
	 * @param int                  $max   Limit in bytes.
	 * @return array<string, mixed>
	 */
	public static function limit( array $entry, int $max ): array {
		if ( self::size( $entry ) <= $max ) {
			return $entry;
		}

		$entry['truncated'] = true;
		$steps              = array(
			static fn( array $current ): array => self::shorten( $current, 1000 ),
			static fn( array $current ): array => self::shorten( $current, 300 ),
			static function ( array $current ): array {
				unset( $current['remote_log'] );
				return $current;
			},
			static function ( array $current ): array {
				foreach ( array( 'local', 'remote' ) as $side ) {
					if ( isset( $current['environment'][ $side ]['plugins'] ) && is_array( $current['environment'][ $side ]['plugins'] ) ) {
						$current['environment'][ $side ]['plugins'] = count( $current['environment'][ $side ]['plugins'] );
					}
				}
				return $current;
			},
			static function ( array $current ): array {
				unset( $current['environment'] );
				return $current;
			},
			static function ( array $current ): array {
				unset( $current['details'] );
				return $current;
			},
			static fn( array $current ): array => self::shorten( $current, 100 ),
		);

		foreach ( $steps as $step ) {
			$entry = $step( $entry );

			if ( self::size( $entry ) <= $max ) {
				break;
			}
		}

		return $entry;
	}

	/**
	 * Whether a new entry repeats the previous one (same error of the same run).
	 *
	 * @param array<string, mixed> $previous Previous entry.
	 * @param array<string, mixed> $entry    New entry.
	 */
	public static function repeats( array $previous, array $entry ): bool {
		foreach ( array( 'level', 'site', 'action', 'run', 'phase', 'table', 'reason', 'message' ) as $key ) {
			if ( ( $previous[ $key ] ?? null ) !== ( $entry[ $key ] ?? null ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Lines of the log without entries older than the age limit, within the size limit.
	 * When the log is too large, the older half is dropped (repeatedly, if needed).
	 *
	 * @param string[] $lines     Lines (JSON entries) from the oldest.
	 * @phpstan-param list<string> $lines
	 * @param int      $now       Current Unix time.
	 * @param int      $max_age   Age limit in seconds.
	 * @param int      $max_bytes Size limit in bytes.
	 * @return list<string>
	 */
	public static function prune( array $lines, int $now, int $max_age, int $max_bytes ): array {
		$kept = array();

		foreach ( $lines as $line ) {
			$entry = json_decode( $line, true );
			$time  = is_array( $entry ) && is_string( $entry['last'] ?? $entry['time'] ?? null ) ? strtotime( (string) ( $entry['last'] ?? $entry['time'] ) ) : false;

			if ( false !== $time && $now - $time <= $max_age ) {
				$kept[] = $line;
			}
		}

		$count = count( $kept );
		while ( $count > 1 && self::lines_size( $kept ) > $max_bytes ) {
			$kept  = array_slice( $kept, intdiv( $count, 2 ) );
			$count = count( $kept );
		}

		return $kept;
	}

	/**
	 * Encoded entry.
	 *
	 * @param array<string, mixed> $entry Entry.
	 */
	public static function encode( array $entry ): string {
		return (string) json_encode( $entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure helper without WordPress.
	}

	/**
	 * Size of an encoded entry.
	 *
	 * @param array<string, mixed> $entry Entry.
	 */
	private static function size( array $entry ): int {
		return strlen( self::encode( $entry ) );
	}

	/**
	 * Size of lines including line breaks.
	 *
	 * @param string[] $lines Lines.
	 * @phpstan-param list<string> $lines
	 */
	private static function lines_size( array $lines ): int {
		return (int) array_sum( array_map( 'strlen', $lines ) ) + count( $lines );
	}

	/**
	 * Copy with every text longer than the limit cut (the message is kept whole).
	 *
	 * @param array<mixed> $value Value.
	 * @param int          $max   Maximum length in characters.
	 * @return array<mixed>
	 */
	private static function shorten( array $value, int $max ): array {
		foreach ( $value as $key => $child ) {
			if ( 'message' === $key ) {
				continue;
			}

			if ( is_array( $child ) ) {
				$value[ $key ] = self::shorten( $child, $max );
			} elseif ( is_string( $child ) && mb_strlen( $child ) > $max ) {
				$value[ $key ] = mb_substr( $child, 0, $max ) . '…';
			}
		}

		return $value;
	}
}
