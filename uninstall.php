<?php
/**
 * Uninstall handler for Unstoppable Migration Inspector.
 *
 * Runs only when the plugin is deleted from the Plugins screen. Deactivation
 * does not run this file.
 *
 * Data is KEPT by default. Snapshots, scan history and (in later versions)
 * undo records survive deletion so a reinstall can still compare or undo.
 *
 * A site's data is removed only if an administrator switched on
 * "Remove all Migration Inspector data when the plugin is deleted" for that
 * site. Even then, nothing outside the plugin's own tables and options is
 * touched.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove the plugin's data for the site currently loaded, if allowed.
 *
 * Kept self-contained so uninstall does not depend on the plugin's
 * autoloader or hooks.
 *
 * @return void
 */
function umin_uninstall_current_site() {
	global $wpdb;

	$settings = get_option( 'umin_settings', array() );

	// Only an explicit opt-in removes data. Anything else keeps it.
	// Same "on" values the settings validator accepts.
	$opted_in = is_array( $settings )
		&& array_key_exists( 'delete_data_on_uninstall', $settings )
		&& in_array( $settings['delete_data_on_uninstall'], array( true, 1, '1', 'yes', 'on', 'true' ), true );

	if ( ! $opted_in ) {
		return;
	}

	$tables = array( 'umin_snapshots', 'umin_scans', 'umin_findings' );

	foreach ( $tables as $table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removing the plugin's own tables on uninstall; names are fixed, not input.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $table ) );
	}

	delete_option( 'umin_settings' );
	delete_option( 'umin_db_version' );

	wp_clear_scheduled_hook( 'umin_cleanup_reports' );
}

if ( is_multisite() ) {
	$umin_site_ids = get_sites(
		array(
			'fields'                 => 'ids',
			'number'                 => 0,
			'update_site_meta_cache' => false,
		)
	);

	foreach ( $umin_site_ids as $umin_site_id ) {
		switch_to_blog( (int) $umin_site_id );
		umin_uninstall_current_site();
		restore_current_blog();
	}
} else {
	umin_uninstall_current_site();
}
