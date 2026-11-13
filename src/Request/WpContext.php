<?php
/**
 * WordPress request context.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Request;

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only inspection of the request shape; nothing is acted on.

/**
 * Reads the WordPress state that `Route` needs, at shutdown, without side effects.
 *
 * Every lookup tolerates a request that ended early (before the query ran or WooCommerce loaded).
 */
final class WpContext {

	/**
	 * Build the request context.
	 *
	 * @return array<string, mixed> Keys used by `Route`, plus `paged` (int) and `path` (string).
	 */
	public static function collect(): array {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only collapsed into a pattern, never output.
		$path = substr( $uri, 0, strcspn( $uri, '?#' ) );

		$ajax  = function_exists( 'wp_doing_ajax' ) && wp_doing_ajax();
		$query = isset( $GLOBALS['wp_query'] ) && $GLOBALS['wp_query'] instanceof \WP_Query;

		return array(
			'rest_route'          => self::restRoute(),
			'wc_ajax'             => self::queryArg( 'wc-ajax' ),
			'wc_api'              => self::queryArg( 'wc-api' ),
			'ajax_action'         => $ajax && isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? wp_unslash( $_REQUEST['action'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Route cleans it.
			'doing_cron'          => function_exists( 'wp_doing_cron' ) && wp_doing_cron(),
			'xmlrpc'              => defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST,
			'pagenow'             => isset( $GLOBALS['pagenow'] ) && is_string( $GLOBALS['pagenow'] ) ? $GLOBALS['pagenow'] : '',
			'is_admin'            => function_exists( 'is_admin' ) && is_admin(),
			'is_robots'           => $query && is_robots(),
			'sitemap'             => $query && self::nonEmptyString( get_query_var( 'sitemap' ) ),
			'is_feed'             => $query && is_feed(),
			'is_404'              => $query && is_404(),
			'is_search'           => $query && is_search(),
			'is_product'          => $query && function_exists( 'is_product' ) && is_product(),
			'is_shop'             => $query && function_exists( 'is_shop' ) && is_shop(),
			'is_product_taxonomy' => $query && function_exists( 'is_product_taxonomy' ) && is_product_taxonomy(),
			'is_front_page'       => $query && is_front_page(),
			'is_home'             => $query && is_home(),
			'is_page'             => $query && is_page(),
			'is_singular'         => $query && is_singular(),
			'method'              => isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? wp_unslash( $_SERVER['REQUEST_METHOD'] ) : 'GET', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Compared, then cleaned by the recorder.
			'logged_in_cookie'    => defined( 'LOGGED_IN_COOKIE' ) && isset( $_COOKIE[ LOGGED_IN_COOKIE ] ),
			'cart_cookie'         => isset( $_COOKIE['woocommerce_items_in_cart'] ),
			'has_query'           => ! empty( $_GET ),
			'donotcachepage'      => defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE,
			'page_cache'          => self::pageCache(),
			'paged'               => self::paged( $query, $path ),
			'path'                => $path,
		);
	}

	/**
	 * REST route of a REST request, or null when this is not one.
	 *
	 * @return string|null
	 */
	private static function restRoute(): ?string {
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			return null;
		}
		if ( isset( $GLOBALS['wp'] ) && $GLOBALS['wp'] instanceof \WP && isset( $GLOBALS['wp']->query_vars['rest_route'] ) && is_string( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
			return $GLOBALS['wp']->query_vars['rest_route'];
		}
		return '/';
	}

	/**
	 * A WooCommerce endpoint argument from the query vars or the query string.
	 *
	 * @param string $name Argument name.
	 * @return string
	 */
	private static function queryArg( string $name ): string {
		if ( isset( $_GET[ $name ] ) && is_string( $_GET[ $name ] ) ) {
			return wp_unslash( $_GET[ $name ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Route cleans it to [a-z0-9_-/].
		}
		if ( isset( $GLOBALS['wp'] ) && $GLOBALS['wp'] instanceof \WP && isset( $GLOBALS['wp']->query_vars[ $name ] ) && is_string( $GLOBALS['wp']->query_vars[ $name ] ) ) {
			return $GLOBALS['wp']->query_vars[ $name ];
		}
		return '';
	}

	/**
	 * Whether a value is a non-empty string.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function nonEmptyString( $value ): bool {
		return is_string( $value ) && '' !== $value;
	}

	/**
	 * Whether a page cache drop-in is active.
	 *
	 * @return bool
	 */
	private static function pageCache(): bool {
		return defined( 'WP_CACHE' ) && WP_CACHE && file_exists( WP_CONTENT_DIR . '/advanced-cache.php' );
	}

	/**
	 * Page number from the `paged` query var, else from a `/page/N/` path segment.
	 *
	 * @param bool   $query Whether the main query exists.
	 * @param string $path  Request path.
	 * @return int
	 */
	private static function paged( bool $query, string $path ): int {
		if ( $query ) {
			$paged = (int) get_query_var( 'paged' );
			if ( $paged > 0 ) {
				return $paged;
			}
		}
		if ( 1 === preg_match( '#/page/(\d{1,6})(?:/|$)#', $path, $m ) ) {
			return (int) $m[1];
		}
		return 0;
	}
}
