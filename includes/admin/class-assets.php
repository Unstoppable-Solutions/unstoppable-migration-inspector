<?php
/**
 * Admin stylesheets and scripts.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues admin assets. Nothing is loaded on the front end.
 */
final class Assets {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Enqueue assets for the current admin screen.
	 *
	 * The menu stylesheet loads on every admin screen because the menu appears
	 * on every admin screen; it holds a few colour rules only. The main
	 * stylesheet loads on the plugin's own screens only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public static function enqueue( $hook_suffix ) {
		wp_enqueue_style(
			'umin-admin-menu',
			UMIN_URL . 'assets/css/admin-menu.css',
			array(),
			UMIN_VERSION
		);

		if ( ! Admin_Menu::is_plugin_screen( $hook_suffix ) ) {
			return;
		}

		wp_enqueue_style(
			'umin-admin',
			UMIN_URL . 'assets/css/admin.css',
			array( 'dashicons' ),
			UMIN_VERSION
		);

		if ( Admin_Menu::is_snapshots_screen( $hook_suffix ) ) {
			wp_enqueue_script(
				'umin-snapshots',
				UMIN_URL . 'assets/js/snapshots.js',
				array( 'wp-api-fetch', 'wp-i18n' ),
				UMIN_VERSION,
				true
			);

			wp_set_script_translations( 'umin-snapshots', 'unstoppable-migration-inspector' );

			wp_localize_script(
				'umin-snapshots',
				'uminSnapshots',
				array(
					'namespace' => \UnstoppableSolutions\MigrationInspector\REST\REST_Controller::NAMESPACE_V1,
					'pageUrl'   => Admin_Menu::url( Admin_Menu::SNAPSHOTS_SLUG ),
				)
			);
		}
	}
}
