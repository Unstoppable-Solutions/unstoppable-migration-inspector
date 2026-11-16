<?php
/**
 * Storage for comparisons and their items.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Snapshots;

use UnstoppableSolutions\MigrationInspector\Core\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes umin_comparisons and umin_comparison_items.
 */
final class Comparison_Repository {

	/**
	 * Create a comparison.
	 *
	 * @param int $snapshot_id Snapshot ID.
	 * @return int Comparison ID.
	 */
	public function create( $snapshot_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin's own table.
		$ok = $wpdb->insert(
			Schema::table( 'umin_comparisons' ),
			array(
				'snapshot_id' => $snapshot_id,
				'status'      => 'running',
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
				'created_by'  => get_current_user_id(),
				'summary'     => wp_json_encode( array() ),
			),
			array( '%d', '%s', '%s', '%d', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Link the job that runs the comparison.
	 *
	 * @param int $id     Comparison ID.
	 * @param int $job_id Job ID.
	 * @return void
	 */
	public function set_job( $id, $job_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
		$wpdb->update( Schema::table( 'umin_comparisons' ), array( 'job_id' => $job_id ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );
	}

	/**
	 * Load a comparison.
	 *
	 * @param int $id Comparison ID.
	 * @return array<string, mixed>|null
	 */
	public function get( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table( 'umin_comparisons' ), $id ), ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}

		$summary        = json_decode( (string) $row['summary'], true );
		$row['summary'] = is_array( $summary ) ? $summary : array();
		$row['id']      = (int) $row['id'];

		return $row;
	}

	/**
	 * Comparison IDs for a snapshot, newest first.
	 *
	 * @param int $snapshot_id Snapshot ID.
	 * @return int[]
	 */
	public function ids_for_snapshot( $snapshot_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE snapshot_id = %d ORDER BY id DESC', Schema::table( 'umin_comparisons' ), $snapshot_id ) );

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Save the summary.
	 *
	 * @param int                  $id      Comparison ID.
	 * @param array<string, mixed> $summary Summary.
	 * @return void
	 */
	public function save_summary( $id, array $summary ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
		$wpdb->update( Schema::table( 'umin_comparisons' ), array( 'summary' => wp_json_encode( $summary ) ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
	}

	/**
	 * Set the status (and finish time for final statuses).
	 *
	 * @param int    $id     Comparison ID.
	 * @param string $status running, complete, failed or cancelled.
	 * @return void
	 */
	public function set_status( $id, $status ) {
		global $wpdb;

		$data   = array( 'status' => $status );
		$format = array( '%s' );

		if ( 'running' !== $status ) {
			$data['finished_at'] = gmdate( 'Y-m-d H:i:s' );
			$format[]            = '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
		$wpdb->update( Schema::table( 'umin_comparisons' ), $data, array( 'id' => $id ), $format, array( '%d' ) );
	}

	/**
	 * Remove items of a type in an ID range, so a repeated step starts clean.
	 *
	 * @param int    $id   Comparison ID.
	 * @param string $type Type key.
	 * @param int    $lo   Exclusive lower bound.
	 * @param int    $hi   Inclusive upper bound, 0 for none.
	 * @return void
	 */
	public function delete_range( $id, $type, $lo, $hi ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE comparison_id = %d AND record_type = %s AND record_id > %d AND record_id <= %d',
				Schema::table( 'umin_comparison_items' ),
				$id,
				$type,
				$lo,
				$hi > 0 ? $hi : PHP_INT_MAX
			)
		);
	}

	/**
	 * Store items.
	 *
	 * One INSERT per item keeps every query a fixed, fully prepared
	 * statement. A step stores at most one chunk's worth of items.
	 *
	 * @param int                                                     $id    Comparison ID.
	 * @param string                                                  $type  Type key.
	 * @param array<int, array{id: int, kind: string, detail: array}> $items Items.
	 * @return void
	 */
	public function insert_items( $id, $type, array $items ) {
		global $wpdb;

		$table = Schema::table( 'umin_comparison_items' );

		foreach ( $items as $item ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin's own table.
			$wpdb->insert(
				$table,
				array(
					'comparison_id' => $id,
					'record_type'   => $type,
					'record_id'     => (int) $item['id'],
					'kind'          => $item['kind'],
					'detail'        => (string) wp_json_encode( $item['detail'] ),
				),
				array( '%d', '%s', '%d', '%s', '%s' )
			);
		}
	}

	/**
	 * Items of one type and bucket, paginated.
	 *
	 * @param int    $id       Comparison ID.
	 * @param string $type     Type key.
	 * @param string $kind     Bucket.
	 * @param int    $page     Page, from 1.
	 * @param int    $per_page Rows per page.
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public function items( $id, $type, $kind, $page = 1, $per_page = 50 ) {
		global $wpdb;

		$table = Schema::table( 'umin_comparison_items' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE comparison_id = %d AND record_type = %s AND kind = %s', $table, $id, $type, $kind ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT record_id, detail FROM %i WHERE comparison_id = %d AND record_type = %s AND kind = %s ORDER BY record_id ASC LIMIT %d OFFSET %d',
				$table,
				$id,
				$type,
				$kind,
				$per_page,
				max( 0, ( $page - 1 ) * $per_page )
			),
			ARRAY_A
		);

		$items = array();
		foreach ( (array) $rows as $row ) {
			$detail  = json_decode( (string) $row['detail'], true );
			$items[] = array(
				'id'     => (int) $row['record_id'],
				'detail' => is_array( $detail ) ? $detail : array(),
			);
		}

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Every ID of a bucket, streamed in pages (for CSV downloads).
	 *
	 * @param int    $id       Comparison ID.
	 * @param string $kind     Bucket.
	 * @param int    $after    Internal row ID to continue after.
	 * @param int    $limit    Rows per page.
	 * @return array<int, array{row: int, type: string, id: int}>
	 */
	public function ids_page( $id, $kind, $after, $limit ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, record_type, record_id FROM %i WHERE comparison_id = %d AND kind = %s AND id > %d ORDER BY id ASC LIMIT %d',
				Schema::table( 'umin_comparison_items' ),
				$id,
				$kind,
				$after,
				$limit
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'row'  => (int) $row['id'],
				'type' => (string) $row['record_type'],
				'id'   => (int) $row['record_id'],
			);
		}

		return $out;
	}

	/**
	 * Delete a comparison and its items.
	 *
	 * @param int $id Comparison ID.
	 * @return void
	 */
	public function delete( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
		$wpdb->delete( Schema::table( 'umin_comparison_items' ), array( 'comparison_id' => $id ), array( '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
		$wpdb->delete( Schema::table( 'umin_comparisons' ), array( 'id' => $id ), array( '%d' ) );
	}
}
