<?php
/**
 * Scheduled tasks.
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
 * WP-Cron and Action Scheduler health signals: overdue tasks mean nothing
 * is triggering them on the new server.
 */
final class Cron_Check extends Check {

	/**
	 * Seconds after which a task counts as overdue.
	 */
	const OVERDUE = 3600;

	/**
	 * Key.
	 *
	 * @return string
	 */
	public function key() {
		return 'scheduled_tasks';
	}

	/**
	 * Category.
	 *
	 * @return string
	 */
	public function category() {
		return 'configuration';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Scheduled tasks', 'unstoppable-migration-inspector' );
	}

	/**
	 * Kinds.
	 *
	 * @return array<string, array{severity: string, title: string, explanation: string, action: string}>
	 */
	public function kinds() {
		return array(
			'cron_overdue'     => array(
				'severity'    => Severity::WARNING,
				'title'       => __( 'Scheduled tasks are overdue', 'unstoppable-migration-inspector' ),
				'explanation' => __( 'WordPress tasks (scheduled posts, update checks, plugin jobs, emails) are more than an hour late. Nothing is running them on this server, often because WP-Cron is disabled without a real cron job set up, or loopback requests are blocked.', 'unstoppable-migration-inspector' ),
				'action'      => __( 'If DISABLE_WP_CRON is set in wp-config.php, ask your host to run wp-cron.php on a schedule. Otherwise check Tools > Site Health for loopback request errors.', 'unstoppable-migration-inspector' ),
			),
			'actions_overdue'  => array(
				'severity'    => Severity::WARNING,
				'title'       => __( 'Background actions are piling up', 'unstoppable-migration-inspector' ),
				'explanation' => __( 'Action Scheduler (used by WooCommerce and many plugins) has pending actions more than an hour past due. Order emails, subscription renewals, analytics and syncs may be delayed.', 'unstoppable-migration-inspector' ),
				'action'      => __( 'Make sure scheduled tasks run on this server (see above). In WooCommerce, check Status > Scheduled Actions for failed or stuck actions.', 'unstoppable-migration-inspector' ),
			),
			'wp_cron_disabled' => array(
				'severity'    => Severity::NOTICE,
				'title'       => __( 'WP-Cron is disabled in wp-config.php', 'unstoppable-migration-inspector' ),
				'explanation' => __( 'DISABLE_WP_CRON is set. That is fine when the host runs WordPress\'s scheduled tasks with a real cron job, but the old host\'s cron job does not move with the site.', 'unstoppable-migration-inspector' ),
				'action'      => __( 'Confirm a cron job for wp-cron.php exists on the new server.', 'unstoppable-migration-inspector' ),
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

		unset( $context );
		$findings = array();
		$now      = time();

		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			$findings[] = ( new Finding( 'wp_cron_disabled' ) )->on( 'setting', 'DISABLE_WP_CRON' );
		}

		$overdue = 0;
		$oldest  = 0;
		$cron    = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();

		foreach ( (array) $cron as $timestamp => $hooks ) {
			if ( (int) $timestamp < $now - self::OVERDUE && is_array( $hooks ) ) {
				foreach ( $hooks as $events ) {
					$overdue += is_array( $events ) ? count( $events ) : 1;
				}
				$oldest = 0 === $oldest ? (int) $timestamp : min( $oldest, (int) $timestamp );
			}
		}

		if ( $overdue > 0 ) {
			$findings[] = ( new Finding( 'cron_overdue', (string) $overdue ) )
				->on( 'setting', 'cron' )
				->with(
					array(
						'overdue'      => $overdue,
						'oldest_hours' => (int) floor( ( $now - $oldest ) / 3600 ),
					)
				);
		}

		$table = $wpdb->prefix . 'actionscheduler_actions';

		if ( Db_Info::table_exists( $table ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only count.
			$late = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s AND scheduled_date_gmt < %s', $table, 'pending', gmdate( 'Y-m-d H:i:s', $now - self::OVERDUE ) ) );

			if ( $late >= 10 ) {
				$findings[] = ( new Finding( 'actions_overdue', (string) $late ) )->on( 'table', $table )->with( array( 'overdue' => $late ) );
			}
		}

		return $findings;
	}
}
