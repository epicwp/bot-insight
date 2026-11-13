<?php
/**
 * Verification of claimed search and AI bots.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Bots;

use BotInsight\Net\IpRanges;

/**
 * Confirms a claimed bot by published IP ranges or reverse plus forward DNS.
 *
 * Runs at fold time only, never during a visitor request. Result codes: 0 not a claimed bot,
 * 1 verified, 2 failed (impostor), 3 not checked. Only 1 and 2 from DNS are cached.
 */
final class Verifier {

	/**
	 * Range matcher.
	 *
	 * @var IpRanges
	 */
	private $ranges;

	/**
	 * Reverse lookup: IP to host, or false.
	 *
	 * @var callable
	 */
	private $reverse;

	/**
	 * Forward lookup (IPv4): host to IP list, or false.
	 *
	 * @var callable
	 */
	private $forward;

	/**
	 * Cache read: IP to result or null.
	 *
	 * @var callable
	 */
	private $cache_get;

	/**
	 * Cache write.
	 *
	 * @var callable
	 */
	private $cache_set;

	/**
	 * Forward lookup for IPv6, if available.
	 *
	 * @var callable|null
	 */
	private $forward6;

	/**
	 * Remaining DNS lookups for this run.
	 *
	 * @var int
	 */
	private $budget;

	/**
	 * Constructor.
	 *
	 * @param IpRanges      $ranges    Range matcher.
	 * @param callable      $reverse   fn(string $ip): string|false.
	 * @param callable      $forward   fn(string $host): string[]|false.
	 * @param callable      $cache_get fn(string $ip): ?int.
	 * @param callable      $cache_set fn(string $ip, int $result): void.
	 * @param int           $dns_budget Reverse lookups allowed in this run.
	 * @param callable|null $forward6  fn(string $host): string[]|false for IPv6.
	 */
	public function __construct( IpRanges $ranges, callable $reverse, callable $forward, callable $cache_get, callable $cache_set, int $dns_budget = 50, ?callable $forward6 = null ) {
		$this->ranges    = $ranges;
		$this->reverse   = $reverse;
		$this->forward   = $forward;
		$this->cache_get = $cache_get;
		$this->cache_set = $cache_set;
		$this->budget    = $dns_budget;
		$this->forward6  = $forward6;
	}

	/**
	 * Verify a client.
	 *
	 * @param array<string,mixed>|null $bot Bot entry from BotList, or null.
	 * @param string                   $ip  Client IP.
	 * @return int 0, 1, 2 or 3.
	 */
	public function verify( ?array $bot, string $ip ): int {
		if ( null === $bot ) {
			return 0;
		}
		$verify = isset( $bot['verify'] ) && is_array( $bot['verify'] ) ? $bot['verify'] : array();
		$ranges = ! empty( $verify['ranges'] ) && is_array( $verify['ranges'] ) ? $verify['ranges'] : array();
		$dns    = ! empty( $verify['dns'] ) && is_array( $verify['dns'] ) ? $verify['dns'] : array();

		if ( array() === $ranges && array() === $dns ) {
			return 3;
		}
		foreach ( $ranges as $list ) {
			if ( $this->ranges->contains( $ip, (string) $list ) ) {
				return 1;
			}
		}
		if ( array() === $dns ) {
			return 2;
		}
		return $this->verify_dns( $ip, $dns );
	}

	/**
	 * Reverse plus forward DNS check.
	 *
	 * @param string   $ip       Client IP.
	 * @param string[] $suffixes Accepted host suffixes, with leading dot.
	 * @return int
	 */
	private function verify_dns( string $ip, array $suffixes ): int {
		$cached = ( $this->cache_get )( $ip );
		if ( 1 === $cached || 2 === $cached ) {
			return $cached;
		}
		$is_v6 = false !== strpos( $ip, ':' );
		if ( $is_v6 && null === $this->forward6 ) {
			return 3;
		}
		if ( $this->budget <= 0 ) {
			return 3;
		}
		--$this->budget;

		$result = $this->lookup( $ip, $suffixes, $is_v6 ) ? 1 : 2;
		( $this->cache_set )( $ip, $result );
		return $result;
	}

	/**
	 * Whether reverse and forward DNS agree and the host is in an accepted domain.
	 *
	 * @param string   $ip       Client IP.
	 * @param string[] $suffixes Accepted suffixes.
	 * @param bool     $is_v6    Whether the IP is IPv6.
	 * @return bool
	 */
	private function lookup( string $ip, array $suffixes, bool $is_v6 ): bool {
		$host = ( $this->reverse )( $ip );
		if ( ! is_string( $host ) || '' === $host || $host === $ip ) {
			return false;
		}
		$host = rtrim( strtolower( $host ), '.' );
		if ( ! $this->suffix_matches( $host, $suffixes ) ) {
			return false;
		}
		$forward = $is_v6 ? $this->forward6 : $this->forward;
		$addrs   = $forward( $host );
		return is_array( $addrs ) && in_array( $ip, $addrs, true );
	}

	/**
	 * Exact suffix match on a lowercased host.
	 *
	 * @param string   $host     Lowercase host without trailing dot.
	 * @param string[] $suffixes Suffixes with leading dot.
	 * @return bool
	 */
	private function suffix_matches( string $host, array $suffixes ): bool {
		foreach ( $suffixes as $suffix ) {
			$suffix = strtolower( (string) $suffix );
			$bare   = ltrim( $suffix, '.' );
			if ( $host === $bare ) {
				return true;
			}
			$len = strlen( $suffix );
			if ( $len > 0 && strlen( $host ) > $len && substr( $host, -$len ) === $suffix ) {
				return true;
			}
		}
		return false;
	}
}
