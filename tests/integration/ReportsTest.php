<?php
/**
 * Read-only reports and factual signals.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Tests\Integration;

use BotInsight\Bots\BotList;
use BotInsight\Plugin;
use BotInsight\Report\Period;
use BotInsight\Report\Reports;
use BotInsight\Report\Signal;
use BotInsight\Report\Signals;
use BotInsight\Report\Signals\BotSessions;
use BotInsight\Report\Signals\GetAddToCart;
use BotInsight\Report\Signals\NoPageCache;
use BotInsight\Report\Signals\OriginBypass;
use BotInsight\Report\Signals\QueryStringBypass;
use BotInsight\Report\Signals\RobotsPerBotAllow;
use BotInsight\Storage\Dictionary;
use BotInsight\Storage\Schema;

final class ReportsTest extends \WP_UnitTestCase {

	/**
	 * 2026-11-13 00:10:00 UTC (01:10 in Amsterdam).
	 */
	private const NOW = 1794528600;

	/**
	 * Start of the current hour: 2026-11-13 00:00 UTC.
	 */
	private const HOUR = 1794528000;

	/**
	 * Dims counter so every hourly row is unique.
	 *
	 * @var int
	 */
	private $dims = 0;

	public function set_up(): void {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$this->cleanSlate();
		Schema::install();
		update_option( 'timezone_string', 'UTC' );
	}

	public function tear_down(): void {
		remove_all_filters( Signals::FILTER );
		parent::tear_down();
		$this->cleanSlate();
		update_option( 'timezone_string', '' );
		$GLOBALS['wpdb']->query( 'COMMIT' );
	}

	// Period.

	public function test_period_from_key(): void {
		$p = Period::fromKey( '24h', self::NOW );
		$this->assertSame( '24h', $p->key );
		$this->assertSame( self::HOUR - 24 * HOUR_IN_SECONDS, $p->start );
		$this->assertSame( self::HOUR + HOUR_IN_SECONDS, $p->end );

		$p = Period::fromKey( '7d', self::NOW );
		$this->assertSame( self::HOUR - 168 * HOUR_IN_SECONDS, $p->start );
		$this->assertSame( self::HOUR + HOUR_IN_SECONDS, $p->end );

		$p = Period::fromKey( '30d', self::NOW );
		$this->assertSame( self::HOUR - 720 * HOUR_IN_SECONDS, $p->start );

		$p = Period::fromKey( 'nonsense', self::NOW );
		$this->assertSame( '7d', $p->key );
		$this->assertSame( self::HOUR - 168 * HOUR_IN_SECONDS, $p->start );
	}

	// Totals.

	public function test_totals_and_bot_share(): void {
		$this->hourly( array( 'client_class' => 'people', 'requests' => 100, 'php_ms_sum' => 3600000 ) );
		$this->hourly( array( 'client_class' => 'search', 'bot' => 'googlebot', 'verified' => 1, 'requests' => 50, 'php_ms_sum' => 900000 ) );
		$this->hourly( array( 'client_class' => 'search', 'bot' => 'googlebot', 'verified' => 3, 'requests' => 7, 'php_ms_sum' => 0 ) );
		$this->hourly( array( 'client_class' => 'ai_assistant', 'bot' => 'chatgpt-user', 'verified' => 1, 'requests' => 5, 'php_ms_sum' => 0 ) );
		$this->hourly( array( 'client_class' => 'ai_training', 'bot' => 'gptbot', 'verified' => 1, 'requests' => 20, 'php_ms_sum' => 2700000 ) );
		$this->hourly( array( 'client_class' => 'suspected', 'requests' => 10, 'php_ms_sum' => 0 ) );
		// Outside the period: ignored.
		$this->hourly( array( 'hour' => self::HOUR - 25 * HOUR_IN_SECONDS, 'client_class' => 'people', 'requests' => 999, 'php_ms_sum' => 999999 ) );
		$this->daily( '2026-11-12', 'people', 40 );
		$this->daily( '2026-11-13', 'people', 30 );
		$this->daily( '2026-11-13', 'search', 2 );
		$this->daily( '2026-11-01', 'people', 500 );
		// 2026-11-13 is not a whole UTC day inside the 24h period; 2026-11-12 is.

		$totals = $this->reports()->totals( Period::fromKey( '24h', self::NOW ) );

		$this->assertSame(
			array(
				'requests'                 => 192,
				'php_hours'                => 2.0,
				'bot_share'                => 0.5,
				'bot_php_hours'            => 1.0,
				'verified_search_requests' => 55,
				'unique_client_days'       => 40,
			),
			$totals
		);
	}

	public function test_unique_client_days_counts_only_whole_utc_days(): void {
		$this->daily( '2026-11-11', 'people', 1 );
		$this->daily( '2026-11-12', 'people', 10 );
		$this->daily( '2026-11-13', 'people', 100 );
		// 24h ending at 05:59 UTC on 2026-11-13 holds no whole UTC day.
		$this->assertSame( 0, $this->reports()->totals( Period::fromKey( '24h', self::NOW + 5 * HOUR_IN_SECONDS ) )['unique_client_days'] );
		// 7d from 2026-11-06 00:00 UTC: whole days 06..12.
		$this->assertSame( 11, $this->reports()->totals( Period::fromKey( '7d', self::NOW ) )['unique_client_days'] );
	}

	public function test_totals_without_data(): void {
		$totals = $this->reports()->totals( Period::fromKey( '7d', self::NOW ) );
		$this->assertSame( 0, $totals['requests'] );
		$this->assertSame( 0.0, $totals['php_hours'] );
		$this->assertSame( 0.0, $totals['bot_share'] );
		$this->assertSame( 0, $totals['unique_client_days'] );
	}

	// Per day.

	public function test_per_day_buckets_by_site_timezone(): void {
		update_option( 'timezone_string', 'Europe/Amsterdam' );
		// 2026-11-12 23:00 UTC is 2026-11-13 00:00 in Amsterdam.
		$this->hourly( array( 'hour' => self::HOUR - HOUR_IN_SECONDS, 'client_class' => 'people', 'php_ms_sum' => 3600000 ) );
		$this->hourly( array( 'hour' => self::HOUR - HOUR_IN_SECONDS, 'client_class' => 'search', 'bot' => 'googlebot', 'php_ms_sum' => 1800000 ) );
		// 2026-11-12 22:00 UTC is 2026-11-12 23:00 in Amsterdam.
		$this->hourly( array( 'hour' => self::HOUR - 2 * HOUR_IN_SECONDS, 'client_class' => 'people', 'php_ms_sum' => 7200000 ) );
		$this->hourly( array( 'hour' => self::HOUR - 2 * HOUR_IN_SECONDS, 'client_class' => 'people', 'php_ms_sum' => 36000, 'route' => 'x' ) );

		$days = $this->reports()->perDay( Period::fromKey( '7d', self::NOW ) );

		// 2026-11-06 01:00 local up to 2026-11-13 01:59 local: eight local days.
		$this->assertSame(
			array( '2026-11-06', '2026-11-07', '2026-11-08', '2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13' ),
			array_column( $days, 'day' )
		);
		$this->assertSame( array(), $days[0]['by_class'] );
		$this->assertSame(
			array(
				'people' => 2.01,
			),
			$days[6]['by_class']
		);
		$this->assertSame(
			array(
				'people' => 1.0,
				'search' => 0.5,
			),
			$days[7]['by_class']
		);
	}

	public function test_per_day_counts_the_25_hour_dst_day(): void {
		update_option( 'timezone_string', 'Europe/Amsterdam' );
		// Local 2026-10-25 runs from 2026-10-24 22:00 UTC to 2026-10-25 23:00 UTC: 25 hours.
		$first = gmmktime( 22, 0, 0, 10, 24, 2026 );
		for ( $i = 0; $i < 25; $i++ ) {
			$this->hourly( array( 'hour' => $first + $i * HOUR_IN_SECONDS, 'client_class' => 'people', 'php_ms_sum' => 3600000 ) );
		}
		$this->hourly( array( 'hour' => $first - HOUR_IN_SECONDS, 'client_class' => 'people', 'php_ms_sum' => 3600000 ) );
		$this->hourly( array( 'hour' => $first + 25 * HOUR_IN_SECONDS, 'client_class' => 'people', 'php_ms_sum' => 3600000 ) );

		$days = $this->reports()->perDay( Period::fromKey( '30d', self::NOW ) );
		$map  = array_column( $days, 'by_class', 'day' );

		$this->assertSame( array( 'people' => 25.0 ), $map['2026-10-25'] );
		$this->assertSame( array( 'people' => 1.0 ), $map['2026-10-24'] );
		$this->assertSame( array( 'people' => 1.0 ), $map['2026-10-26'] );
		// One entry per local day, no gaps or repeats across the DST change.
		$this->assertCount( 31, $days );
		$this->assertSame( '2026-10-14', $days[0]['day'] );
		$this->assertSame( '2026-11-13', $days[30]['day'] );
		$this->assertSame( array_values( array_unique( array_column( $days, 'day' ) ) ), array_column( $days, 'day' ) );
	}

	public function test_per_day_in_utc(): void {
		$this->hourly( array( 'hour' => self::HOUR - HOUR_IN_SECONDS, 'client_class' => 'people', 'php_ms_sum' => 3600000 ) );
		$days = $this->reports()->perDay( Period::fromKey( '24h', self::NOW ) );
		$this->assertSame( array( '2026-11-12', '2026-11-13' ), array_column( $days, 'day' ) );
		$this->assertSame( array( 'people' => 1.0 ), $days[0]['by_class'] );
		$this->assertSame( array(), $days[1]['by_class'] );
	}

	// Visitors.

	public function test_visitors_grouped_and_ordered_by_php_hours(): void {
		$this->hourly( array( 'client_class' => 'people', 'requests' => 100, 'php_ms_sum' => 1800000 ) );
		$this->hourly( array( 'client_class' => 'suspected', 'requests' => 40, 'php_ms_sum' => 3600000 ) );
		$this->hourly( array( 'client_class' => 'library', 'requests' => 4, 'php_ms_sum' => 36000 ) );
		$this->hourly( array( 'client_class' => 'unknown_bot', 'requests' => 3, 'php_ms_sum' => 72000 ) );
		$this->hourly( array( 'client_class' => 'search', 'bot' => 'googlebot', 'verified' => 1, 'requests' => 60, 'php_ms_sum' => 7200000 ) );
		$this->hourly( array( 'client_class' => 'search', 'bot' => 'googlebot', 'verified' => 3, 'requests' => 5, 'php_ms_sum' => 0, 'route' => 'x' ) );
		// An impostor claiming Googlebot is classed unknown_bot but still carries the bot id.
		$this->hourly( array( 'client_class' => 'unknown_bot', 'bot' => 'googlebot', 'verified' => 2, 'requests' => 8, 'php_ms_sum' => 108000 ) );
		$this->hourly( array( 'client_class' => 'ai_training', 'bot' => 'gptbot', 'verified' => 1, 'requests' => 30, 'php_ms_sum' => 5400000 ) );

		$rows = $this->reports()->visitors( Period::fromKey( '24h', self::NOW ) );

		$this->assertSame( array( 'Googlebot', 'GPTBot', 'Suspected automated', 'People', 'Googlebot', 'Unknown bots', 'Script libraries' ), array_column( $rows, 'bot' ) );
		$this->assertSame(
			array(
				'id'          => 'googlebot',
				'bot'         => 'Googlebot',
				'class'       => 'search',
				'requests'    => 65,
				'php_hours'   => 2.0,
				'verified'    => array(
					'yes'       => 60,
					'no'        => 0,
					'unchecked' => 5,
				),
				'findability' => true,
				'impostor'    => false,
			),
			$rows[0]
		);
		// Impostors never count towards the genuine bot.
		$this->assertSame(
			array(
				'id'          => 'googlebot',
				'bot'         => 'Googlebot',
				'class'       => 'unknown_bot',
				'requests'    => 8,
				'php_hours'   => 0.03,
				'verified'    => array(
					'yes'       => 0,
					'no'        => 8,
					'unchecked' => 0,
				),
				'findability' => false,
				'impostor'    => true,
			),
			$rows[4]
		);
		$this->assertSame( array( false, false, false, false, true, false, false ), array_column( $rows, 'impostor' ) );
		$this->assertFalse( $rows[1]['findability'] );
		$this->assertSame( 'ai_training', $rows[1]['class'] );
		$this->assertSame( 'suspected', $rows[2]['class'] );
		$this->assertSame( '', $rows[2]['id'] );
		$this->assertSame( 1.0, $rows[2]['php_hours'] );
		$this->assertSame( 'people', $rows[3]['class'] );

		$this->assertCount( 2, $this->reports()->visitors( Period::fromKey( '24h', self::NOW ), 2 ) );
	}

	// Params.

	public function test_params_with_bot_share(): void {
		$this->hourly( array( 'client_class' => 'people', 'params' => 'add-to-cart', 'requests' => 10, 'php_ms_sum' => 900000 ) );
		$this->hourly( array( 'client_class' => 'suspected', 'params' => 'add-to-cart', 'requests' => 30, 'php_ms_sum' => 2700000 ) );
		$this->hourly( array( 'client_class' => 'people', 'requests' => 50, 'php_ms_sum' => 1800000 ) );
		$this->hourly( array( 'client_class' => 'seo', 'bot' => 'ahrefsbot', 'params' => 'filter_color,orderby', 'requests' => 5, 'php_ms_sum' => 36000 ) );

		$rows = $this->reports()->params( Period::fromKey( '24h', self::NOW ) );

		$this->assertSame(
			array(
				array(
					'params'    => 'add-to-cart',
					'requests'  => 40,
					'php_hours' => 1.0,
					'bot_share' => 0.75,
				),
				array(
					'params'    => '(none)',
					'requests'  => 50,
					'php_hours' => 0.5,
					'bot_share' => 0.0,
				),
				array(
					'params'    => 'filter_color,orderby',
					'requests'  => 5,
					'php_hours' => 0.01,
					'bot_share' => 1.0,
				),
			),
			$rows
		);
		$this->assertCount( 1, $this->reports()->params( Period::fromKey( '24h', self::NOW ), 1 ) );
	}

	public function test_params_groups_overflow_rows_apart(): void {
		$this->hourly( array( 'client_class' => 'people', 'requests' => 10, 'php_ms_sum' => 360000 ) );
		// Aggregator overflow rows: param_sig_id 0 and status_class xxx.
		$this->hourly( array( 'client_class' => 'suspected', 'status_class' => 'xxx', 'route' => 'other', 'requests' => 20, 'php_ms_sum' => 720000 ) );
		$this->hourly( array( 'client_class' => 'people', 'status_class' => 'xxx', 'route' => 'other', 'requests' => 20, 'php_ms_sum' => 720000 ) );

		$rows = $this->reports()->params( Period::fromKey( '24h', self::NOW ) );

		$this->assertSame(
			array(
				array(
					'params'    => '(other combinations)',
					'requests'  => 40,
					'php_hours' => 0.4,
					'bot_share' => 0.5,
				),
				array(
					'params'    => '(none)',
					'requests'  => 10,
					'php_hours' => 0.1,
					'bot_share' => 0.0,
				),
			),
			$rows
		);
	}

	// Unknown countries.

	public function test_unknown_countries(): void {
		$this->hourly( array( 'client_class' => 'suspected', 'country' => 'SG', 'requests' => 10, 'php_ms_sum' => 3600000 ) );
		$this->hourly( array( 'client_class' => 'library', 'country' => 'SG', 'requests' => 2, 'php_ms_sum' => 0 ) );
		$this->hourly( array( 'client_class' => 'unknown_bot', 'country' => '', 'requests' => 3, 'php_ms_sum' => 36000 ) );
		$this->hourly( array( 'client_class' => 'people', 'country' => 'NL', 'requests' => 100, 'php_ms_sum' => 9000000 ) );

		$rows = $this->reports()->unknownCountries( Period::fromKey( '24h', self::NOW ) );

		$this->assertSame(
			array(
				array(
					'country'   => 'SG',
					'requests'  => 12,
					'php_hours' => 1.0,
				),
				array(
					'country'   => '??',
					'requests'  => 3,
					'php_hours' => 0.01,
				),
			),
			$rows
		);
	}

	// Signals breakdown.

	public function test_signals_breakdown_scaled_by_sample_rate(): void {
		update_option( 'bot_insight_sample_rate', 3 );
		$this->sample( array( 'client_class' => 'suspected', 'signals' => 1 | 2 ) );
		$this->sample( array( 'client_class' => 'suspected', 'signals' => 1 | 4 | 16 ) );
		$this->sample( array( 'client_class' => 'people', 'signals' => 1 ) );
		$this->sample( array( 'client_class' => 'suspected', 'signals' => 1 | 2, 'ts' => self::NOW - 40 * DAY_IN_SECONDS ) );

		$this->assertSame(
			array(
				'no_js'           => 6,
				'no_sec_fetch'    => 3,
				'no_accept_lang'  => 3,
				'get_add_to_cart' => 0,
				'ua_outdated'     => 3,
			),
			$this->reports()->signalsBreakdown( Period::fromKey( '7d', self::NOW ) )
		);
	}

	// Overhead, snapshot, events.

	public function test_overhead_averages_last_seven_non_zero_snapshots(): void {
		$values = array( 100, 0, 200, 300, 400, 0, 500, 600, 700, 9000 );
		foreach ( $values as $i => $us ) {
			$this->snapshot( gmdate( 'Y-m-d', self::NOW - ( $i + 1 ) * DAY_IN_SECONDS ), array( 'overhead_p95_us' => $us ) );
		}
		// The newest seven non-zero values: 100..700.
		$this->assertSame(
			array(
				'p95_us' => 400,
				'days'   => 7,
			),
			$this->reports()->overhead()
		);
	}

	public function test_overhead_without_snapshots(): void {
		$this->assertSame(
			array(
				'p95_us' => 0,
				'days'   => 0,
			),
			$this->reports()->overhead()
		);
		$this->assertNull( $this->reports()->latestSnapshot() );
	}

	public function test_latest_snapshot_and_events(): void {
		global $wpdb;
		$this->snapshot( '2026-11-10', array( 'cdn' => 'none' ) );
		$this->snapshot( '2026-11-12', array( 'cdn' => 'cloudflare' ) );
		$this->assertSame( 'cloudflare', $this->reports()->latestSnapshot()['cdn'] );

		$t = Schema::tables()['events'];
		$wpdb->insert( $t, array( 'ts' => self::NOW - 100, 'type' => 'plugin_updated', 'subject' => 'b', 'detail' => '2.0' ) );
		$wpdb->insert( $t, array( 'ts' => self::NOW - 200, 'type' => 'plugin_activated', 'subject' => 'a', 'detail' => '' ) );
		$wpdb->insert( $t, array( 'ts' => self::NOW - 40 * DAY_IN_SECONDS, 'type' => 'note', 'subject' => 'old', 'detail' => '' ) );

		$this->assertSame(
			array(
				array(
					'ts'      => self::NOW - 200,
					'type'    => 'plugin_activated',
					'subject' => 'a',
					'detail'  => '',
				),
				array(
					'ts'      => self::NOW - 100,
					'type'    => 'plugin_updated',
					'subject' => 'b',
					'detail'  => '2.0',
				),
			),
			$this->reports()->events( Period::fromKey( '7d', self::NOW ) )
		);
	}

	// Export.

	public function test_export_hourly(): void {
		$h1 = self::HOUR - 2 * HOUR_IN_SECONDS;
		$this->hourly( array( 'hour' => self::HOUR, 'client_class' => 'people', 'requests' => 4, 'php_ms_sum' => 400 ) );
		$this->hourly( array( 'hour' => $h1, 'client_class' => 'search', 'bot' => 'googlebot', 'verified' => 1, 'requests' => 10, 'php_ms_sum' => 1000 ) );
		$this->hourly( array( 'hour' => $h1, 'client_class' => 'search', 'bot' => 'googlebot', 'verified' => 3, 'requests' => 2, 'php_ms_sum' => 50, 'route' => 'x' ) );
		$this->hourly( array( 'hour' => $h1, 'client_class' => 'people', 'requests' => 7, 'php_ms_sum' => 70, 'route' => 'y' ) );

		$this->assertSame(
			array(
				array(
					'hour'     => '2026-11-12T22:00:00Z',
					'class'    => 'people',
					'bot'      => '',
					'requests' => 7,
					'php_ms'   => 70,
					'verified' => 0,
				),
				array(
					'hour'     => '2026-11-12T22:00:00Z',
					'class'    => 'search',
					'bot'      => 'googlebot',
					'requests' => 12,
					'php_ms'   => 1050,
					'verified' => 1,
				),
				array(
					'hour'     => '2026-11-13T00:00:00Z',
					'class'    => 'people',
					'bot'      => '',
					'requests' => 4,
					'php_ms'   => 400,
					'verified' => 0,
				),
			),
			$this->reports()->exportHourly( Period::fromKey( '24h', self::NOW ) )
		);
	}

	// Signals on a clean site.

	public function test_every_signal_is_null_on_a_clean_site(): void {
		$this->snapshot(
			'2026-11-12',
			array(
				'cdn'        => 'cloudflare',
				'page_cache' => array(
					'dropin' => true,
					'plugin' => 'wp-rocket',
				),
				'robots'     => array(
					'hash'   => 'x',
					'groups' => array(
						array(
							'agents'   => array( '*' ),
							'allow'    => array(),
							'disallow' => array( '/wp-admin/' ),
						),
						array(
							'agents'   => array( 'Googlebot' ),
							'allow'    => array( '/' ),
							'disallow' => array(),
						),
					),
				),
			)
		);
		$this->hourly( array( 'client_class' => 'people', 'via_cdn' => 1, 'requests' => 1000, 'php_ms_sum' => 100000, 'sessions_created' => 20, 'uncached_reason' => 'query_string' ) );
		$this->hourly( array( 'client_class' => 'search', 'bot' => 'googlebot', 'via_cdn' => 1, 'requests' => 1000, 'php_ms_sum' => 900000, 'route' => 'x' ) );
		$this->hourly( array( 'client_class' => 'people', 'via_cdn' => 0, 'requests' => 10, 'php_ms_sum' => 100, 'route' => 'y' ) );
		$this->sample( array( 'client_class' => 'people', 'method' => 'GET', 'params' => 'add-to-cart' ) );
		$this->sample( array( 'client_class' => 'suspected', 'method' => 'POST', 'params' => 'add-to-cart' ) );

		$r = $this->reports();
		$p = Period::fromKey( '24h', self::NOW );
		foreach ( Signals::all() as $signal ) {
			$this->assertNull( $signal->evaluate( $r, $p ), $signal->id() );
		}
		$this->assertSame( array(), Signals::evaluate( $r, $p ) );
	}

	public function test_signals_are_null_without_data(): void {
		$r = $this->reports();
		$p = Period::fromKey( '24h', self::NOW );
		foreach ( Signals::all() as $signal ) {
			$this->assertNull( $signal->evaluate( $r, $p ), $signal->id() );
		}
	}

	// Each signal.

	public function test_origin_bypass(): void {
		$this->snapshot( '2026-11-12', array( 'cdn' => 'cloudflare' ) );
		$this->hourly( array( 'client_class' => 'people', 'via_cdn' => 1, 'requests' => 9000 ) );
		$this->hourly( array( 'client_class' => 'suspected', 'via_cdn' => 0, 'requests' => 1000 ) );

		$out = ( new OriginBypass() )->evaluate( $this->reports(), Period::fromKey( '24h', self::NOW ) );

		$this->assertSame( 'origin_bypass', $out['id'] );
		$this->assertSame( 'notice', $out['severity'] );
		$this->assertSame( '1,000 requests (10% of all requests) reached PHP without passing through Cloudflare.', $out['title'] );
		$this->assertSame(
			array(
				'requests'        => 10000,
				'direct_requests' => 1000,
				'direct_share'    => 0.1,
			),
			$out['facts']
		);

		// Without Cloudflare in the snapshot the same traffic is not a signal.
		$this->snapshot( '2026-11-13', array( 'cdn' => 'none' ) );
		$this->assertNull( ( new OriginBypass() )->evaluate( $this->reports(), Period::fromKey( '24h', self::NOW ) ) );
	}

	public function test_get_add_to_cart(): void {
		update_option( 'bot_insight_sample_rate', 5 );
		$this->sample( array( 'client_class' => 'suspected', 'method' => 'GET', 'params' => 'add-to-cart,quantity' ) );
		$this->sample( array( 'client_class' => 'unknown_bot', 'method' => 'GET', 'params' => 'add-to-cart' ) );
		$this->sample( array( 'client_class' => 'suspected', 'method' => 'GET', 'params' => 'no-add-to-cart' ) );
		$this->sample( array( 'client_class' => 'people', 'method' => 'GET', 'params' => 'add-to-cart' ) );

		$r   = $this->reports();
		$p   = Period::fromKey( '24h', self::NOW );
		$out = ( new GetAddToCart() )->evaluate( $r, $p );

		$this->assertSame( 10, $r->getAddToCartRequests( $p ) );
		$this->assertSame( 'get_add_to_cart', $out['id'] );
		$this->assertSame( 'About 10 GET requests with add-to-cart came from bots and suspected automated clients.', $out['title'] );
		$this->assertSame(
			array(
				'requests'    => 10,
				'sample_rate' => 5,
			),
			$out['facts']
		);
	}

	public function test_robots_per_bot_allow(): void {
		$this->snapshot(
			'2026-11-12',
			array(
				'robots' => array(
					'hash'   => 'x',
					'groups' => array(
						array(
							'agents'   => array( '*' ),
							'allow'    => array(),
							'disallow' => array( '/*?*', '/cart/' ),
						),
						array(
							'agents'   => array( 'gptbot', 'AhrefsBot' ),
							'allow'    => array( '/' ),
							'disallow' => array(),
						),
						array(
							'agents'   => array( 'Googlebot' ),
							'allow'    => array( '/' ),
							'disallow' => array(),
						),
						array(
							'agents'   => array( 'CCBot' ),
							'allow'    => array( '/blog/' ),
							'disallow' => array(),
						),
					),
				),
			)
		);

		$out = ( new RobotsPerBotAllow() )->evaluate( $this->reports(), Period::fromKey( '24h', self::NOW ) );

		$this->assertSame( 'robots_per_bot_allow', $out['id'] );
		$this->assertSame( 'robots.txt has an Allow: / group for gptbot, AhrefsBot while the * group disallows 2 paths.', $out['title'] );
		$this->assertSame(
			array(
				'agents'         => 'gptbot, AhrefsBot',
				'star_disallows' => 2,
			),
			$out['facts']
		);
	}

	public function test_robots_per_bot_allow_needs_a_star_disallow(): void {
		$this->snapshot(
			'2026-11-12',
			array(
				'robots' => array(
					'hash'   => 'x',
					'groups' => array(
						array(
							'agents'   => array( '*' ),
							'allow'    => array(),
							'disallow' => array(),
						),
						array(
							'agents'   => array( 'GPTBot' ),
							'allow'    => array( '/' ),
							'disallow' => array(),
						),
					),
				),
			)
		);
		$this->assertNull( ( new RobotsPerBotAllow() )->evaluate( $this->reports(), Period::fromKey( '24h', self::NOW ) ) );
	}

	public function test_no_page_cache(): void {
		$this->snapshot(
			'2026-11-12',
			array(
				'page_cache' => array(
					'dropin' => false,
					'plugin' => '',
				),
			)
		);
		$this->hourly( array( 'client_class' => 'people', 'requests' => 1234, 'php_ms_sum' => 5400000 ) );

		$out = ( new NoPageCache() )->evaluate( $this->reports(), Period::fromKey( '24h', self::NOW ) );

		$this->assertSame( 'no_page_cache', $out['id'] );
		$this->assertSame( 'No page cache was detected; 1,234 requests used 1.5 hours of PHP time in this period.', $out['title'] );
		$this->assertSame(
			array(
				'requests'  => 1234,
				'php_hours' => 1.5,
			),
			$out['facts']
		);
	}

	public function test_query_string_bypass(): void {
		$this->hourly( array( 'client_class' => 'suspected', 'uncached_reason' => 'query_string', 'requests' => 10, 'php_ms_sum' => 900000 ) );
		$this->hourly( array( 'client_class' => 'people', 'uncached_reason' => 'logged_in', 'requests' => 10, 'php_ms_sum' => 2700000 ) );

		$r   = $this->reports();
		$p   = Period::fromKey( '24h', self::NOW );
		$out = ( new QueryStringBypass() )->evaluate( $r, $p );

		$this->assertSame( 0.25, $r->uncachedShare( $p, 'query_string' ) );
		$this->assertSame( 'query_string_bypass', $out['id'] );
		$this->assertSame( '25% of PHP time (0.25 hours) went to requests that skipped the page cache because of a query string.', $out['title'] );
		$this->assertSame(
			array(
				'share'     => 0.25,
				'php_hours' => 0.25,
			),
			$out['facts']
		);
	}

	public function test_bot_sessions(): void {
		$this->hourly( array( 'client_class' => 'suspected', 'sessions_created' => 1500 ) );
		$this->hourly( array( 'client_class' => 'seo', 'bot' => 'ahrefsbot', 'sessions_created' => 3 ) );
		$this->hourly( array( 'client_class' => 'people', 'sessions_created' => 99 ) );

		$r   = $this->reports();
		$p   = Period::fromKey( '24h', self::NOW );
		$out = ( new BotSessions() )->evaluate( $r, $p );

		$this->assertSame( 1503, $r->botSessions( $p ) );
		$this->assertSame( 'bot_sessions', $out['id'] );
		$this->assertSame( 'info', $out['severity'] );
		$this->assertSame( '1,503 sessions were created for bots and suspected automated clients.', $out['title'] );
		$this->assertSame( array( 'sessions' => 1503 ), $out['facts'] );
	}

	// Registry.

	public function test_registry_filter_drops_non_signals_and_survives_a_broken_signal(): void {
		$broken = new class() implements Signal {
			public function id(): string {
				return 'broken';
			}
			public function evaluate( Reports $r, Period $p ): ?array {
				throw new \RuntimeException( 'boom' );
			}
		};
		$fixed  = new class() implements Signal {
			public function id(): string {
				return 'third_party';
			}
			public function evaluate( Reports $r, Period $p ): ?array {
				return array(
					'severity' => 'loud',
					'title'    => 'A fact.',
					'facts'    => array( 'n' => 1 ),
				);
			}
		};
		add_filter(
			Signals::FILTER,
			static function ( $list ) use ( $broken, $fixed ) {
				$list[] = 'not a signal';
				$list[] = $broken;
				$list[] = $fixed;
				return $list;
			}
		);

		$all = Signals::all();
		$this->assertCount( 8, $all );
		foreach ( $all as $signal ) {
			$this->assertInstanceOf( Signal::class, $signal );
		}
		$this->assertSame(
			array( 'origin_bypass', 'get_add_to_cart', 'robots_per_bot_allow', 'no_page_cache', 'query_string_bypass', 'bot_sessions', 'broken', 'third_party' ),
			array_map(
				static function ( Signal $s ) {
					return $s->id();
				},
				$all
			)
		);

		$out = Signals::evaluate( $this->reports(), Period::fromKey( '24h', self::NOW ) );
		$this->assertSame(
			array(
				array(
					'id'       => 'third_party',
					'severity' => 'info',
					'title'    => 'A fact.',
					'facts'    => array( 'n' => 1 ),
				),
			),
			$out
		);
	}

	// Helpers.

	private function reports(): Reports {
		global $wpdb;
		return new Reports( $wpdb, BotList::fromFile( dirname( __DIR__, 2 ) . '/data/bots.json' ) );
	}

	/**
	 * Insert one hourly row. `bot` and `params` are resolved to dictionary ids.
	 *
	 * @param array<string, mixed> $row Columns.
	 */
	private function hourly( array $row ): void {
		global $wpdb;
		$dict = new Dictionary( $wpdb );
		if ( isset( $row['bot'] ) ) {
			$row['bot_id'] = $dict->id( 'bot', $row['bot'] );
			unset( $row['bot'] );
		}
		if ( isset( $row['params'] ) ) {
			$row['param_sig_id'] = $dict->id( 'param_sig', $row['params'] );
			unset( $row['params'] );
		}
		$row = array_merge(
			array(
				'hour' => self::HOUR,
				'dims' => md5( 'dims' . ( ++$this->dims ), true ),
			),
			$row
		);
		$this->assertNotFalse( $wpdb->insert( Schema::tables()['hourly'], $row ) );
	}

	/**
	 * Insert one sample row.
	 *
	 * @param array<string, mixed> $row Columns.
	 */
	private function sample( array $row ): void {
		global $wpdb;
		if ( isset( $row['params'] ) ) {
			$row['param_sig_id'] = ( new Dictionary( $wpdb ) )->id( 'param_sig', $row['params'] );
			unset( $row['params'] );
		}
		$row         = array_merge( array( 'ts' => self::NOW - 600 ), $row );
		$row['hour'] = $row['ts'] - $row['ts'] % HOUR_IN_SECONDS;
		$this->assertNotFalse( $wpdb->insert( Schema::tables()['sample'], $row ) );
	}

	private function daily( string $day, string $class, int $unique ): void {
		global $wpdb;
		$wpdb->insert(
			Schema::tables()['daily'],
			array(
				'day'          => $day,
				'client_class' => $class,
				'unique_ips'   => $unique,
			)
		);
	}

	/**
	 * Store a snapshot row.
	 *
	 * @param string               $day  Y-m-d.
	 * @param array<string, mixed> $data Snapshot data.
	 */
	private function snapshot( string $day, array $data ): void {
		global $wpdb;
		$wpdb->replace(
			Schema::tables()['snapshot'],
			array(
				'day'  => $day,
				'data' => wp_json_encode( $data ),
			)
		);
	}

	private function cleanSlate(): void {
		global $wpdb;
		foreach ( Schema::tables() as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS $table" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$names = $wpdb->get_col( "SELECT option_name FROM $wpdb->options WHERE option_name LIKE '%bot\\_insight\\_%'" );
		foreach ( $names as $name ) {
			delete_option( $name );
		}
		wp_cache_flush();
		$prop = new \ReflectionProperty( Plugin::class, 'settings' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
	}
}
