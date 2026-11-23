<?php
/**
 * Find & replace in database values.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Database;

/**
 * Replaces text in values, including PHP serialized data and JSON.
 *
 * - Serialized data are rewritten token by token without unserialize(): string lengths are recalculated,
 *   objects are never instantiated and everything else stays byte for byte the same.
 * - JSON is never decoded; the escaped form of every rule ("https:\/\/…", "C:\\path") is replaced as text,
 *   so numbers, empty objects and key order stay exactly as they were.
 * - Domain rules only match a whole domain ("//a.cz" does not touch "//a.cz.example.com" or "//a.czech.cz").
 * Binary columns must never be passed here (see TableInfo). Pure logic without WordPress calls.
 */
final class Replacer {

	private const MAX_DEPTH = 256;

	/**
	 * Compiled operations: [kind, search, replacement], kind is "plain", "iplain", "regex"
	 * (literal replacement) or "pattern" (user expression, $1 references allowed).
	 *
	 * @var list<array{0: string, 1: string, 2: string}>
	 */
	private array $operations = array();

	/**
	 * Lower-case strings of which at least one must occur for a value to change; null = always process.
	 *
	 * @var list<string>|null
	 */
	private ?array $needles = array();

	/**
	 * Constructor.
	 *
	 * @param ReplaceRule[] $rules Rules in the order they apply.
	 * @phpstan-param list<ReplaceRule> $rules
	 */
	public function __construct( array $rules ) {
		foreach ( $rules as $rule ) {
			$this->compile( $rule );
		}
	}

	/**
	 * Whether there is anything to replace.
	 */
	public function is_empty(): bool {
		return array() === $this->operations;
	}

	/**
	 * Replaces text in one value.
	 *
	 * @param string $value Value.
	 */
	public function replace( string $value ): string {
		if ( '' === $value || ! $this->may_match( $value ) ) {
			return $value;
		}

		return $this->process( $value, 0 );
	}

	/**
	 * Replaces text in a value that may be serialized.
	 *
	 * @param string $value Value.
	 * @param int    $depth Nesting depth of serialized strings.
	 */
	private function process( string $value, int $depth ): string {
		if ( $depth < 8 && self::looks_serialized( $value ) ) {
			$position = 0;
			$rebuilt  = $this->rebuild( $value, $position, $depth, 0 );

			if ( null !== $rebuilt && strlen( $value ) === $position ) {
				return $rebuilt;
			}
		}

		return $this->replace_text( $value );
	}

	/**
	 * Applies the operations to plain text.
	 *
	 * @param string $text Text.
	 */
	private function replace_text( string $text ): string {
		foreach ( $this->operations as [ $kind, $search, $replacement ] ) {
			if ( 'plain' === $kind ) {
				$text = str_replace( $search, $replacement, $text );
			} elseif ( 'iplain' === $kind ) {
				$text = str_ireplace( $search, $replacement, $text );
			} elseif ( 'pattern' === $kind ) {
				$result = preg_replace( $search, $replacement, $text );
				$text   = is_string( $result ) ? $result : $text;
			} else {
				$result = preg_replace_callback( $search, static fn(): string => $replacement, $text );
				$text   = is_string( $result ) ? $result : $text;
			}
		}

		return $text;
	}

