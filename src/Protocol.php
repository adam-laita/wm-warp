<?php
/**
 * Protocol constants shared by both sides of a connection.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp;

/**
 * Version of the protocol between two Warp sites.
 *
 * Sites with a different protocol version refuse to migrate; plugin versions may differ.
 */
final class Protocol {

	public const VERSION = 2;

	public const REST_NAMESPACE = 'wm-warp/v1';

	public const HEADER_CONNECTION = 'X-WM-Warp-Connection';
	public const HEADER_TIME       = 'X-WM-Warp-Time';
	public const HEADER_NONCE      = 'X-WM-Warp-Nonce';
	public const HEADER_SIGNATURE  = 'X-WM-Warp-Signature';
	public const HEADER_SITE       = 'X-WM-Warp-Site';
	public const HEADER_PROTOCOL   = 'X-WM-Warp-Protocol';

	/**
	 * Connection header value of the pairing request, which is signed with the site key.
	 */
	public const PAIRING_CONNECTION = 'pair';

	/**
	 * Allowed clock difference between the two sites, in seconds.
	 */
	public const TIME_WINDOW = 300;
}
