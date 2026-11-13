<?php
/**
 * The daily maintenance job.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Site;

use BotInsight\Buffer\FileBuffer;
use BotInsight\Fold\Folder;
use BotInsight\Fold\SampleRate;
use BotInsight\Geo\GeoUpdater;
use BotInsight\Net\IpRanges;
use BotInsight\Net\RangeUpdater;
use BotInsight\Plugin;
use BotInsight\Report\Reports;
use BotInsight\Storage\Schema;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages go to an option, never to HTML. The plugin's own tables; names come from Schema::tables(), values go through prepare().

/**
 * Runs once a day (00:10 UTC): language option, snapshot of yesterday with change events,
 * retention, dictionary prune, sample rate, opt-in range refresh and opt-in GeoIP refresh. Every
 * step runs on its own; a failing step is recorded in `bot_insight_daily_status` and the others
 * still run.
 * Idempotent per day.
 */
final class Daily {

	public const STATUS_OPTION = 'bot_insight_daily_status';

	public const LOAD_OPTION = 'bot_insight_load';

	public const SAMPLE_RATE_OPTION = 'bot_insight_sample_rate';

	/**
	 * Rows deleted per statement.
	 */
	private const BATCH = 5000;

	/**
	 * Days load samples are kept in their option.
	 */
	private const LOAD_DAYS = 2;

	/**
	 * Days after which refreshed ranges are fetched again.
	 */
	private const RANGE_DAYS = 7;

	/**
	 * Database handle.
	 *
	 * @var \wpdb
	 */
	private $wpdb;

	/**
	 * Range updater.
	 *
	 * @var RangeUpdater
	 */
	private $ranges;

	/**
	 * GeoIP updater.
	 *
	 * @var GeoUpdater
	 */
	private $geo;

	/**
	 * Constructor.
	 *
	 * @param \wpdb             $wpdb   Database handle.
	 * @param RangeUpdater|null $ranges Range updater.
	 * @param GeoUpdater|null   $geo    GeoIP updater.
	 */
	public function __construct( \wpdb $wpdb, ?RangeUpdater $ranges = null, ?GeoUpdater $geo = null ) {
		$this->wpdb   = $wpdb;
		$this->ranges = $ranges ?? new RangeUpdater();
		$this->geo    = $geo ?? new GeoUpdater();
	}

	/**
	 * Production wiring.
	 */
	public static function create(): self {
		global $wpdb;
		return new self( $wpdb );
	}

	/**
	 * Run every step. Never throws.
	 *
	 * @param int $now Unix time.
	 */
	public function run( int $now ): void {
		$day    = $now - $now % DAY_IN_SECONDS - DAY_IN_SECONDS;
		$steps  = array(
			'langs'       => function () {
				Langs::update();
				return true;
			},
			'snapshot'    => function () use ( $now, $day ) {
				$this->snapshot( $now, $day );
				return true;
			},
			'retention'   => function () use ( $now ) {
				$this->retention( $now );
				return true;
			},
			'dict_prune'  => function () {
				return $this->pruneDictionary();
			},
			'sample_rate' => function () use ( $day ) {
				return $this->sampleRate( $day );
			},
			'ranges'      => function () use ( $now ) {
				return $this->refreshRanges( $now );
			},
			'geo'         => function () use ( $now ) {
				return $this->refreshGeo( $now );
			},
		);
		$status = array(
			'last_run' => $now,
			'day'      => gmdate( 'Y-m-d', $day ),
			'steps'    => array(),
			'errors'   => array(),
		);
		foreach ( $steps as $name => $step ) {
			try {
				$status['steps'][ $name ] = $step() ? 'ok' : 'skipped';
			} catch ( \Throwable $e ) {
				$status['steps'][ $name ]  = 'error';
				$status['errors'][ $name ] = substr( '' !== $e->getMessage() ? $e->getMessage() : get_class( $e ), 0, 500 );
			}
		}
		update_option( self::STATUS_OPTION, $status, false );
	}

	/**
	 * `bot_insight_folded`: store the 1-minute load average once per hour.
	 */
	public static function recordLoad(): void {
		if ( ! function_exists( 'sys_getloadavg' ) ) {
			return;
		}
		$load = sys_getloadavg();
		if ( is_array( $load ) && isset( $load[0] ) ) {
			self::addLoadSample( time(), (float) $load[0] );
		}
	}

	/**
	 * Add a load sample for the hour of a time, unless that hour has one. Prunes old samples.
	 *
	 * @param int   $now  Unix time.
	 * @param float $load Load average.
	 */
	public static function addLoadSample( int $now, float $load ): void {
		$hour    = $now - $now % HOUR_IN_SECONDS;
		$samples = self::loadSamples();
		if ( isset( $samples[ $hour ] ) ) {
			return;
		}
		$samples[ $hour ] = round( $load, 2 );
		update_option( self::LOAD_OPTION, self::pruneLoad( $samples, $now ), false );
	}

