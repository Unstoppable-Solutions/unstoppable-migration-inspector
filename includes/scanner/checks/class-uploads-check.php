<?php
/**
 * Uploads folder location and permissions.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Scanner\Checks;

use UnstoppableSolutions\MigrationInspector\Scanner\Check;
use UnstoppableSolutions\MigrationInspector\Scanner\Db_Info;
use UnstoppableSolutions\MigrationInspector\Scanner\Finding;
use UnstoppableSolutions\MigrationInspector\Scanner\Location;
use UnstoppableSolutions\MigrationInspector\Scanner\Scan_Context;
use UnstoppableSolutions\MigrationInspector\Scanner\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * The uploads folder must exist and be writable, and custom upload paths
 * must not point at the old server.
 */
final class Uploads_Check extends Check {

	/**
	 * Key.
	 *
	 * @return string
	 */
	public function key() {
		return 'uploads_folder';
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
		return __( 'Uploads folder', 'unstoppable-migration-inspector' );
	}

	/**
	 * Kinds.
	 *
	 * @return array<string, array{severity: string, title: string, explanation: string, action: string}>
	 */
	public function kinds() {
		return array(
			'uploads_error'     => array(
				'severity'    => Severity::CRITICAL,
				'title'       => __( 'WordPress cannot use the uploads folder', 'unstoppable-migration-inspector' ),
				'explanation' => __( 'WordPress reported an error for the uploads folder. New media cannot be uploaded, and existing files may not be found.', 'unstoppable-migration-inspector' ),
				'action'      => __( 'Check that wp-content/uploads exists and that the web server can write to it. If a custom upload path is set (see below), correct or clear it.', 'unstoppable-migration-inspector' ),
			),
			'not_writable'      => array(
				'severity'    => Severity::WARNING,
				'title'       => __( 'The uploads folder is not writable', 'unstoppable-migration-inspector' ),
				'explanation' => __( 'The web server cannot write to the uploads folder. Uploading media, generating thumbnails and some plugins (caches, form uploads, backups) fail. File ownership often changes when files are copied to a new server.', 'unstoppable-migration-inspector' ),
				'action'      => __( 'Ask your host to set the owner and permissions of wp-content/uploads so the web server can write to it (folders are usually 755).', 'unstoppable-migration-inspector' ),
			),
			'custom_path_stale' => array(
				'severity'    => Severity::WARNING,
				'title'       => __( 'A custom upload path or URL points to the old server', 'unstoppable-migration-inspector' ),
				'explanation' => __( 'The site has a custom upload folder path or URL setting that does not exist here or uses an old address. Media is saved to, or loaded from, the wrong place.', 'unstoppable-migration-inspector' ),
				'action'      => __( 'Clear or correct the "upload_path" and "upload_url_path" settings (WP-CLI or the hidden options.php screen), after a backup. Most sites should leave both empty.', 'unstoppable-migration-inspector' ),
			),
			'offloaded'         => array(
				'severity'    => Severity::NOTICE,
				'title'       => __( 'Media appears to be offloaded to cloud storage', 'unstoppable-migration-inspector' ),
				'explanation' => __( 'A media offload plugin\'s data was found. Offloaded files may not be on this server at all, so missing-file results are shown as notices rather than warnings.', 'unstoppable-migration-inspector' ),
				'action'      => __( 'Check the offload plugin\'s settings on the new site (bucket, access keys, domain), and that it is active.', 'unstoppable-migration-inspector' ),
			),
		);
	}

	/**
	 * Whether a media offload plugin has stored data on this site.
	 *
	 * @return bool
	 */
	public static function offload_detected() {
		global $wpdb;

		if ( Db_Info::table_exists( $wpdb->prefix . 'as3cf_items' ) ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only presence check.
		return null !== $wpdb->get_var( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key IN ('amazonS3_info','_wpmcs_attachment','_cloudinary') LIMIT 1" );
	}

	/**
	 * Run.
	 *
	 * @param Scan_Context $context Context.
	 * @return Finding[]
	 */
	public function run( Scan_Context $context ) {
		$findings = array();
		$basedir  = (string) $context->get( 'uploads_basedir' );
		$error    = (string) $context->get( 'uploads_error' );

		if ( '' !== $error || '' === $basedir || ! is_dir( $basedir ) ) {
			$findings[] = ( new Finding( 'uploads_error', '' !== $error ? $error : __( 'The uploads folder does not exist.', 'unstoppable-migration-inspector' ) ) )->on( 'file', 'uploads' );
		} elseif ( ! wp_is_writable( $basedir ) ) {
			$findings[] = ( new Finding( 'not_writable' ) )->on( 'file', 'uploads' );
		}

		$path = (string) Db_Info::raw_option( 'upload_path' );

		if ( '' !== trim( $path ) ) {
			$full = path_is_absolute( $path ) ? $path : ABSPATH . $path;

			if ( ! is_dir( $full ) ) {
				$findings[] = ( new Finding( 'custom_path_stale', 'upload_path' ) )->on( 'option', 'upload_path' );
			}
		}

		$url = (string) Db_Info::raw_option( 'upload_url_path' );

		if ( '' !== trim( $url ) ) {
			$location = Location::from_url( $url );

			foreach ( $context->old_locations() as $old ) {
				if ( '' !== $location && ( $location === $old['location'] || 0 === strpos( $location, $old['location'] . '/' ) ) && empty( $old['shadows'] ) ) {
					$findings[] = ( new Finding( 'custom_path_stale', 'upload_url_path' ) )->on( 'option', 'upload_url_path' )->with( array( 'address' => $old['location'] ) );
					break;
				}
			}
		}

		if ( self::offload_detected() ) {
			$findings[] = ( new Finding( 'offloaded' ) )->on( 'setting', 'media' );
		}

		return $findings;
	}
}
