<?php
/**
 * Tests for URL pattern collapsing.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Tests\Unit\Request;

use BotInsight\Request\UrlPattern;
use PHPUnit\Framework\TestCase;

final class UrlPatternTest extends TestCase {

	public function test_product_path(): void {
		$this->assertSame( '/en/product/*/', UrlPattern::collapse( '/en/product/blue-shirt/', array( 'en' ) ) );
	}

	public function test_category_pagination(): void {
		$this->assertSame(
			'/de_at/category/*/page/*/',
			UrlPattern::collapse( '/de_at/category/shoes/page/7/', array( 'de_at' ) )
		);
	}

	public function test_date_path_merges_stars(): void {
		$this->assertSame( '/*/', UrlPattern::collapse( '/2024/05/hello/', array() ) );
	}

	public function test_root_and_empty(): void {
		$this->assertSame( '/', UrlPattern::collapse( '/', array() ) );
		$this->assertSame( '/', UrlPattern::collapse( '', array() ) );
		$this->assertSame( '/', UrlPattern::collapse( '//?x=1', array() ) );
	}

	public function test_query_and_fragment_are_stripped(): void {
		$this->assertSame( '/shop/', UrlPattern::collapse( '/shop/?orderby=price#top', array() ) );
		$this->assertSame( '/shop/', UrlPattern::collapse( '/shop#a?b', array() ) );
	}

	public function test_case_is_lowered_and_custom_keep_works(): void {
		$this->assertSame( '/winkel/*/', UrlPattern::collapse( '/Winkel/Jas/', array( 'winkel' ) ) );
		$this->assertSame( '/shop/', UrlPattern::collapse( '/SHOP/', array() ) );
	}

	public function test_default_keep_constant(): void {
		$this->assertContains( 'my-account', UrlPattern::DEFAULT_KEEP );
		$this->assertCount( 15, UrlPattern::DEFAULT_KEEP );
	}

	public function test_segment_cap(): void {
		$this->assertSame(
			'/shop/cart/shop/cart/shop/cart/…',
			UrlPattern::collapse( '/shop/cart/shop/cart/shop/cart/shop/cart/', array() )
		);
		$this->assertSame( '/shop/cart/shop/cart/shop/cart/', UrlPattern::collapse( '/shop/cart/shop/cart/shop/cart/', array() ) );
	}

	public function test_binary_bytes_are_removed_without_warning(): void {
		$path = "/shop/\x00\xff\x80\x1f/ab\xc3\xa9c/" . '%00%ff%0a' . '/x';
		$this->assertSame( '/shop/*/', UrlPattern::collapse( $path, array() ) );
		$this->assertSame( '/shop/', UrlPattern::collapse( "/sh\x00op/\xff/", array() ) );
	}

	public function test_long_input_is_cut(): void {
		$path = '/shop/' . str_repeat( 'a', 5000 );
		$this->assertSame( '/shop/*/', UrlPattern::collapse( $path, array() ) );
		// The query marker beyond the cut is never seen.
		$this->assertSame( '/*/', UrlPattern::collapse( str_repeat( 'b', 2100 ) . '?x', array() ) );
	}

	public function test_lang(): void {
		$slugs = array( 'en', 'de_at' );
		$this->assertSame( 'en', UrlPattern::lang( '/en/product/x/', $slugs ) );
		$this->assertSame( 'de_at', UrlPattern::lang( '/DE_AT/', $slugs ) );
		$this->assertSame( 'en', UrlPattern::lang( '//en?x=1', $slugs ) );
		$this->assertSame( '', UrlPattern::lang( '/product/en/', $slugs ) );
		$this->assertSame( '', UrlPattern::lang( '/', $slugs ) );
		$this->assertSame( '', UrlPattern::lang( "/\xff", $slugs ) );
	}
}
