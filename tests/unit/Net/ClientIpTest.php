<?php
/**
 * Tests for client IP resolution.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Tests\Unit\Net;

use BotInsight\Net\ClientIp;
use BotInsight\Net\IpRanges;
use PHPUnit\Framework\TestCase;

final class ClientIpTest extends TestCase {

	private function resolve( array $server ): array {
		$resolver = new ClientIp( new IpRanges( dirname( __DIR__, 2 ) . '/fixtures/ranges-cdn' ) );
		return $resolver->resolve( $server );
	}

	public function test_plain_remote_addr(): void {
		$this->assertSame(
			array(
				'ip'      => '203.0.113.7',
				'source'  => 'remote_addr',
				'via_cdn' => 0,
			),
			$this->resolve( array( 'REMOTE_ADDR' => '203.0.113.7' ) )
		);
	}

	public function test_public_remote_addr_ignores_xff_and_cf_headers(): void {
		$r = $this->resolve(
			array(
				'REMOTE_ADDR'           => '203.0.113.7',
				'HTTP_X_FORWARDED_FOR'  => '192.0.2.1',
				'HTTP_CF_CONNECTING_IP' => '192.0.2.2',
			)
		);
		$this->assertSame(
			array(
				'ip'      => '203.0.113.7',
				'source'  => 'remote_addr',
				'via_cdn' => 0,
			),
			$r
		);
	}

	public function test_direct_cloudflare_remote_addr(): void {
		$r = $this->resolve(
			array(
				'REMOTE_ADDR'           => '173.245.48.10',
				'HTTP_CF_CONNECTING_IP' => '192.0.2.1',
			)
		);
		$this->assertSame(
			array(
				'ip'      => '192.0.2.1',
				'source'  => 'cf_connecting_ip',
				'via_cdn' => 1,
			),
			$r
		);
	}

	public function test_cloudflare_behind_ingress(): void {
		$r = $this->resolve(
			array(
				'REMOTE_ADDR'           => '10.0.0.5',
				'HTTP_X_FORWARDED_FOR'  => '192.0.2.1, 173.245.48.10',
				'HTTP_CF_CONNECTING_IP' => '192.0.2.1',
			)
		);
		$this->assertSame(
			array(
				'ip'      => '192.0.2.1',
				'source'  => 'cf_connecting_ip',
				'via_cdn' => 1,
			),
			$r
		);
	}

	public function test_forged_cf_header_from_non_cloudflare_is_ignored(): void {
		$r = $this->resolve(
			array(
				'REMOTE_ADDR'           => '10.0.0.5',
				'HTTP_X_FORWARDED_FOR'  => '198.51.100.9',
				'HTTP_CF_CONNECTING_IP' => '192.0.2.1',
			)
		);
		$this->assertSame(
			array(
				'ip'      => '198.51.100.9',
				'source'  => 'x_forwarded_for',
				'via_cdn' => 0,
			),
			$r
		);
	}

	public function test_forged_cf_header_on_public_remote_addr_is_ignored(): void {
		$r = $this->resolve(
			array(
				'REMOTE_ADDR'           => '198.51.100.9',
				'HTTP_CF_CONNECTING_IP' => '192.0.2.1',
			)
		);
		$this->assertSame(
			array(
				'ip'      => '198.51.100.9',
				'source'  => 'remote_addr',
				'via_cdn' => 0,
			),
			$r
		);
	}

	public function test_xff_uses_rightmost_non_private_entry(): void {
		$r = $this->resolve(
			array(
				'REMOTE_ADDR'          => '10.0.0.5',
				'HTTP_X_FORWARDED_FOR' => '192.0.2.50, 198.51.100.9, 10.0.0.7',
			)
		);
		$this->assertSame( '198.51.100.9', $r['ip'] );
		$this->assertSame( 'x_forwarded_for', $r['source'] );
	}

	public function test_header_only_cdn_sets_via_cdn(): void {
		$base = array(
			'REMOTE_ADDR'          => '192.168.1.2',
			'HTTP_X_FORWARDED_FOR' => '198.51.100.9',
		);
		$this->assertSame( 1, $this->resolve( $base + array( 'HTTP_FASTLY_CLIENT_IP' => '192.0.2.1' ) )['via_cdn'] );
		$this->assertSame( 1, $this->resolve( $base + array( 'HTTP_X_SUCURI_CLIENTIP' => '192.0.2.1' ) )['via_cdn'] );
		$this->assertSame( 0, $this->resolve( $base )['via_cdn'] );
	}

	public function test_private_ranges_are_detected(): void {
		foreach ( array( '10.1.2.3', '172.16.0.1', '172.31.255.1', '192.168.0.1', '127.0.0.1', '169.254.1.1', '::1', 'fc00::1', 'fd12::1', 'fe80::1' ) as $private ) {
			$r = $this->resolve(
				array(
					'REMOTE_ADDR'          => $private,
					'HTTP_X_FORWARDED_FOR' => '198.51.100.9',
				)
			);
			$this->assertSame( 'x_forwarded_for', $r['source'], $private );
		}
		foreach ( array( '172.15.0.1', '172.32.0.1', '2001:db8::1' ) as $public ) {
			$r = $this->resolve(
				array(
					'REMOTE_ADDR'          => $public,
					'HTTP_X_FORWARDED_FOR' => '198.51.100.9',
				)
			);
			$this->assertSame( 'remote_addr', $r['source'], $public );
		}
	}

	public function test_private_without_xff_falls_back_to_remote_addr(): void {
		$this->assertSame(
			array(
				'ip'      => '10.0.0.5',
				'source'  => 'remote_addr',
				'via_cdn' => 0,
			),
			$this->resolve( array( 'REMOTE_ADDR' => '10.0.0.5' ) )
		);
	}

	public function test_invalid_entries_are_ignored(): void {
		$r = $this->resolve(
			array(
				'REMOTE_ADDR'          => '10.0.0.5',
				'HTTP_X_FORWARDED_FOR' => '198.51.100.9, garbage, , 999.1.1.1',
			)
		);
		$this->assertSame( '198.51.100.9', $r['ip'] );
	}

	public function test_all_private_or_invalid_xff_falls_back_to_remote_addr(): void {
		$r = $this->resolve(
			array(
				'REMOTE_ADDR'          => '10.0.0.5',
				'HTTP_X_FORWARDED_FOR' => 'nope, 10.0.0.9',
			)
		);
		$this->assertSame(
			array(
				'ip'      => '10.0.0.5',
				'source'  => 'remote_addr',
				'via_cdn' => 0,
			),
			$r
		);
	}

	public function test_invalid_cf_header_falls_back_to_remote_addr(): void {
		$r = $this->resolve(
			array(
				'REMOTE_ADDR'           => '173.245.48.10',
				'HTTP_CF_CONNECTING_IP' => 'bogus',
			)
		);
		$this->assertSame(
			array(
				'ip'      => '173.245.48.10',
				'source'  => 'remote_addr',
				'via_cdn' => 0,
			),
			$r
		);
	}

	public function test_missing_or_invalid_remote_addr_gives_empty_ip(): void {
		$this->assertSame( '', $this->resolve( array() )['ip'] );
		$this->assertSame( '', $this->resolve( array( 'REMOTE_ADDR' => 'nope' ) )['ip'] );
		$this->assertSame( '', $this->resolve( array( 'REMOTE_ADDR' => array( 'x' ) ) )['ip'] );
	}

	public function test_hostile_xff_is_bounded(): void {
		$entries = array_fill( 0, 5000, '10.0.0.9' );
		array_unshift( $entries, '198.51.100.9' );
		$r = $this->resolve(
			array(
				'REMOTE_ADDR'          => '10.0.0.5',
				'HTTP_X_FORWARDED_FOR' => implode( ',', $entries ),
			)
		);
		$this->assertSame( '10.0.0.5', $r['ip'] );
	}

	public function test_prefix(): void {
		$this->assertSame( '203.0.113.0', ClientIp::prefix( '203.0.113.77' ) );
		$this->assertSame( '2001:db8:1::', ClientIp::prefix( '2001:db8:1::5' ) );
		$this->assertSame( '2001:db8:1::', ClientIp::prefix( '2001:db8:1:ffff::5' ) );
		$this->assertSame( '', ClientIp::prefix( 'nope' ) );
		$this->assertSame( '', ClientIp::prefix( '' ) );
	}
}
