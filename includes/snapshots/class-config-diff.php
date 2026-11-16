<?php
/**
 * Configuration differences between a snapshot and the live site.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Snapshots;

defined( 'ABSPATH' ) || exit;

/**
 * Lists configuration values that differ, each with a severity and a short
 * explanation.
 *
 * Severity:
 * - info:     expected after a move (new domain, newer WordPress).
 * - warning:  worth checking (theme version, plugin version, prefix).
 * - critical: likely breaks the site (missing plugins or theme, HPOS
 *             storage switched without data, store pages missing).
 */
final class Config_Diff {

	/**
	 * Compare two configuration fingerprints.
	 *
	 * @param array<string, mixed> $before Snapshot configuration.
	 * @param array<string, mixed> $after  Current configuration.
	 * @param bool|null            $roles_ok Whether user roles exist under this site's prefix (null: not checked).
	 * @return array<int, array{key: string, before: mixed, after: mixed, severity: string, note: string}>
	 */
	public static function compare( array $before, array $after, $roles_ok = null ) {
		$diffs = array();

		foreach ( array( 'home', 'siteurl' ) as $key ) {
			if ( self::get( $before, $key ) !== self::get( $after, $key ) ) {
				$diffs[] = self::diff(
					$key,
					self::get( $before, $key ),
					self::get( $after, $key ),
					'info',
					__( 'The site address changed (new domain or folder). Expected after a move and on staging copies. Scan Website checks for leftover references to the old address.', 'unstoppable-migration-inspector' )
				);
			}
		}

		if ( self::get( $before, 'permalink_structure' ) !== self::get( $after, 'permalink_structure' ) ) {
			$diffs[] = self::diff(
				'permalink_structure',
				self::get( $before, 'permalink_structure' ),
				self::get( $after, 'permalink_structure' ),
				'warning',
				__( 'The permalink structure is different, so existing links and search engine results may lead to "page not found".', 'unstoppable-migration-inspector' )
			);
		}

		if ( self::get( $before, 'table_prefix' ) !== self::get( $after, 'table_prefix' ) ) {
			$diffs[] = self::diff(
				'table_prefix',
				self::get( $before, 'table_prefix' ),
				self::get( $after, 'table_prefix' ),
				false === $roles_ok ? 'warning' : 'info',
				true === $roles_ok
					? __( 'The database table prefix changed, and user roles were moved to the new prefix correctly. Common with staging copies and some migration tools.', 'unstoppable-migration-inspector' )
					: ( false === $roles_ok
						? __( 'The database table prefix changed, but user roles were not found under the new prefix, so users may have no role on this site. Scan Website explains how to fix it.', 'unstoppable-migration-inspector' )
						: __( 'The database table prefix changed. Scan Website checks that user roles were moved to the new prefix.', 'unstoppable-migration-inspector' ) )
			);
		}

		$theme_before = self::get( $before, 'theme' );
		$theme_after  = self::get( $after, 'theme' );
		if ( is_array( $theme_before ) && is_array( $theme_after ) ) {
			if ( self::get( $theme_before, 'stylesheet' ) !== self::get( $theme_after, 'stylesheet' ) ) {
				$diffs[] = self::diff(
					'theme',
					self::get( $theme_before, 'stylesheet' ),
					self::get( $theme_after, 'stylesheet' ),
					'critical',
					__( 'A different theme is active. If the original theme did not arrive, WordPress falls back to a default theme and the site looks different.', 'unstoppable-migration-inspector' )
				);
			} elseif ( self::get( $theme_before, 'version' ) !== self::get( $theme_after, 'version' ) ) {
				$diffs[] = self::diff(
					'theme_version',
					self::get( $theme_before, 'version' ),
					self::get( $theme_after, 'version' ),
					'warning',
					__( 'The theme version is different.', 'unstoppable-migration-inspector' )
				);
			}
		}

		$diffs = array_merge( $diffs, self::compare_plugins( (array) self::get( $before, 'plugins' ), (array) self::get( $after, 'plugins' ) ) );
		$diffs = array_merge( $diffs, self::compare_woocommerce( (array) self::get( $before, 'woocommerce' ), (array) self::get( $after, 'woocommerce' ) ) );

		return $diffs;
	}