	/**
	 * Directory of the refreshed range lists ('' without a buffer directory).
	 */
	public static function rangesDir(): string {
		$base = get_option( 'bot_insight_buffer_dir', '' );
		return is_string( $base ) && '' !== $base ? rtrim( $base, '/\\' ) . '/ranges' : '';
	}

	/**
	 * Write yesterday's snapshot and, the first time that day is written, emit change events
	 * against the previous snapshot. Moves yesterday's load samples into it.
	 *
	 * @param int $now Unix time.
	 * @param int $day Start of the snapshot day.
	 * @throws \RuntimeException When the snapshot cannot be stored.
	 */
	private function snapshot( int $now, int $day ): void {
		$table = Schema::tables()['snapshot'];
		$date  = gmdate( 'Y-m-d', $day );
		$data  = Snapshot::collect( $day );
		$json  = wp_json_encode( $data );
		if ( ! is_string( $json ) ) {
			throw new \RuntimeException( 'Snapshot could not be encoded.' );
		}
		$exists   = null !== $this->wpdb->get_var( $this->wpdb->prepare( "SELECT 1 FROM {$table} WHERE day = %s", $date ) );
		$previous = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT data FROM {$table} WHERE day < %s ORDER BY day DESC LIMIT 1", $date ) );

		$ok = $this->wpdb->query( $this->wpdb->prepare( "INSERT INTO {$table} (day, data) VALUES (%s, %s) ON DUPLICATE KEY UPDATE data = VALUES(data)", $date, $json ) );
		if ( false === $ok ) {
			throw new \RuntimeException( 'Snapshot not stored: ' . $this->wpdb->last_error );
		}
		update_option( self::LOAD_OPTION, self::pruneLoad( self::loadSamples(), $now ), false );

		if ( $exists || ! is_string( $previous ) ) {
			return;
		}
		$old = json_decode( $previous, true );
		if ( ! is_array( $old ) ) {
			return;
		}
		if ( ( $old['robots']['hash'] ?? null ) !== $data['robots']['hash'] ) {
			Events::add( 'robots_changed', 'robots.txt', (string) wp_json_encode( array( 'from' => $old['robots']['hash'] ?? '', 'to' => $data['robots']['hash'] ) ), $now ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		}
		if ( ( $old['page_cache'] ?? null ) !== $data['page_cache'] ) {
			Events::add( 'page_cache_changed', 'page_cache', (string) wp_json_encode( array( 'from' => $old['page_cache'] ?? null, 'to' => $data['page_cache'] ) ), $now ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		}
		if ( ( $old['cdn'] ?? null ) !== $data['cdn'] ) {
			Events::add( 'cdn_changed', 'cdn', (string) wp_json_encode( array( 'from' => $old['cdn'] ?? '', 'to' => $data['cdn'] ) ), $now ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		}
	}

	/**
	 * Delete rows past their retention, in batches.
	 *
	 * @param int $now Unix time.
	 */
	private function retention( int $now ): void {
		$t      = Schema::tables();
		$days   = max( 1, (int) Plugin::settings()['retention_days'] );
		$cutoff = $now - $days * DAY_IN_SECONDS;
		$date   = gmdate( 'Y-m-d', $cutoff );
		$this->deleteBatched( "DELETE FROM {$t['hourly']} WHERE hour < %d LIMIT " . self::BATCH, $cutoff );
		$this->deleteBatched( "DELETE FROM {$t['daily']} WHERE day < %s LIMIT " . self::BATCH, $date );
		$this->deleteBatched( "DELETE FROM {$t['snapshot']} WHERE day < %s LIMIT " . self::BATCH, $date );
		$this->deleteBatched( "DELETE FROM {$t['events']} WHERE ts < %d LIMIT " . self::BATCH, $cutoff );
		$this->deleteBatched( "DELETE FROM {$t['sample']} WHERE ts < %d LIMIT " . self::BATCH, $now - Reports::SAMPLE_DAYS * DAY_IN_SECONDS );
	}

	/**
	 * Delete dictionary values that no hourly or sample row references any more: parameter
	 * signatures, URL patterns and user agent families. Bots and network names are bounded and stay.
	 * Runs under the fold lock, so a fold never writes an id that is being deleted.
	 *
	 * @return bool False when a fold holds the lock (nothing was deleted).
	 * @throws \RuntimeException When a statement fails.
	 */
	private function pruneDictionary(): bool {
		$t    = Schema::tables();
		$lock = Folder::lockName( $this->wpdb );
		if ( '1' !== (string) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
			return false;
		}
		try {
			$refs = array(
				'param_sig'   => array( "SELECT param_sig_id FROM {$t['hourly']}", "SELECT param_sig_id FROM {$t['sample']}" ),
				'url_pattern' => array( "SELECT url_pattern_id FROM {$t['sample']}" ),
				'ua_family'   => array( "SELECT ua_family_id FROM {$t['sample']}" ),
			);
			foreach ( $refs as $type => $selects ) {
				$where = '';
				foreach ( $selects as $select ) {
					$where .= " AND id NOT IN ({$select})";
				}
				$this->deleteBatched( "DELETE FROM {$t['dict']} WHERE type = %s{$where} LIMIT " . self::BATCH, $type );
			}
		} finally {
			$this->wpdb->query( $this->wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
		return true;
	}

	/**
	 * Repeat a limited DELETE until it removes fewer rows than the batch.
	 *
	 * @param string     $sql   Statement with one placeholder and a LIMIT.
	 * @param int|string $value Placeholder value.
	 * @throws \RuntimeException When a statement fails.
	 */
	private function deleteBatched( string $sql, $value ): void {
		do {
			$deleted = $this->wpdb->query( $this->wpdb->prepare( $sql, $value ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Built from constants and table names.
			if ( false === $deleted ) {
				throw new \RuntimeException( 'Retention failed: ' . $this->wpdb->last_error );
			}
		} while ( $deleted >= self::BATCH );
	}

	/**
	 * Option `bot_insight_sample_rate` from yesterday's requests, unless the setting fixes it.
	 *
	 * @param int $day Start of yesterday.
	 * @return bool False when the setting fixes the rate.
	 */
	private function sampleRate( int $day ): bool {
		if ( is_int( Plugin::settings()['sample_rate'] ) ) {
			return false;
		}
		$table    = Schema::tables()['hourly'];
		$requests = (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT SUM(requests) FROM {$table} WHERE hour >= %d AND hour < %d", $day, $day + DAY_IN_SECONDS ) );
		update_option( self::SAMPLE_RATE_OPTION, max( 1, (int) ceil( $requests / SampleRate::PER_DAY ) ), false );
		return true;
	}

	/**
	 * Refresh the range lists when opted in and the refreshed copy is a week old or missing.
	 *
	 * @param int $now Unix time.
	 * @return bool Whether a refresh ran.
	 * @throws \RuntimeException When the directory is missing or the refresh fails.
	 */
	private function refreshRanges( int $now ): bool {
		if ( empty( Plugin::settings()['range_updates'] ) ) {
			return false;
		}
		$dir = self::rangesDir();
		if ( '' === $dir ) {
			throw new \RuntimeException( 'No buffer directory.' );
		}
		$fetched = IpRanges::fetched( $dir );
		$time    = '' === $fetched ? false : strtotime( $fetched . ' 00:00:00 UTC' );
		if ( false !== $time && $now - $time < self::RANGE_DAYS * DAY_IN_SECONDS ) {
			return false;
		}
		if ( ! ( new FileBuffer( $dir ) )->ensureDir() ) {
			throw new \RuntimeException( 'Cannot create the ranges directory.' );
		}
		$complete = $this->ranges->run( $dir );
		// The recorder and the fold read refreshed lists only when this flag names their directory.
		update_option( 'bot_insight_ranges_override', '' !== IpRanges::fetched( $dir ) ? $dir : '', true );
		IpRanges::resetCache();
		if ( ! $complete ) {
			throw new \RuntimeException( 'Some range lists could not be refreshed.' );
		}
		return true;
	}

	/**
	 * Refresh the GeoIP databases on the 3rd of the month, or when they are missing; opt-in only.
	 *
	 * @param int $now Unix time.
	 * @return bool Whether a refresh ran.
	 * @throws \RuntimeException When the refresh fails.
	 */
	private function refreshGeo( int $now ): bool {
		if ( empty( Plugin::settings()['geo_updates'] ) ) {
			return false;
		}
		$country = GeoUpdater::countryPath();
		$asn     = GeoUpdater::asnPath();
		$missing = '' === $country || ! is_file( $country ) || ! is_file( $asn );
		if ( '3' !== gmdate( 'j', $now ) && ! $missing ) {
			return false;
		}
		if ( ! $this->geo->run( $now ) ) {
			throw new \RuntimeException( 'GeoIP databases could not be refreshed.' );
		}
		return true;
	}

	/**
	 * Stored load samples, hour start => load.
	 *
	 * @return array<int, float>
	 */
	private static function loadSamples(): array {
		$samples = get_option( self::LOAD_OPTION, array() );
		$out     = array();
		foreach ( is_array( $samples ) ? $samples : array() as $hour => $load ) {
			$out[ (int) $hour ] = (float) $load;
		}
		return $out;
	}

	/**
	 * Drop samples older than LOAD_DAYS.
	 *
	 * @param array<int, float> $samples Samples.
	 * @param int               $now     Unix time.
	 * @return array<int, float>
	 */
	private static function pruneLoad( array $samples, int $now ): array {
		$out = array();
		foreach ( $samples as $hour => $load ) {
			if ( $hour >= $now - self::LOAD_DAYS * DAY_IN_SECONDS ) {
				$out[ $hour ] = $load;
			}
		}
		ksort( $out );
		return $out;
	}
}
