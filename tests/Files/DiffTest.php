<?php
/**
 * Tests of Diff.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Tests\Files;

use PHPUnit\Framework\TestCase;
use WM\Warp\Files\Diff;

/**
 * Deciding what happens to every file.
 */
final class DiffTest extends TestCase {

	/**
	 * New, changed, same and to-check files; nothing is deleted without mirroring.
	 */
	public function test_synchronize(): void {
		$source = array(
			'new.jpg'     => array( 10, 100 ),
			'bigger.jpg'  => array( 20, 100 ),
			'same.jpg'    => array( 30, 100 ),
			'touched.jpg' => array( 40, 200 ),
		);
		$target = array(
			'bigger.jpg'  => array( 15, 100 ),
			'same.jpg'    => array( 30, 100 ),
			'touched.jpg' => array( 40, 100 ),
			'extra.jpg'   => array( 50, 100 ),
		);

		$diff = Diff::compare( $source, $target, false, 0, false, false );

		$this->assertSame( array( array( 'new.jpg', 10, 100 ), array( 'bigger.jpg', 20, 100 ) ), $diff['transfer'] );
		$this->assertSame( array( array( 'touched.jpg', 40, 200 ) ), $diff['check'] );
		$this->assertSame( array( array( 'same.jpg', 30, 100 ) ), $diff['same'] );
		$this->assertSame( array(), $diff['delete'] );
		$this->assertSame( 30, Diff::bytes( $diff['transfer'] ) );
	}

	/**
	 * Mirroring deletes files only on the target.
	 */
	public function test_mirror_deletes_extra_files(): void {
		$diff = Diff::compare(
			array( 'a.jpg' => array( 1, 1 ) ),
			array(
				'a.jpg'     => array( 1, 1 ),
				'extra.jpg' => array( 2, 2 ),
			),
			true,
			0,
			false,
			false
		);

		$this->assertSame( array( 'extra.jpg' ), $diff['delete'] );
	}

	/**
	 * Only files changed since a date; with a date nothing is deleted.
	 */
	public function test_since_date(): void {
		$source = array(
			'old.jpg'   => array( 1, 100 ),
			'fresh.jpg' => array( 1, 300 ),
		);

		$diff = Diff::compare( $source, array( 'extra.jpg' => array( 1, 1 ) ), true, 200, false, false );

		$this->assertSame( array( array( 'fresh.jpg', 1, 300 ) ), $diff['transfer'] );
		$this->assertSame( array(), $diff['delete'] );
	}

	/**
	 * On a case-insensitive target: a name differing in case replaces the file and is not deleted
	 * by mirroring; two source names differing only in case cannot both exist.
	 */
	public function test_case_insensitive_target(): void {
		$source = array(
			'Foto.jpg' => array( 5, 100 ),
			'foto.jpg' => array( 6, 100 ),
			'b.jpg'    => array( 1, 1 ),
		);
		$target = array(
			'foto.jpg' => array( 5, 100 ),
			'B.JPG'    => array( 1, 1 ),
		);

		$diff = Diff::compare( $source, $target, true, 0, true, false );

		$this->assertSame( array( array( 'Foto.jpg', 5, 100 ), array( 'b.jpg', 1, 1 ) ), $diff['transfer'] );
		$this->assertSame( array( array( 'foto.jpg', 'case' ) ), $diff['skipped'] );
		$this->assertSame( array(), $diff['delete'] );
	}

	/**
	 * On a case-sensitive target both names are separate files.
	 */
	public function test_case_sensitive_target(): void {
		$source = array(
			'Foto.jpg' => array( 5, 100 ),
			'foto.jpg' => array( 6, 100 ),
		);

		$diff = Diff::compare( $source, array( 'foto.jpg' => array( 6, 100 ) ), true, 0, false, false );

		$this->assertSame( array( array( 'Foto.jpg', 5, 100 ) ), $diff['transfer'] );
		$this->assertSame( array( array( 'foto.jpg', 6, 100 ) ), $diff['same'] );
	}

	/**
	 * Names not allowed on Windows are skipped when the target runs on Windows.
	 */
	public function test_windows_names_are_skipped(): void {
		$diff = Diff::compare( array( 'a:b.jpg' => array( 1, 1 ) ), array(), false, 0, true, true );

		$this->assertSame( array(), $diff['transfer'] );
		$this->assertSame( array( array( 'a:b.jpg', 'windows' ) ), $diff['skipped'] );
	}
}
