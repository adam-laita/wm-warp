<?php
/**
 * Migration form, progress and result.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Admin;

use WM\Warp\Api\Client;
use WM\Warp\Api\ClientError;
use WM\Warp\Api\Endpoint;
use WM\Warp\Api\Environment;
use WM\Warp\Database\Schema;
use WM\Warp\Files\Groups;
use WM\Warp\Files\Items;
use WM\Warp\Migration\Rules;
use WM\Warp\Migration\Runner;
use WM\Warp\Sites\Connection;
use WM\Warp\Sites\SiteIdentity;

/**
 * Pull or push form of one site. The browser runs the migration through MigrationAjax and shows
 * the progress and the result in the same page.
 */
final class MigrationView {

	/**
	 * Constructor.
	 *
	 * @param SiteIdentity $identity Identity of this site.
	 */
	public function __construct( private SiteIdentity $identity ) {}

	/**
	 * Renders the page.
	 *
	 * @param Connection $connection Other site.
	 * @param string     $direction  Runner::PULL or Runner::PUSH.
	 */
	public function render( Connection $connection, string $direction ): void {
		$pull   = Runner::PULL === $direction;
		$local  = Environment::describe( $this->identity->site_id() );
		$remote = $connection->remote;
		$source = $pull ? $remote : $local;
		$target = $pull ? $local : $remote;
		$host   = $connection->host();
		$error  = '';
		$tables = array();
		$items  = array();

		try {
			$tables = $pull ? $this->remote_tables( $connection ) : Schema::tables();
			$items  = $this->items( $connection, $pull );
		} catch ( ClientError $exception ) {
			$error = $exception->getMessage();
		}

		$prefix   = self::text( $source, 'prefix' );
		$prefixed = array_filter( $tables, static fn( array $table ): bool => str_starts_with( $table['name'], $prefix ) );
		$https    = str_starts_with( self::text( $target, 'home' ), 'https://' );
		$rules    = array_merge(
			Rules::domain( self::text( $source, 'home' ), self::text( $target, 'home' ) ),
			array_slice( Rules::path( self::text( $source, 'path' ), self::text( $target, 'path' ) ), 0, 1 )
		);
		$protocol = Rules::protocol( self::text( $target, 'home' ) );
		$switch   = AdminPage::url(
			array(
				'view'       => 'migrate',
				'connection' => $connection->id,
				'direction'  => $pull ? Runner::PUSH : Runner::PULL,
			)
		);
		?>
		<section class="wm-warp__card wm-warp__migration" data-wm-warp-migration data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( MigrationAjax::ACTION ) ); ?>" data-direction="<?php echo esc_attr( $direction ); ?>" data-leave="<?php esc_attr_e( 'The migration is still running. Leaving the page stops it.', 'wm-warp' ); ?>">
			<div class="wm-warp__migration-head">
				<h2>
					<?php
					echo esc_html(
						$pull
							/* translators: %s: domain of the other site. */
							? sprintf( __( 'Pull from %s', 'wm-warp' ), $host )
							/* translators: %s: domain of the other site. */
							: sprintf( __( 'Push to %s', 'wm-warp' ), $host )
					);
					?>
				</h2>
				<a class="wm-warp__switch" href="<?php echo esc_url( $switch ); ?>"><?php echo esc_html( $pull ? __( 'Switch to Push', 'wm-warp' ) : __( 'Switch to Pull', 'wm-warp' ) ); ?></a>
			</div>
			<p class="wm-warp__lead">
				<?php
				echo esc_html(
					$pull
						/* translators: %s: domain of the other site. */
						? sprintf( __( 'The chosen data of this site will be replaced with the data of %s.', 'wm-warp' ), $host )
						/* translators: %s: domain of the other site. */
						: sprintf( __( 'The chosen data of %s will be replaced with the data of this site.', 'wm-warp' ), $host )
				);
				?>
			</p>

			<?php if ( '' !== $error ) : ?>
				<div class="notice notice-error inline" role="alert"><p><?php echo esc_html( $error ); ?></p></div>
				<p><a class="button" href="<?php echo esc_url( AdminPage::url() ); ?>"><?php esc_html_e( 'Back to sites', 'wm-warp' ); ?></a></p>
			</section>
				<?php
				return;
			endif;
			?>

