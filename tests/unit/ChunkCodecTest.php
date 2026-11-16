<?php
/**
 * Tests for the snapshot chunk codec.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Tests;

use UnstoppableSolutions\MigrationInspector\Snapshots\Chunk_Codec;

/**
 * Chunk codec tests.
 */
final class ChunkCodecTest extends Test_Case {

	/**
	 * Standard post-like field kinds.
	 *
	 * @return array<string, int>
	 */
	private function kinds() {
		return array(
			'status'   => Chunk_Codec::KIND_UNSIGNED,
			'modified' => Chunk_Codec::KIND_DELTA,
			'length'   => Chunk_Codec::KIND_UNSIGNED,
		);
	}

	/**
	 * Encode then decode, returning the decoded chunk.
	 *
	 * @param int[]                $ids    IDs.
	 * @param array<string, int[]> $fields Fields.
	 * @param array<string, int>   $kinds  Kinds.
	 * @return array{ids: int[], fields: array<string, int[]>}
	 */
	private function round_trip( array $ids, array $fields, array $kinds ) {
		$chunk = Chunk_Codec::encode( $ids, $fields, $kinds );

		return Chunk_Codec::decode( $chunk['payload'], $chunk['count'], $chunk['sha256'], $kinds );
	}

	public function test_single_record_round_trip() {
		$fields  = array(
			'status'   => array( 3 ),
			'modified' => array( 1763300000 ),
			'length'   => array( 0 ),
		);
		$decoded = $this->round_trip( array( 42 ), $fields, $this->kinds() );

		$this->assertSame( array( 42 ), $decoded['ids'] );
		$this->assertSame( $fields, $decoded['fields'] );
	}

	public function test_sparse_ids_and_falling_times_round_trip() {
		$ids    = array( 1, 2, 3, 1000, 1001, 999999, 1000000, PHP_INT_MAX - 5 );
		$fields = array(
			'status'   => array( 0, 1, 2, 3, 4, 5, 6, 7 ),
			'modified' => array( 1763300000, 0, 1763300000, 1000, 2000000000, 5, 5, 1 ),
			'length'   => array( 0, 1, 127, 128, 16383, 16384, PHP_INT_MAX, 99 ),
		);

		$decoded = $this->round_trip( $ids, $fields, $this->kinds() );

		$this->assertSame( $ids, $decoded['ids'] );
		$this->assertSame( $fields, $decoded['fields'] );
	}

	public function test_ids_only_chunk_round_trip() {
		$ids     = range( 10, 5009 );
		$decoded = $this->round_trip( $ids, array(), array() );

		$this->assertSame( $ids, $decoded['ids'] );
		$this->assertSame( array(), $decoded['fields'] );
	}

	public function test_sequential_ids_are_compact() {
		$ids   = range( 1, 5000 );
		$chunk = Chunk_Codec::encode( $ids, array(), array() );

		// 5,000 sequential IDs: about one byte each before compression and
		// far less after.
		$this->assertLessThan( 200, strlen( $chunk['payload'] ) );
		$this->assertSame( 1, $chunk['first_id'] );
		$this->assertSame( 5000, $chunk['last_id'] );
	}

	public function test_one_million_ids_round_trip_in_chunks() {
		$all = array();
		$id  = 0;
		// Mostly sequential with periodic gaps, like a real posts table.
		for ( $i = 0; $i < 1000000; $i++ ) {
			$id   += ( 0 === $i % 97 ) ? 13 : 1;
			$all[] = $id;
		}

		$bytes = 0;
		foreach ( array_chunk( $all, Chunk_Codec::MAX_RECORDS ) as $ids ) {
			$chunk  = Chunk_Codec::encode( $ids, array(), array() );
			$bytes += strlen( $chunk['payload'] );
			$out    = Chunk_Codec::decode( $chunk['payload'], $chunk['count'], $chunk['sha256'], array() );
			// Compare chunk by chunk so the test stays inside a 128 MB memory_limit.
			$this->assertSame( $ids, $out['ids'] );
		}

		$this->assertLessThan( 1000000, $bytes, 'A million IDs should encode to well under a megabyte.' );
	}

