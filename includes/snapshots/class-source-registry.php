<?php
/**
 * Discovers which record types a site has and builds their sources.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Snapshots;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the record types to track and creates Record_Source objects.
 *
 * Discovery reads the database (DISTINCT post types and taxonomies), so
 * content from plugins that are now inactive is still tracked.
 */
final class Source_Registry {

	/**
	 * Post types never tracked by ID.
	 *
	 * Revisions are counted instead; caches and changesets are throwaway.
	 *
	 * @var string[]
	 */
	const SKIPPED_POST_TYPES = array( 'revision', 'oembed_cache', 'customize_changeset' );

	/**
	 * Pattern for valid type keys.
	 */
	const KEY_PATTERN = '/^(post:[a-z0-9_-]{1,20}|term:[a-z0-9_-]{1,32}|user|comment|wc_[a-z0-9_]{1,30})$/';

	/**
	 * Definitions for every record type on this site.
	 *
	 * @return array<int, array{key: string, source: string, arg: string}>
	 */
	public static function discover() {
		global $wpdb;

		$definitions = array();
		$excluded    = array_merge( self::SKIPPED_POST_TYPES, Woo_Storage::ORDER_POST_TYPES );

		if ( function_exists( 'wc_get_order_types' ) ) {
			$excluded = array_merge( $excluded, (array) wc_get_order_types() );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only discovery.
		$post_types = (array) $wpdb->get_col( "SELECT DISTINCT post_type FROM {$wpdb->posts}" );
		sort( $post_types );

		foreach ( $post_types as $post_type ) {
			$post_type = (string) $post_type;
			if ( in_array( $post_type, $excluded, true ) || ! preg_match( '/^[a-z0-9_-]{1,20}$/', $post_type ) ) {
				continue;
			}
			$definitions[] = array(
				'key'    => 'post:' . $post_type,
				'source' => 'post',
				'arg'    => $post_type,
			);
		}

		$definitions[] = array(
			'key'    => 'user',
			'source' => 'user',
			'arg'    => '',
		);
		$definitions[] = array(
			'key'    => 'comment',
			'source' => 'comment',
			'arg'    => '',
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only discovery.
		$taxonomies = (array) $wpdb->get_col( "SELECT DISTINCT taxonomy FROM {$wpdb->term_taxonomy}" );
		sort( $taxonomies );

		foreach ( $taxonomies as $taxonomy ) {
			$taxonomy = (string) $taxonomy;
			if ( ! preg_match( '/^[a-z0-9_-]{1,32}$/', $taxonomy ) ) {
				continue;
			}
			$definitions[] = array(
				'key'    => 'term:' . $taxonomy,
				'source' => 'term',
				'arg'    => $taxonomy,
			);
		}

		$storage = Woo_Storage::detect();

		foreach ( Woo_Storage::order_types( $storage['mode'] ) as $order_type ) {
			$definitions[] = array(
				'key'    => Woo_Storage::type_key( $order_type ),
				'source' => 'order',
				'arg'    => $order_type,
			);
		}

		return $definitions;
	}

	/**
	 * Whether a stored definition is well formed.
	 *
	 * @param mixed $definition Definition.
	 * @return bool
	 */
	public static function is_valid_definition( $definition ) {
		if ( ! is_array( $definition ) || ! isset( $definition['key'], $definition['source'], $definition['arg'] ) ) {
			return false;
		}

		if ( ! is_string( $definition['key'] ) || ! preg_match( self::KEY_PATTERN, $definition['key'] ) ) {
			return false;
		}

		switch ( $definition['source'] ) {
			case 'post':
				return 'post:' . $definition['arg'] === $definition['key'];
			case 'term':
				return 'term:' . $definition['arg'] === $definition['key'];
			case 'user':
			case 'comment':
				return $definition['source'] === $definition['key'] && '' === $definition['arg'];
			case 'order':
				return is_string( $definition['arg'] )
					&& (bool) preg_match( '/^[a-z0-9_]{1,20}$/', $definition['arg'] )
					&& Woo_Storage::type_key( $definition['arg'] ) === $definition['key'];
		}

		return false;
	}

	/**
	 * Build a source from a definition.
	 *
	 * @param array{key: string, source: string, arg: string} $definition   Definition.
	 * @param string                                          $order_mode   Storage mode for orders on this site.
	 * @param string                                          $content_salt Snapshot content digest salt, or ''.
	 * @return Record_Source|null
	 */
	public static function make( array $definition, $order_mode, $content_salt = '' ) {
		if ( ! self::is_valid_definition( $definition ) ) {
			return null;
		}

		switch ( $definition['source'] ) {
			case 'post':
				return new Post_Source( $definition['key'], $definition['arg'], $content_salt );
			case 'user':
				return new User_Source( $definition['key'] );
			case 'comment':
				return new Comment_Source( $definition['key'] );
			case 'term':
				return new Term_Source( $definition['key'], $definition['arg'] );
			case 'order':
				$mode = Woo_Storage::MODE_NONE === $order_mode ? Woo_Storage::MODE_LEGACY : $order_mode;
				return new Order_Source( $definition['key'], $definition['arg'], $mode );
		}

		return null;
	}

	/**
	 * A readable label for a type key.
	 *
	 * @param string $key Type key.
	 * @return string
	 */
	public static function label( $key ) {
		$fixed = array(
			'post:post'       => __( 'Posts', 'unstoppable-migration-inspector' ),
			'post:page'       => __( 'Pages', 'unstoppable-migration-inspector' ),
			'post:attachment' => __( 'Media (attachments)', 'unstoppable-migration-inspector' ),
			'post:product'    => __( 'Products', 'unstoppable-migration-inspector' ),
			'user'            => __( 'Users', 'unstoppable-migration-inspector' ),
			'comment'         => __( 'Comments, reviews and notes', 'unstoppable-migration-inspector' ),
			'wc_order'        => __( 'WooCommerce orders', 'unstoppable-migration-inspector' ),
			'wc_refund'       => __( 'WooCommerce refunds', 'unstoppable-migration-inspector' ),
		);

		if ( isset( $fixed[ $key ] ) ) {
			return $fixed[ $key ];
		}

		if ( 0 === strpos( $key, 'post:' ) ) {
			/* translators: %s: post type name. */
			return sprintf( __( 'Post type: %s', 'unstoppable-migration-inspector' ), substr( $key, 5 ) );
		}

		if ( 0 === strpos( $key, 'term:' ) ) {
			/* translators: %s: taxonomy name. */
			return sprintf( __( 'Taxonomy: %s', 'unstoppable-migration-inspector' ), substr( $key, 5 ) );
		}

		if ( 0 === strpos( $key, 'wc_' ) ) {
			/* translators: %s: WooCommerce order type. */
			return sprintf( __( 'WooCommerce: %s', 'unstoppable-migration-inspector' ), substr( $key, 3 ) );
		}

		return $key;
	}
}
