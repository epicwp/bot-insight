<?php
/**
 * Folder against a real database and real buffer files.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Tests\Integration;

use BotInsight\Bots\BotList;
use BotInsight\Bots\Classifier;
use BotInsight\Bots\UaParser;
use BotInsight\Bots\Verifier;
use BotInsight\Buffer\FileBuffer;
use BotInsight\Capture\Record;
use BotInsight\Fold\Aggregator;
use BotInsight\Fold\Enricher;
use BotInsight\Fold\Folder;
use BotInsight\Fold\SampleRate;
use BotInsight\Fold\UniqueTracker;
use BotInsight\Geo\GeoLookup;
use BotInsight\Net\IpRanges;
use BotInsight\Plugin;
use BotInsight\Storage\Dictionary;
use BotInsight\Storage\Salt;
use BotInsight\Storage\Schema;

/**
 * The folder commits its own transactions, so these tests use real tables and clean up after
 * themselves (the harness rollback cannot undo a commit).
 */
final class FolderTest extends \WP_UnitTestCase {

	private const CHROME    = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';
	private const GOOGLEBOT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

	/**
	 * Buffer directory.
	 *
	 * @var string
	 */
	private $dir = '';

	/**
	 * Stats passed to the bot_insight_folded action.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $fired = array();

	public function set_up(): void {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$this->cleanSlate();
		Schema::install();
		$this->dir = Plugin::ensureBufferDir();
		$this->assertNotSame( '', $this->dir );
		$this->fired = array();
		add_action(
			'bot_insight_folded',
			function ( $stats ) {
				$this->fired[] = $stats;
			}
		);
	}

	public function tear_down(): void {
		parent::tear_down();
		$this->cleanSlate();
		$GLOBALS['wpdb']->query( 'COMMIT' );
	}

	public function test_fold_writes_hourly_and_sample_rows_and_deletes_files(): void {
		$now  = $this->now();
		$hour = $this->hourStart( $now ) - 7200;
		$this->write( $hour + 60, array( $this->rec( $hour + 61, '198.51.100.1' ), $this->rec( $hour + 62, '198.51.100.2', self::GOOGLEBOT ) ) );
		$this->write( $hour + 120, array( $this->rec( $hour + 121, '198.51.100.1' ), $this->rec( $hour + 122, '203.0.113.9' ), $this->rec( $hour + 123, '203.0.113.9' ) ) );

		$stats = $this->folder()->run( $now );

		$this->assertSame(
			array(
				'files'        => 2,
				'records'      => 5,
				'skipped_lock' => false,
			),
			$stats
		);
		$this->assertSame( '5', $this->scalar( 'SELECT SUM(requests) FROM %t' ) );
		$this->assertSame( '5', $this->scalar( 'SELECT COUNT(*) FROM %s', 'sample' ) );
		$this->assertSame( '500', $this->scalar( 'SELECT SUM(php_ms_sum) FROM %t' ) );
		$this->assertSame( array(), ( new FileBuffer( $this->dir ) )->foldable( $now + 3600 ) );
		$this->assertSame( array(), get_option( 'bot_insight_folded_files' ) );
		$this->assertSame( array(), glob( $this->dir . '/folding-*' ) );
		$this->assertTrue( $this->lockIsFree() );
		$this->assertSame( array( $stats ), $this->fired );

		$status = get_option( 'bot_insight_fold_status' );
		$this->assertSame( $now, $status['last_run'] );
		$this->assertSame( '', $status['last_error'] );
		$this->assertSame( 2, $status['files'] );
		$this->assertSame( 5, $status['records'] );
	}

	public function test_sample_row_maps_record_fields_onto_columns(): void {
		global $wpdb;
		$now  = $this->now();
		$hour = $this->hourStart( $now ) - 7200;
		$this->write(
			$hour,
			array(
				$this->rec(
					$hour + 5,
					'198.51.100.7',
					self::GOOGLEBOT,
					array(
						'p'   => 'orderby,page',
						'u'   => '/shop/{slug}/',
						'ref' => 'search',
						'dp'  => '2',
						'hf'  => 3,
						'se'  => 1,
						'pc'  => 1,
						'st'  => 404,
					)
				),
			)
		);

		$this->folder()->run( $now );

		$table = Schema::tables()['sample'];
		$row   = $wpdb->get_row( "SELECT * FROM $table", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$dict  = new Dictionary( $wpdb );
		$this->assertSame( (string) ( $hour + 5 ), $row['ts'] );
		$this->assertSame( (string) $hour, $row['hour'] );
		$this->assertSame( '100', $row['php_ms'] );
		$this->assertSame( '404', $row['status'] );
		$this->assertSame( '4xx', $row['status_class'] );
		$this->assertSame( 'GET', $row['method'] );
		$this->assertSame( 'product', $row['route'] );
		$this->assertSame( (string) $dict->id( 'param_sig', 'orderby,page' ), $row['param_sig_id'] );
		$this->assertSame( (string) $dict->id( 'url_pattern', '/shop/{slug}/' ), $row['url_pattern_id'] );
		$this->assertSame( (string) $dict->id( 'bot', 'googlebot' ), $row['bot_id'] );
		$this->assertSame( (string) $dict->id( 'ua_family', 'Googlebot' ), $row['ua_family_id'] );
		$this->assertSame( 'search', $row['referer_type'] );
		$this->assertSame( '2', $row['depth'] );
		$this->assertSame( '3', $row['header_flags'] );
		$this->assertSame( '1', $row['session_created'] );
		$this->assertSame( '1', $row['payment_callback'] );
		$this->assertSame( '7', $row['self_us'] );
		$this->assertSame( 16, strlen( $row['ip_hash'] ) );
		$this->assertSame( '198.51.100.0', $row['ip_prefix'] );
		$this->assertSame( 'search', $row['client_class'] );
		$this->assertSame( '1', $row['verified'] );
	}

	public function test_lock_held_by_another_connection_skips_the_run_and_changes_nothing(): void {
		$now   = $this->now();
		$hour  = $this->hourStart( $now ) - 7200;
		$file  = $this->write( $hour, array( $this->rec( $hour + 1, '198.51.100.1' ) ) );
		$other = $this->otherConnection();
		$this->assertSame( '1', $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', Folder::lockName( $other ) ) ) );

		$stats = $this->folder()->run( $now );

		$other->query( $other->prepare( 'SELECT RELEASE_LOCK(%s)', Folder::lockName( $other ) ) );
		$other->close();
		$this->assertTrue( $stats['skipped_lock'] );
		$this->assertSame( 0, $stats['files'] );
		$this->assertSame( '0', $this->scalar( 'SELECT COUNT(*) FROM %t' ) );
		$this->assertFileExists( $file );
		$this->assertSame( array(), $this->fired );
	}

	public function test_lock_of_a_dead_connection_is_released_by_the_server(): void {
		$now   = $this->now();
		$hour  = $this->hourStart( $now ) - 7200;
		$file  = $this->write( $hour, array( $this->rec( $hour + 1, '198.51.100.1' ) ) );
		$other = $this->otherConnection();
		$this->assertSame( '1', $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', Folder::lockName( $other ) ) ) );
		$other->close();

		$stats = $this->folder()->run( $now );

		$this->assertFalse( $stats['skipped_lock'] );
		$this->assertSame( 1, $stats['files'] );
		$this->assertFileDoesNotExist( $file );
		$this->assertTrue( $this->lockIsFree() );
	}

	public function test_file_committed_by_an_interleaved_run_is_not_counted_again(): void {
		$now     = $this->now();
		$hour    = $this->hourStart( $now ) - 7200;
		$file    = $this->write( $hour, array( $this->rec( $hour + 1, '192.0.2.10', self::GOOGLEBOT ) ) );
		$working = dirname( $file ) . '/folding-' . basename( $file );
		// The DNS check runs while this run enriches, i.e. after it read the folded list and
		// before its transaction: simulate a second run committing the same file right then.
		$reverse = static function () use ( $working ) {
			update_option( 'bot_insight_folded_files', array( basename( $working ) ), false );
			return false;
		};

		$stats = $this->folder( null, $reverse )->run( $now );

		$this->assertSame( 0, $stats['files'] );
		$this->assertSame( 0, $stats['records'] );
		$this->assertSame( '0', $this->scalar( 'SELECT COUNT(*) FROM %t' ) );
		$this->assertSame( '0', $this->scalar( 'SELECT COUNT(*) FROM %s', 'sample' ) );
		$this->assertSame( array( basename( $working ) ), get_option( 'bot_insight_folded_files' ) );
	}

	public function test_file_removed_by_an_interleaved_run_is_skipped(): void {
		$now     = $this->now();
		$hour    = $this->hourStart( $now ) - 7200;
		$file    = $this->write( $hour, array( $this->rec( $hour + 1, '192.0.2.10', self::GOOGLEBOT ) ) );
		$working = dirname( $file ) . '/folding-' . basename( $file );
		$reverse = static function () use ( $working ) {
			unlink( $working ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return false;
		};

		$stats = $this->folder( null, $reverse )->run( $now );

		$this->assertSame( 0, $stats['files'] );
		$this->assertSame( '0', $this->scalar( 'SELECT COUNT(*) FROM %t' ) );
	}

	public function test_file_committed_before_a_crash_is_deleted_without_adding_rows(): void {
		$now     = $this->now();
		$hour    = $this->hourStart( $now ) - 7200;
		$file    = $this->write( $hour, array( $this->rec( $hour + 1, '198.51.100.1' ), $this->rec( $hour + 2, '198.51.100.2' ) ) );
		$working = dirname( $file ) . '/folding-' . basename( $file );
		rename( $file, $working ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		update_option( 'bot_insight_folded_files', array( basename( $working ) ), false );

		$stats = $this->folder()->run( $now );

		$this->assertSame( 0, $stats['records'] );
		$this->assertFileDoesNotExist( $working );
		$this->assertSame( '0', $this->scalar( 'SELECT COUNT(*) FROM %t' ) );
		$this->assertSame( array(), get_option( 'bot_insight_folded_files' ) );
	}

	public function test_claimed_file_left_by_a_crash_is_folded_before_a_new_file_of_that_name(): void {
		$now     = $this->now();
		$hour    = $this->hourStart( $now ) - 7200;
		$file    = $this->write( $hour, array( $this->rec( $hour + 1, '198.51.100.1' ), $this->rec( $hour + 2, '198.51.100.2' ) ) );
		$working = dirname( $file ) . '/folding-' . basename( $file );
		rename( $file, $working ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		// A late request of that minute created the original name again.
		$this->write( $hour, array( $this->rec( $hour + 3, '198.51.100.3' ) ) );

		$stats = $this->folder()->run( $now );

		$this->assertSame( 2, $stats['files'] );
		$this->assertSame( '3', $this->scalar( 'SELECT SUM(requests) FROM %t' ) );
		$this->assertSame( array(), glob( $this->dir . '/*buffer-*' ) );
	}

	public function test_failure_after_commit_never_double_counts(): void {
		$now     = $this->now();
		$hour    = $this->hourStart( $now ) - 7200;
		$file    = $this->write( $hour, array( $this->rec( $hour + 1, '198.51.100.1' ), $this->rec( $hour + 2, '198.51.100.2' ) ) );
		$working = dirname( $file ) . '/folding-' . basename( $file );

		// The tracker cannot write: the run fails after the commit, before the file delete.
		$stats = $this->folder( $this->dir . '/missing' )->run( $now );

		$this->assertSame( 0, $stats['files'] );
		$this->assertFileExists( $working );
		$this->assertSame( '2', $this->scalar( 'SELECT SUM(requests) FROM %t' ) );
		$this->assertSame( array( basename( $working ) ), get_option( 'bot_insight_folded_files' ) );
		$this->assertStringContainsString( 'unique tracker', get_option( 'bot_insight_fold_status' )['last_error'] );

		$this->folder()->run( $now );

		$this->assertFileDoesNotExist( $working );
		$this->assertSame( '2', $this->scalar( 'SELECT SUM(requests) FROM %t' ) );
		$this->assertSame( '2', $this->scalar( 'SELECT COUNT(*) FROM %s', 'sample' ) );
		$this->assertSame( array(), get_option( 'bot_insight_folded_files' ) );
	}

	public function test_file_that_cannot_be_deleted_keeps_its_name_and_is_never_folded_again(): void {
		$now     = $this->now();
		$hour    = $this->hourStart( $now ) - 7200;
		$file    = $this->write( $hour, array( $this->rec( $hour + 1, '198.51.100.1' ) ) );
		$working = dirname( $file ) . '/folding-' . basename( $file );
		$noop    = static function (): void {};

		$this->folder( null, null, $noop )->run( $now );

		$this->assertFileExists( $working );
		$this->assertSame( array( basename( $working ) ), get_option( 'bot_insight_folded_files' ) );
		$this->assertStringContainsString( 'Could not delete', get_option( 'bot_insight_fold_status' )['last_error'] );

		$this->folder( null, null, $noop )->run( $now );
		$this->folder( null, null, $noop )->run( $now );

		$this->assertSame( '1', $this->scalar( 'SELECT SUM(requests) FROM %t' ) );
		$this->assertSame( array( basename( $working ) ), get_option( 'bot_insight_folded_files' ) );

		$this->folder()->run( $now );

		$this->assertFileDoesNotExist( $working );
		$this->assertSame( '1', $this->scalar( 'SELECT SUM(requests) FROM %t' ) );
		$this->assertSame( array(), get_option( 'bot_insight_folded_files' ) );
		$this->assertSame( '', get_option( 'bot_insight_fold_status' )['last_error'] );
	}

	public function test_poison_file_is_quarantined_after_three_failures_and_the_run_goes_on(): void {
		global $wpdb;
		$now    = $this->now();
		$hour   = $this->hourStart( $now ) - 7200;
		$first  = $this->write( $hour, array( $this->rec( $hour + 1, '198.51.100.1' ) ) );
		$second = $this->write( $hour + 60, array( $this->rec( $hour + 61, '198.51.100.2' ) ) );
		$wpdb->query( 'DROP TABLE ' . Schema::tables()['sample'] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$suppress = $wpdb->suppress_errors( true );

		$this->folder()->run( $now );
		$this->folder()->run( $now );
		$this->assertSame( array( 'folding-' . basename( $first ) => 2 ), get_option( 'bot_insight_fold_failures' ) );
		$this->assertFileExists( $second );

		$this->folder()->run( $now );
		$wpdb->suppress_errors( $suppress );

		$this->assertFileExists( $this->dir . '/quarantine-' . basename( $first ) );
		$this->assertFileDoesNotExist( $this->dir . '/folding-' . basename( $first ) );
		$this->assertStringContainsString( 'Quarantined quarantine-' . basename( $first ), get_option( 'bot_insight_fold_status' )['last_error'] );
		// The run went on to the next file, which failed once.
		$this->assertSame( array( 'folding-' . basename( $second ) => 1 ), get_option( 'bot_insight_fold_failures' ) );
		$this->assertSame( array(), ( new FileBuffer( $this->dir ) )->foldable( $now ) );
		$this->assertSame( '0', $this->scalar( 'SELECT COUNT(*) FROM %t' ) );
	}

	public function test_old_quarantined_files_are_purged(): void {
		$now = $this->now();
		$old = $this->dir . '/quarantine-buffer-202001010000-abc-1.php';
		$new = $this->dir . '/quarantine-buffer-202001010001-abc-1.php';
		file_put_contents( $old, FileBuffer::HEADER ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		file_put_contents( $new, FileBuffer::HEADER ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		touch( $old, $now - 8 * DAY_IN_SECONDS );
		touch( $new, $now - 6 * DAY_IN_SECONDS );

		$this->folder()->run( $now );

		$this->assertFileDoesNotExist( $old );
		$this->assertFileExists( $new );
	}

	public function test_names_of_files_that_are_gone_are_pruned(): void {
		update_option( 'bot_insight_folded_files', array( 'folding-buffer-202001010000-abc-1.php' ), false );

		$this->folder()->run( $this->now() );

		$this->assertSame( array(), get_option( 'bot_insight_folded_files' ) );
	}

	public function test_no_raw_ip_or_user_agent_is_stored(): void {
		global $wpdb;
		$now  = $this->now();
		$hour = $this->hourStart( $now ) - 7200;
		$recs = array();
		for ( $i = 1; $i <= 20; $i++ ) {
			$recs[] = $this->rec( $hour + $i, '198.51.100.' . $i, 0 === $i % 2 ? self::GOOGLEBOT : self::CHROME );
		}
		$this->write( $hour, $recs );
		$this->write( $now - 60, array( $this->rec( $now - 60, '198.51.100.99' ) ) );

		$this->folder()->run( $now );

		$haystack = '';
		foreach ( array( 'hourly', 'daily', 'dict', 'sample' ) as $short ) {
			$table = Schema::tables()[ $short ];
			foreach ( (array) $wpdb->get_results( "SELECT * FROM $table", ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$haystack .= implode( "\n", array_map( 'strval', $row ) ) . "\n";
			}
		}
		foreach ( (array) $wpdb->get_col( "SELECT option_value FROM $wpdb->options WHERE option_name LIKE 'bot\\_insight\\_%'" ) as $value ) {
			$haystack .= $value . "\n";
		}
		foreach ( (array) glob( $this->dir . '/uniq-*.php' ) as $file ) {
			$haystack .= (string) file_get_contents( (string) $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		$this->assertNotSame( '', $haystack );
		// ip_prefix keeps the /24 network ('198.51.100.0') by design; no host address may appear.
		$this->assertDoesNotMatchRegularExpression( '/198\.51\.100\.(?!0(?!\d))\d/', $haystack );
		$this->assertStringContainsString( '198.51.100.0', $haystack );
		$this->assertStringNotContainsString( 'Mozilla', $haystack );
	}

	public function test_closed_hour_gets_unique_counts(): void {
		global $wpdb;
		$now  = $this->now();
		$hour = $this->hourStart( $now ) - 7200;
		$this->write(
			$hour + 600,
			array(
				$this->rec( $hour + 601, '198.51.100.1' ),
				$this->rec( $hour + 602, '198.51.100.2' ),
				$this->rec( $hour + 603, '198.51.100.2' ),
				$this->rec( $hour + 604, '203.0.113.5' ),
			)
		);

		$this->folder()->run( $now );

		$table = Schema::tables()['hourly'];
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT requests, unique_ips, ips_seen_once, top_ip_share FROM $table WHERE hour = %d", $hour ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame(
			array(
				array(
					'requests'      => '4',
					'unique_ips'    => '3',
					'ips_seen_once' => '2',
					'top_ip_share'  => '10000',
				),
			),
			$rows
		);
		$this->assertFileDoesNotExist( $this->dir . '/uniq-' . gmdate( 'YmdH', $hour ) . '.php' );
	}

	public function test_open_hour_keeps_its_unique_file(): void {
		$now  = $this->hourStart( $this->now() ) + 1800;
		$hour = $this->hourStart( $now );
		$this->write( $hour + 60, array( $this->rec( $hour + 61, '198.51.100.1' ) ) );

		$this->folder()->run( $now );

		$this->assertSame( '0', $this->scalar( 'SELECT SUM(unique_ips) FROM %t' ) );
		$this->assertFileExists( $this->dir . '/uniq-' . gmdate( 'YmdH', $hour ) . '.php' );
	}

	public function test_hour_with_unfolded_files_left_is_not_closed(): void {
		$now  = $this->now();
		$hour = $this->hourStart( $now ) - 7200;
		// First run while the hour is still current: its uniques stay open.
		$this->write( $hour + 60, array( $this->rec( $hour + 61, '198.51.100.1' ) ) );
		$this->folder()->run( $hour + 180 );
		$this->assertFileExists( $this->dir . '/uniq-' . gmdate( 'YmdH', $hour ) . '.php' );

		// Later a run with no time budget folds nothing; a file of the hour is still waiting.
		$second = $this->write( $hour + 120, array( $this->rec( $hour + 121, '198.51.100.2' ) ) );
		$stats  = $this->folder()->run( $now, 0 );

		$this->assertSame( 0, $stats['files'] );
		$this->assertFileExists( $second );
		$this->assertSame( '0', $this->scalar( 'SELECT SUM(unique_ips) FROM %t' ) );
		$this->assertFileExists( $this->dir . '/uniq-' . gmdate( 'YmdH', $hour ) . '.php' );

		// With the file folded, the hour closes with both IPs.
		$this->folder()->run( $now );
		$this->assertSame( '2', $this->scalar( 'SELECT SUM(unique_ips) FROM %t' ) );
	}

	public function test_closed_day_gets_daily_rows(): void {
		global $wpdb;
		$today = (int) ( floor( $this->now() / 86400 ) * 86400 );
		$now   = $today + 1800;
		$day   = $today - 86400;
		$this->write(
			$day + 36000,
			array(
				$this->rec( $day + 36001, '198.51.100.1' ),
				$this->rec( $day + 36002, '198.51.100.2' ),
				$this->rec( $day + 36003, '198.51.100.2', self::GOOGLEBOT ),
			)
		);
		$this->write( $day + 39600, array( $this->rec( $day + 39601, '198.51.100.1' ) ) );

		$this->folder()->run( $now );

		$table = Schema::tables()['daily'];
		$rows  = $wpdb->get_results( "SELECT * FROM $table ORDER BY client_class", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertCount( 2, $rows );
		$people = $rows[0]['client_class'] === 'people' ? $rows[0] : $rows[1];
		$bots   = $rows[0]['client_class'] === 'people' ? $rows[1] : $rows[0];
		$this->assertSame( gmdate( 'Y-m-d', $day ), $people['day'] );
		$this->assertSame( '3', $people['requests'] );
		$this->assertSame( '300', $people['php_ms_sum'] );
		$this->assertSame( '2', $people['unique_ips'] );
		$this->assertSame( '1', $people['ips_seen_once'] );
		$this->assertSame( '1', $bots['requests'] );
		$this->assertSame( '1', $bots['unique_ips'] );
		$this->assertSame( array(), glob( $this->dir . '/uniq-' . gmdate( 'Ymd', $day ) . '*.php' ) );
	}

	public function test_reclosing_an_hour_never_lowers_its_uniques(): void {
		$now  = $this->now();
		$hour = $this->hourStart( $now ) - 7200;
		$this->write( $hour, array( $this->rec( $hour + 1, '198.51.100.1' ), $this->rec( $hour + 2, '198.51.100.2' ), $this->rec( $hour + 3, '198.51.100.3' ) ) );
		$this->folder()->run( $now );
		$this->assertSame( '3', $this->scalar( 'SELECT SUM(unique_ips) FROM %t' ) );

		// A late file for the closed hour carries only one IP.
		$this->write( $hour + 60, array( $this->rec( $hour + 61, '198.51.100.9' ) ) );
		$this->folder()->run( $now );

		$this->assertSame( '4', $this->scalar( 'SELECT SUM(requests) FROM %t' ) );
		$this->assertSame( '3', $this->scalar( 'SELECT SUM(unique_ips) FROM %t' ) );
		$this->assertSame( '10000', $this->scalar( 'SELECT MAX(top_ip_share) FROM %t' ) );
	}

	public function test_day_without_tracker_data_still_gets_daily_rows(): void {
		global $wpdb;
		$today = (int) ( floor( $this->now() / 86400 ) * 86400 );
		$day   = $today - 86400;
		$this->write( $day + 36000, array( $this->rec( $day + 36001, '198.51.100.1' ), $this->rec( $day + 36002, '198.51.100.2' ) ) );
		// Fold while that day is still current, then lose its unique data.
		$this->folder()->run( $day + 40000 );
		foreach ( (array) glob( $this->dir . '/uniq-*.php' ) as $uniq ) {
			unlink( (string) $uniq ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		$this->folder()->run( $today + 1800 );

		$table = Schema::tables()['daily'];
		$rows  = $wpdb->get_results( "SELECT day, client_class, requests, unique_ips FROM $table", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame(
			array(
				array(
					'day'          => gmdate( 'Y-m-d', $day ),
					'client_class' => 'people',
					'requests'     => '2',
					'unique_ips'   => '0',
				),
			),
			$rows
		);
		$this->assertSame( gmdate( 'Ymd', $day ), get_option( 'bot_insight_last_closed_day' ) );

		// The closed day is not rescanned: deleting its row stays deleted.
		$wpdb->query( "DELETE FROM $table" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->folder()->run( $today + 2400 );
		$this->assertSame( '0', $this->scalar( 'SELECT COUNT(*) FROM %s', 'daily' ) );
	}

	public function test_overflow_guard_holds_across_runs(): void {
		global $wpdb;
		$now  = $this->now();
		$hour = $this->hourStart( $now ) - 7200;
		$recs = array();
		for ( $i = 0; $i < 150; $i++ ) {
			$recs[] = $this->rec( $hour + 1, '198.51.100.1', self::CHROME, array( 'r' => 'r' . $i ) );
		}
		$this->write( $hour, $recs );
		$this->folder()->run( $now );

		$recs = array();
		for ( $i = 150; $i < 300; $i++ ) {
			$recs[] = $this->rec( $hour + 61, '198.51.100.1', self::CHROME, array( 'r' => 'r' . $i ) );
		}
		$this->write( $hour + 60, $recs );
		$this->folder()->run( $now );

		$table = Schema::tables()['hourly'];
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$named = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE hour = %d AND route <> 'other'", $hour ) );
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE hour = %d", $hour ) );
		// phpcs:enable
		$this->assertSame( Aggregator::MAX_PER_HOUR, $named );
		$this->assertLessThanOrEqual( Aggregator::MAX_PER_HOUR + 2, $total );
		$this->assertSame( '300', $this->scalar( 'SELECT SUM(requests) FROM %t' ) );
	}

	public function test_failure_rolls_back_keeps_the_file_and_records_the_error(): void {
		global $wpdb;
		$now  = $this->now();
		$hour = $this->hourStart( $now ) - 7200;
		$file = $this->write( $hour, array( $this->rec( $hour + 1, '198.51.100.1' ) ) );
		$wpdb->query( 'DROP TABLE ' . Schema::tables()['sample'] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$suppress = $wpdb->suppress_errors( true );
		$stats    = $this->folder()->run( $now );
		$wpdb->suppress_errors( $suppress );

		$this->assertSame( 0, $stats['files'] );
		$this->assertFileExists( dirname( $file ) . '/folding-' . basename( $file ) );
		$this->assertSame( '0', $this->scalar( 'SELECT COUNT(*) FROM %t' ) );
		$this->assertSame( array(), get_option( 'bot_insight_folded_files' ) );
		$this->assertTrue( $this->lockIsFree() );
		$this->assertNotSame( '', get_option( 'bot_insight_fold_status' )['last_error'] );
		$this->assertCount( 1, $this->fired );
	}

	public function test_sample_rate_from_option(): void {
		$now  = $this->now();
		$hour = $this->hourStart( $now ) - 7200;
		$recs = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$recs[] = $this->rec( $hour + $i, '198.51.100.1' );
		}
		$this->write( $hour, $recs );
		update_option( 'bot_insight_sample_rate', 100000000, false );

		$this->folder()->run( $now );

		$this->assertSame( '5', $this->scalar( 'SELECT SUM(requests) FROM %t' ) );
		$this->assertSame( '0', $this->scalar( 'SELECT COUNT(*) FROM %s', 'sample' ) );
	}

	public function test_sample_rows_carry_the_rate_they_were_taken_at(): void {
		$now  = $this->now();
		$hour = $this->hourStart( $now ) - 7200;
		$this->write( $hour, array( $this->rec( $hour + 1, '198.51.100.1' ), $this->rec( $hour + 2, '198.51.100.2' ) ) );

		$this->folder()->run( $now );

		$this->assertSame( '2', $this->scalar( 'SELECT COUNT(*) FROM %s WHERE weight = 1', 'sample' ) );
	}

	public function test_busy_day_raises_the_rate_within_the_day(): void {
		global $wpdb;
		$day = $this->now() - $this->now() % DAY_IN_SECONDS;
		$now = $day + 43200;
		// 250,000 requests in the first half of the day: on course for 1 in 100.
		$wpdb->insert(
			Schema::tables()['hourly'],
			array(
				'hour'     => $day + 3600,
				'dims'     => md5( 'busy', true ),
				'requests' => 250000,
			)
		);
		$recs = array();
		for ( $i = 0; $i < 300; $i++ ) {
			$recs[] = $this->rec( $now - 600 + $i % 50, '198.51.100.1' );
		}
		$this->write( $now - 600, $recs );

		$this->folder()->run( $now );

		$this->assertSame(
			array(
				'day'  => gmdate( 'Ymd', $now ),
				'rate' => 100,
			),
			get_option( 'bot_insight_sample_rate_day' )
		);
		$this->assertSame( '0', $this->scalar( 'SELECT COUNT(*) FROM %s WHERE weight <> 100', 'sample' ) );
		// The start rate for the next day is still the daily job's.
		$this->assertFalse( get_option( 'bot_insight_sample_rate' ) );
	}

	public function test_rate_never_drops_within_the_day(): void {
		$now  = $this->now();
		$hour = $this->hourStart( $now ) - 60;
		update_option(
			'bot_insight_sample_rate_day',
			array(
				'day'  => gmdate( 'Ymd', $now ),
				'rate' => 100000000,
			),
			false
		);
		$this->write( $hour, array( $this->rec( $hour + 1, '198.51.100.1' ) ) );

		$this->folder()->run( $now );

		$this->assertSame( SampleRate::MAX, get_option( 'bot_insight_sample_rate_day' )['rate'] );
		$this->assertSame( '0', $this->scalar( 'SELECT COUNT(*) FROM %s WHERE weight <> ' . SampleRate::MAX, 'sample' ) );
	}

	/**
	 * Folder with a fake-DNS verifier and the fixture bot list.
	 *
	 * @param string|null   $tracker_dir Unique tracker directory (default: the buffer dir).
	 * @param callable|null $reverse     Reverse DNS callable (default: always fails).
	 * @param callable|null $unlink      File delete callable (default: the real one).
	 */
	private function folder( ?string $tracker_dir = null, ?callable $reverse = null, ?callable $unlink = null ): Folder {
		global $wpdb;
		$root     = dirname( __DIR__ );
		$list     = BotList::fromFile( $root . '/fixtures/bots.json' );
		$verifier = new Verifier(
			new IpRanges( $root . '/fixtures/ranges' ),
			$reverse ?? static function () {
				return false;
			},
			static function () {
				return false;
			},
			static function () {
				return null;
			},
			static function (): void {},
			50
		);
		$enricher = new Enricher(
			new UaParser( $list, $list->currentMajors() ),
			new Classifier(),
			$verifier,
			new Salt(
				'get_option',
				static function ( $name, $value ) {
					return update_option( $name, $value, false );
				}
			),
			new GeoLookup( null, null, array() )
		);
		return new Folder(
			new FileBuffer( $this->dir ),
			$enricher,
			new Aggregator(),
			new Dictionary( $wpdb ),
			new UniqueTracker( $tracker_dir ?? $this->dir ),
			$wpdb,
			$unlink
		);
	}

