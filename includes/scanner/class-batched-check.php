<?php
/**
 * Base class for checks that walk a table in batches.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Batched checks keep a small cursor array in the scan job's state. Each
 * call to batch() does a bounded amount of work and returns the next
 * cursor, so a scan of any size runs as many short requests and resumes
 * from the last saved cursor after an interruption.
 *
 * A batch must give the same findings when repeated with the same cursor:
 * the scan handler deletes a batch's findings before storing them again.
 */
abstract class Batched_Check extends Check {

	/**
	 * Batched.
	 *
	 * @return bool
	 */
	public function is_batched() {
		return true;
	}

	/**
	 * First cursor. Must contain an integer 'after' (batch key) and may
	 * contain an integer 'max' used for progress.
	 *
	 * @param Scan_Context $context Context.
	 * @return array<string, mixed>
	 */
	abstract public function start( Scan_Context $context );

	/**
	 * Process one batch.
	 *
	 * @param Scan_Context         $context Context.
	 * @param array<string, mixed> $cursor  Cursor from start() or the last batch.
	 * @return array{findings: Finding[], cursor: array<string, mixed>|null, scanned: int}
	 *         A null cursor means the check is finished.
	 */
	abstract public function batch( Scan_Context $context, array $cursor );
}
