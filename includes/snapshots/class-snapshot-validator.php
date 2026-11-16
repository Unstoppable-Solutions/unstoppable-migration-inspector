<?php
/**
 * Strict validation of imported snapshot files.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Snapshots;

defined( 'ABSPATH' ) || exit;

/**
 * Validates a decoded snapshot document against an allowlist schema.
 *
 * Nothing is trusted: every key must be known, every value has a type,
 * length and pattern, every chunk is decoded and checksummed, and the
 * overall checksum must match. Anything unexpected rejects the whole file.
 * Values are only ever integers and short vocabulary strings, which are
 * escaped on output and never executed or unserialized.
 */
final class Snapshot_Validator {

	/**
	 * Maximum chunks in one file.
	 */
	const MAX_CHUNKS = 20000;

	/**
	 * Maximum record types in one file.
	 */
	const MAX_TYPES = 500;

	/**
	 * Maximum tables in one file.
	 */
	const MAX_TABLES = 2000;

	/**
	 * Allowed dictionary names.
	 *
	 * @var string[]
	 */
	const DICTIONARIES = array( 'post_status', 'role', 'comment_approved', 'comment_type', 'order_status' );

	/**
	 * Validate a decoded document.
	 *
	 * @param mixed $doc Result of json_decode( $file, true ).
	 * @return array{header: array<string, mixed>, chunks: array<int, array<string, mixed>>, label: string, checksum: string}
	 * @throws Snapshot_Validation_Exception With a message for the administrator.
	 */
	public static function validate( $doc ) {
		if ( ! is_array( $doc ) ) {
			self::fail( __( 'The file is not a valid snapshot (it is not JSON, or it is empty).', 'unstoppable-migration-inspector' ) );
		}

		self::exact_keys( $doc, array( 'format', 'format_version', 'exported_at', 'plugin_version', 'snapshot', 'chunks' ), 'file' );

		if ( 'umin-snapshot' !== $doc['format'] ) {
			self::fail( __( 'This file is not an Unstoppable Migration Inspector snapshot.', 'unstoppable-migration-inspector' ) );
		}

		if ( 1 !== $doc['format_version'] ) {
			self::fail( __( 'This snapshot was made by a newer or unknown version of the plugin. Update the plugin and try again.', 'unstoppable-migration-inspector' ) );
		}

		self::int_in( $doc['exported_at'], 0, PHP_INT_MAX, 'exported_at' );
		self::pattern( $doc['plugin_version'], '/^[0-9A-Za-z.+-]{1,20}$/', 'plugin_version' );

		$snapshot = $doc['snapshot'];
		if ( ! is_array( $snapshot ) ) {
			self::fail( self::bad( 'snapshot' ) );
		}
		self::exact_keys( $snapshot, array( 'label', 'checksum', 'header' ), 'snapshot' );

		if ( ! is_string( $snapshot['label'] ) || mb_strlen( $snapshot['label'] ) > 190 ) {
			self::fail( self::bad( 'label' ) );
		}
		self::pattern( $snapshot['checksum'], '/^[0-9a-f]{64}$/', 'checksum' );

		$header = self::validate_header( $snapshot['header'] );
		$chunks = self::validate_chunks( $doc['chunks'], $header );

		if ( ! hash_equals( $snapshot['checksum'], Snapshot_Repository::checksum( $header, $chunks ) ) ) {
			self::fail( __( 'The snapshot checksum does not match its contents. The file is damaged or was edited.', 'unstoppable-migration-inspector' ) );
		}

		return array(
			'header'   => $header,
			'chunks'   => $chunks,
			'label'    => sanitize_text_field( $snapshot['label'] ),
			'checksum' => $snapshot['checksum'],
		);
	}

