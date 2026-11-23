<?php
/**
 * Admin page (Tools → Warp).
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Admin;

use WM\Warp\Plugin;
use WM\Warp\Sites\ConnectionStore;
use WM\Warp\Sites\SiteIdentity;
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
	 * Constructor.
	 *
	 * @param SiteIdentity    $identity Identity of this site.
	 * @param ConnectionStore $outgoing Sites this site connected to.
	 * @param ConnectionStore $incoming Sites connected to this site.
	 * @param Flash           $flash    Messages from the last action.
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
	 * Loads styles and scripts on the plugin page only.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'wm-warp-admin', Plugin::url( 'assets/css/admin.css' ), array(), self::asset_version( 'assets/css/admin.css' ) );
		wp_enqueue_script(
			'wm-warp-admin',
			Plugin::url( 'assets/js/admin.js' ),
			array(),
			self::asset_version( 'assets/js/admin.js' ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/**
	 * Version of an asset for cache busting: plugin version and file modification time.
	 *
	 * @param string $path Path relative to the plugin folder.
	 */
	private static function asset_version( string $path ): string {
		$mtime = filemtime( dirname( \WM\Warp\PLUGIN_FILE ) . '/' . $path );

		return VERSION . ( false === $mtime ? '' : '.' . $mtime );
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
		// Read-only navigation parameters, checked against known values.
		$current = $tabs->resolve( isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : null ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$view    = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$flash   = $this->flash->take();
		?>
		<div class="wrap wm-warp">
			<header class="wm-warp__header">
				<h1 class="wm-warp__title"><?php esc_html_e( 'Warp', 'wm-warp' ); ?></h1>
				<p class="wm-warp__subtitle"><?php esc_html_e( 'Pull and push the database and files between your WordPress sites.', 'wm-warp' ); ?></p>
			</header>

			<nav class="nav-tab-wrapper wm-warp__tabs" aria-label="<?php esc_attr_e( 'Warp sections', 'wm-warp' ); ?>">
				<?php foreach ( $tabs->all() as $slug => $label ) : ?>
					<a
						href="<?php echo esc_url( self::url( 'sites' === $slug ? array() : array( 'tab' => $slug ) ) ); ?>"
						class="nav-tab<?php echo $slug === $current ? ' nav-tab-active' : ''; ?>"
						<?php echo $slug === $current ? 'aria-current="page"' : ''; ?>
					><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<?php if ( null !== $flash ) : ?>
				<div class="notice notice-<?php echo esc_attr( $flash['type'] ); ?> inline wm-warp__notice" role="<?php echo 'error' === $flash['type'] ? 'alert' : 'status'; ?>">
					<p><?php echo esc_html( $flash['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<div class="wm-warp__content">
				<?php
				if ( 'settings' === $current ) {
					( new SettingsView( $this->identity, $this->incoming ) )->render();
				} elseif ( 'connect' === $view ) {
					( new SitesView( $this->outgoing ) )->render_connect( $flash );
				} else {
					( new SitesView( $this->outgoing ) )->render_list();
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * URL of the admin page.
	 *
	 * @param array<string, string> $args Query arguments.
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'tools.php' ) );
	}

	/**
	 * Formats a time for display.
	 *
	 * @param int $time Unix time; 0 for never.
	 */
	public static function date( int $time ): string {
		if ( 0 === $time ) {
			return '—';
		}

		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time );
	}
}
