<?php
/**
 * Limit of failed requests.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Api;

/**
 * Temporarily blocks an IP address after repeated requests with a wrong signature.
 */
final class RateLimiter {

	private const MAX_FAILURES = 20;
	private const PERIOD       = 15 * MINUTE_IN_SECONDS;

	/**
	 * Whether the address is blocked.
	 *
	 * @param string $ip Client IP address.
	 */
	public function is_blocked( string $ip ): bool {
		return $this->failures( $ip ) >= self::MAX_FAILURES;
	}

	/**
	 * Records a failed request.
	 *
	 * @param string $ip Client IP address.
	 */
	public function fail( string $ip ): void {
		set_transient( $this->key( $ip ), $this->failures( $ip ) + 1, self::PERIOD );
	}

	/**
	 * Number of recent failures.
	 *
	 * @param string $ip Client IP address.
	 */
	private function failures( string $ip ): int {
		$value = get_transient( $this->key( $ip ) );

		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * Transient name.
	 *
	 * @param string $ip Client IP address.
	 */
	private function key( string $ip ): string {
		return 'wm_warp_fail_' . md5( $ip );
	}
}
