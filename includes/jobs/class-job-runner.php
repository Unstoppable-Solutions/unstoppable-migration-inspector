<?php
/**
 * Runs job steps within a time budget.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Executes a job for one request.
 *
 * Execution and state are separate. State is in the database (Job_Store).
 * Execution happens only here, inside a request made by an administrator's
 * open screen: claim the lease, run steps until the time budget is used,
 * save state after every step, release the lease. Nothing runs in the
 * background.
 */
final class Job_Runner {

	/**
	 * Job store.
	 *
	 * @var Job_Store
	 */
	private $store;

	/**
	 * Handlers by job type.
	 *
	 * @var array<string, Job_Handler>
	 */
	private $handlers = array();

	/**
	 * Constructor.
	 *
	 * @param Job_Store     $store    Job store.
	 * @param Job_Handler[] $handlers Handlers.
	 */
	public function __construct( Job_Store $store, array $handlers ) {
		$this->store = $store;

		foreach ( $handlers as $handler ) {
			$this->handlers[ $handler->type() ] = $handler;
		}
	}

	/**
	 * Create a job.
	 *
	 * @param string               $type   Job type.
	 * @param array<string, mixed> $params Validated parameters.
	 * @return int Job ID, 0 on failure.
	 */
	public function create( $type, array $params ) {
		if ( ! isset( $this->handlers[ $type ] ) ) {
			return 0;
		}

		return $this->store->create( $type, $params, $this->handlers[ $type ]->initial_state( $params ) );
	}

	/**
	 * Run steps for up to the time budget.
	 *
	 * @param int      $id       Job ID.
	 * @param Deadline $deadline Time budget for this request.
	 * @return array{status: string, progress: array<string, mixed>, message: string}
	 */
	public function run( $id, Deadline $deadline ) {
		$job = $this->store->get( $id );

		if ( null === $job ) {
			return $this->result( 'missing', array(), __( 'This job no longer exists.', 'unstoppable-migration-inspector' ) );
		}

		if ( Job_Store::is_finished( $job ) ) {
			return $this->result( $job['status'], $job['progress'], '' );
		}

		if ( ! isset( $this->handlers[ $job['type'] ] ) ) {
			return $this->result( 'failed', $job['progress'], __( 'Unknown job type.', 'unstoppable-migration-inspector' ) );
		}

		$token = $this->store->claim( $id );

		if ( '' === $token ) {
			return $this->result( 'busy', $job['progress'], __( 'This job is already running in another window. It will continue there, or can be resumed here in about a minute if that window was closed.', 'unstoppable-migration-inspector' ) );
		}

		$handler  = $this->handlers[ $job['type'] ];
		$state    = $job['state'];
		$progress = $job['progress'];

		try {
			do {
				$outcome  = $handler->step( $job['params'], $state );
				$state    = $outcome['state'];
				$progress = $outcome['progress'];

				if ( ! $this->store->save_state( $id, $token, $state, $progress ) ) {
					// Cancelled, or the lease was lost to another request.
					$latest = $this->store->get( $id );
					return $this->result( $latest ? $latest['status'] : 'missing', $progress, '' );
				}

				if ( $outcome['done'] ) {
					$handler->on_complete( $job['params'], $state );
					$this->store->finish( $id, $token, Job_Store::STATUS_COMPLETED );
					return $this->result( Job_Store::STATUS_COMPLETED, $progress, '' );
				}
			} while ( ! $deadline->expired() );
		} catch ( \Throwable $error ) {
			$handler->on_stop( $job['params'], $state, Job_Store::STATUS_FAILED );
			$message = $error instanceof Job_Exception
				? $error->getMessage()
				: __( 'The job stopped because of an unexpected error. Details were written to the PHP error log if debugging is enabled.', 'unstoppable-migration-inspector' );

			if ( ! $error instanceof Job_Exception && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Only when debugging is on; no credentials are included.
				error_log( 'Unstoppable Migration Inspector job ' . $id . ': ' . $error->getMessage() );
			}

			$this->store->finish( $id, $token, Job_Store::STATUS_FAILED, $message );
			return $this->result( Job_Store::STATUS_FAILED, $progress, $message );
		}

		$this->store->release( $id, $token );

		return $this->result( Job_Store::STATUS_RUNNING, $progress, '' );
	}

	/**
	 * Cancel a job and let its handler clean up.
	 *
	 * @param int $id Job ID.
	 * @return bool
	 */
	public function cancel( $id ) {
		$job = $this->store->get( $id );

		if ( null === $job || ! $this->store->cancel( $id ) ) {
			return false;
		}

		if ( isset( $this->handlers[ $job['type'] ] ) ) {
			$this->handlers[ $job['type'] ]->on_stop( $job['params'], $job['state'], Job_Store::STATUS_CANCELLED );
		}

		return true;
	}

	/**
	 * Build a result array.
	 *
	 * @param string               $status   Status.
	 * @param array<string, mixed> $progress Progress.
	 * @param string               $message  Message.
	 * @return array{status: string, progress: array<string, mixed>, message: string}
	 */
	private function result( $status, array $progress, $message ) {
		return array(
			'status'   => $status,
			'progress' => $progress,
			'message'  => $message,
		);
	}
}
