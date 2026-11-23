<?php
/**
 * Binary format of table rows.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Database;

/**
 * Encodes rows as compact binary frames: for every value a 4-byte big-endian length and the bytes,
 * the length 0xFFFFFFFF means NULL. Values travel exactly as the database returned them
 * (no number or date conversion, binary data without base64).
 * Pure logic without WordPress calls.
 */
final class RowCodec {

	private const NULL_LENGTH = 0xFFFFFFFF;

	/**
	 * Encodes rows.
	 *
	 * @param list<list<string|null>> $rows Rows; every row has the same number of values.
	 */
	public static function encode( array $rows ): string {
		$parts = array();

		foreach ( $rows as $row ) {
			foreach ( $row as $value ) {
				$parts[] = null === $value ? pack( 'N', self::NULL_LENGTH ) : pack( 'N', strlen( $value ) ) . $value;
			}
		}

		return implode( '', $parts );
	}

	/**
	 * Decodes rows.
	 *
	 * @param string $bytes   Encoded rows.
	 * @param int    $columns Number of values in a row.
	 * @return list<list<string|null>>
	 * @throws \UnexpectedValueException When the data are truncated or do not match the column count.
	 */
	public static function decode( string $bytes, int $columns ): array {
		if ( $columns < 1 ) {
			throw new \UnexpectedValueException( 'Invalid column count.' );
		}

		$rows   = array();
		$row    = array();
		$offset = 0;
		$size   = strlen( $bytes );

		while ( $offset < $size ) {
			if ( $offset + 4 > $size ) {
				throw new \UnexpectedValueException( 'Truncated row data.' );
			}

			$length  = unpack( 'N', $bytes, $offset );
			$length  = is_array( $length ) ? (int) $length[1] : 0;
			$offset += 4;

			if ( self::NULL_LENGTH === $length ) {
				$row[] = null;
			} else {
				if ( $offset + $length > $size ) {
					throw new \UnexpectedValueException( 'Truncated row data.' );
				}

				$row[]   = substr( $bytes, $offset, $length );
				$offset += $length;
			}

			if ( count( $row ) === $columns ) {
				$rows[] = $row;
				$row    = array();
			}
		}

		if ( array() !== $row ) {
			throw new \UnexpectedValueException( 'Row data do not match the column count.' );
		}

		return $rows;
	}
}
