<?php
/**
 * Checks whether a snapshot belongs to this site before comparing.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Snapshots;

defined( 'ABSPATH' ) || exit;

/**
 * Weighs several signals to decide whether a snapshot came from this site
 * (or from the site this one was migrated from).
 *
 * Comparing a snapshot with an unrelated site would report thousands of
 * "missing" records that were never meant to be here, so a comparison that
 * is not clearly the same site requires an explicit acknowledgment.
 *
 * Verdicts:
 * - same_site:        the site keys match (the database was carried over).
 * - likely_same:      keys differ or are absent, but almost every sampled
 *                     record ID exists here and the name, address or table
 *                     prefix matches.
 * - unverified:       a majority of sampled IDs exist, but not enough other
 *                     signals agree.
 * - likely_unrelated: most sampled IDs do not exist here.
 */
final class Identity_Check {

	const SAME_SITE        = 'same_site';
	const LIKELY_SAME      = 'likely_same';
	const UNVERIFIED       = 'unverified';
	const LIKELY_UNRELATED = 'likely_unrelated';

	/**
	 * Record types sampled for ID overlap.
	 *
	 * @var string[]
	 */
	const SAMPLED_TYPES = array( 'post:post', 'post:page', 'post:attachment', 'post:product', 'user', 'wc_order' );

	/**
	 * IDs sampled per type.
	 */
	const SAMPLE_PER_TYPE = 50;

	/**
	 * Assess a snapshot against the current site.
	 *
	 * @param int                  $snapshot_id Snapshot ID.
	 * @param array<string, mixed> $header      Snapshot header.
	 * @return array{verdict: string, needs_ack: bool, signals: array<string, mixed>, reasons: string[]}
	 */
	public static function assess( $snapshot_id, array $header ) {
		$identity = isset( $header['identity'] ) && is_array( $header['identity'] ) ? $header['identity'] : array();
		$config   = isset( $header['config'] ) && is_array( $header['config'] ) ? $header['config'] : array();

		$snap_key = isset( $identity['site_key'] ) ? (string) $identity['site_key'] : '';
		$site_key = Site_Identity::key();

		$signals = array(
			'key_match'    => ( '' === $snap_key || '' === $site_key ) ? null : hash_equals( $snap_key, $site_key ),
			'name_match'   => self::name_match( isset( $identity['name_hash'] ) ? (string) $identity['name_hash'] : '' ),
			'domain_match' => self::host( isset( $config['home'] ) ? (string) $config['home'] : '' ) === self::host( (string) get_option( 'home' ) ),
			'prefix_match' => isset( $config['table_prefix'] ) && $GLOBALS['wpdb']->prefix === $config['table_prefix'],
			'id_overlap'   => self::id_overlap( $snapshot_id, $header ),
		);

		return self::decide( $signals );
	}

	/**
	 * Turn signals into a verdict. Separate from assess() so the rules can be
	 * unit tested without a database.
	 *
	 * @param array<string, mixed> $signals Signals.
	 * @return array{verdict: string, needs_ack: bool, signals: array<string, mixed>, reasons: string[]}
	 */
	public static function decide( array $signals ) {
		$overlap = $signals['id_overlap'];
		$reasons = array();

		if ( true === $signals['key_match'] ) {
			// A matching key proves the database was copied from the
			// snapshot's site at some point, not that the two still hold the
			// same records: an old staging copy, or a site built from a cloned
			// template, shares the key but has diverged. The ID sample decides.
			if ( null !== $overlap && $overlap < 0.5 ) {
				$verdict   = self::UNVERIFIED;
				$reasons[] = __( 'The site key matches, so this database was copied from the snapshot\'s site at some point, but most sampled records are not here. The sites may have drifted apart (for example an old staging copy, or a site built from a cloned template).', 'unstoppable-migration-inspector' );
			} elseif ( null !== $overlap && $overlap < 0.9 ) {
				$verdict = self::LIKELY_SAME;
			} else {
				$verdict = self::SAME_SITE;
			}
		} else {
			$other = (bool) $signals['name_match'] || (bool) $signals['domain_match'] || (bool) $signals['prefix_match'];

			if ( null !== $overlap && $overlap >= 0.9 && $other ) {
				$verdict = self::LIKELY_SAME;
			} elseif ( null !== $overlap && $overlap >= 0.5 ) {
				$verdict = self::UNVERIFIED;
			} elseif ( null === $overlap && $other ) {
				$verdict = self::UNVERIFIED;
			} else {
				$verdict = self::LIKELY_UNRELATED;
			}
		}

		if ( false === $signals['key_match'] ) {
			$reasons[] = __( 'The snapshot was taken on a site with a different Migration Inspector site key. This is expected if the database was rebuilt rather than copied, but also happens when the snapshot belongs to another site.', 'unstoppable-migration-inspector' );
		}

		if ( null !== $overlap && $overlap < 0.9 ) {
			$reasons[] = sprintf(
				/* translators: %s: percentage. */
				__( 'Only %s of a sample of record IDs from the snapshot exist on this site. If content was moved with a tool that renumbers posts (such as the WordPress export/import tool), an ID-based comparison cannot give meaningful results.', 'unstoppable-migration-inspector' ),
				number_format_i18n( $overlap * 100, 0 ) . '%'
			);
		}

		if ( null === $overlap ) {
			$reasons[] = __( 'The snapshot has no posts, pages, media, products, users or orders to sample, so the site could not be matched by record IDs.', 'unstoppable-migration-inspector' );
		}

		if ( true === $signals['key_match'] ) {
			// Informational: expected after a move, but never hidden.
			if ( ! $signals['domain_match'] ) {
				$reasons[] = __( 'The site address is different from the snapshot (expected after a move to a new domain or a staging copy).', 'unstoppable-migration-inspector' );
			}
			if ( ! $signals['prefix_match'] ) {
				$reasons[] = __( 'The database table prefix is different from the snapshot (common with staging copies and some migration tools).', 'unstoppable-migration-inspector' );
			}
			if ( ! $signals['name_match'] ) {
				$reasons[] = __( 'The site name is different from the snapshot.', 'unstoppable-migration-inspector' );
			}
		} elseif ( ! $signals['name_match'] && ! $signals['domain_match'] ) {
			$reasons[] = __( 'The site name and address are both different from the snapshot.', 'unstoppable-migration-inspector' );
		}

		return array(
			'verdict'   => $verdict,
			'needs_ack' => in_array( $verdict, array( self::UNVERIFIED, self::LIKELY_UNRELATED ), true ),
			'signals'   => $signals,
			'reasons'   => $reasons,
		);
	}

