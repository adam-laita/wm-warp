<?php
/**
 * Test object for serialized data.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Tests\Database;

/**
 * Object with a private property for serialization tests.
 */
final class ReplacerTestObject {

	/**
	 * Constructor.
	 *
	 * @param string $url URL.
	 */
	public function __construct( private string $url ) {}

	/**
	 * URL.
	 */
	public function url(): string {
		return $this->url;
	}
}
