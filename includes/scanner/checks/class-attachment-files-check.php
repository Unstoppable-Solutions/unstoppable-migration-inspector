<?php
/**
 * Media Library files on disk.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Scanner\Checks;

use UnstoppableSolutions\MigrationInspector\Scanner\Batched_Check;
use UnstoppableSolutions\MigrationInspector\Scanner\Db_Info;
use UnstoppableSolutions\MigrationInspector\Scanner\Finding;
use UnstoppableSolutions\MigrationInspector\Scanner\Row_Reader;
use UnstoppableSolutions\MigrationInspector\Scanner\Scan_Context;
use UnstoppableSolutions\MigrationInspector\Scanner\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * For each attachment: does the original file exist, and do the generated
 * image sizes listed in its metadata exist? Only file_exists() and a
 * directory listing (for case hints) are used; files are never opened.
 */
final class Attachment_Files_Check extends Batched_Check {

	/**
	 * Key.
	 *
	 * @return string
	 */
	public function key() {
		return 'attachment_files';
	}

	/**
	 * Category.
	 *
	 * @return string
	 */
	public function category() {
		return 'media';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Media Library files', 'unstoppable-migration-inspector' );
	}

	/**
	 * Kinds.
	 *
	 * @return array<string, array{severity: string, title: string, explanation: string, action: string}>
	 */
	public function kinds() {
		return array(
			'missing_file'  => array(
				'severity'    => Severity::WARNING,
				'title'       => __( 'Media Library items whose file is missing', 'unstoppable-migration-inspector' ),
				'explanation' => __( 'These items are in the Media Library, but their file is not in the uploads folder. Wherever they are used, visitors see a broken image or a download that fails. Usually the uploads folder (or part of it) was not copied to the new server.', 'unstoppable-migration-inspector' ),
				'action'      => __( 'Copy the missing files from the old server or a backup into the same folders under wp-content/uploads. Run the scan again to confirm. If the site uses a media offload plugin, check its settings instead.', 'unstoppable-migration-inspector' ),
			),
			'case_mismatch' => array(
				'severity'    => Severity::WARNING,
				'title'       => __( 'Media files exist with different upper/lower case', 'unstoppable-migration-inspector' ),
				'explanation' => __( 'A file with the same name but different capitalisation exists. The old server ignored case (Windows or macOS), the new one does not (Linux), so WordPress cannot find the file.', 'unstoppable-migration-inspector' ),
				'action'      => __( 'Rename the file on the server to the exact name shown, or re-upload it. Search & Replace can update references if you prefer to keep the new name.', 'unstoppable-migration-inspector' ),
			),
			'absolute_path' => array(
				'severity'    => Severity::WARNING,
				'title'       => __( 'Media items point to a full server path from another server', 'unstoppable-migration-inspector' ),
				'explanation' => __( 'The stored file location is a full server path (for example /home/olduser/public_html/…) that does not exist here. WordPress stores a path relative to the uploads folder; a full path usually comes from the old server.', 'unstoppable-migration-inspector' ),
				'action'      => __( 'Re-attach the files with a media tool, or update the stored path ("_wp_attached_file") to the part after wp-content/uploads/, after a backup.', 'unstoppable-migration-inspector' ),
			),
			'missing_sizes' => array(
				'severity'    => Severity::NOTICE,
				'title'       => __( 'Images whose thumbnails or other sizes are missing', 'unstoppable-migration-inspector' ),
				'explanation' => __( 'The original image is present, but some of the smaller sizes WordPress made from it are not. Themes that ask for those sizes show a broken image or fall back to the full-size file, which is slower.', 'unstoppable-migration-inspector' ),
				'action'      => __( 'Copy the missing size files from the old server, or regenerate thumbnails with a thumbnail regeneration plugin or WP-CLI ("wp media regenerate").', 'unstoppable-migration-inspector' ),
			),
			'no_file_meta'  => array(
				'severity'    => Severity::NOTICE,
				'title'       => __( 'Media Library items with no file recorded', 'unstoppable-migration-inspector' ),
				'explanation' => __( 'These items have no stored file location at all. Their custom fields may not have been imported, or they are external media added by a plugin.', 'unstoppable-migration-inspector' ),
				'action'      => __( 'Open a few of them in the Media Library. If they show nothing, re-import or remove them. If a plugin manages them, no action is needed.', 'unstoppable-migration-inspector' ),
			),
		);
	}

	/**
	 * Applicability.
	 *
	 * @param Scan_Context $context Context.
	 * @return true|string
	 */
	public function applicable( Scan_Context $context ) {
		if ( '' === (string) $context->get( 'uploads_basedir' ) || ! is_dir( (string) $context->get( 'uploads_basedir' ) ) ) {
			return __( 'The uploads folder could not be found, so files cannot be checked. See the uploads folder finding.', 'unstoppable-migration-inspector' );
		}

		return true;
	}

	/**
	 * First cursor.
	 *
	 * @param Scan_Context $context Context.
	 * @return array<string, mixed>
	 */
	public function start( Scan_Context $context ) {
		global $wpdb;

		return array(
			'after'     => 0,
			'max'       => Row_Reader::max_id( $wpdb->posts, 'ID' ),
			'offloaded' => Uploads_Check::offload_detected(),
		);
	}

