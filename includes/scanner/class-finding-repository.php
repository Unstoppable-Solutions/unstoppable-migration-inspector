<?php
/**
 * Stored findings.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Scanner;

use UnstoppableSolutions\MigrationInspector\Core\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Findings in umin_findings. Each batch of a check stores its findings with
 * the batch's cursor as `batch_key`; repeating the batch deletes and
 * rewrites them, so a retried request never duplicates findings.
 */
final class Finding_Repository {

	/**
	 * Most findings stored per check and scan. Beyond that only the count
	 * is kept (in the scan summary).
	 */
	const MAX_PER_CHECK = 500;

	/**
	 * Delete one batch's findings.
	 *
	 * @param int    $scan_id   Scan ID.
	 * @param string $check_key Check key.
	 * @param int    $batch_key Batch key.
	 * @return void
	 */
	public function delete_batch( $scan_id, $check_key, $batch_key ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
		$wpdb->delete(
			Schema::table( 'umin_findings' ),
			array(
				'scan_id'   => (int) $scan_id,
				'check_key' => (string) $check_key,
				'batch_key' => (int) $batch_key,
			),
			array( '%d', '%s', '%d' )
		);
	}

	/**
	 * Number of findings stored for a check.
	 *
	 * @param int    $scan_id   Scan ID.
	 * @param string $check_key Check key.
	 * @return int
	 */
	public function count_for_check( $scan_id, $check_key ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE scan_id = %d AND check_key = %s', Schema::table( 'umin_findings' ), (int) $scan_id, (string) $check_key ) );
	}

	/**
	 * Store findings of one batch, up to the per-check limit.
	 *
	 * @param int       $scan_id   Scan ID.
	 * @param Check     $check     Check.
	 * @param int       $batch_key Batch key.
	 * @param Finding[] $findings  Findings.
	 * @return array<string, int> Kind => number not stored because of the limit.
	 */
	public function store( $scan_id, Check $check, $batch_key, array $findings ) {
		global $wpdb;

		$this->delete_batch( $scan_id, $check->key(), $batch_key );

		$room    = self::MAX_PER_CHECK - $this->count_for_check( $scan_id, $check->key() );
		$dropped = array();
		$now     = current_time( 'mysql', true );

		foreach ( $findings as $finding ) {
			$kind = $check->kind( $finding->kind );

			if ( null === $kind ) {
				continue;
			}

			if ( $room <= 0 ) {
				$dropped[ $finding->kind ] = isset( $dropped[ $finding->kind ] ) ? $dropped[ $finding->kind ] + 1 : 1;
				continue;
			}

			$severity = Severity::is_valid( $finding->severity ) ? $finding->severity : $kind['severity'];

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin's own table.
			$wpdb->insert(
				Schema::table( 'umin_findings' ),
				array(
					'scan_id'     => (int) $scan_id,
					'category'    => $check->category(),
					'check_key'   => $check->key(),
					'kind'        => $finding->kind,
					'batch_key'   => (int) $batch_key,
					'severity'    => $severity,
					'status'      => 'open',
					'object_type' => substr( $finding->object_type, 0, 40 ),
					'object_id'   => substr( $finding->object_id, 0, 191 ),
					'location'    => substr( $finding->location, 0, 255 ),
					'message'     => $finding->message,
					'context'     => (string) wp_json_encode( $finding->details ),
					'created_at'  => $now,
				),
				array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);

			--$room;
		}

		return $dropped;
	}

	/**
	 * Counts per check, kind and severity.
	 *
	 * @param int $scan_id Scan ID.
	 * @return array<int, array{category: string, check_key: string, kind: string, severity: string, total: int}>
	 */
	public function groups( $scan_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT category, check_key, kind, severity, COUNT(*) AS total FROM %i WHERE scan_id = %d GROUP BY category, check_key, kind, severity', Schema::table( 'umin_findings' ), (int) $scan_id ), ARRAY_A );

		foreach ( $rows as $i => $row ) {
			$rows[ $i ]['total'] = (int) $row['total'];
		}

		return $rows;
	}

	/**
	 * Findings of one check and kind, paginated.
	 *
	 * @param int    $scan_id   Scan ID.
	 * @param string $check_key Check key.
	 * @param string $kind      Kind.
	 * @param int    $page      Page, from 1.
	 * @param int    $per_page  Per page.
	 * @return array<int, array<string, mixed>>
	 */
	public function page( $scan_id, $check_key, $kind, $page = 1, $per_page = 20 ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, severity, object_type, object_id, location, message, context FROM %i WHERE scan_id = %d AND check_key = %s AND kind = %s ORDER BY id ASC LIMIT %d OFFSET %d',
				Schema::table( 'umin_findings' ),
				(int) $scan_id,
				(string) $check_key,
				(string) $kind,
				(int) $per_page,
				max( 0, ( (int) $page - 1 ) * (int) $per_page )
			),
			ARRAY_A
		);

		foreach ( $rows as $i => $row ) {
			$context               = json_decode( (string) $row['context'], true );
			$rows[ $i ]['context'] = is_array( $context ) ? $context : array();
		}

		return $rows;
	}
}
