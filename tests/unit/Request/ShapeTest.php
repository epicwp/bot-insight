<?php
/**
 * Tests for request shape helpers.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Tests\Unit\Request;

use BotInsight\Request\Shape;
use PHPUnit\Framework\TestCase;

final class ShapeTest extends TestCase {

	public function test_param_names_sorted_lowercased(): void {
		$this->assertSame(
			'add-to-cart,filter_color,orderby',
			Shape::paramNames(
				array(
					'orderby'      => 'x',
					'add-to-cart'  => '1',
					'Filter_Color' => 'r',
				)
			)
		);
	}

	public function test_param_names_empty(): void {
		$this->assertSame( '', Shape::paramNames( array() ) );
	}

	public function test_param_names_cleaning(): void {
		$this->assertSame(
			'#,a[b],abc',
			Shape::paramNames(
				array(
					'A[b]'     => 1,
					5          => 'x',
					0          => 'y',
					'a b!c'    => 1,
					'!!!'      => 1,
					"\xff\x00" => 1,
				)
			)
		);
	}

	public function test_param_names_dedupe(): void {
		$this->assertSame(
			'abc',
			Shape::paramNames(
				array(
					'ABC'  => 1,
					'abc'  => 2,
					'a.bc' => 3,
				)
			)
		);
	}

	public function test_random_names_collapse_to_one_placeholder(): void {
		$this->assertSame(
			'#,orderby',
			Shape::paramNames(
				array(
					'1699999999'                       => 1,
					'42'                               => 1,
					'5f2b9c0d8e7a6b1c'                 => 1,
					'deadbeefcafebabe00'               => 1,
					'q2fvbgxvd29ybgqgzm9vyMFyYmF6'     => 1,
					'ORDERBY'                          => 1,
				)
			)
		);
	}

	public function test_long_names_with_separators_or_without_digits_are_kept(): void {
		$this->assertSame(
			'add-to-cart,filter_product_brand,utm_source_platform,woocommercecheckout',
			Shape::paramNames(
				array(
					'utm_source_platform'  => 1,
					'filter_product_brand' => 1,
					'woocommercecheckout'  => 1,
					'add-to-cart'          => 1,
				)
			)
		);
	}

	public function test_short_alphanumeric_names_are_kept(): void {
		$this->assertSame( 'p2,utm2', Shape::paramNames( array( 'p2' => 1, 'utm2' => 1 ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	public function test_500_params_give_20_names(): void {
		$get = array();
		for ( $i = 0; $i < 500; $i++ ) {
			$get[ 'p' . $i ] = 'v';
		}
		$names = explode( ',', Shape::paramNames( $get ) );
		$this->assertCount( 20, $names );
		$sorted = $names;
		sort( $sorted, SORT_STRING );
		$this->assertSame( $sorted, $names );
	}

	public function test_4kb_name_is_cut_to_40(): void {
		$result = Shape::paramNames( array( str_repeat( 'x', 4096 ) => '1' ) );
		$this->assertSame( str_repeat( 'x', 40 ), $result );
	}

	public function test_binary_param_names_no_warning(): void {
		$this->assertSame( 'ab', Shape::paramNames( array( "a\x00\xffb" => '1' ) ) );
	}

	public function test_page_depth(): void {
		$this->assertSame( '1', Shape::pageDepth( -3 ) );
		$this->assertSame( '1', Shape::pageDepth( 0 ) );
		$this->assertSame( '1', Shape::pageDepth( 1 ) );
		$this->assertSame( '2-5', Shape::pageDepth( 2 ) );
		$this->assertSame( '2-5', Shape::pageDepth( 5 ) );
		$this->assertSame( '6-20', Shape::pageDepth( 6 ) );
		$this->assertSame( '6-20', Shape::pageDepth( 20 ) );
		$this->assertSame( '21+', Shape::pageDepth( 21 ) );
	}

	/**
	 * @dataProvider referers
	 */
	public function test_referer_type( string $referer, string $expected ): void {
		$this->assertSame( $expected, Shape::refererType( $referer, 'Shop.example.com' ) );
	}

	public function referers(): array {
		return array(
			'empty'         => array( '', 'none' ),
			'own'           => array( 'https://shop.example.com/cart/', 'own' ),
			'own www'       => array( 'https://www.shop.example.com/', 'own' ),
			'own subdomain' => array( 'https://m.shop.example.com/', 'own' ),
			'gemini'        => array( 'https://gemini.google.com/app', 'ai' ),
			'chatgpt'       => array( 'https://chatgpt.com/', 'ai' ),
			'openai chat'   => array( 'https://chat.openai.com/c/1', 'ai' ),
			'perplexity'    => array( 'https://www.perplexity.ai/search', 'ai' ),
			'claude'        => array( 'https://claude.ai/chat', 'ai' ),
			'copilot'       => array( 'https://copilot.microsoft.com/', 'ai' ),
			'google nl'     => array( 'https://www.google.nl/', 'search' ),
			'google de'     => array( 'https://www.google.de/search?q=x', 'search' ),
			'google co.uk'  => array( 'https://google.co.uk/', 'search' ),
			'bing'          => array( 'https://www.bing.com/', 'search' ),
			'yahoo'         => array( 'https://nl.search.yahoo.com/', 'search' ),
			'ddg'           => array( 'https://duckduckgo.com/', 'search' ),
			'facebook'      => array( 'https://l.facebook.com/l.php', 'social' ),
			'tco'           => array( 'https://t.co/abc', 'social' ),
			'pinterest'     => array( 'https://nl.pinterest.com/pin/1', 'social' ),
			'youtube'       => array( 'https://m.youtube.com/', 'social' ),
			'notgoogle'     => array( 'https://notgoogle.com/', 'other' ),
			'fakebing'      => array( 'https://notbing.com/', 'other' ),
			'fake x'        => array( 'https://box.com/', 'other' ),
			'other'         => array( 'https://example.org/', 'other' ),
			'no scheme'     => array( 'garbage', 'other' ),
			'unparseable'   => array( 'http:///', 'other' ),
			'uppercase'     => array( 'HTTPS://WWW.GOOGLE.NL/', 'search' ),
			'binary'        => array( "https://\xff\x00/", 'other' ),
		);
	}

	public function test_referer_with_empty_own_host(): void {
		$this->assertSame( 'other', Shape::refererType( 'https://example.org/', '' ) );
	}
}