	/**
	 * Validate the header.
	 *
	 * @param mixed $header Header.
	 * @return array<string, mixed>
	 */
	private static function validate_header( $header ) {
		if ( ! is_array( $header ) ) {
			self::fail( self::bad( 'header' ) );
		}

		self::exact_keys( $header, array( 'format_version', 'created_at', 'order_mode', 'identity', 'content_salt', 'config', 'types', 'dictionaries', 'tables', 'revisions' ), 'header' );

		if ( 1 !== $header['format_version'] ) {
			self::fail( self::bad( 'header.format_version' ) );
		}

		self::int_in( $header['created_at'], 946684800, 4102444800, 'created_at' );
		self::one_of( $header['order_mode'], array( Woo_Storage::MODE_HPOS, Woo_Storage::MODE_LEGACY, Woo_Storage::MODE_NONE ), 'order_mode' );
		self::int_in( $header['revisions'], 0, PHP_INT_MAX, 'revisions' );
		self::pattern( $header['content_salt'], '/^([0-9a-f]{16})?$/', 'content_salt' );

		if ( ! is_array( $header['identity'] ) ) {
			self::fail( self::bad( 'identity' ) );
		}
		self::exact_keys( $header['identity'], array( 'site_key', 'name_hash' ), 'identity' );
		self::pattern( $header['identity']['site_key'], '/^([0-9a-f]{32})?$/', 'identity.site_key' );
		self::pattern( $header['identity']['name_hash'], '/^([0-9a-f]{64})?$/', 'identity.name_hash' );

		self::validate_config( $header['config'] );
		self::validate_types( $header['types'] );
		self::validate_dictionaries( $header['dictionaries'] );
		self::validate_tables( $header['tables'] );

		return $header;
	}

	/**
	 * Validate the configuration fingerprint.
	 *
	 * @param mixed $config Config.
	 * @return void
	 */
	private static function validate_config( $config ) {
		if ( ! is_array( $config ) ) {
			self::fail( self::bad( 'config' ) );
		}

		self::exact_keys(
			$config,
			array( 'home', 'siteurl', 'wp_version', 'php_version', 'db_version', 'table_prefix', 'is_multisite', 'blog_id', 'permalink_structure', 'theme', 'plugins', 'upload_baseurl', 'woocommerce' ),
			'config'
		);

		foreach ( array( 'home', 'siteurl', 'upload_baseurl' ) as $key ) {
			self::url( $config[ $key ], 'config.' . $key );
		}

		self::pattern( $config['wp_version'], '/^[0-9A-Za-z.+-]{0,40}$/', 'config.wp_version' );
		self::pattern( $config['php_version'], '/^[0-9A-Za-z .+~:_-]{0,100}$/', 'config.php_version' );
		self::pattern( $config['db_version'], '/^[0-9A-Za-z .+~:_()-]{0,100}$/', 'config.db_version' );
		self::pattern( $config['table_prefix'], '/^[A-Za-z0-9_]{1,40}$/', 'config.table_prefix' );
		self::pattern( $config['permalink_structure'], '#^[A-Za-z0-9%/_.-]{0,200}$#', 'config.permalink_structure' );

		if ( ! is_bool( $config['is_multisite'] ) ) {
			self::fail( self::bad( 'config.is_multisite' ) );
		}
		self::int_in( $config['blog_id'], 1, PHP_INT_MAX, 'config.blog_id' );

		$theme = $config['theme'];
		if ( ! is_array( $theme ) ) {
			self::fail( self::bad( 'config.theme' ) );
		}
		self::exact_keys( $theme, array( 'stylesheet', 'template', 'version' ), 'config.theme' );
		self::pattern( $theme['stylesheet'], '/^[A-Za-z0-9._-]{0,100}$/', 'config.theme.stylesheet' );
		self::pattern( $theme['template'], '/^[A-Za-z0-9._-]{0,100}$/', 'config.theme.template' );
		self::pattern( $theme['version'], '/^[0-9A-Za-z.+-]{0,40}$/', 'config.theme.version' );

		if ( ! is_array( $config['plugins'] ) || count( $config['plugins'] ) > 1000 ) {
			self::fail( self::bad( 'config.plugins' ) );
		}
		foreach ( $config['plugins'] as $file => $version ) {
			self::pattern( (string) $file, '#^[A-Za-z0-9._/-]{1,200}$#', 'config.plugins' );
			self::pattern( $version, '/^[0-9A-Za-z.+-]{0,40}$/', 'config.plugins' );
		}

		$woo = $config['woocommerce'];
		if ( ! is_array( $woo ) ) {
			self::fail( self::bad( 'config.woocommerce' ) );
		}
		self::exact_keys( $woo, array( 'active', 'version', 'storage', 'hpos_table', 'sync_enabled', 'pages' ), 'config.woocommerce' );
		foreach ( array( 'active', 'hpos_table', 'sync_enabled' ) as $key ) {
			if ( ! is_bool( $woo[ $key ] ) ) {
				self::fail( self::bad( 'config.woocommerce.' . $key ) );
			}
		}
		self::pattern( $woo['version'], '/^[0-9A-Za-z.+-]{0,40}$/', 'config.woocommerce.version' );
		self::one_of( $woo['storage'], array( Woo_Storage::MODE_HPOS, Woo_Storage::MODE_LEGACY, Woo_Storage::MODE_NONE ), 'config.woocommerce.storage' );
		if ( ! is_array( $woo['pages'] ) || count( $woo['pages'] ) > 10 ) {
			self::fail( self::bad( 'config.woocommerce.pages' ) );
		}
		foreach ( $woo['pages'] as $page => $id ) {
			self::one_of( (string) $page, array( 'shop', 'cart', 'checkout', 'myaccount', 'terms' ), 'config.woocommerce.pages' );
			self::int_in( $id, 0, PHP_INT_MAX, 'config.woocommerce.pages' );
		}
	}

