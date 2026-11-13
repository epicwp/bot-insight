<?php
/**
 * The daily job: snapshot, events, retention, sample rate, range refresh, load samples.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Tests\Integration;

use BotInsight\Buffer\FileBuffer;
use BotInsight\Net\IpRanges;
use BotInsight\Net\RangeUpdater;
use BotInsight\Plugin;
use BotInsight\Site\Daily;
use BotInsight\Site\Events;
use BotInsight\Site\Langs;
use BotInsight\Site\Snapshot;
use BotInsight\Storage\Schema;

final class DailyTest extends \WP_UnitTestCase {

	/**
	 * 2026-11-13 00:10:00 UTC.
	 */
	private const NOW = 1794528600;

	/**
	 * Buffer directory.
	 *
	 * @var string
	 */
	private $dir = '';

	/**
	 * URLs the fake HTTP layer saw.
	 *
	 * @var string[]
	 */
	private $urls = array();

	/**
	 * Body the fake HTTP layer returns per URL substring, or a throwable to throw.
	 *
	 * @var array<string, string>
	 */
	private $bodies = array();

	public function set_up(): void {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$this->cleanSlate();
		Schema::install();
		$this->dir = Plugin::ensureBufferDir();
		$this->assertNotSame( '', $this->dir );
		$this->urls   = array();
		$this->bodies = array();
		add_filter( 'pre_http_request', array( $this, 'fakeHttp' ), 10, 3 );
		$this->settings( array() );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'fakeHttp' ), 10 );
		parent::tear_down();
		$this->cleanSlate();
		$this->settings( array() );
		$GLOBALS['wpdb']->query( 'COMMIT' );
	}

	public function test_snapshot_day_start(): void {
		$this->assertSame( '2026-11-13 00:10:00', gmdate( 'Y-m-d H:i:s', self::NOW ) );
	}

	public function test_retention_deletes_old_rows_and_keeps_recent_ones(): void {
		global $wpdb;
		$t   = Schema::tables();
		$old = self::NOW - 401 * DAY_IN_SECONDS;
		$new = self::NOW - 2 * DAY_IN_SECONDS;
		$this->hourly( $old, 1 );
		$this->hourly( $new, 1, 0, 'b' );
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$t['daily']} (day, client_class, requests) VALUES (%s,'human',1),(%s,'human',1)", gmdate( 'Y-m-d', $old ), gmdate( 'Y-m-d', $new ) ) );
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$t['snapshot']} (day, data) VALUES (%s,'{}'),(%s,'{}')", gmdate( 'Y-m-d', $old ), gmdate( 'Y-m-d', $new ) ) );
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$t['events']} (ts, type, subject, detail) VALUES (%d,'note','old',''),(%d,'note','new','')", $old, $new ) );
		// 5003 old sample rows (more than one delete batch) and one recent one.
		$this->samples( self::NOW - 15 * DAY_IN_SECONDS, array_fill( 0, 5003, 10 ) );
		$this->samples( self::NOW - 13 * DAY_IN_SECONDS, array( 10 ) );

		( new Daily( $wpdb ) )->run( self::NOW );

		$this->assertSame( '1', $wpdb->get_var( "SELECT COUNT(*) FROM {$t['hourly']}" ) );
		$this->assertSame( (string) ( $new - $new % HOUR_IN_SECONDS ), $wpdb->get_var( "SELECT MIN(hour) FROM {$t['hourly']}" ) );
		$this->assertSame( gmdate( 'Y-m-d', $new ), $wpdb->get_var( "SELECT MIN(day) FROM {$t['daily']}" ) );
		$this->assertSame( gmdate( 'Y-m-d', $new ), $wpdb->get_var( "SELECT MIN(day) FROM {$t['snapshot']}" ) );
		$this->assertSame( '1', $wpdb->get_var( "SELECT COUNT(*) FROM {$t['sample']}" ) );
		$this->assertSame( array( 'new' ), $wpdb->get_col( "SELECT subject FROM {$t['events']} WHERE type = 'note'" ) );
	}

	public function test_retention_follows_the_setting(): void {
		global $wpdb;
		$this->settings( array( 'retention_days' => 30 ) );
		$this->hourly( self::NOW - 31 * DAY_IN_SECONDS, 1 );
		$this->hourly( self::NOW - 29 * DAY_IN_SECONDS, 1, 0, 'b' );
		( new Daily( $wpdb ) )->run( self::NOW );
		$this->assertSame( '1', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::tables()['hourly'] ) );
	}

	public function test_sample_rate_from_yesterdays_requests(): void {
		global $wpdb;
		$yesterday = self::NOW - self::NOW % DAY_IN_SECONDS - DAY_IN_SECONDS;
		$this->hourly( $yesterday, 30000 );
		$this->hourly( $yesterday + 3600, 22000, 0, 'b' );
		// Not yesterday: ignored.
		$this->hourly( $yesterday - 3600, 99999, 0, 'c' );

		( new Daily( $wpdb ) )->run( self::NOW );

		$this->assertSame( 11, (int) get_option( 'bot_insight_sample_rate' ) );
	}

	public function test_sample_rate_is_one_on_a_quiet_day_and_untouched_when_fixed(): void {
		global $wpdb;
		( new Daily( $wpdb ) )->run( self::NOW );
		$this->assertSame( 1, (int) get_option( 'bot_insight_sample_rate' ) );

		update_option( 'bot_insight_sample_rate', 7 );
		$this->settings( array( 'sample_rate' => 3 ) );
		$this->hourly( self::NOW - DAY_IN_SECONDS, 52000 );
		( new Daily( $wpdb ) )->run( self::NOW );
		$this->assertSame( 7, (int) get_option( 'bot_insight_sample_rate' ) );
	}

	public function test_snapshot_has_all_keys_and_no_personal_data(): void {
		global $wpdb;
		$day = self::NOW - self::NOW % DAY_IN_SECONDS - DAY_IN_SECONDS;
		$this->hourly( $day + 7200, 5, 1 );
		$this->samples( $day + 100, array( 300, 100, 200 ) );
		update_option( 'bot_insight_load', array( $day + 3600 => 0.5, $day + 7200 => 1.25 ) );

		// A WooCommerce order in the legacy (posts) store, full of personal data.
		$order = self::factory()->post->create(
			array(
				'post_type'     => 'shop_order',
				'post_status'   => 'wc-completed',
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', self::NOW - 5 * DAY_IN_SECONDS ),
			)
		);
		update_post_meta( $order, '_billing_country', 'NL' );
		update_post_meta( $order, '_billing_email', 'jane@example.com' );
		update_post_meta( $order, '_billing_first_name', 'Jane' );
		update_post_meta( $order, '_billing_address_1', 'Main Street 1' );
		$old = self::factory()->post->create(
			array(
				'post_type'     => 'shop_order',
				'post_status'   => 'wc-completed',
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', self::NOW - 120 * DAY_IN_SECONDS ),
			)
		);
		update_post_meta( $old, '_billing_country', 'DE' );

		( new Daily( $wpdb ) )->run( self::NOW );

		$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT data FROM ' . Schema::tables()['snapshot'] . ' WHERE day = %s', gmdate( 'Y-m-d', $day ) ) );
		$this->assertIsString( $raw );
		$snap = json_decode( $raw, true );
		$this->assertIsArray( $snap );
		foreach ( array( 'wp', 'wc', 'php', 'plugins', 'theme', 'page_cache', 'object_cache', 'server', 'htaccess', 'cdn', 'robots', 'sitemap', 'lang_plugin', 'languages', 'billing_countries_90d', 'shipping_countries', 'load', 'overhead_p95_us', 'bots_version', 'ranges_date' ) as $key ) {
			$this->assertArrayHasKey( $key, $snap );
		}
		$this->assertSame( 'cloudflare', $snap['cdn'] );
		$this->assertSame( 300, $snap['overhead_p95_us'] );
		$this->assertSame(
			array(
				'01' => 0.5,
				'02' => 1.25,
			),
			$snap['load']
		);
		$this->assertSame( array( 'NL' => 1 ), $snap['billing_countries_90d'] );
		$this->assertSame( PHP_VERSION, $snap['php'] );
		$this->assertSame( get_bloginfo( 'version' ), $snap['wp'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $snap['robots']['hash'] );
		$this->assertNotSame( '', $snap['bots_version'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $snap['ranges_date'] );

		// Privacy: no key or value looks like an email, name, address or order id.
		$this->assertStringNotContainsString( 'example.com', $raw );
		$this->assertStringNotContainsString( 'Jane', $raw );
		$this->assertStringNotContainsString( 'Main Street', $raw );
		array_walk_recursive(
			$snap,
			function ( $value, $key ) {
				$this->assertDoesNotMatchRegularExpression( '/@|email|e-mail|first_name|last_name|address|phone|order_id/i', (string) $key );
				$this->assertDoesNotMatchRegularExpression( '/[^\s@]+@[^\s@]+\.[a-z]{2,}/i', (string) $value );
			}
		);
		$this->assertDoesNotMatchRegularExpression( '/"[^"]*@[^"]*"\s*:/', $raw );
	}

	public function test_cdn_none_and_p95_zero_without_rows(): void {
		global $wpdb;
		$day = self::NOW - self::NOW % DAY_IN_SECONDS - DAY_IN_SECONDS;
		$this->hourly( $day + 3600, 5, 0 );
		// via_cdn on another day does not count.
		$this->hourly( $day - 3600, 5, 1, 'b' );
		( new Daily( $wpdb ) )->run( self::NOW );
		$snap = $this->snapshot( $day );
		$this->assertSame( 'none', $snap['cdn'] );
		$this->assertSame( 0, $snap['overhead_p95_us'] );
	}

	public function test_overhead_p95_uses_nearest_rank(): void {
		global $wpdb;
		$day = self::NOW - self::NOW % DAY_IN_SECONDS - DAY_IN_SECONDS;
		$this->samples( $day + 50, range( 100, 1, -1 ) );
		// Outside the day.
		$this->samples( $day - 50, array( 99999 ) );
		( new Daily( $wpdb ) )->run( self::NOW );
		$this->assertSame( 95, $this->snapshot( $day )['overhead_p95_us'] );
	}

	public function test_load_samples_are_moved_and_pruned(): void {
		global $wpdb;
		$day = self::NOW - self::NOW % DAY_IN_SECONDS - DAY_IN_SECONDS;
		update_option(
			'bot_insight_load',
			array(
				$day - 3 * DAY_IN_SECONDS => 9.0,
				$day + 3600               => 0.5,
				self::NOW - 600           => 0.25,
			),
			false
		);
		( new Daily( $wpdb ) )->run( self::NOW );
		$this->assertSame( array( '01' => 0.5 ), $this->snapshot( $day )['load'] );
		$this->assertSame(
			array(
				$day + 3600     => 0.5,
				self::NOW - 600 => 0.25,
			),
			get_option( 'bot_insight_load' )
		);
	}

	public function test_load_sample_is_added_once_per_hour(): void {
		$hour = self::NOW - self::NOW % HOUR_IN_SECONDS;
		Daily::addLoadSample( $hour + 60, 1.5 );
		Daily::addLoadSample( $hour + 600, 3.0 );
		Daily::addLoadSample( $hour + 3600, 2.0 );
		$this->assertSame(
			array(
				$hour        => 1.5,
				$hour + 3600 => 2.0,
			),
			get_option( 'bot_insight_load' )
		);
		$this->assertContains( $this->autoload( 'bot_insight_load' ), array( 'no', 'off' ) );
	}

	public function test_fold_action_records_load(): void {
		if ( ! function_exists( 'sys_getloadavg' ) || false === sys_getloadavg() ) {
			$this->markTestSkipped( 'No load average on this system.' );
		}
		do_action( 'bot_insight_folded', array() );
		$load = get_option( 'bot_insight_load' );
		$this->assertIsArray( $load );
		$this->assertArrayHasKey( time() - time() % HOUR_IN_SECONDS, $load );
	}

	public function test_robots_change_emits_event_only_after_a_previous_snapshot(): void {
		global $wpdb;
		$robots = static function ( $out ) {
			return $out . "Disallow: /first\n";
		};
		add_filter( 'robots_txt', $robots );
		( new Daily( $wpdb ) )->run( self::NOW - DAY_IN_SECONDS );
		$this->assertSame( array(), $this->events() );
		remove_filter( 'robots_txt', $robots );

		( new Daily( $wpdb ) )->run( self::NOW );
		$events = $this->events();
		$this->assertSame( array( 'robots_changed' ), array_column( $events, 'type' ) );

		// A second run on the same day writes no new events and keeps one snapshot row.
		( new Daily( $wpdb ) )->run( self::NOW + 3600 );
		$this->assertCount( 1, $this->events() );
		$this->assertSame( '2', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::tables()['snapshot'] ) );
	}

	public function test_cdn_change_emits_event(): void {
		global $wpdb;
		$day = self::NOW - self::NOW % DAY_IN_SECONDS - DAY_IN_SECONDS;
		( new Daily( $wpdb ) )->run( self::NOW - DAY_IN_SECONDS );
		$this->hourly( $day + 60, 1, 1 );
		( new Daily( $wpdb ) )->run( self::NOW );
		$events = $this->events();
		$this->assertSame( array( 'cdn_changed' ), array_column( $events, 'type' ) );
		$this->assertStringContainsString( 'cloudflare', $events[0]['detail'] );
	}

	public function test_robots_parsing_from_physical_file_and_sitemap(): void {
		$file = ABSPATH . 'robots.txt';
		$this->assertFileDoesNotExist( $file );
		file_put_contents( $file, "User-agent: *\nDisallow: /*?*\n\nUser-agent: GPTBot\nAllow: /\nSitemap: https://example.org/sitemap_index.xml\n" );
		try {
			$snap = Snapshot::collect( self::NOW - self::NOW % DAY_IN_SECONDS - DAY_IN_SECONDS );
		} finally {
			unlink( $file );
		}
		$this->assertCount( 2, $snap['robots']['groups'] );
		$this->assertSame( array( 'GPTBot' ), $snap['robots']['groups'][1]['agents'] );
		$this->assertSame( 'https://example.org/sitemap_index.xml', $snap['sitemap'] );
	}

	public function test_virtual_robots_uses_its_sitemap_line(): void {
		$snap = Snapshot::collect( self::NOW - self::NOW % DAY_IN_SECONDS - DAY_IN_SECONDS );
		$this->assertNotEmpty( $snap['robots']['groups'] );
		// Core adds its sitemap index to the virtual robots.txt.
		$this->assertSame( get_sitemap_url( 'index' ), $snap['sitemap'] );
	}

	public function test_sitemap_falls_back_to_core_sitemap(): void {
		$strip = static function ( $out ) {
			return (string) preg_replace( '/^Sitemap:.*$/mi', '', (string) $out );
		};
		add_filter( 'robots_txt', $strip, 999 );
		try {
			$snap = Snapshot::collect( self::NOW - self::NOW % DAY_IN_SECONDS - DAY_IN_SECONDS );
		} finally {
			remove_filter( 'robots_txt', $strip, 999 );
		}
		$this->assertSame( home_url( '/wp-sitemap.xml' ), $snap['sitemap'] );
	}

	public function test_events_dedupe_and_truncate(): void {
		Events::add( 'note', 'same', 'one', 1000 );
		Events::add( 'note', 'same', 'two', 1059 );
		Events::add( 'note', 'other', 'three', 1059 );
		Events::add( 'note', 'same', 'four', 1061 );
		Events::add( 'note', str_repeat( 'x', 300 ), str_repeat( 'y', 70000 ), 2000 );
		Events::add( 'bogus_type', 'x', '', 3000 );
		$events = $this->events();
		$this->assertSame( array( 'one', 'three', 'four' ), array_slice( array_column( $events, 'detail' ), 0, 3 ) );
		$this->assertCount( 4, $events );
		$this->assertSame( 191, strlen( $events[3]['subject'] ) );
		$this->assertSame( 65535, strlen( $events[3]['detail'] ) );
	}

	public function test_plugin_and_theme_hooks_record_events(): void {
		do_action( 'activated_plugin', 'akismet/akismet.php', false );
		do_action( 'deactivated_plugin', 'hello.php', false );
		do_action( 'switch_theme', 'Twenty Twenty-Four', wp_get_theme( 'twentytwentyfour' ), wp_get_theme( 'twentytwentythree' ) );
		$this->assertSame(
			array(
				array( 'plugin_activated', 'akismet' ),
				array( 'plugin_deactivated', 'hello' ),
				array( 'theme_switched', 'twentytwentyfour' ),
			),
			array_map(
				static function ( $e ) {
					return array( $e['type'], $e['subject'] );
				},
				$this->events()
			)
		);
	}

	public function test_upgrader_records_plugin_and_core_updates(): void {
		$this->assertNotFalse( has_action( 'upgrader_process_complete', array( Events::class, 'onUpgrade' ) ) );
		// Called directly: core's own callbacks on this hook need a real upgrader.
		Events::onUpgrade(
			null,
			array(
				'action'  => 'update',
				'type'    => 'plugin',
				'plugins' => array( 'akismet/akismet.php' ),
			)
		);
		Events::onUpgrade(
			null,
			array(
				'action' => 'update',
				'type'   => 'core',
			)
		);
		Events::onUpgrade(
			null,
			array(
				'action' => 'install',
				'type'   => 'plugin',
			)
		);
		$this->assertSame( array( 'plugin_update', 'core_update' ), array_column( $this->events(), 'type' ) );
		$this->assertSame( 'akismet', $this->events()[0]['subject'] );
	}

	public function test_langs_option_from_wpml_and_permalink_bases(): void {
		global $wpdb;
		$wpml = static function () {
			return array(
				'nl' => array( 'code' => 'nl' ),
				'de' => array( 'code' => 'de' ),
			);
		};
		add_filter( 'wpml_active_languages', $wpml );
		update_option( 'category_base', '/onderwerp' );
		update_option( 'tag_base', '/label/' );
		try {
			( new Daily( $wpdb ) )->run( self::NOW );
		} finally {
			remove_filter( 'wpml_active_languages', $wpml );
			delete_option( 'category_base' );
			delete_option( 'tag_base' );
		}
		$langs = get_option( 'bot_insight_langs' );
		$this->assertSame( array( 'nl', 'de' ), $langs['slugs'] );
		$this->assertContains( 'onderwerp', $langs['keep'] );
		$this->assertContains( 'label', $langs['keep'] );
		$this->assertContains( $this->autoload( 'bot_insight_langs' ), array( 'yes', 'on' ) );
		$snap = $this->snapshot( self::NOW - self::NOW % DAY_IN_SECONDS - DAY_IN_SECONDS );
		$this->assertSame( 'wpml', $snap['lang_plugin'] );
		$this->assertSame( array( 'nl', 'de' ), $snap['languages'] );
	}

	public function test_permalink_base_cleanup(): void {
		$this->assertSame( 'shop', Langs::base( '/shop/%product_cat%/' ) );
		$this->assertSame( 'produkt', Langs::base( 'produkt' ) );
		$this->assertSame( '', Langs::base( '/%product_cat%/' ) );
		$this->assertSame( '', Langs::base( '' ) );
	}

	public function test_activation_writes_langs(): void {
		update_option( 'category_base', '/topics' );
		try {
			Plugin::activate();
		} finally {
			delete_option( 'category_base' );
		}
		$this->assertContains( 'topics', get_option( 'bot_insight_langs' )['keep'] );
	}

	public function test_range_refresh_writes_override_used_by_ip_ranges(): void {
		global $wpdb;
		$this->settings( array( 'range_updates' => true ) );
		$this->bodies = array(
			'googlebot.json'   => '{"prefixes":[{"ipv4Prefix":"198.51.100.0/24"}]}',
			'cloudflare.com'   => "203.0.113.0/24\n",
			'.json'            => '{"prefixes":[{"ipv6Prefix":"2001:db8::/32"}]}',
		);
		( new Daily( $wpdb ) )->run( self::NOW );

		$override = $this->dir . '/ranges';
		$this->assertNotEmpty( $this->urls );
		$this->assertFileExists( $override . '/_meta.php' );
		$this->assertSame( gmdate( 'Y-m-d' ), IpRanges::fetched( $override ) );
		$this->assertSame( array(), glob( $override . '/*.tmp' ) );
		// The fixture dir has no `_meta.php`, so any dated override is newer than it.
		$ranges = new IpRanges( dirname( __DIR__ ) . '/fixtures/ranges', $override );
		$this->assertTrue( $ranges->contains( '198.51.100.9', 'google' ) );
		$this->assertTrue( $ranges->contains( '203.0.113.9', 'cloudflare' ) );

		// Fresh override: no new fetch on the next run.
		$this->urls = array();
		( new Daily( $wpdb ) )->run( self::NOW + DAY_IN_SECONDS );
		$this->assertSame( array(), $this->urls );
	}

	public function test_range_refresh_is_off_by_default(): void {
		global $wpdb;
		( new Daily( $wpdb ) )->run( self::NOW );
		$this->assertSame( array(), $this->urls );
		$this->assertDirectoryDoesNotExist( $this->dir . '/ranges' );
	}

	public function test_range_updater_rejects_invalid_and_empty_lists(): void {
		$target       = $this->dir . '/ranges';
		$this->bodies = array(
			'googlebot.json' => '{"prefixes":[{"ipv4Prefix":"198.51.100.0/24"},{"ipv4Prefix":"999.1.1.1/8"}]}',
			'cloudflare.com' => '<html>error</html>',
			'.json'          => '{"prefixes":[{"ipv4Prefix":"192.0.2.0/24"}]}',
		);
		$this->assertFalse( ( new RangeUpdater() )->run( $target ) );
		$this->assertFileDoesNotExist( $target . '/google.php' );
		$this->assertFileDoesNotExist( $target . '/cloudflare.php' );
		$this->assertFileExists( $target . '/bing.php' );
		$meta = include $target . '/_meta.php';
		$this->assertArrayNotHasKey( 'google', $meta['ok'] );
		$this->assertArrayHasKey( 'bing', $meta['ok'] );
	}

	public function test_failing_step_does_not_stop_the_others(): void {
		global $wpdb;
		$this->settings( array( 'range_updates' => true ) );
		$this->bodies = array( '' => 'THROW' );
		$this->hourly( self::NOW - DAY_IN_SECONDS, 52000 );

		( new Daily( $wpdb ) )->run( self::NOW );

		$this->assertSame( 11, (int) get_option( 'bot_insight_sample_rate' ) );
		$this->assertNotNull( $this->snapshot( self::NOW - self::NOW % DAY_IN_SECONDS - DAY_IN_SECONDS ) );
		$status = get_option( 'bot_insight_daily_status' );
		$this->assertSame( self::NOW, $status['last_run'] );
		$this->assertArrayHasKey( 'ranges', $status['errors'] );
		$this->assertStringContainsString( 'fake failure', $status['errors']['ranges'] );
		$this->assertSame( 'ok', $status['steps']['snapshot'] );
	}

	public function test_geo_runs_on_the_third_only_when_enabled(): void {
		global $wpdb;
		$third = gmmktime( 0, 10, 0, 12, 3, 2026 );
		( new Daily( $wpdb ) )->run( $third );
		$this->assertSame( array(), $this->urls );

		$this->settings( array( 'geo_updates' => true ) );
		$this->bodies = array( '' => '' );
		( new Daily( $wpdb ) )->run( $third );
		$this->assertNotEmpty( preg_grep( '/db-ip\.com/', $this->urls ) );
	}

	public function test_scheduler_daily_callback_runs_the_job(): void {
		\BotInsight\Fold\Scheduler::runDaily();
		$status = get_option( 'bot_insight_daily_status' );
		$this->assertIsArray( $status );
		$this->assertGreaterThan( 0, $status['last_run'] );
	}

	/**
	 * Fake the HTTP layer.
	 *
	 * @param mixed  $pre  Pre value.
	 * @param array  $args Request args.
	 * @param string $url  URL.
	 * @return array<string, mixed>
	 * @throws \RuntimeException When the configured body is THROW.
	 */
	public function fakeHttp( $pre, $args, $url ) {
		$this->urls[] = $url;
		foreach ( $this->bodies as $needle => $body ) {
			if ( '' === $needle || false !== strpos( $url, $needle ) ) {
				if ( 'THROW' === $body ) {
					throw new \RuntimeException( 'fake failure' );
				}
				return array(
					'headers'  => array(),
					'body'     => $body,
					'response' => array(
						'code'    => '' === $body ? 404 : 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			}
		}
		return array(
			'headers'  => array(),
			'body'     => '',
			'response' => array(
				'code'    => 404,
				'message' => 'Not Found',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Insert an hourly row.
	 *
	 * @param int    $hour     Hour start (any time is floored).
	 * @param int    $requests Requests.
	 * @param int    $via_cdn  via_cdn flag.
	 * @param string $dims     Dims seed.
	 */
	private function hourly( int $hour, int $requests, int $via_cdn = 0, string $dims = 'a' ): void {
		global $wpdb;
		$wpdb->insert(
			Schema::tables()['hourly'],
			array(
				'hour'         => $hour - $hour % HOUR_IN_SECONDS,
				'dims'         => md5( $dims, true ),
				'client_class' => 'human',
				'requests'     => $requests,
				'via_cdn'      => $via_cdn,
			)
		);
	}

	/**
	 * Insert sample rows with the given self_us values.
	 *
	 * @param int   $ts   Time of every row.
	 * @param int[] $self self_us values.
	 */
	private function samples( int $ts, array $self ): void {
		global $wpdb;
		$table = Schema::tables()['sample'];
		foreach ( array_chunk( $self, 1000 ) as $chunk ) {
			$values = array();
			foreach ( $chunk as $us ) {
				$values[] = $wpdb->prepare( '(%d,%d,%d)', $ts, $ts - $ts % HOUR_IN_SECONDS, $us );
			}
			$wpdb->query( "INSERT INTO {$table} (ts, hour, self_us) VALUES " . implode( ',', $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/**
	 * Decoded snapshot of a day, or null.
	 *
	 * @param int $day Day start.
	 * @return array<string, mixed>|null
	 */
	private function snapshot( int $day ): ?array {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT data FROM ' . Schema::tables()['snapshot'] . ' WHERE day = %s', gmdate( 'Y-m-d', $day ) ) );
		return is_string( $raw ) ? json_decode( $raw, true ) : null;
	}

	/**
	 * Events, oldest first.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function events(): array {
		global $wpdb;
		return $wpdb->get_results( 'SELECT ts, type, subject, detail FROM ' . Schema::tables()['events'] . ' ORDER BY id', ARRAY_A );
	}

	/**
	 * Autoload flag of an option.
	 *
	 * @param string $name Option.
	 */
	private function autoload( string $name ): string {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $name ) );
	}

	/**
	 * Store settings and drop the per-request cache.
	 *
	 * @param array<string, mixed> $settings Settings.
	 */
	private function settings( array $settings ): void {
		update_option( 'bot_insight_settings', $settings );
		$prop = new \ReflectionProperty( Plugin::class, 'settings' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
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
		$wpdb->query( "DELETE p, m FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE p.post_type = 'shop_order'" );
		wp_cache_flush();
		$dirs = glob( wp_upload_dir( null, false )['basedir'] . '/bot-insight-*', GLOB_ONLYDIR );
		foreach ( is_array( $dirs ) ? $dirs : array() as $dir ) {
			if ( FileBuffer::isOwnDir( $dir ) ) {
				( new FileBuffer( $dir ) )->removeDir();
			}
		}
		$this->dir = '';
	}
}
