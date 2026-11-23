<?php
/**
 * Tests for row changes.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Tests\Database;

use PHPUnit\Framework\TestCase;
use WM\Warp\Database\ReplaceRule;
use WM\Warp\Database\Replacer;
use WM\Warp\Database\RowTransformer;
use WM\Warp\Database\TableInfo;

/**
 * Text columns only, NULL untouched, table prefix in keys.
 */
final class RowTransformerTest extends TestCase {

	/**
	 * Table with a text, a binary and a number column.
	 *
	 * @param string $name Table name.
	 * @param string $key  Name of the text column.
	 */
	private static function table( string $name, string $key = 'meta_key' ): TableInfo {
		return new TableInfo(
			$name,
			'',
			array(
				array(
					'name'      => $key,
					'kind'      => TableInfo::TEXT,
					'generated' => false,
				),
				array(
					'name'      => 'data',
					'kind'      => TableInfo::BINARY,
					'generated' => false,
				),
				array(
					'name'      => 'n',
					'kind'      => TableInfo::OTHER,
					'generated' => false,
				),
				array(
					'name'      => 'g',
					'kind'      => TableInfo::TEXT,
					'generated' => true,
				),
			),
			array(),
			0,
			0
		);
	}

	/**
	 * Binary data and NULL stay as they are, text is replaced.
	 */
	public function test_binary_untouched(): void {
		$transformer = new RowTransformer( new Replacer( array( new ReplaceRule( 'a.cz', 'bb.cz' ) ) ), self::table( 'wp_t' ), 'wp_', 'wp_' );
		$rows        = $transformer->apply( array( array( 'x a.cz', 'bin a.cz', 'a.cz' ), array( null, null, null ) ) );

		self::assertSame( array( array( 'x bb.cz', 'bin a.cz', 'a.cz' ), array( null, null, null ) ), $rows );
	}

	/**
	 * Keys with the table prefix follow the prefix of the target.
	 */
	public function test_prefix(): void {
		$none = new Replacer( array() );

		$usermeta = new RowTransformer( $none, self::table( 'wp_usermeta' ), 'wp_', 'wb_' );
		self::assertSame(
			array( array( 'wb_capabilities', null, '1' ), array( 'nickname', null, '1' ) ),
			$usermeta->apply( array( array( 'wp_capabilities', null, '1' ), array( 'nickname', null, '1' ) ) )
		);

		$options = new RowTransformer( $none, self::table( 'wp_options', 'option_name' ), 'wp_', 'wb_' );
		self::assertSame(
			array( array( 'wb_user_roles', null, '1' ), array( 'wp_other', null, '1' ) ),
			$options->apply( array( array( 'wp_user_roles', null, '1' ), array( 'wp_other', null, '1' ) ) )
		);

		self::assertTrue( ( new RowTransformer( $none, self::table( 'wp_usermeta' ), 'wp_', 'wp_' ) )->is_identity() );
	}
}
