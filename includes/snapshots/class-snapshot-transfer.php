<?php
/**
 * Snapshot export and import.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Snapshots;

defined( 'ABSPATH' ) || exit;

/**
 * Streams a snapshot out as JSON, and stores a validated imported file.
 *
 * Export writes the document piece by piece (one page of chunks at a time),
 * so memory stays flat however large the snapshot is. Import only stores a
 * file after Snapshot_Validator has accepted every part of it.
 */
final class Snapshot_Transfer {

	/**
	 * Default maximum import size in bytes (32 MB).
	 */
	const DEFAULT_MAX_IMPORT_BYTES = 33554432;

	/**
	 * Chunks read per page while exporting.
	 */
	const EXPORT_PAGE = 50;

	/**
	 * Snapshot storage.
	 *
	 * @var Snapshot_Repository
	 */
	private $snapshots;

	/**
	 * Constructor.
	 *
	 * @param Snapshot_Repository|null $snapshots Snapshot storage.
	 */
	public function __construct( $snapshots = null ) {
		$this->snapshots = $snapshots ? $snapshots : new Snapshot_Repository();
	}

	/**
	 * Largest file accepted for import.
	 *
	 * @return int Bytes.
	 */
	public static function max_import_bytes() {
		/**
		 * Filters the maximum snapshot file size accepted for import.
		 *
		 * @param int $bytes Maximum size in bytes. Default 32 MB.
		 */
		$bytes = (int) apply_filters( 'umin_max_snapshot_import_bytes', self::DEFAULT_MAX_IMPORT_BYTES );

		return max( 1024, $bytes );
	}

	/**
	 * Stream a complete snapshot as JSON through a writer callback.
	 *
	 * With $include_digests false, the post content digests are zeroed and
	 * the salt left out, so the file carries nothing derived from content.
	 * Chunk and overall checksums are recomputed for the reduced file.
	 *
	 * @param int      $snapshot_id     Snapshot ID.
	 * @param callable $write           Receives each piece of the document.
	 * @param bool     $include_digests Keep the content digests and their salt.
	 * @return bool False when the snapshot is missing or not complete.
	 */
	public function export( $snapshot_id, callable $write, $include_digests = true ) {
		$snapshot = $this->snapshots->get( $snapshot_id );

		if ( null === $snapshot || Snapshot_Repository::STATUS_COMPLETE !== $snapshot['status'] ) {
			return false;
		}

		$header   = $snapshot['header'];
		$checksum = (string) $snapshot['checksum'];

		if ( ! $include_digests ) {
			$header['content_salt'] = '';
			$checksum               = $this->checksum_without_digests( $snapshot_id, $header );
		}

		$opening = array(
			'format'         => 'umin-snapshot',
			'format_version' => 1,
			'exported_at'    => time(),
			'plugin_version' => UMIN_VERSION,
			'snapshot'       => array(
				'label'    => (string) $snapshot['label'],
				'checksum' => $checksum,
				'header'   => Snapshot_Repository::canonical_header( $header ),
			),
		);

		// Everything except the chunk list, then the chunks one by one.
		$json = (string) wp_json_encode( $opening, JSON_UNESCAPED_SLASHES );
		call_user_func( $write, substr( $json, 0, -1 ) . ',"chunks":[' );

		$after = 0;
		$first = true;

		do {
			$page      = $this->snapshots->chunks_after( $snapshot_id, $after, self::EXPORT_PAGE );
			$page_size = count( $page );

			foreach ( $page as $row ) {
				if ( ! $include_digests ) {
					$row = self::without_digest( $row );
				}

				$piece = wp_json_encode(
					array(
						'type'     => (string) $row['record_type'],
						'seq'      => (int) $row['seq'],
						'first_id' => (int) $row['first_id'],
						'last_id'  => (int) $row['last_id'],
						'count'    => (int) $row['row_count'],
						'sha256'   => (string) $row['sha256'],
						'payload'  => (string) $row['payload'],
					),
					JSON_UNESCAPED_SLASHES
				);

				call_user_func( $write, ( $first ? '' : ',' ) . $piece );
				$first = false;
				$after = (int) $row['id'];
			}
		} while ( self::EXPORT_PAGE === $page_size );

		call_user_func( $write, ']}' );

		return true;
	}

