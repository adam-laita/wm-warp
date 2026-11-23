<?php
/**
 * Admin page tabs.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Admin;

/**
 * List of admin tabs and resolution of the requested tab.
 *
 * Pure logic without WordPress calls, so it can be unit tested.
 */
final class Tabs {

	public const DEFAULT_TAB = 'sites';

	/**
	 * Tab slugs => labels, in display order.
	 *
	 * @var array<string, string>
	 */
	private array $tabs;

	/**
	 * Constructor.
	 *
	 * @param array<string, string> $tabs Tab slugs => labels; must contain the default tab.
	 *
	 * @throws \InvalidArgumentException When the default tab is missing.
	 */
	public function __construct( array $tabs ) {
		if ( ! array_key_exists( self::DEFAULT_TAB, $tabs ) ) {
			throw new \InvalidArgumentException( 'The default tab is missing.' );
		}

		$this->tabs = $tabs;
	}

	/**
	 * All tabs, slug => label.
	 *
	 * @return array<string, string>
	 */
	public function all(): array {
		return $this->tabs;
	}

	/**
	 * Returns a known tab slug for a raw request value, falling back to the default tab.
	 *
	 * @param mixed $requested Raw value from the request.
	 */
	public function resolve( mixed $requested ): string {
		if ( is_string( $requested ) && array_key_exists( $requested, $this->tabs ) ) {
			return $requested;
		}

		return self::DEFAULT_TAB;
	}
}
