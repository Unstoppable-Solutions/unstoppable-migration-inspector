<?php
/**
 * Compact, verifiable encoding for snapshot record chunks.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Snapshots;

defined( 'ABSPATH' ) || exit;

/**
 * Encodes a chunk of records (sorted IDs plus small integer fields) into a
 * compact string, and decodes it back with strict validation.
 *
 * Format "c1", before compression:
 *   byte    'c', byte 1                     magic and version
 *   varint  record count
 *   varint  field count
 *   per field: varint name length, name bytes, byte kind
 *   IDs:    first ID as varint, then each gap (id - previous id) as varint
 *   per field, one varint per record:
 *     kind 0 (unsigned):    the value
 *     kind 1 (delta):       zigzag(value - previous value), first previous 0
 *
 * The binary is deflated and base64 encoded. The SHA-256 of the binary
 * (before compression) identifies the chunk's content independently of the
 * compression level used.
 *
 * Nothing in a chunk is ever unserialized or evaluated; it only ever
 * produces integers.
 */
final class Chunk_Codec {

	const KIND_UNSIGNED = 0;
	const KIND_DELTA    = 1;

	/**
	 * Maximum records in one chunk.
	 */
	const MAX_RECORDS = 5000;

	/**
	 * Maximum fields per record.
	 */
	const MAX_FIELDS = 8;

	/**
	 * Largest number of bytes one varint may occupy (64-bit values).
	 */
	const MAX_VARINT_BYTES = 10;

