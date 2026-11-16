<?php
/**
 * References to old site addresses, or to this site over plain HTTP.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Scanner\Checks;

use UnstoppableSolutions\MigrationInspector\Scanner\Batched_Check;
use UnstoppableSolutions\MigrationInspector\Scanner\Finding;
use UnstoppableSolutions\MigrationInspector\Scanner\Location;
use UnstoppableSolutions\MigrationInspector\Scanner\Row_Reader;
use UnstoppableSolutions\MigrationInspector\Scanner\Scan_Context;
use UnstoppableSolutions\MigrationInspector\Scanner\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * One instance per table ("target") and mode:
 *
 * - old:      links and paths that still use a previous, staging or
 *             temporary address of the site
 * - insecure: links to this site that use http:// on an HTTPS site
 *
 * Matching is done in PHP on rows the database prefiltered, so plain,
 * JSON-escaped and URL-encoded forms are all found, inside serialized data
 * too, without unserializing anything.
 */
final class Reference_Check extends Batched_Check {

	const MODE_OLD      = 'old';
	const MODE_INSECURE = 'insecure';

	/**
	 * Rows read per batch at most.
	 */
	const ROWS_PER_BATCH = 200;

	/**
	 * Target key.
	 *
	 * @var string
	 */
	private $target;

	/**
	 * Mode.
	 *
	 * @var string
	 */
	private $mode;

	/**
	 * Create the check.
	 *
	 * @param string $target posts, postmeta, options, comments or termmeta.
	 * @param string $mode   self::MODE_OLD or self::MODE_INSECURE.
	 */
	public function __construct( $target, $mode ) {
		$this->target = $target;
		$this->mode   = $mode;
	}

	/**
	 * Target definitions.
	 *
	 * @return array<string, array{table: string, id: string, columns: string[], extra: string[], object: string}>
	 */
	public static function targets() {
		global $wpdb;

		return array(
			'posts'    => array(
				'table'   => $wpdb->posts,
				'id'      => 'ID',
				'columns' => array( 'post_content', 'post_excerpt' ),
				'extra'   => array( 'post_type', 'post_status' ),
				'object'  => 'post',
			),
			'postmeta' => array(
				'table'   => $wpdb->postmeta,
				'id'      => 'meta_id',
				'columns' => array( 'meta_value' ),
				'extra'   => array( 'post_id', 'meta_key' ),
				'object'  => 'postmeta',
			),
			'options'  => array(
				'table'   => $wpdb->options,
				'id'      => 'option_id',
				'columns' => array( 'option_value' ),
				'extra'   => array( 'option_name' ),
				'object'  => 'option',
			),
			'comments' => array(
				'table'   => $wpdb->comments,
				'id'      => 'comment_ID',
				'columns' => array( 'comment_content', 'comment_author_url' ),
				'extra'   => array( 'comment_post_ID' ),
				'object'  => 'comment',
			),
			'termmeta' => array(
				'table'   => $wpdb->termmeta,
				'id'      => 'meta_id',
				'columns' => array( 'meta_value' ),
				'extra'   => array( 'term_id', 'meta_key' ),
				'object'  => 'termmeta',
			),
		);
	}

	/**
	 * Key.
	 *
	 * @return string
	 */
	public function key() {
		return ( self::MODE_OLD === $this->mode ? 'old_urls_' : 'insecure_urls_' ) . $this->target;
	}

	/**
	 * Category.
	 *
	 * @return string
	 */
	public function category() {
		return self::MODE_OLD === $this->mode ? 'urls' : 'https';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		$names = array(
			'posts'    => __( 'posts, pages and other content', 'unstoppable-migration-inspector' ),
			'postmeta' => __( 'post custom fields and page-builder data', 'unstoppable-migration-inspector' ),
			'options'  => __( 'site settings and widgets', 'unstoppable-migration-inspector' ),
			'comments' => __( 'comments', 'unstoppable-migration-inspector' ),
			'termmeta' => __( 'category and tag fields', 'unstoppable-migration-inspector' ),
		);
		$name  = isset( $names[ $this->target ] ) ? $names[ $this->target ] : $this->target;

		return self::MODE_OLD === $this->mode
			/* translators: %s: what is searched, e.g. "comments". */
			? sprintf( __( 'Old addresses in %s', 'unstoppable-migration-inspector' ), $name )
			/* translators: %s: what is searched, e.g. "comments". */
			: sprintf( __( 'Insecure links to this site in %s', 'unstoppable-migration-inspector' ), $name );
	}

