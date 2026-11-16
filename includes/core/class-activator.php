<?php
/**
 * Activation routines.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Prepares storage when the plugin is activated.
 *
 * Activation never scans or changes site content. It only creates the
 * plugin's own tables and default settings.
 */
final class Activator {

	/**
	 * Activation hook callback.
	 *
	 * On a network activation only the main site is installed here. Every
	 * other site installs itself the first time an administrator loads the
	 * dashboard (see Schema::maybe_upgrade()), so large networks do not time
	 * out during activation.
	 *
	 * @param bool $network_wide Whether the plugin is being network activated.
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		unset( $network_wide );

		self::install_current_site();
	}

	/**
	 * Install tables and defaults for the site currently loaded.
	 *
	 * @return void
	 */
	public static function install_current_site() {
		Schema::install();
		Settings::ensure_defaults();
	}

	/**
	 * Install for a site created after the plugin was network activated.
	 *
	 * @param \WP_Site $site The new site.
	 * @return void
	 */
	public static function on_new_site( $site ) {
		if ( ! is_multisite() || ! $site instanceof \WP_Site ) {
			return;
		}

		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! is_plugin_active_for_network( UMIN_BASENAME ) ) {
			return;
		}

		switch_to_blog( (int) $site->blog_id );
		self::install_current_site();
		restore_current_blog();
	}
}
