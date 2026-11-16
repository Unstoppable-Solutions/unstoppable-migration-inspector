<?php
/**
 * Tests for the autoloader, capability map and health summary.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Tests;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use UnstoppableSolutions\MigrationInspector\Autoloader;
use UnstoppableSolutions\MigrationInspector\Core\Capabilities;
use UnstoppableSolutions\MigrationInspector\Core\Health_Summary;

/**
 * Core class tests.
 */
final class CoreTest extends Test_Case {

	public function test_autoloader_maps_namespace_to_wordpress_file_names() {
		$this->assertSame(
			'/base/rest/class-rest-controller.php',
			Autoloader::path_for( 'UnstoppableSolutions\MigrationInspector\REST\REST_Controller', '/base' )
		);
		$this->assertSame(
			'/base/core/class-health-summary.php',
			Autoloader::path_for( 'UnstoppableSolutions\MigrationInspector\Core\Health_Summary', '/base/' )
		);
	}

	public function test_autoloader_ignores_foreign_and_unsafe_class_names() {
		$this->assertSame( '', Autoloader::path_for( 'Other\Plugin\Thing', '/base' ) );
		$this->assertSame( '', Autoloader::path_for( 'UnstoppableSolutions\MigrationInspector\..\Evil', '/base' ) );
		$this->assertSame( '', Autoloader::path_for( 'UnstoppableSolutions\MigrationInspector\Core\Bad/Name', '/base' ) );
	}

	public function test_every_plugin_class_autoloads() {
		$classes = array(
			'Core\Plugin',
			'Core\Activator',
			'Core\Deactivator',
			'Core\Schema',
			'Core\Capabilities',
			'Core\Settings',
			'Core\Health_Summary',
			'REST\REST_Controller',
			'Admin\Admin_Menu',
			'Admin\Assets',
			'Admin\Dashboard_Page',
			'Admin\Settings_Page',
		);

		foreach ( $classes as $class ) {
			$this->assertTrue(
				class_exists( 'UnstoppableSolutions\MigrationInspector\\' . $class ),
				$class . ' should autoload'
			);
		}
	}

	public function test_other_capabilities_pass_through_unchanged() {
		$this->assertSame( array( 'edit_posts' ), Capabilities::map_meta_cap( array( 'edit_posts' ), 'edit_post' ) );
	}

	public function test_modify_requires_manage_options_on_single_site() {
		Functions\when( 'is_multisite' )->justReturn( false );

		$this->assertSame( array( 'manage_options' ), Capabilities::map_meta_cap( array(), Capabilities::MODIFY ) );
	}

	public function test_modify_requires_super_admin_on_multisite() {
		Functions\when( 'is_multisite' )->justReturn( true );

		$this->assertSame( array( 'manage_network_options' ), Capabilities::map_meta_cap( array(), Capabilities::MODIFY ) );
	}

	public function test_modify_filter_returning_garbage_denies() {
		Functions\when( 'is_multisite' )->justReturn( false );
		Filters\expectApplied( 'umin_modify_database_capability' )->andReturn( array( 'not', 'a', 'string' ) );

		$this->assertSame( array( 'do_not_allow' ), Capabilities::map_meta_cap( array(), Capabilities::MODIFY ) );
	}

	public function test_overall_is_not_tested_when_anything_is_untested() {
		$rows = array(
			array( 'status' => Health_Summary::STATUS_PASSED ),
			array( 'status' => Health_Summary::STATUS_NOT_TESTED ),
			array( 'status' => Health_Summary::STATUS_NOT_APPLICABLE ),
		);

		$this->assertSame( Health_Summary::STATUS_NOT_TESTED, Health_Summary::overall_status( $rows ) );
	}

	public function test_overall_passes_only_when_all_applicable_passed() {
		$rows = array(
			array( 'status' => Health_Summary::STATUS_PASSED ),
			array( 'status' => Health_Summary::STATUS_PASSED ),
			array( 'status' => Health_Summary::STATUS_NOT_APPLICABLE ),
		);

		$this->assertSame( Health_Summary::STATUS_PASSED, Health_Summary::overall_status( $rows ) );
	}

	public function test_overall_worst_status_wins() {
		$rows = array(
			array( 'status' => Health_Summary::STATUS_WARNING ),
			array( 'status' => Health_Summary::STATUS_CRITICAL ),
			array( 'status' => Health_Summary::STATUS_NOT_TESTED ),
		);

		$this->assertSame( Health_Summary::STATUS_CRITICAL, Health_Summary::overall_status( $rows ) );
	}

	public function test_overall_with_nothing_applicable_is_not_tested() {
		$rows = array( array( 'status' => Health_Summary::STATUS_NOT_APPLICABLE ) );

		$this->assertSame( Health_Summary::STATUS_NOT_TESTED, Health_Summary::overall_status( $rows ) );
	}

	public function test_woocommerce_category_not_applicable_without_woocommerce() {
		$rows = Health_Summary::category_statuses();
		$woo  = array_values(
			array_filter(
				$rows,
				static function ( $row ) {
					return 'woocommerce' === $row['id'];
				}
			)
		);

		$this->assertSame( Health_Summary::STATUS_NOT_APPLICABLE, $woo[0]['status'] );
	}

	public function test_every_status_has_label_and_icon() {
		$statuses = array(
			Health_Summary::STATUS_PASSED,
			Health_Summary::STATUS_WARNING,
			Health_Summary::STATUS_CRITICAL,
			Health_Summary::STATUS_NOT_TESTED,
			Health_Summary::STATUS_NOT_APPLICABLE,
		);

		$icons = array();
		foreach ( $statuses as $status ) {
			$this->assertNotSame( '', Health_Summary::status_label( $status ) );
			$icons[] = Health_Summary::status_icon( $status );
		}

		$this->assertCount( 5, array_unique( $icons ), 'Each status needs its own icon so status is not shown by colour alone.' );
	}
}
