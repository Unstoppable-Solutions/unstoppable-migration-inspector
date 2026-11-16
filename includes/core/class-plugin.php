<?php
/**
 * Plugin bootstrap.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Core;

use UnstoppableSolutions\MigrationInspector\Admin\Admin_Menu;
use UnstoppableSolutions\MigrationInspector\Admin\Assets;
use UnstoppableSolutions\MigrationInspector\Admin\Snapshot_Downloads;
use UnstoppableSolutions\MigrationInspector\REST\REST_Controller;
use UnstoppableSolutions\MigrationInspector\REST\Scans_Controller;
use UnstoppableSolutions\MigrationInspector\REST\Snapshots_Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin's subsystems into WordPress.
 *
 * Nothing here runs on front-end requests apart from registering the REST
 * route (which only executes when that route is called) and the capability
 * map. Inspections never run on page load.
 */
final class Plugin {

	/**
	 * Single instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether boot() has already run.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Get the plugin instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register hooks. Safe to call more than once.
	 *
	 * @return void
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		Capabilities::register();
		Settings::register();

		add_action( 'rest_api_init', array( new REST_Controller(), 'register_routes' ) );
		add_action( 'rest_api_init', array( new Snapshots_Controller(), 'register_routes' ) );
		add_action( 'rest_api_init', array( new Scans_Controller(), 'register_routes' ) );

		// Multisite: create tables for sites added after network activation,
		// and drop them when a site is deleted.
		add_action( 'wp_initialize_site', array( Activator::class, 'on_new_site' ), 20 );
		add_filter( 'wpmu_drop_tables', array( Schema::class, 'add_tables_to_drop_list' ) );

		if ( is_admin() ) {
			add_action( 'admin_init', array( Schema::class, 'maybe_upgrade' ) );
			Admin_Menu::register();
			Assets::register();
			Snapshot_Downloads::register();
		}
	}
}
