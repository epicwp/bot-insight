<?php
/**
 * Tests for the bot verifier.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Tests\Unit\Bots;

use BotInsight\Bots\Verifier;
use BotInsight\Net\IpRanges;
use PHPUnit\Framework\TestCase;

final class VerifierTest extends TestCase {

	/** @var string[] */
	private $log = array();

	/** @var array<string,int> */
	private $cache = array();

	/**
	 * Build a verifier with fake lookups.
	 *
	 * @param array<string,string|false> $rdns    IP to host.
	 * @param array<string,string[]>     $fwd     Host to IPs.
	 * @param int                        $budget  DNS budget.
	 * @param array<string,string[]>     $fwd6    Host to IPv6 IPs, or null for no IPv6 support.
	 */
	private function verifier( array $rdns = array(), array $fwd = array(), int $budget = 50, ?array $fwd6 = null ): Verifier {
		$this->log   = array();
		$this->cache = array();
		return new Verifier(
			new IpRanges( dirname( __DIR__, 2 ) . '/fixtures/ranges' ),
			function ( string $ip ) use ( $rdns ) {
				$this->log[] = 'r:' . $ip;
				return $rdns[ $ip ] ?? false;
			},
			function ( string $host ) use ( $fwd ) {
				$this->log[] = 'f:' . $host;
				return $fwd[ $host ] ?? false;
			},
			function ( string $ip ) {
				return $this->cache[ $ip ] ?? null;
			},
			function ( string $ip, int $result ): void {
				$this->cache[ $ip ] = $result;
			},
			$budget,
			null === $fwd6 ? null : function ( string $host ) use ( $fwd6 ) {
				$this->log[] = 'f6:' . $host;
				return $fwd6[ $host ] ?? false;
			}
		);
	}

	private function bot( ?array $ranges, ?array $dns ): array {
		return array( 'verify' => array( 'ranges' => $ranges, 'dns' => $dns ) );
	}

	public function test_no_bot_is_zero(): void {
		$this->assertSame( 0, $this->verifier()->verify( null, '192.0.2.1' ) );
	}

	public function test_no_method_is_three(): void {
		$this->assertSame( 3, $this->verifier()->verify( $this->bot( null, null ), '192.0.2.1' ) );
		$this->assertSame( 3, $this->verifier()->verify( array( 'verify' => null ), '192.0.2.1' ) );
	}

	public function test_range_hit_and_miss(): void {
		$bot = $this->bot( array( 'test-v4' ), null );
		$v   = $this->verifier();
		$this->assertSame( 1, $v->verify( $bot, '198.51.100.7' ) );
		$this->assertSame( 2, $v->verify( $bot, '198.51.101.1' ) );
		$this->assertSame( array(), $this->log );
	}

	public function test_range_miss_falls_back_to_dns(): void {
		$bot = $this->bot( array( 'test-v4' ), array( '.googlebot.com' ) );
		$v   = $this->verifier( array( '192.0.2.5' => 'crawl-1.googlebot.com' ), array( 'crawl-1.googlebot.com' => array( '192.0.2.5' ) ) );
		$this->assertSame( 1, $v->verify( $bot, '192.0.2.5' ) );
	}

	public function test_range_hit_skips_dns(): void {
		$bot = $this->bot( array( 'test-v4' ), array( '.googlebot.com' ) );
		$v   = $this->verifier();
		$this->assertSame( 1, $v->verify( $bot, '198.51.100.7' ) );
		$this->assertSame( array(), $this->log );
	}

	public function test_range_miss_with_dns_and_no_budget_is_three(): void {
		$bot = $this->bot( array( 'test-v4' ), array( '.googlebot.com' ) );
		$this->assertSame( 3, $this->verifier( array(), array(), 0 )->verify( $bot, '192.0.2.5' ) );
	}

	public function test_dns_pass(): void {
		$bot = $this->bot( null, array( '.googlebot.com' ) );
		$v   = $this->verifier( array( '192.0.2.5' => 'Crawl-1.GoogleBot.com.' ), array( 'crawl-1.googlebot.com' => array( '192.0.2.5' ) ) );
		$this->assertSame( 1, $v->verify( $bot, '192.0.2.5' ) );
		$this->assertSame( 1, $this->cache['192.0.2.5'] );
	}

	public function test_suffix_must_match_exactly(): void {
		$bot = $this->bot( null, array( '.googlebot.com' ) );
		foreach ( array( 'crawl.googlebot.com.evil.net', 'evilgooglebot.com' ) as $host ) {
			$v = $this->verifier( array( '192.0.2.5' => $host ), array( $host => array( '192.0.2.5' ) ) );
			$this->assertSame( 2, $v->verify( $bot, '192.0.2.5' ), $host );
		}
	}

	public function test_host_equal_to_bare_suffix_is_accepted(): void {
		$bot = $this->bot( null, array( '.googlebot.com' ) );
		$v   = $this->verifier( array( '192.0.2.5' => 'googlebot.com' ), array( 'googlebot.com' => array( '192.0.2.5' ) ) );
		$this->assertSame( 1, $v->verify( $bot, '192.0.2.5' ) );
	}

	public function test_forward_mismatch_is_two(): void {
		$bot = $this->bot( null, array( '.googlebot.com' ) );
		$v   = $this->verifier( array( '192.0.2.5' => 'a.googlebot.com' ), array( 'a.googlebot.com' => array( '192.0.2.99' ) ) );
		$this->assertSame( 2, $v->verify( $bot, '192.0.2.5' ) );
		$this->assertSame( 2, $this->cache['192.0.2.5'] );
	}

	public function test_failed_reverse_is_two_and_cached(): void {
		$bot = $this->bot( null, array( '.googlebot.com' ) );
		$v   = $this->verifier( array( '192.0.2.5' => false ) );
		$this->assertSame( 2, $v->verify( $bot, '192.0.2.5' ) );
		$this->assertSame( 2, $this->cache['192.0.2.5'] );
		$v = $this->verifier( array( '192.0.2.6' => '192.0.2.6' ) );
		$this->assertSame( 2, $v->verify( $bot, '192.0.2.6' ) );
	}

	public function test_budget_zero_is_three_and_not_cached(): void {
		$bot = $this->bot( null, array( '.googlebot.com' ) );
		$v   = $this->verifier( array(), array(), 0 );
		$this->assertSame( 3, $v->verify( $bot, '192.0.2.5' ) );
		$this->assertSame( array(), $this->cache );
		$this->assertSame( array(), $this->log );
	}

	public function test_budget_is_spent_per_lookup(): void {
		$bot = $this->bot( null, array( '.googlebot.com' ) );
		$v   = $this->verifier( array(), array(), 1 );
		$this->assertSame( 2, $v->verify( $bot, '192.0.2.5' ) );
		$this->assertSame( 3, $v->verify( $bot, '192.0.2.6' ) );
	}

	public function test_cache_hit_skips_lookups(): void {
		$bot = $this->bot( null, array( '.googlebot.com' ) );
		$v   = $this->verifier( array(), array(), 0 );
		$this->cache['192.0.2.5'] = 1;
		$this->assertSame( 1, $v->verify( $bot, '192.0.2.5' ) );
		$this->assertSame( array(), $this->log );
	}

	public function test_ipv6_without_forward6_is_three_and_not_cached(): void {
		$bot = $this->bot( null, array( '.googlebot.com' ) );
		$v   = $this->verifier( array( '2001:db8::1' => 'a.googlebot.com' ) );
		$this->assertSame( 3, $v->verify( $bot, '2001:db8::1' ) );
		$this->assertSame( array(), $this->cache );
	}

	public function test_ipv6_with_forward6(): void {
		$bot = $this->bot( null, array( '.googlebot.com' ) );
		$v   = $this->verifier( array( '2001:db8::1' => 'a.googlebot.com' ), array(), 50, array( 'a.googlebot.com' => array( '2001:db8::1' ) ) );
		$this->assertSame( 1, $v->verify( $bot, '2001:db8::1' ) );
		$this->assertSame( array( 'r:2001:db8::1', 'f6:a.googlebot.com' ), $this->log );
	}

	public function test_ipv6_forms_are_normalised(): void {
		$bot = $this->bot( null, array( '.googlebot.com' ) );
		$v   = $this->verifier( array( '2001:db8::1' => 'a.googlebot.com' ), array(), 50, array( 'a.googlebot.com' => array( '2001:db8::1' ) ) );
		$this->assertSame( 1, $v->verify( $bot, '2001:0DB8:0000:0000:0000:0000:0000:0001' ) );
		$this->assertSame( 1, $this->cache['2001:db8::1'] );
	}

	public function test_forward_results_are_normalised(): void {
		$bot = $this->bot( null, array( '.googlebot.com' ) );
		$v   = $this->verifier( array( '2001:db8::1' => 'a.googlebot.com' ), array(), 50, array( 'a.googlebot.com' => array( '2001:0DB8::0001' ) ) );
		$this->assertSame( 1, $v->verify( $bot, '2001:db8::1' ) );
	}

	public function test_mapped_ipv4_uses_ipv4_forward(): void {
		$bot = $this->bot( null, array( '.googlebot.com' ) );
		$v   = $this->verifier( array( '192.0.2.5' => 'a.googlebot.com' ), array( 'a.googlebot.com' => array( '192.0.2.5' ) ) );
		$this->assertSame( 1, $v->verify( $bot, '::ffff:192.0.2.5' ) );
		$this->assertSame( array( 'r:192.0.2.5', 'f:a.googlebot.com' ), $this->log );
	}

	public function test_ipv6_forward_mismatch_and_false_are_two(): void {
		$bot = $this->bot( null, array( '.googlebot.com' ) );
		$v   = $this->verifier( array( '2001:db8::1' => 'a.googlebot.com' ), array(), 50, array( 'a.googlebot.com' => array( '2001:db8::2' ) ) );
		$this->assertSame( 2, $v->verify( $bot, '2001:db8::1' ) );
		$v = $this->verifier( array( '2001:db8::1' => 'a.googlebot.com' ), array(), 50, array() );
		$this->assertSame( 2, $v->verify( $bot, '2001:db8::1' ) );
	}

	public function test_invalid_ip_is_three(): void {
		$bot = $this->bot( null, array( '.googlebot.com' ) );
		$this->assertSame( 3, $this->verifier()->verify( $bot, 'not-an-ip' ) );
		$this->assertSame( array(), $this->log );
	}

	public function test_suffix_without_leading_dot_is_normalised(): void {
		$bot = $this->bot( null, array( 'googlebot.com' ) );
		$v   = $this->verifier( array( '192.0.2.5' => 'evilgooglebot.com' ), array( 'evilgooglebot.com' => array( '192.0.2.5' ) ) );
		$this->assertSame( 2, $v->verify( $bot, '192.0.2.5' ) );
		$v = $this->verifier( array( '192.0.2.5' => 'a.googlebot.com' ), array( 'a.googlebot.com' => array( '192.0.2.5' ) ) );
		$this->assertSame( 1, $v->verify( $bot, '192.0.2.5' ) );
	}

	public function test_string_cache_values_count_as_hits(): void {
		$bot = $this->bot( null, array( '.googlebot.com' ) );
		$v   = $this->verifier( array(), array(), 0 );
		$this->cache['192.0.2.5'] = '1';
		$this->assertSame( 1, $v->verify( $bot, '192.0.2.5' ) );
	}
}