	/**
	 * Invalid encode inputs.
	 *
	 * @return array<string, array{0: int[], 1: array<string, int[]>}>
	 */
	public function invalid_encode_provider() {
		return array(
			'empty'                => array( array(), array() ),
			'too many'             => array( range( 1, Chunk_Codec::MAX_RECORDS + 1 ), array() ),
			'descending'           => array( array( 5, 4 ), array() ),
			'duplicate'            => array( array( 5, 5 ), array() ),
			'zero id'              => array( array( 0, 1 ), array() ),
			'negative id'          => array( array( -1 ), array() ),
			'string id'            => array( array( '1' ), array() ),
			'short field'          => array( array( 1, 2 ), array( 'status' => array( 1 ) ) ),
			'negative unsigned'    => array( array( 1 ), array( 'status' => array( -1 ) ) ),
			'non-int field value'  => array( array( 1 ), array( 'status' => array( 1.5 ) ) ),
		);
	}

	/**
	 * Invalid inputs are refused.
	 *
	 * @dataProvider invalid_encode_provider
	 *
	 * @param int[]                $ids    IDs.
	 * @param array<string, int[]> $fields Fields.
	 */
	public function test_invalid_input_is_refused( array $ids, array $fields ) {
		$kinds = array();
		foreach ( array_keys( $fields ) as $name ) {
			$kinds[ $name ] = Chunk_Codec::KIND_UNSIGNED;
		}

		$this->expectException( \InvalidArgumentException::class );
		Chunk_Codec::encode( $ids, $fields, $kinds );
	}

	public function test_invalid_field_name_is_refused() {
		$this->expectException( \InvalidArgumentException::class );
		Chunk_Codec::encode( array( 1 ), array( 'Bad-Name' => array( 1 ) ), array( 'Bad-Name' => 0 ) );
	}

	public function test_wrong_checksum_is_rejected() {
		$chunk = Chunk_Codec::encode( array( 1, 2, 3 ), array(), array() );

		$this->expectException( \UnexpectedValueException::class );
		Chunk_Codec::decode( $chunk['payload'], 3, str_repeat( '0', 64 ), array() );
	}

	public function test_wrong_count_is_rejected() {
		$chunk = Chunk_Codec::encode( array( 1, 2, 3 ), array(), array() );

		$this->expectException( \UnexpectedValueException::class );
		Chunk_Codec::decode( $chunk['payload'], 4, $chunk['sha256'], array() );
	}

	public function test_wrong_fields_are_rejected() {
		$chunk = Chunk_Codec::encode( array( 1 ), array( 'status' => array( 1 ) ), array( 'status' => 0 ) );

		$this->expectException( \UnexpectedValueException::class );
		Chunk_Codec::decode( $chunk['payload'], 1, $chunk['sha256'], array( 'status' => 1 ) );
	}

	public function test_tampered_payload_is_rejected() {
		$chunk    = Chunk_Codec::encode( range( 1, 100 ), array(), array() );
		$binary   = gzinflate( base64_decode( $chunk['payload'] ) );
		$binary  .= chr( 1 ); // Append a byte.
		$tampered = base64_encode( gzdeflate( $binary ) );

		$this->expectException( \UnexpectedValueException::class );
		Chunk_Codec::decode( $tampered, 100, hash( 'sha256', $binary ), array() );
	}

	public function test_not_base64_is_rejected() {
		$this->expectException( \UnexpectedValueException::class );
		Chunk_Codec::decode( '!!!not base64!!!', 1, str_repeat( 'a', 64 ), array() );
	}

	public function test_decompression_bomb_is_rejected() {
		// Two million zero bytes compress to a few kilobytes but claim to be
		// a one-record chunk.
		$bomb = base64_encode( gzdeflate( str_repeat( "\0", 2000000 ), 9 ) );

		$this->expectException( \UnexpectedValueException::class );
		Chunk_Codec::decode( $bomb, 1, str_repeat( 'a', 64 ), array() );
	}

	public function test_truncated_binary_is_rejected() {
		$chunk     = Chunk_Codec::encode( range( 1, 10 ), array(), array() );
		$binary    = gzinflate( base64_decode( $chunk['payload'] ) );
		$truncated = substr( $binary, 0, -3 );

		$this->expectException( \UnexpectedValueException::class );
		Chunk_Codec::decode( base64_encode( gzdeflate( $truncated ) ), 10, hash( 'sha256', $truncated ), array() );
	}

	public function test_oversized_varint_is_rejected() {
		// Valid header claiming one record, then an 11-byte varint.
		$binary = 'c' . chr( 1 ) . chr( 1 ) . chr( 0 ) . str_repeat( chr( 0xFF ), 10 ) . chr( 1 );

		$this->expectException( \UnexpectedValueException::class );
		Chunk_Codec::decode( base64_encode( gzdeflate( $binary ) ), 1, hash( 'sha256', $binary ), array() );
	}
}
