<?php
/**
 * Tests of choosing plugins and themes.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Tests\Migration;

use PHPUnit\Framework\TestCase;
use WM\Warp\Migration\Runner;

/**
 * Modes of the Themes and Plugins sections.
 */
final class ChooseItemsTest extends TestCase {

	/**
	 * Items of a source.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function items(): array {
		return array(
			array(
				'item'   => 'akismet',
				'active' => true,
				'link'   => false,
			),
			array(
				'item'   => 'hello.php',
				'active' => false,
				'link'   => false,
			),
			array(
				'item'   => 'dev-checkout',
				'active' => true,
				'link'   => true,
			),
		);
	}

	/**
	 * Every mode; links are never chosen.
	 */
	public function test_modes(): void {
		$this->assertSame( array( 'akismet', 'hello.php' ), Runner::choose_items( self::items(), 'all', array() ) );
		$this->assertSame( array( 'akismet' ), Runner::choose_items( self::items(), 'active', array() ) );
		$this->assertSame( array( 'hello.php' ), Runner::choose_items( self::items(), 'selected', array( 'hello.php', 'dev-checkout' ) ) );
		$this->assertSame( array( 'hello.php' ), Runner::choose_items( self::items(), 'except', array( 'akismet' ) ) );
		$this->assertSame( array(), Runner::choose_items( self::items(), 'selected', array() ) );
	}
}
