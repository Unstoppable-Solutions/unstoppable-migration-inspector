<?php
/**
 * REST routes for scans.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\REST;

use UnstoppableSolutions\MigrationInspector\Scanner\Scan_Repository;
use UnstoppableSolutions\MigrationInspector\Scanner\Scan_Service;

defined( 'ABSPATH' ) || exit;

/**
 * POST /scans starts (or resumes) a scan; the shared /jobs/{id}/run route
 * drives it. GET /scans/{id} returns its status and summary. DELETE removes
 * a scan and its findings (plugin data only). Every route requires the
 * view capability; nothing here changes site content.
 */
class Scans_Controller extends REST_Controller {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$id_arg = array(
			'id' => array(
				'type'              => 'integer',
				'minimum'           => 1,
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/scans',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_scan' ),
					'permission_callback' => array( $this, 'can_view' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/scans/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_scan' ),
					'permission_callback' => array( $this, 'can_view' ),
					'args'                => $id_arg,
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_scan' ),
					'permission_callback' => array( $this, 'can_view' ),
					'args'                => $id_arg,
				),
			)
		);
	}

	/**
	 * Start or resume a scan.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_scan( \WP_REST_Request $request ) {
		unset( $request );
		$result = Scan_Service::start( Scan_Service::request_facts() );

		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * Scan status and summary.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_scan( \WP_REST_Request $request ) {
		$scan = ( new Scan_Repository() )->get( (int) $request['id'] );

		if ( null === $scan ) {
			return new \WP_Error( 'umin_not_found', __( 'Scan not found.', 'unstoppable-migration-inspector' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response(
			array(
				'id'          => $scan['id'],
				'status'      => (string) $scan['status'],
				'started_at'  => (string) $scan['started_at'],
				'finished_at' => (string) $scan['finished_at'],
				'overall'     => isset( $scan['summary']['overall'] ) ? (string) $scan['summary']['overall'] : '',
				'categories'  => isset( $scan['summary']['categories'] ) ? $scan['summary']['categories'] : array(),
			)
		);
	}

	/**
	 * Delete a scan.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_scan( \WP_REST_Request $request ) {
		if ( ! Scan_Service::delete( (int) $request['id'] ) ) {
			return new \WP_Error( 'umin_not_found', __( 'Scan not found.', 'unstoppable-migration-inspector' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( array( 'deleted' => true ) );
	}
}
