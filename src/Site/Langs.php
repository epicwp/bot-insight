<?php
/**
 * Language slugs and permalink bases for URL pattern building.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Site;

/**
 * Detects the language plugin and its language slugs, and the permalink bases worth keeping in
 * URL patterns. Stored in the autoloaded option `bot_insight_langs` that the request recorder
 * reads, so a visitor's request never queries a language plugin.
 */
final class Langs {

	public const OPTION = 'bot_insight_langs';

	/**
	 * Language plugin and slugs.
	 *
	 * @return array{plugin: string, slugs: string[]}
	 */
	public static function detect(): array {
		$plugin = '';
		$slugs  = array();
		if ( function_exists( 'pll_languages_list' ) ) {
			$plugin = 'polylang';
			$list   = pll_languages_list( array( 'fields' => 'slug' ) );
			$slugs  = is_array( $list ) ? $list : array();
		} else {
			$wpml = apply_filters( 'wpml_active_languages', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's public API filter.
			if ( is_array( $wpml ) && array() !== $wpml ) {
				$plugin = 'wpml';
				$slugs  = array_keys( $wpml );
			} elseif ( class_exists( 'TRP_Translate_Press' ) ) {
				$trp    = get_option( 'trp_settings' );
				$plugin = 'translatepress';
				$slugs  = is_array( $trp ) && isset( $trp['url-slugs'] ) && is_array( $trp['url-slugs'] ) ? array_values( $trp['url-slugs'] ) : array();
			}
		}
		$out = array();
		foreach ( $slugs as $slug ) {
			$slug = is_scalar( $slug ) ? strtolower( trim( (string) $slug, " \t/" ) ) : '';
			if ( 1 === preg_match( '/^[a-z0-9_-]{1,12}$/', $slug ) && ! in_array( $slug, $out, true ) ) {
				$out[] = $slug;
			}
		}
		return array(
			'plugin' => $plugin,
			'slugs'  => $out,
		);
	}

	/**
	 * Permalink bases to keep as literal URL segments: the WooCommerce product, category and tag
	 * bases and the core category and tag bases.
	 *
	 * @return string[]
	 */
	public static function keep(): array {
		$bases = array();
		if ( function_exists( 'wc_get_permalink_structure' ) ) {
			$wc = wc_get_permalink_structure();
			if ( is_array( $wc ) ) {
				foreach ( array( 'product_base', 'category_base', 'tag_base' ) as $key ) {
					$bases[] = isset( $wc[ $key ] ) && is_string( $wc[ $key ] ) ? $wc[ $key ] : '';
				}
			}
		}
		foreach ( array( 'category_base', 'tag_base' ) as $option ) {
			$value   = get_option( $option, '' );
			$bases[] = is_string( $value ) ? $value : '';
		}
		$out = array();
		foreach ( $bases as $base ) {
			$segment = self::base( $base );
			if ( '' !== $segment && ! in_array( $segment, $out, true ) ) {
				$out[] = $segment;
			}
		}
		return $out;
	}

	/**
	 * First path segment of a permalink base, without slashes and `%…%` placeholders, lowercased.
	 *
	 * @param string $base Base such as `/shop/%product_cat%/`.
	 */
	public static function base( string $base ): string {
		$base = trim( (string) preg_replace( '/%[^%\/]*%/', '', $base ), '/ ' );
		if ( '' === $base ) {
			return '';
		}
		$first = strtolower( explode( '/', $base )[0] );
		return strlen( $first ) > 64 ? '' : $first;
	}

	/**
	 * Write option `bot_insight_langs` (autoloaded).
	 */
	public static function update(): void {
		$value = array(
			'slugs' => self::detect()['slugs'],
			'keep'  => self::keep(),
		);
		if ( false === get_option( self::OPTION ) ) {
			add_option( self::OPTION, $value, '', true );
		} elseif ( get_option( self::OPTION ) !== $value ) {
			update_option( self::OPTION, $value, true );
		}
	}
}
