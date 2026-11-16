<?php
/**
 * Time budget for one request.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Tracks how much of a request's time budget is left.
 */
final class Deadline {

	/**
	 * Monotonic time (seconds) at which the budget runs out.
	 *
	 * @var float
	 */
	private $ends_at;

	/**
	 * Clock used for time, injectable for tests.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Create a deadline.
	 *
	 * @param float         $seconds Budget in seconds.
	 * @param callable|null $clock   Returns the current time in seconds.
	 */
	public function __construct( $seconds, $clock = null ) {
		$this->clock   = $clock ? $clock : static function () {
			return microtime( true );
		};
		$this->ends_at = call_user_func( $this->clock ) + max( 0.0, (float) $seconds );
	}

	/**
	 * Whether the budget has run out.
	 *
	 * @return bool
	 */
	public function expired() {
		return call_user_func( $this->clock ) >= $this->ends_at;
	}

	/**
	 * Seconds left, never negative.
	 *
	 * @return float
	 */
	public function remaining() {
		return max( 0.0, $this->ends_at - call_user_func( $this->clock ) );
	}
}
