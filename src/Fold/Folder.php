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
 * Crash safety per file: the hourly upsert, the sample inserts and the file's name in option
 * `bot_insight_folded_files` are committed in one transaction. A file whose name is in that list
 * is deleted on sight without being folded again, so a crash between the commit and the file
 * delete can never double count. The unique tracker is written after the commit; a crash there
 * loses a few uniques of that file (never requests).
 */
final class Folder {

	public const LOCK_OPTION   = 'bot_insight_fold_lock';
	public const FOLDED_OPTION = 'bot_insight_folded_files';
	public const STATUS_OPTION = 'bot_insight_fold_status';

	/**
	 * A lock younger than this many seconds is respected.
	 */
	private const LOCK_TTL = 240;

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
	 * Constructor.
	 *
	 * @param FileBuffer    $buffer     Buffer files.
	 * @param Enricher      $enricher   Record enricher.
	 * @param Aggregator    $aggregator Hourly aggregator.
	 * @param Dictionary    $dict       String dictionary.
	 * @param UniqueTracker $tracker    Unique tracker.
	 * @param \wpdb         $wpdb       Database handle.
	 */
	public function __construct( FileBuffer $buffer, Enricher $enricher, Aggregator $aggregator, Dictionary $dict, UniqueTracker $tracker, \wpdb $wpdb ) {
		$this->buffer     = $buffer;
		$this->enricher   = $enricher;
		$this->aggregator = $aggregator;
		$this->dict       = $dict;
		$this->tracker    = $tracker;
		$this->wpdb       = $wpdb;
	}

