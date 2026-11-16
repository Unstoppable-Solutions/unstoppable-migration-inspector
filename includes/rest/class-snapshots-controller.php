<?php
/**
 * REST routes for snapshots, comparisons and jobs.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\REST;

use UnstoppableSolutions\MigrationInspector\Core\Settings;
use UnstoppableSolutions\MigrationInspector\Jobs\Deadline;
use UnstoppableSolutions\MigrationInspector\Jobs\Job_Store;
use UnstoppableSolutions\MigrationInspector\Snapshots\Comparator;
use UnstoppableSolutions\MigrationInspector\Snapshots\Comparison_Repository;
use UnstoppableSolutions\MigrationInspector\Snapshots\Snapshot_Service;
use UnstoppableSolutions\MigrationInspector\Snapshots\Source_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Snapshot routes. All of them are read-only for site content: they only
 * read site tables and write the plugin's own tables.
 */
class Snapshots_Controller extends REST_Controller {

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
			'/snapshots',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_snapshot' ),
					'permission_callback' => array( $this, 'can_view' ),
					'args'                => array(
						'label' => array(
							'type'              => 'string',
							'default'           => '',
							'maxLength'         => 190,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/snapshots/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_snapshot' ),
					'permission_callback' => array( $this, 'can_view' ),
					'args'                => $id_arg,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/snapshots/(?P<id>\d+)/compare',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'compare_snapshot' ),
					'permission_callback' => array( $this, 'can_view' ),
					'args'                => array_merge(
						$id_arg,
						array(
							'acknowledge_identity' => array(
								'type'    => 'boolean',
								'default' => false,
							),
						)
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/jobs/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_job' ),
					'permission_callback' => array( $this, 'can_view' ),
					'args'                => $id_arg,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/jobs/(?P<id>\d+)/run',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'run_job' ),
					'permission_callback' => array( $this, 'can_view' ),
					'args'                => $id_arg,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/jobs/(?P<id>\d+)/cancel',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'cancel_job' ),
					'permission_callback' => array( $this, 'can_view' ),
					'args'                => $id_arg,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/comparisons/(?P<id>\d+)/items',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'can_view' ),
					'args'                => array_merge(
						$id_arg,
						array(
							'type' => array(
								'type'     => 'string',
								'required' => true,
								'pattern'  => '^(post:[a-z0-9_-]{1,20}|term:[a-z0-9_-]{1,32}|user|comment|wc_[a-z0-9_]{1,30})$',
							),
							'kind' => array(
								'type'     => 'string',
								'required' => true,
								'enum'     => array( Comparator::MISSING, Comparator::CHANGED, Comparator::ADDED, Comparator::EXTRA ),
							),
							'page' => array(
								'type'    => 'integer',
								'minimum' => 1,
								'default' => 1,
							),
						)
					),
				),
			)
		);
	}

	/**
	 * POST /snapshots: start a capture.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_snapshot( \WP_REST_Request $request ) {
		$result = Snapshot_Service::start_capture( (string) $request->get_param( 'label' ) );

		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * DELETE /snapshots/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_snapshot( \WP_REST_Request $request ) {
		if ( ! Snapshot_Service::delete_snapshot( (int) $request['id'] ) ) {
			return new \WP_Error( 'umin_not_found', __( 'Snapshot not found.', 'unstoppable-migration-inspector' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * POST /snapshots/{id}/compare: start a comparison.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function compare_snapshot( \WP_REST_Request $request ) {
		$result = Snapshot_Service::start_compare( (int) $request['id'], (bool) $request->get_param( 'acknowledge_identity' ) );

		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * Load a job the API may act on.
	 *
	 * @param int $id Job ID.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function load_job( $id ) {
		$store = new Job_Store();
		$job   = $store->get( $id );

		if ( null === $job || ! in_array( $job['type'], Snapshot_Service::job_types(), true ) ) {
			return new \WP_Error( 'umin_not_found', __( 'Job not found.', 'unstoppable-migration-inspector' ), array( 'status' => 404 ) );
		}

		return $job;
	}

	/**
	 * GET /jobs/{id}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_job( \WP_REST_Request $request ) {
		$job = $this->load_job( (int) $request['id'] );

		if ( is_wp_error( $job ) ) {
			return $job;
		}

		$store = new Job_Store();

		return rest_ensure_response(
			array(
				'id'       => $job['id'],
				'type'     => $job['type'],
				'status'   => $store->display_status( $job ),
				'progress' => $job['progress'],
				'message'  => (string) $job['error'],
			)
		);
	}

	/**
	 * POST /jobs/{id}/run: run steps for up to the time budget.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function run_job( \WP_REST_Request $request ) {
		$job = $this->load_job( (int) $request['id'] );

		if ( is_wp_error( $job ) ) {
			return $job;
		}

		$result = Snapshot_Service::runner()->run( $job['id'], new Deadline( (float) Settings::get( 'time_budget' ) ) );

		return rest_ensure_response( $result );
	}

	/**
	 * POST /jobs/{id}/cancel.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function cancel_job( \WP_REST_Request $request ) {
		$job = $this->load_job( (int) $request['id'] );

		if ( is_wp_error( $job ) ) {
			return $job;
		}

		return rest_ensure_response( array( 'cancelled' => Snapshot_Service::runner()->cancel( $job['id'] ) ) );
	}

	/**
	 * GET /comparisons/{id}/items.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_items( \WP_REST_Request $request ) {
		$comparisons = new Comparison_Repository();

		if ( null === $comparisons->get( (int) $request['id'] ) ) {
			return new \WP_Error( 'umin_not_found', __( 'Comparison not found.', 'unstoppable-migration-inspector' ), array( 'status' => 404 ) );
		}

		$type   = (string) $request['type'];
		$result = $comparisons->items( (int) $request['id'], $type, (string) $request['kind'], (int) $request['page'], 100 );

		$result['label'] = Source_Registry::label( $type );

		return rest_ensure_response( $result );
	}
}
