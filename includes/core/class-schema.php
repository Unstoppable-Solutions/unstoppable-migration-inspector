<?php
/**
 * Database schema for the plugin's own tables.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the plugin's tables.
 *
 * All tables use the current site's prefix, so on multisite each site keeps
 * its own snapshots and scan history. The undo log for replacements is added
 * in a later schema version, together with the code that uses it, rather
 * than created empty in advance.
 *
 * Version history:
 * 1  snapshots, scans, findings.
 * 2  snapshot status column; snapshot chunks, jobs, comparisons and
 *    comparison items (Phase 2a).
 */
final class Schema {

	/**
	 * Current schema version. Bump when a table definition changes.
	 */
	const VERSION = '2';

	/**
	 * Option that stores the installed schema version for this site.
	 */
	const VERSION_OPTION = 'umin_db_version';

	/**
	 * Unprefixed table names owned by the plugin.
	 *
	 * @var string[]
	 */
	const TABLES = array(
		'umin_snapshots',
		'umin_snapshot_chunks',
		'umin_comparisons',
		'umin_comparison_items',
		'umin_jobs',
		'umin_scans',
		'umin_findings',
	);

	/**
	 * Full table name for the current site.
	 *
	 * @param string $name Unprefixed table name from self::TABLES.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;

		return $wpdb->prefix . $name;
	}

	/**
	 * Full names of every plugin table for the current site.
	 *
	 * @return string[]
	 */
	public static function table_names() {
		return array_map( array( __CLASS__, 'table' ), self::TABLES );
	}

	/**
	 * Install or upgrade the tables for the current site.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$snapshots       = self::table( 'umin_snapshots' );
		$chunks          = self::table( 'umin_snapshot_chunks' );
		$comparisons     = self::table( 'umin_comparisons' );
		$items           = self::table( 'umin_comparison_items' );
		$jobs            = self::table( 'umin_jobs' );
		$scans           = self::table( 'umin_scans' );
		$findings        = self::table( 'umin_findings' );

		// dbDelta is strict about formatting: two spaces after PRIMARY KEY,
		// one field per line, KEY not INDEX.
		$sql = array(
			"CREATE TABLE {$snapshots} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				status varchar(20) NOT NULL DEFAULT 'capturing',
				created_at datetime NOT NULL,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				label varchar(190) NOT NULL DEFAULT '',
				origin varchar(20) NOT NULL DEFAULT 'local',
				source_home varchar(255) NOT NULL DEFAULT '',
				plugin_version varchar(20) NOT NULL DEFAULT '',
				format_version smallint(5) unsigned NOT NULL DEFAULT 1,
				data longtext NOT NULL,
				checksum char(64) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY created_at (created_at)
			) {$charset_collate};",
			"CREATE TABLE {$chunks} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				snapshot_id bigint(20) unsigned NOT NULL,
				record_type varchar(64) NOT NULL,
				seq int(10) unsigned NOT NULL,
				first_id bigint(20) unsigned NOT NULL,
				last_id bigint(20) unsigned NOT NULL,
				row_count int(10) unsigned NOT NULL,
				sha256 char(64) NOT NULL,
				payload longtext NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY snapshot_type_seq (snapshot_id,record_type,seq),
				KEY snapshot_type_first (snapshot_id,record_type,first_id)
			) {$charset_collate};",
			"CREATE TABLE {$comparisons} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				snapshot_id bigint(20) unsigned NOT NULL,
				job_id bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'running',
				created_at datetime NOT NULL,
				finished_at datetime DEFAULT NULL,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				summary longtext NOT NULL,
				PRIMARY KEY  (id),
				KEY snapshot_id (snapshot_id)
			) {$charset_collate};",
			"CREATE TABLE {$items} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				comparison_id bigint(20) unsigned NOT NULL,
				record_type varchar(64) NOT NULL,
				record_id bigint(20) unsigned NOT NULL,
				kind varchar(16) NOT NULL,
				detail text NOT NULL,
				PRIMARY KEY  (id),
				KEY comparison_type_kind (comparison_id,record_type,kind,record_id),
				KEY comparison_type_record (comparison_id,record_type,record_id)
			) {$charset_collate};",
			"CREATE TABLE {$jobs} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				type varchar(40) NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'pending',
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				params longtext NOT NULL,
				state longtext NOT NULL,
				progress text NOT NULL,
				lease_token varchar(64) NOT NULL DEFAULT '',
				lease_expires bigint(20) unsigned NOT NULL DEFAULT 0,
				steps int(10) unsigned NOT NULL DEFAULT 0,
				error text NOT NULL,
				PRIMARY KEY  (id),
				KEY type_status (type,status)
			) {$charset_collate};",
			"CREATE TABLE {$scans} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				status varchar(20) NOT NULL DEFAULT 'pending',
				started_at datetime NOT NULL,
				finished_at datetime DEFAULT NULL,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				snapshot_id bigint(20) unsigned DEFAULT NULL,
				categories text NOT NULL,
				progress longtext NOT NULL,
				summary longtext NOT NULL,
				PRIMARY KEY  (id),
				KEY status (status),
				KEY started_at (started_at)
			) {$charset_collate};",
			"CREATE TABLE {$findings} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				scan_id bigint(20) unsigned NOT NULL,
				category varchar(40) NOT NULL,
				check_key varchar(80) NOT NULL,
				severity varchar(20) NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'open',
				object_type varchar(40) NOT NULL DEFAULT '',
				object_id varchar(191) NOT NULL DEFAULT '',
				location varchar(255) NOT NULL DEFAULT '',
				message text NOT NULL,
				context longtext NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY scan_id (scan_id),
				KEY scan_category (scan_id,category),
				KEY severity (severity)
			) {$charset_collate};",
		);

		dbDelta( $sql );

		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	/**
	 * Install or upgrade when the stored version is out of date.
	 *
	 * Runs on admin_init, so it covers updates delivered without activation
	 * and sites in a network that was activated network-wide. It does
	 * nothing for users who cannot manage the site.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( ! current_user_can( Capabilities::VIEW ) ) {
			return;
		}

		if ( self::VERSION === get_option( self::VERSION_OPTION ) ) {
			return;
		}

		Activator::install_current_site();
	}

	/**
	 * Add the plugin's tables to the list WordPress drops with a site.
	 *
	 * @param string[] $tables Table names to drop.
	 * @return string[]
	 */
	public static function add_tables_to_drop_list( $tables ) {
		return array_merge( (array) $tables, self::table_names() );
	}

	/**
	 * Drop the plugin's tables for the current site. Used by uninstall only.
	 *
	 * @return void
	 */
	public static function drop_tables() {
		global $wpdb;

		foreach ( self::table_names() as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall removes the plugin's own tables; names come from a constant list, not input.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}

		delete_option( self::VERSION_OPTION );
	}
}
