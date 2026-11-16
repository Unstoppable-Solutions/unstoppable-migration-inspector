<?php
/**
 * REST API base controller.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\REST;

use UnstoppableSolutions\MigrationInspector\Core\Capabilities;
use UnstoppableSolutions\MigrationInspector\Core\Health_Summary;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin's REST routes.
 *
 * Every route has an explicit permission callback. Cookie-authenticated
 * requests from the admin screens carry the standard wp_rest nonce, which
 * WordPress verifies before the permission callback runs.
 */
class REST_Controller {

	/**
	 * Route namespace.
	 */
	const NAMESPACE_V1 = 'unstoppable-migration-inspector/v1';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/status',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_status' ),
					'permission_callback' => array( $this, 'can_view' ),
					'args'                => array(),
				),
			)
		);
	}

	/**
	 * Permission callback for read-only routes.
	 *
	 * @return true|\WP_Error
	 */
	public function can_view() {
		if ( Capabilities::current_user_can_view() ) {
			return true;
		}

		return new \WP_Error(
			'umin_forbidden',
			__( 'You do not have permission to view migration inspection results.', 'unstoppable-migration-inspector' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * GET /status: the Migration Health summary.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_status() {
		$summary = Health_Summary::summary();

		$summary['can_modify']      = Capabilities::current_user_can_modify();
		$summary['writes_disabled'] = Capabilities::writes_disabled();

		return rest_ensure_response( $summary );
	}
}
