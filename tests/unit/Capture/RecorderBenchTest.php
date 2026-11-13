<?php
/**
 * Hot-path benchmark: build, encode and append one record.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Tests\Unit\Capture;

use BotInsight\Buffer\FileBuffer;
use BotInsight\Capture\Record;
use BotInsight\Capture\Recorder;
use BotInsight\Net\ClientIp;
use BotInsight\Net\IpRanges;
use PHPUnit\Framework\TestCase;

/**
 * @group bench
 */
final class RecorderBenchTest extends TestCase {

	private const RUNS = 1000;

	/**
	 * @var string
	 */
	private $dir;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/bi-bench-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->dir );
	}

	protected function tearDown(): void {
		foreach ( (array) glob( $this->dir . '/*' ) as $file ) {
			unlink( (string) $file );
		}
		rmdir( $this->dir );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function server(): array {
		return array(
			'REQUEST_METHOD'        => 'GET',
			'REQUEST_URI'           => '/en/product-category/shoes/page/3/?orderby=price&utm_source=x',
			'HTTP_HOST'             => 'shop.example.com:443',
			'REMOTE_ADDR'           => '173.245.48.10',
			'HTTP_CF_CONNECTING_IP' => '203.0.113.7',
			'HTTP_CF_IPCOUNTRY'     => 'NL',
			'HTTP_USER_AGENT'       => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
			'HTTP_ACCEPT'           => 'text/html,application/xhtml+xml',
			'HTTP_ACCEPT_LANGUAGE'  => 'nl-NL,nl;q=0.9',
			'HTTP_REFERER'          => 'https://www.google.com/',
			'HTTP_SEC_FETCH_MODE'   => 'navigate',
			'HTTP_SEC_CH_UA'        => '"Chromium";v="130"',
			'REQUEST_TIME_FLOAT'    => 1794564930.1,
		);
	}

	public function test_build_encode_append_is_cheap(): void {
		$client  = new ClientIp( new IpRanges( dirname( __DIR__, 2 ) . '/fixtures/ranges-cdn' ) );
		$buffer  = new FileBuffer( $this->dir );
		$server  = self::server();
		$cookies = array(
			'bi_js'                     => '1',
			'cmplz_marketing'           => 'allow',
			'woocommerce_items_in_cart' => '1',
		);
		$get     = array(
			'orderby'    => 'price',
			'utm_source' => 'x',
		);
		$ctx     = array(
			'is_product_taxonomy' => true,
			'method'              => 'GET',
			'cart_cookie'         => true,
			'has_query'           => true,
			'page_cache'          => true,
			'paged'               => 3,
			'path'                => '/en/product-category/shoes/page/3/',
		);
		$metrics = array(
			'ts'  => 1794564930,
			'ms'  => 210.5,
			'cpu' => 180.25,
			'mem' => 40960,
			'dbq' => 63,
			'st'  => 200,
		);
		$langs   = array(
			'slugs' => array( 'en', 'de' ),
			'keep'  => array( 'shoes' ),
		);

		// Warm up autoloading and the range file.
		$buffer->append( Record::encode( Recorder::buildRecord( $server, $cookies, $get, $ctx, $metrics, $client, $langs ) ), 1794564930 );

		$start = hrtime( true );
		for ( $i = 0; $i < self::RUNS; $i++ ) {
			$record = Recorder::buildRecord( $server, $cookies, $get, $ctx, $metrics, $client, $langs );
			$buffer->append( Record::encode( $record ), 1794564930 );
		}
		$avg_ms = ( hrtime( true ) - $start ) / 1e6 / self::RUNS;

		fwrite( STDERR, sprintf( "\nbuild+encode+append avg: %.4f ms over %d runs\n", $avg_ms, self::RUNS ) );
		$this->assertLessThan( 0.15, $avg_ms );
		$this->assertSame( 'product_cat', $record['r'] );
	}
}
