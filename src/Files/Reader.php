<?php
/**
 * Reading files on the source.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Files;

/**
 * Reads requested parts of files for a transfer. A whole small file and the last part of a large one
 * carry the hash of the whole file, so the target can check that it arrived intact.
 */
final class Reader {

	/**
	 * Extensions of files that are already compressed (deflating them again only costs time).
	 */
	private const COMPRESSED = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'mp4', 'm4v', 'mov', 'webm', 'mkv', 'mp3', 'm4a', 'ogg', 'oga', 'flac', 'aac', 'zip', 'gz', 'tgz', 'bz2', 'xz', '7z', 'rar', 'woff', 'woff2', 'pdf', 'docx', 'xlsx', 'pptx', 'jar', 'br', 'zst', 'wpress' );

	/**
	 * Reads parts.
	 *
	 * @param array $parts Requested parts: group, path, offset, length, size.
	 * @phpstan-param list<mixed> $parts
	 * @return array{parts: list<array<string, mixed>>, payload: string, deflate: bool}
	 */
	public static function read( array $parts ): array {
		$headers      = array();
		$payload      = '';
		$compressible = 0;

		foreach ( $parts as $part ) {
			if ( ! is_array( $part ) ) {
				continue;
			}

			$group  = is_string( $part['group'] ?? null ) ? $part['group'] : '';
			$path   = Paths::normalize( is_string( $part['path'] ?? null ) ? $part['path'] : '' );
			$offset = max( 0, (int) ( $part['offset'] ?? 0 ) );
			$length = max( 0, (int) ( $part['length'] ?? 0 ) );
			$size   = (int) ( $part['size'] ?? -1 );
			$header = array(
				'group' => $group,
				'path'  => (string) ( $part['path'] ?? '' ),
			);

			if ( ! in_array( $group, Groups::ALL, true ) || null === $path || Groups::is_protected( $group, $path ) ) {
				$headers[] = $header + array( 'status' => 'missing' );
				continue;
			}

			$root     = Groups::root( $group );
			$absolute = $root . '/' . $path;
			$stat     = is_link( $absolute ) || ! Fs::inside( $root, $absolute ) ? null : Fs::stat( $absolute );

			if ( null === $stat ) {
				$headers[] = $header + array( 'status' => 'missing' );
				continue;
			}

			if ( $stat[0] !== $size || $offset + $length > $size ) {
				$headers[] = $header + array(
					'status' => 'changed',
					'size'   => $stat[0],
				);
				continue;
			}

			$data  = 0 === $length ? '' : Fs::read( $absolute, $offset, $length );
			$final = $offset + $length >= $size;

			if ( strlen( $data ) !== $length ) {
				$headers[] = $header + array(
					'status' => 'changed',
					'size'   => $stat[0],
				);
				continue;
			}

			$hash = '';
			if ( $final ) {
				$hash = 0 === $offset ? hash( 'xxh128', $data ) : (string) Fs::hash( $absolute );
			}

			$headers[] = $header + array(
				'status' => 'ok',
				'offset' => $offset,
				'length' => $length,
				'size'   => $size,
				'mtime'  => $stat[1],
				'final'  => $final,
				'hash'   => $hash,
			);

			if ( ! in_array( Paths::extension( $path ), self::COMPRESSED, true ) ) {
				$compressible += $length;
			}

			$payload .= $data;
		}

		return array(
			'parts'   => $headers,
			'payload' => $payload,
			'deflate' => $compressible * 2 >= strlen( $payload ),
		);
	}

	/**
	 * Whether a part is worth compressing, by the file extension.
	 *
	 * @param string $path Relative path.
	 */
	public static function compressible( string $path ): bool {
		return ! in_array( Paths::extension( $path ), self::COMPRESSED, true );
	}

	/**
	 * Hashes of files.
	 *
	 * @param string   $group Group.
	 * @param string[] $paths Relative paths.
	 * @phpstan-param list<string> $paths
	 * @return list<string|null> Hash, or null when the file is missing.
	 */
	public static function hashes( string $group, array $paths ): array {
		$root   = Groups::root( $group );
		$hashes = array();

		foreach ( $paths as $path ) {
			$safe     = Paths::normalize( $path );
			$absolute = $root . '/' . $safe;
			$hashes[] = null === $safe || Groups::is_protected( $group, $safe ) || is_link( $absolute ) || ! Fs::inside( $root, $absolute ) ? null : Fs::hash( $absolute );
		}

		return $hashes;
	}
}
