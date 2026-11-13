<?php
/**
 * Background fold of buffer files into the database.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Fold;

use BotInsight\Bots\BotList;
use BotInsight\Bots\Classifier;
use BotInsight\Bots\UaParser;
use BotInsight\Bots\Verifier;
use BotInsight\Buffer\FileBuffer;
use BotInsight\Geo\GeoLookup;
use BotInsight\Geo\GeoUpdater;
use BotInsight\Net\IpRanges;
use BotInsight\Storage\Dictionary;
use BotInsight\Storage\Salt;
use BotInsight\Storage\Schema;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The plugin's own tables; names come from Schema::tables(), values go through prepare() with built placeholder lists.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages go to an option, never to HTML.

/**
 * Reads the buffer files, enriches every record, upserts hourly rows, inserts sample rows and
 * closes hours and days for the unique counts. Runs every five minutes from cron, under a lock.
 *
 * Per file:
 * - The file is first renamed to `folding-<name>` (outside the foldable glob, still `.php`), so a
 *   late append to the old path goes to the renamed inode or a fresh file, never into a file that
 *   is deleted after reading.
 * - The hourly upsert, the sample inserts and the working name in option
 *   `bot_insight_folded_files` commit in one transaction. Inside it the option row is read
 *   `FOR UPDATE`; a name that is already listed (another run committed it) or a file that is gone
 *   rolls back and skips the file. A listed file is deleted on sight without being folded again,
 *   so a crash between the commit and the delete never double counts.
 * - The name leaves the list only once the file is really gone.
 * - The unique tracker is written after the commit; a crash there loses a few uniques of that
 *   file (never requests).
 * - A file that fails three runs in a row is renamed to `quarantine-<name>` and skipped; quarantined
 *   files are purged after a day.
 * - Raw files (buffer, working and quarantined) whose minute is more than 48 hours old are deleted
 *   unfolded at the start of a run, and the run records that in its error.
 *
 * The run lock is MySQL `GET_LOCK`, which the server releases when the connection dies.
 */
final class Folder {

	public const FOLDED_OPTION   = 'bot_insight_folded_files';
	public const STATUS_OPTION   = 'bot_insight_fold_status';
	public const FAILURES_OPTION = 'bot_insight_fold_failures';
	public const LAST_DAY_OPTION = 'bot_insight_last_closed_day';

	/**
	 * Prefix of a buffer file that is being folded.
	 */
	public const WORK_PREFIX = 'folding-';

	/**
	 * Prefix of a buffer file that failed too often.
	 */
	public const QUARANTINE_PREFIX = 'quarantine-';

	/**
	 * Failed runs after which a file is quarantined.
	 */
	private const MAX_FAILURES = 3;

	/**
	 * Seconds a quarantined file is kept.
	 */
	private const QUARANTINE_SECONDS = 86400;

	/**
	 * Age (by the minute in its name) after which a raw buffer file is deleted unfolded, so raw IP
	 * addresses and user agents never stay on disk longer, whatever goes wrong.
	 */
	public const STALE_SECONDS = 172800;

	/**
	 * Rows per multi-row INSERT statement.
	 */
	private const CHUNK = 200;

	/**
	 * Hourly columns written by the upsert, with their placeholders.
	 */
	private const HOURLY_COLUMNS = array(
		'hour'             => '%d',
		'dims'             => '%s',
		'client_class'     => '%s',
		'bot_id'           => '%d',
		'route'            => '%s',
		'param_sig_id'     => '%d',
		'lang'             => '%s',
		'country'          => '%s',
		'asn_type'         => '%s',
		'via_cdn'          => '%d',
		'verified'         => '%d',
		'js_seen'          => '%d',
		'status_class'     => '%s',
		'uncached_reason'  => '%s',
		'requests'         => '%d',
		'php_ms_sum'       => '%d',
		'cpu_ms_sum'       => '%d',
		'db_queries_sum'   => '%d',
		'mem_peak_max'     => '%d',
		'slow_2s'          => '%d',
		'sessions_created' => '%d',
		'cart_changes'     => '%d',
		'orders'           => '%d',
	);

	/**
	 * Additive hourly metrics (summed on duplicate key).
	 */
	private const HOURLY_SUMS = array( 'requests', 'php_ms_sum', 'cpu_ms_sum', 'db_queries_sum', 'slow_2s', 'sessions_created', 'cart_changes', 'orders' );

	/**
	 * Maximum length of the hourly string columns.
	 */
	private const HOURLY_LENGTHS = array(
		'client_class'    => 16,
		'route'           => 64,
		'lang'            => 12,
		'country'         => 2,
		'asn_type'        => 12,
		'status_class'    => 3,
		'uncached_reason' => 16,
	);

