<?php
/**
 * A published release of the plugin.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Updates;

/**
 * Release read from the GitHub API ("releases/latest", which leaves out drafts and pre-releases).
 *
 * The package is the attached asset "wm-warp.zip" (with the folder wm-warp inside), never the source
 * archive GitHub builds from the tag. Its SHA-256 comes from the asset digest GitHub computes, or from
 * the attached "wm-warp.zip.sha256" when the digest is missing (8.1).
 * Pure logic without WordPress calls, covered by unit tests.
 */
final class Release {

	public const ASSET = 'wm-warp.zip';

	/**
	 * Constructor.
	 *
	 * @param string      $version    Version without the leading "v".
	 * @param string      $package    Download URL of the ZIP.
	 * @param string|null $sha256     Expected SHA-256 (hex), null when it must be read from $sha256_url.
	 * @param string|null $sha256_url Download URL of the checksum file.
	 * @param string      $notes      Release notes (Markdown).
	 * @param string      $url        Web page of the release.
	 * @param string      $published  Publication time (ISO 8601).
	 */
	public function __construct(
		public readonly string $version,
		public readonly string $package,
		public readonly ?string $sha256,
		public readonly ?string $sha256_url,
		public readonly string $notes,
		public readonly string $url,
		public readonly string $published
	) {}

	/**
	 * Reads a release from the decoded API response.
	 *
	 * @param mixed $data Decoded JSON.
	 * @return self|null Null when it is not a stable release with the ZIP attached.
	 */
	public static function from_api( mixed $data ): ?self {
		if ( ! is_array( $data ) || ! empty( $data['draft'] ) || ! empty( $data['prerelease'] ) ) {
			return null;
		}

		$version = self::version( is_string( $data['tag_name'] ?? null ) ? $data['tag_name'] : '' );

		if ( null === $version ) {
			return null;
		}

		$package    = null;
		$sha256     = null;
		$sha256_url = null;

		foreach ( is_array( $data['assets'] ?? null ) ? $data['assets'] : array() as $asset ) {
			if ( ! is_array( $asset ) || ! is_string( $asset['browser_download_url'] ?? null ) || ! str_starts_with( $asset['browser_download_url'], 'https://' ) ) {
				continue;
			}

			$name = is_string( $asset['name'] ?? null ) ? $asset['name'] : '';

			if ( self::ASSET === $name ) {
				$package = $asset['browser_download_url'];
				$digest  = is_string( $asset['digest'] ?? null ) ? strtolower( $asset['digest'] ) : '';
				$sha256  = 1 === preg_match( '/^sha256:([0-9a-f]{64})$/', $digest, $m ) ? $m[1] : null;
			} elseif ( self::ASSET . '.sha256' === $name ) {
				$sha256_url = $asset['browser_download_url'];
			}
		}

		if ( null === $package || ( null === $sha256 && null === $sha256_url ) ) {
			return null;
		}

		return new self(
			$version,
			$package,
			$sha256,
			$sha256_url,
			is_string( $data['body'] ?? null ) ? $data['body'] : '',
			is_string( $data['html_url'] ?? null ) ? $data['html_url'] : '',
			is_string( $data['published_at'] ?? null ) ? $data['published_at'] : ''
		);
	}

	/**
	 * Version from a tag: "v1.2.3" or "1.2.3".
	 *
	 * @param string $tag Tag name.
	 * @return string|null Null for tags that are not a plain version.
	 */
	public static function version( string $tag ): ?string {
		return 1 === preg_match( '/^v?(\d+\.\d+\.\d+)$/', trim( $tag ), $m ) ? $m[1] : null;
	}

	/**
	 * Whether this release is newer than the installed version (0.1.0 is newer than 0.1.0-dev).
	 *
	 * @param string $installed Installed version.
	 */
	public function newer_than( string $installed ): bool {
		return version_compare( $this->version, $installed, '>' );
	}

	/**
	 * Reads the hash from the content of a checksum file ("<hex>  wm-warp.zip" or just "<hex>").
	 *
	 * @param string $content File content.
	 */
	public static function parse_checksum( string $content ): ?string {
		return 1 === preg_match( '/^\s*([0-9a-fA-F]{64})\b/', $content, $m ) ? strtolower( $m[1] ) : null;
	}

	/**
	 * Release notes as simple HTML: headings, lists, paragraphs, bold, code and links.
	 *
	 * The result still has to pass wp_kses_post().
	 *
	 * @param string $markdown Markdown.
	 */
	public static function notes_html( string $markdown ): string {
		$html  = '';
		$list  = false;
		$lines = preg_split( '/\r?\n/', $markdown );

		foreach ( false === $lines ? array() : $lines as $line ) {
			$line = rtrim( $line );

			if ( 1 === preg_match( '/^\s*[-*]\s+(.*)$/', $line, $m ) ) {
				$html .= ( $list ? '' : '<ul>' ) . '<li>' . self::inline( $m[1] ) . '</li>';
				$list  = true;
				continue;
			}

			if ( $list ) {
				$html .= '</ul>';
				$list  = false;
			}

			if ( '' === trim( $line ) ) {
				continue;
			}

			if ( 1 === preg_match( '/^#{1,6}\s+(.*)$/', $line, $m ) ) {
				$html .= '<h4>' . self::inline( $m[1] ) . '</h4>';
			} else {
				$html .= '<p>' . self::inline( $line ) . '</p>';
			}
		}

		return $html . ( $list ? '</ul>' : '' );
	}

	/**
	 * Inline Markdown of one line.
	 *
	 * @param string $text Text.
	 */
	private static function inline( string $text ): string {
		$text = htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
		$text = (string) preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text );
		$text = (string) preg_replace( '/`([^`]+)`/', '<code>$1</code>', $text );

		return (string) preg_replace( '/\[([^\]]+)\]\((https:\/\/[^\s)"]+)\)/', '<a href="$2">$1</a>', $text );
	}
}
