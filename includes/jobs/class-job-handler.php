<?php
/**
 * Base class for job types.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * A job type processes its work in small, committed steps.
 *
 * Contract for step():
 * - Do one bounded unit of work (for example one batch of rows).
 * - Make the step's writes safe to repeat: if the request dies after the
 *   writes but before the new state is saved, the same step will run again
 *   with the old state and must reach the same result.
 * - Return the new state. The runner saves it only while the lease is held.
 */
abstract class Job_Handler {

	/**
	 * Machine name of the job type.
	 *
	 * @return string
	 */
	abstract public function type();

	/**
	 * Prepare the initial state for a new job.
	 *
	 * @param array<string, mixed> $params Validated job parameters.
	 * @return array<string, mixed> Initial state.
	 */
	abstract public function initial_state( array $params );

	/**
	 * Run one step.
	 *
	 * @param array<string, mixed> $params Job parameters.
	 * @param array<string, mixed> $state  Current state.
	 * @return array{state: array<string, mixed>, done: bool, progress: array<string, mixed>}
	 */
	abstract public function step( array $params, array $state );

	/**
	 * Called once when the job finishes successfully.
	 *
	 * @param array<string, mixed> $params Job parameters.
	 * @param array<string, mixed> $state  Final state.
	 * @return void
	 */
	public function on_complete( array $params, array $state ) {
		unset( $params, $state );
	}

	/**
	 * Called once when the job is cancelled or fails.
	 *
	 * @param array<string, mixed> $params Job parameters.
	 * @param array<string, mixed> $state  Last saved state.
	 * @param string               $status Final status: cancelled or failed.
	 * @return void
	 */
	public function on_stop( array $params, array $state, $status ) {
		unset( $params, $state, $status );
	}
}