	/**
	 * Sample columns: placeholder and, for strings, maximum length; for integers the maximum.
	 */
	private const SAMPLE_COLUMNS = array(
		'ts'               => array( '%d', 4294967295 ),
		'hour'             => array( '%d', 4294967295 ),
		'php_ms'           => array( '%f', 0 ),
		'cpu_ms'           => array( '%f', 0 ),
		'mem_kb'           => array( '%d', 4294967295 ),
		'db_queries'       => array( '%d', 16777215 ),
		'status'           => array( '%d', 65535 ),
		'status_class'     => array( '%s', 3 ),
		'uncached_reason'  => array( '%s', 16 ),
		'method'           => array( '%s', 7 ),
		'route'            => array( '%s', 64 ),
		'param_sig_id'     => array( '%d', 4294967295 ),
		'depth'            => array( '%s', 8 ),
		'lang'             => array( '%s', 12 ),
		'url_pattern_id'   => array( '%d', 4294967295 ),
		'referer_type'     => array( '%s', 8 ),
		'ip_source'        => array( '%s', 20 ),
		'ip_hash'          => array( '%s', 16 ),
		'ip_prefix'        => array( '%s', 45 ),
		'via_cdn'          => array( '%d', 255 ),
		'cdn_country'      => array( '%s', 2 ),
		'country'          => array( '%s', 2 ),
		'asn'              => array( '%d', 4294967295 ),
		'asn_type'         => array( '%s', 12 ),
		'header_flags'     => array( '%d', 65535 ),
		'js_seen'          => array( '%d', 255 ),
		'session_created'  => array( '%d', 255 ),
		'cart_changed'     => array( '%d', 255 ),
		'order_created'    => array( '%d', 255 ),
		'payment_callback' => array( '%d', 255 ),
		'ua_family_id'     => array( '%d', 4294967295 ),
		'ua_outdated'      => array( '%d', 255 ),
		'bot_id'           => array( '%d', 4294967295 ),
		'client_class'     => array( '%s', 16 ),
		'verified'         => array( '%d', 255 ),
		'signals'          => array( '%d', 65535 ),
		'self_us'          => array( '%d', 4294967295 ),
		'weight'           => array( '%d', SampleRate::MAX ),
	);

	/**
	 * Buffer files.
	 *
	 * @var FileBuffer
	 */
	private $buffer;

	/**
	 * Record enricher.
	 *
	 * @var Enricher
	 */
	private $enricher;

	/**
	 * Hourly aggregator.
	 *
	 * @var Aggregator
	 */
	private $aggregator;

	/**
	 * String dictionary.
	 *
	 * @var Dictionary
	 */
	private $dict;

	/**
	 * Unique tracker.
	 *
	 * @var UniqueTracker
	 */
	private $tracker;

	/**
	 * Database handle.
	 *
	 * @var \wpdb
	 */
	private $wpdb;

	/**
	 * Deletes a file: fn( string $path ). Injectable for tests.
	 *
	 * @var callable
	 */
	private $unlink;

	/**
	 * Wall-clock start of the run.
	 *
	 * @var float
	 */
	private $started = 0.0;

	/**
	 * Time budget of the run in seconds.
	 *
	 * @var int
	 */
	private $budget = 20;

	/**
	 * Set when the budget ran out while a file was being read.
	 *
	 * @var bool
	 */
	private $over = false;

	/**
	 * Sample rate of the current run (1 in N).
	 *
	 * @var int
	 */
	private $rate = 1;

	/**
	 * Errors of the current run.
	 *
	 * @var string[]
	 */
	private $errors = array();

	/**
	 * Constructor.
	 *
	 * @param FileBuffer    $buffer     Buffer files.
	 * @param Enricher      $enricher   Record enricher.
	 * @param Aggregator    $aggregator Hourly aggregator.
	 * @param Dictionary    $dict       String dictionary.
	 * @param UniqueTracker $tracker    Unique tracker.
	 * @param \wpdb         $wpdb       Database handle.
	 * @param callable|null $unlink     Deletes a file (default: FileBuffer::delete()).
	 */
	public function __construct( FileBuffer $buffer, Enricher $enricher, Aggregator $aggregator, Dictionary $dict, UniqueTracker $tracker, \wpdb $wpdb, ?callable $unlink = null ) {
		$this->buffer     = $buffer;
		$this->enricher   = $enricher;
		$this->aggregator = $aggregator;
		$this->dict       = $dict;
		$this->tracker    = $tracker;
		$this->wpdb       = $wpdb;
		$this->unlink     = $unlink ?? array( $buffer, 'delete' );
	}