	/**
	 * Validate record type definitions.
	 *
	 * @param mixed $types Types.
	 * @return void
	 */
	private static function validate_types( $types ) {
		if ( ! is_array( $types ) || count( $types ) > self::MAX_TYPES ) {
			self::fail( self::bad( 'types' ) );
		}

		foreach ( $types as $key => $type ) {
			if ( ! is_array( $type ) ) {
				self::fail( self::bad( 'types' ) );
			}
			self::exact_keys( $type, array( 'source', 'arg', 'count', 'chunks' ), 'types' );

			$definition = array(
				'key'    => (string) $key,
				'source' => $type['source'],
				'arg'    => $type['arg'],
			);

			if ( ! Source_Registry::is_valid_definition( $definition ) ) {
				self::fail( self::bad( 'types' ) );
			}

			self::int_in( $type['count'], 0, PHP_INT_MAX, 'types.count' );
			self::int_in( $type['chunks'], 0, self::MAX_CHUNKS, 'types.chunks' );
		}
	}

	/**
	 * Validate dictionaries.
	 *
	 * @param mixed $dictionaries Dictionaries.
	 * @return void
	 */
	private static function validate_dictionaries( $dictionaries ) {
		if ( ! is_array( $dictionaries ) ) {
			self::fail( self::bad( 'dictionaries' ) );
		}

		foreach ( $dictionaries as $name => $list ) {
			self::one_of( (string) $name, self::DICTIONARIES, 'dictionaries' );

			if ( ! is_array( $list ) || array() === $list || ! self::is_list( $list ) || count( $list ) > Dictionary::MAX_ENTRIES + 1 || '' !== $list[0] ) {
				self::fail( self::bad( 'dictionaries' ) );
			}

			foreach ( $list as $value ) {
				if ( Dictionary::OTHER !== $value && ! Dictionary::is_vocabulary( $value ) ) {
					self::fail( self::bad( 'dictionaries' ) );
				}
			}

			if ( count( array_unique( $list ) ) !== count( $list ) ) {
				self::fail( self::bad( 'dictionaries' ) );
			}
		}
	}

	/**
	 * Validate table counts.
	 *
	 * @param mixed $tables Tables.
	 * @return void
	 */
	private static function validate_tables( $tables ) {
		if ( ! is_array( $tables ) || count( $tables ) > self::MAX_TABLES ) {
			self::fail( self::bad( 'tables' ) );
		}

		foreach ( $tables as $name => $count ) {
			self::pattern( (string) $name, '/^[A-Za-z0-9_$]{1,64}$/', 'tables' );

			if ( ! is_array( $count ) ) {
				self::fail( self::bad( 'tables' ) );
			}
			self::exact_keys( $count, array( 'rows', 'approximate', 'missing' ), 'tables' );
			self::int_in( $count['rows'], 0, PHP_INT_MAX, 'tables.rows' );

			if ( ! is_bool( $count['approximate'] ) || ! is_bool( $count['missing'] ) ) {
				self::fail( self::bad( 'tables' ) );
			}
		}
	}

