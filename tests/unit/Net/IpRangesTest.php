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
		$this->assertSame( array( 'test-v4', 'test-v6' ), $this->ranges()->lists() );
	}
}