	/**
	 * Production wiring. Build a new folder per run, so every run gets a fresh DNS budget.
	 *
	 * @param int $dns_budget Reverse DNS lookups allowed in the run; 0 verifies by ranges and
	 *                        cached DNS results only.
	 * @return self
	 * @throws \RuntimeException When there is no buffer directory or the bot list is invalid.
	 */
	public static function create( int $dns_budget = 50 ): self {
		global $wpdb;
		$dir = get_option( 'bot_insight_buffer_dir', '' );
		if ( ! is_string( $dir ) || '' === $dir ) {
			throw new \RuntimeException( 'No buffer directory.' );
		}

		$base = dirname( __DIR__, 2 ) . '/'; // The plugin directory (BOT_INSIGHT_DIR).
		$bots = BotList::fromFile( $base . 'data/bots.json' );

		$override  = get_option( 'bot_insight_ranges_override', '' );
		$cache_key = static function ( string $ip ): string {
			return 'bot_insight_dns_' . substr( md5( (string) get_option( 'bot_insight_secret', '' ) . $ip ), 0, 20 );
		};
		$verifier  = new Verifier(
			new IpRanges( $base . 'data/ranges', is_string( $override ) && '' !== $override ? $override : null ),
			static function ( string $ip ) {
				return @gethostbyaddr( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A failed lookup is an expected result.
			},
			static function ( string $host ) {
				return @gethostbynamel( $host ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A failed lookup is an expected result.
			},
			static function ( string $ip ) use ( $cache_key ): ?int {
				$value = get_transient( $cache_key( $ip ) );
				return false === $value ? null : (int) $value;
			},
			static function ( string $ip, int $result ) use ( $cache_key ): void {
				set_transient( $cache_key( $ip ), $result, DAY_IN_SECONDS );
			},
			max( 0, $dns_budget ),
			static function ( string $host ) {
				$records = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A failed lookup is an expected result.
				if ( ! is_array( $records ) ) {
					return false;
				}
				$out = array();
				foreach ( $records as $record ) {
					if ( isset( $record['ipv6'] ) && is_string( $record['ipv6'] ) ) {
						$out[] = $record['ipv6'];
					}
				}
				return $out;
			}
		);

		$country = GeoUpdater::countryPath();
		$asn     = GeoUpdater::asnPath();
		$types   = is_readable( $base . 'data/asn-types.json' )
			? json_decode( (string) file_get_contents( $base . 'data/asn-types.json' ), true ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local bundled file.
			: array();

		$enricher = new Enricher(
			new UaParser( $bots, $bots->currentMajors() ),
			new Classifier(),
			$verifier,
			new Salt(
				'get_option',
				static function ( $name, $value ) {
					return update_option( $name, $value, false );
				}
			),
			new GeoLookup(
				'' !== $country && is_readable( $country ) ? $country : null,
				'' !== $asn && is_readable( $asn ) ? $asn : null,
				is_array( $types ) ? $types : array()
			)
		);

		return new self( new FileBuffer( $dir ), $enricher, new Aggregator(), new Dictionary( $wpdb ), new UniqueTracker( $dir ), $wpdb );
	}

	/**
	 * Fold what is foldable. Never throws.
	 *
	 * @param int $now         Unix time.
	 * @param int $max_seconds Stop starting new files after this many seconds of wall time. A
	 *                         file that is started is always finished (atomicity).
	 * @return array{files:int, records:int, skipped_lock:bool}
	 */
	public function run( int $now, int $max_seconds = 20 ): array {
		$stats = array(
			'files'        => 0,
			'records'      => 0,
			'skipped_lock' => false,
		);
		if ( ! $this->lock() ) {
			$stats['skipped_lock'] = true;
			return $stats;
		}

		$this->started = microtime( true );
		$this->budget  = $max_seconds;
		$this->over    = false;
		$this->errors  = array();
		try {
			$this->work( $now, $stats );
		} catch ( \Throwable $e ) {
			$this->errors[] = '' !== $e->getMessage() ? $e->getMessage() : get_class( $e );
		} finally {
			$this->unlock();
		}

		$this->saveStatus( $now, implode( ' | ', $this->errors ), $stats );
		try {
			do_action( 'bot_insight_folded', $stats );
		} catch ( \Throwable $e ) {
			$this->saveStatus( $now, 'bot_insight_folded: ' . $e->getMessage(), $stats );
		}
		return $stats;
	}

	/**
	 * Fold the files and close what is complete.
	 *
	 * @param int                                              $now   Unix time.
	 * @param array{files:int, records:int, skipped_lock:bool} $stats Run stats, updated.
	 * @throws \Throwable On the first failure that is not quarantined; it ends the run.
	 */
	private function work( int $now, array &$stats ): void {
		$dir        = $this->buffer->dir();
		$this->rate = $this->sampleRate( $now );
		$this->purgeStale( $dir, $now );
		$this->purgeQuarantine( $dir, $now );
		// Files a crashed run had claimed first, then the new ones; both oldest first.
		$work    = array_merge( self::glob( $dir . '/' . self::WORK_PREFIX . 'buffer-*.php' ), $this->buffer->foldable( $now ) );
		$folded  = $this->foldedNames( $dir );
		$pending = null;

		foreach ( $work as $file ) {
			if ( $this->overBudget() ) {
				$pending = self::earliest( $pending, self::minuteOf( $file ) );
				break;
			}
			$working = $this->claim( $file );
			if ( null === $working ) {
				// An older working copy of the same name still waits; take this one next run.
				$pending = self::earliest( $pending, self::minuteOf( $file ) );
				continue;
			}
			$name = basename( $working );
			if ( in_array( $name, $folded, true ) ) {
				// Committed by an earlier run that stopped before deleting the file.
				$this->finish( $working );
				$folded = $this->readFolded();
				continue;
			}
			try {
				$count = $this->foldFile( $working );
			} catch ( \Throwable $e ) {
				if ( $this->recordFailure( $working, $e ) ) {
					continue;
				}
				// A failure ends the run: no hour or day is closed, the file stays for the next run.
				throw $e;
			}
			if ( null !== $count ) {
				$stats['records'] += $count;
				++$stats['files'];
			}
			$this->clearFailure( $name );
			$folded = $this->readFolded();
		}

		// Close only what every remaining file is newer than.
		$cutoff = (int) ( floor( $now / 60 ) * 60 ) - 60;
		if ( null !== $pending ) {
			$cutoff = min( $cutoff, $pending );
		}
		$this->closeHours( $cutoff );
		$this->closeDays( $cutoff );
	}

	/**
	 * Name of the MySQL user lock of the fold: unique per database and table prefix, at most 64
	 * characters (GET_LOCK names are server-wide).
	 *
	 * @param \wpdb $wpdb Database handle.
	 */
	public static function lockName( \wpdb $wpdb ): string {
		return 'bot_insight_fold_' . md5( (string) $wpdb->get_var( 'SELECT DATABASE()' ) . '|' . $wpdb->prefix );
	}

	/**
	 * Fold one claimed file. Its records are committed with its name in the folded list, then
	 * counted for uniques, then the file and its name are removed.
	 *
	 * @param string $working Absolute path of the claimed (`folding-`) file.
	 * @return int|null Records folded, or null when another run already committed the file.
	 * @throws \RuntimeException When the file cannot be read or a query fails.
	 * @throws \Throwable        Anything else, after the transaction was rolled back.
	 */
	private function foldFile( string $working ): ?int {
		if ( ! is_readable( $working ) ) {
			throw new \RuntimeException( 'Cannot read buffer file ' . basename( $working ) );
		}
		$rows = array();
		foreach ( $this->buffer->read( $working ) as $record ) {
			$rows[] = $this->enricher->enrich( $record );
			if ( ! $this->over && microtime( true ) - $this->started >= $this->budget ) {
				// Finish this file (it must commit as a whole), but start no other.
				$this->over = true;
			}
		}
		$name = basename( $working );

		$hours = array();
		foreach ( $rows as $row ) {
			$hours[ (int) $row['hour'] ] = true;
		}
		$result  = $this->aggregator::aggregate( $rows, array( $this->dict, 'id' ), $this->existingSlots( array_keys( $hours ) ) );
		$samples = $this->sampleRows( $rows );

		$this->exec( 'START TRANSACTION' );
		try {
			$listed = $this->lockFoldedRow();
			clearstatcache( true, $working );
			if ( in_array( $name, $listed, true ) || ! is_file( $working ) ) {
				$this->wpdb->query( 'ROLLBACK' );
				return null;
			}
			$this->upsertHourly( $result['rows'] );
			$this->insertSamples( $samples );
			$listed[] = $name;
			$this->writeFoldedInTransaction( $listed );
			$this->exec( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$this->wpdb->query( 'ROLLBACK' );
			throw $e;
		}
		wp_cache_delete( self::FOLDED_OPTION, 'options' );

		foreach ( $rows as $i => $row ) {
			$this->tracker->add( (int) $row['hour'], $result['dims'][ $i ], (string) $row['class'], (string) $row['ip_hash'] );
		}
		$this->tracker->flush();

		$this->finish( $working );
		return count( $rows );
	}

	/**
	 * Rename a buffer file to its working name. A file that already is a working copy is returned
	 * as is.
	 *
	 * @param string $file Absolute path.
	 * @return string|null Working path, or null when a working copy of that name exists already.
	 * @throws \RuntimeException When the rename fails.
	 */
	private function claim( string $file ): ?string {
		$name = basename( $file );
		if ( 0 === strpos( $name, self::WORK_PREFIX ) ) {
			return $file;
		}
		$working = dirname( $file ) . '/' . self::WORK_PREFIX . $name;
		if ( file_exists( $working ) ) {
			return null;
		}
		if ( ! @rename( $file, $working ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- Same-directory rename in the private buffer dir.
			throw new \RuntimeException( 'Cannot claim buffer file ' . $name );
		}
		return $working;
	}

	/**
	 * Delete a committed file; drop its name from the folded list only when it is really gone.
	 *
	 * @param string $working Absolute path.
	 */
	private function finish( string $working ): void {
		$name = basename( $working );
		( $this->unlink )( $working );
		clearstatcache( true, $working );
		if ( file_exists( $working ) ) {
			// Keep the name, so no later run folds the file again.
			$this->errors[] = 'Could not delete folded buffer file ' . $name;
			return;
		}
		update_option( self::FOLDED_OPTION, array_values( array_diff( $this->readFolded(), array( $name ) ) ), false );
		$this->clearFailure( $name );
	}

	/**
	 * Count a failed fold of a file; quarantine it after MAX_FAILURES.
	 *
	 * @param string     $working Absolute path.
	 * @param \Throwable $e       The failure.
	 * @return bool True when the file was quarantined and the run may go on.
	 */
	private function recordFailure( string $working, \Throwable $e ): bool {
		$name    = basename( $working );
		$message = '' !== $e->getMessage() ? $e->getMessage() : get_class( $e );
		if ( in_array( $name, $this->readFolded(), true ) ) {
			// Committed already; the next run deletes it. Nothing to quarantine.
			return false;
		}
		$failures = get_option( self::FAILURES_OPTION, array() );
		$failures = is_array( $failures ) ? $failures : array();
		$count    = (int) ( $failures[ $name ] ?? 0 ) + 1;
		if ( $count < self::MAX_FAILURES ) {
			$failures[ $name ] = $count;
			update_option( self::FAILURES_OPTION, $failures, false );
			return false;
		}
		$target = dirname( $working ) . '/' . self::QUARANTINE_PREFIX . substr( $name, strlen( self::WORK_PREFIX ) );
		if ( ! @rename( $working, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- Same-directory rename in the private buffer dir.
			$failures[ $name ] = $count;
			update_option( self::FAILURES_OPTION, $failures, false );
			return false;
		}
		@touch( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions -- Private buffer dir; the purge age counts from the quarantine.
		unset( $failures[ $name ] );
		update_option( self::FAILURES_OPTION, $failures, false );
		$this->errors[] = 'Quarantined ' . basename( $target ) . ' after ' . $count . ' failures: ' . $message;
		return true;
	}

	/**
	 * Forget the failures of a file.
	 *
	 * @param string $name Working name.
	 */
	private function clearFailure( string $name ): void {
		$failures = get_option( self::FAILURES_OPTION, array() );
		if ( is_array( $failures ) && isset( $failures[ $name ] ) ) {
			unset( $failures[ $name ] );
			update_option( self::FAILURES_OPTION, $failures, false );
		}
	}

	/**
	 * Delete quarantined files older than QUARANTINE_SECONDS.
	 *
	 * @param string $dir Buffer directory.
	 * @param int    $now Unix time.
	 */
	private function purgeQuarantine( string $dir, int $now ): void {
		foreach ( self::glob( $dir . '/' . self::QUARANTINE_PREFIX . '*.php' ) as $file ) {
			$mtime = @filemtime( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A vanished file is fine.
			if ( false !== $mtime && $mtime < $now - self::QUARANTINE_SECONDS ) {
				( $this->unlink )( $file );
			}
		}
	}

	/**
	 * Delete raw files whose minute is more than STALE_SECONDS old, folded or not.
	 *
	 * @param string $dir Buffer directory.
	 * @param int    $now Unix time.
	 */
	private function purgeStale( string $dir, int $now ): void {
		$deleted = 0;
		foreach ( array( 'buffer-*.php', self::WORK_PREFIX . 'buffer-*.php', self::QUARANTINE_PREFIX . 'buffer-*.php' ) as $pattern ) {
			foreach ( self::glob( $dir . '/' . $pattern ) as $file ) {
				$minute = self::minuteOf( $file );
				if ( 0 === $minute || $minute >= $now - self::STALE_SECONDS ) {
					continue;
				}
				( $this->unlink )( $file );
				clearstatcache( true, $file );
				if ( ! file_exists( $file ) ) {
					++$deleted;
					$this->clearFailure( basename( $file ) );
				}
			}
		}
		if ( $deleted > 0 ) {
			$this->errors[] = sprintf( 'Deleted %d buffer files older than 48 hours without folding them.', $deleted );
		}
	}

	/**
	 * Whether the time budget is spent.
	 */
	private function overBudget(): bool {
		return $this->over || microtime( true ) - $this->started >= $this->budget;
	}

	/**
	 * Take the run lock (MySQL GET_LOCK, released by the server when the connection dies).
	 *
	 * @return bool Whether this run holds the lock.
	 */
	private function lock(): bool {
		$got = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::lockName( $this->wpdb ) ) );
		return '1' === (string) $got;
	}

	/**
	 * Release the run lock.
	 */
	private function unlock(): void {
		$this->wpdb->query( $this->wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lockName( $this->wpdb ) ) );
	}

	/**
	 * The folded list, fresh from the database, with names whose file is gone pruned.
	 *
	 * @param string $dir Buffer directory.
	 * @return string[]
	 */
	private function foldedNames( string $dir ): array {
		add_option( self::FOLDED_OPTION, array(), '', false );
		$names = $this->readFolded();
		$kept  = array();
		foreach ( $names as $name ) {
			if ( is_file( $dir . '/' . $name ) ) {
				$kept[] = $name;
			}
		}
		if ( $kept !== $names ) {
			update_option( self::FOLDED_OPTION, $kept, false );
		}
		return $kept;
	}

	/**
	 * Folded list from the database (skips a possibly stale object cache).
	 *
	 * @return string[]
	 */
	private function readFolded(): array {
		wp_cache_delete( self::FOLDED_OPTION, 'options' );
		$value = get_option( self::FOLDED_OPTION, array() );
		return is_array( $value ) ? array_values( array_map( 'strval', $value ) ) : array();
	}

	/**
	 * Read the folded list inside the open transaction and lock its row until COMMIT/ROLLBACK.
	 *
	 * @return string[]
	 * @throws \RuntimeException When the option row is missing.
	 */
	private function lockFoldedRow(): array {
		$raw = $this->wpdb->get_var(
			$this->wpdb->prepare( "SELECT option_value FROM {$this->wpdb->options} WHERE option_name = %s FOR UPDATE", self::FOLDED_OPTION )
		);
		if ( null === $raw ) {
			throw new \RuntimeException( 'Missing option ' . self::FOLDED_OPTION . ' ' . $this->wpdb->last_error );
		}
		$value = maybe_unserialize( $raw );
		return is_array( $value ) ? array_values( array_map( 'strval', $value ) ) : array();
	}

	/**
	 * Write the folded list as a statement of the open transaction, so it commits together with
	 * the rows (wp_options is InnoDB). The option exists already (added in foldedNames()).
	 *
	 * @param string[] $folded Names.
	 * @throws \RuntimeException When the option row is not updated.
	 */
	private function writeFoldedInTransaction( array $folded ): void {
		$updated = $this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$this->wpdb->options} SET option_value = %s WHERE option_name = %s",
				maybe_serialize( array_values( array_unique( $folded ) ) ),
				self::FOLDED_OPTION
			)
		);
		if ( false === $updated || $updated < 1 ) {
			throw new \RuntimeException( 'Could not record the folded file: ' . $this->wpdb->last_error );
		}
	}

	/**
	 * Rows already in the hourly table for the given hours.
	 *
	 * @param int[] $hours Hour starts.
	 * @return array<int, array{count:int, keys:array<string, bool>}>
	 * @throws \RuntimeException When the query fails.
	 */
	private function existingSlots( array $hours ): array {
		if ( array() === $hours ) {
			return array();
		}
		$table = Schema::tables()['hourly'];
		$in    = implode( ',', array_fill( 0, count( $hours ), '%d' ) );
		$rows  = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT hour, dims FROM {$table} WHERE hour IN ({$in})", $hours ), ARRAY_A );
		if ( '' !== $this->wpdb->last_error ) {
			throw new \RuntimeException( $this->wpdb->last_error );
		}
		$out = array();
		foreach ( (array) $rows as $row ) {
			$hour = (int) $row['hour'];
			if ( ! isset( $out[ $hour ] ) ) {
				$out[ $hour ] = array(
					'count' => 0,
					'keys'  => array(),
				);
			}
			++$out[ $hour ]['count'];
			$out[ $hour ]['keys'][ (string) $row['dims'] ] = true;
		}
		return $out;
	}

	/**
	 * Upsert hourly rows: sums for the additive metrics, GREATEST for the memory peak.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows from the aggregator.
	 */
	private function upsertHourly( array $rows ): void {
		$table   = Schema::tables()['hourly'];
		$columns = array_keys( self::HOURLY_COLUMNS );
		$tuple   = '(' . implode( ',', self::HOURLY_COLUMNS ) . ')';
		$update  = array();
		foreach ( self::HOURLY_SUMS as $col ) {
			$update[] = "{$col} = {$col} + VALUES({$col})";
		}
		$update[] = 'mem_peak_max = GREATEST(mem_peak_max, VALUES(mem_peak_max))';

		foreach ( array_chunk( $rows, self::CHUNK ) as $chunk ) {
			$values = array();
			foreach ( $chunk as $row ) {
				foreach ( self::HOURLY_COLUMNS as $col => $format ) {
					$value = $row[ $col ];
					if ( isset( self::HOURLY_LENGTHS[ $col ] ) ) {
						$value = substr( (string) $value, 0, self::HOURLY_LENGTHS[ $col ] );
					} elseif ( '%d' === $format ) {
						$value = max( 0, (int) $value );
					}
					$values[] = $value;
				}
			}
			$sql = "INSERT INTO {$table} (" . implode( ',', $columns ) . ') VALUES '
				. implode( ',', array_fill( 0, count( $chunk ), $tuple ) )
				. ' ON DUPLICATE KEY UPDATE ' . implode( ', ', $update );
			$this->exec( $this->wpdb->prepare( $sql, $values ) );
		}
	}

	/**
	 * Sample rows for the sampled records, mapped onto the sample columns.
	 *
	 * @param array<int, array<string, mixed>> $rows Enriched rows.
	 * @return array<int, array<int, mixed>> Values in SAMPLE_COLUMNS order.
	 */
	private function sampleRows( array $rows ): array {
		$rate = $this->rate;
		$out  = array();
		foreach ( $rows as $row ) {
			if ( 1 !== random_int( 1, $rate ) ) {
				continue;
			}
			$mapped = array(
				'ts'               => $row['ts'],
				'hour'             => $row['hour'],
				'php_ms'           => $row['ms'],
				'cpu_ms'           => $row['cpu'],
				'mem_kb'           => $row['mem'],
				'db_queries'       => $row['dbq'],
				'status'           => $row['st'],
				'status_class'     => $row['status_class'],
				'uncached_reason'  => $row['unc'],
				'method'           => $row['m'],
				'route'            => $row['r'],
				'param_sig_id'     => $this->dictId( 'param_sig', (string) $row['p'] ),
				'depth'            => $row['dp'],
				'lang'             => $row['lg'],
				'url_pattern_id'   => $this->dictId( 'url_pattern', (string) $row['u'] ),
				'referer_type'     => $row['ref'],
				'ip_source'        => $row['ips'],
				'ip_hash'          => $row['ip_hash'],
				'ip_prefix'        => $row['ip_prefix'],
				'via_cdn'          => $row['cdn'],
				'cdn_country'      => $row['cc'],
				'country'          => $row['country'],
				'asn'              => $row['asn'],
				'asn_type'         => $row['asn_type'],
				'header_flags'     => $row['hf'],
				'js_seen'          => $row['js'],
				'session_created'  => $row['se'],
				'cart_changed'     => $row['ca'],
				'order_created'    => $row['or'],
				'payment_callback' => $row['pc'],
				'ua_family_id'     => $this->dictId( 'ua_family', (string) $row['ua_family'] ),
				'ua_outdated'      => $row['ua_outdated'],
				'bot_id'           => $this->dictId( 'bot', (string) $row['bot'] ),
				'client_class'     => $row['class'],
				'verified'         => $row['verified'],
				'signals'          => $row['signals'],
				'self_us'          => $row['self'],
				'weight'           => $rate,
			);
			$values = array();
			foreach ( self::SAMPLE_COLUMNS as $col => $spec ) {
				$value = $mapped[ $col ];
				if ( '%s' === $spec[0] ) {
					$value = substr( (string) $value, 0, $spec[1] );
				} elseif ( '%d' === $spec[0] ) {
					$value = min( $spec[1], max( 0, (int) $value ) );
				} else {
					$value = max( 0.0, (float) $value );
				}
				$values[] = $value;
			}
			$out[] = $values;
		}
		return $out;
	}

	/**
	 * Insert sample rows.
	 *
	 * @param array<int, array<int, mixed>> $samples Values in SAMPLE_COLUMNS order.
	 */
	private function insertSamples( array $samples ): void {
		$table   = Schema::tables()['sample'];
		$formats = array();
		foreach ( self::SAMPLE_COLUMNS as $spec ) {
			$formats[] = $spec[0];
		}
		$tuple = '(' . implode( ',', $formats ) . ')';
		$cols  = implode( ',', array_keys( self::SAMPLE_COLUMNS ) );
		foreach ( array_chunk( $samples, self::CHUNK ) as $chunk ) {
			$sql = "INSERT INTO {$table} ({$cols}) VALUES " . implode( ',', array_fill( 0, count( $chunk ), $tuple ) );
			$this->exec( $this->wpdb->prepare( $sql, array_merge( ...$chunk ) ) );
		}
	}

	/**
	 * Write the unique metrics of every hour that ended at or before the cutoff, then roll the hour
	 * into its day file.
	 *
	 * @param int $cutoff Every buffer file before this time has been folded.
	 * @throws \Throwable When a query fails, after the transaction was rolled back.
	 */
	private function closeHours( int $cutoff ): void {
		$table = Schema::tables()['hourly'];
		foreach ( $this->tracker->openHours() as $hour ) {
			if ( $hour + 3600 > $cutoff ) {
				continue;
			}
			$stats = $this->tracker->hourStats( $hour );
			$this->exec( 'START TRANSACTION' );
			try {
				foreach ( $stats as $dims => $m ) {
					// A repeated close never lowers a count. top_ip_share is set first, against the
					// old unique_ips: it only follows data at least as complete as what is stored.
					$this->exec(
						$this->wpdb->prepare(
							"UPDATE {$table} SET top_ip_share = IF(unique_ips <= %d, %d, top_ip_share), unique_ips = GREATEST(unique_ips, %d), ips_seen_once = GREATEST(ips_seen_once, %d) WHERE hour = %d AND dims = %s",
							$m['unique_ips'],
							min( 10000, $m['top_ip_share'] ),
							$m['unique_ips'],
							$m['ips_seen_once'],
							$hour,
							(string) $dims
						)
					);
				}
				$this->exec( 'COMMIT' );
			} catch ( \Throwable $e ) {
				$this->wpdb->query( 'ROLLBACK' );
				throw $e;
			}
			// A crash after the commit repeats the GREATEST updates and the roll; both are idempotent.
			$this->tracker->rollIntoDay( $hour );
			$this->tracker->dropHour( $hour );
		}
	}

	/**
	 * Write the daily rows of every day that ended at or before the cutoff: days with tracker data
	 * and days with hourly rows after the last closed day.
	 *
	 * @param int $cutoff Every buffer file before this time has been folded.
	 * @throws \RuntimeException When a query fails.
	 */
	private function closeDays( int $cutoff ): void {
		$hourly = Schema::tables()['hourly'];
		$days   = array();
		foreach ( $this->tracker->openDays() as $ymd ) {
			$start = self::dayStart( $ymd );
			if ( null !== $start ) {
				$days[ $start ] = true;
			}
		}

		$last = self::dayStart( (string) get_option( self::LAST_DAY_OPTION, '' ) );
		$from = null === $last ? 0 : $last + 86400;
		while ( true ) {
			$min = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT MIN(hour) FROM {$hourly} WHERE hour >= %d", $from ) );
			if ( '' !== $this->wpdb->last_error ) {
				throw new \RuntimeException( $this->wpdb->last_error );
			}
			if ( null === $min ) {
				break;
			}
			$day = (int) $min - (int) $min % 86400;
			if ( $day + 86400 > $cutoff ) {
				break;
			}
			$days[ $day ] = true;
			$from         = $day + 86400;
		}

		ksort( $days );
		foreach ( array_keys( $days ) as $start ) {
			if ( $start + 86400 > $cutoff ) {
				continue;
			}
			$this->closeDay( $start );
			if ( null === $last || $start > $last ) {
				$last = $start;
				update_option( self::LAST_DAY_OPTION, gmdate( 'Ymd', $start ), false );
			}
		}
	}

	/**
	 * Write the daily rows of one day and drop its tracker file. Requests and PHP time are a full
	 * recount from the hourly table; uniques never go down on a repeated close.
	 *
	 * @param int $start Day start (UTC).
	 * @throws \RuntimeException When a query fails.
	 */
	private function closeDay( int $start ): void {
		$hourly  = Schema::tables()['hourly'];
		$daily   = Schema::tables()['daily'];
		$ymd     = gmdate( 'Ymd', $start );
		$uniques = $this->tracker->dayStats( $ymd );
		$sums    = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT client_class, SUM(requests) AS requests, SUM(php_ms_sum) AS php_ms FROM {$hourly} WHERE hour >= %d AND hour < %d GROUP BY client_class",
				$start,
				$start + 86400
			),
			ARRAY_A
		);
		if ( '' !== $this->wpdb->last_error ) {
			throw new \RuntimeException( $this->wpdb->last_error );
		}
		$classes = array();
		foreach ( (array) $sums as $row ) {
			$classes[ (string) $row['client_class'] ] = array( (int) $row['requests'], (int) $row['php_ms'] );
		}
		foreach ( array_keys( $uniques ) as $class ) {
			if ( ! isset( $classes[ $class ] ) ) {
				$classes[ $class ] = array( 0, 0 );
			}
		}
		if ( array() !== $classes ) {
			$values = array();
			foreach ( $classes as $class => $sum ) {
				array_push(
					$values,
					gmdate( 'Y-m-d', $start ),
					substr( (string) $class, 0, 16 ),
					$sum[0],
					$sum[1],
					$uniques[ $class ]['unique_ips'] ?? 0,
					$uniques[ $class ]['ips_seen_once'] ?? 0
				);
			}
			$sql = "INSERT INTO {$daily} (day, client_class, requests, php_ms_sum, unique_ips, ips_seen_once) VALUES "
				. implode( ',', array_fill( 0, count( $classes ), '(%s,%s,%d,%d,%d,%d)' ) )
				. ' ON DUPLICATE KEY UPDATE requests = VALUES(requests), php_ms_sum = VALUES(php_ms_sum), unique_ips = GREATEST(unique_ips, VALUES(unique_ips)), ips_seen_once = GREATEST(ips_seen_once, VALUES(ips_seen_once))';
			$this->exec( $this->wpdb->prepare( $sql, $values ) );
		}
		$this->tracker->dropDay( $ymd );
	}