	/**
	 * Rewrites one serialized token starting at $position, replacing text in its strings.
	 *
	 * @param string $data     Serialized data.
	 * @param int    $position Position; moved past the token.
	 * @param int    $depth    Nesting depth of serialized strings.
	 * @param int    $level    Nesting level of arrays and objects.
	 * @return string|null Rewritten token, or null when the data are not valid serialized data.
	 */
	private function rebuild( string $data, int &$position, int $depth, int $level ): ?string {
		if ( $level > self::MAX_DEPTH || ! isset( $data[ $position ] ) ) {
			return null;
		}

		switch ( $data[ $position ] ) {
			case 'N':
				return self::take( $data, $position, '/\GN;/' );

			case 'b':
				return self::take( $data, $position, '/\Gb:[01];/' );

			case 'i':
				return self::take( $data, $position, '/\Gi:[+-]?\d+;/' );

			case 'd':
				return self::take( $data, $position, '/\Gd:(?:[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?|NAN|-?INF);/' );

			case 'r':
			case 'R':
				return self::take( $data, $position, '/\G[rR]:\d+;/' );

			case 's':
				$string = self::length_prefixed( $data, $position, 's' );
				if ( null === $string || ! self::expect( $data, $position, '";' ) ) {
					return null;
				}

				$string = $this->process( $string, $depth + 1 );

				return 's:' . strlen( $string ) . ':"' . $string . '";';

			case 'E':
				$start = $position;
				if ( null === self::length_prefixed( $data, $position, 'E' ) || ! self::expect( $data, $position, '";' ) ) {
					return null;
				}

				return substr( $data, $start, $position - $start );

			case 'a':
				if ( 1 !== preg_match( '/\Ga:(\d+):\{/', $data, $match, 0, $position ) ) {
					return null;
				}

				$position += strlen( $match[0] );
				$members   = $this->members( $data, $position, (int) $match[1], $depth, $level );

				return null === $members ? null : $match[0] . $members;

			case 'O':
				$start = $position;
				if ( null === self::length_prefixed( $data, $position, 'O' ) || 1 !== preg_match( '/\G":(\d+):\{/', $data, $match, 0, $position ) ) {
					return null;
				}

				$position += strlen( $match[0] );
				$head      = substr( $data, $start, $position - $start );
				$members   = $this->members( $data, $position, (int) $match[1], $depth, $level );

				return null === $members ? null : $head . $members;

			case 'C':
				// Custom serialization (Serializable): the payload format belongs to the class, keep it as it is.
				$start = $position;
				if ( null === self::length_prefixed( $data, $position, 'C' ) || 1 !== preg_match( '/\G":(\d+):\{/', $data, $match, 0, $position ) ) {
					return null;
				}

				$position += strlen( $match[0] ) + (int) $match[1];
				if ( ! self::expect( $data, $position, '}' ) ) {
					return null;
				}

				return substr( $data, $start, $position - $start );
		}

		return null;
	}

	/**
	 * Rewrites the members of an array or object and the closing brace.
	 *
	 * @param string $data     Serialized data.
	 * @param int    $position Position after the opening brace; moved past the closing brace.
	 * @param int    $count    Number of members.
	 * @param int    $depth    Nesting depth of serialized strings.
	 * @param int    $level    Nesting level.
	 */
	private function members( string $data, int &$position, int $count, int $depth, int $level ): ?string {
		$parts = array();

		for ( $i = 0; $i < $count; $i++ ) {
			// Keys stay as they are; only values are replaced.
			$start = $position;
			if ( null === self::take( $data, $position, '/\G(?:i:[+-]?\d+;|s:\d+:")/' ) ) {
				return null;
			}

			if ( 's' === $data[ $start ] ) {
				$position = $start;
				if ( null === self::length_prefixed( $data, $position, 's' ) || ! self::expect( $data, $position, '";' ) ) {
					return null;
				}
			}

			$parts[] = substr( $data, $start, $position - $start );
			$value   = $this->rebuild( $data, $position, $depth, $level + 1 );

			if ( null === $value ) {
				return null;
			}

			$parts[] = $value;
		}

		if ( ! self::expect( $data, $position, '}' ) ) {
			return null;
		}

		return implode( '', $parts ) . '}';
	}

	/**
	 * Reads a token matching a pattern anchored with \G.
	 *
	 * @param string $data     Data.
	 * @param int    $position Position; moved past the token.
	 * @param string $pattern  Pattern.
	 */
	private static function take( string $data, int &$position, string $pattern ): ?string {
		if ( 1 !== preg_match( $pattern, $data, $match, 0, $position ) ) {
			return null;
		}

		$position += strlen( $match[0] );

		return $match[0];
	}

