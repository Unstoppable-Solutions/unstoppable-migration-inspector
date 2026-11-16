<?php
/**
 * Base class for scanner checks.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * A check reads the site and reports findings. It never writes anything.
 *
 * Every kind of finding a check can report is declared in kinds(), with a
 * severity, a title, an explanation of why it matters and a recommended
 * next action. The results screen shows those for each group of findings.
 */
abstract class Check {

	/**
	 * Unique key (lower case, underscores).
	 *
	 * @return string
	 */
	abstract public function key();

	/**
	 * Health category: urls, database, media, https, configuration or woocommerce.
	 *
	 * @return string
	 */
	abstract public function category();

	/**
	 * Short label shown while the scan runs and in the "checked" list.
	 *
	 * @return string
	 */
	abstract public function label();

	/**
	 * The kinds of finding this check reports.
	 *
	 * @return array<string, array{severity: string, title: string, explanation: string, action: string}>
	 */
	abstract public function kinds();

	/**
	 * Whether the check applies to this site.
	 *
	 * @param Scan_Context $context Context.
	 * @return true|string True, or the reason it does not apply.
	 */
	public function applicable( Scan_Context $context ) {
		unset( $context );

		return true;
	}

	/**
	 * Whether the check runs in batches (see Batched_Check).
	 *
	 * @return bool
	 */
	public function is_batched() {
		return false;
	}

	/**
	 * Run a quick check in one go.
	 *
	 * @param Scan_Context $context Context.
	 * @return Finding[]
	 */
	public function run( Scan_Context $context ) {
		unset( $context );

		return array();
	}

	/**
	 * Definition of one kind, or null.
	 *
	 * @param string $kind Kind key.
	 * @return array{severity: string, title: string, explanation: string, action: string}|null
	 */
	public function kind( $kind ) {
		$kinds = $this->kinds();

		return isset( $kinds[ $kind ] ) ? $kinds[ $kind ] : null;
	}
}
