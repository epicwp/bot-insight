<?php
/**
 * Tests for the buffer record codec.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Tests\Unit\Capture;

use BotInsight\Capture\Record;
use PHPUnit\Framework\TestCase;

final class RecordTest extends TestCase {

	/**
	 * @return array<string, mixed>
	 */
	public static function sample(): array {
		return array(
			'ts'   => 1794556800,
			'ms'   => 123.45,
			'cpu'  => 98.5,
			'mem'  => 40960,
			'dbq'  => 57,
			'st'   => 200,
			'unc'  => 'no_page_cache',
			'm'    => 'GET',
			'r'    => 'product',
			'p'    => 'orderby,utm_source',
			'dp'   => '1',
			'lg'   => 'en',
			'u'    => '/en/product/*/',
			'ref'  => 'search',
			'ua'   => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
			'ip'   => '203.0.113.7',
			'ips'  => 'remote_addr',
			'cdn'  => 0,
			'cc'   => 'NL',
			'hf'   => 9,
			'js'   => 0,
			'se'   => 0,
			'ca'   => 1,
			'or'   => 0,
			'pc'   => 0,
			'self' => 42,
		);
	}

	public function test_keys_match_the_shared_definition(): void {
		$this->assertSame( array_keys( self::sample() ), Record::KEYS );
		$this->assertSame( Record::KEYS, array_keys( Record::TYPES ) );
	}

	public function test_round_trip(): void {
		$line = Record::encode( self::sample() );
		$this->assertSame( "\n", substr( $line, -1 ) );
		$this->assertSame( 1, substr_count( $line, "\n" ) );
		$this->assertSame( self::sample(), Record::decode( $line ) );
	}

	public function test_round_trip_keeps_float_type_for_whole_numbers(): void {
		$r       = self::sample();
		$r['ms'] = 12.0;
		$this->assertSame( 12.0, Record::decode( Record::encode( $r ) )['ms'] );
	}

	public function test_ua_is_cut_to_256_bytes(): void {
		$r       = self::sample();
		$r['ua'] = str_repeat( 'a', 300 );
		$this->assertSame( str_repeat( 'a', 256 ), Record::decode( Record::encode( $r ) )['ua'] );
	}

	public function test_invalid_utf8_and_newlines_stay_on_one_line(): void {
		$r       = self::sample();
		$r['ua'] = "bad\xff\nua\r" . str_repeat( 'é', 200 );
		$line    = Record::encode( $r );
		$this->assertSame( 1, substr_count( $line, "\n" ) );
		$out = Record::decode( $line );
		$this->assertIsArray( $out );
		$this->assertLessThanOrEqual( 256 + 3, strlen( $out['ua'] ) );
	}

	public function test_missing_keys_get_typed_defaults_and_unknown_keys_are_dropped(): void {
		$out = Record::decode( Record::encode( array( 'ts' => 5, 'extra' => 'x' ) ) );
		$this->assertIsArray( $out );
		$this->assertSame( Record::KEYS, array_keys( $out ) );
		$this->assertSame( 5, $out['ts'] );
		$this->assertSame( 0.0, $out['ms'] );
		$this->assertSame( '', $out['ua'] );
		$this->assertSame( 0, $out['st'] );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function garbage(): array {
		return array(
			'empty'         => array( '' ),
			'header'        => array( "<?php exit; ?>\n" ),
			'truncated'     => array( '{"ts":1,"ms":' ),
			'scalar'        => array( '42' ),
			'list'          => array( '[1,2,3]' ),
			'no ts'         => array( '{"ms":1.5}' ),
			'string ts'     => array( '{"ts":"now"}' ),
			'binary'        => array( "\x00\xff\xfe" ),
		);
	}

	/**
	 * @dataProvider garbage
	 */
	public function test_decode_garbage_gives_null( string $line ): void {
		$this->assertNull( Record::decode( $line ) );
	}
}
