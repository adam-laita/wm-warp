<?php
/**
 * Translation tooling without external dependencies.
 *
 * Usage:
 *   php bin/i18n.php          Regenerate languages/wm-warp.pot from the PHP code, merge it into every
 *                             languages/wm-warp-*.po (translations are kept) and compile .mo and .l10n.php.
 *   php bin/i18n.php --check  Fail when any generated file is outdated or a translation is missing.
 *
 * Translate by editing msgstr in the .po file, then run `composer i18n`.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Bin;

const DOMAIN = 'wm-warp';

/**
 * Translation functions: argument positions of singular, plural, context and text domain.
 */
const FUNCTIONS = array(
	'__'         => array(
		's' => 0,
		'd' => 1,
	),
	'_e'         => array(
		's' => 0,
		'd' => 1,
	),
	'esc_html__' => array(
		's' => 0,
		'd' => 1,
	),
	'esc_html_e' => array(
		's' => 0,
		'd' => 1,
	),
	'esc_attr__' => array(
		's' => 0,
		'd' => 1,
	),
	'esc_attr_e' => array(
		's' => 0,
		'd' => 1,
	),
	'_x'         => array(
		's' => 0,
		'c' => 1,
		'd' => 2,
	),
	'_ex'        => array(
		's' => 0,
		'c' => 1,
		'd' => 2,
	),
	'esc_html_x' => array(
		's' => 0,
		'c' => 1,
		'd' => 2,
	),
	'esc_attr_x' => array(
		's' => 0,
		'c' => 1,
		'd' => 2,
	),
	'_n'         => array(
		's' => 0,
		'p' => 1,
		'd' => 3,
	),
	'_nx'        => array(
		's' => 0,
		'p' => 1,
		'c' => 3,
		'd' => 4,
	),
	'_n_noop'    => array(
		's' => 0,
		'p' => 1,
		'd' => 2,
	),
	'_nx_noop'   => array(
		's' => 0,
		'p' => 1,
		'c' => 2,
		'd' => 3,
	),
);

/**
 * Plugin header fields that WordPress translates.
 */
const HEADER_FIELDS = array( 'Plugin Name', 'Description', 'Author' );

/**
 * Plural forms of supported languages.
 */
const PLURAL_FORMS = array(
	'cs_CZ' => 'nplurals=3; plural=(n==1) ? 0 : (n>=2 && n<=4) ? 1 : 2;',
);

exit( run( $argv ?? array() ) );

/**
 * Runs the tool.
 *
 * @param string[] $args Command line arguments.
 * @return int Exit code.
 */
function run( array $args ): int {
	$root  = dirname( __DIR__ );
	$check = in_array( '--check', $args, true );

	$entries  = extract_entries( $root );
	$expected = array();
	$problems = array();
	$po_files = glob( $root . '/languages/' . DOMAIN . '-*.po' );

	$expected[] = array( $root . '/languages/' . DOMAIN . '.pot', write_po( $entries, pot_header( $root ) ) );

	foreach ( false === $po_files ? array() : $po_files as $po_file ) {
		$locale       = substr( basename( $po_file, '.po' ), strlen( DOMAIN ) + 1 );
		$plural_forms = PLURAL_FORMS[ $locale ] ?? 'nplurals=2; plural=(n != 1);';
		$nplurals     = nplurals( $plural_forms );
		$existing     = parse_po( (string) file_get_contents( $po_file ) );
		$merged       = array();

		foreach ( $entries as $key => $entry ) {
			$entry['msgstr'] = $existing[ $key ]['msgstr'] ?? array();
			$merged[ $key ]  = $entry;

			if ( ! is_translated( $entry, $nplurals ) ) {
				$problems[] = sprintf( '%s: missing translation for "%s"', basename( $po_file ), $entry['msgid'] );
			}
		}

		$header = array(
			'Project-Id-Version'        => 'WM Warp',
			'Language'                  => $locale,
			'MIME-Version'              => '1.0',
			'Content-Type'              => 'text/plain; charset=UTF-8',
			'Content-Transfer-Encoding' => '8bit',
			'Plural-Forms'              => $plural_forms,
			'X-Domain'                  => DOMAIN,
		);

		$base       = substr( $po_file, 0, -3 );
		$expected[] = array( $po_file, write_po( $merged, $header, $nplurals ) );
		$expected[] = array( $base . '.mo', write_mo( $merged, $header ) );
		$expected[] = array( $base . '.l10n.php', write_l10n_php( $merged, $locale, $plural_forms ) );
	}

	foreach ( $expected as list( $file, $content ) ) {
		$current = is_file( $file ) ? file_get_contents( $file ) : null;

		if ( $current === $content ) {
			continue;
		}

		if ( $check ) {
			$problems[] = sprintf( '%s is outdated, run `composer i18n`', relative( $root, $file ) );
		} else {
			file_put_contents( $file, $content );
			echo 'Written ', relative( $root, $file ), "\n";
		}
	}

	if ( array() !== $problems ) {
		fwrite( STDERR, implode( "\n", $problems ) . "\n" );
		return 1;
	}

	echo count( $entries ), " strings, translations complete.\n";
	return 0;
}

