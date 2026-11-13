<?php
/**
 * Tests for the bundled bot list.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Tests\Unit\Bots;

use BotInsight\Bots\BotList;
use BotInsight\Net\IpRanges;
use PHPUnit\Framework\TestCase;

final class BotListTest extends TestCase {

	private function list(): BotList {
		return BotList::fromFile( dirname( __DIR__, 3 ) . '/data/bots.json' );
	}

	public function test_googlebot(): void {
		$bot = $this->list()->match( 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' );
		$this->assertIsArray( $bot );
		$this->assertSame( 'googlebot', $bot['id'] );
		$this->assertSame( 'search', $bot['class'] );
		$this->assertTrue( $bot['findability'] );
	}

	public function test_ai_classes(): void {
		$this->assertSame( 'ai_training', $this->list()->match( 'GPTBot/1.2' )['class'] );
		$this->assertSame( 'ai_search', $this->list()->match( 'OAI-SearchBot/1.0' )['class'] );
		$this->assertSame( 'ai_assistant', $this->list()->match( 'ChatGPT-User/1.0' )['class'] );
	}

	public function test_browser_is_not_a_bot(): void {
		$this->assertNull( $this->list()->match( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36' ) );
	}

	public function test_case_insensitive(): void {
		$this->assertSame( 'gptbot', $this->list()->match( 'gptbot/1.2' )['id'] );
	}

	public function test_specific_pattern_wins_by_order(): void {
		$this->assertSame( 'googlebot-image', $this->list()->match( 'Googlebot-Image/1.0' )['id'] );
	}

	public function test_get_and_version(): void {
		$this->assertSame( 'Bingbot', $this->list()->get( 'bingbot' )['name'] );
		$this->assertNull( $this->list()->get( 'nope' ) );
		$this->assertMatchesRegularExpression( '/^\d{4}\.\d+\.\d+$/', $this->list()->version() );
	}

	public function test_current_majors(): void {
		$majors = $this->list()->currentMajors();
		foreach ( array( 'chrome', 'edge', 'firefox', 'safari' ) as $browser ) {
			$this->assertGreaterThan( 0, $majors[ $browser ] );
		}
	}

	public function test_entries_are_well_formed_and_ids_unique(): void {
		$raw  = json_decode( (string) file_get_contents( dirname( __DIR__, 3 ) . '/data/bots.json' ), true );
		$ids  = array();
		$list = $this->list();
		foreach ( $raw['bots'] as $entry ) {
			$this->assertNotContains( $entry['id'], $ids );
			$ids[] = $entry['id'];
			$this->assertNotEmpty( $entry['patterns'] );
			$this->assertContains( $entry['class'], array( 'search', 'ai_search', 'ai_assistant', 'ai_training', 'seo', 'social', 'monitoring', 'feed', 'library', 'unknown_bot' ) );
			$this->assertSame( in_array( $entry['class'], array( 'search', 'ai_search', 'ai_assistant' ), true ), $entry['findability'], $entry['id'] );
			$this->assertArrayHasKey( 'ranges', $entry['verify'] );
			$this->assertArrayHasKey( 'dns', $entry['verify'] );
			// Every claimed pattern must match its own entry or a more specific one earlier in the list.
			$this->assertNotNull( $list->match( $entry['patterns'][0] ), $entry['id'] );
		}
	}

	public function test_referenced_range_lists_exist(): void {
		$ranges = new IpRanges( dirname( __DIR__, 3 ) . '/data/ranges' );
		$known  = $ranges->lists();
		$raw    = json_decode( (string) file_get_contents( dirname( __DIR__, 3 ) . '/data/bots.json' ), true );
		foreach ( $raw['bots'] as $entry ) {
			foreach ( (array) $entry['verify']['ranges'] as $name ) {
				$this->assertContains( $name, $known, $entry['id'] );
			}
		}
	}

	public function test_in_app_browsers_and_plugins_are_not_bots(): void {
		$this->assertNull( $this->list()->match( 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 [Pinterest/iOS]' ) );
		$this->assertSame( 'pinterestbot', $this->list()->match( 'Mozilla/5.0 (compatible; Pinterestbot/1.0; +http://www.pinterest.com/bot.html)' )['id'] );
		$this->assertNull( $this->list()->match( 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0 Safari/537.36 Jetpack/13.0' ) );
		$this->assertSame( 'jetpack', $this->list()->match( 'Mozilla/5.0 (compatible; Jetpack by WordPress.com)' )['id'] );
	}

	public function test_other_google_crawlers(): void {
		$this->assertSame( 'search', $this->list()->match( 'AdsBot-Google (+http://www.google.com/adsbot.html)' )['class'] );
		$this->assertSame( 'search', $this->list()->match( 'Mediapartners-Google' )['class'] );
		$this->assertSame( 'feed', $this->list()->match( 'FeedFetcher-Google; (+http://www.google.com/feedfetcher.html)' )['class'] );
	}
}