			<form class="wm-warp__migration-form" data-wm-warp-migration-form>
				<input type="hidden" name="connection" value="<?php echo esc_attr( $connection->id ); ?>">
				<input type="hidden" name="direction" value="<?php echo esc_attr( $direction ); ?>">

				<fieldset class="wm-warp__section" data-wm-warp-group>
					<legend><label><input type="checkbox" name="database" value="1" checked data-wm-warp-group-toggle> <?php esc_html_e( 'Database', 'wm-warp' ); ?></label></legend>
					<div data-wm-warp-group-body>
					<label class="wm-warp__choice">
						<input type="radio" name="mode" value="<?php echo esc_attr( Schema::MODE_PREFIX ); ?>" checked>
						<?php
						/* translators: 1: table prefix, 2: number of tables. */
						echo esc_html( sprintf( __( 'All tables with the prefix %1$s (%2$d)', 'wm-warp' ), $prefix, count( $prefixed ) ) );
						?>
					</label>
					<label class="wm-warp__choice">
						<input type="radio" name="mode" value="<?php echo esc_attr( Schema::MODE_ALL ); ?>">
						<?php
						/* translators: %d: number of tables. */
						echo esc_html( sprintf( __( 'All tables in the database (%d)', 'wm-warp' ), count( $tables ) ) );
						?>
					</label>
					<label class="wm-warp__choice">
						<input type="radio" name="mode" value="<?php echo esc_attr( Schema::MODE_SELECTED ); ?>">
						<?php esc_html_e( 'Only selected tables', 'wm-warp' ); ?>
					</label>
					<div class="wm-warp__tables" data-wm-warp-tables hidden>
						<select name="tables[]" multiple size="12" aria-label="<?php esc_attr_e( 'Tables', 'wm-warp' ); ?>">
							<?php foreach ( $tables as $table ) : ?>
								<option value="<?php echo esc_attr( $table['name'] ); ?>"><?php echo esc_html( $table['name'] . ' · ' . size_format( $table['bytes'], 1 ) ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Hold Ctrl (Cmd on a Mac) to select more tables.', 'wm-warp' ); ?></p>
					</div>
					</div>
				</fieldset>

				<fieldset class="wm-warp__section" data-wm-warp-database-only>
					<legend><?php esc_html_e( 'Find & replace', 'wm-warp' ); ?></legend>
					<p class="description"><?php esc_html_e( 'Addresses and paths of the source are replaced with those of the target, also inside serialized data and JSON. Binary data are never changed.', 'wm-warp' ); ?></p>
					<table class="wm-warp__rules">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Find', 'wm-warp' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Replace with', 'wm-warp' ); ?></th>
								<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Options', 'wm-warp' ); ?></span></th>
							</tr>
						</thead>
						<tbody data-wm-warp-rules>
							<?php if ( array() === $rules ) : ?>
								<tr class="wm-warp__rule wm-warp__rule--auto">
									<td colspan="3"><?php esc_html_e( 'Both sites have the same address and path; nothing needs to be replaced.', 'wm-warp' ); ?></td>
								</tr>
							<?php endif; ?>
							<?php foreach ( $rules as $rule ) : ?>
								<tr class="wm-warp__rule wm-warp__rule--auto">
									<td><code><?php echo esc_html( $rule->find ); ?></code></td>
									<td><code><?php echo esc_html( $rule->replace ); ?></code></td>
									<td><?php esc_html_e( 'automatic', 'wm-warp' ); ?></td>
								</tr>
							<?php endforeach; ?>
							<?php foreach ( $protocol as $rule ) : ?>
								<tr class="wm-warp__rule wm-warp__rule--auto">
									<td><code><?php echo esc_html( $rule->find ); ?></code></td>
									<td><code><?php echo esc_html( $rule->replace ); ?></code></td>
									<td>
										<label>
											<input type="checkbox" name="protocol" value="1"<?php checked( $https ); ?>>
											<?php echo esc_html( $https ? __( 'Convert to HTTPS', 'wm-warp' ) : __( 'Convert to HTTP', 'wm-warp' ) ); ?>
										</label>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<template data-wm-warp-rule-template>
						<tr class="wm-warp__rule">
							<td><input type="text" name="find[]" aria-label="<?php esc_attr_e( 'Find', 'wm-warp' ); ?>" spellcheck="false" autocomplete="off"></td>
							<td><input type="text" name="replace[]" aria-label="<?php esc_attr_e( 'Replace with', 'wm-warp' ); ?>" spellcheck="false" autocomplete="off"></td>
							<td class="wm-warp__rule-options">
								<label class="wm-warp__toggle" title="<?php esc_attr_e( 'Match case: upper and lower case letters must match exactly. Off: “Warp” also finds “warp”.', 'wm-warp' ); ?>">
									<input type="checkbox" data-wm-warp-flag="case"><span aria-hidden="true">Aa</span><span class="screen-reader-text"><?php esc_html_e( 'Match case', 'wm-warp' ); ?></span>
								</label>
								<label class="wm-warp__toggle" title="<?php esc_attr_e( 'Regular expression with delimiters, e.g. /old-(\d+)/; use $1 in the replacement for the matched group.', 'wm-warp' ); ?>">
									<input type="checkbox" data-wm-warp-flag="regex"><span aria-hidden="true">.*</span><span class="screen-reader-text"><?php esc_html_e( 'Regular expression', 'wm-warp' ); ?></span>
								</label>
								<button type="button" class="button-link wm-warp__danger" data-wm-warp-remove-rule><?php esc_html_e( 'Remove', 'wm-warp' ); ?></button>
							</td>
						</tr>
					</template>
					<p><button type="button" class="button" data-wm-warp-add-rule><?php esc_html_e( 'Add a row', 'wm-warp' ); ?></button></p>
				</fieldset>