	/**
	 * Short explanation of a verdict for the screen.
	 *
	 * @param string $verdict Verdict.
	 * @return string
	 */
	public static function describe( $verdict ) {
		switch ( $verdict ) {
			case self::SAME_SITE:
				return __( 'This snapshot was taken on this site, or on the site this database was copied from.', 'unstoppable-migration-inspector' );
			case self::LIKELY_SAME:
				return __( 'This snapshot very likely comes from this site: almost all sampled record IDs exist here.', 'unstoppable-migration-inspector' );
			case self::UNVERIFIED:
				return __( 'It could not be confirmed that this snapshot comes from this site. The comparison was run after you confirmed it.', 'unstoppable-migration-inspector' );
			default:
				return __( 'This snapshot appears to come from a different site. The comparison was run after you confirmed it, and its results may not be meaningful.', 'unstoppable-migration-inspector' );
		}
	}

	/**
	 * Whether the snapshot's site-name hash matches this site.
	 *
	 * @param string $hash Snapshot name hash.
	 * @return bool
	 */
	private static function name_match( $hash ) {
		$mine = Site_Identity::name_hash();

		return '' !== $hash && '' !== $mine && hash_equals( $hash, $mine );
	}

	/**
	 * Lower-cased host of a URL, without a leading "www.".
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function host( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		return is_string( $host ) ? preg_replace( '/^www\./', '', strtolower( $host ) ) : '';
	}

	/**
	 * Fraction of sampled snapshot IDs that exist on this site.
	 *
	 * Samples up to SAMPLE_PER_TYPE IDs, evenly spread, from the first chunk
	 * of each sampled type. Each check is a primary-key lookup.
	 *
	 * @param int                  $snapshot_id Snapshot ID.
	 * @param array<string, mixed> $header      Header.
	 * @return float|null Null when there was nothing to sample.
	 */
	private static function id_overlap( $snapshot_id, array $header ) {
		$repository = new Snapshot_Repository();
		$storage    = Woo_Storage::detect();
		$salt       = isset( $header['content_salt'] ) ? (string) $header['content_salt'] : '';
		$sampled    = 0;
		$found      = 0;

		foreach ( self::SAMPLED_TYPES as $key ) {
			if ( empty( $header['types'][ $key ]['chunks'] ) ) {
				continue;
			}

			$type   = $header['types'][ $key ];
			$source = Source_Registry::make(
				array(
					'key'    => $key,
					'source' => $type['source'],
					'arg'    => $type['arg'],
				),
				$storage['mode'],
				$salt
			);
			$chunk  = $repository->get_chunk( $snapshot_id, $key, 0 );

			if ( null === $source || null === $chunk ) {
				continue;
			}

			try {
				$decoded = Chunk_Codec::decode( (string) $chunk['payload'], (int) $chunk['row_count'], (string) $chunk['sha256'], $source->kinds() );
			} catch ( \UnexpectedValueException $error ) {
				continue;
			}

			$ids   = $decoded['ids'];
			$count = count( $ids );
			$step  = max( 1, (int) floor( $count / self::SAMPLE_PER_TYPE ) );
			$limit = min( $count, $step * self::SAMPLE_PER_TYPE );

			for ( $i = 0; $i < $limit; $i += $step ) {
				$id = $ids[ $i ];
				++$sampled;
				if ( array() !== $source->fetch_after( $id - 1, 1, $id ) ) {
					++$found;
				}
			}
		}

		return 0 === $sampled ? null : $found / $sampled;
	}
}
