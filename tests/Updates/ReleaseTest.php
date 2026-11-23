<?php
/**
 * Tests for reading releases.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Tests\Updates;

use PHPUnit\Framework\TestCase;
use WM\Warp\Updates\Release;

/**
 * Releases from the GitHub API, versions, checksums and release notes.
 */
final class ReleaseTest extends TestCase {

	private const HASH = 'c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00';

	/**
	 * API response of a stable release.
	 *
	 * @param array<string, mixed> $changes Changed fields.
	 * @return array<string, mixed>
	 */
	private static function api( array $changes = array() ): array {
		return array_merge(
			array(
				'tag_name'     => 'v0.1.0',
				'draft'        => false,
				'prerelease'   => false,
				'html_url'     => 'https://github.com/adam-laita/wm-warp/releases/tag/v0.1.0',
				'published_at' => '2026-11-24T10:00:00Z',
				'body'         => "- First release\n- **Pull** and push",
				'assets'       => array(
					array(
						'name'                 => 'wm-warp.zip',
						'browser_download_url' => 'https://github.com/adam-laita/wm-warp/releases/download/v0.1.0/wm-warp.zip',
						'digest'               => 'sha256:' . self::HASH,
					),
					array(
						'name'                 => 'wm-warp.zip.sha256',
						'browser_download_url' => 'https://github.com/adam-laita/wm-warp/releases/download/v0.1.0/wm-warp.zip.sha256',
					),
				),
			),
			$changes
		);
	}

	/**
	 * A stable release with the ZIP and its digest.
	 */
	public function test_stable_release(): void {
		$release = Release::from_api( self::api() );

		self::assertNotNull( $release );
		self::assertSame( '0.1.0', $release->version );
		self::assertSame( self::HASH, $release->sha256 );
		self::assertStringEndsWith( '/wm-warp.zip', $release->package );
		self::assertStringEndsWith( '/wm-warp.zip.sha256', (string) $release->sha256_url );
		self::assertTrue( $release->newer_than( '0.1.0-dev' ) );
		self::assertTrue( $release->newer_than( '0.0.9' ) );
		self::assertFalse( $release->newer_than( '0.1.0' ) );
		self::assertFalse( $release->newer_than( '0.2.0' ) );
	}

	/**
	 * Drafts, pre-releases, other tags and releases without the package are ignored.
	 */
	public function test_ignored_releases(): void {
		self::assertNull( Release::from_api( self::api( array( 'prerelease' => true ) ) ) );
		self::assertNull( Release::from_api( self::api( array( 'draft' => true ) ) ) );
		self::assertNull( Release::from_api( self::api( array( 'tag_name' => 'v0.2.0-beta' ) ) ) );
		self::assertNull( Release::from_api( self::api( array( 'assets' => array() ) ) ) );
		self::assertNull( Release::from_api( 'not json' ) );

		$http                                      = self::api();
		$http['assets'][0]['browser_download_url'] = 'http://example.com/wm-warp.zip';
		self::assertNull( Release::from_api( $http ) );
	}

	/**
	 * Without a digest the checksum file is used; without both the release is ignored.
	 */
	public function test_checksum_sources(): void {
		$data = self::api();
		unset( $data['assets'][0]['digest'] );
		$release = Release::from_api( $data );

		self::assertNotNull( $release );
		self::assertNull( $release->sha256 );
		self::assertNotNull( $release->sha256_url );

		unset( $data['assets'][1] );
		self::assertNull( Release::from_api( $data ) );

		self::assertSame( self::HASH, Release::parse_checksum( strtoupper( self::HASH ) . "  wm-warp.zip\n" ) );
		self::assertSame( self::HASH, Release::parse_checksum( self::HASH ) );
		self::assertNull( Release::parse_checksum( 'Not Found' ) );
	}

	/**
	 * Versions from tags.
	 */
	public function test_versions(): void {
		self::assertSame( '1.2.3', Release::version( 'v1.2.3' ) );
		self::assertSame( '1.2.3', Release::version( '1.2.3' ) );
		self::assertNull( Release::version( 'v1.2' ) );
		self::assertNull( Release::version( 'latest' ) );
	}

	/**
	 * Release notes become simple HTML; HTML in the notes is escaped and only https links stay.
	 */
	public function test_notes_html(): void {
		self::assertSame(
			'<h4>Changes</h4><ul><li><strong>Pull</strong> is faster</li><li>Fixed <code>utf8</code></li></ul><p>See <a href="https://example.com/x">notes</a>.</p>',
			Release::notes_html( "## Changes\n\n- **Pull** is faster\n* Fixed `utf8`\n\nSee [notes](https://example.com/x)." )
		);
		self::assertSame( '<p>&lt;script&gt;x&lt;/script&gt; [a](javascript:alert(1))</p>', Release::notes_html( '<script>x</script> [a](javascript:alert(1))' ) );
	}
}
