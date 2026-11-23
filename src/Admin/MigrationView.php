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

		try {
			$tables = $pull ? $this->remote_tables( $connection ) : Schema::tables();
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
						? sprintf( __( 'The database of this site will be replaced with the data of %s.', 'wm-warp' ), $host )
						/* translators: %s: domain of the other site. */
						: sprintf( __( 'The database of %s will be replaced with the data of this site.', 'wm-warp' ), $host )
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

				<fieldset class="wm-warp__section">
					<legend><?php esc_html_e( 'Database', 'wm-warp' ); ?></legend>
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
				</fieldset>

				<fieldset class="wm-warp__section">
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
				<p class="description">
					<?php
					echo esc_html(
						$pull
							? __( 'The previous tables of this site are kept aside. Confirm to delete them, or undo to get the previous state back. The migration is confirmed automatically after 7 days or with the next migration.', 'wm-warp' )
							/* translators: %s: domain of the other site. */
							: sprintf( __( 'The previous tables of %s are kept aside. Confirm to delete them, or undo to get the previous state back. The migration is confirmed automatically after 7 days or with the next migration.', 'wm-warp' ), $host )
					);
					?>
				</p>
				<p class="wm-warp__buttons">
					<?php if ( ! $pull ) : ?>
						<a class="button" href="<?php echo esc_url( $connection->url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open the site', 'wm-warp' ); ?></a>
					<?php endif; ?>
					<button type="button" class="button button-primary" data-wm-warp-command="confirm"><?php esc_html_e( 'Confirm', 'wm-warp' ); ?></button>
					<button type="button" class="button" data-wm-warp-command="rollback" data-confirm="<?php esc_attr_e( 'Undo the migration and restore the previous tables?', 'wm-warp' ); ?>"><?php esc_html_e( 'Undo', 'wm-warp' ); ?></button>
				</p>
				<p class="wm-warp__logged-out" data-wm-warp-logged-out hidden><?php esc_html_e( 'Your user account does not exist in the pulled database, so you were logged out. Log in with an account of the pulled site to confirm or undo the migration.', 'wm-warp' ); ?></p>
			</div>

			<div class="wm-warp__final" data-wm-warp-final hidden>
				<p data-wm-warp-final-text data-confirmed="<?php esc_attr_e( 'The migration is confirmed.', 'wm-warp' ); ?>" data-rolled-back="<?php esc_attr_e( 'The migration was undone; the previous tables are back.', 'wm-warp' ); ?>" data-cancelled="<?php esc_attr_e( 'The migration was cancelled; the target was not changed.', 'wm-warp' ); ?>"></p>
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