				<?php $this->render_files( $pull, $items ); ?>

				<p class="wm-warp__buttons">
					<button type="submit" class="button button-primary button-hero"><?php echo esc_html( $pull ? __( 'Pull', 'wm-warp' ) : __( 'Push', 'wm-warp' ) ); ?></button>
					<a class="button" href="<?php echo esc_url( AdminPage::url() ); ?>"><?php esc_html_e( 'Cancel', 'wm-warp' ); ?></a>
				</p>
				<div class="notice notice-error inline" data-wm-warp-form-error hidden role="alert"><p></p></div>
			</form>

			<div class="wm-warp__progress" data-wm-warp-progress hidden>
				<p class="wm-warp__phase" data-wm-warp-phase aria-live="polite"></p>
				<progress max="100" value="0" data-wm-warp-bar></progress>
				<p class="wm-warp__stats"><span data-wm-warp-percent>0 %</span> · <span data-wm-warp-stats></span></p>
				<p><button type="button" class="button" data-wm-warp-cancel><?php esc_html_e( 'Cancel the migration', 'wm-warp' ); ?></button></p>
			</div>

			<div class="wm-warp__result" data-wm-warp-result hidden>
				<h3><?php esc_html_e( 'Done, check the site', 'wm-warp' ); ?></h3>
				<p data-wm-warp-summary></p>
				<ul class="wm-warp__warnings" data-wm-warp-warnings hidden></ul>
				<p class="description">
					<?php
					echo esc_html(
						$pull
							? __( 'The previous tables and files of this site are kept aside. Confirm to delete them, or undo to get the previous state back. The migration is confirmed automatically after 7 days or with the next migration.', 'wm-warp' )
							/* translators: %s: domain of the other site. */
							: sprintf( __( 'The previous tables and files of %s are kept aside. Confirm to delete them, or undo to get the previous state back. The migration is confirmed automatically after 7 days or with the next migration.', 'wm-warp' ), $host )
					);
					?>
				</p>
				<p class="wm-warp__buttons">
					<?php if ( ! $pull ) : ?>
						<a class="button" href="<?php echo esc_url( $connection->url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open the site', 'wm-warp' ); ?></a>
					<?php endif; ?>
					<button type="button" class="button button-primary" data-wm-warp-command="confirm"><?php esc_html_e( 'Confirm', 'wm-warp' ); ?></button>
					<button type="button" class="button" data-wm-warp-command="rollback" data-confirm="<?php esc_attr_e( 'Undo the migration and restore the previous tables and files?', 'wm-warp' ); ?>"><?php esc_html_e( 'Undo', 'wm-warp' ); ?></button>
				</p>
				<p class="wm-warp__logged-out" data-wm-warp-logged-out hidden><?php esc_html_e( 'Your user account does not exist in the pulled database, so you were logged out. Log in with an account of the pulled site to confirm or undo the migration.', 'wm-warp' ); ?></p>
			</div>

