<?php
/**
 * Tests for the unique tracker.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Tests\Unit\Fold;

use BotInsight\Fold\UniqueTracker;
use PHPUnit\Framework\TestCase;

final class UniqueTrackerTest extends TestCase {

	private const HOUR = 1794528000; // 2026-11-13 00:00 UTC.

	/**
	 * Scratch directory.
	 *
	 * @var string
	 */
	private $dir = '';

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/bi-uniq-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->dir );
	}

	protected function tearDown(): void {
		foreach ( (array) glob( $this->dir . '/*' ) as $file ) {
			unlink( (string) $file );
		}
		rmdir( $this->dir );
	}

	public function test_three_hashes_one_seen_twice(): void {
		$dims = md5( 'a', true );
		$t    = new UniqueTracker( $this->dir );
		$t->add( self::HOUR, $dims, 'search', 'aaaaaaaaaaaaaaaa' );
		$t->add( self::HOUR, $dims, 'search', 'bbbbbbbbbbbbbbbb' );
		$t->add( self::HOUR, $dims, 'search', 'bbbbbbbbbbbbbbbb' );
		$t->add( self::HOUR, $dims, 'search', 'cccccccccccccccc' );

		$out = $t->closeHour( self::HOUR );

		$this->assertSame( array( $dims ), array_keys( $out ) );
		$this->assertSame( 3, $out[ $dims ]['unique_ips'] );
		$this->assertSame( 2, $out[ $dims ]['ips_seen_once'] );
		// Top 10 hashes cover all 4 requests.
		$this->assertSame( 10000, $out[ $dims ]['top_ip_share'] );
		$this->assertFileDoesNotExist( $this->dir . '/uniq-2026111300.log' );
		$this->assertSame( array(), $t->closeHour( self::HOUR ) );
	}

	public function test_binary_dims_with_tabs_and_newlines_survive(): void {
		$a = "\t\n\0" . str_repeat( 'x', 13 );
		$b = md5( 'b', true );
		$t = new UniqueTracker( $this->dir );
		$t->add( self::HOUR, $a, 'people', '1111111111111111' );
		$t->add( self::HOUR, $b, 'people', '1111111111111111' );

		$out = $t->closeHour( self::HOUR );

		$this->assertCount( 2, $out );
		$this->assertSame( 1, $out[ $a ]['unique_ips'] );
		$this->assertSame( 1, $out[ $b ]['unique_ips'] );
	}

	public function test_top_ip_share_counts_ten_busiest_hashes(): void {
		$dims = md5( 'c', true );
		$t    = new UniqueTracker( $this->dir );
		// 10 hashes with 2 requests each, 20 hashes with 1 request: top 10 = 20 of 40.
		for ( $i = 0; $i < 10; $i++ ) {
			$t->add( self::HOUR, $dims, 'seo', sprintf( 'a%015d', $i ) );
			$t->add( self::HOUR, $dims, 'seo', sprintf( 'a%015d', $i ) );
		}
		for ( $i = 0; $i < 20; $i++ ) {
			$t->add( self::HOUR, $dims, 'seo', sprintf( 'b%015d', $i ) );
		}

		$out = $t->closeHour( self::HOUR );

		$this->assertSame( 30, $out[ $dims ]['unique_ips'] );
		$this->assertSame( 20, $out[ $dims ]['ips_seen_once'] );
		$this->assertSame( 5000, $out[ $dims ]['top_ip_share'] );
	}

	public function test_day_counts_per_class_across_hours(): void {
		$t = new UniqueTracker( $this->dir );
		$t->add( self::HOUR, md5( 'x', true ), 'search', 'aaaaaaaaaaaaaaaa' );
		$t->add( self::HOUR + 3600, md5( 'y', true ), 'search', 'aaaaaaaaaaaaaaaa' );
		$t->add( self::HOUR + 7200, md5( 'y', true ), 'search', 'bbbbbbbbbbbbbbbb' );
		$t->add( self::HOUR, md5( 'z', true ), 'people', 'cccccccccccccccc' );

		$this->assertSame( array( '20261113' ), $t->openDays() );
		$this->assertSame( array( self::HOUR, self::HOUR + 3600, self::HOUR + 7200 ), $t->openHours() );

		$out = $t->closeDay( '20261113' );

		$this->assertSame(
			array(
				'people' => array(
					'unique_ips'    => 1,
					'ips_seen_once' => 1,
				),
				'search' => array(
					'unique_ips'    => 2,
					'ips_seen_once' => 1,
				),
			),
			$out
		);
		$this->assertSame( array(), $t->openDays() );
		$this->assertCount( 3, $t->openHours() );
	}

	public function test_stats_do_not_delete_until_dropped(): void {
		$dims = md5( 'd', true );
		$t    = new UniqueTracker( $this->dir );
		$t->add( self::HOUR, $dims, 'seo', 'aaaaaaaaaaaaaaaa' );

		$this->assertSame( 1, $t->hourStats( self::HOUR )[ $dims ]['unique_ips'] );
		$this->assertSame( 1, $t->hourStats( self::HOUR )[ $dims ]['unique_ips'] );
		$this->assertSame( 1, $t->dayStats( '20261113' )['seo']['unique_ips'] );

		$t->dropHour( self::HOUR );
		$t->dropDay( '20261113' );

		$this->assertSame( array(), $t->hourStats( self::HOUR ) );
		$this->assertSame( array(), $t->dayStats( '20261113' ) );
	}

	public function test_empty_hash_is_ignored(): void {
		$t = new UniqueTracker( $this->dir );
		$t->add( self::HOUR, md5( 'e', true ), 'people', '' );
		$this->assertSame( array(), $t->closeHour( self::HOUR ) );
	}
}
