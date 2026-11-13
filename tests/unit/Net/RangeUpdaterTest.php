<?php
/**
 * Tests for parsing and validating vendor range lists.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Tests\Unit\Net;

use BotInsight\Net\RangeUpdater;
use PHPUnit\Framework\TestCase;

final class RangeUpdaterTest extends TestCase {

	public function test_parses_vendor_json(): void {
		$body = '{"creationTime":"x","prefixes":[{"ipv4Prefix":"192.0.2.0/24"},{"ipv6Prefix":"2001:db8::/32"},{"other":"x"}]}';
		$this->assertSame( array( '192.0.2.0/24', '2001:db8::/32' ), RangeUpdater::parse( $body ) );
	}

	public function test_parses_plain_text(): void {
		$this->assertSame( array( '198.51.100.0/24', '2001:db8::/32' ), RangeUpdater::parse( "198.51.100.0/24\n2001:db8::/32\n<html>\n" ) );
	}

	public function test_is_cidr(): void {
		$this->assertTrue( RangeUpdater::isCidr( '192.0.2.0/24' ) );
		$this->assertTrue( RangeUpdater::isCidr( '2001:db8::/128' ) );
		$this->assertFalse( RangeUpdater::isCidr( '192.0.2.0/33' ) );
		$this->assertFalse( RangeUpdater::isCidr( '192.0.2.0' ) );
		$this->assertFalse( RangeUpdater::isCidr( '999.0.2.0/8' ) );
		$this->assertFalse( RangeUpdater::isCidr( '2001:db8::/129' ) );
		$this->assertFalse( RangeUpdater::isCidr( '192.0.2.0/' ) );
	}

	public function test_sets_cover_the_bundled_lists(): void {
		$names = array();
		foreach ( (array) glob( dirname( __DIR__, 3 ) . '/data/ranges/*.php' ) as $file ) {
			$name = basename( (string) $file, '.php' );
			if ( '_' !== $name[0] ) {
				$names[] = $name;
			}
		}
		$sets = array_keys( RangeUpdater::SETS );
		sort( $names );
		sort( $sets );
		$this->assertSame( $names, $sets );
	}
}
