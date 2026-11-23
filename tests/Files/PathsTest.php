<?php
/**
 * Tests of Paths.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Tests\Files;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WM\Warp\Files\Paths;

/**
 * Paths from the other site never leave their root.
 */
final class PathsTest extends TestCase {

	/**
	 * Unsafe paths.
	 *
	 * @return array<string, array{string}>
	 */
	public static function unsafe(): array {
		return array(
			'empty'          => array( '' ),
			'absolute'       => array( '/etc/passwd' ),
			'parent'         => array( '../wp-config.php' ),
			'parent inside'  => array( '2024/../../wp-config.php' ),
			'dot'            => array( './a.jpg' ),
			'double slash'   => array( '2024//a.jpg' ),
			'trailing slash' => array( '2024/' ),
			'backslash'      => array( '2024\\..\\a.jpg' ),
			'drive'          => array( 'C:/Windows/win.ini' ),
			'null byte'      => array( "a.jpg\0.php" ),
			'newline'        => array( "a\n.jpg" ),
			'invalid utf-8'  => array( "a\xC3.jpg" ),
		);
	}

	/**
	 * Unsafe paths are refused.
	 *
	 * @param string $path Path.
	 */
	#[DataProvider( 'unsafe' )]
	public function test_unsafe_paths_are_refused( string $path ): void {
		$this->assertNull( Paths::normalize( $path ) );
	}

	/**
	 * Normal paths pass unchanged.
	 */
	public function test_safe_paths_pass(): void {
		foreach ( array( 'a.jpg', '2024/05/foto.jpg', 'akismet/akismet.php', 'žluťoučký kůň.png', '.htaccess', 'a..b.jpg' ) as $path ) {
			$this->assertSame( $path, Paths::normalize( $path ) );
		}
	}

	/**
	 * Names Windows cannot store.
	 */
	public function test_invalid_on_windows(): void {
		$this->assertTrue( Paths::invalid_on_windows( '2024/a:b.jpg' ) );
		$this->assertTrue( Paths::invalid_on_windows( 'what?.png' ) );
		$this->assertTrue( Paths::invalid_on_windows( 'con.txt' ) );
		$this->assertTrue( Paths::invalid_on_windows( 'folder./a.jpg' ) );
		$this->assertTrue( Paths::invalid_on_windows( 'name ' ) );
		$this->assertFalse( Paths::invalid_on_windows( '2024/console.txt' ) );
		$this->assertFalse( Paths::invalid_on_windows( 'žluť.jpg' ) );
	}

	/**
	 * Item, extension and folding.
	 */
	public function test_helpers(): void {
		$this->assertSame( 'akismet', Paths::item( 'akismet/akismet.php' ) );
		$this->assertSame( 'hello.php', Paths::item( 'hello.php' ) );
		$this->assertSame( 'jpg', Paths::extension( '2024/Foto.JPG' ) );
		$this->assertSame( '', Paths::extension( 'LICENSE' ) );
		$this->assertSame( 'foto čž.jpg', Paths::fold( 'Foto ČŽ.jpg' ) );
	}
}