	/**
	 * A record.
	 *
	 * @param int                  $ts   Time.
	 * @param string               $ip   Documentation-range IP.
	 * @param string               $ua   User agent.
	 * @param array<string, mixed> $over Overrides.
	 * @return array<string, mixed>
	 */
	private function rec( int $ts, string $ip, string $ua = self::CHROME, array $over = array() ): array {
		return array_merge(
			array(
				'ts'   => $ts,
				'ms'   => 100.0,
				'cpu'  => 50.0,
				'mem'  => 4096,
				'dbq'  => 12,
				'st'   => 200,
				'unc'  => 'none',
				'm'    => 'GET',
				'r'    => 'product',
				'p'    => '',
				'dp'   => '1',
				'lg'   => '',
				'u'    => '/product/{slug}/',
				'ref'  => 'none',
				'ua'   => $ua,
				'ip'   => $ip,
				'ips'  => 'remote_addr',
				'cdn'  => 0,
				'cc'   => '',
				'hf'   => 3,
				'js'   => 1,
				'se'   => 0,
				'ca'   => 0,
				'or'   => 0,
				'pc'   => 0,
				'self' => 7,
			),
			$over
		);
	}

	/**
	 * Write records into the buffer file for the minute of `$minute`.
	 *
	 * @param int                               $minute Any time in the minute.
	 * @param array<int, array<string, mixed>>  $records Records.
	 * @return string File path.
	 */
	private function write( int $minute, array $records ): string {
		$buffer = new FileBuffer( $this->dir );
		foreach ( $records as $record ) {
			$this->assertTrue( $buffer->append( Record::encode( $record ), $minute ) );
		}
		$files = glob( $this->dir . '/buffer-' . gmdate( 'YmdHi', $minute ) . '-*.php' );
		$this->assertCount( 1, $files );
		return $files[0];
	}

