<?php
/**
 * Storage for snapshots and their chunks.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Snapshots;

use UnstoppableSolutions\MigrationInspector\Core\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes umin_snapshots and umin_snapshot_chunks.
 *
 * Only the plugin's own tables are ever written.
 */
final class Snapshot_Repository {

	const STATUS_CAPTURING = 'capturing';
	const STATUS_COMPLETE  = 'complete';
	const STATUS_FAILED    = 'failed';

	/**
	 * Create a snapshot header row.
	 *
	 * @param string               $label  Label.
	 * @param string               $origin local or imported.
	 * @param array<string, mixed> $header Header data.
	 * @param string               $status Initial status.
	 * @return int Snapshot ID, 0 on failure.
	 */
	public function create( $label, $origin, array $header, $status = self::STATUS_CAPTURING ) {
		global $wpdb;

		$created = isset( $header['created_at'] ) ? (int) $header['created_at'] : time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin's own table.
		$ok = $wpdb->insert(
			Schema::table( 'umin_snapshots' ),
			array(
				'status'         => $status,
				'created_at'     => gmdate( 'Y-m-d H:i:s', $created ),
				'created_by'     => get_current_user_id(),
				'label'          => mb_substr( $label, 0, 190 ),
				'origin'         => $origin,
				'source_home'    => isset( $header['config']['home'] ) ? mb_substr( (string) $header['config']['home'], 0, 255 ) : '',
				'plugin_version' => UMIN_VERSION,
				'format_version' => 1,
				'data'           => wp_json_encode( $header ),
				'checksum'       => '',
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Load a snapshot header.
	 *
	 * @param int $id Snapshot ID.
	 * @return array<string, mixed>|null
	 */
	public function get( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table( 'umin_snapshots' ), $id ), ARRAY_A );

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * List snapshots, newest first, without chunk data.
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function all( $limit = 100 ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', Schema::table( 'umin_snapshots' ), $limit ), ARRAY_A );

		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Decode a row.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	private function hydrate( array $row ) {
		$data = json_decode( (string) $row['data'], true );

		$row['id']     = (int) $row['id'];
		$row['header'] = is_array( $data ) ? $data : array();
		unset( $row['data'] );

		return $row;
	}

	/**
	 * Replace the header data.
	 *
	 * @param int                  $id     Snapshot ID.
	 * @param array<string, mixed> $header Header.
	 * @return void
	 */
	public function save_header( $id, array $header ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
		$wpdb->update(
			Schema::table( 'umin_snapshots' ),
			array( 'data' => wp_json_encode( $header ) ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Set status and checksum.
	 *
	 * @param int    $id       Snapshot ID.
	 * @param string $status   Status.
	 * @param string $checksum Overall checksum.
	 * @return void
	 */
	public function set_status( $id, $status, $checksum = '' ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
		$wpdb->update(
			Schema::table( 'umin_snapshots' ),
			array(
				'status'   => $status,
				'checksum' => $checksum,
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Store a chunk. Replaces an existing chunk with the same position, so a
	 * repeated step after an interruption is harmless.
	 *
	 * @param int                  $snapshot_id Snapshot ID.
	 * @param string               $type        Type key.
	 * @param int                  $seq         Chunk sequence number.
	 * @param array<string, mixed> $chunk       Encoded chunk from Chunk_Codec::encode().
	 * @return bool
	 */
	public function put_chunk( $snapshot_id, $type, $seq, array $chunk ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
		$ok = $wpdb->replace(
			Schema::table( 'umin_snapshot_chunks' ),
			array(
				'snapshot_id' => $snapshot_id,
				'record_type' => $type,
				'seq'         => $seq,
				'first_id'    => $chunk['first_id'],
				'last_id'     => $chunk['last_id'],
				'row_count'   => $chunk['count'],
				'sha256'      => $chunk['sha256'],
				'payload'     => $chunk['payload'],
			),
			array( '%d', '%s', '%d', '%d', '%d', '%d', '%s', '%s' )
		);

		return false !== $ok;
	}

	/**
	 * One chunk by position.
	 *
	 * @param int    $snapshot_id Snapshot ID.
	 * @param string $type        Type key.
	 * @param int    $seq         Sequence number.
	 * @return array<string, mixed>|null
	 */
	public function get_chunk( $snapshot_id, $type, $seq ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE snapshot_id = %d AND record_type = %s AND seq = %d',
				Schema::table( 'umin_snapshot_chunks' ),
				$snapshot_id,
				$type,
				$seq
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Remove chunks of a type from a sequence number onward. Used when a
	 * capture step is repeated for a type, so stale tail chunks never remain.
	 *
	 * @param int    $snapshot_id Snapshot ID.
	 * @param string $type        Type key.
	 * @param int    $from_seq    First sequence number to remove.
	 * @return void
	 */
	public function delete_chunks_from( $snapshot_id, $type, $from_seq ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE snapshot_id = %d AND record_type = %s AND seq >= %d',
				Schema::table( 'umin_snapshot_chunks' ),
				$snapshot_id,
				$type,
				$from_seq
			)
		);
	}

	/**
	 * Per-type chunk summary: chunks, records, checksum lines.
	 *
	 * @param int $snapshot_id Snapshot ID.
	 * @return array<string, array{chunks: int, records: int}>
	 */
	public function type_totals( $snapshot_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT record_type, COUNT(*) AS chunks, SUM(row_count) AS records FROM %i WHERE snapshot_id = %d GROUP BY record_type',
				Schema::table( 'umin_snapshot_chunks' ),
				$snapshot_id
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ $row['record_type'] ] = array(
				'chunks'  => (int) $row['chunks'],
				'records' => (int) $row['records'],
			);
		}

		return $out;
	}

	/**
	 * Chunk metadata (no payloads) in canonical order, for checksums.
	 *
	 * @param int $snapshot_id Snapshot ID.
	 * @param int $after_id    Return rows with an internal ID above this (paging).
	 * @param int $limit       Maximum rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function chunk_index( $snapshot_id, $after_id = 0, $limit = 1000 ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, record_type, seq, first_id, last_id, row_count, sha256 FROM %i WHERE snapshot_id = %d AND id > %d ORDER BY id ASC LIMIT %d',
				Schema::table( 'umin_snapshot_chunks' ),
				$snapshot_id,
				$after_id,
				$limit
			),
			ARRAY_A
		);

		return (array) $rows;
	}

	/**
	 * Full chunk rows including payloads, in canonical order (for export).
	 *
	 * @param int $snapshot_id Snapshot ID.
	 * @param int $after_id    Internal ID to continue after.
	 * @param int $limit       Maximum rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function chunks_after( $snapshot_id, $after_id, $limit ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, record_type, seq, first_id, last_id, row_count, sha256, payload FROM %i WHERE snapshot_id = %d AND id > %d ORDER BY id ASC LIMIT %d',
				Schema::table( 'umin_snapshot_chunks' ),
				$snapshot_id,
				$after_id,
				$limit
			),
			ARRAY_A
		);

		return (array) $rows;
	}

	/**
	 * Canonical checksum over the header essentials and every chunk.
	 *
	 * The chunk list is sorted by type and sequence, so the value does not
	 * depend on insert order or internal IDs.
	 *
	 * @param array<string, mixed>             $header Header.
	 * @param array<int, array<string, mixed>> $chunks Chunk metadata rows.
	 * @return string
	 */
	public static function checksum( array $header, array $chunks ) {
		usort(
			$chunks,
			static function ( $a, $b ) {
				return array( (string) $a['record_type'], (int) $a['seq'] ) <=> array( (string) $b['record_type'], (int) $b['seq'] );
			}
		);

		$context = hash_init( 'sha256' );
		hash_update( $context, (string) wp_json_encode( self::canonical_header( $header ) ) );

		foreach ( $chunks as $chunk ) {
			hash_update(
				$context,
				"\n" . $chunk['record_type'] . '|' . (int) $chunk['seq'] . '|' . (int) $chunk['first_id'] . '|' . (int) $chunk['last_id'] . '|' . (int) $chunk['row_count'] . '|' . $chunk['sha256']
			);
		}

		return hash_final( $context );
	}

	/**
	 * The header fields covered by the checksum, with keys sorted.
	 *
	 * @param array<string, mixed> $header Header.
	 * @return array<string, mixed>
	 */
	public static function canonical_header( array $header ) {
		$keys = array( 'format_version', 'created_at', 'config', 'types', 'dictionaries', 'tables', 'revisions' );
		$out  = array();

		foreach ( $keys as $key ) {
			$out[ $key ] = isset( $header[ $key ] ) ? self::ksort_recursive( $header[ $key ] ) : null;
		}

		return $out;
	}

	/**
	 * Sort associative arrays by key, recursively.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function ksort_recursive( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );

		foreach ( $value as $key => $child ) {
			$value[ $key ] = self::ksort_recursive( $child );
		}

		if ( ! $is_list ) {
			ksort( $value, SORT_STRING );
		}

		return $value;
	}

	/**
	 * Delete a snapshot with its chunks and comparisons.
	 *
	 * @param int $id Snapshot ID.
	 * @return void
	 */
	public function delete( $id ) {
		global $wpdb;

		$comparisons = new Comparison_Repository();
		foreach ( $comparisons->ids_for_snapshot( $id ) as $comparison_id ) {
			$comparisons->delete( $comparison_id );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
		$wpdb->delete( Schema::table( 'umin_snapshot_chunks' ), array( 'snapshot_id' => $id ), array( '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
		$wpdb->delete( Schema::table( 'umin_snapshots' ), array( 'id' => $id ), array( '%d' ) );
	}
}
