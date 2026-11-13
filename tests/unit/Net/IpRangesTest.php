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

	/**
	 * Temporary directories made by a test.
	 *
	 * @var string[]
	 */
	private $tmp = array();

	protected function tearDown(): void {
		foreach ( $this->tmp as $dir ) {
			foreach ( (array) glob( $dir . '/*' ) as $file ) {
				unlink( $file );
			}
			rmdir( $dir );
		}
		$this->tmp = array();
		parent::tearDown();
	}

	/**
	 * Make a range directory.
	 *
	 * @param string|null           $fetched Meta date, or null for no `_meta.php`.
	 * @param array<string, string> $lists   List name => single CIDR.
	 * @param string[]|null         $ok      Lists named in the meta (default: all of $lists).
	 */
	private function rangeDir( ?string $fetched, array $lists, ?array $ok = null ): string {
		$dir = sys_get_temp_dir() . '/bi-ranges-' . bin2hex( random_bytes( 4 ) );
		mkdir( $dir );
		$this->tmp[] = $dir;
		foreach ( $lists as $name => $cidr ) {
			file_put_contents( "{$dir}/{$name}.php", '<?php return ' . var_export( array( $cidr ), true ) . ';' );
		}
		if ( null !== $fetched ) {
			$meta = array(
				'fetched' => $fetched,
				'ok'      => array_fill_keys( $ok ?? array_keys( $lists ), 1 ),
				'failed'  => array(),
			);
			file_put_contents( "{$dir}/_meta.php", '<?php return ' . var_export( $meta, true ) . ';' );
		}
		return $dir;
	}

	public function test_newer_override_wins(): void {
		$bundled  = $this->rangeDir( '2026-11-01', array( 'google' => '192.0.2.0/24' ) );
		$override = $this->rangeDir( '2026-11-10', array( 'google' => '198.51.100.0/24' ) );
		$r        = new IpRanges( $bundled, $override );
		$this->assertTrue( $r->contains( '198.51.100.7', 'google' ) );
		$this->assertFalse( $r->contains( '192.0.2.7', 'google' ) );
	}

	public function test_older_or_same_age_override_is_ignored(): void {
		$bundled = $this->rangeDir( '2026-11-10', array( 'google' => '192.0.2.0/24' ) );
		foreach ( array( '2026-11-01', '2026-11-10' ) as $date ) {
			$r = new IpRanges( $bundled, $this->rangeDir( $date, array( 'google' => '198.51.100.0/24' ) ) );
			$this->assertTrue( $r->contains( '192.0.2.7', 'google' ), $date );
			$this->assertFalse( $r->contains( '198.51.100.7', 'google' ), $date );
		}
	}

	public function test_override_without_meta_is_ignored(): void {
		$bundled = $this->rangeDir( '2026-11-01', array( 'google' => '192.0.2.0/24' ) );
		$r       = new IpRanges( $bundled, $this->rangeDir( null, array( 'google' => '198.51.100.0/24' ) ) );
		$this->assertTrue( $r->contains( '192.0.2.7', 'google' ) );
	}

	public function test_list_missing_from_override_falls_back_to_bundled(): void {
		$bundled  = $this->rangeDir( '2026-11-01', array( 'google' => '192.0.2.0/24', 'bing' => '203.0.113.0/24' ) );
		$override = $this->rangeDir( '2026-11-10', array( 'google' => '198.51.100.0/24', 'bing' => '198.51.100.0/24' ), array( 'google' ) );
		$r        = new IpRanges( $bundled, $override );
		$this->assertTrue( $r->contains( '198.51.100.7', 'google' ) );
		// bing.php exists in the override but its fetch failed in that run (not in `ok`): bundled wins.
		$this->assertTrue( $r->contains( '203.0.113.7', 'bing' ) );
		$this->assertFalse( $r->contains( '198.51.100.7', 'bing' ) );
	}

	public function test_missing_override_dir_is_harmless(): void {
		$bundled = $this->rangeDir( '2026-11-01', array( 'google' => '192.0.2.0/24' ) );
		$r       = new IpRanges( $bundled, $bundled . '/nope' );
		$this->assertTrue( $r->contains( '192.0.2.7', 'google' ) );
		$this->assertSame( array( 'google' ), $r->lists() );
	}

	public function test_lists_include_override_only_lists(): void {
		$bundled  = $this->rangeDir( '2026-11-01', array( 'google' => '192.0.2.0/24' ) );
		$override = $this->rangeDir( '2026-11-10', array( 'cloudflare' => '198.51.100.0/24' ) );
		$this->assertSame( array( 'cloudflare', 'google' ), ( new IpRanges( $bundled, $override ) )->lists() );
	}

	public function test_fetched_reads_the_meta_date(): void {
		$this->assertSame( '2026-11-10', IpRanges::fetched( $this->rangeDir( '2026-11-10', array() ) ) );
		$this->assertSame( '', IpRanges::fetched( $this->rangeDir( null, array() ) ) );
		$this->assertSame( '', IpRanges::fetched( $this->rangeDir( 'garbage', array() ) ) );
		$this->assertSame( '', IpRanges::fetched( '/nonexistent/bi' ) );
	}
}
