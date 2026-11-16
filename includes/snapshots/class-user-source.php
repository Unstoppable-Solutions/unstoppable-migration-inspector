<?php
/**
 * Users.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Snapshots;

defined( 'ABSPATH' ) || exit;

/**
 * Tracks user accounts by ID and primary role.
 *
 * On multisite only members of the current site are tracked. Logins,
 * emails, names and every other user field are never read.
 */
class User_Source extends Record_Source {

	/**
	 * Field kinds.
	 *
	 * @return array<string, int>
	 */
	public function kinds() {
		return array( 'role' => Chunk_Codec::KIND_UNSIGNED );
	}

	/**
	 * Coded fields.
	 *
	 * @return array<string, string>
	 */
	public function coded_fields() {
		return array( 'role' => 'role' );
	}

	/**
	 * Fetch the next batch.
	 *
	 * @param int $after_id Exclusive lower bound.
	 * @param int $limit    Maximum rows.
	 * @param int $max_id   Inclusive upper bound or 0.
	 * @return array<int, array{id: int, fields: array<string, int|string>, created: int|null}>
	 */
	public function fetch_after( $after_id, $limit, $max_id = 0 ) {
		global $wpdb;

		$cap_key = $wpdb->get_blog_prefix() . 'capabilities';

		if ( is_multisite() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only keyset scan.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT u.ID, u.user_registered, m.meta_value AS caps
					FROM {$wpdb->users} u
					INNER JOIN {$wpdb->usermeta} m ON m.user_id = u.ID AND m.meta_key = %s
					WHERE u.ID > %d AND u.ID <= %d
					ORDER BY u.ID ASC
					LIMIT %d",
					$cap_key,
					$after_id,
					self::upper( $max_id ),
					$limit
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only keyset scan.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT u.ID, u.user_registered, m.meta_value AS caps
					FROM {$wpdb->users} u
					LEFT JOIN {$wpdb->usermeta} m ON m.user_id = u.ID AND m.meta_key = %s
					WHERE u.ID > %d AND u.ID <= %d
					ORDER BY u.ID ASC
					LIMIT %d",
					$cap_key,
					$after_id,
					self::upper( $max_id ),
					$limit
				),
				ARRAY_A
			);
		}

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'id'      => (int) $row['ID'],
				'fields'  => array( 'role' => self::primary_role( $row['caps'] ) ),
				'created' => self::gmt_to_unix( $row['user_registered'] ),
			);
		}

		return $out;
	}

	/**
	 * First granted role from a stored capabilities value.
	 *
	 * The value comes from the database, but it is still parsed defensively:
	 * objects are never instantiated, and anything that is not a plain array
	 * of role => true yields no role.
	 *
	 * @param string|null $caps Serialized capabilities array.
	 * @return string Role name, or an empty string.
	 */
	public static function primary_role( $caps ) {
		if ( ! is_string( $caps ) || '' === $caps || ! is_serialized( $caps ) ) {
			return '';
		}

		// Objects are never instantiated (allowed_classes=false); malformed values return false.
		$value = @unserialize( trim( $caps ), array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged -- Database value parsed without objects; failure handled below.

		if ( ! is_array( $value ) ) {
			return '';
		}

		foreach ( $value as $role => $granted ) {
			if ( is_string( $role ) && true === (bool) $granted ) {
				return $role;
			}
		}

		return '';
	}
}
