<?php
/**
 * Tests for the aggregator.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Tests\Unit\Fold;

use BotInsight\Fold\Aggregator;
use PHPUnit\Framework\TestCase;

final class AggregatorTest extends TestCase {

	private const HOUR = 1700000000;

	/**
	 * Dictionary stub: stable ids per (type, value).
	 *
	 * @return callable
	 */
	private function dict(): callable {
		$ids = array();
		return static function ( string $type, string $value ) use ( &$ids ): int {
			$k = $type . "\0" . $value;
			if ( ! isset( $ids[ $k ] ) ) {
				$ids[ $k ] = count( $ids ) + 1;
			}
			return $ids[ $k ];
		};
	}

	/**
	 * @param array<string, mixed> $over Overrides.
	 * @return array<string, mixed>
	 */
	private function row( array $over = array() ): array {
		return array_merge(
			array(
				'hour'         => self::HOUR,
				'class'        => 'search',
				'bot'          => 'googlebot',
				'r'            => 'product',
				'p'            => '',
				'lg'           => 'nl',
				'country'      => 'US',
				'asn_type'     => 'hosting',
				'cdn'          => 0,
				'verified'     => 1,
				'js'           => 0,
				'status_class' => '2xx',
				'unc'          => 'miss',
				'ms'           => 100.4,
				'cpu'          => 50.4,
				'mem'          => 1000,
				'dbq'          => 10,
				'se'           => 0,
				'ca'           => 0,
				'or'           => 0,
			),
			$over
		);
	}

	public function test_identical_rows_merge(): void {
		$rows = array(
			$this->row(),
			$this->row(
				array(
					'ms'  => 2500.3,
					'cpu' => 10.2,
					'mem' => 3000,
					'dbq' => 5,
					'se'  => 1,
					'ca'  => 1,
					'or'  => 1,
				)
			),
		);
		$out  = Aggregator::aggregate( $rows, $this->dict(), array() );

		$this->assertCount( 1, $out['rows'] );
		$r = $out['rows'][0];
		$this->assertSame( 2, $r['requests'] );
		$this->assertSame( 2601, $r['php_ms_sum'] );
		$this->assertSame( 61, $r['cpu_ms_sum'] );
		$this->assertSame( 15, $r['db_queries_sum'] );
		$this->assertSame( 3000, $r['mem_peak_max'] );
		$this->assertSame( 1, $r['slow_2s'] );
		$this->assertSame( 1, $r['sessions_created'] );
		$this->assertSame( 1, $r['cart_changes'] );
		$this->assertSame( 1, $r['orders'] );
		$this->assertSame( 0, $r['unique_ips'] );
		$this->assertSame( 0, $r['ips_seen_once'] );
		$this->assertSame( 0, $r['top_ip_share'] );
		$this->assertSame( self::HOUR, $r['hour'] );
		$this->assertSame( 'search', $r['client_class'] );
		$this->assertSame( 16, strlen( $r['dims'] ) );
		$this->assertSame( array( $r['dims'], $r['dims'] ), $out['dims'] );
	}

	public function test_dims_key_and_empty_ids(): void {
		$out = Aggregator::aggregate( array( $this->row( array( 'bot' => '', 'p' => '' ) ) ), $this->dict(), array() );
		$r   = $out['rows'][0];
		$this->assertSame( 0, $r['bot_id'] );
		$this->assertSame( 0, $r['param_sig_id'] );
		$vals = array();
		foreach ( Aggregator::DIMENSIONS as $d ) {
			$vals[ $d ] = $r[ $d ];
		}
		$this->assertSame( Aggregator::dimsKey( $vals ), $r['dims'] );
		$this->assertSame( md5( implode( "\x1f", array_values( $vals ) ), true ), $r['dims'] );
	}

	public function test_250_combos_keep_200_and_overflow(): void {
		$rows = array();
		for ( $i = 1; $i <= 250; $i++ ) {
			$rows[] = $this->row( array( 'r' => 'route' . $i, 'ms' => (float) $i ) );
		}
		$out = Aggregator::aggregate( $rows, $this->dict(), array() );

		$total = 0;
		$kept  = 0;
		$over  = array();
		foreach ( $out['rows'] as $r ) {
			$total += $r['requests'];
			if ( 'other' === $r['route'] ) {
				$over[] = $r;
			} else {
				++$kept;
			}
		}
		$this->assertSame( 250, $total );
		$this->assertSame( 200, $kept );
		$this->assertCount( 1, $over );
		$this->assertSame( 50, $over[0]['requests'] );
		$this->assertSame( 1275, $over[0]['php_ms_sum'] ); // 1..50.
		$this->assertSame( 'xxx', $over[0]['status_class'] );
		$this->assertSame( '??', $over[0]['country'] );
		$this->assertSame( 'unknown', $over[0]['asn_type'] );
		$this->assertSame( 'other', $over[0]['uncached_reason'] );
		$this->assertSame( 'search', $over[0]['client_class'] );
		$this->assertSame( 1, $over[0]['verified'] );
		// Top 200 by php time kept: route51 is the cheapest kept, route50 is not.
		$routes = array_column( $out['rows'], 'route' );
		$this->assertContains( 'route51', $routes );
		$this->assertNotContains( 'route50', $routes );
		// dims has one entry per input row, mapped to the overflow key for the dropped ones.
		$this->assertCount( 250, $out['dims'] );
		$this->assertSame( $over[0]['dims'], $out['dims'][0] );
		$this->assertNotSame( $over[0]['dims'], $out['dims'][249] );
	}

	public function test_existing_combo_updates_when_hour_is_full(): void {
		$first = Aggregator::aggregate( array( $this->row( array( 'r' => 'known' ) ) ), $this->dict(), array() );
		$key   = $first['rows'][0]['dims'];

		$rows = array( $this->row( array( 'r' => 'known', 'ms' => 1.0 ) ) );
		$rows[] = $this->row( array( 'r' => 'fresh', 'ms' => 999.0 ) );
		$slots = array( self::HOUR => array( 'count' => 200, 'keys' => array( $key => true ) ) );
		$out   = Aggregator::aggregate( $rows, $this->dict(), $slots );

		$byRoute = array_column( $out['rows'], null, 'route' );
		$this->assertArrayHasKey( 'known', $byRoute );
		$this->assertSame( 1, $byRoute['known']['requests'] );
		$this->assertArrayNotHasKey( 'fresh', $byRoute );
		$this->assertSame( 1, $byRoute['other']['requests'] );
	}

	public function test_hours_are_guarded_independently(): void {
		$rows   = array();
		$rows[] = $this->row( array( 'r' => 'a' ) );
		$rows[] = $this->row( array( 'r' => 'a', 'hour' => self::HOUR + 3600 ) );
		$slots  = array( self::HOUR => array( 'count' => 200, 'keys' => array() ) );
		$out    = Aggregator::aggregate( $rows, $this->dict(), $slots );

		$this->assertCount( 2, $out['rows'] );
		$routes = array();
		foreach ( $out['rows'] as $r ) {
			$routes[ $r['hour'] ] = $r['route'];
		}
		$this->assertSame( 'other', $routes[ self::HOUR ] );
		$this->assertSame( 'a', $routes[ self::HOUR + 3600 ] );
	}
}
