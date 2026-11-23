<?php
/**
 * Tests of the chunk size.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Tests\Migration;

use PHPUnit\Framework\TestCase;
use WM\Warp\Migration\Runner;

/**
 * Chunks grow until a request takes 3–8 s.
 */
final class AdaptTest extends TestCase {

	private const MB = 1024 * 1024;

	/**
	 * A site that needs 2 s to start WordPress still gets larger chunks.
	 */
	public function test_grows_below_three_seconds(): void {
		$this->assertSame( 2 * self::MB, Runner::adapt( self::MB, 2.4, 32 * self::MB ) );
	}

	/**
	 * Inside the target window the size stays.
	 */
	public function test_keeps_size_in_target_window(): void {
		$this->assertSame( 8 * self::MB, Runner::adapt( 8 * self::MB, 5.0, 32 * self::MB ) );
	}

	/**
	 * Slow chunks get smaller, never below the minimum.
	 */
	public function test_shrinks_when_slow(): void {
		$this->assertSame( 4 * self::MB, Runner::adapt( 8 * self::MB, 9.0, 32 * self::MB ) );
		$this->assertSame( 256 * 1024, Runner::adapt( 256 * 1024, 20.0, 32 * self::MB ) );
	}

	/**
	 * The limit of the other site wins.
	 */
	public function test_respects_maximum(): void {
		$this->assertSame( 4 * self::MB, Runner::adapt( 4 * self::MB, 0.5, 4 * self::MB ) );
	}
}
