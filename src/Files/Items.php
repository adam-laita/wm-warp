<?php
/**
 * Plugins and themes of a site.
 *
 * @package WM\Warp
 */

declare(strict_types=1);

namespace WM\Warp\Files;

/**
 * Plugins and themes as items of their group: the folder (or the file of a single-file plugin) with a
 * name, version and whether it is active. Warp itself is never listed; links are listed but marked,
 * because they are never transferred or replaced.
 */
final class Items {

	/**
	 * Plugins.
	 *
	 * @return list<array{item: string, name: string, version: string, active: bool, link: bool}>
	 */
	public static function plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		wp_cache_delete( 'plugins', 'plugins' );

		$root   = Groups::root( Groups::PLUGINS );
		$active = (array) get_option( 'active_plugins', array() );
		$items  = array();

		foreach ( get_plugins() as $file => $data ) {
			$item = Paths::item( (string) $file );

			if ( Groups::is_protected( Groups::PLUGINS, $item ) || isset( $items[ $item ] ) ) {
				continue;
			}

			$items[ $item ] = array(
				'item'    => $item,
				'name'    => '' !== (string) $data['Name'] ? (string) $data['Name'] : $item,
				'version' => (string) $data['Version'],
				'active'  => in_array( $file, $active, true ),
				'link'    => Fs::is_link( $root . '/' . $item ),
			);
		}

		uasort( $items, static fn( array $a, array $b ): int => strcasecmp( $a['name'], $b['name'] ) );

		return array_values( $items );
	}

	/**
	 * Themes; "active" marks the active theme and its parent.
	 *
	 * @return list<array{item: string, name: string, version: string, active: bool, link: bool, parent: string}>
	 */
	public static function themes(): array {
		$root   = Groups::root( Groups::THEMES );
		$active = array( get_stylesheet(), get_template() );
		$items  = array();

		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$stylesheet = (string) $stylesheet;

			if ( str_contains( $stylesheet, '/' ) ) {
				continue; // A theme in a subfolder of a theme root other than the main one.
			}

			$parent  = (string) $theme->get_template();
			$items[] = array(
				'item'    => $stylesheet,
				'name'    => (string) $theme->get( 'Name' ),
				'version' => (string) $theme->get( 'Version' ),
				'active'  => in_array( $stylesheet, $active, true ),
				'link'    => Fs::is_link( $root . '/' . $stylesheet ),
				'parent'  => $parent !== $stylesheet ? $parent : '',
			);
		}

		usort( $items, static fn( array $a, array $b ): int => strcasecmp( $a['name'], $b['name'] ) );

		return $items;
	}
}
