<?php
/**
 * Sites tab.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Admin;

use WM\Warp\Sites\ConnectionStore;
use const WM\Warp\VERSION;

/**
 * List of connected sites and the form for connecting a new one.
 */
final class SitesView {

	/**
	 * Constructor.
	 *
	 * @param ConnectionStore $outgoing Sites this site connected to.
	 */
	public function __construct( private ConnectionStore $outgoing ) {}

	/**
	 * Renders the list of sites.
	 */
	public function render_list(): void {
		$connections = $this->outgoing->all();
		$connect_url = AdminPage::url( array( 'view' => 'connect' ) );

		if ( array() === $connections ) {
			?>
			<section class="wm-warp__card wm-warp__empty">
				<span class="wm-warp__empty-icon dashicons dashicons-admin-site-alt3" aria-hidden="true"></span>
				<h2><?php esc_html_e( 'No sites connected yet', 'wm-warp' ); ?></h2>
				<p><?php esc_html_e( 'Connect another site with Warp installed, then pull or push its database and files in a few clicks.', 'wm-warp' ); ?></p>
				<p class="wm-warp__empty-action">
					<a class="button button-primary button-hero" href="<?php echo esc_url( $connect_url ); ?>"><?php esc_html_e( 'Connect a new site', 'wm-warp' ); ?></a>
				</p>
				<p class="description"><?php esc_html_e( 'You will need the key of the other site: Tools → Warp → Settings on that site.', 'wm-warp' ); ?></p>
			</section>
			<?php
			return;
		}
		?>
		<section class="wm-warp__card">
			<header class="wm-warp__card-head">
				<h2>
					<?php esc_html_e( 'Connected sites', 'wm-warp' ); ?>
					<span class="wm-warp__count"><?php echo esc_html( (string) count( $connections ) ); ?></span>
				</h2>
				<a class="button button-primary" href="<?php echo esc_url( $connect_url ); ?>"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> <?php esc_html_e( 'Connect a new site', 'wm-warp' ); ?></a>
			</header>

			<ul class="wm-warp__sites">
			<?php foreach ( $connections as $connection ) : ?>
				<?php
				$remote_version = isset( $connection->remote['version'] ) && is_string( $connection->remote['version'] ) ? $connection->remote['version'] : '';
				$migrate        = static fn( string $direction ): string => AdminPage::url(
					array(
						'view'       => 'migrate',
						'connection' => $connection->id,
						'direction'  => $direction,
					)
				);
				?>
				<li class="wm-warp__site">
					<span class="wm-warp__site-icon dashicons dashicons-admin-site-alt3" aria-hidden="true"></span>
					<div class="wm-warp__site-main">
						<a class="wm-warp__site-host" href="<?php echo esc_url( $connection->url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $connection->host() ); ?></a>
						<?php if ( '' !== $remote_version && VERSION !== $remote_version ) : ?>
							<span class="wm-warp__badge" title="<?php esc_attr_e( 'The other site has a different version of Warp.', 'wm-warp' ); ?>">
								<?php
								/* translators: %s: Warp version on the other site. */
								echo esc_html( sprintf( __( 'Warp %s', 'wm-warp' ), $remote_version ) );
								?>
							</span>
						<?php endif; ?>
						<span class="wm-warp__site-name">
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: name of the site, 2: date of connecting. */
									__( '%1$s · connected %2$s', 'wm-warp' ),
									$connection->name,
									AdminPage::date( $connection->created )
								)
							);
							?>
						</span>
					</div>
					<div class="wm-warp__site-actions">
						<a class="button button-primary wm-warp__go" href="<?php echo esc_url( $migrate( 'pull' ) ); ?>"><span class="dashicons dashicons-download" aria-hidden="true"></span> <?php esc_html_e( 'Pull', 'wm-warp' ); ?></a>
						<a class="button wm-warp__go" href="<?php echo esc_url( $migrate( 'push' ) ); ?>"><span class="dashicons dashicons-upload" aria-hidden="true"></span> <?php esc_html_e( 'Push', 'wm-warp' ); ?></a>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-wm-warp-confirm="<?php echo esc_attr( sprintf( /* translators: %s: name of the site. */ __( 'Disconnect %s? To connect it again you will need its key.', 'wm-warp' ), $connection->host() ) ); ?>">
							<input type="hidden" name="action" value="<?php echo esc_attr( Actions::DISCONNECT ); ?>">
							<input type="hidden" name="connection" value="<?php echo esc_attr( $connection->id ); ?>">
							<?php wp_nonce_field( Actions::DISCONNECT ); ?>
							<button type="submit" class="button-link wm-warp__danger"><?php esc_html_e( 'Disconnect', 'wm-warp' ); ?></button>
						</form>
					</div>
				</li>
			<?php endforeach; ?>
			</ul>
		</section>
		<?php
	}

	/**
	 * Renders the form for connecting a new site.
	 *
	 * @param array{type: string, message: string, field: string, form: array<string, string>, reason: string}|null $flash Result of the last attempt.
	 */
	public function render_connect( ?array $flash ): void {
		$form    = $flash['form'] ?? array();
		$field   = $flash['field'] ?? '';
		$reason  = $flash['reason'] ?? '';
		$ip      = $form['ip'] ?? '';
		$ip_open = '' !== $ip || 'ip' === $field;
		?>
		<p class="wm-warp__back"><a href="<?php echo esc_url( AdminPage::url() ); ?>"><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span> <?php esc_html_e( 'Sites', 'wm-warp' ); ?></a></p>
		<section class="wm-warp__card wm-warp__connect">
			<header class="wm-warp__card-head">
				<h2><?php esc_html_e( 'Connect a new site', 'wm-warp' ); ?></h2>
			</header>

			<form class="wm-warp__card-body" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( Actions::CONNECT ); ?>">
				<?php wp_nonce_field( Actions::CONNECT ); ?>

				<div class="wm-warp__field<?php echo 'key' === $field ? ' wm-warp__field--error' : ''; ?>">
					<label for="wm-warp-key"><?php esc_html_e( 'Key of the other site', 'wm-warp' ); ?></label>
					<textarea id="wm-warp-key" name="key" rows="3" required spellcheck="false" autocomplete="off" aria-describedby="wm-warp-key-help"<?php echo 'key' === $field ? ' aria-invalid="true"' : ''; ?>><?php echo esc_textarea( $form['key'] ?? '' ); ?></textarea>
					<p id="wm-warp-key-help" class="description"><?php esc_html_e( 'On the other site: Tools → Warp → Settings → Copy.', 'wm-warp' ); ?></p>
				</div>

				<fieldset class="wm-warp__field wm-warp__auth<?php echo 'auth' === $field ? ' wm-warp__field--error' : ''; ?>">
					<legend><?php esc_html_e( 'Server password (optional)', 'wm-warp' ); ?></legend>
					<p class="description"><?php esc_html_e( 'Fill in only when the other site is protected by a server password (HTTP authentication), typically a development or staging site.', 'wm-warp' ); ?></p>
					<div class="wm-warp__row">
						<label for="wm-warp-auth-user">
							<span><?php esc_html_e( 'User name', 'wm-warp' ); ?></span>
							<input type="text" id="wm-warp-auth-user" name="auth_user" value="<?php echo esc_attr( $form['auth_user'] ?? '' ); ?>" autocomplete="off"<?php echo 'auth' === $field ? ' aria-invalid="true"' : ''; ?>>
						</label>
						<label for="wm-warp-auth-password">
							<span><?php esc_html_e( 'Password', 'wm-warp' ); ?></span>
							<input type="password" id="wm-warp-auth-password" name="auth_password" value="<?php echo esc_attr( $form['auth_password'] ?? '' ); ?>" autocomplete="new-password"<?php echo 'auth' === $field ? ' aria-invalid="true"' : ''; ?>>
						</label>
					</div>
				</fieldset>

				<details class="wm-warp__field wm-warp__advanced<?php echo 'ip' === $field ? ' wm-warp__field--error' : ''; ?>"<?php echo $ip_open ? ' open' : ''; ?> data-wm-warp-probe data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-action="<?php echo esc_attr( ConnectProbe::ACTION ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( ConnectProbe::ACTION ) ); ?>">
					<summary><?php esc_html_e( 'Moving a site to another hosting with the same domain?', 'wm-warp' ); ?></summary>
					<label for="wm-warp-ip"><?php esc_html_e( 'IP address of the other server', 'wm-warp' ); ?></label>
					<input type="text" id="wm-warp-ip" name="ip" value="<?php echo esc_attr( $ip ); ?>" class="regular-text" inputmode="decimal" autocomplete="off" aria-describedby="wm-warp-ip-help wm-warp-ip-cloudflare"<?php echo 'ip' === $field ? ' aria-invalid="true"' : ''; ?>>
					<p id="wm-warp-ip-help" class="description"><?php esc_html_e( 'Warp then connects to this server instead of the one the domain leads to, like an entry in the hosts file on your computer. Leave empty otherwise.', 'wm-warp' ); ?></p>
					<div id="wm-warp-ip-cloudflare" aria-live="polite">
						<p class="wm-warp__hint" data-wm-warp-probe-ip hidden><?php esc_html_e( 'This IP address belongs to Cloudflare. Enter the IP address of the hosting behind Cloudflare instead.', 'wm-warp' ); ?></p>
						<p class="wm-warp__hint" data-wm-warp-probe-site hidden>
							<?php esc_html_e( 'This site is behind Cloudflare. Enter the IP address of the hosting, not of Cloudflare.', 'wm-warp' ); ?>
							<span class="wm-warp__hint-more"><?php esc_html_e( 'You find it in Cloudflare under DNS as the A record of the domain, or in the details from the hosting.', 'wm-warp' ); ?></span>
						</p>
					</div>
				</details>

				<?php if ( 'tls' === $reason ) : ?>
					<div class="wm-warp__field wm-warp__insecure">
						<label>
							<input type="checkbox" name="allow_insecure" value="1">
							<?php esc_html_e( 'Connect anyway, without checking the certificate', 'wm-warp' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Use only when you know why the certificate is invalid, e.g. a new hosting before the domain is moved. Warp data stay signed and encrypted; only the server password could be exposed.', 'wm-warp' ); ?></p>
					</div>
				<?php endif; ?>

				<p class="wm-warp__buttons">
					<button type="submit" class="button button-primary button-large"><?php esc_html_e( 'Connect', 'wm-warp' ); ?></button>
					<a class="button" href="<?php echo esc_url( AdminPage::url() ); ?>"><?php esc_html_e( 'Cancel', 'wm-warp' ); ?></a>
				</p>
			</form>
		</section>
		<?php
	}
}