	/**
	 * Encode a chunk.
	 *
	 * @param int[]                $ids    Strictly ascending positive IDs.
	 * @param array<string, int[]> $fields Field name => values, one per ID.
	 * @param array<string, int>   $kinds  Field name => KIND_* constant.
	 * @return array{payload: string, sha256: string, count: int, first_id: int, last_id: int}
	 * @throws \InvalidArgumentException When the input is not encodable.
	 */
	public static function encode( array $ids, array $fields, array $kinds ) {
		$count = count( $ids );

		if ( $count < 1 || $count > self::MAX_RECORDS ) {
			throw new \InvalidArgumentException( 'A chunk must hold between 1 and 5000 records.' );
		}

		if ( count( $fields ) > self::MAX_FIELDS || array_keys( $fields ) !== array_keys( $kinds ) ) {
			throw new \InvalidArgumentException( 'Fields and kinds must have the same keys, at most 8.' );
		}

		$binary  = 'c' . chr( 1 );
		$binary .= self::varint( $count );
		$binary .= self::varint( count( $fields ) );

		foreach ( $kinds as $name => $kind ) {
			if ( ! is_string( $name ) || ! preg_match( '/^[a-z_]{1,16}$/', $name ) ) {
				throw new \InvalidArgumentException( 'Invalid field name.' );
			}
			if ( self::KIND_UNSIGNED !== $kind && self::KIND_DELTA !== $kind ) {
				throw new \InvalidArgumentException( 'Invalid field kind.' );
			}
			$binary .= self::varint( strlen( $name ) ) . $name . chr( $kind );
		}

		$previous = 0;
		foreach ( array_values( $ids ) as $index => $id ) {
			if ( ! is_int( $id ) || $id < 1 || $id <= $previous ) {
				throw new \InvalidArgumentException( 'IDs must be positive integers in strictly ascending order.' );
			}
			$binary  .= self::varint( 0 === $index ? $id : $id - $previous );
			$previous = $id;
		}

		foreach ( $fields as $name => $values ) {
			$values = array_values( $values );

			if ( count( $values ) !== $count ) {
				throw new \InvalidArgumentException( 'Every field needs one value per record.' );
			}

			$previous = 0;
			foreach ( $values as $value ) {
				if ( ! is_int( $value ) ) {
					throw new \InvalidArgumentException( 'Field values must be integers.' );
				}

				if ( self::KIND_UNSIGNED === $kinds[ $name ] ) {
					if ( $value < 0 ) {
						throw new \InvalidArgumentException( 'Unsigned field values cannot be negative.' );
					}
					$binary .= self::varint( $value );
				} else {
					$binary  .= self::varint( self::zigzag( self::safe_subtract( $value, $previous ) ) );
					$previous = $value;
				}
			}
		}

		$compressed = gzdeflate( $binary, 6 );

		return array(
			'payload'  => base64_encode( $compressed ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Transport encoding for binary chunk data, not obfuscation.
			'sha256'   => hash( 'sha256', $binary ),
			'count'    => $count,
			'first_id' => $ids[0],
			'last_id'  => $ids[ $count - 1 ],
		);
	}

	/**
	 * Decode and fully validate a chunk.
	 *
	 * @param string             $payload        Base64 payload.
	 * @param int                $expected_count Record count the chunk must hold.
	 * @param string             $expected_sha   Expected SHA-256 of the binary.
	 * @param array<string, int> $expected_kinds Field name => kind the chunk must use.
	 * @return array{ids: int[], fields: array<string, int[]>}
	 * @throws \UnexpectedValueException When the payload is malformed or does not match.
	 */
	public static function decode( $payload, $expected_count, $expected_sha, array $expected_kinds ) {
		if ( ! is_string( $payload ) || ! is_int( $expected_count ) || $expected_count < 1 || $expected_count > self::MAX_RECORDS ) {
			throw new \UnexpectedValueException( 'Invalid chunk header.' );
		}

		$max_binary = self::max_binary_size( $expected_count, count( $expected_kinds ) );

		// Base64 inflates by 4/3; deflate never needs much more than the input.
		if ( strlen( $payload ) > (int) ( $max_binary * 1.5 ) + 1024 ) {
			throw new \UnexpectedValueException( 'Chunk payload is larger than its record count allows.' );
		}

		$compressed = base64_decode( $payload, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Transport encoding for binary chunk data, not obfuscation.

		if ( false === $compressed || '' === $compressed ) {
			throw new \UnexpectedValueException( 'Chunk payload is not valid base64.' );
		}

		// The second argument caps the inflated size, which stops
		// decompression bombs before they allocate memory.
		$binary = @gzinflate( $compressed, $max_binary ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- gzinflate warns on malformed input; the false return is handled below.

		if ( false === $binary ) {
			throw new \UnexpectedValueException( 'Chunk payload could not be decompressed, or is larger than its record count allows.' );
		}

		if ( ! is_string( $expected_sha ) || ! hash_equals( $expected_sha, hash( 'sha256', $binary ) ) ) {
			throw new \UnexpectedValueException( 'Chunk checksum does not match.' );
		}

		$offset = 0;
		$length = strlen( $binary );

		if ( $length < 2 || 'c' !== $binary[0] || 1 !== ord( $binary[1] ) ) {
			throw new \UnexpectedValueException( 'Unknown chunk format.' );
		}
		$offset = 2;

		$count = self::read_varint( $binary, $offset, $length );
		if ( $count !== $expected_count ) {
			throw new \UnexpectedValueException( 'Chunk record count does not match.' );
		}

		$field_count = self::read_varint( $binary, $offset, $length );
		if ( count( $expected_kinds ) !== $field_count ) {
			throw new \UnexpectedValueException( 'Chunk field list does not match.' );
		}

		$kinds = array();
		for ( $f = 0; $f < $field_count; $f++ ) {
			$name_length = self::read_varint( $binary, $offset, $length );
			if ( $name_length < 1 || $name_length > 16 || $offset + $name_length + 1 > $length ) {
				throw new \UnexpectedValueException( 'Malformed field name.' );
			}
			$name           = substr( $binary, $offset, $name_length );
			$offset        += $name_length;
			$kinds[ $name ] = ord( $binary[ $offset ] );
			++$offset;
		}

		if ( $kinds !== $expected_kinds ) {
			throw new \UnexpectedValueException( 'Chunk fields do not match the expected fields.' );
		}

		$ids      = array();
		$previous = 0;
		for ( $i = 0; $i < $count; $i++ ) {
			$value = self::read_varint( $binary, $offset, $length );
			if ( $i > 0 && $value < 1 ) {
				throw new \UnexpectedValueException( 'IDs are not strictly ascending.' );
			}
			$id = 0 === $i ? $value : $previous + $value;
			if ( ! is_int( $id ) || $id < 1 || $id < $previous ) {
				throw new \UnexpectedValueException( 'Invalid ID in chunk.' );
			}
			$ids[]    = $id;
			$previous = $id;
		}

		$fields = array();
		foreach ( $kinds as $name => $kind ) {
			$values   = array();
			$previous = 0;
			for ( $i = 0; $i < $count; $i++ ) {
				$raw = self::read_varint( $binary, $offset, $length );
				if ( self::KIND_UNSIGNED === $kind ) {
					$values[] = $raw;
				} else {
					$previous = self::safe_add( $previous, self::unzigzag( $raw ) );
					$values[] = $previous;
				}
			}
			$fields[ $name ] = $values;
		}

		if ( $offset !== $length ) {
			throw new \UnexpectedValueException( 'Unexpected data after the end of the chunk.' );
		}

		return array(
			'ids'    => $ids,
			'fields' => $fields,
		);
	}

	/**
	 * Largest binary size a chunk with this many records and fields can have.
	 *
	 * @param int $count       Records.
	 * @param int $field_count Fields.
	 * @return int Bytes.
	 */
	public static function max_binary_size( $count, $field_count ) {
		$header = 2 + ( 2 * self::MAX_VARINT_BYTES ) + ( $field_count * ( 1 + 16 + 1 ) );

		return $header + ( $count * ( 1 + $field_count ) * self::MAX_VARINT_BYTES );
	}

	/**
	 * Encode a non-negative integer as an unsigned LEB128 varint.
	 *
	 * @param int $value Value, 0 to PHP_INT_MAX.
	 * @return string
	 */
	private static function varint( $value ) {
		$out = '';

		do {
			$byte  = $value & 0x7F;
			$value = $value >> 7;
			if ( 0 !== $value ) {
				$byte |= 0x80;
			}
			$out .= chr( $byte );
		} while ( 0 !== $value );

		return $out;
	}

	/**
	 * Read one varint, advancing the offset.
	 *
	 * @param string $data   Binary data.
	 * @param int    $offset Current offset (updated).
	 * @param int    $length Data length.
	 * @return int
	 * @throws \UnexpectedValueException On truncated or oversized varints.
	 */
	private static function read_varint( $data, &$offset, $length ) {
		$value = 0;
		$shift = 0;

		for ( $i = 0; $i < self::MAX_VARINT_BYTES; $i++ ) {
			if ( $offset >= $length ) {
				throw new \UnexpectedValueException( 'Chunk ends in the middle of a value.' );
			}

			$byte = ord( $data[ $offset ] );
			++$offset;

			$part = $byte & 0x7F;

			// Reject anything that would not fit in a positive PHP integer.
			if ( 63 === $shift && $part > 0 ) {
				throw new \UnexpectedValueException( 'Value in chunk is too large.' );
			}

			$value |= $part << $shift;

			if ( 0 === ( $byte & 0x80 ) ) {
				if ( $value < 0 ) {
					throw new \UnexpectedValueException( 'Value in chunk is too large.' );
				}
				return $value;
			}

			$shift += 7;
		}

		throw new \UnexpectedValueException( 'Value in chunk is too long.' );
	}

	/**
	 * Zigzag-encode a signed value so small negatives stay small.
	 *
	 * Only used for deltas of field values, which are bounded well inside
	 * the 63-bit range by safe_subtract().
	 *
	 * @param int $value Signed value.
	 * @return int Non-negative value.
	 */
	private static function zigzag( $value ) {
		return $value >= 0 ? $value * 2 : ( -$value * 2 ) - 1;
	}

	/**
	 * Reverse zigzag encoding.
	 *
	 * @param int $value Encoded value.
	 * @return int
	 */
	private static function unzigzag( $value ) {
		return 0 === ( $value & 1 ) ? intdiv( $value, 2 ) : -intdiv( $value + 1, 2 );
	}

	/**
	 * Subtract, refusing results that zigzag encoding could not represent.
	 *
	 * @param int $a Minuend.
	 * @param int $b Subtrahend.
	 * @return int
	 * @throws \InvalidArgumentException When the difference is out of range.
	 */
	private static function safe_subtract( $a, $b ) {
		$limit = intdiv( PHP_INT_MAX, 2 );
		$diff  = $a - $b;

		if ( ! is_int( $diff ) || $diff > $limit || $diff < -$limit ) {
			throw new \InvalidArgumentException( 'Field value change is too large to encode.' );
		}

		return $diff;
	}

	/**
	 * Add, refusing results outside the integer range.
	 *
	 * @param int $a Value.
	 * @param int $b Value.
	 * @return int
	 * @throws \UnexpectedValueException On overflow.
	 */
	private static function safe_add( $a, $b ) {
		$sum = $a + $b;

		if ( ! is_int( $sum ) ) {
			throw new \UnexpectedValueException( 'Field value in chunk is out of range.' );
		}

		return $sum;
	}
}