	/**
	 * Validate and fully decode every chunk.
	 *
	 * @param mixed                $chunks Chunks.
	 * @param array<string, mixed> $header Validated header.
	 * @return array<int, array<string, mixed>> Chunk rows ready to store.
	 */
	private static function validate_chunks( $chunks, array $header ) {
		if ( ! is_array( $chunks ) || count( $chunks ) > self::MAX_CHUNKS || ! self::is_list( $chunks ) ) {
			self::fail( self::bad( 'chunks' ) );
		}

		$per_type = array();
		$out      = array();

		foreach ( $chunks as $chunk ) {
			if ( ! is_array( $chunk ) ) {
				self::fail( self::bad( 'chunks' ) );
			}
			self::exact_keys( $chunk, array( 'type', 'seq', 'first_id', 'last_id', 'count', 'sha256', 'payload' ), 'chunks' );

			$type = $chunk['type'];
			if ( ! is_string( $type ) || ! isset( $header['types'][ $type ] ) ) {
				self::fail( self::bad( 'chunks.type' ) );
			}

			self::int_in( $chunk['seq'], 0, self::MAX_CHUNKS, 'chunks.seq' );
			self::int_in( $chunk['first_id'], 1, PHP_INT_MAX, 'chunks.first_id' );
			self::int_in( $chunk['last_id'], 1, PHP_INT_MAX, 'chunks.last_id' );
			self::int_in( $chunk['count'], 1, Chunk_Codec::MAX_RECORDS, 'chunks.count' );
			self::pattern( $chunk['sha256'], '/^[0-9a-f]{64}$/', 'chunks.sha256' );

			if ( ! is_string( $chunk['payload'] ) ) {
				self::fail( self::bad( 'chunks.payload' ) );
			}

			if ( ! isset( $per_type[ $type ] ) ) {
				$per_type[ $type ] = array(
					'next_seq' => 0,
					'last_id'  => 0,
					'records'  => 0,
				);
			}

			// Chunks of a type must be in order, consecutive and non-overlapping.
			if ( $chunk['seq'] !== $per_type[ $type ]['next_seq'] || $chunk['first_id'] <= $per_type[ $type ]['last_id'] || $chunk['last_id'] < $chunk['first_id'] ) {
				self::fail( __( 'The snapshot\'s record lists are out of order or overlap. The file is damaged or was edited.', 'unstoppable-migration-inspector' ) );
			}

			$definition = array(
				'key'    => $type,
				'source' => $header['types'][ $type ]['source'],
				'arg'    => $header['types'][ $type ]['arg'],
			);
			$source     = Source_Registry::make( $definition, Woo_Storage::MODE_LEGACY, $header['content_salt'] );

			try {
				$decoded = Chunk_Codec::decode( $chunk['payload'], $chunk['count'], $chunk['sha256'], $source->kinds() );
			} catch ( \UnexpectedValueException $error ) {
				self::fail( __( 'Part of the snapshot is damaged (a record list failed its checksum or could not be read).', 'unstoppable-migration-inspector' ) );
			}

			if ( $decoded['ids'][0] !== $chunk['first_id'] || end( $decoded['ids'] ) !== $chunk['last_id'] ) {
				self::fail( __( 'Part of the snapshot does not match its own index. The file is damaged or was edited.', 'unstoppable-migration-inspector' ) );
			}

			$per_type[ $type ]['next_seq'] = $chunk['seq'] + 1;
			$per_type[ $type ]['last_id']  = $chunk['last_id'];
			$per_type[ $type ]['records'] += $chunk['count'];

			$out[] = array(
				'record_type' => $type,
				'seq'         => $chunk['seq'],
				'first_id'    => $chunk['first_id'],
				'last_id'     => $chunk['last_id'],
				'row_count'   => $chunk['count'],
				'sha256'      => $chunk['sha256'],
				'payload'     => $chunk['payload'],
			);
		}

		foreach ( $header['types'] as $key => $type ) {
			$records = isset( $per_type[ $key ] ) ? $per_type[ $key ]['records'] : 0;
			$count   = isset( $per_type[ $key ] ) ? $per_type[ $key ]['next_seq'] : 0;

			if ( $records !== $type['count'] || $count !== $type['chunks'] ) {
				self::fail( __( 'The snapshot\'s totals do not match its record lists. The file is incomplete, damaged or was edited.', 'unstoppable-migration-inspector' ) );
			}
		}

		return $out;
	}

