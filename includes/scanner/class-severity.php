<?php
/**
 * Finding severities.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Three levels, in order of urgency.
 *
 * - critical: something is broken or will break (missing core tables, users
 *   without roles, checkout page gone).
 * - warning:  something is wrong and visitors or admins are likely to notice
 *   (links to the old domain, missing media files, insecure resources).
 * - notice:   worth knowing, often expected after a move, never makes the
 *   site look unhealthy on its own.
 */
final class Severity {

	const CRITICAL = 'critical';
	const WARNING  = 'warning';
	const NOTICE   = 'notice';

	/**
	 * All severities, most urgent first.
	 *
	 * @return string[]
	 */
	public static function all() {
		return array( self::CRITICAL, self::WARNING, self::NOTICE );
	}

	/**
	 * Sort rank: lower is more urgent.
	 *
	 * @param string $severity Severity.
	 * @return int
	 */
	public static function rank( $severity ) {
		$rank = array_search( $severity, self::all(), true );

		return false === $rank ? 99 : (int) $rank;
	}

	/**
	 * Whether a value is a known severity.
	 *
	 * @param mixed $severity Value.
	 * @return bool
	 */
	public static function is_valid( $severity ) {
		return in_array( $severity, self::all(), true );
	}

	/**
	 * Translated label.
	 *
	 * @param string $severity Severity.
	 * @return string
	 */
	public static function label( $severity ) {
		switch ( $severity ) {
			case self::CRITICAL:
				return __( 'Critical', 'unstoppable-migration-inspector' );
			case self::WARNING:
				return __( 'Warning', 'unstoppable-migration-inspector' );
			default:
				return __( 'Notice', 'unstoppable-migration-inspector' );
		}
	}
}