	/**
	 * Overall checksum of the snapshot as it will be exported without digests.
	 *
	 * @param int                  $snapshot_id Snapshot ID.
	 * @param array<string, mixed> $header      Header with the salt removed.
	 * @return string
	 */
	private function checksum_without_digests( $snapshot_id, array $header ) {
		$chunks = array();
		$after  = 0;

		do {
			$page      = $this->snapshots->chunks_after( $snapshot_id, $after, self::EXPORT_PAGE );
			$page_size = count( $page );

			foreach ( $page as $row ) {
				$after    = (int) $row['id'];
				$row      = self::without_digest( $row );
				$chunks[] = array(
					'record_type' => (string) $row['record_type'],
					'seq'         => (int) $row['seq'],
					'first_id'    => (int) $row['first_id'],
					'last_id'     => (int) $row['last_id'],
					'row_count'   => (int) $row['row_count'],
					'sha256'      => (string) $row['sha256'],
				);
			}
		} while ( self::EXPORT_PAGE === $page_size );

		return Snapshot_Repository::checksum( $header, $chunks );
	}

	/**
	 * A chunk row with its post content digests set to zero.
	 *
	 * Rows of other record types are returned unchanged.
	 *
	 * @param array<string, mixed> $row Chunk row with payload.
	 * @return array<string, mixed>
	 */
	private static function without_digest( array $row ) {
		if ( 0 !== strpos( (string) $row['record_type'], 'post:' ) ) {
			return $row;
		}

		$kinds   = ( new Post_Source( 'post:post', 'post' ) )->kinds();
		$decoded = Chunk_Codec::decode( (string) $row['payload'], (int) $row['row_count'], (string) $row['sha256'], $kinds );

		$decoded['fields']['digest'] = array_fill( 0, count( $decoded['ids'] ), 0 );

		$chunk          = Chunk_Codec::encode( $decoded['ids'], $decoded['fields'], $kinds );
		$row['payload'] = $chunk['payload'];
		$row['sha256']  = $chunk['sha256'];

		return $row;
	}

	/**
	 * Validate and store an imported snapshot document.
	 *
	 * @param string $json Raw file contents.
	 * @return int New snapshot ID.
	 * @throws Snapshot_Validation_Exception When the file is refused.
	 */
	public function import( $json ) {
		if ( ! is_string( $json ) || '' === $json ) {
			throw new Snapshot_Validation_Exception( esc_html__( 'The uploaded file is empty.', 'unstoppable-migration-inspector' ) );
		}

		if ( strlen( $json ) > self::max_import_bytes() ) {
			throw new Snapshot_Validation_Exception( esc_html__( 'The snapshot file is larger than the maximum allowed size.', 'unstoppable-migration-inspector' ) );
		}

		// json_decode only. A snapshot file is never unserialized or evaluated.
		$doc = json_decode( $json, true, 64 );
		unset( $json );

		$valid = Snapshot_Validator::validate( $doc );
		unset( $doc );

		$label = '' === $valid['label']
			? __( 'Imported snapshot', 'unstoppable-migration-inspector' )
			: $valid['label'];

		$snapshot_id = $this->snapshots->create( $label, 'imported', $valid['header'], Snapshot_Repository::STATUS_CAPTURING );

		if ( 0 === $snapshot_id ) {
			throw new Snapshot_Validation_Exception( esc_html__( 'The snapshot could not be saved to the database.', 'unstoppable-migration-inspector' ) );
		}

		foreach ( $valid['chunks'] as $chunk ) {
			$stored = $this->snapshots->put_chunk(
				$snapshot_id,
				$chunk['record_type'],
				$chunk['seq'],
				array(
					'first_id' => $chunk['first_id'],
					'last_id'  => $chunk['last_id'],
					'count'    => $chunk['row_count'],
					'sha256'   => $chunk['sha256'],
					'payload'  => $chunk['payload'],
				)
			);

			if ( ! $stored ) {
				$this->snapshots->delete( $snapshot_id );
				throw new Snapshot_Validation_Exception( esc_html__( 'The snapshot could not be saved to the database.', 'unstoppable-migration-inspector' ) );
			}
		}

		$this->snapshots->set_status( $snapshot_id, Snapshot_Repository::STATUS_COMPLETE, $valid['checksum'] );

		return $snapshot_id;
	}
}
