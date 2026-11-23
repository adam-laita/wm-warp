<?php
/**
 * Tests for the binary row format.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Tests\Database;

use PHPUnit\Framework\TestCase;
use WM\Warp\Database\RowCodec;

/**
 * Rows survive encoding byte for byte, NULL stays NULL.
 */
final class RowCodecTest extends TestCase {

	/**
	 * Every kind of value comes back exactly.
	 */
	public function test_round_trip(): void {
		$rows = array(
			array( '1', null, '', "a\0b", 'Žluťoučký kůň 🐎' ),
			array( '18446744073709551615', '0.1000000000', '0000-00-00 00:00:00', random_bytes( 300 ), str_repeat( 'x', 70000 ) ),
			array( null, null, null, null, null ),
		);

		self::assertSame( $rows, RowCodec::decode( RowCodec::encode( $rows ), 5 ) );
	}

	/**
	 * No rows encode to nothing.
	 */
	public function test_empty(): void {
		self::assertSame( '', RowCodec::encode( array() ) );
		self::assertSame( array(), RowCodec::decode( '', 3 ) );
	}

	/**
	 * Truncated data are refused.
	 */
	public function test_truncated(): void {
		$bytes = RowCodec::encode( array( array( 'abc', 'def' ) ) );

		$this->expectException( \UnexpectedValueException::class );
		RowCodec::decode( substr( $bytes, 0, -1 ), 2 );
	}

	/**
	 * Data with a different column count are refused.
	 */
	public function test_column_mismatch(): void {
		$bytes = RowCodec::encode( array( array( 'a', 'b', 'c' ) ) );

		$this->expectException( \UnexpectedValueException::class );
		RowCodec::decode( $bytes, 2 );
	}
}
