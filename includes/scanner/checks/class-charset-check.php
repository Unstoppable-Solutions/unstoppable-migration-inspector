<?php
/**
 * Table character sets and collations.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Scanner\Checks;

use UnstoppableSolutions\MigrationInspector\Scanner\Check;
use UnstoppableSolutions\MigrationInspector\Scanner\Db_Info;
use UnstoppableSolutions\MigrationInspector\Scanner\Finding;
use UnstoppableSolutions\MigrationInspector\Scanner\Scan_Context;
use UnstoppableSolutions\MigrationInspector\Scanner\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * Reads information_schema for this site's tables (MySQL and MariaDB only).
 */
final class Charset_Check extends Check {

	/**
	 * Key.
	 *
	 * @return string
	 */
	public function key() {
		return 'charset_collation';
	}

	/**
	 * Category.
	 *
	 * @return string
	 */
	public function category() {
		return 'database';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Character sets and collations', 'unstoppable-migration-inspector' );
	}

	/**
	 * Kinds.
	 *
	 * @return array<string, array{severity: string, title: string, explanation: string, action: string}>
	 */
	public function kinds() {
		return array(
			'legacy_charset'   => array(
				'severity'    => Severity::WARNING,
				'title'       => __( 'Tables use an older character set than the site', 'unstoppable-migration-inspector' ),
				'explanation' => __( 'WordPress connects with utf8mb4, but these tables use an older character set (such as latin1 or utf8). Emoji and some accented or non-Latin characters can be saved as question marks or garbled text. This often comes from an export or import with the wrong character set.', 'unstoppable-migration-inspector' ),
				'action'      => __( 'Check a few posts with special characters on the front end. Ask your host or a developer to convert the tables to utf8mb4 with a tool designed for it, after a backup. Do not convert by search and replace.', 'unstoppable-migration-inspector' ),
			),
			'mixed_collations' => array(
				'severity'    => Severity::NOTICE,
				'title'       => __( 'Tables use a mix of collations', 'unstoppable-migration-inspector' ),
				'explanation' => __( 'This site\'s tables do not all use the same collation (sorting rule). It rarely causes problems, but some plugins\' queries that join tables can fail with "Illegal mix of collations" errors.', 'unstoppable-migration-inspector' ),
				'action'      => __( 'No action needed unless you see collation errors. If you do, ask your host to align the collations.', 'unstoppable-migration-inspector' ),
			),
		);
	}

	/**
	 * Applicability.
	 *
	 * @param Scan_Context $context Context.
	 * @return true|string
	 */
	public function applicable( Scan_Context $context ) {
		unset( $context );

		return Db_Info::is_sqlite() ? __( 'The site uses SQLite, which has no table character sets.', 'unstoppable-migration-inspector' ) : true;
	}

	/**
	 * Run.
	 *
	 * @param Scan_Context $context Context.
	 * @return Finding[]
	 */
	public function run( Scan_Context $context ) {
		global $wpdb;

		unset( $context );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only schema information.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT TABLE_NAME AS name, TABLE_COLLATION AS collation FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ), ARRAY_A );

		$findings   = array();
		$collations = array();
		$wants_mb4  = 'utf8mb4' === $wpdb->charset;

		foreach ( $rows as $row ) {
			$collation = strtolower( (string) $row['collation'] );

			if ( '' === $collation ) {
				continue;
			}

			$collations[ $collation ][] = (string) $row['name'];

			if ( $wants_mb4 && 0 !== strpos( $collation, 'utf8mb4' ) ) {
				$findings[] = ( new Finding( 'legacy_charset', $collation ) )->on( 'table', (string) $row['name'] );
			}
		}

		if ( count( $collations ) > 1 ) {
			$summary = array();
			foreach ( $collations as $collation => $tables ) {
				$summary[ $collation ] = count( $tables );
			}
			arsort( $summary );
			$findings[] = ( new Finding( 'mixed_collations', implode( ', ', array_keys( $summary ) ) ) )->with( array( 'collations' => $summary ) );
		}

		return $findings;
	}
}
