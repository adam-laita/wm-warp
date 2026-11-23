<?php
/**
 * Changes applied to rows on their way to the target.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Database;

/**
 * Find & replace in text columns (never in binary ones, F1d) and the table prefix in keys that contain it
 * ({prefix}user_roles in options, {prefix}capabilities and others in usermeta), otherwise nobody could log in
 * on a target with a different prefix. Pure logic without WordPress calls.
 */
final class RowTransformer {

	/**
	 * Indexes of text columns.
	 *
	 * @var list<int>
	 */
	private array $text = array();

	/**
	 * Index of the column whose prefix is fixed, or -1.
	 *
	 * @var int
	 */
	private int $prefix_column = -1;

	/**
	 * Whether the whole value must equal "{prefix}user_roles" (options) rather than start with the prefix (usermeta).
	 *
	 * @var bool
	 */
	private bool $exact = false;

	/**
	 * Constructor.
	 *
	 * @param Replacer  $replacer      Find & replace.
	 * @param TableInfo $table         Source table.
	 * @param string    $source_prefix Table prefix of the source.
	 * @param string    $target_prefix Table prefix of the target.
	 */
	public function __construct( private Replacer $replacer, TableInfo $table, private string $source_prefix, private string $target_prefix ) {
		$columns = $table->data_columns();

		foreach ( $columns as $index => $column ) {
			if ( TableInfo::TEXT === $column['kind'] ) {
				$this->text[] = $index;
			}
		}

		if ( '' === $source_prefix || $source_prefix === $target_prefix ) {
			return;
		}

		$names = array_map( static fn( array $column ): string => $column['name'], $columns );

		if ( $source_prefix . 'options' === $table->name ) {
			$this->prefix_column = self::index_of( $names, 'option_name' );
			$this->exact         = true;
		} elseif ( $source_prefix . 'usermeta' === $table->name ) {
			$this->prefix_column = self::index_of( $names, 'meta_key' );
		}
	}

	/**
	 * Whether rows change at all.
	 */
	public function is_identity(): bool {
		return -1 === $this->prefix_column && ( $this->replacer->is_empty() || array() === $this->text );
	}

	/**
	 * Transforms rows.
	 *
	 * @param list<list<string|null>> $rows Rows.
	 * @return list<list<string|null>>
	 */
	public function apply( array $rows ): array {
		if ( $this->is_identity() ) {
			return $rows;
		}

		$replace = ! $this->replacer->is_empty();

		foreach ( $rows as $r => $row ) {
			if ( $replace ) {
				foreach ( $this->text as $index ) {
					if ( null !== $row[ $index ] ) {
						$row[ $index ] = $this->replacer->replace( $row[ $index ] );
					}
				}
			}

			$value = -1 === $this->prefix_column ? null : ( $row[ $this->prefix_column ] ?? null );

			if ( null !== $value ) {
				if ( $this->exact ? $value === $this->source_prefix . 'user_roles' : str_starts_with( $value, $this->source_prefix ) ) {
					$row[ $this->prefix_column ] = $this->target_prefix . substr( $value, strlen( $this->source_prefix ) );
				}
			}

			$rows[ $r ] = $row;
		}

		return $rows;
	}

	/**
	 * Index of a column, or -1.
	 *
	 * @param string[] $names Column names.
	 * @phpstan-param list<string> $names
	 * @param string   $name  Column.
	 */
	private static function index_of( array $names, string $name ): int {
		$index = array_search( $name, $names, true );

		return false === $index ? -1 : (int) $index;
	}
}
