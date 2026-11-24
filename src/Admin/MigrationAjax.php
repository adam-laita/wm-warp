<?php
/**
 * Migration requests of the admin page.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Admin;

use WM\Warp\Api\ClientError;
use WM\Warp\Database\ReplaceRule;
use WM\Warp\Database\Schema;
use WM\Warp\Files\Excludes;
use WM\Warp\Files\Groups;
use WM\Warp\Migration\Runner;
use WM\Warp\Sites\ConnectionStore;
use WM\Warp\Sites\SiteIdentity;
use WM\Warp\Support\Log;

/**
 * Endpoint of admin-ajax.php the browser uses to run a migration step by step:
 * start, step, cancel, confirm, rollback. Every request checks the capability and the nonce.
 */
final class MigrationAjax {

	public const ACTION = 'wm_warp_migration';

	/**
	 * Constructor.
	 *
	 * @param SiteIdentity    $identity Identity of this site.
	 * @param ConnectionStore $outgoing Sites this site connected to.
	 */
	public function __construct( private SiteIdentity $identity, private ConnectionStore $outgoing ) {}

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Handles a request.
	 *
	 * @throws \RuntimeException When the command is unknown (sent back as an error).
	 */
	public function handle(): void {
		if ( ! current_user_can( AdminPage::CAPABILITY ) || false === check_ajax_referer( self::ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Your session has expired. Reload the page.', 'wm-warp' ) ), 403 );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Migration steps.
		}
		wp_raise_memory_limit( 'admin' );

		$command = isset( $_POST['command'] ) ? sanitize_key( wp_unslash( $_POST['command'] ) ) : '';
		$runner  = new Runner( $this->identity, $this->outgoing );
		$login   = wp_get_current_user()->user_login;

		try {
			$state = match ( $command ) {
				'start'    => $this->start( $runner ),
				'step'     => $runner->step(),
				'cancel'   => $runner->cancel(),
				'confirm'  => $runner->confirm(),
				'rollback' => $runner->rollback(),
				default    => throw new \RuntimeException( __( 'Unknown command.', 'wm-warp' ) ),
			};
		} catch ( \Throwable $error ) {
			Log::error(
				'migration',
				$error->getMessage(),
				array(
					'command' => $command,
					'reason'  => $error instanceof ClientError ? $error->reason : '',
					'details' => array(
						'exception' => get_class( $error ),
						'where'     => basename( $error->getFile() ) . ':' . $error->getLine(),
					) + ( $error instanceof ClientError ? $error->details : array() ),
				)
			);
			wp_send_json_error( array( 'message' => $error->getMessage() ) );
		}

		$progress = self::progress( $state );

		// A pull replaced the users of this site: log the same user in again, with a new nonce.
		if ( Runner::PULL === $state['direction'] && in_array( $command, array( 'step', 'rollback' ), true ) && in_array( $state['status'], array( Runner::DONE, Runner::ROLLED_BACK ), true ) ) {
			$progress['nonce']     = self::log_in_again( $login );
			$progress['logged_in'] = '' !== $progress['nonce'];
		}

		wp_send_json_success( $progress );
	}

	/**
	 * Starts a migration from the submitted form.
	 *
	 * @param Runner $runner Runner.
	 * @return array<string, mixed>
	 * @throws \RuntimeException When the form is invalid.
	 */
	private function start( Runner $runner ): array {
		// Nonce checked in handle().
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$connection = $this->outgoing->get( isset( $_POST['connection'] ) ? sanitize_key( wp_unslash( $_POST['connection'] ) ) : '' );
		$direction  = isset( $_POST['direction'] ) && Runner::PUSH === $_POST['direction'] ? Runner::PUSH : Runner::PULL;
		$database   = ! empty( $_POST['database'] );
		$mode       = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : Schema::MODE_PREFIX;
		$selected   = isset( $_POST['tables'] ) && is_array( $_POST['tables'] ) ? array_values( array_map( 'sanitize_text_field', wp_unslash( $_POST['tables'] ) ) ) : array();
		$protocol   = ! empty( $_POST['protocol'] );
		$finds      = isset( $_POST['find'] ) && is_array( $_POST['find'] ) ? array_values( wp_unslash( $_POST['find'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Search text is used as data, exactly as typed.
		$replaces   = isset( $_POST['replace'] ) && is_array( $_POST['replace'] ) ? array_values( wp_unslash( $_POST['replace'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Replacement is used as data, exactly as typed.
		$cases      = isset( $_POST['case'] ) && is_array( $_POST['case'] ) ? array_map( 'absint', wp_unslash( $_POST['case'] ) ) : array();
		$regexes    = isset( $_POST['regex'] ) && is_array( $_POST['regex'] ) ? array_map( 'absint', wp_unslash( $_POST['regex'] ) ) : array();
		// phpcs:enable

		if ( null === $connection ) {
			throw new \RuntimeException( __( 'The site is no longer connected.', 'wm-warp' ) );
		}

		if ( ! in_array( $mode, array( Schema::MODE_PREFIX, Schema::MODE_ALL, Schema::MODE_SELECTED ), true ) ) {
			$mode = Schema::MODE_PREFIX;
		}

		if ( $database && Schema::MODE_SELECTED === $mode && array() === $selected ) {
			throw new \RuntimeException( __( 'Choose at least one table.', 'wm-warp' ) );
		}

		$custom = array();
		foreach ( $finds as $index => $find ) {
			$find    = is_string( $find ) ? $find : '';
			$replace = is_string( $replaces[ $index ] ?? null ) ? $replaces[ $index ] : '';
			$regex   = in_array( $index, $regexes, true );

			if ( '' === $find ) {
				continue;
			}

			if ( $regex && ! ReplaceRule::is_valid_regex( $find ) ) {
				/* translators: %s: regular expression entered by the user. */
				throw new \RuntimeException( sprintf( __( 'The regular expression %s is not valid. Write it with delimiters, e.g. /old-(\d+)/.', 'wm-warp' ), $find ) );
			}

			$custom[] = new ReplaceRule( $find, $replace, $regex, in_array( $index, $cases, true ) );
		}

		return $runner->start( $connection, $direction, $database, $mode, $selected, $protocol, $custom, self::files() );
	}

	/**
	 * File groups chosen in the form.
	 *
	 * @return list<array{group: string, mode: string, selected: list<string>, since: int, excludes: list<string>, allow_php: bool}>
	 * @throws \RuntimeException When the date is missing.
	 */
	private static function files(): array {
		// Nonce checked in handle().
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$files = array();

		foreach ( Groups::ALL as $group ) {
			if ( empty( $_POST[ 'files_' . $group ] ) ) {
				continue;
			}

			$mode     = isset( $_POST[ $group . '_mode' ] ) ? sanitize_key( wp_unslash( $_POST[ $group . '_mode' ] ) ) : '';
			$selected = isset( $_POST[ $group . '_items' ] ) && is_array( $_POST[ $group . '_items' ] ) ? array_values( array_map( 'sanitize_text_field', wp_unslash( $_POST[ $group . '_items' ] ) ) ) : array();
			$excludes = isset( $_POST[ $group . '_excludes' ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $group . '_excludes' ] ) ) : '';
			$markdown = isset( $_POST[ $group . '_markdown' ] ) ? sanitize_key( wp_unslash( $_POST[ $group . '_markdown' ] ) ) : '';
			$since    = 0;

			if ( Groups::UPLOADS === $group ) {
				$mode = in_array( $mode, array( 'sync', 'since', 'mirror' ), true ) ? $mode : 'sync';

				if ( 'since' === $mode ) {
					$date  = isset( $_POST['uploads_since'] ) ? sanitize_text_field( wp_unslash( $_POST['uploads_since'] ) ) : '';
					$since = 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? (int) ( new \DateTimeImmutable( $date, wp_timezone() ) )->getTimestamp() : 0;

					if ( 0 === $since ) {
						throw new \RuntimeException( __( 'Enter the date from which media files are transferred.', 'wm-warp' ) );
					}
				}
			} else {
				$default = Groups::THEMES === $group ? 'active' : 'all';
				$mode    = in_array( $mode, array( 'all', 'active', 'selected', 'except' ), true ) ? $mode : $default;
			}

			$files[] = array(
				'group'     => $group,
				'mode'      => $mode,
				'selected'  => $selected,
				'since'     => $since,
				'excludes'  => array_merge( Groups::markdown_excludes( $markdown ), Excludes::lines( $excludes ) ),
				'allow_php' => Groups::UPLOADS === $group && ! empty( $_POST['uploads_php'] ),
			);
		}
		// phpcs:enable

		return $files;
	}

	/**
	 * Logs the user in again after the users table was replaced.
	 *
	 * @param string $login Login of the user who started the migration.
	 * @return string New nonce, empty when the user does not exist on this site any more.
	 */
	public static function log_in_again( string $login ): string {
		wp_cache_flush();

		$user = get_user_by( 'login', $login );

		if ( ! $user instanceof \WP_User || ! user_can( $user, AdminPage::CAPABILITY ) ) {
			return '';
		}

		$capture = static function ( string $cookie ): void {
			$_COOKIE[ LOGGED_IN_COOKIE ] = $cookie;
		};

		add_action( 'set_logged_in_cookie', $capture );
		wp_set_auth_cookie( $user->ID, false, is_ssl() );
		remove_action( 'set_logged_in_cookie', $capture );
		wp_set_current_user( $user->ID );

		return wp_create_nonce( self::ACTION );
	}

	/**
	 * Progress for the browser.
	 *
	 * @param array<string, mixed> $state Runner state.
	 * @return array<string, mixed>
	 */
	public static function progress( array $state ): array {
		$tables  = is_array( $state['tables'] ?? null ) ? $state['tables'] : array();
		$files   = is_array( $state['files'] ?? null ) ? $state['files'] : null;
		$current = (string) ( $tables[ (int) $state['index'] ]['info']['name'] ?? '' );
		$status  = (string) $state['status'];
		$percent = Runner::DONE === $status || Runner::CONFIRMED === $status || Runner::ROLLED_BACK === $status ? 100 : (int) ( $state['percent'] ?? 0 );
		$end     = (int) $state['finished'] > 0 ? (int) $state['finished'] : time();
		$error   = is_array( $state['error'] ?? null ) ? $state['error'] : null;

		return array(
			'status'   => $status,
			'percent'  => $percent,
			'phase'    => self::phase_text( $state, $current, count( $tables ) ),
			'stats'    => self::stats_text( (int) $state['bytes'], max( 1, $end - (int) $state['started'] ) ),
			'summary'  => self::summary_text( $state ),
			'warnings' => null === $files || Runner::RUNNING === $status ? array() : array_values( array_map( 'strval', array_slice( (array) $files['warnings'], 0, 50 ) ) ),
			'error'    => null === $error ? null : array(
				'message' => (string) $error['message'],
				'where'   => '' !== (string) $error['table']
					/* translators: %s: table name. */
					? sprintf( __( 'Table %s', 'wm-warp' ), (string) $error['table'] )
					: '',
				'details' => (string) wp_json_encode( $error['details'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			),
		);
	}

	/**
	 * What is happening now.
	 *
	 * @param array<string, mixed> $state   State.
	 * @param string               $current Current table.
	 * @param int                  $count   Number of tables.
	 */
	private static function phase_text( array $state, string $current, int $count ): string {
		return match ( (string) $state['phase'] ) {
			'prepare' => __( 'Preparing…', 'wm-warp' ),
			/* translators: 1: table name, 2: number of the table, 3: number of tables. */
			'tables'  => sprintf( __( 'Transferring %1$s (%2$d of %3$d)', 'wm-warp' ), $current, min( $count, (int) $state['index'] + 1 ), $count ),
			'files'   => self::files_text( is_array( $state['files'] ?? null ) ? $state['files'] : array() ),
			'swap'    => __( 'Replacing the files…', 'wm-warp' ),
			'finish'  => false === ( $state['database'] ?? true ) ? __( 'Finishing…', 'wm-warp' ) : __( 'Checking the data and replacing the tables…', 'wm-warp' ),
			default   => '',
		};
	}

	/**
	 * What is happening with the files now.
	 *
	 * @param array<string, mixed> $files File state.
	 */
	private static function files_text( array $files ): string {
		return match ( (string) ( $files['step'] ?? '' ) ) {
			/* translators: %s: number of files. */
			'scan'     => sprintf( __( 'Comparing files on both sites (%s found)…', 'wm-warp' ), self::number( (int) $files['scanned'] ) ),
			/* translators: 1: number of checked files, 2: number of files to check. */
			'compare', 'hash' => sprintf( __( 'Comparing contents of files (%1$s of %2$s)…', 'wm-warp' ), self::number( (int) $files['ci'] ), self::number( (int) $files['checks'] ) ),
			/* translators: 1: number of transferred files, 2: number of files to transfer. */
			'transfer' => sprintf( __( 'Transferring files (%1$s of %2$s)', 'wm-warp' ), self::number( (int) $files['moved'] ), self::number( (int) $files['count'] ) ),
			default    => __( 'Preparing plugins and themes…', 'wm-warp' ),
		};
	}

	/**
	 * Transferred data, time and speed.
	 *
	 * @param int $bytes   Bytes.
	 * @param int $seconds Seconds.
	 */
	private static function stats_text( int $bytes, int $seconds ): string {
		/* translators: 1: data size, 2: duration, 3: speed per second. */
		return sprintf( __( '%1$s in %2$s (%3$s/s)', 'wm-warp' ), self::size( $bytes ), self::duration( $seconds ), self::size( (int) ( $bytes / $seconds ) ) );
	}

	/**
	 * Result of a finished migration.
	 *
	 * @param array<string, mixed> $state State.
	 */
	private static function summary_text( array $state ): string {
		$summary = is_array( $state['summary'] ?? null ) ? $state['summary'] : null;
		$files   = is_array( $state['files'] ?? null ) && is_array( $state['files']['result'] ?? null ) ? $state['files']['result'] : null;

		if ( null === $summary && null === $files ) {
			return '';
		}

		$seconds = max( 1, (int) $state['finished'] - (int) $state['started'] );
		/* translators: 1: data size, 2: duration. */
		$text = sprintf( __( 'Transferred %1$s in %2$s.', 'wm-warp' ), self::size( (int) $state['bytes'] ), self::duration( $seconds ) );

		if ( null !== $summary ) {
			$tables = (int) ( $summary['tables'] ?? 0 );
			$rows   = (int) ( $summary['rows'] ?? 0 );
			$parts  = array(
				/* translators: %s: number of tables. */
				sprintf( _n( '%s table', '%s tables', $tables, 'wm-warp' ), self::number( $tables ) ),
				/* translators: %s: number of rows. */
				sprintf( _n( '%s row', '%s rows', $rows, 'wm-warp' ), self::number( $rows ) ),
			);
			/* translators: %s: list of counts, e.g. "116 tables, 333,753 rows". */
			$text .= ' ' . sprintf( __( 'Database: %s.', 'wm-warp' ), implode( ', ', $parts ) );
		}

		if ( null !== $files ) {
			$changed = (int) $files['files'];
			$deleted = (int) $files['deleted'];
			$items   = (int) $files['items'];
			$parts   = array(
				/* translators: %s: number of files. */
				sprintf( _n( '%s file changed', '%s files changed', $changed, 'wm-warp' ), self::number( $changed ) ),
				/* translators: %s: number of files. */
				sprintf( _n( '%s file deleted', '%s files deleted', $deleted, 'wm-warp' ), self::number( $deleted ) ),
			);
			if ( $items > 0 ) {
				/* translators: %s: number of plugins and themes. */
				$parts[] = sprintf( _n( '%s plugin or theme replaced', '%s plugins and themes replaced', $items, 'wm-warp' ), self::number( $items ) );
			}
			/* translators: %s: list of counts, e.g. "12 files changed, 0 files deleted". */
			$text .= ' ' . sprintf( __( 'Files: %s.', 'wm-warp' ), implode( ', ', $parts ) );
		}

		if ( null === $summary ) {
			return $text;
		}

		$collations = is_array( $summary['collations'] ?? null ) ? $summary['collations'] : array();
		foreach ( $collations as $from => $to ) {
			/* translators: 1: collation of the source, 2: collation used instead. */
			$text .= ' ' . sprintf( __( 'The target does not know the collation %1$s, %2$s is used instead.', 'wm-warp' ), (string) $from, is_string( $to ) ? $to : '' );
		}

		return $text;
	}

	/**
	 * Number formatted for the locale as plain text (the browser shows it with textContent).
	 *
	 * @param int $number Number.
	 */
	private static function number( int $number ): string {
		return html_entity_decode( number_format_i18n( $number ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Data size as plain text.
	 *
	 * @param int $bytes Bytes.
	 */
	private static function size( int $bytes ): string {
		return html_entity_decode( (string) size_format( $bytes, 1 ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Human readable duration.
	 *
	 * @param int $seconds Seconds.
	 */
	public static function duration( int $seconds ): string {
		if ( $seconds < 60 ) {
			/* translators: %d: seconds. */
			return sprintf( __( '%d s', 'wm-warp' ), $seconds );
		}

		/* translators: 1: minutes, 2: seconds. */
		return sprintf( __( '%1$d min %2$d s', 'wm-warp' ), intdiv( $seconds, 60 ), $seconds % 60 );
	}
}
