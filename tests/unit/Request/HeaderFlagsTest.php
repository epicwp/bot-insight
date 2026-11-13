<?php
/**
 * Tests for request header flags.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Tests\Unit\Request;

use BotInsight\Request\HeaderFlags;
use PHPUnit\Framework\TestCase;

final class HeaderFlagsTest extends TestCase {

	public function test_empty_request(): void {
		$this->assertSame( 0, HeaderFlags::fromRequest( array(), array() ) );
	}

	public function test_header_flags(): void {
		$server = array(
			'HTTP_ACCEPT_LANGUAGE' => 'nl',
			'HTTP_SEC_FETCH_MODE'  => 'navigate',
			'HTTP_SEC_CH_UA'       => '"x"',
			'HTTP_ACCEPT'          => 'text/html,application/xhtml+xml',
		);
		$this->assertSame( 1 | 2 | 4 | 8, HeaderFlags::fromRequest( $server, array() ) );
	}

	public function test_sec_fetch_any_suffix(): void {
		$this->assertSame( 2, HeaderFlags::fromRequest( array( 'HTTP_SEC_FETCH_DEST' => 'document' ), array() ) );
	}

	public function test_accept_without_html(): void {
		$this->assertSame( 0, HeaderFlags::fromRequest( array( 'HTTP_ACCEPT' => '*/*' ), array() ) );
	}

	public function test_any_cookie(): void {
		$this->assertSame( 16, HeaderFlags::fromRequest( array(), array( 'foo' => 'bar' ) ) );
	}

	public function test_js_cookie_requires_value_one(): void {
		$this->assertSame( 16 | 512, HeaderFlags::fromRequest( array(), array( 'bi_js' => '1' ) ) );
		$this->assertSame( 16, HeaderFlags::fromRequest( array(), array( 'bi_js' => '0' ) ) );
	}

	/**
	 * @dataProvider consentCookies
	 */
	public function test_consent_cookies( string $name ): void {
		$this->assertSame( 16 | 32, HeaderFlags::fromRequest( array(), array( $name => 'x' ) ) );
	}

	public function consentCookies(): array {
		return array(
			array( 'cookieyes-consent' ),
			array( 'CookieConsent' ),
			array( 'cmplz_banner-status' ),
			array( 'borlabs-cookie' ),
			array( 'wp_consent_functional' ),
			array( 'moove_gdpr_popup' ),
			array( 'cookielawinfo-checkbox-necessary' ),
		);
	}

	public function test_woo_and_login_cookies(): void {
		$this->assertSame( 16 | 64, HeaderFlags::fromRequest( array(), array( 'wp_woocommerce_session_abc123' => 'x' ) ) );
		$this->assertSame( 16 | 128, HeaderFlags::fromRequest( array(), array( 'woocommerce_items_in_cart' => '1' ) ) );
		$this->assertSame( 16 | 128, HeaderFlags::fromRequest( array(), array( 'woocommerce_cart_hash' => 'h' ) ) );
		$this->assertSame( 16 | 256, HeaderFlags::fromRequest( array(), array( 'wordpress_logged_in_deadbeef' => 'x' ) ) );
	}

	public function test_near_miss_cookie_names(): void {
		$this->assertSame(
			16,
			HeaderFlags::fromRequest(
				array(),
				array(
					'cookieyes'           => 'x',
					'my_cmplz_x'          => 'y',
					'wordpress_logged_in' => 'z',
				)
			)
		);
	}

	public function test_int_cookie_names_do_not_warn(): void {
		$this->assertSame( 16, HeaderFlags::fromRequest( array(), array( 0 => 'x' ) ) );
	}

	public function test_non_string_server_values(): void {
		$this->assertSame( 0, HeaderFlags::fromRequest( array( 'HTTP_ACCEPT' => array( 'text/html' ) ), array() ) );
	}
}
