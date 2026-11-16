<?php
/**
 * Snapshot file rejected during validation.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Snapshots;

defined( 'ABSPATH' ) || exit;

/**
 * Carries a translated, escaped message explaining why a file was refused.
 */
class Snapshot_Validation_Exception extends \RuntimeException {
}
