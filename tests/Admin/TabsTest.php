<?php
/**
 * Tests for the admin tabs.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Tests\Admin;

use PHPUnit\Framework\TestCase;
use WM\Warp\Admin\Tabs;

/**
 * Tab resolution.
 */
final class TabsTest extends TestCase {

	/**
	 * Tabs used in the tests.
	 */
	private function tabs(): Tabs {
		return new Tabs(
			array(
				'sites'    => 'Sites',
				'settings' => 'Settings',
			)
		);
	}

	/**
	 * A known tab is returned as is.
	 */
	public function test_known_tab_is_kept(): void {
		self::assertSame( 'settings', $this->tabs()->resolve( 'settings' ) );
	}

	/**
	 * Unknown, empty and non-string values fall back to the default tab.
	 */
	public function test_unknown_values_fall_back_to_default(): void {
		$tabs = $this->tabs();

		foreach ( array( 'backups', '', null, array( 'settings' ), 42 ) as $value ) {
			self::assertSame( Tabs::DEFAULT_TAB, $tabs->resolve( $value ) );
		}
	}

	/**
	 * The default tab is required.
	 */
	public function test_default_tab_is_required(): void {
		$this->expectException( \InvalidArgumentException::class );

		new Tabs( array( 'settings' => 'Settings' ) );
	}
}
