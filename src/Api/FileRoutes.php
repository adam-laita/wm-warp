<?php
/**
 * File part of the Warp API.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Api;

use WM\Warp\Files\Groups;
use WM\Warp\Files\Items;
use WM\Warp\Files\Reader;
use WM\Warp\Files\Scanner;
use WM\Warp\Files\Stager;
use WM\Warp\Migration\Target;

/**
 * Requests about files and about the whole run. This site is the source (items, scan, hash, read),
 * the target (begin, write, copy, plan, swap; scan and hash too) or both. The site that started the
 * migration calls the same handlers directly for its own side.
 */
final class FileRoutes {

	public const ROUTES = array( 'files/items', 'files/scan', 'files/hash', 'files/read', 'files/begin', 'files/write', 'files/copy', 'files/plan', 'files/swap', 'run/confirm', 'run/rollback', 'run/abort' );

	/**
	 * Handles a request.
	 *
	 * @param string               $route   Route below the namespace.
	 * @param array<string, mixed> $data    Request data.
	 * @param string               $payload Binary payload.
	 * @param string               $peer    Name of the other site.
	 * @return array{data: array<string, mixed>, payload: string, deflate: bool}
	 * @throws \UnexpectedValueException When the request is invalid.
	 */
	public static function handle( string $route, array $data, string $payload, string $peer ): array {
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Large files.
		}
		wp_raise_memory_limit( 'admin' );

		$result = match ( $route ) {
			'files/items'  => array(
				'data' => array(
					Groups::PLUGINS => Items::plugins(),
					Groups::THEMES  => Items::themes(),
				),
			),
			'files/scan'   => array( 'data' => self::scan( $data ) ),
			'files/hash'   => array( 'data' => array( 'hashes' => Reader::hashes( self::group( $data ), self::strings( $data['paths'] ?? null ) ) ) ),
			'files/read'   => self::read( $data ),
			'files/begin'  => self::begin( $data, $peer ),
			'files/write'  => array( 'data' => Stager::write( self::run_id( $data ), self::list( $data['parts'] ?? null ), $payload ) ),
			'files/copy'   => array( 'data' => Stager::copy( self::run_id( $data ), self::group( $data ), self::list( $data['files'] ?? null ) ) ),
			'files/plan'   => self::plan( $data ),
			'files/swap'   => array( 'data' => Stager::swap( self::run_id( $data ) ) ),
			'run/confirm'  => self::done( array( Target::class, 'confirm' ) ),
			'run/rollback' => self::done( array( Target::class, 'rollback' ) ),
			'run/abort'    => self::done( static fn() => Target::abort( self::run_id( $data ) ) ),
			default        => throw new \UnexpectedValueException( 'Unknown route.' ),
		};

		return array(
			'data'    => $result['data'],
			'payload' => $result['payload'] ?? '',
			'deflate' => $result['deflate'] ?? true,
		);
	}

	/**
	 * One step of listing files.
	 *
	 * @param array<string, mixed> $data Request data: group, bases, excludes, allow_php, stack.
	 * @return array<string, mixed>
	 */
	private static function scan( array $data ): array {
		$group  = self::group( $data );
		$stack  = is_array( $data['stack'] ?? null ) ? self::strings( $data['stack'] ) : null;
		$result = Scanner::scan(
			$group,
			self::strings( $data['bases'] ?? array( '' ) ),
			Groups::excludes( $group, self::strings( $data['excludes'] ?? null ), true === ( $data['allow_php'] ?? false ) ),
			$stack
		);

		return $result;
	}

	/**
	 * Parts of files.
	 *
	 * @param array<string, mixed> $data Request data: parts.
	 * @return array{data: array<string, mixed>, payload: string, deflate: bool}
	 */
	private static function read( array $data ): array {
		$result = Reader::read( self::list( $data['parts'] ?? null ) );

		return array(
			'data'    => array( 'parts' => $result['parts'] ),
			'payload' => $result['payload'],
			'deflate' => $result['deflate'],
		);
	}

	/**
	 * Starts receiving files.
	 *
	 * @param array<string, mixed> $data Request data: run_id, groups.
	 * @param string               $peer Name of the source site.
	 * @return array{data: array<string, mixed>}
	 */
	private static function begin( array $data, string $peer ): array {
		$run_id = self::run_id( $data );
		$groups = array();

		foreach ( is_array( $data['groups'] ?? null ) ? $data['groups'] : array() as $group => $spec ) {
			if ( is_array( $spec ) ) {
				$groups[ (string) $group ] = array(
					'mode'      => is_string( $spec['mode'] ?? null ) ? $spec['mode'] : Stager::MODE_FILES,
					'items'     => self::strings( $spec['items'] ?? null ),
					'allow_php' => true === ( $spec['allow_php'] ?? false ),
				);
			}
		}

		Target::settle( $run_id );

		return array( 'data' => Stager::begin( $run_id, $peer, $groups ) );
	}

	/**
	 * Adds deletions, times and items to replace.
	 *
	 * @param array<string, mixed> $data Request data: run_id, group, delete, touch, items.
	 * @return array{data: array<string, mixed>}
	 */
	private static function plan( array $data ): array {
		Stager::plan( self::run_id( $data ), self::group( $data ), $data );

		return array( 'data' => array( 'planned' => true ) );
	}

	/**
	 * Runs an action without a result.
	 *
	 * @param callable $action Action.
	 * @return array{data: array<string, mixed>}
	 */
	private static function done( callable $action ): array {
		$action();

		return array( 'data' => array( 'done' => true ) );
	}

	/**
	 * Group from request data.
	 *
	 * @param array<string, mixed> $data Request data.
	 * @throws \UnexpectedValueException When it is unknown.
	 */
	private static function group( array $data ): string {
		$group = is_string( $data['group'] ?? null ) ? $data['group'] : '';

		if ( ! in_array( $group, Groups::ALL, true ) ) {
			throw new \UnexpectedValueException( 'Unknown file group.' );
		}

		return $group;
	}

	/**
	 * Run ID from request data.
	 *
	 * @param array<string, mixed> $data Request data.
	 * @throws \UnexpectedValueException When it is missing.
	 */
	private static function run_id( array $data ): string {
		$run_id = is_string( $data['run_id'] ?? null ) ? $data['run_id'] : '';

		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $run_id ) ) {
			throw new \UnexpectedValueException( 'Invalid run ID.' );
		}

		return $run_id;
	}

	/**
	 * List from request data.
	 *
	 * @param mixed $value Value.
	 * @return list<mixed>
	 */
	private static function list( mixed $value ): array {
		return is_array( $value ) ? array_values( $value ) : array();
	}

	/**
	 * Strings from request data.
	 *
	 * @param mixed $value Value.
	 * @return list<string>
	 */
	private static function strings( mixed $value ): array {
		return array_values( array_filter( is_array( $value ) ? $value : array(), 'is_string' ) );
	}
}
