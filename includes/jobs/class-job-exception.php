<?php
/**
 * Expected job failure with a message for the administrator.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by job handlers for known problems. The message is shown to the
 * administrator, so it must be translated and must not contain server paths
 * or credentials.
 */
class Job_Exception extends \RuntimeException {
}
