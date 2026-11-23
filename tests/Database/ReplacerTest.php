<?php
/**
 * Tests for find & replace.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Tests\Database;

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Serialized test data.

use PHPUnit\Framework\TestCase;
use WM\Warp\Database\ReplaceRule;
use WM\Warp\Database\Replacer;

/**
 * Domains, paths, serialized data and JSON.
 */
final class ReplacerTest extends TestCase {

	/**
	 * Typical rules of a pull from warpb.local to warpa.local.
	 */
	private function replacer(): Replacer {
		return new Replacer(
			array(
				new ReplaceRule( '//warpb.local', '//warpa.local', false, false, true ),
				new ReplaceRule( 'C:\\Sites\\warpb\\public', 'C:\\Sites\\warpa-long\\public' ),
				new ReplaceRule( 'http://warpa.local', 'https://warpa.local', false, false, true ),
			)
		);
	}

	/**
	 * Plain text: domain, protocol and path.
	 */
	public function test_plain_text(): void {
		self::assertSame(
			'<a href="https://warpa.local/kontakt">x</a> C:\\Sites\\warpa-long\\public\\wp-content',
			$this->replacer()->replace( '<a href="http://warpb.local/kontakt">x</a> C:\\Sites\\warpb\\public\\wp-content' )
		);
	}

	/**
	 * Only the whole domain is replaced, letter case of the domain does not matter.
	 */
	public function test_whole_domain_only(): void {
		$replacer = $this->replacer();

		self::assertSame( 'https://warpb.local.example.com/', $replacer->replace( 'https://warpb.local.example.com/' ) );
		self::assertSame( 'https://warpb.localhost/', $replacer->replace( 'https://warpb.localhost/' ) );
		self::assertSame( 'https://warpa.local:8080/ https://warpa.local.', $replacer->replace( 'https://WarpB.local:8080/ https://warpb.local.' ) );
		self::assertSame( 'https://www.warpb.local/', $replacer->replace( 'https://www.warpb.local/' ) );
	}

	/**
	 * Serialized strings get the right lengths, also when nested and in objects.
	 */
	public function test_serialized(): void {
		$inner = serialize( array( 'url' => 'http://warpb.local/a' ) );
		$data  = array(
			'home'   => 'http://warpb.local',
			'nested' => $inner,
			'n'      => 5,
			'f'      => 0.1,
			'b'      => true,
			'null'   => null,
			'list'   => array( 'http://warpb.local/1', 'x' ),
		);

		$result = $this->replacer()->replace( serialize( $data ) );
		$after  = unserialize( $result ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Test data.

		self::assertIsArray( $after );
		self::assertSame( 'https://warpa.local', $after['home'] );
		self::assertSame( array( 'url' => 'https://warpa.local/a' ), unserialize( $after['nested'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Test data.
		self::assertSame( array( 'https://warpa.local/1', 'x' ), $after['list'] );
		self::assertSame( 5, $after['n'] );
		self::assertSame( 0.1, $after['f'] );
		self::assertTrue( $after['b'] );
		self::assertNull( $after['null'] );
	}

	/**
	 * Objects are rewritten without being instantiated; private properties (with NUL bytes) survive.
	 */
	public function test_serialized_object(): void {
		$object = serialize( new ReplacerTestObject( 'http://warpb.local/x' ) );
		$result = $this->replacer()->replace( $object );

		self::assertStringContainsString( 'https://warpa.local/x', $result );
		self::assertSame( 'https://warpa.local/x', ( unserialize( $result, array( 'allowed_classes' => array( ReplacerTestObject::class ) ) ) )->url() ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Test data.
	}

	/**
	 * Broken serialized data are replaced as plain text, nothing is lost.
	 */
	public function test_broken_serialized(): void {
		self::assertSame( 's:99:"https://warpa.local";', $this->replacer()->replace( 's:99:"http://warpb.local";' ) );
	}

	/**
	 * JSON stays byte for byte the same apart from the replaced text (G1): empty objects, big numbers,
	 * duplicate keys and escaped slashes.
	 */
	public function test_json_is_not_decoded(): void {
		$json = '{"settings":{},"big":12345678901234567890,"f":1.0,"a":1,"a":2,"url":"http:\/\/warpb.local\/x","path":"C:\\\\Sites\\\\warpb\\\\public","u":"\u017e"}';

		self::assertSame(
			'{"settings":{},"big":12345678901234567890,"f":1.0,"a":1,"a":2,"url":"https:\/\/warpa.local\/x","path":"C:\\\\Sites\\\\warpa-long\\\\public","u":"\u017e"}',
			$this->replacer()->replace( $json )
		);
	}

	/**
	 * JSON inside a JSON string (form revisions, consent logs) and URL-encoded addresses in query strings.
	 */
	public function test_nested_json_and_url_encoded(): void {
		$nested = '{"data":"{\"url\":\"https:\\\\\/\\\\\/warpb.local\\\\\/x\"}"}';
		$query  = 'https://example.com/?site_url=https%3A%2F%2Fwarpb.local&v=1';

		self::assertSame( '{"data":"{\"url\":\"https:\\\\\/\\\\\/warpa.local\\\\\/x\"}"}', $this->replacer()->replace( $nested ) );
		self::assertSame( 'https://example.com/?site_url=https%3A%2F%2Fwarpa.local&v=1', $this->replacer()->replace( $query ) );
	}

	/**
	 * JSON inside serialized data.
	 */
	public function test_json_in_serialized(): void {
		$value  = serialize( '{"url":"http:\/\/warpb.local\/"}' );
		$result = $this->replacer()->replace( $value );

		self::assertSame( serialize( '{"url":"https:\/\/warpa.local\/"}' ), $result );
	}

	/**
	 * Values without anything to replace are returned untouched.
	 */
	public function test_unchanged(): void {
		$value = serialize( array( 'a' => 'b' ) );

		self::assertSame( $value, $this->replacer()->replace( $value ) );
		self::assertSame( '', $this->replacer()->replace( '' ) );
		self::assertTrue( ( new Replacer( array( new ReplaceRule( 'a', 'a' ) ) ) )->is_empty() );
	}

	/**
	 * Custom rules: letter case and regular expressions.
	 */
	public function test_custom_rules(): void {
		$sensitive = new Replacer( array( new ReplaceRule( 'Warp', 'Bolt', false, true ) ) );
		self::assertSame( 'Bolt warp', $sensitive->replace( 'Warp warp' ) );

		$insensitive = new Replacer( array( new ReplaceRule( 'Warp', 'Bolt' ) ) );
		self::assertSame( 'Bolt Bolt', $insensitive->replace( 'Warp warp' ) );

		$regex = new Replacer( array( new ReplaceRule( '/id-(\d+)/', 'x$1', true ) ) );
		self::assertSame( serialize( 'x1 and x22' ), $regex->replace( serialize( 'id-1 and id-22' ) ) );

		self::assertTrue( ReplaceRule::is_valid_regex( '/a+/i' ) );
		self::assertFalse( ReplaceRule::is_valid_regex( '/a(/' ) );
		self::assertFalse( ReplaceRule::is_valid_regex( 'abc' ) );
	}
}
