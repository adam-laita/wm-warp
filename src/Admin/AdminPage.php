<?php
/**
 * Admin page (Tools → Warp).
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Admin;

use WM\Warp\Plugin;
use const WM\Warp\VERSION;

/**
 * Registers and renders the plugin admin page.
 */
final class AdminPage {

	public const SLUG       = 'wm-warp';
	public const CAPABILITY = 'manage_options';

	/**
	 * Page hook suffix returned by WordPress.
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Adds the page under Tools.
	 */
	public function add_menu(): void {
		$hook_suffix = add_management_page(
			__( 'Warp', 'wm-warp' ),
			__( 'Warp', 'wm-warp' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);

		$this->hook_suffix = is_string( $hook_suffix ) ? $hook_suffix : '';
	}

	/**
	 * Loads styles on the plugin page only.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'wm-warp-admin', Plugin::url( 'assets/css/admin.css' ), array(), VERSION );
	}

	/**
	 * Tabs available in this version.
	 */
	private function tabs(): Tabs {
		return new Tabs(
			array(
				'sites'    => __( 'Sites', 'wm-warp' ),
				'settings' => __( 'Settings', 'wm-warp' ),
			)
		);
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wm-warp' ) );
		}

		$tabs = $this->tabs();
		// Read-only navigation parameter, the value is checked against the list of tabs.
		$current = $tabs->resolve( isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : null ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap wm-warp">
			<header class="wm-warp__header">
				<h1 class="wm-warp__title"><?php esc_html_e( 'Warp', 'wm-warp' ); ?></h1>
				<p class="wm-warp__subtitle"><?php esc_html_e( 'Pull and push the database and files between your WordPress sites.', 'wm-warp' ); ?></p>
			</header>

			<nav class="nav-tab-wrapper wm-warp__tabs" aria-label="<?php esc_attr_e( 'Warp sections', 'wm-warp' ); ?>">
				<?php foreach ( $tabs->all() as $slug => $label ) : ?>
					<a
						href="<?php echo esc_url( $this->tab_url( $slug ) ); ?>"
						class="nav-tab<?php echo $slug === $current ? ' nav-tab-active' : ''; ?>"
						<?php echo $slug === $current ? 'aria-current="page"' : ''; ?>
					><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<div class="wm-warp__content">
				<?php
				if ( 'settings' === $current ) {
					$this->render_settings();
				} else {
					$this->render_sites();
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * URL of a tab.
	 *
	 * @param string $slug Tab slug.
	 */
	private function tab_url( string $slug ): string {
		$args = array( 'page' => self::SLUG );

		if ( Tabs::DEFAULT_TAB !== $slug ) {
			$args['tab'] = $slug;
		}

		return add_query_arg( $args, admin_url( 'tools.php' ) );
	}

	/**
	 * Sites tab. Connected sites come with the pairing step; until then the empty state.
	 */
	private function render_sites(): void {
		?>
		<section class="wm-warp__empty">
			<h2><?php esc_html_e( 'No sites connected yet', 'wm-warp' ); ?></h2>
			<p><?php esc_html_e( 'Connect another site with Warp installed, then pull or push its database and files in a few clicks.', 'wm-warp' ); ?></p>
		</section>
		<?php
	}

	/**
	 * Settings tab: information about this site.
	 */
	private function render_settings(): void {
		global $wpdb;

		$rows = array(
			__( 'Warp version', 'wm-warp' )      => VERSION,
			__( 'WordPress version', 'wm-warp' ) => get_bloginfo( 'version' ),
			__( 'PHP version', 'wm-warp' )       => PHP_VERSION,
			__( 'Database server', 'wm-warp' )   => (string) $wpdb->db_server_info(),
		);
		?>
		<section class="wm-warp__card">
			<h2><?php esc_html_e( 'This site', 'wm-warp' ); ?></h2>
			<table class="wm-warp__facts">
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
