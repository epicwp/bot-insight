<?php
/**
 * Tests for the robots.txt parser of the daily snapshot.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Tests\Unit\Site;

use BotInsight\Site\Snapshot;
use PHPUnit\Framework\TestCase;

final class RobotsParserTest extends TestCase {

	public function test_field_case_gives_two_groups(): void {
		$txt = "User-agent: *\nDisallow: /*?*\n\nUser-agent: GPTBot\nAllow: /\n";
		$this->assertSame(
			array(
				array(
					'agents'   => array( '*' ),
					'allow'    => array(),
					'disallow' => array( '/*?*' ),
				),
				array(
					'agents'   => array( 'GPTBot' ),
					'allow'    => array( '/' ),
					'disallow' => array(),
				),
			),
			Snapshot::parseRobots( $txt )
		);
	}

	public function test_consecutive_agents_share_a_group_and_fields_are_case_insensitive(): void {
		$txt    = "\xEF\xBB\xBFuser-AGENT: Googlebot\r\nUser-agent: Bingbot # search\r\nDISALLOW: /cart/ # no carts\r\nallow: /cart/help\r\nCrawl-delay: 5\r\nUser-agent: *\r\nDisallow:\r\n";
		$groups = Snapshot::parseRobots( $txt );
		$this->assertCount( 2, $groups );
		$this->assertSame( array( 'Googlebot', 'Bingbot' ), $groups[0]['agents'] );
		$this->assertSame( array( '/cart/' ), $groups[0]['disallow'] );
		$this->assertSame( array( '/cart/help' ), $groups[0]['allow'] );
		$this->assertSame( array( '*' ), $groups[1]['agents'] );
		$this->assertSame( array(), $groups[1]['disallow'] );
	}

	public function test_comments_rules_without_group_and_garbage_are_ignored(): void {
		$txt = "# Robots\nDisallow: /orphan\nnot a field line\nUser-agent: *\n# Disallow: /commented\nDisallow: /wp-admin/\n";
		$this->assertSame(
			array(
				array(
					'agents'   => array( '*' ),
					'allow'    => array(),
					'disallow' => array( '/wp-admin/' ),
				),
			),
			Snapshot::parseRobots( $txt )
		);
	}

	public function test_sitemaps_are_collected_separately(): void {
		$txt = "Sitemap: https://example.com/sitemap_index.xml\nUser-agent: *\nDisallow: /x\nsitemap: https://example.com/other.xml\nAllow: /y\n";
		$this->assertSame( array( 'https://example.com/sitemap_index.xml', 'https://example.com/other.xml' ), Snapshot::robotsSitemaps( $txt ) );
		$groups = Snapshot::parseRobots( $txt );
		$this->assertCount( 1, $groups );
		$this->assertSame( array( '/y' ), $groups[0]['allow'] );
	}

	public function test_normalised_text_ignores_line_endings_whitespace_and_comments(): void {
		$a = "User-agent: *\nDisallow: /x\n";
		$b = "# changed comment\r\nUser-agent: *   \r\n\r\n  Disallow: /x\r\n";
		$this->assertSame( Snapshot::normaliseRobots( $a ), Snapshot::normaliseRobots( $b ) );
		$this->assertNotSame( Snapshot::normaliseRobots( $a ), Snapshot::normaliseRobots( "User-agent: *\nDisallow: /y\n" ) );
	}

	public function test_empty_text_has_no_groups(): void {
		$this->assertSame( array(), Snapshot::parseRobots( '' ) );
	}
}
