<?php
/**
 * Binary REST response.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Api;

/**
 * REST response whose body is sent as raw bytes (application/octet-stream), not JSON.
 */
final class BinaryResponse extends \WP_REST_Response {

	/**
	 * Body.
	 *
	 * @var string
	 */
	private string $bytes;

	/**
	 * Constructor.
	 *
	 * @param string $bytes Body.
	 */
	public function __construct( string $bytes ) {
		parent::__construct( null, 200, array( 'Content-Type' => 'application/octet-stream' ) );
		$this->bytes = $bytes;
	}

	/**
	 * Body.
	 */
	public function bytes(): string {
		return $this->bytes;
	}
}