	/**
	 * Reads `X:<length>:"<bytes>` and returns the bytes; the position ends after them.
	 *
	 * @param string $data     Data.
	 * @param int    $position Position; moved past the bytes.
	 * @param string $type     Type letter.
	 */
	private static function length_prefixed( string $data, int &$position, string $type ): ?string {
		if ( 1 !== preg_match( '/\G' . $type . ':(\d+):"/', $data, $match, 0, $position ) ) {
			return null;
		}

		$length = (int) $match[1];
		$start  = $position + strlen( $match[0] );

		if ( $start + $length > strlen( $data ) ) {
			return null;
		}

		$position = $start + $length;

		return substr( $data, $start, $length );
	}

	/**
	 * Consumes an exact string.
	 *
	 * @param string $data     Data.
	 * @param int    $position Position; moved past the string.
	 * @param string $expected Expected string.
	 */
	private static function expect( string $data, int &$position, string $expected ): bool {
		if ( substr( $data, $position, strlen( $expected ) ) !== $expected ) {
			return false;
		}

		$position += strlen( $expected );

		return true;
	}

	/**
	 * Quick test whether a value starts like serialized data.
	 *
	 * @param string $value Value.
	 */
	private static function looks_serialized( string $value ): bool {
		return 1 === preg_match( '/^(?:[aOC]:\d+:[{"]|s:\d+:"|N;$|b:[01];$|i:[+-]?\d+;$|d:[^;]+;$|E:\d+:")/', $value );
	}

	/**
	 * Whether any rule can change the value.
	 *
	 * @param string $value Value.
	 */
	private function may_match( string $value ): bool {
		if ( null === $this->needles ) {
			return true;
		}

		foreach ( $this->needles as $needle ) {
			if ( false !== stripos( $value, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Compiles a rule into operations, including its JSON escaped form.
	 *
	 * @param ReplaceRule $rule Rule.
	 */
	private function compile( ReplaceRule $rule ): void {
		if ( '' === $rule->find || ( $rule->find === $rule->replace && ! $rule->regex ) ) {
			return;
		}

		if ( $rule->regex ) {
			$this->operations[] = array( 'pattern', $rule->find, $rule->replace );
			$this->needles      = null;

			return;
		}

		// Plain text, JSON ("https:\/\/…"), JSON inside a JSON string ("https:\\\/\\\/…", page builders
		// and form revisions) and for domains also the URL-encoded form ("https%3A%2F%2F…" in query strings).
		$variants = array( $rule->find => $rule->replace );
		$once     = self::json_escape( $rule->find );
		$twice    = self::json_escape( $once );

		$variants[ $once ]  = self::json_escape( $rule->replace );
		$variants[ $twice ] = self::json_escape( self::json_escape( $rule->replace ) );

		if ( $rule->domain ) {
			$variants[ rawurlencode( $rule->find ) ] = rawurlencode( $rule->replace );
		}

		foreach ( $variants as $find => $replace ) {
			$find = (string) $find; // Same forms share one key, so each is replaced once.

			if ( null !== $this->needles ) {
				$this->needles[] = strtolower( $find );
			}

			if ( $rule->domain ) {
				// Whole domain only: the next character must not continue the host name.
				$this->operations[] = array( 'regex', '/' . preg_quote( $find, '/' ) . '(?![a-z0-9-]|\.[a-z0-9])/i', $replace );
			} else {
				$this->operations[] = array( $rule->case_sensitive ? 'plain' : 'iplain', $find, $replace );
			}
		}
	}

	/**
	 * Text as it appears inside a JSON string ("/" → "\/", "\" → "\\").
	 *
	 * @param string $text Text.
	 */
	private static function json_escape( string $text ): string {
		return str_replace( array( '\\', '/' ), array( '\\\\', '\\/' ), $text );
	}
}
