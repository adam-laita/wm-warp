<?php
/**
 * Settings tab.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Admin;

use WM\Warp\Sites\ConnectionStore;
use WM\Warp\Sites\SiteIdentity;
use const WM\Warp\VERSION;

/**
 * Key of this site, sites connected to this site and information about this site.
 */
final class SettingsView {

	/**
	 * Constructor.
	 *
	 * @param SiteIdentity    $identity Identity of this site.
	 * @param ConnectionStore $incoming Sites connected to this site.
	 */
	public function __construct(
		private SiteIdentity $identity,
		private ConnectionStore $incoming
	) {}

	/**
	 * Renders the tab.
	 */
	public function render(): void {
		$this->render_key();
		echo '<div class="wm-warp__grid">';
		$this->render_peers();
		$this->render_facts();
		echo '</div>';
	}

	/**
	 * Key of this site.
	 */
	private function render_key(): void {
		?>
		<section class="wm-warp__card">
			<header class="wm-warp__card-head">
				<h2><?php esc_html_e( 'Key of this site', 'wm-warp' ); ?></h2>
			</header>
			<div class="wm-warp__card-body">
			<p class="description"><?php esc_html_e( 'Paste it on the other site in Connect a new site. The key is used only to make a connection; keep it private, anyone with the key can connect to this site.', 'wm-warp' ); ?></p>
			<div class="wm-warp__key">
				<textarea id="wm-warp-site-key" rows="3" readonly spellcheck="false" aria-label="<?php esc_attr_e( 'Key of this site', 'wm-warp' ); ?>"><?php echo esc_textarea( $this->identity->key()->encode() ); ?></textarea>
				<button type="button" class="button button-primary" data-wm-warp-copy="wm-warp-site-key" data-wm-warp-copied="<?php esc_attr_e( 'Copied', 'wm-warp' ); ?>"><?php esc_html_e( 'Copy', 'wm-warp' ); ?></button>
			</div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-wm-warp-confirm="<?php esc_attr_e( 'Generate a new key? The current key stops working; connected sites keep working.', 'wm-warp' ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( Actions::REGENERATE ); ?>">
				<?php wp_nonce_field( Actions::REGENERATE ); ?>
				<button type="submit" class="button"><span class="dashicons dashicons-update" aria-hidden="true"></span> <?php esc_html_e( 'Generate a new key', 'wm-warp' ); ?></button>
			</form>
			</div>
		</section>
		<?php
	}

	/**
	 * Sites connected to this site.
	 */
	private function render_peers(): void {
		$peers = $this->incoming->all();
		?>
		<section class="wm-warp__card">
			<header class="wm-warp__card-head">
				<h2><?php esc_html_e( 'Sites connected to this site', 'wm-warp' ); ?></h2>
			</header>
			<?php if ( array() === $peers ) : ?>
				<p class="wm-warp__card-body description"><?php esc_html_e( 'No other site is connected to this site.', 'wm-warp' ); ?></p>
			<?php else : ?>
				<table class="wm-warp__table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Site', 'wm-warp' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Connected', 'wm-warp' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Last request', 'wm-warp' ); ?></th>
							<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'wm-warp' ); ?></span></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $peers as $peer ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $peer->host() ); ?></strong> <span class="wm-warp__muted"><?php echo esc_html( $peer->name ); ?></span></td>
							<td><?php echo esc_html( AdminPage::date( $peer->created ) ); ?></td>
							<td><?php echo esc_html( AdminPage::date( $peer->last_seen ) ); ?></td>
							<td class="wm-warp__actions">
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-wm-warp-confirm="<?php echo esc_attr( sprintf( /* translators: %s: name of the site. */ __( 'Remove %s? It will no longer be able to pull from or push to this site.', 'wm-warp' ), $peer->name ) ); ?>">
									<input type="hidden" name="action" value="<?php echo esc_attr( Actions::REMOVE_PEER ); ?>">
									<input type="hidden" name="connection" value="<?php echo esc_attr( $peer->id ); ?>">
									<?php wp_nonce_field( Actions::REMOVE_PEER ); ?>
									<button type="submit" class="button-link wm-warp__danger"><?php esc_html_e( 'Remove', 'wm-warp' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Information about this site.
	 */
	private function render_facts(): void {
		global $wpdb;

		$rows = array(
			__( 'Warp version', 'wm-warp' )      => VERSION,
			__( 'WordPress version', 'wm-warp' ) => get_bloginfo( 'version' ),
			__( 'PHP version', 'wm-warp' )       => PHP_VERSION,
			__( 'Database server', 'wm-warp' )   => (string) $wpdb->db_server_info(),
		);
		?>
		<section class="wm-warp__card">
			<header class="wm-warp__card-head">
				<h2><?php esc_html_e( 'This site', 'wm-warp' ); ?></h2>
			</header>
			<table class="wm-warp__table wm-warp__facts">
				<tbody>
				<?php foreach ( $rows as $label => $value ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $label ); ?></th>
						<td><?php echo esc_html( $value ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</section>
		<?php
	}
}
