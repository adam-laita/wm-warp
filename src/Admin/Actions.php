<?php
/**
 * Form actions of the admin page.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Admin;

use WM\Warp\Api\ClientError;
use WM\Warp\Migration\Target;
use WM\Warp\Migration\Runner;
use WM\Warp\Sites\ConnectionStore;
use WM\Warp\Sites\Connector;
use WM\Warp\Sites\SiteIdentity;
use WM\Warp\Support\Log;

/**
 * Handles admin-post.php actions: connect and disconnect a site, new key, remove a connected site.
 *
 * Every action checks the capability and a nonce, then redirects back to the admin page.
 */
final class Actions {

	public const CONNECT     = 'wm_warp_connect';
	public const DISCONNECT  = 'wm_warp_disconnect';
	public const REGENERATE  = 'wm_warp_regenerate_key';
	public const REMOVE_PEER = 'wm_warp_remove_peer';
	public const PENDING     = 'wm_warp_pending';

	/**
	 * Constructor.
	 *
	 * @param SiteIdentity    $identity Identity of this site.
	 * @param ConnectionStore $outgoing Sites this site connected to.
	 * @param ConnectionStore $incoming Sites connected to this site.
	 * @param Flash           $flash    Messages for the next page view.
	 */
	public function __construct(
		private SiteIdentity $identity,
		private ConnectionStore $outgoing,
		private ConnectionStore $incoming,
		private Flash $flash
	) {}

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::CONNECT, array( $this, 'connect' ) );
		add_action( 'admin_post_' . self::DISCONNECT, array( $this, 'disconnect' ) );
		add_action( 'admin_post_' . self::REGENERATE, array( $this, 'regenerate' ) );
		add_action( 'admin_post_' . self::REMOVE_PEER, array( $this, 'remove_peer' ) );
		add_action( 'admin_post_' . self::PENDING, array( $this, 'pending' ) );
	}

	/**
	 * Confirms or undoes a migration waiting for confirmation (notice on the admin page).
	 *
	 * "run" is a migration started on this site, "import" one another site pushed here.
	 */
	public function pending(): void {
		$this->guard( self::PENDING );

		$command = self::post( 'command' );
		$login   = wp_get_current_user()->user_login;

		if ( ! in_array( $command, array( 'confirm', 'rollback' ), true ) ) {
			$this->flash->set( 'error', __( 'Unknown command.', 'wm-warp' ) );
			$this->redirect();
		}

		try {
			if ( 'run' === self::post( 'scope' ) ) {
				$runner = new Runner( $this->identity, $this->outgoing );
				'rollback' === $command ? $runner->rollback() : $runner->confirm();
			} else {
				'rollback' === $command ? Target::rollback() : Target::confirm();
			}
		} catch ( \Throwable $error ) {
			$this->flash->set( 'error', $error->getMessage() );
			$this->redirect();
		}

		if ( 'rollback' === $command ) {
			// The users table may have changed back; keep the same user logged in.
			MigrationAjax::log_in_again( $login );
		}

		$this->flash->set( 'success', 'rollback' === $command ? __( 'The migration was undone; the previous data are back.', 'wm-warp' ) : __( 'The migration is confirmed.', 'wm-warp' ) );
		$this->redirect();
	}

	/**
	 * Connects a site from the pasted key.
	 */
	public function connect(): void {
		$this->guard( self::CONNECT );

		$form = array(
			'key'           => self::post( 'key' ),
			'auth_user'     => self::post( 'auth_user' ),
			'auth_password' => self::post( 'auth_password', false ),
			'ip'            => self::post( 'ip' ),
		);

		$allow_insecure = self::post( 'allow_insecure' );

		try {
			$connection = ( new Connector( $this->identity, $this->outgoing ) )->connect(
				$form['key'],
				$form['auth_user'],
				$form['auth_password'],
				$form['ip'],
				'1' === $allow_insecure
			);
		} catch ( ClientError $error ) {
			Log::error(
				'connect',
				$error->getMessage(),
				array(
					'reason'  => $error->reason,
					'field'   => $error->field,
					'details' => $error->details + array(
						'auth'     => '' !== $form['auth_user'],
						'insecure' => '1' === $allow_insecure,
					),
				)
			);
			$this->flash->set( 'error', $error->getMessage(), $error->field, $form, $error->reason );
			$this->redirect( array( 'view' => 'connect' ) );
		}

		$this->flash->set(
			'success',
			/* translators: %s: name of the connected site. */
			sprintf( __( 'The site %s is connected.', 'wm-warp' ), $connection->name )
		);
		$this->redirect();
	}

	/**
	 * Disconnects a site.
	 */
	public function disconnect(): void {
		$this->guard( self::DISCONNECT );

		$connection = $this->outgoing->get( self::post( 'connection' ) );

		if ( null !== $connection ) {
			$confirmed = ( new Connector( $this->identity, $this->outgoing ) )->disconnect( $connection );

			if ( $confirmed ) {
				/* translators: %s: name of the site. */
				$this->flash->set( 'success', sprintf( __( 'The site %s is disconnected.', 'wm-warp' ), $connection->name ) );
			} else {
				/* translators: %s: name of the site. */
				$this->flash->set( 'warning', sprintf( __( 'The site %s is disconnected here, but it could not be reached. Remove this site in the Settings tab of the other site as well.', 'wm-warp' ), $connection->name ) );
			}
		}

		$this->redirect();
	}

	/**
	 * Generates a new key of this site.
	 */
	public function regenerate(): void {
		$this->guard( self::REGENERATE );

		$this->identity->regenerate();
		$this->flash->set( 'success', __( 'A new key was generated. The old key no longer works; connected sites keep working.', 'wm-warp' ) );
		$this->redirect( array( 'tab' => 'settings' ) );
	}

	/**
	 * Removes a site connected to this site.
	 */
	public function remove_peer(): void {
		$this->guard( self::REMOVE_PEER );

		$connection = $this->incoming->get( self::post( 'connection' ) );

		if ( null !== $connection ) {
			$this->incoming->delete( $connection->id );
			/* translators: %s: name of the site. */
			$this->flash->set( 'success', sprintf( __( 'The site %s can no longer connect to this site.', 'wm-warp' ), $connection->name ) );
		}

		$this->redirect( array( 'tab' => 'settings' ) );
	}

	/**
	 * Checks the capability and nonce.
	 *
	 * @param string $action Action name.
	 */
	private function guard( string $action ): void {
		if ( ! current_user_can( AdminPage::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wm-warp' ), 403 );
		}

		check_admin_referer( $action );
	}

	/**
	 * Sanitized POST value. The nonce is checked in guard() before any value is read.
	 *
	 * @param string $name     Field name.
	 * @param bool   $sanitize Whether to sanitize as text; passwords are kept as typed.
	 */
	private static function post( string $name, bool $sanitize = true ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in guard().
		if ( ! isset( $_POST[ $name ] ) || ! is_string( $_POST[ $name ] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Checked in guard(); a password must not be altered.
		$value = wp_unslash( $_POST[ $name ] );

		return $sanitize ? trim( sanitize_text_field( $value ) ) : $value;
	}

	/**
	 * Redirects back to the admin page and stops.
	 *
	 * @param array<string, string> $args Query arguments.
	 * @return never
	 */
	private function redirect( array $args = array() ): never {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => AdminPage::SLUG ), $args ), admin_url( 'tools.php' ) ) );
		exit;
	}
}
