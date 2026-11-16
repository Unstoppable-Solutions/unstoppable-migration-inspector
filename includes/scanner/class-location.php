<?php
/**
 * Site locations (host plus path) and how they appear in stored content.
 *
 * @package UnstoppableSolutions\MigrationInspector
 */

namespace UnstoppableSolutions\MigrationInspector\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * A "location" is where a site lives, without the scheme:
 * `example.com`, `example.com:8080` or `example.com/staging01`. Lower case,
 * no trailing slash.
 *
 * Stored content writes the same URL in several ways. This class counts
 * all of them:
 * - plain:        //example.com/staging01
 * - JSON-escaped: \/\/example.com\/staging01  (block editor, Elementor…)
 * - URL-encoded:  %2f%2fexample.com%2fstaging01 (redirect parameters…)
 *
 * A match must end at a boundary, so `old.com` does not match
 * `old.com.au` or `old.community`.
 */
final class Location {

	/**
	 * Location of a URL, or '' when it has no host.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function from_url( $url ) {
		$parts = wp_parse_url( (string) $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		$location = strtolower( $parts['host'] );

		if ( isset( $parts['port'] ) ) {
			$location .= ':' . (int) $parts['port'];
		}

		if ( ! empty( $parts['path'] ) ) {
			$path = trim( strtolower( $parts['path'] ), '/' );

			if ( '' !== $path ) {
				$location .= '/' . $path;
			}
		}

		return $location;
	}

	/**
	 * Host part of a location.
	 *
	 * @param string $location Location.
	 * @return string
	 */
	public static function host( $location ) {
		$host = explode( '/', (string) $location, 2 );
		$host = explode( ':', $host[0], 2 );

		return $host[0];
	}

	/**
	 * The forms a URL prefix takes in stored content.
	 *
	 * @param string $prefix Lower-case prefix, e.g. "//old.com/sub" or "http://example.com".
	 * @return string[]
	 */
	public static function encodings( $prefix ) {
		$prefix = strtolower( (string) $prefix );
		$json   = str_replace( '/', '\\/', $prefix );
		$url    = str_replace( array( ':', '/' ), array( '%3a', '%2f' ), $prefix );

		return array_values( array_unique( array( $prefix, $json, $url ) ) );
	}

	/**
	 * Count occurrences of a prefix in lower-cased text, in every encoding,
	 * ending at a boundary.
	 *
	 * @param string $text   Lower-cased text.
	 * @param string $prefix Lower-case prefix.
	 * @return int
	 */
	public static function count( $text, $prefix ) {
		$total = 0;

		foreach ( self::encodings( $prefix ) as $needle ) {
			if ( false === strpos( $text, $needle ) ) {
				continue;
			}

			$total += (int) preg_match_all( '/' . preg_quote( $needle, '/' ) . '(?![a-z0-9.\-])/', $text );
		}

		return $total;
	}

	/**
	 * Count references to an old location that are not references to a
	 * longer, current location it is a prefix of.
	 *
	 * Example: on a staging copy at example.com/staging01, the old location
	 * example.com is a prefix of the current one. Links to
	 * example.com/staging01/… are the site's own and must not count.
	 *
	 * @param string   $text    Lower-cased text.
	 * @param string   $prefix  Prefix to count.
	 * @param string[] $shadows Longer prefixes whose matches are subtracted.
	 * @return int
	 */
	public static function count_excluding( $text, $prefix, array $shadows ) {
		$count = self::count( $text, $prefix );

		if ( 0 === $count ) {
			return 0;
		}

		foreach ( $shadows as $shadow ) {
			$count -= self::count( $text, $shadow );
		}

		return max( 0, $count );
	}

	/**
	 * A short excerpt around the first occurrence of a prefix.
	 *
	 * @param string $text   Original text.
	 * @param string $prefix Lower-case prefix.
	 * @return string Plain text, at most about 120 characters.
	 */
	public static function excerpt( $text, $prefix ) {
		$lower = strtolower( (string) $text );
		$at    = false;

		foreach ( self::encodings( $prefix ) as $needle ) {
			$position = strpos( $lower, $needle );

			if ( false !== $position && ( false === $at || $position < $at ) ) {
				$at = $position;
			}
		}

		if ( false === $at ) {
			return '';
		}

		$start   = max( 0, $at - 40 );
		$excerpt = substr( (string) $text, $start, 120 );
		$excerpt = wp_check_invalid_utf8( $excerpt, true );
		$excerpt = trim( (string) preg_replace( '/\s+/', ' ', $excerpt ) );

		return ( $start > 0 ? '…' : '' ) . $excerpt . '…';
	}
}
