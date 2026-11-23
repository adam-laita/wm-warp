<?php
/**
 * Tests for adapting CREATE TABLE.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Tests\Database;

use PHPUnit\Framework\TestCase;
use WM\Warp\Database\CreateRewriter;
use WM\Warp\Database\TableNames;

/**
 * Temporary table name, foreign keys, collations and server specific options.
 */
final class CreateRewriterTest extends TestCase {

	private const MYSQL_CREATE = "CREATE TABLE `wp_child` (\n"
		. "  `id` bigint unsigned NOT NULL AUTO_INCREMENT,\n"
		. "  `post_id` bigint unsigned NOT NULL,\n"
		. "  `name` varchar(100) COLLATE utf8mb4_0900_ai_ci NOT NULL,\n"
		. "  `pt` point NOT NULL /*!80003 SRID 4326 */,\n"
		. "  PRIMARY KEY (`id`),\n"
		. "  KEY `post_id` (`post_id`),\n"
		. "  CONSTRAINT `wp_child_chk_1` CHECK ((`id` > 0)),\n"
		. "  CONSTRAINT `fk_post` FOREIGN KEY (`post_id`) REFERENCES `wp_posts` (`ID`) ON DELETE CASCADE\n"
		. ") ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci /*!80016 DEFAULT ENCRYPTION='N' */";

	/**
	 * MySQL 8 table on MariaDB: foreign key out, collation replaced, MySQL comments removed.
	 */
	public function test_mysql_to_mariadb(): void {
		$mariadb = static fn( string $collation ): bool => in_array( $collation, array( 'utf8mb4_unicode_520_ci', 'utf8mb4_unicode_ci', 'utf8mb4_general_ci' ), true );
		$result  = CreateRewriter::rewrite( self::MYSQL_CREATE, TableNames::temp( 'wb_child' ), true, $mariadb, 'wp_', 'wb_' );

		self::assertStringStartsWith( 'CREATE TABLE `wmwt_wb_child` (', $result['create'] );
		self::assertStringNotContainsString( 'FOREIGN KEY', $result['create'] );
		self::assertStringNotContainsString( '0900', $result['create'] );
		self::assertStringNotContainsString( '/*!80', $result['create'] );
		self::assertStringContainsString( "  CHECK ((`id` > 0))\n)", $result['create'] );
		self::assertStringContainsString( 'COLLATE=utf8mb4_unicode_520_ci', $result['create'] );
		self::assertSame( array( 'CONSTRAINT `fk_post` FOREIGN KEY (`post_id`) REFERENCES `wb_posts` (`ID`) ON DELETE CASCADE' ), $result['foreign_keys'] );
		self::assertSame( array( 'utf8mb4_0900_ai_ci' => 'utf8mb4_unicode_520_ci' ), $result['collations'] );
	}

	/**
	 * MariaDB options that MySQL does not know.
	 */
	public function test_mariadb_to_mysql(): void {
		$create = "CREATE TABLE `wp_x` (\n  `a` int(11) NOT NULL,\n  `b` int(11) GENERATED ALWAYS AS (`a` * 2) PERSISTENT,\n  `c` varchar(10) COLLATE utf8mb4_uca1400_ai_ci DEFAULT NULL,\n  PRIMARY KEY (`a`)\n) ENGINE=Aria DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci PAGE_CHECKSUM=1 TRANSACTIONAL=1";
		$mysql  = static fn( string $collation ): bool => 'utf8mb4_0900_ai_ci' === $collation;
		$result = CreateRewriter::rewrite( $create, 'wmwt_wp_x', false, $mysql, 'wp_', 'wp_' );

		self::assertStringContainsString( '(`a` * 2) STORED', $result['create'] );
		self::assertStringContainsString( 'ENGINE=InnoDB', $result['create'] );
		self::assertStringContainsString( 'COLLATE=utf8mb4_0900_ai_ci', $result['create'] );
		self::assertStringNotContainsString( 'PAGE_CHECKSUM', $result['create'] );
		self::assertStringNotContainsString( 'TRANSACTIONAL', $result['create'] );
		self::assertSame( array(), $result['foreign_keys'] );
	}

	/**
	 * Long table names stay within 64 characters and unique; prefixes are swapped only at the start.
	 */
	public function test_names(): void {
		$long = 'wp_' . str_repeat( 'very_long_plugin_table_', 3 );

		self::assertLessThanOrEqual( 64, strlen( TableNames::temp( $long ) ) );
		self::assertNotSame( TableNames::temp( $long ), TableNames::temp( $long . 'x' ) );
		self::assertTrue( TableNames::is_internal( TableNames::backup( 'wp_posts' ) ) );
		self::assertSame( 'wb_posts', TableNames::retarget( 'wp_posts', 'wp_', 'wb_' ) );
		self::assertSame( 'other_wp_posts', TableNames::retarget( 'other_wp_posts', 'wp_', 'wb_' ) );
	}
}
