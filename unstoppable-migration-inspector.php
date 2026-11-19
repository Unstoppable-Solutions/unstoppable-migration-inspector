<?php
/**
 * Plugin Name: Unstoppable Migration Inspector
 * Plugin URI: https://unstoppablesolutions.app/plugins/unstoppable-migration-inspector
 * Description: Checks a WordPress site after it has been moved and explains what did not transfer correctly. Read-only by default.
 * Version: 0.2.1
 * Author: Unstoppable Solutions
 * Author URI: https://unstoppablesolutions.app
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: unstoppable-migration-inspector
 * Domain Path: /languages
 * Requires at least: 6.5
 * Requires PHP: 7.4
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

defined( 'ABSPATH' ) || exit;

define( 'UMIN_VERSION', '0.2.1' );
define( 'UMIN_FILE', __FILE__ );
define( 'UMIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'UMIN_URL', plugin_dir_url( __FILE__ ) );
define( 'UMIN_BASENAME', plugin_basename( __FILE__ ) );

require_once UMIN_DIR . 'includes/class-autoloader.php';

\UnstoppableSolutions\MigrationInspector\Autoloader::register();

register_activation_hook( __FILE__, array( '\UnstoppableSolutions\MigrationInspector\Core\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\UnstoppableSolutions\MigrationInspector\Core\Deactivator', 'deactivate' ) );

\UnstoppableSolutions\MigrationInspector\Core\Plugin::instance()->boot();
