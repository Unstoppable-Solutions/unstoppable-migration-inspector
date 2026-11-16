<?php
/**
 * PHPUnit bootstrap for unit tests.
 *
 * Unit tests run without WordPress. WordPress functions are stubbed per test
 * with Brain Monkey. Integration behaviour (activation, real database,
 * screens) is covered by tests/smoke against a real WordPress install.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/umin-fake-wp/' );
}

require_once dirname( __DIR__ ) . '/includes/class-autoloader.php';

\UnstoppableSolutions\MigrationInspector\Autoloader::register();

require_once __DIR__ . '/unit/class-test-case.php';
