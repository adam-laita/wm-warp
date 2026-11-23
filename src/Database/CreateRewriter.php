<?php
/**
 * Adapting a CREATE TABLE statement to the target server.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Database;

/**
 * Turns the CREATE TABLE of the source into one for a temporary table on the target:
 * - foreign keys are taken out and added after the tables are swapped (their names are unique per database),
 * - names of CHECK constraints are dropped for the same reason,
 * - collations unknown to the target are replaced by the closest one (utf8mb4_0900_ai_ci on MariaDB, F2),
 * - MySQL-only and MariaDB-only table options are removed.
 * Pure logic without WordPress calls.
 */
final class CreateRewriter {

	/**
	 * Rewrites a statement.
	 *
	 * @param string   $create        CREATE TABLE of the source.
	 * @param string   $temp          Name of the temporary table.
	 * @param bool     $mariadb       Whether the target is MariaDB.
	 * @param callable $has_collation Whether the target knows a collation: fn( string ): bool.
	 * @param string   $source_prefix Table prefix of the source.
	 * @param string   $target_prefix Table prefix of the target.
	 * @return array{create: string, foreign_keys: list<string>, collations: array<string, string>}
	 */
	public static function rewrite( string $create, string $temp, bool $mariadb, callable $has_collation, string $source_prefix, string $target_prefix ): array {
		$create = (string) preg_replace( '/^\s*CREATE\s+TABLE\s+`(?:[^`]|``)+`/i', 'CREATE TABLE `' . str_replace( '`', '``', $temp ) . '`', $create, 1 );

		$lines        = explode( "\n", $create );
		$kept         = array();
		$foreign_keys = array();

		foreach ( $lines as $line ) {
			if ( 1 === preg_match( '/^\s*CONSTRAINT\s+`(?:[^`]|``)+`\s+FOREIGN\s+KEY\b/i', $line ) ) {
				$clause         = rtrim( trim( $line ), ',' );
				$foreign_keys[] = (string) preg_replace_callback(
					'/\bREFERENCES\s+`((?:[^`]|``)+)`/i',
					static fn( array $found ): string => 'REFERENCES `' . str_replace( '`', '``', TableNames::retarget( str_replace( '``', '`', $found[1] ), $source_prefix, $target_prefix ) ) . '`',
					$clause
				);
				continue;
			}

			$kept[] = (string) preg_replace( '/^(\s*)CONSTRAINT\s+`(?:[^`]|``)+`\s+(CHECK\s*\()/i', '$1$2', $line );
		}

		// The last definition before the closing parenthesis must not end with a comma.
		$count = count( $kept );
		for ( $i = $count - 1; $i > 0; $i-- ) {
			if ( str_starts_with( ltrim( $kept[ $i ] ), ')' ) ) {
				$kept[ $i - 1 ] = rtrim( $kept[ $i - 1 ], ',' );
				break;
			}
		}

		$create     = implode( "\n", $kept );
		$collations = array();
		$create     = (string) preg_replace_callback(
			'/\b(COLLATE)([= ])([a-z0-9_]+)/i',
			static function ( array $found ) use ( $has_collation, &$collations ): string {
				$collation = $found[3];

				if ( ! $has_collation( $collation ) ) {
					$alias = self::alias( $collation );

					// utf8_bin and utf8mb3_bin are the same collation under two names, nothing changes.
					if ( null !== $alias && $has_collation( $alias ) ) {
						return $found[1] . $found[2] . $alias;
					}

					$replacement              = self::fallback( $collation, $has_collation );
					$collations[ $collation ] = $replacement;
					$collation                = $replacement;
				}

				return $found[1] . $found[2] . $collation;
			},
			$create
		);

		if ( $mariadb ) {
			// MySQL 8 versioned comments (DEFAULT ENCRYPTION, SRID) would be executed by MariaDB 10+.
			$create = (string) preg_replace( '/\s*\/\*!80\d{3}\s.*?\*\//s', '', $create );
		} else {
			$create = (string) preg_replace( '/\b(GENERATED\s+ALWAYS\s+AS\s*\(.*\)\s+)PERSISTENT\b/i', '$1STORED', $create );
			$create = (string) preg_replace( '/\)\s+PERSISTENT\b/i', ') STORED', $create );
			$create = (string) preg_replace( '/\s+(PAGE_CHECKSUM|TRANSACTIONAL)=\d+/i', '', $create );
			$create = (string) preg_replace( '/\bENGINE=Aria\b/i', 'ENGINE=InnoDB', $create );
		}

		return array(
			'create'       => $create,
			'foreign_keys' => $foreign_keys,
			'collations'   => $collations,
		);
	}

	/**
	 * Foreign key clauses of a CREATE TABLE statement.
	 *
	 * @param string $create CREATE TABLE.
	 * @return list<string>
	 */
	public static function foreign_keys( string $create ): array {
		$clauses = array();

		foreach ( explode( "\n", $create ) as $line ) {
			if ( 1 === preg_match( '/^\s*CONSTRAINT\s+`(?:[^`]|``)+`\s+FOREIGN\s+KEY\b/i', $line ) ) {
				$clauses[] = rtrim( trim( $line ), ',' );
			}
		}

		return $clauses;
	}

	/**
	 * Name of the constraint in a foreign key clause.
	 *
	 * @param string $clause Clause from foreign_keys().
	 */
	public static function constraint_name( string $clause ): string {
		return 1 === preg_match( '/^CONSTRAINT\s+`((?:[^`]|``)+)`/i', $clause, $match ) ? str_replace( '``', '`', $match[1] ) : '';
	}

	/**
	 * Other name of the same collation: utf8 is utf8mb3 (MariaDB 10.6+ and MySQL 8 list only utf8mb3_*).
	 *
	 * @param string $collation Collation.
	 */
	public static function alias( string $collation ): ?string {
		$lower = strtolower( $collation );

		if ( str_starts_with( $lower, 'utf8_' ) ) {
			return 'utf8mb3_' . substr( $lower, 5 );
		}

		if ( str_starts_with( $lower, 'utf8mb3_' ) ) {
			return 'utf8_' . substr( $lower, 8 );
		}

		return null;
	}

	/**
	 * Closest collation the target knows.
	 *
	 * @param string   $collation     Unknown collation.
	 * @param callable $has_collation Whether the target knows a collation.
	 */
	public static function fallback( string $collation, callable $has_collation ): string {
		$charset    = strtolower( (string) strstr( $collation, '_', true ) );
		$candidates = str_ends_with( strtolower( $collation ), '_bin' )
			? array( $charset . '_bin' )
			: array( $charset . '_0900_ai_ci', $charset . '_uca1400_ai_ci', $charset . '_unicode_520_ci', $charset . '_unicode_ci', $charset . '_general_ci' );

		foreach ( $candidates as $candidate ) {
			if ( $has_collation( $candidate ) ) {
				return $candidate;
			}
		}

		return $charset . '_general_ci';
	}
}
