<?php
/**
 * Inspection categories and the Migration Health summary.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Defines the inspection categories and works out their current status.
 *
 * The overall status is never "passed" unless every applicable category has
 * actually been tested and passed. A partly tested site is reported as
 * partly tested, not healthy.
 */
final class Health_Summary {

	const STATUS_PASSED         = 'passed';
	const STATUS_WARNING        = 'warning';
	const STATUS_CRITICAL       = 'critical';
	const STATUS_NOT_TESTED     = 'not_tested';
	const STATUS_NOT_APPLICABLE = 'not_applicable';

	/**
	 * Category definitions in display order.
	 *
	 * @return array<string, array{label: string, description: string, action: string}>
	 */
	public static function categories() {
		return array(
			'urls'          => array(
				'label'       => __( 'Domains & URLs', 'unstoppable-migration-inspector' ),
				'description' => __( 'Looks for links that still point to the old domain, a staging site or a temporary host, and checks the WordPress Address and Site Address settings.', 'unstoppable-migration-inspector' ),
				'action'      => __( 'Run a scan to check for leftover domain references.', 'unstoppable-migration-inspector' ),
			),
			'database'      => array(
				'label'       => __( 'Database', 'unstoppable-migration-inspector' ),
				'description' => __( 'Checks that the core WordPress tables are present, the table prefix is consistent and the character sets are compatible.', 'unstoppable-migration-inspector' ),
				'action'      => __( 'Run a scan to check the database structure.', 'unstoppable-migration-inspector' ),
			),
			'media'         => array(
				'label'       => __( 'Media', 'unstoppable-migration-inspector' ),
				'description' => __( 'Finds Media Library items whose files did not arrive, missing image sizes and upload paths that point to the old server.', 'unstoppable-migration-inspector' ),
				'action'      => __( 'Run a scan to check for missing media files.', 'unstoppable-migration-inspector' ),
			),
			'https'         => array(
				'label'       => __( 'HTTPS & Mixed Content', 'unstoppable-migration-inspector' ),
				'description' => __( 'Checks whether the site uses HTTPS and finds insecure http:// resources that browsers would block or warn about.', 'unstoppable-migration-inspector' ),
				'action'      => __( 'Run a scan to check for mixed content.', 'unstoppable-migration-inspector' ),
			),
			'configuration' => array(
				'label'       => __( 'WordPress Configuration', 'unstoppable-migration-inspector' ),
				'description' => __( 'Checks permalinks, the active theme and plugins, upload folder permissions, scheduled tasks and important settings.', 'unstoppable-migration-inspector' ),
				'action'      => __( 'Run a scan to check the WordPress configuration.', 'unstoppable-migration-inspector' ),
			),
			'woocommerce'   => array(
				'label'       => __( 'WooCommerce', 'unstoppable-migration-inspector' ),
				'description' => __( 'Checks store page assignments, order storage (HPOS) and its tables, and product image references.', 'unstoppable-migration-inspector' ),
				'action'      => __( 'Run a scan to check the store.', 'unstoppable-migration-inspector' ),
			),
		);
	}

	/**
	 * Whether a category applies to this site.
	 *
	 * @param string $category Category key.
	 * @return bool
	 */
	public static function is_applicable( $category ) {
		if ( 'woocommerce' === $category ) {
			return class_exists( 'WooCommerce' );
		}

		return true;
	}

	/**
	 * Current status of every category.
	 *
	 * Phase 1 has no scan results yet, so applicable categories report
	 * "not tested". Later phases read the latest completed scan here.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function category_statuses() {
		$rows = array();

		foreach ( self::categories() as $key => $category ) {
			$applicable = self::is_applicable( $key );

			$rows[] = array(
				'id'          => $key,
				'label'       => $category['label'],
				'status'      => $applicable ? self::STATUS_NOT_TESTED : self::STATUS_NOT_APPLICABLE,
				'issues'      => null,
				'description' => $category['description'],
				'action'      => $applicable
					? $category['action']
					: __( 'Nothing to check. This category does not apply to this site.', 'unstoppable-migration-inspector' ),
			);
		}

		return $rows;
	}

	/**
	 * Overall status from category statuses.
	 *
	 * @param array<int, array<string, mixed>> $rows Category rows.
	 * @return string One of the STATUS_* constants.
	 */
	public static function overall_status( array $rows ) {
		$statuses = array();

		foreach ( $rows as $row ) {
			if ( self::STATUS_NOT_APPLICABLE !== $row['status'] ) {
				$statuses[] = $row['status'];
			}
		}

		if ( empty( $statuses ) ) {
			return self::STATUS_NOT_TESTED;
		}

		if ( in_array( self::STATUS_CRITICAL, $statuses, true ) ) {
			return self::STATUS_CRITICAL;
		}

		if ( in_array( self::STATUS_WARNING, $statuses, true ) ) {
			return self::STATUS_WARNING;
		}

		// Anything untested means we cannot call the site healthy.
		if ( in_array( self::STATUS_NOT_TESTED, $statuses, true ) ) {
			return self::STATUS_NOT_TESTED;
		}

		return self::STATUS_PASSED;
	}

	/**
	 * Human label for a status.
	 *
	 * @param string $status Status key.
	 * @return string
	 */
	public static function status_label( $status ) {
		$labels = array(
			self::STATUS_PASSED         => __( 'Passed', 'unstoppable-migration-inspector' ),
			self::STATUS_WARNING        => __( 'Warning', 'unstoppable-migration-inspector' ),
			self::STATUS_CRITICAL       => __( 'Critical', 'unstoppable-migration-inspector' ),
			self::STATUS_NOT_TESTED     => __( 'Not Tested', 'unstoppable-migration-inspector' ),
			self::STATUS_NOT_APPLICABLE => __( 'Not Applicable', 'unstoppable-migration-inspector' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : $labels[ self::STATUS_NOT_TESTED ];
	}

	/**
	 * Dashicon for a status, so status is never shown by colour alone.
	 *
	 * @param string $status Status key.
	 * @return string Dashicon class suffix.
	 */
	public static function status_icon( $status ) {
		$icons = array(
			self::STATUS_PASSED         => 'yes-alt',
			self::STATUS_WARNING        => 'warning',
			self::STATUS_CRITICAL       => 'dismiss',
			self::STATUS_NOT_TESTED     => 'clock',
			self::STATUS_NOT_APPLICABLE => 'minus',
		);

		return isset( $icons[ $status ] ) ? $icons[ $status ] : $icons[ self::STATUS_NOT_TESTED ];
	}

	/**
	 * Full summary used by the dashboard and the REST status route.
	 *
	 * @return array<string, mixed>
	 */
	public static function summary() {
		$rows = self::category_statuses();

		return array(
			'overall'    => self::overall_status( $rows ),
			'categories' => $rows,
			'last_scan'  => null,
		);
	}
}
