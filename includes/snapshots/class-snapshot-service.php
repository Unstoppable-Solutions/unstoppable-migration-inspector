<?php
/**
 * Entry points for starting snapshot and comparison jobs.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Snapshots;

use UnstoppableSolutions\MigrationInspector\Core\Settings;
use UnstoppableSolutions\MigrationInspector\Jobs\Job_Runner;
use UnstoppableSolutions\MigrationInspector\Jobs\Job_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Creates snapshots and comparisons and the jobs that fill them.
 */
final class Snapshot_Service {

	/**
	 * Job runner with every job type this plugin knows.
	 *
	 * @return Job_Runner
	 */
	public static function runner() {
		return new Job_Runner(
			new Job_Store(),
			array(
				new Capture_Handler(),
				new Compare_Handler(),
			)
		);
	}

	/**
	 * Job types that may be run or cancelled through the API.
	 *
	 * @return string[]
	 */
	public static function job_types() {
		return array( Capture_Handler::TYPE, Compare_Handler::TYPE );
	}

	/**
	 * Start capturing a new snapshot.
	 *
	 * @param string $label Label.
	 * @return array{snapshot_id: int, job_id: int}|\WP_Error
	 */
	public static function start_capture( $label ) {
		$snapshots = new Snapshot_Repository();
		$label     = '' === trim( $label )
			/* translators: %s: date and time. */
			? sprintf( __( 'Snapshot %s', 'unstoppable-migration-inspector' ), wp_date( 'Y-m-d H:i' ) )
			: $label;

		$snapshot_id = $snapshots->create(
			$label,
			'local',
			array(
				'format_version' => 1,
				'created_at'     => time(),
			)
		);

		if ( 0 === $snapshot_id ) {
			return new \WP_Error( 'umin_db', __( 'The snapshot could not be created in the database.', 'unstoppable-migration-inspector' ), array( 'status' => 500 ) );
		}

		$job_id = self::runner()->create(
			Capture_Handler::TYPE,
			array(
				'snapshot_id' => $snapshot_id,
				'batch_size'  => (int) Settings::get( 'batch_size' ),
			)
		);

		if ( 0 === $job_id ) {
			$snapshots->delete( $snapshot_id );
			return new \WP_Error( 'umin_db', __( 'The snapshot job could not be created.', 'unstoppable-migration-inspector' ), array( 'status' => 500 ) );
		}

		$snapshot                     = $snapshots->get( $snapshot_id );
		$snapshot['header']['job_id'] = $job_id;
		$snapshots->save_header( $snapshot_id, $snapshot['header'] );

		return array(
			'snapshot_id' => $snapshot_id,
			'job_id'      => $job_id,
		);
	}

	/**
	 * Start comparing a complete snapshot with this site.
	 *
	 * @param int $snapshot_id Snapshot ID.
	 * @return array{comparison_id: int, job_id: int}|\WP_Error
	 */
	public static function start_compare( $snapshot_id ) {
		$snapshots = new Snapshot_Repository();
		$snapshot  = $snapshots->get( $snapshot_id );

		if ( null === $snapshot ) {
			return new \WP_Error( 'umin_not_found', __( 'Snapshot not found.', 'unstoppable-migration-inspector' ), array( 'status' => 404 ) );
		}

		if ( Snapshot_Repository::STATUS_COMPLETE !== $snapshot['status'] ) {
			return new \WP_Error( 'umin_incomplete', __( 'Only a complete snapshot can be compared.', 'unstoppable-migration-inspector' ), array( 'status' => 409 ) );
		}

		$comparisons   = new Comparison_Repository();
		$comparison_id = $comparisons->create( $snapshot_id );

		if ( 0 === $comparison_id ) {
			return new \WP_Error( 'umin_db', __( 'The comparison could not be created.', 'unstoppable-migration-inspector' ), array( 'status' => 500 ) );
		}

		$job_id = self::runner()->create(
			Compare_Handler::TYPE,
			array(
				'snapshot_id'   => $snapshot_id,
				'comparison_id' => $comparison_id,
				'batch_size'    => (int) Settings::get( 'batch_size' ),
			)
		);

		if ( 0 === $job_id ) {
			$comparisons->delete( $comparison_id );
			return new \WP_Error( 'umin_db', __( 'The comparison job could not be created.', 'unstoppable-migration-inspector' ), array( 'status' => 500 ) );
		}

		$comparisons->set_job( $comparison_id, $job_id );

		return array(
			'comparison_id' => $comparison_id,
			'job_id'        => $job_id,
		);
	}

	/**
	 * Delete a snapshot, cancelling its capture job if one is unfinished.
	 *
	 * @param int $snapshot_id Snapshot ID.
	 * @return bool
	 */
	public static function delete_snapshot( $snapshot_id ) {
		$snapshots = new Snapshot_Repository();
		$snapshot  = $snapshots->get( $snapshot_id );

		if ( null === $snapshot ) {
			return false;
		}

		$store  = new Job_Store();
		$job_id = isset( $snapshot['header']['job_id'] ) ? (int) $snapshot['header']['job_id'] : 0;

		if ( $job_id > 0 ) {
			$store->cancel( $job_id );
		}

		$comparisons = new Comparison_Repository();
		foreach ( $comparisons->ids_for_snapshot( $snapshot_id ) as $comparison_id ) {
			$comparison = $comparisons->get( $comparison_id );
			if ( $comparison && (int) $comparison['job_id'] > 0 ) {
				$store->cancel( (int) $comparison['job_id'] );
			}
		}

		$snapshots->delete( $snapshot_id );

		return true;
	}
}