	/**
	 * Kinds.
	 *
	 * @return array<string, array{severity: string, title: string, explanation: string, action: string}>
	 */
	public function kinds() {
		$where = $this->label();

		if ( self::MODE_OLD === $this->mode ) {
			return array(
				'old_reference' => array(
					'severity'    => Severity::WARNING,
					'title'       => $where,
					'explanation' => __( 'These items still contain links or file paths that use a previous, staging or temporary address of this site. Visitors following them may land on the old site, see broken images, or hit a page that no longer exists. Settings and page-builder data that point to the old address can also make features misbehave.', 'unstoppable-migration-inspector' ),
					'action'      => __( 'Check a few of the listed items to confirm the address is really this site\'s old address. If it is, replace it with the current address using a search-and-replace tool that handles serialized data safely, after taking a backup. If an address is not yours (for example a partner site), ignore it.', 'unstoppable-migration-inspector' ),
				),
			);
		}

		return array(
			'insecure_reference' => array(
				'severity'    => Severity::WARNING,
				'title'       => $where,
				'explanation' => __( 'This site uses HTTPS, but these items link to it with http://. Images, scripts and styles loaded this way are blocked or flagged by browsers as mixed content, and plain links cause an extra redirect or a security warning.', 'unstoppable-migration-inspector' ),
				'action'      => __( 'Replace http:// with https:// for this site\'s own address in the listed items, using a search-and-replace tool that handles serialized data safely, after taking a backup.', 'unstoppable-migration-inspector' ),
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
		if ( self::MODE_INSECURE === $this->mode ) {
			return $context->is_https() ? true : __( 'The site address does not use HTTPS, so http:// links to it are expected.', 'unstoppable-migration-inspector' );
		}

		return empty( $context->old_locations() )
			? __( 'No previous, staging or temporary address of this site was entered or detected. Add previous domains in Settings to search for them.', 'unstoppable-migration-inspector' )
			: true;
	}

	/**
	 * First cursor.
	 *
	 * @param Scan_Context $context Context.
	 * @return array<string, mixed>
	 */
	public function start( Scan_Context $context ) {
		$target = self::targets()[ $this->target ];

		return array(
			'after' => 0,
			'max'   => Row_Reader::max_id( $target['table'], $target['id'] ),
		);
	}

	/**
	 * What to look for: prefixes with the prefixes that shadow them, and the
	 * database prefilter patterns.
	 *
	 * @param Scan_Context $context Context.
	 * @return array{needles: array<int, array{prefix: string, shadows: string[], label: string, source: string}>, patterns: string[]}
	 */
	private function needles( Scan_Context $context ) {
		global $wpdb;

		$needles  = array();
		$patterns = array();

		if ( self::MODE_OLD === $this->mode ) {
			foreach ( $context->old_locations() as $old ) {
				$needles[]  = array(
					'prefix'  => '//' . $old['location'],
					'shadows' => (array) $old['shadows'],
					'label'   => $old['location'],
					'source'  => $old['source'],
				);
				$patterns[] = Row_Reader::contains( Location::host( $old['location'] ) );
			}
		} else {
			$own = array_unique( array_filter( array( (string) $context->get( 'location' ), (string) $context->get( 'site_location' ) ) ) );

			foreach ( $own as $location ) {
				$needles[]  = array(
					'prefix'  => 'http://' . $location,
					'shadows' => array(),
					'label'   => 'http://' . $location,
					'source'  => 'current',
				);
				$host       = $wpdb->esc_like( Location::host( $location ) );
				$patterns[] = '%http://' . $host . '%';
				// "\/\/" in JSON-escaped data is four characters: four "_" wildcards.
				$patterns[] = '%http:____' . $host . '%';
			}
		}

		return array(
			'needles'  => $needles,
			'patterns' => array_values( array_unique( $patterns ) ),
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
		$target = self::targets()[ $this->target ];
		$after  = (int) $cursor['after'];
		$max    = (int) $cursor['max'];

		if ( $after >= $max ) {
			return array(
				'findings' => array(),
				'cursor'   => null,
				'scanned'  => 0,
			);
		}

		$search = $this->needles( $context );
		$window = $context->batch_size() * 10;
		$found  = Row_Reader::prefilter( $target['table'], $target['id'], $target['columns'], $search['patterns'], $after, $window, self::ROWS_PER_BATCH, $target['extra'] );
		$extra  = array();

		foreach ( $found['rows'] as $row ) {
			if ( ! $this->skip( $row ) ) {
				$extra[ (int) $row['id'] ] = $row;
			}
		}

		$findings = array();
		$skipped  = Row_Reader::fetch(
			$target['table'],
			$target['id'],
			$target['columns'],
			array_values( $extra ),
			function ( $id, array $values ) use ( $search, $target, $extra, &$findings ) {
				$finding = $this->inspect( $id, $values, $search['needles'], $target, $extra[ $id ] );

				if ( null !== $finding ) {
					$findings[] = $finding;
				}
			}
		);

		foreach ( $skipped as $id ) {
			$findings[] = ( new Finding( $this->main_kind(), __( 'Too large to check (over 8 MB).', 'unstoppable-migration-inspector' ), Severity::NOTICE ) )
				->on( $target['object'], $id, '' )
				->with( array_merge( $this->object_details( $extra[ $id ] ), array( 'skipped' => true ) ) );
		}

		$next = (int) $found['next'];

		return array(
			'findings' => $findings,
			'cursor'   => $next >= $max ? null : array(
				'after' => $next,
				'max'   => $max,
			),
			'scanned'  => count( $found['rows'] ),
		);
	}

	/**
	 * The kind this mode reports.
	 *
	 * @return string
	 */
	private function main_kind() {
		return self::MODE_OLD === $this->mode ? 'old_reference' : 'insecure_reference';
	}

	/**
	 * Rows that are expected to hold other addresses and are not checked.
	 *
	 * @param array<string, mixed> $row Prefilter row.
	 * @return bool
	 */
	private function skip( array $row ) {
		switch ( $this->target ) {
			case 'posts':
				return 'revision' === $row['post_type'] || 'auto-draft' === $row['post_status'];
			case 'postmeta':
				$key = (string) $row['meta_key'];
				return in_array( $key, array( '_wp_attached_file', '_wp_attachment_metadata', '_edit_lock', '_edit_last', '_wp_old_slug' ), true )
					|| 0 === strpos( $key, '_oembed_' );
			case 'options':
				$name = (string) $row['option_name'];
				return in_array( $name, array( 'home', 'siteurl', 'rewrite_rules', 'recently_edited', 'cron' ), true )
					|| 0 === strpos( $name, '_transient_' )
					|| 0 === strpos( $name, '_site_transient_' )
					|| 0 === strpos( $name, 'umin_' );
		}

		return false;
	}

	/**
	 * Check one row.
	 *
	 * @param int                                                                                 $id      Row ID.
	 * @param array<string, mixed>                                                                $values  Column values.
	 * @param array<int, array{prefix: string, shadows: string[], label: string, source: string}> $needles Needles.
	 * @param array<string, mixed>                                                                $target  Target.
	 * @param array<string, mixed>                                                                $row     Prefilter row.
	 * @return Finding|null
	 */
	private function inspect( $id, array $values, array $needles, array $target, array $row ) {
		$hits    = array();
		$columns = array();
		$excerpt = '';

		foreach ( $values as $column => $value ) {
			$text  = (string) $value;
			$lower = strtolower( $text );

			foreach ( $needles as $needle ) {
				$count = Location::count_excluding( $lower, $needle['prefix'], $needle['shadows'] );

				if ( $count > 0 ) {
					$hits[ $needle['label'] ] = ( isset( $hits[ $needle['label'] ] ) ? $hits[ $needle['label'] ] : 0 ) + $count;
					$columns[ $column ]       = true;

					if ( '' === $excerpt ) {
						$excerpt = Location::excerpt( $text, $needle['prefix'] );
					}
				}
			}
		}

		if ( empty( $hits ) ) {
			return null;
		}

		$parts = array();
		foreach ( $hits as $label => $count ) {
			/* translators: 1: address, 2: number of occurrences. */
			$parts[] = sprintf( __( '%1$s (%2$d)', 'unstoppable-migration-inspector' ), $label, $count );
		}

		return ( new Finding( $this->main_kind(), implode( ', ', $parts ) ) )
			->on( $target['object'], $id, implode( ', ', array_keys( $columns ) ) )
			->with(
				array_merge(
					$this->object_details( $row ),
					array(
						'addresses' => $hits,
						'excerpt'   => $excerpt,
					)
				)
			);
	}

	/**
	 * Small identifying details for the results screen.
	 *
	 * @param array<string, mixed> $row Prefilter row.
	 * @return array<string, mixed>
	 */
	private function object_details( array $row ) {
		$details = array();

		foreach ( array( 'post_type', 'post_id', 'meta_key', 'option_name', 'comment_post_ID', 'term_id' ) as $key ) {
			if ( isset( $row[ $key ] ) ) {
				$details[ $key ] = is_numeric( $row[ $key ] ) && 'meta_key' !== $key && 'option_name' !== $key ? (int) $row[ $key ] : (string) $row[ $key ];
			}
		}

		return $details;
	}
}
