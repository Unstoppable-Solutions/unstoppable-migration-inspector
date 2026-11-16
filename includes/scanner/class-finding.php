<?php
/**
 * One finding produced by a check.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * A single affected object (or a site-wide fact) for one kind of problem.
 *
 * The explanation, the reason it matters and the recommended next action
 * belong to the kind and are defined by the check (see Check::kinds()), so
 * they are translated when shown rather than frozen into the database. A
 * finding carries only what is specific to the object: what it is, where
 * the problem is, and a short message.
 */
final class Finding {

	/**
	 * Kind key, defined by the check.
	 *
	 * @var string
	 */
	public $kind;

	/**
	 * Severity; defaults to the kind's severity.
	 *
	 * @var string
	 */
	public $severity;

	/**
	 * Object type: post, attachment, postmeta, option, comment, termmeta,
	 * table, user, file, setting, or '' for site-wide facts.
	 *
	 * @var string
	 */
	public $object_type = '';

	/**
	 * Object identifier (ID, option name, table name…).
	 *
	 * @var string
	 */
	public $object_id = '';

	/**
	 * Where the problem is (column, meta key, file path…).
	 *
	 * @var string
	 */
	public $location = '';

	/**
	 * Short, object-specific message (plain text).
	 *
	 * @var string
	 */
	public $message = '';

	/**
	 * Extra structured details (plain values only).
	 *
	 * @var array<string, mixed>
	 */
	public $details = array();

	/**
	 * Create a finding.
	 *
	 * @param string $kind     Kind key.
	 * @param string $message  Object-specific message.
	 * @param string $severity Severity, or '' for the kind's default.
	 */
	public function __construct( $kind, $message = '', $severity = '' ) {
		$this->kind     = (string) $kind;
		$this->message  = (string) $message;
		$this->severity = (string) $severity;
	}

	/**
	 * Set the affected object.
	 *
	 * @param string     $type     Object type.
	 * @param string|int $id       Object identifier.
	 * @param string     $location Location inside the object.
	 * @return $this
	 */
	public function on( $type, $id, $location = '' ) {
		$this->object_type = (string) $type;
		$this->object_id   = (string) $id;
		$this->location    = (string) $location;

		return $this;
	}

	/**
	 * Attach details.
	 *
	 * @param array<string, mixed> $details Details.
	 * @return $this
	 */
	public function with( array $details ) {
		$this->details = array_merge( $this->details, $details );

		return $this;
	}
}
