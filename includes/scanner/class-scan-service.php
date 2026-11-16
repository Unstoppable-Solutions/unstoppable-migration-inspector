<?php
/**
 * Starting and managing scans.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Scanner;

use UnstoppableSolutions\MigrationInspector\Core\Settings;
use UnstoppableSolutions\MigrationInspector\Jobs\Job_Registry;
use UnstoppableSolutions\MigrationInspector\Jobs\Job_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Entry points used by the REST controller and the admin screens.
 */
final class Scan_Service {

	/**
	 * Start a scan, or return the unfinished one so it can be resumed.
	 *
	 * @param array<string, mixed> $request Request facts: request_host, request_https.
	 * @return array{scan_id: int, job_id: int, resumed: bool}|\WP_Error
	 */
	public static function start( array $request = array() ) {
		$scans  = new Scan_Repository();
		$store  = new Job_Store();
		$latest = $scans->recent( 1 );

		if ( ! empty( $latest ) && Scan_Repository::STATUS_RUNNING === $latest[0]['status'] ) {
			$job_id = isset( $latest[0]['progress']['job_id'] ) ? (int) $latest[0]['progress']['job_id'] : 0;
			$job    = $job_id > 0 ? $store->get( $job_id ) : null;

			if ( null !== $job && in_array( $job['status'], array( Job_Store::STATUS_PENDING, Job_Store::STATUS_RUNNING ), true ) ) {
				return array(
					'scan_id' => $latest[0]['id'],
					'job_id'  => $job_id,
					'resumed' => true,
				);
			}

			$scans->finish( $latest[0]['id'], Scan_Repository::STATUS_FAILED );
		}

		$scan_id = $scans->create();

		if ( 0 === $scan_id ) {
			return new \WP_Error( 'umin_db', __( 'The scan could not be created in the database.', 'unstoppable-migration-inspector' ), array( 'status' => 500 ) );
		}

		$params = array(
			'scan_id'    => $scan_id,
			'batch_size' => (int) Settings::get( 'batch_size' ),
		);

		if ( isset( $request['request_host'] ) ) {
			$params['request_host'] = (string) $request['request_host'];
		}
		if ( array_key_exists( 'request_https', $request ) ) {
			$params['request_https'] = $request['request_https'];
		}

		$job_id = Job_Registry::runner()->create( Scan_Handler::TYPE, $params );

		if ( 0 === $job_id ) {
			$scans->delete( $scan_id );
			return new \WP_Error( 'umin_db', __( 'The scan job could not be created.', 'unstoppable-migration-inspector' ), array( 'status' => 500 ) );
		}

		$scans->save_progress( $scan_id, array( 'job_id' => $job_id ) );

		return array(
			'scan_id' => $scan_id,
			'job_id'  => $job_id,
			'resumed' => false,
		);
	}

	/**
	 * Facts about the current request that a scan reports on.
	 *
	 * @return array<string, mixed>
	 */
	public static function request_facts() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : '';
		$host = (string) preg_replace( '/:\d+$/', '', $host );

		return array(
			'request_host'  => preg_match( '/^[a-z0-9.\-]{1,253}$/', $host ) ? $host : '',
			'request_https' => is_ssl(),
		);
	}

	/**
	 * Delete a scan and its findings (the plugin's own data only).
	 *
	 * @param int $scan_id Scan ID.
	 * @return bool
	 */
	public static function delete( $scan_id ) {
		$scans = new Scan_Repository();
		$scan  = $scans->get( $scan_id );

		if ( null === $scan ) {
			return false;
		}

		$job_id = isset( $scan['progress']['job_id'] ) ? (int) $scan['progress']['job_id'] : 0;

		if ( $job_id > 0 ) {
			Job_Registry::runner()->cancel( $job_id );
			( new Job_Store() )->delete( $job_id );
		}

		$scans->delete( $scan_id );

		return true;
	}
}
