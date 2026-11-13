<?php
/**
 * Route and uncached reason resolution.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Request;

/**
 * Names the kind of request and, when it reached PHP, the most likely reason it was not cached.
 *
 * Pure function of a context array filled by `WpContext::collect()`. Missing keys count as
 * empty or false; non-string values for string keys count as empty.
 */
final class Route {

	/**
	 * Uncached reasons in the order they are checked.
	 */
	public const REASONS = array(
		'post',
		'logged_in',
		'cart_cookie',
		'query_string',
		'donotcachepage',
		'no_page_cache',
		'cache_miss',
	);

	private const MAX_NAME_LENGTH = 40;

	/**
	 * Resolve the route of a request. First match wins.
	 *
	 * @param array<string, mixed> $ctx Request context.
	 * @return string
	 */
	public static function resolve( array $ctx ): string {
		if ( isset( $ctx['rest_route'] ) && is_string( $ctx['rest_route'] ) ) {
			return 'rest:' . self::restNamespace( $ctx['rest_route'] );
		}

		foreach ( array(
			'wc_ajax'     => 'wc-ajax:',
			'wc_api'      => 'wc-api:',
			'ajax_action' => 'ajax:',
		) as $key => $prefix ) {
			$name = self::name( $ctx[ $key ] ?? '' );
			if ( '' !== $name ) {
				return $prefix . $name;
			}
		}

		if ( ! empty( $ctx['doing_cron'] ) ) {
			return 'cron';
		}
		if ( ! empty( $ctx['xmlrpc'] ) ) {
			return 'xmlrpc';
		}
		if ( 'wp-login.php' === ( $ctx['pagenow'] ?? '' ) ) {
			return 'login';
		}

		foreach ( array(
			'is_admin'            => 'admin',
			'is_robots'           => 'robots',
			'sitemap'             => 'sitemap',
			'is_feed'             => 'feed',
			'is_404'              => '404',
			'is_search'           => 'search',
			'is_product'          => 'product',
			'is_shop'             => 'shop',
			'is_product_taxonomy' => 'product_cat',
		) as $key => $route ) {
			if ( ! empty( $ctx[ $key ] ) ) {
				return $route;
			}
		}

		if ( ! empty( $ctx['is_front_page'] ) || ! empty( $ctx['is_home'] ) ) {
			return 'home';
		}
		if ( ! empty( $ctx['is_page'] ) ) {
			return 'page';
		}
		return ! empty( $ctx['is_singular'] ) ? 'post' : 'other';
	}

	/**
	 * Why a request that reached PHP was not served from a cache.
	 *
	 * An absent or non-string method is treated as GET.
	 *
	 * @param array<string, mixed> $ctx Request context.
	 * @return string One of self::REASONS.
	 */
	public static function uncachedReason( array $ctx ): string {
		$method = $ctx['method'] ?? '';
		$method = is_string( $method ) ? strtoupper( $method ) : '';
		if ( '' !== $method && 'GET' !== $method && 'HEAD' !== $method ) {
			return 'post';
		}
		if ( ! empty( $ctx['logged_in_cookie'] ) ) {
			return 'logged_in';
		}
		if ( ! empty( $ctx['cart_cookie'] ) ) {
			return 'cart_cookie';
		}
		if ( ! empty( $ctx['has_query'] ) ) {
			return 'query_string';
		}
		if ( ! empty( $ctx['donotcachepage'] ) ) {
			return 'donotcachepage';
		}
		if ( empty( $ctx['page_cache'] ) ) {
			return 'no_page_cache';
		}
		return 'cache_miss';
	}

	/**
	 * First two path segments of a REST route, cleaned.
	 *
	 * @param string $route REST route such as `/wc/store/v1/cart`.
	 * @return string
	 */
	private static function restNamespace( string $route ): string {
		$parts = explode( '/', ltrim( $route, '/' ), 3 );
		$ns    = $parts[0];
		if ( '' !== $ns && isset( $parts[1] ) && '' !== $parts[1] ) {
			$ns .= '/' . $parts[1];
		}
		return self::name( $ns );
	}

	/**
	 * Lowercase, strip to `[a-z0-9_\-/]` and cap the length. Non-strings give ''.
	 *
	 * @param mixed $value Raw name.
	 * @return string
	 */
	private static function name( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$name = (string) preg_replace( '/[^a-z0-9_\-\/]+/', '', strtolower( $value ) );
		return substr( $name, 0, self::MAX_NAME_LENGTH );
	}
}
