<?php
/**
 * Tests for route and uncached reason resolution.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Tests\Unit\Request;

use BotInsight\Request\Route;
use PHPUnit\Framework\TestCase;

final class RouteTest extends TestCase {

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function routes(): array {
		return array(
			'rest namespace'        => array( array( 'rest_route' => '/wc/store/v1/cart' ), 'rest:wc/store' ),
			'rest single segment'   => array( array( 'rest_route' => '/oembed' ), 'rest:oembed' ),
			'rest index'            => array( array( 'rest_route' => '/' ), 'rest:' ),
			'rest empty string'     => array( array( 'rest_route' => '' ), 'rest:' ),
			'rest uppercase'        => array( array( 'rest_route' => '/WC/Store/v1' ), 'rest:wc/store' ),
			'rest null is not rest' => array( array( 'rest_route' => null ), 'other' ),
			'rest non-string'       => array( array( 'rest_route' => 5 ), 'other' ),
			'wc-ajax'               => array( array( 'wc_ajax' => 'get_refreshed_fragments' ), 'wc-ajax:get_refreshed_fragments' ),
			'wc-api'                => array( array( 'wc_api' => 'WC_Gateway_X' ), 'wc-api:wc_gateway_x' ),
			'ajax'                  => array( array( 'ajax_action' => 'heartbeat', 'is_admin' => true ), 'ajax:heartbeat' ),
			'name is sanitised'     => array( array( 'ajax_action' => 'Do <b>It</b>!' ), 'ajax:dobit/b' ),
			'name is truncated'     => array( array( 'ajax_action' => str_repeat( 'a', 60 ) ), 'ajax:' . str_repeat( 'a', 40 ) ),
			'empty name is absent'  => array( array( 'ajax_action' => '!!!' ), 'other' ),
			'non-string name'       => array( array( 'wc_ajax' => array( 'x' ) ), 'other' ),
			'cron'                  => array( array( 'doing_cron' => true ), 'cron' ),
			'xmlrpc'                => array( array( 'xmlrpc' => true ), 'xmlrpc' ),
			'login'                 => array( array( 'pagenow' => 'wp-login.php' ), 'login' ),
			'admin'                 => array( array( 'is_admin' => true, 'pagenow' => 'edit.php' ), 'admin' ),
			'robots'                => array( array( 'is_robots' => true ), 'robots' ),
			'sitemap'               => array( array( 'sitemap' => true ), 'sitemap' ),
			'feed'                  => array( array( 'is_feed' => true ), 'feed' ),
			'404'                   => array( array( 'is_404' => true ), '404' ),
			'search'                => array( array( 'is_search' => true ), 'search' ),
			'product'               => array( array( 'is_product' => true, 'is_singular' => true ), 'product' ),
			'shop'                  => array( array( 'is_shop' => true ), 'shop' ),
			'product_cat'           => array( array( 'is_product_taxonomy' => true ), 'product_cat' ),
			'front page'            => array( array( 'is_front_page' => true, 'is_page' => true ), 'home' ),
			'blog home'             => array( array( 'is_home' => true ), 'home' ),
			'page'                  => array( array( 'is_page' => true, 'is_singular' => true ), 'page' ),
			'post'                  => array( array( 'is_singular' => true ), 'post' ),
			'other'                 => array( array(), 'other' ),
		);
	}

	/**
	 * @dataProvider routes
	 * @param array<string, mixed> $ctx      Context.
	 * @param string               $expected Route.
	 */
	public function test_resolve( array $ctx, string $expected ): void {
		$this->assertSame( $expected, Route::resolve( $ctx ) );
	}

	public function test_rest_beats_404(): void {
		$this->assertSame( 'rest:wc/store', Route::resolve( array( 'rest_route' => '/wc/store/v1/cart', 'is_404' => true ) ) );
	}

	public function test_precedence_order(): void {
		$this->assertSame( 'wc-ajax:x', Route::resolve( array( 'wc_ajax' => 'x', 'wc_api' => 'y', 'ajax_action' => 'z', 'doing_cron' => true ) ) );
		$this->assertSame( 'wc-api:y', Route::resolve( array( 'wc_api' => 'y', 'ajax_action' => 'z' ) ) );
		$this->assertSame( 'cron', Route::resolve( array( 'doing_cron' => true, 'xmlrpc' => true, 'is_admin' => true ) ) );
		$this->assertSame( 'login', Route::resolve( array( 'pagenow' => 'wp-login.php', 'is_admin' => true ) ) );
		$this->assertSame( 'product', Route::resolve( array( 'is_product' => true, 'is_shop' => true, 'is_page' => true ) ) );
		$this->assertSame( 'home', Route::resolve( array( 'is_home' => true, 'is_page' => true ) ) );
	}

	public function test_wc_ajax_post_has_post_reason(): void {
		$ctx = array(
			'wc_ajax'    => 'add_to_cart',
			'method'     => 'POST',
			'page_cache' => true,
		);
		$this->assertSame( 'wc-ajax:add_to_cart', Route::resolve( $ctx ) );
		$this->assertSame( 'post', Route::uncachedReason( $ctx ) );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function reasons(): array {
		return array(
			'post'                    => array( array( 'method' => 'POST', 'logged_in_cookie' => true ), 'post' ),
			'lowercase post'          => array( array( 'method' => 'post' ), 'post' ),
			'put'                     => array( array( 'method' => 'PUT' ), 'post' ),
			'head is get'             => array( array( 'method' => 'head', 'page_cache' => true ), 'cache_miss' ),
			'logged in'               => array( array( 'method' => 'GET', 'logged_in_cookie' => true, 'cart_cookie' => true ), 'logged_in' ),
			'cart cookie'             => array( array( 'method' => 'GET', 'cart_cookie' => true, 'has_query' => true ), 'cart_cookie' ),
			'query string'            => array( array( 'method' => 'GET', 'has_query' => true, 'donotcachepage' => true ), 'query_string' ),
			'donotcachepage'          => array( array( 'method' => 'GET', 'donotcachepage' => true ), 'donotcachepage' ),
			'no page cache'           => array( array( 'method' => 'GET', 'page_cache' => false ), 'no_page_cache' ),
			'missing page cache'      => array( array( 'method' => 'GET' ), 'no_page_cache' ),
			'cache miss'              => array( array( 'method' => 'GET', 'page_cache' => true ), 'cache_miss' ),
			'missing method'          => array( array( 'page_cache' => true ), 'cache_miss' ),
			'non-string method'       => array( array( 'method' => array(), 'page_cache' => true ), 'cache_miss' ),
			'cron route still reason' => array( array( 'doing_cron' => true, 'method' => 'GET', 'page_cache' => true ), 'cache_miss' ),
		);
	}

	/**
	 * @dataProvider reasons
	 * @param array<string, mixed> $ctx      Context.
	 * @param string               $expected Reason.
	 */
	public function test_uncached_reason( array $ctx, string $expected ): void {
		$this->assertSame( $expected, Route::uncachedReason( $ctx ) );
	}

	public function test_add_to_cart_get_on_page_cache_site_is_query_string(): void {
		$ctx = array(
			'method'     => 'GET',
			'has_query'  => true,
			'page_cache' => true,
			'is_shop'    => true,
		);
		$this->assertSame( 'query_string', Route::uncachedReason( $ctx ) );
	}

	public function test_reasons_constant_is_ordered(): void {
		$this->assertSame(
			array( 'post', 'logged_in', 'cart_cookie', 'query_string', 'donotcachepage', 'no_page_cache', 'cache_miss' ),
			Route::REASONS
		);
	}
}