	/**
	 * Whether an array is a plain list (keys 0..n-1 in order).
	 *
	 * @param array<mixed> $data Data.
	 * @return bool
	 */
	private static function is_list( array $data ) {
		return array() === $data || array_keys( $data ) === range( 0, count( $data ) - 1 );
	}

	/**
	 * Require exactly these keys.
	 *
	 * @param array<string, mixed> $data Data.
	 * @param string[]             $keys Allowed and required keys.
	 * @param string               $what Name for the message.
	 * @return void
	 */
	private static function exact_keys( array $data, array $keys, $what ) {
		$have = array_keys( $data );
		sort( $have );
		sort( $keys );

		if ( $have !== $keys ) {
			self::fail( self::bad( $what ) );
		}
	}

	/**
	 * Require an integer in a range.
	 *
	 * @param mixed  $value Value.
	 * @param int    $min   Minimum.
	 * @param int    $max   Maximum.
	 * @param string $what  Name.
	 * @return void
	 */
	private static function int_in( $value, $min, $max, $what ) {
		if ( ! is_int( $value ) || $value < $min || $value > $max ) {
			self::fail( self::bad( $what ) );
		}
	}

	/**
	 * Require a string matching a pattern.
	 *
	 * @param mixed  $value   Value.
	 * @param string $pattern Regex.
	 * @param string $what    Name.
	 * @return void
	 */
	private static function pattern( $value, $pattern, $what ) {
		if ( ! is_string( $value ) || ! preg_match( $pattern, $value ) ) {
			self::fail( self::bad( $what ) );
		}
	}

	/**
	 * Require one of a set of strings.
	 *
	 * @param mixed    $value   Value.
	 * @param string[] $allowed Allowed values.
	 * @param string   $what    Name.
	 * @return void
	 */
	private static function one_of( $value, array $allowed, $what ) {
		if ( ! is_string( $value ) || ! in_array( $value, $allowed, true ) ) {
			self::fail( self::bad( $what ) );
		}
	}

	/**
	 * Require an empty string or an http(s) URL.
	 *
	 * @param mixed  $value Value.
	 * @param string $what  Name.
	 * @return void
	 */
	private static function url( $value, $what ) {
		if ( ! is_string( $value ) || strlen( $value ) > 2048 ) {
			self::fail( self::bad( $what ) );
		}

		if ( '' !== $value && ( ! preg_match( '#^https?://[^\s<>"\']+$#i', $value ) || false === wp_parse_url( $value ) ) ) {
			self::fail( self::bad( $what ) );
		}
	}

	/**
	 * Message for an invalid field.
	 *
	 * @param string $what Field name.
	 * @return string
	 */
	private static function bad( $what ) {
		return sprintf(
			/* translators: %s: name of the part of the file that failed validation. */
			__( 'The snapshot file is not valid (problem in "%s"). It may be damaged, edited, or not a snapshot file.', 'unstoppable-migration-inspector' ),
			$what
		);
	}

	/**
	 * Stop validation.
	 *
	 * @param string $message Message for the administrator.
	 * @return never
	 * @throws Snapshot_Validation_Exception Always.
	 */
	private static function fail( $message ) {
		throw new Snapshot_Validation_Exception( esc_html( $message ) );
	}
}
