<?php
/**
 * Failed request to another site.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Api;

/**
 * Error with a human readable, translated message for the user.
 */
final class ClientError extends \RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string               $reason  Machine readable reason, e.g. "basic_auth".
	 * @param string               $message Translated message for the user.
	 * @param string               $field   Form field the user should look at ("key", "auth", "ip"), or empty.
	 * @param array<string, mixed> $details Technical details for the log.
	 */
	public function __construct(
		public readonly string $reason,
		string $message,
		public readonly string $field = '',
		public readonly array $details = array()
	) {
		parent::__construct( $message );
	}
}