	/**
	 * Production wiring. Build a new folder per run, so every run gets a fresh DNS budget.
	 *
	 * @return self
	 * @throws \RuntimeException When there is no buffer directory or the bot list is invalid.
	 */
	public static function create(): self {
		global $wpdb;
		$dir = get_option( 'bot_insight_buffer_dir', '' );
		if ( ! is_string( $dir ) || '' === $dir ) {
			throw new \RuntimeException( 'No buffer directory.' );
		}

		$base = dirname( __DIR__, 2 ) . '/'; // The plugin directory (BOT_INSIGHT_DIR).
		$bots = BotList::fromFile( $base . 'data/bots.json' );

		$cache_key = static function ( string $ip ): string {
			return 'bot_insight_dns_' . substr( md5( (string) get_option( 'bot_insight_secret', '' ) . $ip ), 0, 20 );
		};
		$verifier  = new Verifier(
			new IpRanges( $base . 'data/ranges' ),
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
			50,
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
	 * @param int $max_seconds Stop starting new files after this many seconds of wall time.
	 * @return array{files:int, records:int, skipped_lock:bool}
	 */
	public function run( int $now, int $max_seconds = 20 ): array {
		$stats = array(
			'files'        => 0,
			'records'      => 0,
			'skipped_lock' => false,
		);
		if ( ! $this->lock( $now ) ) {
			$stats['skipped_lock'] = true;
			return $stats;
		}

		$error = '';
		try {
			$started = microtime( true );
			$files   = $this->buffer->foldable( $now );
			$folded  = $this->foldedNames( $files );
			$pending = null;

			foreach ( $files as $file ) {
				if ( microtime( true ) - $started >= $max_seconds ) {
					$pending = self::minuteOf( $file );
					break;
				}
				$name = basename( $file );
				if ( in_array( $name, $folded, true ) ) {
					// Committed by an earlier run that stopped before deleting the file.
					$this->buffer->delete( $file );
					$folded = array_values( array_diff( $folded, array( $name ) ) );
					update_option( self::FOLDED_OPTION, $folded, false );
					continue;
				}
				// A failure here ends the run: no hour or day is closed, the file stays for the next run.
				$stats['records'] += $this->foldFile( $file, $folded );
				++$stats['files'];
				$folded = $this->readFolded();
			}

			// Close only what every remaining file is newer than.
			$cutoff = (int) ( floor( $now / 60 ) * 60 ) - 60;
			if ( null !== $pending ) {
				$cutoff = min( $cutoff, $pending );
			}
			$this->closeHours( $cutoff );
			$this->closeDays( $cutoff );
		} catch ( \Throwable $e ) {
			$error = '' !== $e->getMessage() ? $e->getMessage() : get_class( $e );
		} finally {
			$this->unlock();
		}

		$this->saveStatus( $now, $error, $stats );
		try {
			do_action( 'bot_insight_folded', $stats );
		} catch ( \Throwable $e ) {
			$this->saveStatus( $now, 'bot_insight_folded: ' . $e->getMessage(), $stats );
		}
		return $stats;
	}

	/**
	 * Fold one file. Its records are committed with its name in the folded list, then counted for
	 * uniques, then the file and its name are removed.
	 *
	 * @param string   $file   Absolute path.
	 * @param string[] $folded Current folded list.
	 * @return int Records folded.
	 * @throws \RuntimeException When the file cannot be read or a query fails.
	 * @throws \Throwable        Anything else, after the transaction was rolled back.
	 */
	private function foldFile( string $file, array $folded ): int {
		if ( ! is_readable( $file ) ) {
			throw new \RuntimeException( 'Cannot read buffer file ' . basename( $file ) );
		}
		$rows = array();
		foreach ( $this->buffer->read( $file ) as $record ) {
			$rows[] = $this->enricher->enrich( $record );
		}
		$name = basename( $file );

		$hours = array();
		foreach ( $rows as $row ) {
			$hours[ (int) $row['hour'] ] = true;
		}
		$result  = $this->aggregator::aggregate( $rows, array( $this->dict, 'id' ), $this->existingSlots( array_keys( $hours ) ) );
		$samples = $this->sampleRows( $rows );
		$folded  = array_values( array_unique( array_merge( $folded, array( $name ) ) ) );

		$this->exec( 'START TRANSACTION' );
		try {
			$this->upsertHourly( $result['rows'] );
			$this->insertSamples( $samples );
			$this->writeFoldedInTransaction( $folded );
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

		$this->buffer->delete( $file );
		update_option( self::FOLDED_OPTION, array_values( array_diff( $folded, array( $name ) ) ), false );

		return count( $rows );
	}

	/**
	 * Take the lock.
	 *
	 * @param int $now Unix time.
	 * @return bool Whether this run holds the lock.
	 */
	private function lock( int $now ): bool {
		if ( add_option( self::LOCK_OPTION, $now, '', false ) ) {
			return true;
		}
		wp_cache_delete( self::LOCK_OPTION, 'options' );
		$held = get_option( self::LOCK_OPTION, false );
		if ( false !== $held && $now - (int) $held < self::LOCK_TTL ) {
			return false;
		}
		update_option( self::LOCK_OPTION, $now, false );
		return true;
	}

	/**
	 * Release the lock.
	 */
	private function unlock(): void {
		delete_option( self::LOCK_OPTION );
	}

	/**
	 * The folded list, fresh from the database, with names whose file is gone pruned.
	 *
	 * @param string[] $files Foldable files.
	 * @return string[]
	 */
	private function foldedNames( array $files ): array {
		add_option( self::FOLDED_OPTION, array(), '', false );
		$names = $this->readFolded();
		$kept  = array_values( array_intersect( $names, array_map( 'basename', $files ) ) );
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
				maybe_serialize( $folded ),
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
		$rate = $this->sampleRate();
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
	 * Write the unique metrics of every hour that ended at or before the cutoff.
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
					$this->exec(
						$this->wpdb->prepare(
							"UPDATE {$table} SET unique_ips = %d, ips_seen_once = %d, top_ip_share = %d WHERE hour = %d AND dims = %s",
							$m['unique_ips'],
							$m['ips_seen_once'],
							min( 10000, $m['top_ip_share'] ),
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
			// A crash before this line only repeats the same idempotent UPDATEs next run.
			$this->tracker->dropHour( $hour );
		}
	}

	/**
	 * Write the daily rows of every day that ended at or before the cutoff.
	 *
	 * @param int $cutoff Every buffer file before this time has been folded.
	 * @throws \RuntimeException When a query fails.
	 */
	private function closeDays( int $cutoff ): void {
		$hourly = Schema::tables()['hourly'];
		$daily  = Schema::tables()['daily'];
		foreach ( $this->tracker->openDays() as $ymd ) {
			$date = \DateTime::createFromFormat( '!Ymd', $ymd, new \DateTimeZone( 'UTC' ) );
			if ( false === $date ) {
				continue;
			}
			$start = $date->getTimestamp();
			if ( $start + 86400 > $cutoff ) {
				continue;
			}
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
					. ' ON DUPLICATE KEY UPDATE requests = VALUES(requests), php_ms_sum = VALUES(php_ms_sum), unique_ips = VALUES(unique_ips), ips_seen_once = VALUES(ips_seen_once)';
				$this->exec( $this->wpdb->prepare( $sql, $values ) );
			}
			$this->tracker->dropDay( $ymd );
		}
	}

	/**
	 * Sampling rate: an integer `sample_rate` setting wins, else option `bot_insight_sample_rate`.
	 *
	 * @return int At least 1.
	 */
	private function sampleRate(): int {
		$settings = get_option( 'bot_insight_settings', array() );
		if ( is_array( $settings ) && isset( $settings['sample_rate'] ) && is_int( $settings['sample_rate'] ) ) {
			return max( 1, $settings['sample_rate'] );
		}
		return max( 1, (int) get_option( 'bot_insight_sample_rate', 1 ) );
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
		if ( 1 === preg_match( '/^buffer-(\d{12})-/', basename( $file ), $m ) ) {
			$time = \DateTime::createFromFormat( '!YmdHi', $m[1], new \DateTimeZone( 'UTC' ) );
			if ( false !== $time ) {
				return $time->getTimestamp();
			}
		}
		return 0;
	}
}