			<div class="wm-warp__final" data-wm-warp-final hidden>
				<p data-wm-warp-final-text data-confirmed="<?php esc_attr_e( 'The migration is confirmed.', 'wm-warp' ); ?>" data-rolled-back="<?php esc_attr_e( 'The migration was undone; the previous data are back.', 'wm-warp' ); ?>" data-cancelled="<?php esc_attr_e( 'The migration was cancelled; the target was not changed.', 'wm-warp' ); ?>"></p>
				<p><a class="button" href="<?php echo esc_url( AdminPage::url() ); ?>"><?php esc_html_e( 'Back to sites', 'wm-warp' ); ?></a></p>
			</div>

			<div class="wm-warp__failure" data-wm-warp-failure hidden>
				<div class="notice notice-error inline" role="alert">
					<p><strong><?php esc_html_e( 'The migration failed. The target was not changed.', 'wm-warp' ); ?></strong></p>
					<p data-wm-warp-error-message></p>
					<p data-wm-warp-error-where></p>
				</div>
				<details>
					<summary><?php esc_html_e( 'Technical details', 'wm-warp' ); ?></summary>
					<pre data-wm-warp-error-details></pre>
				</details>
				<p><a class="button" href="<?php echo esc_url( AdminPage::url() ); ?>"><?php esc_html_e( 'Back to sites', 'wm-warp' ); ?></a></p>
			</div>
		</section>
		<?php
	}

	/**
	 * Sections of the file groups: Media, Themes, Plugins. All unchecked by default (4.4).
	 *
	 * @param bool                                      $pull  Whether this is a pull.
	 * @param array<string, list<array<string, mixed>>> $items Plugins and themes: source and target.
	 */
	private function render_files( bool $pull, array $items ): void {
		$php_help = __( 'WordPress and page builders (Elementor, Bricks…) do not need PHP in uploads; they store only images, CSS and fonts there. PHP files in uploads are either empty protection files that plugins create by themselves, or malicious code of a hacked site. Turn this on only if you know your site needs them.', 'wm-warp' );
		?>
		<fieldset class="wm-warp__section" data-wm-warp-group>
			<legend><label><input type="checkbox" name="files_uploads" value="1" data-wm-warp-group-toggle> <?php esc_html_e( 'Media Uploads', 'wm-warp' ); ?></label></legend>
			<div data-wm-warp-group-body hidden>
				<label class="wm-warp__choice">
					<input type="radio" name="uploads_mode" value="sync" checked>
					<?php esc_html_e( 'Synchronize: transfer new and changed files, delete nothing', 'wm-warp' ); ?>
				</label>
				<label class="wm-warp__choice">
					<input type="radio" name="uploads_mode" value="since">
					<?php esc_html_e( 'Only files changed since a date', 'wm-warp' ); ?>
				</label>
				<p class="wm-warp__indent" data-wm-warp-since hidden>
					<label><?php esc_html_e( 'Changed on or after', 'wm-warp' ); ?> <input type="date" name="uploads_since"></label>
				</p>
				<label class="wm-warp__choice">
					<input type="radio" name="uploads_mode" value="mirror">
					<?php esc_html_e( 'Replace all: the target becomes an exact copy, files only on the target are removed', 'wm-warp' ); ?>
				</label>
				<p class="description wm-warp__indent"><?php esc_html_e( 'Only differences are transferred. Replaced and removed files are kept aside until you confirm the migration.', 'wm-warp' ); ?></p>
				<details class="wm-warp__more">
					<summary><?php esc_html_e( 'Excluded files and PHP', 'wm-warp' ); ?></summary>
					<?php $this->render_excludes( 'uploads' ); ?>
					<label class="wm-warp__choice">
						<input type="checkbox" name="uploads_php" value="1">
						<?php esc_html_e( 'Transfer PHP files in uploads', 'wm-warp' ); ?>
					</label>
					<p class="description wm-warp__indent"><?php echo esc_html( $php_help ); ?></p>
				</details>
			</div>
		</fieldset>
		<?php
		$this->render_items(
			'themes',
			__( 'Themes', 'wm-warp' ),
			array(
				'active'   => __( 'Only the active theme (with its parent theme)', 'wm-warp' ),
				'all'      => __( 'All themes', 'wm-warp' ),
				'selected' => __( 'Only selected themes', 'wm-warp' ),
				'except'   => __( 'All themes except the selected ones', 'wm-warp' ),
			),
			$items['themes_source'],
			$items['themes_target']
		);
		$this->render_items(
			'plugins',
			__( 'Plugins', 'wm-warp' ),
			array(
				'all'      => __( 'All plugins', 'wm-warp' ),
				'active'   => __( 'Only active plugins', 'wm-warp' ),
				'selected' => __( 'Only selected plugins', 'wm-warp' ),
				'except'   => __( 'All plugins except the selected ones', 'wm-warp' ),
			),
			$items['plugins_source'],
			$items['plugins_target']
		);
		unset( $pull );
	}

	/**
	 * Section of plugins or themes with the list of the source and the version change on the target.
	 *
	 * @param string                     $group  Group.
	 * @param string                     $title  Title.
	 * @param array<string, string>      $modes  Modes; the first is the default.
	 * @param list<array<string, mixed>> $source Items of the source.
	 * @param list<array<string, mixed>> $target Items of the target.
	 */
	private function render_items( string $group, string $title, array $modes, array $source, array $target ): void {
		$versions = array();
		foreach ( $target as $item ) {
			$versions[ (string) $item['item'] ] = (string) $item['version'];
		}
		$first = (string) array_key_first( $modes );
		?>
		<fieldset class="wm-warp__section" data-wm-warp-group>
			<legend><label><input type="checkbox" name="files_<?php echo esc_attr( $group ); ?>" value="1" data-wm-warp-group-toggle> <?php echo esc_html( $title ); ?></label></legend>
			<div data-wm-warp-group-body data-wm-warp-items hidden>
				<?php foreach ( $modes as $mode => $label ) : ?>
					<label class="wm-warp__choice">
						<input type="radio" name="<?php echo esc_attr( $group ); ?>_mode" value="<?php echo esc_attr( $mode ); ?>"<?php checked( $mode, $first ); ?>>
						<?php echo esc_html( $label ); ?>
					</label>
				<?php endforeach; ?>
				<?php if ( array() === $source ) : ?>
					<p class="description"><?php esc_html_e( 'The source has nothing to transfer here.', 'wm-warp' ); ?></p>
				<?php else : ?>
					<ul class="wm-warp__items">
						<?php foreach ( $source as $item ) : ?>
							<?php
							$name   = (string) $item['item'];
							$status = self::version_status( (string) $item['version'], $versions[ $name ] ?? null );
							$link   = true === ( $item['link'] ?? false );
							?>
							<li class="wm-warp__item<?php echo 'downgrade' === $status['kind'] ? ' wm-warp__item--warning' : ''; ?>" data-active="<?php echo true === ( $item['active'] ?? false ) ? '1' : '0'; ?>">
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $group ); ?>_items[]" value="<?php echo esc_attr( $name ); ?>" data-wm-warp-item-check hidden<?php disabled( $link ); ?>>
									<strong><?php echo esc_html( (string) $item['name'] ); ?></strong>
								</label>
								<span class="wm-warp__item-status"><?php echo esc_html( $link ? __( 'link, never transferred', 'wm-warp' ) : $status['text'] ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<p class="description"><?php esc_html_e( 'Each chosen item is replaced as a whole folder, so no files of the old version stay behind. Items that are only on the target stay as they are.', 'wm-warp' ); ?></p>
				<details class="wm-warp__more">
					<summary><?php esc_html_e( 'Excluded files', 'wm-warp' ); ?></summary>
					<?php $this->render_excludes( $group ); ?>
				</details>
			</div>
		</fieldset>
		<?php
	}

	/**
	 * Field with excluded files.
	 *
	 * @param string $group Group.
	 */
	private function render_excludes( string $group ): void {
		?>
		<p>
			<label for="wm-warp-<?php echo esc_attr( $group ); ?>-excludes"><?php esc_html_e( 'One pattern per line, like .gitignore (e.g. *.log or cache/).', 'wm-warp' ); ?></label>
			<textarea id="wm-warp-<?php echo esc_attr( $group ); ?>-excludes" class="wm-warp__excludes" name="<?php echo esc_attr( $group ); ?>_excludes" rows="4" spellcheck="false"><?php echo esc_textarea( implode( "\n", Groups::default_excludes( $group ) ) ); ?></textarea>
		</p>
		<?php
	}

	/**
	 * What happens to the version of a plugin or theme.
	 *
	 * @param string      $source Version on the source.
	 * @param string|null $target Version on the target, null when it is missing there.
	 * @return array{kind: string, text: string}
	 */
	public static function version_status( string $source, ?string $target ): array {
		if ( null === $target ) {
			return array(
				'kind' => 'new',
				/* translators: %s: version. */
				'text' => sprintf( __( 'new, version %s', 'wm-warp' ), $source ),
			);
		}

		$compare = version_compare( $source, $target );

		if ( 0 === $compare ) {
			return array(
				'kind' => 'same',
				/* translators: %s: version. */
				'text' => sprintf( __( 'version %s on both sites', 'wm-warp' ), $source ),
			);
		}

		return array(
			'kind' => $compare > 0 ? 'update' : 'downgrade',
			'text' => $compare > 0
				/* translators: 1: version on the target, 2: version on the source. */
				? sprintf( __( 'update %1$s → %2$s', 'wm-warp' ), $target, $source )
				/* translators: 1: version on the target, 2: version on the source. */
				: sprintf( __( 'downgrade %1$s → %2$s', 'wm-warp' ), $target, $source ),
		);
	}

	/**
	 * Plugins and themes of both sites.
	 *
	 * @param Connection $connection Other site.
	 * @param bool       $pull       Whether this is a pull.
	 * @return array<string, list<array<string, mixed>>>
	 * @throws ClientError When the other site cannot be reached.
	 */
	private function items( Connection $connection, bool $pull ): array {
		$remote = ( new Client( $this->identity->site_id() ) )->send( Endpoint::of( $connection ), 'files/items', array(), $connection->secret, $connection->id, $connection->site_id );
		$local  = array(
			'plugins' => Items::plugins(),
			'themes'  => Items::themes(),
		);
		$list   = static function ( mixed $items ): array {
			return array_values( array_filter( is_array( $items ) ? $items : array(), static fn( mixed $item ): bool => is_array( $item ) && is_string( $item['item'] ?? null ) ) );
		};

		return array(
			'plugins_source' => $list( $pull ? ( $remote['plugins'] ?? null ) : $local['plugins'] ),
			'plugins_target' => $list( $pull ? $local['plugins'] : ( $remote['plugins'] ?? null ) ),
			'themes_source'  => $list( $pull ? ( $remote['themes'] ?? null ) : $local['themes'] ),
			'themes_target'  => $list( $pull ? $local['themes'] : ( $remote['themes'] ?? null ) ),
		);
	}

	/**
	 * Tables of the other site.
	 *
	 * @param Connection $connection Other site.
	 * @return list<array{name: string, rows: int, bytes: int}>
	 * @throws ClientError When the other site cannot be reached.
	 */
	private function remote_tables( Connection $connection ): array {
		$response = ( new Client( $this->identity->site_id() ) )->send(
			Endpoint::of( $connection ),
			'db/tables',
			array( 'describe' => false ),
			$connection->secret,
			$connection->id,
			$connection->site_id
		);

		$tables = array();
		foreach ( is_array( $response['all'] ?? null ) ? $response['all'] : array() as $table ) {
			if ( is_array( $table ) && is_string( $table['name'] ?? null ) ) {
				$tables[] = array(
					'name'  => $table['name'],
					'rows'  => (int) ( $table['rows'] ?? 0 ),
					'bytes' => (int) ( $table['bytes'] ?? 0 ),
				);
			}
		}

		return $tables;
	}

	/**
	 * Text value of an environment description.
	 *
	 * @param array<string, mixed> $environment Environment.
	 * @param string               $key         Key.
	 */
	private static function text( array $environment, string $key ): string {
		return is_string( $environment[ $key ] ?? null ) ? $environment[ $key ] : '';
	}
}
