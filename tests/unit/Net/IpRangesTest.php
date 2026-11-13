<?php
/**
 * Tests for IP range matching.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Tests\Unit\Net;

use BotInsight\Net\IpRanges;
use PHPUnit\Framework\TestCase;

final class IpRangesTest extends TestCase {

	private function ranges(): IpRanges {
		return new IpRanges( dirname( __DIR__, 2 ) . '/fixtures/ranges' );
	}

	public function test_ipv4_inside_and_outside(): void {
		$this->assertTrue( $this->ranges()->contains( '198.51.100.7', 'test-v4' ) );
		$this->assertFalse( $this->ranges()->contains( '198.51.101.1', 'test-v4' ) );
	}

	public function test_single_host_prefix(): void {
		$this->assertTrue( $this->ranges()->contains( '203.0.113.5', 'test-v4' ) );
		$this->assertFalse( $this->ranges()->contains( '203.0.113.6', 'test-v4' ) );
	}

	public function test_ipv6(): void {
		$this->assertTrue( $this->ranges()->contains( '2001:db8:1::5', 'test-v6' ) );
		$this->assertFalse( $this->ranges()->contains( '2001:db9::1', 'test-v6' ) );
	}

	public function test_family_mismatch_is_false(): void {
		$this->assertFalse( $this->ranges()->contains( '2001:db8::1', 'test-v4' ) );
		$this->assertFalse( $this->ranges()->contains( '198.51.100.7', 'test-v6' ) );
	}

	public function test_invalid_ip_is_false_without_warning(): void {
		$this->assertFalse( $this->ranges()->contains( 'abc', 'test-v4' ) );
		$this->assertFalse( $this->ranges()->contains( '', 'test-v4' ) );
	}

	public function test_unknown_list_is_false(): void {
		$this->assertFalse( $this->ranges()->contains( '198.51.100.7', 'nope' ) );
		$this->assertFalse( $this->ranges()->contains( '198.51.100.7', '../x' ) );
	}

	public function test_lists_excludes_meta_and_is_sorted(): void {
		$this->assertSame( array( 'test-all', 'test-edge', 'test-v4', 'test-v6' ), $this->ranges()->lists() );
	}

	public function test_ipv4_mapped_ipv6_matches_v4_list(): void {
		$this->assertTrue( $this->ranges()->contains( '::ffff:198.51.100.7', 'test-v4' ) );
		$this->assertFalse( $this->ranges()->contains( '::ffff:198.51.101.1', 'test-v4' ) );
		$this->assertFalse( $this->ranges()->contains( '::ffff:198.51.100.7', 'test-v6' ) );
	}

	public function test_prefix_boundaries(): void {
		$r = $this->ranges();
		// /20: 198.51.96.0 - 198.51.111.255.
		$this->assertTrue( $r->contains( '198.51.111.255', 'test-edge' ) );
		$this->assertFalse( $r->contains( '198.51.112.0', 'test-edge' ) );
		$this->assertFalse( $r->contains( '198.51.95.255', 'test-edge' ) );
		// /28 (non-byte boundary): 192.0.2.16 - 192.0.2.31.
		$this->assertTrue( $r->contains( '192.0.2.31', 'test-edge' ) );
		$this->assertFalse( $r->contains( '192.0.2.32', 'test-edge' ) );
		// IPv6 /29 (non-byte boundary) (3fff::/29, RFC 9637 documentation space) covers 3fff:0:: - 3fff:7:ffff:...
		$this->assertTrue( $r->contains( '3fff:7:ffff::1', 'test-edge' ) );
		$this->assertFalse( $r->contains( '3fff:8::1', 'test-edge' ) );
		// /32 and /128 are exact hosts.
		$this->assertTrue( $r->contains( '203.0.113.9', 'test-edge' ) );
		$this->assertFalse( $r->contains( '203.0.113.8', 'test-edge' ) );
		$this->assertTrue( $r->contains( '2001:db8::7', 'test-edge' ) );
		$this->assertFalse( $r->contains( '2001:db8::6', 'test-edge' ) );
		// /0 matches every address of its family only.
		$this->assertTrue( $r->contains( '8.8.8.8', 'test-all' ) );
		$this->assertFalse( $r->contains( '2001:db8::1', 'test-all' ) );
	}

	public function test_malformed_prefixes_are_skipped(): void {
		$r = $this->ranges();
		$this->assertFalse( $r->contains( '10.0.0.5', 'test-edge' ) );
		$this->assertFalse( $r->contains( '10.1.0.5', 'test-edge' ) );
		$this->assertFalse( $r->contains( '10.2.0.5', 'test-edge' ) );
	}
}