	/**
	 * Sample rate of a run. An integer `sample_rate` setting wins. Otherwise the day's start rate
	 * (option `bot_insight_sample_rate`, set by the daily job) is raised when today's folded
	 * requests are on course for more than SampleRate::PER_DAY sample rows; the rate used is
	 * kept for the day, so it never drops within a day.
	 *
	 * @param int $now Unix time.
	 * @return int At least 1.
	 * @throws \RuntimeException When today's requests cannot be read.
	 */
	private function sampleRate( int $now ): int {
		$settings = get_option( 'bot_insight_settings', array() );
		if ( is_array( $settings ) && isset( $settings['sample_rate'] ) && is_int( $settings['sample_rate'] ) ) {
			return max( 1, min( SampleRate::MAX, $settings['sample_rate'] ) );
		}
		$start    = max( 1, (int) get_option( 'bot_insight_sample_rate', 1 ) );
		$day      = $now - $now % 86400;
		$ymd      = gmdate( 'Ymd', $day );
		$stored   = get_option( SampleRate::DAY_OPTION, array() );
		$day_rate = is_array( $stored ) && ( $stored['day'] ?? '' ) === $ymd ? (int) ( $stored['rate'] ?? 0 ) : 0;
		$table    = Schema::tables()['hourly'];
		$requests = (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COALESCE(SUM(requests), 0) FROM {$table} WHERE hour >= %d", $day ) );
		if ( '' !== $this->wpdb->last_error ) {
			throw new \RuntimeException( $this->wpdb->last_error );
		}
		$rate = SampleRate::adapt( $start, $day_rate, $requests, $now - $day );
		if ( $rate !== $day_rate ) {
			update_option(
				SampleRate::DAY_OPTION,
				array(
					'day'  => $ymd,
					'rate' => $rate,
				),
				false
			);
		}
		return $rate;
	}

	/**
	 * Dictionary id, 0 for an empty value.
	 *
	 * @param string $type  Dictionary type.
	 * @param string $value Value.
	 */
	private function dictId( string $type, string $value ): int {
		return '' === $value ? 0 : $this->dict->id( $type, $value );
	}

	/**
	 * Run a statement; throw when it fails.
	 *
	 * @param string|null $sql Statement.
	 * @throws \RuntimeException On failure.
	 */
	private function exec( ?string $sql ): void {
		if ( null === $sql || '' === $sql || false === $this->wpdb->query( $sql ) ) {
			throw new \RuntimeException( 'Query failed: ' . $this->wpdb->last_error );
		}
	}

	/**
	 * Store the run's outcome. `last_run` is the time of the last successful run.
	 *
	 * @param int                  $now   Unix time.
	 * @param string               $error Error message, '' on success.
	 * @param array<string, mixed> $stats Run stats.
	 */
	private function saveStatus( int $now, string $error, array $stats ): void {
		$old = get_option( self::STATUS_OPTION, array() );
		update_option(
			self::STATUS_OPTION,
			array(
				'last_run'   => '' === $error ? $now : (int) ( is_array( $old ) ? ( $old['last_run'] ?? 0 ) : 0 ),
				'last_error' => substr( $error, 0, 500 ),
				'files'      => (int) $stats['files'],
				'records'    => (int) $stats['records'],
			),
			false
		);
	}

	/**
	 * Start of the minute a buffer file belongs to.
	 *
	 * @param string $file Path.
	 */
	private static function minuteOf( string $file ): int {
		if ( 1 === preg_match( '/^(?:' . self::WORK_PREFIX . '|' . self::QUARANTINE_PREFIX . ')?buffer-(\d{12})-/', basename( $file ), $m ) ) {
			$time = \DateTime::createFromFormat( '!YmdHi', $m[1], new \DateTimeZone( 'UTC' ) );
			if ( false !== $time ) {
				return $time->getTimestamp();
			}
		}
		return 0;
	}

	/**
	 * Start of a UTC day given as Ymd, or null.
	 *
	 * @param string $ymd Day.
	 */
	private static function dayStart( string $ymd ): ?int {
		if ( 1 !== preg_match( '/^\d{8}$/', $ymd ) ) {
			return null;
		}
		$date = \DateTime::createFromFormat( '!Ymd', $ymd, new \DateTimeZone( 'UTC' ) );
		return false === $date ? null : $date->getTimestamp();
	}

	/**
	 * The earlier of a pending minute and another one.
	 *
	 * @param int|null $pending Current pending minute.
	 * @param int      $minute  Candidate.
	 */
	private static function earliest( ?int $pending, int $minute ): int {
		return null === $pending ? $minute : min( $pending, $minute );
	}

	/**
	 * Sorted glob.
	 *
	 * @param string $pattern Pattern.
	 * @return string[]
	 */
	private static function glob( string $pattern ): array {
		$files = @glob( $pattern ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- An unreadable dir gives no files.
		$files = is_array( $files ) ? $files : array();
		sort( $files, SORT_STRING );
		return $files;
	}
}
