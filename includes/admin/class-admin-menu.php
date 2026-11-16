<?php
/**
 * Admin menu registration.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Admin;

use UnstoppableSolutions\MigrationInspector\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the Migration Inspector menu and its screens.
 *
 * Sections are added as each one is built, so no placeholder screens ship.
 * Planned order: Dashboard, Migration Scanner, Snapshots, Repair Center
 * (which offers Search & Replace as a tool), Reports, Settings.
 */
final class Admin_Menu {

	/**
	 * Top-level menu slug. Also used for the gold menu label CSS selector.
	 */
	const SLUG = 'unstoppable-migration-inspector';

	/**
	 * Settings screen slug.
	 */
	const SETTINGS_SLUG = 'umin-settings';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_filter( 'plugin_action_links_' . UMIN_BASENAME, array( __CLASS__, 'plugin_action_links' ) );
	}

	/**
	 * Add the menu and submenu pages.
	 *
	 * @return void
	 */
	public static function add_menu() {
		add_menu_page(
			__( 'Unstoppable Migration Inspector', 'unstoppable-migration-inspector' ),
			__( 'Migration Inspector', 'unstoppable-migration-inspector' ),
			Capabilities::VIEW,
			self::SLUG,
			array( Dashboard_Page::class, 'render' ),
			'dashicons-search'
		);

		add_submenu_page(
			self::SLUG,
			__( 'Dashboard', 'unstoppable-migration-inspector' ),
			__( 'Dashboard', 'unstoppable-migration-inspector' ),
			Capabilities::VIEW,
			self::SLUG,
			array( Dashboard_Page::class, 'render' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'Settings', 'unstoppable-migration-inspector' ),
			__( 'Settings', 'unstoppable-migration-inspector' ),
			Capabilities::VIEW,
			self::SETTINGS_SLUG,
			array( Settings_Page::class, 'render' )
		);
	}

	/**
	 * Add a Dashboard link to the plugin's row on the Plugins screen.
	 *
	 * @param string[] $links Existing action links.
	 * @return string[]
	 */
	public static function plugin_action_links( $links ) {
		if ( ! Capabilities::current_user_can_view() ) {
			return $links;
		}

		$dashboard = sprintf(
			'<a href="%s">%s</a>',
			esc_url( self::url() ),
			esc_html__( 'Dashboard', 'unstoppable-migration-inspector' )
		);

		array_unshift( $links, $dashboard );

		return $links;
	}

	/**
	 * URL of a plugin screen.
	 *
	 * @param string $slug Page slug.
	 * @param array  $args Extra query arguments.
	 * @return string
	 */
	public static function url( $slug = self::SLUG, array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => $slug ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Whether the current admin screen belongs to this plugin.
	 *
	 * @param string $hook_suffix Hook suffix passed to admin_enqueue_scripts.
	 * @return bool
	 */
	public static function is_plugin_screen( $hook_suffix ) {
		return in_array(
			$hook_suffix,
			array(
				'toplevel_page_' . self::SLUG,
				'migration-inspector_page_' . self::SETTINGS_SLUG,
			),
			true
		) || false !== strpos( (string) $hook_suffix, '_page_' . self::SETTINGS_SLUG );
	}
}
