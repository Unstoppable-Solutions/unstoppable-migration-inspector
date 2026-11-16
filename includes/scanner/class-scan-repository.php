<?php
/**
 * Scan records.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Scanner;

use UnstoppableSolutions\MigrationInspector\Core\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * One row per scan in umin_scans. `progress` holds the job ID and the
 * context summary; `summary` holds the results once the scan completes.
 */
final class Scan_Repository {

	const STATUS_RUNNING   = 'running';
	const STATUS_COMPLETE  = 'complete';
	const STATUS_FAILED    = 'failed';
	const STATUS_CANCELLED = 'cancelled';

	/**
	 * Create a scan.
	 *
	 * @return int Scan ID, or 0 on failure.
	 */
	public function create() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin's own table.
		$ok = $wpdb->insert(
			Schema::table( 'umin_scans' ),
			array(
				'status'     => self::STATUS_RUNNING,
				'started_at' => current_time( 'mysql', true ),
				'created_by' => get_current_user_id(),
				'categories' => '',
				'progress'   => '{}',
				'summary'    => '{}',
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		return false === $ok ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Get a scan.
	 *
	 * @param int $id Scan ID.
	 * @return array<string, mixed>|null
	 */
	public function get( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table( 'umin_scans' ), (int) $id ), ARRAY_A );

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Most recent scans.
	 *
	 * @param int $limit Limit.
	 * @return array<int, array<string, mixed>>
	 */
	public function recent( $limit = 10 ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', Schema::table( 'umin_scans' ), (int) $limit ), ARRAY_A );

		return array_map( array( __CLASS__, 'hydrate' ), $rows );
	}

	/**
	 * The latest completed scan.
	 *
	 * @return array<string, mixed>|null
	 */
	public function latest_complete() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE status = %s ORDER BY id DESC LIMIT 1', Schema::table( 'umin_scans' ), self::STATUS_COMPLETE ), ARRAY_A );

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Save progress data.
	 *
	 * @param int                  $id       Scan ID.
	 * @param array<string, mixed> $progress Progress.
	 * @return void
	 */
	public function save_progress( $id, array $progress ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
		$wpdb->update( Schema::table( 'umin_scans' ), array( 'progress' => (string) wp_json_encode( $progress ) ), array( 'id' => (int) $id ), array( '%s' ), array( '%d' ) );
	}

	/**
	 * Finish a scan.
	 *
	 * @param int                  $id      Scan ID.
	 * @param string               $status  Final status.
	 * @param array<string, mixed> $summary Summary.
	 * @return void
	 */
	public function finish( $id, $status, array $summary = array() ) {
		global $wpdb;

		$data = array(
			'status'      => $status,
			'finished_at' => current_time( 'mysql', true ),
		);

		if ( ! empty( $summary ) ) {
			$data['summary']    = (string) wp_json_encode( $summary );
			$data['categories'] = implode( ',', array_keys( isset( $summary['categories'] ) ? (array) $summary['categories'] : array() ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
		$wpdb->update( Schema::table( 'umin_scans' ), $data, array( 'id' => (int) $id ) );
	}

	/**
	 * Delete a scan and its findings.
	 *
	 * @param int $id Scan ID.
	 * @return void
	 */
	public function delete( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
		$wpdb->delete( Schema::table( 'umin_findings' ), array( 'scan_id' => (int) $id ), array( '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
		$wpdb->delete( Schema::table( 'umin_scans' ), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Decode JSON columns.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ) {
		$row['id']       = (int) $row['id'];
		$progress        = json_decode( (string) $row['progress'], true );
		$summary         = json_decode( (string) $row['summary'], true );
		$row['progress'] = is_array( $progress ) ? $progress : array();
		$row['summary']  = is_array( $summary ) ? $summary : array();

		return $row;
	}
}
