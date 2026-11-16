<?php
/**
 * Deactivation routines.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Cleans up runtime state on deactivation.
 *
 * Settings, snapshots and scan history are kept so that reactivating the
 * plugin loses nothing. Permanent removal happens only in uninstall.php.
 */
final class Deactivator {

	/**
	 * Scheduled hooks the plugin may register in later phases.
	 *
	 * @var string[]
	 */
	const SCHEDULED_HOOKS = array(
		'umin_cleanup_reports',
	);

	/**
	 * Deactivation hook callback.
	 *
	 * @return void
	 */
	public static function deactivate() {
		foreach ( self::SCHEDULED_HOOKS as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}
}
