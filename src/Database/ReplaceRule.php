<?php
/**
 * One find & replace rule.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Database;

/**
 * Text to find and its replacement. Pure value object.
 */
final class ReplaceRule {

	/**
	 * Constructor.
	 *
	 * @param string $find           Text to find, or a PCRE pattern with delimiters when $regex is true.
	 * @param string $replace        Replacement; for patterns $1 refers to a group.
	 * @param bool   $regex          Whether $find is a regular expression.
	 * @param bool   $case_sensitive Whether letter case must match (plain text only).
	 * @param bool   $domain         Match a whole domain only (automatic domain rules).
	 */
	public function __construct(
		public readonly string $find,
		public readonly string $replace,
		public readonly bool $regex = false,
		public readonly bool $case_sensitive = false,
		public readonly bool $domain = false
	) {}

	/**
	 * Rule from stored data.
	 *
	 * @param array<string, mixed> $data Data from to_array().
	 */
	public static function from_array( array $data ): self {
		return new self(
			is_string( $data['find'] ?? null ) ? $data['find'] : '',
			is_string( $data['replace'] ?? null ) ? $data['replace'] : '',
			true === ( $data['regex'] ?? false ),
			true === ( $data['case'] ?? false ),
			true === ( $data['domain'] ?? false )
		);
	}

	/**
	 * Data for storage.
	 *
	 * @return array{find: string, replace: string, regex: bool, case: bool, domain: bool}
	 */
	public function to_array(): array {
		return array(
			'find'    => $this->find,
			'replace' => $this->replace,
			'regex'   => $this->regex,
			'case'    => $this->case_sensitive,
			'domain'  => $this->domain,
		);
	}

	/**
	 * Whether a regular expression is valid.
	 *
	 * @param string $pattern Pattern with delimiters.
	 */
	public static function is_valid_regex( string $pattern ): bool {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- An invalid user pattern only means "invalid".
		return '' !== $pattern && false !== @preg_match( $pattern, '' );
	}
}
