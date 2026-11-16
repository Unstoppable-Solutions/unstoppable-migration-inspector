<?php
/**
 * Persistent job state with leases.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Jobs;

use UnstoppableSolutions\MigrationInspector\Core\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes rows in umin_jobs.
 *
 * Job state lives here, not in the request. A request may only change a
 * job's state while it holds the job's lease. Claiming the lease is a single
 * conditional UPDATE, so two requests can never hold it at once. A lease
 * expires on its own, so a closed browser or a timed-out request never
 * leaves a job locked for good.
 */
final class Job_Store {

	const STATUS_PENDING   = 'pending';
	const STATUS_RUNNING   = 'running';
	const STATUS_COMPLETED = 'completed';
	const STATUS_CANCELLED = 'cancelled';
	const STATUS_FAILED    = 'failed';

	/**
	 * Lease length in seconds. Longer than any single step may take.
	 */
	const LEASE_SECONDS = 60;

	/**
	 * Clock returning unix time, injectable for tests.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Constructor.
	 *
	 * @param callable|null $clock Returns the current unix time.
	 */
	public function __construct( $clock = null ) {
		$this->clock = $clock ? $clock : 'time';
	}

	/**
	 * Current unix time.
	 *
	 * @return int
	 */
	public function now() {
		return (int) call_user_func( $this->clock );
	}

	/**
	 * Jobs table name.
	 *
	 * @return string
	 */
	private function table() {
		return Schema::table( 'umin_jobs' );
	}

	/**
	 * Create a job.
	 *
	 * @param string               $type   Job type.
	 * @param array<string, mixed> $params Parameters.
	 * @param array<string, mixed> $state  Initial state.
	 * @return int Job ID, 0 on failure.
	 */
	public function create( $type, array $params, array $state ) {
		global $wpdb;

		$now = gmdate( 'Y-m-d H:i:s', $this->now() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin's own table.
		$inserted = $wpdb->insert(
			$this->table(),
			array(
				'type'       => $type,
				'status'     => self::STATUS_PENDING,
				'created_by' => get_current_user_id(),
				'created_at' => $now,
				'updated_at' => $now,
				'params'     => wp_json_encode( $params ),
				'state'      => wp_json_encode( $state ),
				'progress'   => wp_json_encode( array() ),
				'error'      => '',
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Load a job.
	 *
	 * @param int $id Job ID.
	 * @return array<string, mixed>|null
	 */
	public function get( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table; state must never be served from cache.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table(), $id ), ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}

		foreach ( array( 'params', 'state', 'progress' ) as $key ) {
			$decoded     = json_decode( (string) $row[ $key ], true );
			$row[ $key ] = is_array( $decoded ) ? $decoded : array();
		}

		$row['id']            = (int) $row['id'];
		$row['lease_expires'] = (int) $row['lease_expires'];
		$row['steps']         = (int) $row['steps'];
		$row['created_by']    = (int) $row['created_by'];

		return $row;
	}

	/**
	 * Whether a job is in a final state.
	 *
	 * @param array<string, mixed> $job Job row.
	 * @return bool
	 */
	public static function is_finished( array $job ) {
		return in_array( $job['status'], array( self::STATUS_COMPLETED, self::STATUS_CANCELLED, self::STATUS_FAILED ), true );
	}

	/**
	 * Status to show a person: a running job whose lease has lapsed is paused.
	 *
	 * @param array<string, mixed> $job Job row.
	 * @return string
	 */
	public function display_status( array $job ) {
		if ( self::STATUS_RUNNING === $job['status'] && $job['lease_expires'] < $this->now() ) {
			return 'paused';
		}

		return (string) $job['status'];
	}

	/**
	 * Try to claim the lease.
	 *
	 * Succeeds only if the job is unfinished and nobody holds a live lease.
	 *
	 * @param int $id Job ID.
	 * @return string Lease token, or an empty string if the lease is taken.
	 */
	public function claim( $id ) {
		global $wpdb;

		$token = wp_generate_password( 32, false, false );
		$now   = $this->now();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic compare-and-set on the plugin's own table.
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET lease_token = %s, lease_expires = %d, status = %s, updated_at = %s
				WHERE id = %d AND status IN (%s, %s) AND ( lease_token = %s OR lease_expires < %d )',
				$this->table(),
				$token,
				$now + self::LEASE_SECONDS,
				self::STATUS_RUNNING,
				gmdate( 'Y-m-d H:i:s', $now ),
				$id,
				self::STATUS_PENDING,
				self::STATUS_RUNNING,
				'',
				$now
			)
		);

		return 1 === $updated ? $token : '';
	}

	/**
	 * Save state after a step, only while the lease is still held.
	 *
	 * @param int                  $id       Job ID.
	 * @param string               $token    Lease token.
	 * @param array<string, mixed> $state    New state.
	 * @param array<string, mixed> $progress Progress for display.
	 * @return bool False if the lease was lost (expired and taken, or the job
	 *              was cancelled); the caller must stop.
	 */
	public function save_state( $id, $token, array $state, array $progress ) {
		global $wpdb;

		$now = $this->now();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Lease-guarded write to the plugin's own table.
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET state = %s, progress = %s, steps = steps + 1, lease_expires = %d, updated_at = %s
				WHERE id = %d AND lease_token = %s AND status = %s',
				$this->table(),
				wp_json_encode( $state ),
				wp_json_encode( $progress ),
				$now + self::LEASE_SECONDS,
				gmdate( 'Y-m-d H:i:s', $now ),
				$id,
				$token,
				self::STATUS_RUNNING
			)
		);

		return 1 === $updated;
	}

	/**
	 * Release the lease without finishing, so another request can continue.
	 *
	 * @param int    $id    Job ID.
	 * @param string $token Lease token.
	 * @return void
	 */
	public function release( $id, $token ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET lease_token = %s, lease_expires = 0 WHERE id = %d AND lease_token = %s',
				$this->table(),
				'',
				$id,
				$token
			)
		);
	}

	/**
	 * Mark a job finished, only while the lease is held.
	 *
	 * @param int    $id     Job ID.
	 * @param string $token  Lease token.
	 * @param string $status completed or failed.
	 * @param string $error  Error message for failed jobs.
	 * @return bool
	 */
	public function finish( $id, $token, $status, $error = '' ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Lease-guarded write to the plugin's own table.
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, error = %s, lease_token = %s, lease_expires = 0, updated_at = %s
				WHERE id = %d AND lease_token = %s AND status = %s',
				$this->table(),
				$status,
				$error,
				'',
				gmdate( 'Y-m-d H:i:s', $this->now() ),
				$id,
				$token,
				self::STATUS_RUNNING
			)
		);

		return 1 === $updated;
	}

	/**
	 * Cancel a job. Allowed whether or not a lease is held: a step that is
	 * running finishes its current batch, then fails to save and stops.
	 *
	 * @param int $id Job ID.
	 * @return bool True if the job was unfinished and is now cancelled.
	 */
	public function cancel( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, lease_token = %s, lease_expires = 0, updated_at = %s WHERE id = %d AND status IN (%s, %s)',
				$this->table(),
				self::STATUS_CANCELLED,
				'',
				gmdate( 'Y-m-d H:i:s', $this->now() ),
				$id,
				self::STATUS_PENDING,
				self::STATUS_RUNNING
			)
		);

		return 1 === $updated;
	}

	/**
	 * Delete a job row.
	 *
	 * @param int $id Job ID.
	 * @return void
	 */
	public function delete( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
		$wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );
	}
}
