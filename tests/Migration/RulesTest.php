<?php
/**
 * Tests for automatic rules.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Tests\Migration;

use PHPUnit\Framework\TestCase;
use WM\Warp\Database\Replacer;
use WM\Warp\Migration\Rules;

/**
 * Domain, path and protocol of a pull from a live site to a local one.
 */
final class RulesTest extends TestCase {

	/**
	 * Rules applied in order produce addresses and paths of the target.
	 */
	public function test_pull_to_local(): void {
		$rules = array_merge(
			Rules::domain( 'http://example.cz/', 'https://example.local' ),
			Rules::path( '/var/www/example/', 'C:\\Users\\a\\Local Sites\\example\\app\\public/' ),
			Rules::protocol( 'https://example.local' )
		);

		$replacer = new Replacer( $rules );

		self::assertSame( 'https://example.local/kontakt', $replacer->replace( 'http://example.cz/kontakt' ) );
		self::assertSame( 'https://example.local/a', $replacer->replace( 'https://example.cz/a' ) );
		self::assertSame( 'http://www.w3.org/2000/svg', $replacer->replace( 'http://www.w3.org/2000/svg' ) );
		self::assertSame( 'C:\\Users\\a\\Local Sites\\example\\app\\public/wp-content', $replacer->replace( '/var/www/example/wp-content' ) );
	}

	/**
	 * Paths on Windows are replaced with both kinds of slashes.
	 */
	public function test_windows_paths(): void {
		$replacer = new Replacer( Rules::path( 'C:\\Sites\\a\\public/', 'C:\\Sites\\b\\public/' ) );

		self::assertSame( 'C:\\Sites\\b\\public\\x', $replacer->replace( 'C:\\Sites\\a\\public\\x' ) );
		self::assertSame( 'C:/Sites/b/public/x', $replacer->replace( 'C:/Sites/a/public/x' ) );
	}

	/**
	 * Same address: no domain rule (moving a site with the same domain, 1.9).
	 */
	public function test_same_address(): void {
		self::assertSame( array(), Rules::domain( 'https://Example.cz/', 'https://example.cz' ) );
		self::assertSame( array(), Rules::path( '/var/www/a/', '/var/www/a' ) );
		self::assertSame( '//example.cz/sub', Rules::without_scheme( 'https://example.cz/sub/' ) );
	}
}