	/**
	 * Single value from a query on a plugin table: `%t` is the hourly table, `%s` the table named
	 * by `$short`.
	 *
	 * @param string $sql   Query.
	 * @param string $short Short table name for `%s`.
	 * @return string|null
	 */
	private function scalar( string $sql, string $short = 'hourly' ) {
		global $wpdb;
		$tables = Schema::tables();
		$sql    = str_replace( array( '%t', '%s' ), array( $tables['hourly'], $tables[ $short ] ), $sql );
		$value  = $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return null === $value ? '0' : $value;
	}

	/**
	 * A second database connection.
	 */
	private function otherConnection(): \wpdb {
		global $wpdb;
		$other = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$other->set_prefix( $wpdb->prefix );
		return $other;
	}

	/**
	 * Whether the fold lock is free.
	 */
	private function lockIsFree(): bool {
		global $wpdb;
		return '1' === $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', Folder::lockName( $wpdb ) ) );
	}

	private function now(): int {
		return time();
	}

	private function hourStart( int $time ): int {
		return $time - $time % 3600;
	}

	/**
	 * Drop the plugin's tables, options, scheduled events and buffer dirs.
	 */
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
		$dirs = glob( wp_upload_dir( null, false )['basedir'] . '/bot-insight-*', GLOB_ONLYDIR );
		foreach ( is_array( $dirs ) ? $dirs : array() as $dir ) {
			if ( FileBuffer::isOwnDir( $dir ) ) {
				( new FileBuffer( $dir ) )->removeDir();
			}
		}
		$this->dir = '';
	}
}
