<?php
/**
 * URL path collapsing.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Request;

/**
 * Turns a request path into a low-cardinality pattern (for example "/en/product/star/").
 */
final class UrlPattern {

	/**
	 * Segments that are always kept verbatim.
	 */
	public const DEFAULT_KEEP = array(
		'page',
		'feed',
		'wp-json',
		'wp-content',
		'wp-admin',
		'shop',
		'cart',
		'checkout',
		'my-account',
		'product',
		'product-category',
		'product-tag',
		'category',
		'tag',
		'author',
	);

	/**
	 * Longest input considered, in bytes.
	 */
	private const MAX_INPUT = 2048;

	/**
	 * Most segments in the result before it is cut off.
	 */
	private const MAX_SEGMENTS = 6;

	/**
	 * Collapse a path.
	 *
	 * @param string   $path Request path, optionally with query string.
	 * @param string[] $keep Extra segments to keep (language slugs, permalink bases).
	 * @return string Pattern with leading slash; trailing slash unless cut off.
	 */
	public static function collapse( string $path, array $keep ): string {
		$allowed = array_flip( self::DEFAULT_KEEP );
		foreach ( $keep as $slug ) {
			if ( is_string( $slug ) && '' !== $slug ) {
				$allowed[ strtolower( $slug ) ] = true;
			}
		}

		$out       = array();
		$last_star = false;
		foreach ( self::segments( $path ) as $segment ) {
			if ( isset( $allowed[ $segment ] ) ) {
				$out[]     = $segment;
				$last_star = false;
			} elseif ( ! $last_star ) {
				$out[]     = '*';
				$last_star = true;
			}
		}

		if ( array() === $out ) {
			return '/';
		}
		if ( count( $out ) > self::MAX_SEGMENTS ) {
			return '/' . implode( '/', array_slice( $out, 0, self::MAX_SEGMENTS ) ) . '/…';
		}
		return '/' . implode( '/', $out ) . '/';
	}

	/**
	 * Return the language slug of a path, or an empty string.
	 *
	 * @param string   $path  Request path.
	 * @param string[] $slugs Known language slugs.
	 * @return string
	 */
	public static function lang( string $path, array $slugs ): string {
		$segments = self::segments( $path );
		if ( array() === $segments ) {
			return '';
		}
		$first = $segments[0];
		foreach ( $slugs as $slug ) {
			if ( is_string( $slug ) && strtolower( $slug ) === $first ) {
				return $first;
			}
		}
		return '';
	}

	/**
	 * Normalise a path into non-empty, lowercased, printable-ASCII segments.
	 *
	 * @param string $path Raw path.
	 * @return string[]
	 */
	private static function segments( string $path ): array {
		if ( strlen( $path ) > self::MAX_INPUT ) {
			$path = substr( $path, 0, self::MAX_INPUT );
		}
		$cut = strcspn( $path, '?#' );
		if ( $cut < strlen( $path ) ) {
			$path = substr( $path, 0, $cut );
		}
		if ( false !== strpos( $path, '%' ) ) {
			$path = rawurldecode( $path );
		}
		$path = (string) preg_replace( '/[^\x20-\x7e]+/', '', $path );

		return array_values(
			array_filter(
				explode( '/', strtolower( $path ) ),
				static function ( string $segment ): bool {
					return '' !== $segment;
				}
			)
		);
	}
}
