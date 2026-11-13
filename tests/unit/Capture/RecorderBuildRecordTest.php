<?php
/**
 * Tests for the pure record builder.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Tests\Unit\Capture;

use BotInsight\Capture\Record;
use BotInsight\Capture\Recorder;
use BotInsight\Net\ClientIp;
use BotInsight\Net\IpRanges;
use BotInsight\Request\HeaderFlags;
use PHPUnit\Framework\TestCase;

final class RecorderBuildRecordTest extends TestCase {

	/**
	 * @param array<string, mixed>     $server  Server.
	 * @param array<int|string, mixed> $cookies Cookies.
	 * @param array<int|string, mixed> $get     Query.
	 * @param array<string, mixed>     $ctx     Context.
	 * @param array<string, mixed>     $metrics Metrics.
	 * @return array<string, mixed>
	 */
	private function build( array $server, array $cookies = array(), array $get = array(), array $ctx = array(), array $metrics = array() ): array {
		$client = new ClientIp( new IpRanges( dirname( __DIR__, 2 ) . '/fixtures/ranges-cdn' ) );
		return Recorder::buildRecord(
			$server,
			$cookies,
			$get,
			$ctx,
			$metrics,
			$client,
			array(
				'slugs' => array( 'en' ),
				'keep'  => array( 'shoes' ),
			)
		);
	}

	public function test_full_record(): void {
		$r = $this->build(
			array(
				'REQUEST_METHOD'        => 'get',
				'REQUEST_URI'           => '/ignored/',
				'HTTP_HOST'             => 'Shop.Example.com:8443',
				'REMOTE_ADDR'           => '173.245.48.10',
				'HTTP_CF_CONNECTING_IP' => '203.0.113.7',
				'HTTP_CF_IPCOUNTRY'     => 'NL',
				'HTTP_USER_AGENT'       => str_repeat( 'x', 300 ),
				'HTTP_REFERER'          => 'https://shop.example.com/en/',
			),
			array( 'bi_js' => '1' ),
			array( 'orderby' => 'price' ),
			array(
				'is_product_taxonomy' => true,
				'page_cache'          => true,
				'has_query'           => true,
				'paged'               => 3,
				'path'                => '/en/product-category/shoes/page/3/',
			),
			array(
				'ts'  => 1794564930,
				'ms'  => 12.34567,
				'cpu' => 8,
				'mem' => 2048,
				'dbq' => 40,
				'st'  => 404,
				'ca'  => 1,
				'or'  => true,
			)
		);

		$this->assertSame( Record::KEYS, array_keys( $r ) );
		$this->assertSame( 1794564930, $r['ts'] );
		$this->assertSame( 12.346, $r['ms'] );
		$this->assertSame( 8.0, $r['cpu'] );
		$this->assertSame( 404, $r['st'] );
		$this->assertSame( 'query_string', $r['unc'] );
		$this->assertSame( 'GET', $r['m'] );
		$this->assertSame( 'product_cat', $r['r'] );
		$this->assertSame( 'orderby', $r['p'] );
		$this->assertSame( '2-5', $r['dp'] );
		$this->assertSame( 'en', $r['lg'] );
		$this->assertSame( '/en/product-category/shoes/page/*/', $r['u'] );
		$this->assertSame( 'own', $r['ref'] );
		$this->assertSame( 256, strlen( $r['ua'] ) );
		$this->assertSame( '203.0.113.7', $r['ip'] );
		$this->assertSame( 'cf_connecting_ip', $r['ips'] );
		$this->assertSame( 1, $r['cdn'] );
		$this->assertSame( 'NL', $r['cc'] );
		$this->assertSame( HeaderFlags::ANY_COOKIE | HeaderFlags::JS_COOKIE, $r['hf'] );
		$this->assertSame( 1, $r['js'] );
		$this->assertSame( array( 0, 1, 1, 0 ), array( $r['se'], $r['ca'], $r['or'], $r['pc'] ) );
		$this->assertSame( 0, $r['self'] );
	}

	public function test_defaults_and_cleaning(): void {
		$r = $this->build(
			array(
				'REQUEST_METHOD'    => 'PROPFIND',
				'REQUEST_URI'       => '/some/thing/?a=1',
				'HTTP_CF_IPCOUNTRY' => 'xx',
				'REMOTE_ADDR'       => '198.51.100.4',
			)
		);
		$this->assertSame( 'OTHER', $r['m'] );
		$this->assertSame( '', $r['cc'] );
		$this->assertSame( '/*/', $r['u'] );
		$this->assertSame( '', $r['lg'] );
		$this->assertSame( '1', $r['dp'] );
		$this->assertSame( 'none', $r['ref'] );
		$this->assertSame( 'other', $r['r'] );
		$this->assertSame( 'no_page_cache', $r['unc'] );
		$this->assertSame( 200, $r['st'] );
		$this->assertSame( 0, $r['js'] );
		$this->assertSame( 'remote_addr', $r['ips'] );
	}

	public function test_missing_method_counts_as_get(): void {
		$this->assertSame( 'GET', $this->build( array() )['m'] );
	}
}
