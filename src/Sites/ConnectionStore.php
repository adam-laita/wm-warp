<?php
/**
 * Storage of connections.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Sites;

use WM\Warp\Security\Vault;

/**
 * Stores connections in one option; secrets and passwords are sealed by the vault.
 */
final class ConnectionStore {

	public const OUTGOING = 'wm_warp_sites';
	public const INCOMING = 'wm_warp_peers';

	/**
	 * Option name.
	 *
	 * @var string
	 */
	private string $option;

	/**
	 * Vault.
	 *
	 * @var Vault
	 */
	private Vault $vault;

	/**
	 * Constructor.
	 *
	 * @param string $option Option name, self::OUTGOING or self::INCOMING.
	 * @param Vault  $vault  Vault.
	 */
	public function __construct( string $option, Vault $vault ) {
		$this->option = $option;
		$this->vault  = $vault;
	}

	/**
	 * All connections that can be opened, newest first.
	 *
	 * @return list<Connection>
	 */
	public function all(): array {
		$connections = array();

		foreach ( $this->raw() as $record ) {
			$connection = $this->hydrate( $record );

			if ( null !== $connection ) {
				$connections[] = $connection;
			}
		}

		usort( $connections, static fn( Connection $a, Connection $b ): int => $b->created <=> $a->created );

		return $connections;
	}

	/**
	 * Connection by ID.
	 *
	 * @param string $id Connection ID.
	 */
	public function get( string $id ): ?Connection {
		$raw = $this->raw();

		return isset( $raw[ $id ] ) ? $this->hydrate( $raw[ $id ] ) : null;
	}

	/**
	 * Connection to a site by the site ID.
	 *
	 * @param string $site_id Site ID.
	 */
	public function find_by_site( string $site_id ): ?Connection {
		foreach ( $this->all() as $connection ) {
			if ( $connection->site_id === $site_id ) {
				return $connection;
			}
		}

		return null;
	}

	/**
	 * Adds or replaces a connection.
	 *
	 * @param Connection $connection Connection.
	 */
	public function save( Connection $connection ): void {
		$raw                    = $this->raw();
		$raw[ $connection->id ] = array(
			'id'            => $connection->id,
			'site_id'       => $connection->site_id,
			'url'           => $connection->url,
			'rest_url'      => $connection->rest_url,
			'name'          => $connection->name,
			'secret'        => $this->vault->seal( $connection->secret ),
			'created'       => $connection->created,
			'last_seen'     => $connection->last_seen,
			'auth_user'     => $connection->auth_user,
			'auth_password' => '' === $connection->auth_password ? '' : $this->vault->seal( $connection->auth_password ),
			'ip'            => $connection->ip,
			'verify_tls'    => $connection->verify_tls,
			'remote'        => $connection->remote,
		);

		update_option( $this->option, $raw, false );
	}

	/**
	 * Removes a connection.
	 *
	 * @param string $id Connection ID.
	 */
	public function delete( string $id ): void {
		$raw = $this->raw();

		if ( isset( $raw[ $id ] ) ) {
			unset( $raw[ $id ] );
			update_option( $this->option, $raw, false );
		}
	}

	/**
	 * Stored records.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function raw(): array {
		$value   = get_option( $this->option, array() );
		$records = array();

		if ( ! is_array( $value ) ) {
			return $records;
		}

		foreach ( $value as $id => $record ) {
			if ( is_string( $id ) && is_array( $record ) ) {
				$records[ $id ] = $record;
			}
		}

		return $records;
	}

	/**
	 * Connection from a stored record.
	 *
	 * @param array<string, mixed> $record Record.
	 * @return Connection|null Connection, or null when the record is damaged or the secret cannot be opened.
	 */
	private function hydrate( array $record ): ?Connection {
		foreach ( array( 'id', 'site_id', 'url', 'secret' ) as $field ) {
			if ( ! isset( $record[ $field ] ) || ! is_string( $record[ $field ] ) ) {
				return null;
			}
		}

		$secret = $this->vault->open( $record['secret'] );

		if ( null === $secret ) {
			return null;
		}

		$password = isset( $record['auth_password'] ) && is_string( $record['auth_password'] ) && '' !== $record['auth_password']
			? (string) $this->vault->open( $record['auth_password'] )
			: '';

		return new Connection(
			$record['id'],
			$record['site_id'],
			$record['url'],
			self::string( $record, 'rest_url' ),
			self::string( $record, 'name' ),
			$secret,
			self::int( $record, 'created' ),
			self::int( $record, 'last_seen' ),
			self::string( $record, 'auth_user' ),
			$password,
			self::string( $record, 'ip' ),
			! isset( $record['verify_tls'] ) || (bool) $record['verify_tls'],
			isset( $record['remote'] ) && is_array( $record['remote'] ) ? $record['remote'] : array()
		);
	}

	/**
	 * String field of a record.
	 *
	 * @param array<string, mixed> $record Record.
	 * @param string               $field  Field.
	 */
	private static function string( array $record, string $field ): string {
		return isset( $record[ $field ] ) && is_string( $record[ $field ] ) ? $record[ $field ] : '';
	}

	/**
	 * Integer field of a record.
	 *
	 * @param array<string, mixed> $record Record.
	 * @param string               $field  Field.
	 */
	private static function int( array $record, string $field ): int {
		return isset( $record[ $field ] ) && is_int( $record[ $field ] ) ? $record[ $field ] : 0;
	}
}