	/**
	 * One batch.
	 *
	 * @param Scan_Context         $context Context.
	 * @param array<string, mixed> $cursor  Cursor.
	 * @return array{findings: Finding[], cursor: array<string, mixed>|null, scanned: int}
	 */
	public function batch( Scan_Context $context, array $cursor ) {
		global $wpdb;

		$after = (int) $cursor['after'];
		$max   = (int) $cursor['max'];
		$limit = min( 500, $context->batch_size() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only keyset scan of attachments.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID AS id,
					(SELECT m.meta_value FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = '_wp_attached_file' LIMIT 1) AS file,
					(SELECT m2.meta_value FROM {$wpdb->postmeta} m2 WHERE m2.post_id = p.ID AND m2.meta_key = '_wp_attachment_metadata' LIMIT 1) AS meta
				FROM {$wpdb->posts} p
				WHERE p.post_type = 'attachment' AND p.ID > %d
				ORDER BY p.ID ASC
				LIMIT %d",
				$after,
				$limit
			),
			ARRAY_A
		);

		$basedir  = trailingslashit( (string) $context->get( 'uploads_basedir' ) );
		$severity = empty( $cursor['offloaded'] ) ? '' : Severity::NOTICE;
		$findings = array();
		$listings = array();

		foreach ( $rows as $row ) {
			$id   = (int) $row['id'];
			$file = null === $row['file'] ? '' : (string) $row['file'];

			if ( '' === $file ) {
				$findings[] = ( new Finding( 'no_file_meta', '', $severity ) )->on( 'attachment', $id );
				continue;
			}

			$path = self::resolve( $file, $basedir );

			if ( null === $path ) {
				$findings[] = ( new Finding( 'absolute_path', $file, $severity ) )->on( 'attachment', $id, '_wp_attached_file' );
				continue;
			}

			if ( ! file_exists( $path ) ) {
				$alternative = self::case_match( $path, $listings );
				$findings[]  = null === $alternative
					? ( new Finding( 'missing_file', $file, $severity ) )->on( 'attachment', $id, '_wp_attached_file' )
					: ( new Finding( 'case_mismatch', $file, $severity ) )->on( 'attachment', $id, '_wp_attached_file' )->with( array( 'found' => $alternative ) );
				continue;
			}

			$missing = self::missing_sizes( (string) $row['meta'], dirname( $path ) );

			if ( ! empty( $missing ) ) {
				$findings[] = ( new Finding( 'missing_sizes', implode( ', ', array_keys( $missing ) ) ) )
					->on( 'attachment', $id, '_wp_attachment_metadata' )
					->with( array( 'sizes' => $missing ) );
			}
		}

		$last = empty( $rows ) ? $max : (int) $rows[ count( $rows ) - 1 ]['id'];

		return array(
			'findings' => $findings,
			'cursor'   => ( count( $rows ) < $limit || $last >= $max ) ? null : array_merge(
				$cursor,
				array( 'after' => $last )
			),
			'scanned'  => count( $rows ),
		);
	}

	/**
	 * Full path of a stored file location, or null for a full path that
	 * does not belong to this site's uploads folder and does not exist.
	 *
	 * @param string $file    Stored _wp_attached_file value.
	 * @param string $basedir Uploads folder with trailing slash.
	 * @return string|null
	 */
	public static function resolve( $file, $basedir ) {
		$is_absolute = 0 === strpos( $file, '/' ) || preg_match( '#^[A-Za-z]:[\\\\/]#', $file );

		if ( ! $is_absolute ) {
			return $basedir . ltrim( $file, '/' );
		}

		if ( 0 === strpos( $file, $basedir ) || file_exists( $file ) ) {
			return $file;
		}

		return null;
	}

	/**
	 * A file in the same folder whose name differs only in case.
	 *
	 * @param string                  $path     Missing path.
	 * @param array<string, string[]> $listings Folder listings cache (by reference).
	 * @return string|null File name found.
	 */
	private static function case_match( $path, array &$listings ) {
		$dir  = dirname( $path );
		$name = basename( $path );

		if ( ! isset( $listings[ $dir ] ) ) {
			$entries          = is_dir( $dir ) ? scandir( $dir ) : false;
			$listings[ $dir ] = false === $entries ? array() : array_slice( $entries, 0, 20000 );
		}

		foreach ( $listings[ $dir ] as $entry ) {
			if ( $entry !== $name && strtolower( $entry ) === strtolower( $name ) ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * Generated sizes listed in attachment metadata whose files are missing.
	 *
	 * @param string $meta Serialized metadata.
	 * @param string $dir  Folder of the original file.
	 * @return array<string, string> Size name => file name.
	 */
	public static function missing_sizes( $meta, $dir ) {
		if ( '' === $meta || ! is_serialized( $meta ) ) {
			return array();
		}

		// Objects are never instantiated: allowed_classes is false.
		$data = @unserialize( $meta, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize,WordPress.PHP.NoSilencedErrors.Discouraged -- Core attachment metadata, read only; objects are refused.

		if ( ! is_array( $data ) || empty( $data['sizes'] ) || ! is_array( $data['sizes'] ) ) {
			return array();
		}

		$missing = array();
		$checked = array();

		foreach ( $data['sizes'] as $size => $info ) {
			if ( ! is_array( $info ) || empty( $info['file'] ) || ! is_string( $info['file'] ) ) {
				continue;
			}

			$file = basename( $info['file'] );

			if ( isset( $checked[ $file ] ) ) {
				continue;
			}

			$checked[ $file ] = true;

			if ( ! file_exists( trailingslashit( $dir ) . $file ) ) {
				$missing[ (string) $size ] = $file;
			}
		}

		return $missing;
	}
}
