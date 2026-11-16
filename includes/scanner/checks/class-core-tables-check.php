<?php
/**
 * Core WordPress tables.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Scanner\Checks;

use UnstoppableSolutions\MigrationInspector\Scanner\Check;
use UnstoppableSolutions\MigrationInspector\Scanner\Db_Info;
use UnstoppableSolutions\MigrationInspector\Scanner\Finding;
use UnstoppableSolutions\MigrationInspector\Scanner\Scan_Context;
use UnstoppableSolutions\MigrationInspector\Scanner\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * Every core table must exist with this site's prefix.
 */
final class Core_Tables_Check extends Check {

	/**
	 * Key.
	 *
	 * @return string
	 */
	public function key() {
		return 'core_tables';
	}

	/**
	 * Category.
	 *
	 * @return string
	 */
	public function category() {
		return 'database';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Core WordPress tables', 'unstoppable-migration-inspector' );
	}

	/**
	 * Kinds.
	 *
	 * @return array<string, array{severity: string, title: string, explanation: string, action: string}>
	 */
	public function kinds() {
		return array(
			'missing_table' => array(
				'severity'    => Severity::CRITICAL,
				'title'       => __( 'Core WordPress tables are missing', 'unstoppable-migration-inspector' ),
				'explanation' => __( 'WordPress needs these tables. A missing table usually means the database import stopped part-way or used a different table prefix. Whatever the table held (comments, categories, user data…) is not available on this site.', 'unstoppable-migration-inspector' ),
				'action'      => __( 'Do not make changes to the site yet. Re-import the database from the source site or a backup, and check that the table prefix in wp-config.php matches the imported tables.', 'unstoppable-migration-inspector' ),
			),
		);
	}

	/**
	 * Expected core tables for the current site.
	 *
	 * @return string[]
	 */
	public static function expected() {
		global $wpdb;

		$site   = array( 'posts', 'postmeta', 'options', 'comments', 'commentmeta', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta' );
		$tables = array();

		foreach ( $site as $name ) {
			$tables[] = $wpdb->prefix . $name;
		}

		$tables[] = $wpdb->users;
		$tables[] = $wpdb->usermeta;

		return $tables;
	}

	/**
	 * Run.
	 *
	 * @param Scan_Context $context Context.
	 * @return Finding[]
	 */
	public function run( Scan_Context $context ) {
		unset( $context );
		$findings = array();

		foreach ( self::expected() as $table ) {
			if ( ! Db_Info::table_exists( $table ) ) {
				$findings[] = ( new Finding( 'missing_table', $table ) )->on( 'table', $table );
			}
		}

		return $findings;
	}
}
