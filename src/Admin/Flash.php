<?php
/**
 * Messages and form values carried over a redirect.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Admin;

use WM\Warp\Security\Vault;

/**
 * Stores the result of an admin action for the current user until the next page view.
 *
 * Form values are kept so a failed form does not have to be filled in again; the password is sealed.
 */
final class Flash {

	private const TTL = 10 * MINUTE_IN_SECONDS;

	/**
	 * Vault.
	 *
	 * @var Vault
	 */
	private Vault $vault;

	/**
	 * Constructor.
	 *
	 * @param Vault $vault Vault.
	 */
	public function __construct( Vault $vault ) {
		$this->vault = $vault;
	}

	/**
	 * Stores a message.
	 *
	 * @param string                $type    "success", "error" or "warning".
	 * @param string                $message Translated message.
	 * @param string                $field   Form field to highlight.
	 * @param array<string, string> $form    Form values to fill in again.
	 * @param string                $reason  Machine readable reason of an error.
	 */
	public function set( string $type, string $message, string $field = '', array $form = array(), string $reason = '' ): void {
		if ( isset( $form['auth_password'] ) && '' !== $form['auth_password'] ) {
			$form['auth_password'] = $this->vault->seal( $form['auth_password'] );
		}

		set_transient(
			$this->key(),
			array(
				'type'    => $type,
				'message' => $message,
				'field'   => $field,
				'form'    => $form,
				'reason'  => $reason,
			),
			self::TTL
		);
	}

	/**
	 * Takes the stored message, if any.
	 *
	 * @return array{type: string, message: string, field: string, form: array<string, string>, reason: string}|null
	 */
	public function take(): ?array {
		$data = get_transient( $this->key() );
		delete_transient( $this->key() );

		if ( ! is_array( $data ) || ! isset( $data['type'], $data['message'] ) || ! is_string( $data['type'] ) || ! is_string( $data['message'] ) ) {
			return null;
		}

		$form = array();
		if ( isset( $data['form'] ) && is_array( $data['form'] ) ) {
			foreach ( $data['form'] as $name => $value ) {
				if ( is_string( $name ) && is_string( $value ) ) {
					$form[ $name ] = $value;
				}
			}
		}

		if ( isset( $form['auth_password'] ) && '' !== $form['auth_password'] ) {
			$form['auth_password'] = (string) $this->vault->open( $form['auth_password'] );
		}

		return array(
			'type'    => $data['type'],
			'message' => $data['message'],
			'field'   => isset( $data['field'] ) && is_string( $data['field'] ) ? $data['field'] : '',
			'form'    => $form,
			'reason'  => isset( $data['reason'] ) && is_string( $data['reason'] ) ? $data['reason'] : '',
		);
	}

	/**
	 * Transient name for the current user.
	 */
	private function key(): string {
		return 'wm_warp_flash_' . get_current_user_id();
	}
}
