<?php
/**
 * Dev fixture: fill the plugin's tables with seven days of sample data for the dashboard.
 *
 * Run against any site with Bot Insight active:
 *
 *     wp eval-file wp-content/plugins/bot-insight/tests/fixtures/seed.php
 *     wp eval-file wp-content/plugins/bot-insight/tests/fixtures/seed.php reset
 *
 * Without `reset` it refuses to touch a site that already has hourly data. With `reset` it first
 * empties the hourly, daily, sample and snapshot tables. All numbers are made up; addresses
 * use documentation prefixes only (192.0.2.0/24, 198.51.100.0/24, 203.0.113.0/24).
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

use BotInsight\Bots\Classifier;
use BotInsight\Storage\Dictionary;
use BotInsight\Storage\Schema;

if ( ! defined( 'ABSPATH' ) || ! class_exists( Schema::class ) ) {
	fwrite( STDERR, "Run with `wp eval-file` on a site where Bot Insight is active.\n" );
	return;
}

( static function ( array $argv ) {
	global $wpdb;

	Schema::install();
	$t     = Schema::tables();
	$reset = in_array( 'reset', $argv, true );

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	if ( $reset ) {
		foreach ( array( 'hourly', 'daily', 'sample', 'snapshot' ) as $short ) {
			$wpdb->query( "TRUNCATE TABLE {$t[ $short ]}" );
		}
	} elseif ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['hourly']}" ) > 0 ) {
		$message = 'The hourly table already has data. Pass `reset` to empty the tables first.';
		if ( class_exists( 'WP_CLI' ) ) {
			WP_CLI::error( $message );
		}
		fwrite( STDERR, $message . "\n" );
		return;
	}

	mt_srand( 20261113 );
	$dict = new Dictionary( $wpdb );
	$now  = time();
	$cur  = $now - $now % HOUR_IN_SECONDS;
	$dims = 0;

	$sig = static function ( string $params ) use ( $dict ): int {
		return '' === $params ? 0 : $dict->id( 'param_sig', $params );
	};
	$bot = static function ( string $id ) use ( $dict ): int {
		return $dict->id( 'bot', $id );
	};

	$insert = static function ( array $row ) use ( $wpdb, $t, &$dims ) {
		$row['dims'] = md5( 'seed' . ( ++$dims ), true );
		$wpdb->insert( $t['hourly'], $row );
	};

	// Visitor streams: class, bot id ('' = none), verified code, requests per hour, ms per request,
	// share of daytime traffic, param signatures (weighted), countries.
	$streams = array(
		array( 'people', '', 0, 260, 240, 0.9, array( '' => 7, 'orderby' => 2, 'filter_color' => 1 ), array( 'NL', 'BE', 'DE' ) ),
		array( 'search', 'googlebot', 1, 40, 310, 0.3, array( '' => 8, 'orderby' => 1 ), array( 'US' ) ),
		array( 'search', 'bingbot', 1, 18, 300, 0.3, array( '' => 9, 'orderby' => 1 ), array( 'US' ) ),
		array( 'search', 'applebot', 3, 4, 290, 0.3, array( '' => 1 ), array( 'US' ) ),
		array( 'ai_search', 'oai-searchbot', 1, 3, 330, 0.3, array( '' => 1 ), array( 'US' ) ),
		array( 'ai_search', 'perplexitybot', 1, 2, 330, 0.3, array( '' => 1 ), array( 'US' ) ),
		array( 'ai_assistant', 'chatgpt-user', 1, 2, 280, 0.6, array( '' => 1 ), array( 'US' ) ),
		array( 'ai_assistant', 'claude-user', 3, 1, 280, 0.6, array( '' => 1 ), array( 'US' ) ),
		array( 'ai_training', 'gptbot', 1, 30, 420, 0.3, array( 'filter_color,filter_size' => 3, 'orderby' => 2, '' => 2 ), array( 'US' ) ),
		array( 'ai_training', 'claudebot', 3, 14, 400, 0.3, array( 'filter_color' => 2, '' => 2 ), array( 'US' ) ),
		array( 'ai_training', 'meta-externalagent', 1, 45, 450, 0.3, array( 'filter_color,filter_size' => 3, 'per_page,shop_view' => 2 ), array( 'US' ) ),
		array( 'ai_training', 'amazonbot', 3, 6, 380, 0.3, array( '' => 1 ), array( 'US' ) ),
		array( 'seo', 'semrushbot', 3, 60, 360, 0.3, array( 'orderby' => 3, 'per_page,shop_view' => 2, '' => 1 ), array( 'US' ) ),
		array( 'seo', 'ahrefsbot', 3, 35, 350, 0.3, array( 'orderby' => 2, '' => 2 ), array( 'SG' ) ),
		array( 'social', 'facebookexternalhit', 3, 5, 260, 0.6, array( '' => 1 ), array( 'IE' ) ),
		array( 'monitoring', 'uptimerobot', 3, 12, 180, 0.0, array( '' => 1 ), array( 'US' ) ),
		array( 'feed', 'feedly', 3, 2, 150, 0.3, array( '' => 1 ), array( 'US' ) ),
		array( 'library', '', 0, 25, 300, 0.3, array( '' => 2, 'add-to-cart' => 1 ), array( 'US', 'DE', 'CN' ) ),
		array( 'unknown_bot', '', 0, 140, 520, 0.2, array( 'add-to-cart' => 5, 'orderby' => 1 ), array( 'BR', 'US', 'AR', 'RU', 'CO', '' ) ),
		array( 'suspected', '', 0, 900, 560, 0.2, array( 'add-to-cart' => 6, 'add-to-cart,orderby' => 2, '' => 1 ), array( 'BR', 'US', 'AR', 'VN', '' ) ),
		// Requests that claimed to be Googlebot from addresses outside its ranges.
		array( 'unknown_bot', 'googlebot', 2, 22, 500, 0.2, array( 'add-to-cart' => 2, '' => 1 ), array( 'BR', 'VN' ) ),
	);

	$pick = static function ( array $weights ) {
		$total = array_sum( $weights );
		$roll  = mt_rand( 1, max( 1, $total ) );
		foreach ( $weights as $key => $weight ) {
			$roll -= $weight;
			if ( $roll <= 0 ) {
				return (string) $key;
			}
		}
		return (string) array_key_first( $weights );
	};

	$rows      = 0;
	$suspected = 0;
	for ( $h = 168; $h >= 0; $h-- ) {
		$hour  = $cur - $h * HOUR_IN_SECONDS;
		$local = (int) gmdate( 'G', $hour + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) );
		$day   = ( $local >= 8 && $local <= 22 ) ? 1.0 : 0.25;
		// One busy day in the middle of the week for the unknown and suspected streams.
		$surge = ( $h >= 72 && $h < 96 ) ? 2.5 : 1.0;

		foreach ( $streams as $s ) {
			list( $class, $bot_id, $verified, $per_hour, $ms, $daytime, $params, $countries ) = $s;
			$factor = ( 1 - $daytime ) + $daytime * $day;
			if ( in_array( $class, array( 'unknown_bot', 'suspected' ), true ) ) {
				$factor *= $surge;
			}
			$requests = (int) round( $per_hour * $factor * ( 0.75 + mt_rand( 0, 50 ) / 100 ) );
			if ( $requests < 1 ) {
				continue;
			}
			// Split the hour over two parameter signatures and one country each.
			$parts = array(
				array( $pick( $params ), $countries[ array_rand( $countries ) ], (int) ceil( $requests * 0.7 ) ),
				array( $pick( $params ), $countries[ array_rand( $countries ) ], (int) floor( $requests * 0.3 ) ),
			);
			foreach ( $parts as $part ) {
				list( $param, $country, $n ) = $part;
				if ( $n < 1 ) {
					continue;
				}
				if ( 'suspected' === $class && $h < 168 ) {
					$suspected += $n;
				}
				// Unidentified clients spread over many addresses; a named bot or a person rarely does.
				$many_ips = in_array( $class, array( 'people', 'library', 'suspected' ), true ) || ( 'unknown_bot' === $class && '' === $bot_id );
				$insert(
					array(
						'hour'             => $hour,
						'client_class'     => $class,
						'bot_id'           => '' === $bot_id ? 0 : $bot( $bot_id ),
						'route'            => '' === $param ? 'product' : 'shop',
						'param_sig_id'     => $sig( $param ),
						'country'          => $country,
						'via_cdn'          => mt_rand( 0, 9 ) < 8 ? 1 : 0,
						'verified'         => $verified,
						'js_seen'          => 'people' === $class ? 1 : 0,
						'status_class'     => '2xx',
						'uncached_reason'  => '' === $param ? '' : 'query_string',
						'requests'         => $n,
						'php_ms_sum'       => (int) round( $n * $ms * ( 0.8 + mt_rand( 0, 40 ) / 100 ) ),
						'cpu_ms_sum'       => (int) round( $n * $ms * 0.6 ),
						'db_queries_sum'   => $n * 40,
						'mem_peak_max'     => 48 * 1024,
						'slow_2s'          => 0,
						'unique_ips'       => $many_ips ? (int) ceil( $n * 0.6 ) : 1,
						'ips_seen_once'    => $many_ips ? (int) ceil( $n * 0.4 ) : 0,
						'top_ip_share'     => $many_ips ? 300 : 10000,
						'sessions_created' => 'suspected' === $class ? (int) floor( $n * 0.3 ) : 0,
						'cart_changes'     => 'people' === $class ? (int) floor( $n * 0.02 ) : 0,
						'orders'           => 0,
					)
				);
				++$rows;
			}
		}

		// Overflow rows: dimension combinations past the per-hour cap.
		foreach ( array( 'suspected', 'unknown_bot', 'search' ) as $class ) {
			$n = mt_rand( 5, 30 );
			$insert(
				array(
					'hour'         => $hour,
					'client_class' => $class,
					'bot_id'       => 0,
					'country'      => '',
					'status_class' => 'xxx',
					'requests'     => $n,
					'php_ms_sum'   => $n * 450,
				)
			);
			++$rows;
		}
	}

	// Daily unique client counts for whole UTC days.
	for ( $d = 8; $d >= 0; $d-- ) {
		$date = gmdate( 'Y-m-d', $now - $d * DAY_IN_SECONDS );
		foreach ( array(
			'people'      => 1800,
			'search'      => 60,
			'ai_training' => 40,
			'seo'         => 20,
			'unknown_bot' => 2600,
			'suspected'   => 14000,
		) as $class => $ips ) {
			$wpdb->replace(
				$t['daily'],
				array(
					'day'          => $date,
					'client_class' => $class,
					'requests'     => 0,
					'php_ms_sum'   => 0,
					'unique_ips'   => (int) round( $ips * ( 0.8 + mt_rand( 0, 40 ) / 100 ) ),
				)
			);
		}
	}

	// Sample rows: suspected requests with their signal bits, some GET add-to-cart. Each row weighs
	// the rate it stands for, so the weights add up to the suspected requests of the hourly rows.
	$samples = 400;
	$weight  = max( 1, (int) round( $suspected / $samples ) );
	$no_js   = array_search( 'no_js', Classifier::SIGNALS, true );
	$no_sec  = array_search( 'no_sec_fetch', Classifier::SIGNALS, true );
	$no_lang = array_search( 'no_accept_lang', Classifier::SIGNALS, true );
	$cart    = array_search( 'get_add_to_cart', Classifier::SIGNALS, true );
	$old_ua  = array_search( 'ua_outdated', Classifier::SIGNALS, true );
	$prefix  = array( '192.0.2.0', '198.51.100.0', '203.0.113.0' );
	for ( $i = 0; $i < $samples; $i++ ) {
		$ts      = $now - mt_rand( 60, 7 * DAY_IN_SECONDS - 60 );
		$is_cart = mt_rand( 0, 9 ) < 6;
		$signals = $no_js | ( mt_rand( 0, 9 ) < 7 ? $no_sec : 0 ) | ( mt_rand( 0, 9 ) < 4 ? $no_lang : 0 ) | ( $is_cart ? $cart : 0 ) | ( mt_rand( 0, 9 ) < 3 ? $old_ua : 0 );
		$wpdb->insert(
			$t['sample'],
			array(
				'ts'              => $ts,
				'hour'            => $ts - $ts % HOUR_IN_SECONDS,
				'php_ms'          => mt_rand( 300, 900 ),
				'status'          => 200,
				'status_class'    => '2xx',
				'uncached_reason' => 'query_string',
				'method'          => 'GET',
				'route'           => 'shop',
				'param_sig_id'    => $sig( $is_cart ? 'add-to-cart' : 'orderby' ),
				'ip_source'       => 'remote_addr',
				'ip_hash'         => substr( md5( 'seed-ip-' . mt_rand( 1, 5000 ) ), 0, 16 ),
				'ip_prefix'       => $prefix[ array_rand( $prefix ) ] . '/24',
				'country'         => array( 'BR', 'US', 'AR', 'VN' )[ mt_rand( 0, 3 ) ],
				'client_class'    => 'suspected',
				'signals'         => $signals,
				'session_created' => mt_rand( 0, 1 ),
				'weight'          => $weight,
			)
		);
	}

	// Snapshots: overhead per day, no page cache detected, no CDN.
	for ( $d = 7; $d >= 0; $d-- ) {
		$wpdb->replace(
			$t['snapshot'],
			array(
				'day'  => gmdate( 'Y-m-d', $now - $d * DAY_IN_SECONDS ),
				'data' => wp_json_encode(
					array(
						'page_cache'      => array(
							'dropin' => false,
							'plugin' => '',
						),
						'cdn'             => 'none',
						'overhead_p95_us' => mt_rand( 105, 135 ),
					)
				),
			)
		);
	}
	// phpcs:enable

	if ( class_exists( 'WP_CLI' ) ) {
		WP_CLI::success( sprintf( 'Seeded %d hourly rows, 400 sample rows and 8 snapshots.', $rows ) );
	}
} )( isset( $args ) && is_array( $args ) ? $args : array() );
