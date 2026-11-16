<?php
/**
 * Every job type the plugin runs.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Jobs;

use UnstoppableSolutions\MigrationInspector\Scanner\Scan_Handler;
use UnstoppableSolutions\MigrationInspector\Snapshots\Capture_Handler;
use UnstoppableSolutions\MigrationInspector\Snapshots\Compare_Handler;

defined( 'ABSPATH' ) || exit;

/**
 * One runner for all job types, so the shared /jobs routes can drive
 * snapshot captures, comparisons and scans alike.
 */
final class Job_Registry {

	/**
	 * A runner with every handler.
	 *
	 * @return Job_Runner
	 */
	public static function runner() {
		return new Job_Runner(
			new Job_Store(),
			array(
				new Capture_Handler(),
				new Compare_Handler(),
				new Scan_Handler(),
			)
		);
	}

	/**
	 * Job types.
	 *
	 * @return string[]
	 */
	public static function types() {
		return array( Capture_Handler::TYPE, Compare_Handler::TYPE, Scan_Handler::TYPE );
	}
}
