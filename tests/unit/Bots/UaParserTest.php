<?php
/**
 * Tests for the user agent parser.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Tests\Unit\Bots;

use BotInsight\Bots\BotList;
use BotInsight\Bots\UaParser;
use PHPUnit\Framework\TestCase;

final class UaParserTest extends TestCase {

	private function parser( ?array $majors = null ): UaParser {
		$majors = $majors ?? array(
			'chrome'  => 140,
			'edge'    => 140,
			'firefox' => 140,
			'safari'  => 26,
		);
		return new UaParser( BotList::fromFile( dirname( __DIR__, 3 ) . '/data/bots.json' ), $majors );
	}

	private function chrome( int $major ): string {
		return "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/{$major}.0.0.0 Safari/537.36";
	}

	public function test_bot_wins(): void {
		$r = $this->parser()->parse( 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' );
		$this->assertSame( 'bot', $r['kind'] );
		$this->assertSame( 'Googlebot', $r['family'] );
		$this->assertSame( 'googlebot', $r['bot']['id'] );
		$this->assertSame( 0, $r['outdated'] );
	}

	public function test_empty(): void {
		$r = $this->parser()->parse( '' );
		$this->assertSame( 'empty', $r['kind'] );
		$this->assertSame( 'empty', $r['family'] );
		$this->assertNull( $r['bot'] );
		$this->assertSame( 0, $r['outdated'] );
	}

	/**
	 * @dataProvider libraries
	 */
	public function test_libraries( string $ua, string $family ): void {
		$r = $this->parser()->parse( $ua );
		$this->assertSame( 'library', $r['kind'] );
		$this->assertSame( $family, $r['family'] );
		$this->assertNull( $r['bot'] );
		$this->assertSame( 0, $r['outdated'] );
	}

	public function libraries(): array {
		return array(
			array( 'curl/8.4.0', 'curl' ),
			array( 'Wget/1.21', 'Wget' ),
			array( 'python-requests/2.31.0', 'python-requests' ),
			array( 'Python-urllib/3.11', 'Python-urllib' ),
			array( 'Go-http-client/2.0', 'Go-http-client' ),
			array( 'GuzzleHttp/7', 'GuzzleHttp' ),
			array( 'okhttp/4.12.0', 'okhttp' ),
			array( 'WordPress/6.5; https://example.com', 'WordPress' ),
			array( 'CURL/8.0', 'curl' ),
		);
	}

	public function test_headless_chrome_is_library(): void {
		$r = $this->parser()->parse( 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/120.0.0.0 Safari/537.36' );
		$this->assertSame( 'library', $r['kind'] );
		$this->assertSame( 'HeadlessChrome', $r['family'] );
	}

	public function test_chrome_boundaries(): void {
		$r = $this->parser()->parse( $this->chrome( 128 ) );
		$this->assertSame( 'browser', $r['kind'] );
		$this->assertSame( 'Chrome 128', $r['family'] );
		$this->assertSame( 0, $r['outdated'] );
		$this->assertSame( 1, $this->parser()->parse( $this->chrome( 127 ) )['outdated'] );
		$this->assertSame( 0, $this->parser()->parse( $this->chrome( 140 ) )['outdated'] );
	}

	public function test_edge(): void {
		$ua = $this->chrome( 120 ) . ' Edg/120.0.0.0';
		$r  = $this->parser()->parse( $ua );
		$this->assertSame( 'Edge 120', $r['family'] );
		$this->assertSame( 1, $r['outdated'] );
		$this->assertSame( 0, $this->parser()->parse( $this->chrome( 130 ) . ' Edg/130.0.0.0' )['outdated'] );
		$this->assertSame( 'Edge 140', $this->parser()->parse( 'Mozilla/5.0 (Linux; Android 10) Chrome/140.0.0.0 Mobile Safari/537.36 EdgA/140.0.0.0' )['family'] );
		$this->assertSame( 'Edge 140', $this->parser()->parse( 'Mozilla/5.0 (iPhone) Version/16.0 EdgiOS/140.0.0.0 Mobile Safari/605.1.15' )['family'] );
	}

	public function test_opera_uses_chrome_major_for_outdated(): void {
		$r = $this->parser()->parse( $this->chrome( 140 ) . ' OPR/90.0.0.0' );
		$this->assertSame( 'Opera 90', $r['family'] );
		$this->assertSame( 0, $r['outdated'] );
		$this->assertSame( 1, $this->parser()->parse( $this->chrome( 120 ) . ' OPR/106.0.0.0' )['outdated'] );
	}

	public function test_opera_without_chrome_token_not_outdated(): void {
		$r = $this->parser()->parse( 'Opera/9.80 OPR/20.0' );
		$this->assertSame( 'Opera 20', $r['family'] );
		$this->assertSame( 0, $r['outdated'] );
	}

	public function test_firefox(): void {
		$r = $this->parser()->parse( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:128.0) Gecko/20100101 Firefox/128.0' );
		$this->assertSame( 'Firefox 128', $r['family'] );
		$this->assertSame( 0, $r['outdated'] );
		$this->assertSame( 1, $this->parser()->parse( 'Mozilla/5.0 (X11; Linux x86_64; rv:100.0) Gecko/20100101 Firefox/127.0' )['outdated'] );
		$this->assertSame( 'Firefox 140', $this->parser()->parse( 'Mozilla/5.0 (iPhone) AppleWebKit/605.1.15 FxiOS/140.0 Mobile/15E148 Safari/605.1.15' )['family'] );
	}

	public function test_ios_chrome(): void {
		$r = $this->parser()->parse( 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/120.0.6099.119 Mobile/15E148 Safari/604.1' );
		$this->assertSame( 'Chrome 120', $r['family'] );
		$this->assertSame( 'browser', $r['kind'] );
		$this->assertSame( 1, $r['outdated'] );
	}

	public function test_safari(): void {
		$ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/%d.0 Safari/605.1.15';
		$r  = $this->parser()->parse( sprintf( $ua, 24 ) );
		$this->assertSame( 'Safari 24', $r['family'] );
		$this->assertSame( 0, $r['outdated'] );
		$this->assertSame( 1, $this->parser()->parse( sprintf( $ua, 23 ) )['outdated'] );
	}

	public function test_safari_without_version_is_other(): void {
		$r = $this->parser()->parse( 'Mozilla/5.0 (Macintosh) AppleWebKit/605.1.15 (KHTML, like Gecko) Safari/605.1.15' );
		$this->assertSame( 'other', $r['kind'] );
		$this->assertSame( 'other', $r['family'] );
	}

	public function test_missing_major_never_outdated(): void {
		$p = $this->parser( array( 'safari' => 26 ) );
		$this->assertSame( 0, $p->parse( $this->chrome( 50 ) )['outdated'] );
		$this->assertSame( 0, $p->parse( 'Mozilla/5.0 Firefox/3.0' )['outdated'] );
	}

	/**
	 * @dataProvider webviews
	 */
	public function test_ios_webviews_are_browsers( string $ua ): void {
		$r = $this->parser()->parse( $ua );
		$this->assertSame( 'browser', $r['kind'] );
		$this->assertSame( 'iOS WebView', $r['family'] );
		$this->assertSame( 0, $r['outdated'] );
	}

	public function webviews(): array {
		return array(
			'instagram' => array( 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/21E219 Instagram 330.0.0.30.99 (iPhone14,5; iOS 17_4; en_US; en; scale=3.00; 1170x2532; 551534321)' ),
			'fban'      => array( 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/21E219 [FBAN/FBIOS;FBAV/450.0.0.38.108;FBBV/565678;FBDV/iPhone14,5;FBMD/iPhone;FBSN/iOS;FBSV/17.4;FBLC/en_US;FBOP/5]' ),
			'linkedin'  => array( 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 LinkedInApp/9.30.1234' ),
		);
	}

	/**
	 * @dataProvider chromium_browsers
	 */
	public function test_chromium_variants_are_browsers( string $ua ): void {
		$this->assertSame( 'browser', $this->parser()->parse( $ua )['kind'] );
	}

	public function chromium_browsers(): array {
		return array(
			'samsung' => array( 'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/24.0 Chrome/117.0.0.0 Mobile Safari/537.36' ),
			'yandex'  => array( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 YaBrowser/24.7.0.0 Safari/537.36' ),
			'fb_iab'  => array( 'Mozilla/5.0 (Linux; Android 13; Pixel 7 Build/TQ3A.230901.001; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/124.0.6367.82 Mobile Safari/537.36 [FB_IAB/FB4A;FBAV/450.0.0.38.108;]' ),
		);
	}

	public function test_java_needs_slash(): void {
		$this->assertSame( 'Java', $this->parser()->parse( 'Java/1.8.0_292' )['family'] );
		$this->assertSame( 'other', $this->parser()->parse( 'JavaFXApp 1.0' )['kind'] );
	}

	public function test_other(): void {
		$r = $this->parser()->parse( 'SomethingOdd/1.0' );
		$this->assertSame( array( 'family' => 'other', 'outdated' => 0, 'bot' => null, 'kind' => 'other' ), $r );
	}

	public function test_truncates_before_matching(): void {
		$ua = str_repeat( 'a', 600 ) . ' curl/8';
		$this->assertSame( 'other', $this->parser()->parse( $ua )['kind'] );
	}

	public function test_long_ua_is_fast_and_quiet(): void {
		$parser = $this->parser();
		$ua     = str_repeat( 'Mozilla/5.0 (X11) ', 500 );
		$this->assertGreaterThan( 8000, strlen( $ua ) );
		$parser->parse( 'warmup' );
		$warnings = 0;
		set_error_handler(
			static function () use ( &$warnings ) {
				++$warnings;
				return true;
			}
		);
		$start = microtime( true );
		$r     = $parser->parse( $ua );
		$ms    = ( microtime( true ) - $start ) * 1000;
		restore_error_handler();
		$this->assertSame( 'other', $r['kind'] );
		$this->assertSame( 0, $warnings );
		$this->assertLessThan( 5, $ms );
	}
}
