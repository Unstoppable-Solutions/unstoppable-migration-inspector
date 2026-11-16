<?php
/**
 * Table prefix consistency.
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
 * When a site is moved to a different table prefix, the tables are renamed
 * but two things inside them must change too: the `{prefix}user_roles`
 * option and the `{prefix}capabilities` / `{prefix}user_level` user meta
 * keys. If they are not, every user loses their role.
 */
final class Prefix_Check extends Check {

	/**
	 * Key.
	 *
	 * @return string
	 */
	public function key() {
		return 'table_prefix';
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
		return __( 'Table prefix and user roles', 'unstoppable-migration-inspector' );
	}

	/**
	 * Kinds.
	 *
	 * @return array<string, array{severity: string, title: string, explanation: string, action: string}>
	 */
	public function kinds() {
		return array(
			'roles_option_missing' => array(
				'severity'    => Severity::CRITICAL,
				'title'       => __( 'The user roles setting is missing for this table prefix', 'unstoppable-migration-inspector' ),
				'explanation' => __( 'WordPress stores its list of roles in an option named after the table prefix. It is missing, which usually happens when the tables were renamed to a new prefix but the option inside was not. Users can lose access to the admin area.', 'unstoppable-migration-inspector' ),
				'action'      => __( 'Compare the table prefix in wp-config.php with the prefix used on the old site. Rename the roles option (old prefix + "user_roles") to the new prefix, or re-import with the original prefix, after taking a backup.', 'unstoppable-migration-inspector' ),
			),
			'capabilities_prefix'  => array(
				'severity'    => Severity::CRITICAL,
				'title'       => __( 'User roles are stored under a different table prefix', 'unstoppable-migration-inspector' ),
				'explanation' => __( 'No user has a role for this site\'s table prefix, but users do have roles stored under another prefix. That is what happens when the prefix changed during the move and the user meta keys were not renamed. Users, including administrators, have no role here.', 'unstoppable-migration-inspector' ),
				'action'      => __( 'Rename the user meta keys from the old prefix to the new one ("capabilities" and "user_level"), or change the prefix in wp-config.php back to the one the data uses. Take a backup first.', 'unstoppable-migration-inspector' ),
			),
			'other_installs'       => array(
				'severity'    => Severity::NOTICE,
				'title'       => __( 'The database also holds tables with other prefixes', 'unstoppable-migration-inspector' ),
				'explanation' => __( 'Other complete sets of WordPress tables share this database. That can be another site, a staging copy (staging tools often use a prefix such as "wpstg" or "staging"), or leftovers from an earlier import. They do not affect this site, but they use space and can be confused with it.', 'unstoppable-migration-inspector' ),
				'action'      => __( 'Find out which site each prefix belongs to before doing anything. Remove leftover tables only when you are sure nothing uses them, and after taking a backup.', 'unstoppable-migration-inspector' ),
			),
		);
	}

	/**
	 * Run.
	 *
	 * @param Scan_Context $context Context.
	 * @return Finding[]
	 */
	public function run( Scan_Context $context ) {
		global $wpdb;

		$findings = array();
		$prefix   = $wpdb->get_blog_prefix();

		if ( null === Db_Info::raw_option( $prefix . 'user_roles' ) && Db_Info::table_exists( $wpdb->options ) ) {
			$findings[] = ( new Finding( 'roles_option_missing', $prefix . 'user_roles' ) )->on( 'option', $prefix . 'user_roles' );
		}

		if ( ! $context->get( 'multisite' ) && Db_Info::table_exists( $wpdb->usermeta ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only.
			$current = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s", $prefix . 'capabilities' ) );

			if ( 0 === $current ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only.
				$others = (array) $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, COUNT(*) AS users FROM {$wpdb->usermeta} WHERE meta_key LIKE %s GROUP BY meta_key", '%' . $wpdb->esc_like( 'capabilities' ) ), ARRAY_A );

				foreach ( $others as $row ) {
					$old = substr( (string) $row['meta_key'], 0, -strlen( 'capabilities' ) );

					if ( '' !== $old ) {
						$findings[] = ( new Finding( 'capabilities_prefix', $old ) )
							->on( 'table', $wpdb->usermeta, (string) $row['meta_key'] )
							->with(
								array(
									'old_prefix' => $old,
									'users'      => (int) $row['users'],
								)
							);
					}
				}
			}
		}

		foreach ( self::other_prefixes( $wpdb->base_prefix ) as $other => $count ) {
			$findings[] = ( new Finding( 'other_installs', $other ) )->on( 'table', $other . 'options' )->with( array( 'tables' => $count ) );
		}

		return $findings;
	}

	/**
	 * Prefixes of other complete WordPress table sets in this database.
	 *
	 * @param string $base_prefix This site's base prefix.
	 * @return array<string, int> Prefix => number of tables using it.
	 */
	public static function other_prefixes( $base_prefix ) {
		$tables = Db_Info::all_tables();
		$out    = array();

		foreach ( $tables as $table ) {
			if ( ! preg_match( '/^(.+?)options$/', $table, $match ) ) {
				continue;
			}

			$prefix = $match[1];

			if ( 0 === strpos( $prefix, $base_prefix ) && ( $prefix === $base_prefix || preg_match( '/^' . preg_quote( $base_prefix, '/' ) . '\d+_$/', $prefix ) ) ) {
				continue; // This site, or a site of this network.
			}

			if ( in_array( $prefix . 'posts', $tables, true ) && in_array( $prefix . 'postmeta', $tables, true ) ) {
				$count = 0;
				foreach ( $tables as $candidate ) {
					if ( 0 === strpos( $candidate, $prefix ) ) {
						++$count;
					}
				}
				$out[ $prefix ] = $count;
			}
		}

		return $out;
	}
}
