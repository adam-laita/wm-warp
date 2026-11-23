<?php
/**
 * Tests of Excludes.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Tests\Files;

use PHPUnit\Framework\TestCase;
use WM\Warp\Files\Excludes;

/**
 * Exclusion patterns behave like .gitignore.
 */
final class ExcludesTest extends TestCase {

	/**
	 * A pattern without a slash matches the name at any depth, case-insensitively.
	 */
	public function test_name_pattern_matches_at_any_depth(): void {
		$excludes = new Excludes( array( '*.log', 'Thumbs.db' ) );

		$this->assertTrue( $excludes->matches( 'debug.log', false ) );
		$this->assertTrue( $excludes->matches( '2024/05/error.LOG', false ) );
		$this->assertTrue( $excludes->matches( 'a/thumbs.db', false ) );
		$this->assertFalse( $excludes->matches( 'logo.png', false ) );
		$this->assertFalse( $excludes->matches( 'catalog/x.jpg', false ) );
	}

	/**
	 * A trailing slash matches folders only; contents are covered.
	 */
	public function test_folder_pattern(): void {
		$excludes = new Excludes( array( '*cache*/', '*backup*/' ) );

		$this->assertTrue( $excludes->matches( 'cache', true ) );
		$this->assertTrue( $excludes->matches( 'elementor/css-cache', true ) );
		$this->assertFalse( $excludes->matches( 'cache', false ), 'A file named cache is not a folder.' );
		$this->assertTrue( $excludes->covers( 'wpml/cache/twig/a.php' ) );
		$this->assertTrue( $excludes->covers( 'backwpup-123-backups/x.zip' ) );
		$this->assertFalse( $excludes->covers( '2024/05/cache.jpg' ) );
	}

	/**
	 * A slash at the start or inside anchors the pattern to the root.
	 */
	public function test_anchored_patterns(): void {
		$excludes = new Excludes( array( '/private', 'a/*.tmp', 'docs/**/draft.txt' ) );

		$this->assertTrue( $excludes->matches( 'private', true ) );
		$this->assertFalse( $excludes->matches( '2024/private', true ) );
		$this->assertTrue( $excludes->matches( 'a/x.tmp', false ) );
		$this->assertFalse( $excludes->matches( 'a/b/x.tmp', false ), 'A single star does not cross a slash.' );
		$this->assertTrue( $excludes->matches( 'docs/draft.txt', false ) );
		$this->assertTrue( $excludes->matches( 'docs/a/b/draft.txt', false ) );
	}

	/**
	 * Comments, empty lines and negations are ignored; special characters are literal.
	 */
	public function test_comments_and_literals(): void {
		$excludes = new Excludes( Excludes::lines( "# comment\n\n!keep.log\n[x].txt\r\nfile+1.jpg\n" ) );

		$this->assertTrue( $excludes->matches( '[x].txt', false ) );
		$this->assertTrue( $excludes->matches( 'file+1.jpg', false ) );
		$this->assertFalse( $excludes->matches( 'x.txt', false ) );
		$this->assertFalse( $excludes->matches( 'keep.log', false ) );
		$this->assertFalse( $excludes->matches( '# comment', false ) );
	}
}
