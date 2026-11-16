<?php
/**
 * Class autoloader.
 *
 * Maps classes in the plugin namespace to WordPress-style file names:
 * UnstoppableSolutions\MigrationInspector\Core\REST_Controller
 * becomes includes/core/class-rest-controller.php.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector;

defined( 'ABSPATH' ) || exit;

/**
 * Autoloader for plugin classes.
 */
final class Autoloader {

	/**
	 * Namespace prefix handled by this autoloader.
	 */
	const PREFIX = __NAMESPACE__ . '\\';

	/**
	 * Register the autoloader with PHP.
	 *
	 * @return void
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Load a class file if it belongs to this plugin.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return void
	 */
	public static function load( $class_name ) {
		$path = self::path_for( $class_name, __DIR__ );

		if ( '' !== $path && is_readable( $path ) ) {
			require_once $path;
		}
	}

	/**
	 * Work out the file path for a class without touching the filesystem.
	 *
	 * Only letters, digits and underscores are accepted in each name segment,
	 * so a crafted class name cannot point outside the includes folder.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @param string $base_dir   Absolute path of the includes folder.
	 * @return string Absolute file path, or an empty string when not ours.
	 */
	public static function path_for( $class_name, $base_dir ) {
		if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
			return '';
		}

		$relative = substr( $class_name, strlen( self::PREFIX ) );
		$segments = explode( '\\', $relative );

		foreach ( $segments as $segment ) {
			if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $segment ) ) {
				return '';
			}
		}

		$class_segment = array_pop( $segments );
		$file_name     = 'class-' . strtolower( str_replace( '_', '-', $class_segment ) ) . '.php';
		$directories   = array_map( 'strtolower', $segments );

		$parts  = array_merge( array( rtrim( $base_dir, '/\\' ) ), $directories, array( $file_name ) );
		$joined = implode( '/', $parts );

		return $joined;
	}
}