/**
 * Collects translatable strings from the plugin header and PHP code.
 *
 * @param string $root Plugin folder.
 * @return array<string, array<string, mixed>>
 */
function extract_entries( string $root ): array {
	$entries = array();
	$main    = $root . '/' . DOMAIN . '.php';

	$header_source = (string) file_get_contents( $main );
	foreach ( HEADER_FIELDS as $field ) {
		if ( preg_match( '/^[ \t\/*#@]*' . preg_quote( $field, '/' ) . ':(.*)$/mi', $header_source, $match ) ) {
			add_entry( $entries, null, trim( $match[1] ), null, DOMAIN . '.php', array( $field . ' of the plugin' ) );
		}
	}

	$files = array( $main );
	$iter  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/src', \FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iter as $file ) {
		if ( $file instanceof \SplFileInfo && 'php' === $file->getExtension() ) {
			$files[] = $file->getPathname();
		}
	}
	$files = array_map( static fn( string $f ): string => str_replace( '\\', '/', $f ), $files );
	sort( $files );

	foreach ( $files as $file ) {
		extract_from_file( $entries, $file, relative( $root, $file ) );
	}

	return $entries;
}

/**
 * Extracts translation calls with literal arguments from one file.
 *
 * @param array<string, array<string, mixed>> $entries Collected entries.
 * @param string                              $file    Absolute path.
 * @param string                              $ref     Path used in references.
 */
function extract_from_file( array &$entries, string $file, string $ref ): void {
	$tokens         = token_get_all( (string) file_get_contents( $file ) );
	$count          = count( $tokens );
	$translator_txt = null;
	$translator_end = 0;

	for ( $i = 0; $i < $count; $i++ ) {
		$token = $tokens[ $i ];

		if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) && preg_match( '/translators:\s*(.*?)\s*(\*\/)?$/is', $token[1], $m ) ) {
			$translator_txt = trim( (string) preg_replace( '/\s*\n\s*\*?\s*/', ' ', $m[1] ) );
			$translator_end = $token[2] + substr_count( $token[1], "\n" );
			continue;
		}

		if ( ! is_array( $token ) || T_STRING !== $token[0] || ! isset( FUNCTIONS[ $token[1] ] ) ) {
			continue;
		}

		$previous = previous_code_token( $tokens, $i );
		if ( is_array( $previous ) && in_array( $previous[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NULLSAFE_OBJECT_OPERATOR ), true ) ) {
			continue;
		}

		$j = $i + 1;
		while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
			++$j;
		}
		if ( '(' !== ( $tokens[ $j ] ?? null ) ) {
			continue;
		}

		$args = call_arguments( $tokens, $j );
		$spec = FUNCTIONS[ $token[1] ];
		$line = $token[2];

		if ( ( $args[ $spec['d'] ] ?? null ) !== DOMAIN || ! is_string( $args[ $spec['s'] ] ?? null ) ) {
			continue;
		}

		$comments = ( null !== $translator_txt && $line - $translator_end <= 2 ) ? array( 'translators: ' . $translator_txt ) : array();
		$context  = isset( $spec['c'] ) && is_string( $args[ $spec['c'] ] ?? null ) ? $args[ $spec['c'] ] : null;
		$plural   = isset( $spec['p'] ) && is_string( $args[ $spec['p'] ] ?? null ) ? $args[ $spec['p'] ] : null;

		add_entry( $entries, $context, $args[ $spec['s'] ], $plural, $ref, $comments );
		$translator_txt = null;
	}
}

