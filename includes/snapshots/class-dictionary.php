<?php
/**
 * Dictionaries for coded fields.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Snapshots;

defined( 'ABSPATH' ) || exit;

/**
 * Maps short vocabulary strings (post statuses, roles, comment types…) to
 * small integers for storage, and back.
 *
 * Only machine vocabulary is ever stored here. Values that do not look like
 * vocabulary are stored as "(other)", so free text can never end up in a
 * snapshot through this path.
 */
final class Dictionary {

	/**
	 * Code 0 is always the empty string.
	 */
	const OTHER = '(other)';

	/**
	 * Maximum entries per dictionary.
	 */
	const MAX_ENTRIES = 500;

	/**
	 * Dictionary name => list of strings (index = code).
	 *
	 * @var array<string, string[]>
	 */
	private $lists = array();

	/**
	 * Dictionary name => string => code.
	 *
	 * @var array<string, array<string, int>>
	 */
	private $index = array();

	/**
	 * Build from stored lists.
	 *
	 * @param array<string, string[]> $lists Stored lists.
	 */
	public function __construct( array $lists = array() ) {
		foreach ( $lists as $name => $list ) {
			if ( ! is_string( $name ) || ! is_array( $list ) ) {
				continue;
			}

			$this->lists[ $name ] = array_values( array_map( 'strval', $list ) );
			$this->index[ $name ] = array_flip( $this->lists[ $name ] );
		}
	}

	/**
	 * Whether a value is acceptable vocabulary.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	public static function is_vocabulary( $value ) {
		return is_string( $value ) && (bool) preg_match( '/^[A-Za-z0-9_.:-]{0,64}$/', $value );
	}

	/**
	 * Code for a value, adding it if new.
	 *
	 * @param string $name  Dictionary name.
	 * @param string $value Value.
	 * @return int
	 */
	public function code( $name, $value ) {
		$value = self::is_vocabulary( $value ) ? $value : self::OTHER;

		if ( ! isset( $this->lists[ $name ] ) ) {
			$this->lists[ $name ] = array( '' );
			$this->index[ $name ] = array( '' => 0 );
		}

		if ( isset( $this->index[ $name ][ $value ] ) ) {
			return $this->index[ $name ][ $value ];
		}

		if ( count( $this->lists[ $name ] ) >= self::MAX_ENTRIES ) {
			$value = self::OTHER;
			if ( isset( $this->index[ $name ][ $value ] ) ) {
				return $this->index[ $name ][ $value ];
			}
		}

		$code                           = count( $this->lists[ $name ] );
		$this->lists[ $name ][]         = $value;
		$this->index[ $name ][ $value ] = $code;

		return $code;
	}

	/**
	 * Value for a code.
	 *
	 * @param string $name Dictionary name.
	 * @param int    $code Code.
	 * @return string The value, or "(other)" for unknown codes.
	 */
	public function value( $name, $code ) {
		return isset( $this->lists[ $name ][ $code ] ) ? $this->lists[ $name ][ $code ] : self::OTHER;
	}

	/**
	 * Lists for storage.
	 *
	 * @return array<string, string[]>
	 */
	public function to_array() {
		return $this->lists;
	}
}
