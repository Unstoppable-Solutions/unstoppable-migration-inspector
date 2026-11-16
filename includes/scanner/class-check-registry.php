<?php
/**
 * The list of checks.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Scanner;

use UnstoppableSolutions\MigrationInspector\Scanner\Checks;

defined( 'ABSPATH' ) || exit;

/**
 * Checks in the order a scan runs them: quick configuration checks first so
 * the most important results exist early, then the table walks.
 */
final class Check_Registry {

	/**
	 * Every check.
	 *
	 * @return Check[] Keyed by check key.
	 */
	public static function all() {
		$checks = array(
			new Checks\Site_Url_Check(),
			new Checks\Https_Check(),
			new Checks\Core_Tables_Check(),
			new Checks\Prefix_Check(),
			new Checks\Charset_Check(),
			new Checks\Uploads_Check(),
			new Checks\Permalinks_Check(),
			new Checks\Theme_Plugins_Check(),
			new Checks\Cron_Check(),
			new Checks\Constants_Check(),
			new Checks\Search_Visibility_Check(),
			new Checks\Woo_Pages_Check(),
			new Checks\Woo_Storage_Check(),
			new Checks\Woo_Tables_Check(),
		);

		foreach ( array_keys( Checks\Reference_Check::targets() ) as $target ) {
			$checks[] = new Checks\Reference_Check( $target, Checks\Reference_Check::MODE_OLD );
		}

		foreach ( array_keys( Checks\Reference_Check::targets() ) as $target ) {
			$checks[] = new Checks\Reference_Check( $target, Checks\Reference_Check::MODE_INSECURE );
		}

		$checks[] = new Checks\Mixed_Content_Check();
		$checks[] = new Checks\Attachment_Files_Check();
		$checks[] = new Checks\Content_Media_Check();

		$out = array();
		foreach ( $checks as $check ) {
			$out[ $check->key() ] = $check;
		}

		return $out;
	}

	/**
	 * One check by key.
	 *
	 * @param string $key Key.
	 * @return Check|null
	 */
	public static function get( $key ) {
		$all = self::all();

		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}
}
