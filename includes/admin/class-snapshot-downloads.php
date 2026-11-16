<?php
/**
 * Snapshot export, import and CSV download handlers.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Admin;

use UnstoppableSolutions\MigrationInspector\Core\Capabilities;
use UnstoppableSolutions\MigrationInspector\Snapshots\Comparator;
use UnstoppableSolutions\MigrationInspector\Snapshots\Comparison_Repository;
use UnstoppableSolutions\MigrationInspector\Snapshots\Snapshot_Repository;
use UnstoppableSolutions\MigrationInspector\Snapshots\Snapshot_Transfer;
use UnstoppableSolutions\MigrationInspector\Snapshots\Snapshot_Validation_Exception;

defined( 'ABSPATH' ) || exit;

/**
 * Handles admin-post.php requests that stream files or accept uploads.
 *
 * Every handler checks the capability and a nonce first. Uploaded files are
 * read from PHP's temporary upload location only; nothing is written to
 * the uploads folder or anywhere else on disk.
 */
final class Snapshot_Downloads {

	const EXPORT_ACTION = 'umin_export_snapshot';
	const IMPORT_ACTION = 'umin_import_snapshot';
	const CSV_ACTION    = 'umin_comparison_csv';

	/**
	 * Register handlers.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_post_' . self::EXPORT_ACTION, array( __CLASS__, 'export' ) );
		add_action( 'admin_post_' . self::IMPORT_ACTION, array( __CLASS__, 'import' ) );
		add_action( 'admin_post_' . self::CSV_ACTION, array( __CLASS__, 'csv' ) );
	}

	/**
	 * Export URL for a snapshot.
	 *
	 * @param int  $snapshot_id     Snapshot ID.
	 * @param bool $include_digests Include the content fingerprints.
	 * @return string
	 */
	public static function export_url( $snapshot_id, $include_digests = true ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'  => self::EXPORT_ACTION,
					'id'      => (int) $snapshot_id,
					'digests' => $include_digests ? 1 : 0,
				),
				admin_url( 'admin-post.php' )
			),
			self::EXPORT_ACTION . '_' . (int) $snapshot_id
		);
	}

	/**
	 * CSV URL for one bucket of a comparison.
	 *
	 * @param int    $comparison_id Comparison ID.
	 * @param string $kind          Bucket.
	 * @return string
	 */
	public static function csv_url( $comparison_id, $kind ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::CSV_ACTION,
					'id'     => (int) $comparison_id,
					'kind'   => $kind,
				),
				admin_url( 'admin-post.php' )
			),
			self::CSV_ACTION . '_' . (int) $comparison_id
		);
	}

	/**
	 * Stop unless the current user may use snapshots.
	 *
	 * @return void
	 */
	private static function require_capability() {
		if ( ! Capabilities::current_user_can_view() ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'unstoppable-migration-inspector' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Stream a snapshot as a JSON download.
	 *
	 * @return void
	 */
	public static function export() {
		self::require_capability();

		$id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
		check_admin_referer( self::EXPORT_ACTION . '_' . $id );
		$include_digests = ! isset( $_GET['digests'] ) || '0' !== sanitize_key( wp_unslash( $_GET['digests'] ) );

		$snapshots = new Snapshot_Repository();
		$snapshot  = $snapshots->get( $id );

		if ( null === $snapshot || Snapshot_Repository::STATUS_COMPLETE !== $snapshot['status'] ) {
			wp_die( esc_html__( 'Only a complete snapshot can be exported.', 'unstoppable-migration-inspector' ), '', array( 'response' => 404 ) );
		}

		$host     = wp_parse_url( (string) $snapshot['source_home'], PHP_URL_HOST );
		$filename = sanitize_file_name( 'migration-snapshot-' . ( $host ? $host : 'site' ) . '-' . gmdate( 'Ymd-His', strtotime( $snapshot['created_at'] . ' UTC' ) ) . '.json' );

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		$transfer = new Snapshot_Transfer( $snapshots );
		$transfer->export(
			$id,
			static function ( $piece ) {
				// JSON download, not HTML: every value inside was encoded by wp_json_encode().
				echo $piece; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON file body built with wp_json_encode().
			},
			$include_digests
		);

		exit;
	}

	/**
	 * Accept an uploaded snapshot file.
	 *
	 * @return void
	 */
	public static function import() {
		self::require_capability();
		check_admin_referer( self::IMPORT_ACTION );

		$redirect = Admin_Menu::url( Admin_Menu::SNAPSHOTS_SLUG );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- File upload array; each field is validated below.
		$file = isset( $_FILES['umin_snapshot_file'] ) && is_array( $_FILES['umin_snapshot_file'] ) ? $_FILES['umin_snapshot_file'] : null;

		if ( null === $file || ! isset( $file['error'], $file['tmp_name'], $file['size'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
			self::notice( 'error', self::upload_error_message( null === $file || ! isset( $file['error'] ) ? UPLOAD_ERR_NO_FILE : (int) $file['error'] ) );
			wp_safe_redirect( $redirect );
			exit;
		}

		$tmp = (string) $file['tmp_name'];

		if ( ! is_uploaded_file( $tmp ) ) {
			self::notice( 'error', __( 'The file upload could not be verified.', 'unstoppable-migration-inspector' ) );
			wp_safe_redirect( $redirect );
			exit;
		}

		if ( (int) $file['size'] > Snapshot_Transfer::max_import_bytes() || (int) filesize( $tmp ) > Snapshot_Transfer::max_import_bytes() ) {
			self::notice(
				'error',
				sprintf(
					/* translators: %s: maximum file size, e.g. "32 MB". */
					__( 'The snapshot file is larger than the %s limit.', 'unstoppable-migration-inspector' ),
					size_format( Snapshot_Transfer::max_import_bytes() )
				)
			);
			wp_safe_redirect( $redirect );
			exit;
		}

		// Read straight from PHP's temporary upload file. It is never moved
		// or copied anywhere, and PHP deletes it when the request ends.
		$json = file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local temporary upload verified with is_uploaded_file().

		try {
			$transfer = new Snapshot_Transfer();
			$id       = $transfer->import( false === $json ? '' : $json );
			self::notice( 'success', __( 'Snapshot imported. You can now compare it with this site.', 'unstoppable-migration-inspector' ) );
			$redirect = add_query_arg( 'highlight', $id, $redirect );
		} catch ( Snapshot_Validation_Exception $error ) {
			self::notice( 'error', __( 'The snapshot was not imported.', 'unstoppable-migration-inspector' ) . ' ' . wp_strip_all_tags( html_entity_decode( $error->getMessage(), ENT_QUOTES, 'UTF-8' ) ) );
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Stream one bucket of a comparison as CSV (record type, ID).
	 *
	 * @return void
	 */
	public static function csv() {
		self::require_capability();

		$id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
		check_admin_referer( self::CSV_ACTION . '_' . $id );

		$kind = isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( $_GET['kind'] ) ) : '';
		if ( ! in_array( $kind, array( Comparator::MISSING, Comparator::CHANGED, Comparator::ADDED, Comparator::EXTRA ), true ) ) {
			wp_die( esc_html__( 'Unknown list.', 'unstoppable-migration-inspector' ), '', array( 'response' => 400 ) );
		}

		$comparisons = new Comparison_Repository();
		if ( null === $comparisons->get( $id ) ) {
			wp_die( esc_html__( 'Comparison not found.', 'unstoppable-migration-inspector' ), '', array( 'response' => 404 ) );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="comparison-' . $id . '-' . $kind . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );

		echo "record_type,id\n";

		$after = 0;
		do {
			$page      = $comparisons->ids_page( $id, $kind, $after, 1000 );
			$page_size = count( $page );
			foreach ( $page as $row ) {
				// Type keys match a strict [a-z0-9_:-] pattern and IDs are integers,
				// so nothing here can be interpreted as a spreadsheet formula.
				echo esc_html( $row['type'] ) . ',' . (int) $row['id'] . "\n";
				$after = $row['row'];
			}
		} while ( 1000 === $page_size );

		exit;
	}

	/**
	 * Store a one-time notice for the Snapshots screen.
	 *
	 * @param string $type    success or error.
	 * @param string $message Plain-text message.
	 * @return void
	 */
	private static function notice( $type, $message ) {
		set_transient(
			'umin_snapshot_notice_' . get_current_user_id(),
			array(
				'type'    => 'success' === $type ? 'success' : 'error',
				'message' => $message,
			),
			120
		);
	}

	/**
	 * Take the one-time notice for the current user, if any.
	 *
	 * @return array{type: string, message: string}|null
	 */
	public static function take_notice() {
		$key    = 'umin_snapshot_notice_' . get_current_user_id();
		$notice = get_transient( $key );

		if ( ! is_array( $notice ) || ! isset( $notice['type'], $notice['message'] ) ) {
			return null;
		}

		delete_transient( $key );

		return $notice;
	}

	/**
	 * Explain a PHP upload error.
	 *
	 * @param int $code Upload error code.
	 * @return string
	 */
	private static function upload_error_message( $code ) {
		switch ( $code ) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return __( 'The file is larger than this server accepts for uploads. Ask your host to raise the upload limit, or take a fresh snapshot here instead.', 'unstoppable-migration-inspector' );
			case UPLOAD_ERR_NO_FILE:
				return __( 'Choose a snapshot file to import.', 'unstoppable-migration-inspector' );
			case UPLOAD_ERR_PARTIAL:
				return __( 'The upload was interrupted. Please try again.', 'unstoppable-migration-inspector' );
			default:
				return __( 'The server could not accept the upload.', 'unstoppable-migration-inspector' );
		}
	}
}