/**
 * Previous token that is not whitespace or a comment.
 *
 * @param array<int, mixed> $tokens Tokens.
 * @param int               $index  Current position.
 * @return mixed
 */
function previous_code_token( array $tokens, int $index ): mixed {
	for ( $k = $index - 1; $k >= 0; $k-- ) {
		if ( is_array( $tokens[ $k ] ) && in_array( $tokens[ $k ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		return $tokens[ $k ];
	}
	return null;
}

/**
 * Arguments of a call starting at the opening parenthesis: literal strings as strings, anything else as null.
 *
 * @param array<int, mixed> $tokens Tokens.
 * @param int               $open   Index of "(".
 * @return list<string|null>
 */
function call_arguments( array $tokens, int $open ): array {
	$args    = array();
	$current = array();
	$depth   = 0;
	$count   = count( $tokens );

	for ( $k = $open; $k < $count; $k++ ) {
		$t = $tokens[ $k ];

		if ( in_array( $t, array( '(', '[', '{' ), true ) || ( is_array( $t ) && in_array( $t[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
			++$depth;
			if ( 1 === $depth ) {
				continue;
			}
		} elseif ( in_array( $t, array( ')', ']', '}' ), true ) ) {
			--$depth;
			if ( 0 === $depth ) {
				$args[] = literal( $current );
				break;
			}
		} elseif ( ',' === $t && 1 === $depth ) {
			$args[]  = literal( $current );
			$current = array();
			continue;
		}

		if ( is_array( $t ) && in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		$current[] = $t;
	}

	return $args;
}

/**
 * Value of an argument consisting of a single string literal.
 *
 * @param array<int, mixed> $arg Tokens of the argument.
 */
function literal( array $arg ): ?string {
	if ( 1 !== count( $arg ) || ! is_array( $arg[0] ) || T_CONSTANT_ENCAPSED_STRING !== $arg[0][0] ) {
		return null;
	}

	$raw   = $arg[0][1];
	$inner = substr( $raw, 1, -1 );

	if ( "'" === $raw[0] ) {
		return str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $inner );
	}

	return stripcslashes( $inner );
}

/**
 * Adds or extends an entry.
 *
 * @param array<string, array<string, mixed>> $entries  Collected entries.
 * @param string|null                         $context  Context.
 * @param string                              $msgid    Source text.
 * @param string|null                         $plural   Plural source text.
 * @param string                              $ref      Reference.
 * @param string[]                            $comments Extracted comments.
 */
function add_entry( array &$entries, ?string $context, string $msgid, ?string $plural, string $ref, array $comments ): void {
	$key = entry_key( $context, $msgid );

	if ( ! isset( $entries[ $key ] ) ) {
		$entries[ $key ] = array(
			'msgctxt'      => $context,
			'msgid'        => $msgid,
			'msgid_plural' => $plural,
			'refs'         => array(),
			'comments'     => array(),
			'msgstr'       => array(),
		);
	}

	$entries[ $key ]['refs']     = array_values( array_unique( array_merge( $entries[ $key ]['refs'], array( $ref ) ) ) );
	$entries[ $key ]['comments'] = array_values( array_unique( array_merge( $entries[ $key ]['comments'], $comments ) ) );
}

/**
 * Key of an entry as used in .mo files.
 *
 * @param string|null $context Context.
 * @param string      $msgid   Source text.
 */
function entry_key( ?string $context, string $msgid ): string {
	return null === $context ? $msgid : $context . "\x04" . $msgid;
}

/**
 * Number of plural forms from a Plural-Forms header.
 *
 * @param string $plural_forms Plural forms.
 */
function nplurals( string $plural_forms ): int {
	return preg_match( '/nplurals=(\d+)/', $plural_forms, $m ) ? (int) $m[1] : 2;
}

/**
 * Whether an entry has all translations filled in.
 *
 * @param array<string, mixed> $entry    Entry.
 * @param int                  $nplurals Number of plural forms of the language.
 */
function is_translated( array $entry, int $nplurals ): bool {
	$forms = null === $entry['msgid_plural'] ? 1 : $nplurals;
	$strs  = $entry['msgstr'];

	if ( count( $strs ) < $forms ) {
		return false;
	}

	foreach ( $strs as $str ) {
		if ( '' === $str ) {
			return false;
		}
	}

	return true;
}

/**
 * Header of the template.
 *
 * @param string $root Plugin folder.
 * @return array<string, string>
 */
function pot_header( string $root ): array {
	preg_match( '/^[ \t\/*#@]*Version:\s*(.*)$/mi', (string) file_get_contents( $root . '/' . DOMAIN . '.php' ), $m );

	return array(
		'Project-Id-Version'        => 'WM Warp ' . trim( $m[1] ?? '' ),
		'MIME-Version'              => '1.0',
		'Content-Type'              => 'text/plain; charset=UTF-8',
		'Content-Transfer-Encoding' => '8bit',
		'X-Domain'                  => DOMAIN,
	);
}

/**
 * Parses a .po file.
 *
 * @param string $content File content.
 * @return array<string, array{msgstr: array<int, string>}>
 */
function parse_po( string $content ): array {
	$result = array();
	$blocks = preg_split( "/\r?\n\r?\n/", trim( $content ) );

	if ( false === $blocks ) {
		return $result;
	}

	foreach ( $blocks as $block ) {
		$fields = array();
		$last   = null;

		$lines = preg_split( "/\r?\n/", $block );

		foreach ( false === $lines ? array() : $lines as $line ) {
			if ( preg_match( '/^(msgctxt|msgid_plural|msgid|msgstr(?:\[(\d+)\])?)\s+"(.*)"$/', $line, $m ) ) {
				$last            = $m[1];
				$fields[ $last ] = po_unescape( $m[3] );
			} elseif ( null !== $last && preg_match( '/^"(.*)"$/', $line, $m ) ) {
				$fields[ $last ] .= po_unescape( $m[1] );
			}
		}

		if ( ! isset( $fields['msgid'] ) || '' === $fields['msgid'] ) {
			continue;
		}

		$msgstr = array();
		foreach ( $fields as $name => $value ) {
			if ( 'msgstr' === $name ) {
				$msgstr[0] = $value;
			} elseif ( preg_match( '/^msgstr\[(\d+)\]$/', $name, $m ) ) {
				$msgstr[ (int) $m[1] ] = $value;
			}
		}

		$result[ entry_key( $fields['msgctxt'] ?? null, $fields['msgid'] ) ] = array( 'msgstr' => $msgstr );
	}

	return $result;
}

/**
 * Writes a .po or .pot file.
 *
 * @param array<string, array<string, mixed>> $entries Entries.
 * @param array<string, string>               $header   Header fields.
 * @param int                                 $nplurals Number of plural forms (2 for the template).
 */
function write_po( array $entries, array $header, int $nplurals = 2 ): string {
	$header_text = '';
	foreach ( $header as $name => $value ) {
		$header_text .= $name . ': ' . $value . "\n";
	}

	$out = "# This file is generated by bin/i18n.php. Edit only msgstr lines in .po files.\nmsgid \"\"\nmsgstr \"\"\n";
	foreach ( explode( "\n", rtrim( $header_text, "\n" ) ) as $line ) {
		$out .= '"' . po_escape( $line . "\n" ) . "\"\n";
	}

	foreach ( $entries as $entry ) {
		$out .= "\n";
		foreach ( $entry['comments'] as $comment ) {
			$out .= '#. ' . $comment . "\n";
		}
		$out .= '#: ' . implode( ' ', $entry['refs'] ) . "\n";
		if ( null !== $entry['msgctxt'] ) {
			$out .= 'msgctxt "' . po_escape( $entry['msgctxt'] ) . "\"\n";
		}
		$out .= 'msgid "' . po_escape( $entry['msgid'] ) . "\"\n";

		if ( null === $entry['msgid_plural'] ) {
			$out .= 'msgstr "' . po_escape( $entry['msgstr'][0] ?? '' ) . "\"\n";
			continue;
		}

		$out  .= 'msgid_plural "' . po_escape( $entry['msgid_plural'] ) . "\"\n";
		$forms = max( $nplurals, count( $entry['msgstr'] ) );
		for ( $n = 0; $n < $forms; $n++ ) {
			$out .= 'msgstr[' . $n . '] "' . po_escape( $entry['msgstr'][ $n ] ?? '' ) . "\"\n";
		}
	}

	return $out;
}

/**
 * Compiles a .mo file.
 *
 * @param array<string, array<string, mixed>> $entries Entries.
 * @param array<string, string>               $header  Header fields.
 */
function write_mo( array $entries, array $header ): string {
	$header_text = '';
	foreach ( $header as $name => $value ) {
		$header_text .= $name . ': ' . $value . "\n";
	}

	$messages = array( '' => $header_text );
	foreach ( $entries as $key => $entry ) {
		if ( ! is_translated( $entry, nplurals( $header['Plural-Forms'] ?? '' ) ) ) {
			continue;
		}
		$original              = null === $entry['msgid_plural'] ? $key : $key . "\0" . $entry['msgid_plural'];
		$messages[ $original ] = implode( "\0", $entry['msgstr'] );
	}
	ksort( $messages, SORT_STRING );

	$count       = count( $messages );
	$orig_table  = 28;
	$trans_table = $orig_table + $count * 8;
	$offset      = $trans_table + $count * 8;
	$originals   = '';
	$translated  = '';
	$orig_index  = '';
	$trans_index = '';

	foreach ( array_keys( $messages ) as $original ) {
		$original    = (string) $original;
		$orig_index .= pack( 'VV', strlen( $original ), $offset + strlen( $originals ) );
		$originals  .= $original . "\0";
	}
	$offset += strlen( $originals );
	foreach ( $messages as $translation ) {
		$trans_index .= pack( 'VV', strlen( $translation ), $offset + strlen( $translated ) );
		$translated  .= $translation . "\0";
	}

	return pack( 'VVVVVVV', 0x950412de, 0, $count, $orig_table, $trans_table, 0, 0 ) . $orig_index . $trans_index . $originals . $translated;
}

/**
 * Compiles a PHP translation file (WordPress 6.5+ loads it instead of .mo).
 *
 * @param array<string, array<string, mixed>> $entries      Entries.
 * @param string                              $locale       Locale.
 * @param string                              $plural_forms Plural forms.
 */
function write_l10n_php( array $entries, string $locale, string $plural_forms ): string {
	$messages = array();
	foreach ( $entries as $key => $entry ) {
		if ( is_translated( $entry, nplurals( $plural_forms ) ) ) {
			$messages[ $key ] = implode( "\0", $entry['msgstr'] );
		}
	}
	ksort( $messages, SORT_STRING );

	$data = array(
		'domain'       => DOMAIN,
		'plural-forms' => $plural_forms,
		'language'     => $locale,
		'messages'     => $messages,
	);

	return "<?php\n// This file is generated by bin/i18n.php from the .po file.\nreturn " . var_export( $data, true ) . ";\n"; // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Generates a PHP file, not debug output.
}

/**
 * Escapes a string for a .po file.
 *
 * @param string $text Text.
 */
function po_escape( string $text ): string {
	return str_replace( array( '\\', '"', "\n", "\t" ), array( '\\\\', '\\"', '\\n', '\\t' ), $text );
}

/**
 * Unescapes a string from a .po file.
 *
 * @param string $text Text.
 */
function po_unescape( string $text ): string {
	return stripcslashes( $text );
}

/**
 * Path relative to the plugin folder with forward slashes.
 *
 * @param string $root Plugin folder.
 * @param string $file Absolute path.
 */
function relative( string $root, string $file ): string {
	$root = str_replace( '\\', '/', $root );
	$file = str_replace( '\\', '/', $file );

	return ltrim( str_starts_with( $file, $root ) ? substr( $file, strlen( $root ) ) : $file, '/' );
}
