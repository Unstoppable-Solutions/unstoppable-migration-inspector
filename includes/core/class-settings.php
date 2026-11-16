<?php
/**
 * Plugin settings: schema, defaults, validation and scoped saves.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Stores all settings in one option and validates every field.
 *
 * The settings screen is split into tabs and each tab posts only its own
 * fields. Each form declares the fields it owns, and only those are updated;
 * everything else keeps its stored value. Without this, saving one tab would
 * reset the others to defaults.
 */
final class Settings {

	/**
	 * Option name.
	 */
	const OPTION = 'umin_settings';

	/**
	 * Settings API group used by the form.
	 */
	const GROUP = 'umin_settings_group';

	/**
	 * Request key listing the fields a form owns.
	 */
	const DECLARED_KEY = '_declared';

	/**
	 * Maximum number of previous domains.
	 */
	const MAX_PREVIOUS_DOMAINS = 10;

	/**
	 * Register the setting with WordPress.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_init', array( __CLASS__, 'register_setting' ) );
	}

	/**
	 * Settings API registration.
	 *
	 * @return void
	 */
	public static function register_setting() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_option' ),
				'default'           => self::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Default values for every setting.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			'previous_domains'         => array(),
			'batch_size'               => 500,
			'time_budget'              => 8,
			'report_retention_days'    => 90,
			'delete_data_on_uninstall' => false,
		);
	}

	/**
	 * Field types, used for validation and for unchecked checkboxes.
	 *
	 * @return array<string, string>
	 */
	public static function field_types() {
		return array(
			'previous_domains'         => 'domains',
			'batch_size'               => 'int',
			'time_budget'              => 'int',
			'report_retention_days'    => 'int',
			'delete_data_on_uninstall' => 'bool',
		);
	}

	/**
	 * Allowed ranges for integer fields (inclusive).
	 *
	 * @return array<string, int[]>
	 */
	public static function int_ranges() {
		return array(
			'batch_size'            => array( 50, 5000 ),
			'time_budget'           => array( 2, 20 ),
			'report_retention_days' => array( 0, 3650 ),
		);
	}

	/**
	 * All settings, with defaults filled in for anything missing or invalid.
	 *
	 * @return array<string, mixed>
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$settings = self::defaults();

		foreach ( self::field_types() as $key => $type ) {
			if ( ! array_key_exists( $key, $stored ) ) {
				continue;
			}

			$result = self::validate_field( $key, $type, $stored[ $key ] );

			if ( $result['valid'] ) {
				$settings[ $key ] = $result['value'];
			}
		}

		return $settings;
	}

	/**
	 * A single setting.
	 *
	 * @param string $key Setting name.
	 * @return mixed|null Null when the key is unknown.
	 */
	public static function get( $key ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : null;
	}

	/**
	 * Create the option with defaults if it does not exist yet.
	 *
	 * @return void
	 */
	public static function ensure_defaults() {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, self::defaults(), '', false );
		}
	}

	/**
	 * Settings API sanitize callback.
	 *
	 * Also runs when the option is written programmatically (add_option,
	 * update_option), in which case the input is the complete settings array.
	 *
	 * @param mixed $input Submitted value.
	 * @return array<string, mixed>
	 */
	public static function sanitize_option( $input ) {
		$result = self::merge_submission( is_array( $input ) ? $input : array(), self::all() );

		foreach ( $result['errors'] as $key => $message ) {
			if ( function_exists( 'add_settings_error' ) ) {
				add_settings_error( self::OPTION, 'umin_invalid_' . $key, $message, 'error' );
			}
		}

		return $result['settings'];
	}

	/**
	 * Merge a (possibly partial) submission into the current settings.
	 *
	 * Rules:
	 * - When the submission declares its fields, only those fields change.
	 *   Undeclared fields keep their current values, even if posted.
	 * - When nothing is declared, the submission is a complete settings array
	 *   (programmatic save) and every known field present in it is applied.
	 * - Unknown keys are ignored, so extra fields cannot be injected.
	 * - A declared checkbox that was not posted is saved as false.
	 * - An invalid value is rejected: the current value is kept and an error
	 *   message is returned for that field.
	 *
	 * @param array<string, mixed> $input   Submitted values.
	 * @param array<string, mixed> $current Current settings.
	 * @return array{settings: array<string, mixed>, errors: array<string, string>}
	 */
	public static function merge_submission( array $input, array $current ) {
		$types    = self::field_types();
		$declared = self::declared_fields( $input );
		$settings = array_merge( self::defaults(), array_intersect_key( $current, $types ) );
		$errors   = array();

		$fields = null === $declared ? array_keys( array_intersect_key( $input, $types ) ) : $declared;

		foreach ( $fields as $key ) {
			$type = $types[ $key ];

			if ( ! array_key_exists( $key, $input ) ) {
				if ( 'bool' === $type ) {
					$settings[ $key ] = false;
				}
				continue;
			}

			$result = self::validate_field( $key, $type, $input[ $key ] );

			if ( $result['valid'] ) {
				$settings[ $key ] = $result['value'];
			} else {
				$errors[ $key ] = $result['message'];
			}
		}

		return array(
			'settings' => $settings,
			'errors'   => $errors,
		);
	}

	/**
	 * Fields declared by the form, limited to known field names.
	 *
	 * @param array<string, mixed> $input Submitted values.
	 * @return string[]|null Null when the submission declares nothing.
	 */
	private static function declared_fields( array $input ) {
		if ( ! isset( $input[ self::DECLARED_KEY ] ) ) {
			return null;
		}

		$raw = $input[ self::DECLARED_KEY ];

		if ( is_string( $raw ) ) {
			$raw = explode( ',', $raw );
		}

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$known    = array_keys( self::field_types() );
		$declared = array();

		foreach ( $raw as $name ) {
			$name = is_string( $name ) ? trim( $name ) : '';

			if ( in_array( $name, $known, true ) && ! in_array( $name, $declared, true ) ) {
				$declared[] = $name;
			}
		}

		return $declared;
	}

	/**
	 * Validate one field.
	 *
	 * @param string $key   Field name.
	 * @param string $type  Field type.
	 * @param mixed  $value Raw value.
	 * @return array{valid: bool, value: mixed, message: string}
	 */
	public static function validate_field( $key, $type, $value ) {
		switch ( $type ) {
			case 'bool':
				return self::ok( in_array( $value, array( true, 1, '1', 'yes', 'on', 'true' ), true ) );

			case 'int':
				return self::validate_int( $key, $value );

			case 'domains':
				return self::validate_domains( $value );
		}

		return self::fail( __( 'Unknown setting.', 'unstoppable-migration-inspector' ) );
	}

	/**
	 * Validate an integer field against its allowed range.
	 *
	 * @param string $key   Field name.
	 * @param mixed  $value Raw value.
	 * @return array{valid: bool, value: mixed, message: string}
	 */
	private static function validate_int( $key, $value ) {
		$ranges = self::int_ranges();
		$range  = isset( $ranges[ $key ] ) ? $ranges[ $key ] : array( 0, PHP_INT_MAX );

		if ( is_string( $value ) ) {
			$value = trim( $value );
		}

		if ( ! is_int( $value ) && ! ( is_string( $value ) && preg_match( '/^-?\d+$/', $value ) ) ) {
			return self::fail(
				sprintf(
					/* translators: 1: minimum value, 2: maximum value. */
					__( 'Please enter a whole number between %1$d and %2$d.', 'unstoppable-migration-inspector' ),
					$range[0],
					$range[1]
				)
			);
		}

		$number = (int) $value;

		if ( $number < $range[0] || $number > $range[1] ) {
			return self::fail(
				sprintf(
					/* translators: 1: minimum value, 2: maximum value. */
					__( 'Please enter a whole number between %1$d and %2$d.', 'unstoppable-migration-inspector' ),
					$range[0],
					$range[1]
				)
			);
		}

		return self::ok( $number );
	}

	/**
	 * Validate the list of previous domains.
	 *
	 * Accepts one entry per line (or an array). Each entry may be a bare host
	 * ("old.example.com") or a URL ("https://old.example.com/blog"). Entries
	 * are stored as normalised URLs without a trailing slash. The whole list
	 * is rejected if any line is invalid, so nothing is silently dropped.
	 *
	 * @param mixed $value Raw value.
	 * @return array{valid: bool, value: mixed, message: string}
	 */
	private static function validate_domains( $value ) {
		if ( is_string( $value ) ) {
			$value = preg_split( '/\r\n|\r|\n/', $value );
		}

		if ( ! is_array( $value ) ) {
			return self::fail( __( 'Previous domains must be a list.', 'unstoppable-migration-inspector' ) );
		}

		$domains = array();

		foreach ( $value as $line ) {
			if ( ! is_string( $line ) ) {
				return self::fail( __( 'Previous domains must be a list.', 'unstoppable-migration-inspector' ) );
			}

			$line = trim( $line );

			if ( '' === $line ) {
				continue;
			}

			$normalized = self::normalize_domain( $line );

			if ( '' === $normalized ) {
				return self::fail(
					sprintf(
						/* translators: %s: the invalid entry. */
						__( '"%s" is not a valid domain or URL. Enter one per line, for example old-site.com or https://staging.example.com.', 'unstoppable-migration-inspector' ),
						sanitize_text_field( $line )
					)
				);
			}

			if ( ! in_array( $normalized, $domains, true ) ) {
				$domains[] = $normalized;
			}
		}

		if ( count( $domains ) > self::MAX_PREVIOUS_DOMAINS ) {
			return self::fail(
				sprintf(
					/* translators: %d: maximum number of entries. */
					__( 'Please enter no more than %d previous domains.', 'unstoppable-migration-inspector' ),
					self::MAX_PREVIOUS_DOMAINS
				)
			);
		}

		return self::ok( $domains );
	}

	/**
	 * Normalise a domain or URL entry.
	 *
	 * @param string $entry User entry.
	 * @return string Normalised URL, or an empty string when invalid.
	 */
	public static function normalize_domain( $entry ) {
		$entry = trim( $entry );

		if ( '' === $entry || strlen( $entry ) > 255 || preg_match( '/\s/', $entry ) ) {
			return '';
		}

		if ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $entry ) ) {
			$entry = 'https://' . $entry;
		}

		$parts = wp_parse_url( $entry );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return '';
		}

		$scheme = strtolower( $parts['scheme'] );

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return '';
		}

		$host = strtolower( $parts['host'] );

		$is_ip   = false !== filter_var( $host, FILTER_VALIDATE_IP );
		$is_host = (bool) preg_match( '/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/', $host );

		if ( ! $is_ip && ! $is_host ) {
			return '';
		}

		$url = $scheme . '://' . $host;

		if ( isset( $parts['port'] ) ) {
			$url .= ':' . (int) $parts['port'];
		}

		if ( isset( $parts['path'] ) && '/' !== $parts['path'] ) {
			$path = '/' . trim( $parts['path'], '/' );

			if ( ! preg_match( '#^/[A-Za-z0-9._~%!$&\'()*+,;=:@/-]*$#', $path ) ) {
				return '';
			}

			$url .= $path;
		}

		return $url;
	}

	/**
	 * Successful validation result.
	 *
	 * @param mixed $value Clean value.
	 * @return array{valid: bool, value: mixed, message: string}
	 */
	private static function ok( $value ) {
		return array(
			'valid'   => true,
			'value'   => $value,
			'message' => '',
		);
	}

	/**
	 * Failed validation result.
	 *
	 * @param string $message Explanation for the user.
	 * @return array{valid: bool, value: mixed, message: string}
	 */
	private static function fail( $message ) {
		return array(
			'valid'   => false,
			'value'   => null,
			'message' => $message,
		);
	}
}