	/**
	 * Plugin differences.
	 *
	 * @param array<string, string> $before Plugin file => version.
	 * @param array<string, string> $after  Plugin file => version.
	 * @return array<int, array<string, mixed>>
	 */
	private static function compare_plugins( array $before, array $after ) {
		$diffs = array();

		foreach ( $before as $file => $version ) {
			if ( ! array_key_exists( $file, $after ) ) {
				$diffs[] = self::diff(
					'plugin:' . $file,
					$version,
					null,
					'critical',
					__( 'This plugin was active before the move but is not active now. It may be missing or deactivated.', 'unstoppable-migration-inspector' )
				);
			} elseif ( (string) $version !== (string) $after[ $file ] ) {
				$diffs[] = self::diff(
					'plugin:' . $file,
					$version,
					$after[ $file ],
					'warning',
					__( 'This plugin is a different version than before the move.', 'unstoppable-migration-inspector' )
				);
			}
		}

		foreach ( $after as $file => $version ) {
			if ( ! array_key_exists( $file, $before ) && 0 !== strpos( (string) $file, 'unstoppable-migration-inspector/' ) ) {
				$diffs[] = self::diff(
					'plugin:' . $file,
					null,
					$version,
					'info',
					__( 'This plugin is active now but was not active before the move.', 'unstoppable-migration-inspector' )
				);
			}
		}

		return $diffs;
	}

	/**
	 * WooCommerce differences.
	 *
	 * @param array<string, mixed> $before Snapshot WooCommerce data.
	 * @param array<string, mixed> $after  Current WooCommerce data.
	 * @return array<int, array<string, mixed>>
	 */
	private static function compare_woocommerce( array $before, array $after ) {
		$diffs = array();

		if ( empty( $before['active'] ) && empty( $after['active'] ) ) {
			return $diffs;
		}

		if ( ! empty( $before['active'] ) && empty( $after['active'] ) ) {
			$diffs[] = self::diff(
				'woocommerce',
				'active',
				'inactive',
				'critical',
				__( 'WooCommerce was active before the move and is not active now. Orders and products are still compared from the database.', 'unstoppable-migration-inspector' )
			);
		}

		$storage_before = isset( $before['storage'] ) ? (string) $before['storage'] : '';
		$storage_after  = isset( $after['storage'] ) ? (string) $after['storage'] : '';

		if ( '' !== $storage_before && $storage_before !== $storage_after ) {
			$diffs[] = self::diff(
				'woocommerce_storage',
				$storage_before,
				$storage_after,
				'warning',
				__( 'WooCommerce order storage changed between the snapshot and now (HPOS or the older posts storage). Order IDs are kept when WooCommerce moves orders, so orders are still compared by ID. If orders are missing, check that the order data was migrated before the storage was switched.', 'unstoppable-migration-inspector' )
			);
		}

		$pages_before = isset( $before['pages'] ) ? (array) $before['pages'] : array();
		$pages_after  = isset( $after['pages'] ) ? (array) $after['pages'] : array();

		foreach ( $pages_before as $page => $id ) {
			$now = isset( $pages_after[ $page ] ) ? (int) $pages_after[ $page ] : 0;

			if ( (int) $id !== $now ) {
				$diffs[] = self::diff(
					'woocommerce_page:' . $page,
					(int) $id,
					$now,
					'warning',
					__( 'The page WooCommerce uses for this purpose changed.', 'unstoppable-migration-inspector' )
				);
			}

			if ( $now > 0 && function_exists( 'get_post_status' ) && false === get_post_status( $now ) ) {
				$diffs[] = self::diff(
					'woocommerce_page_missing:' . $page,
					$now,
					null,
					'critical',
					__( 'WooCommerce is set to use a page that does not exist on this site.', 'unstoppable-migration-inspector' )
				);
			}
		}

		return $diffs;
	}

	/**
	 * Read a key.
	 *
	 * @param array<string, mixed> $data Data.
	 * @param string               $key  Key.
	 * @return mixed|null
	 */
	private static function get( array $data, $key ) {
		return array_key_exists( $key, $data ) ? $data[ $key ] : null;
	}

	/**
	 * Build a difference entry.
	 *
	 * @param string $key      Key.
	 * @param mixed  $before   Before.
	 * @param mixed  $after    After.
	 * @param string $severity info, warning or critical.
	 * @param string $note     Explanation.
	 * @return array{key: string, before: mixed, after: mixed, severity: string, note: string}
	 */
	private static function diff( $key, $before, $after, $severity, $note ) {
		return array(
			'key'      => $key,
			'before'   => $before,
			'after'    => $after,
			'severity' => $severity,
			'note'     => $note,
		);
	}
}
