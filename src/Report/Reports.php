<?php
/**
 * Read-only report queries.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Report;

use BotInsight\Bots\BotList;
use BotInsight\Bots\Classifier;
use BotInsight\Storage\Dictionary;
use BotInsight\Storage\Schema;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Read-only queries on the plugin's own tables (names from Schema::tables()); every value goes through prepare().

/**
 * Feeds the dashboard and the signals. Never writes. Results are cached within the instance
 * only, never across requests.
 */
final class Reports {

	/**
	 * Classes counted as findable search for `verified_search_requests`.
	 */
	public const SEARCH_CLASSES = array( 'search', 'ai_search', 'ai_assistant' );

	/**
	 * Classes in the unknown-countries table.
	 */
	public const UNKNOWN_CLASSES = array( 'unknown_bot', 'suspected', 'library' );

	/**
	 * `status_class` of the aggregator's overflow rows.
	 */
	public const OVERFLOW_STATUS = 'xxx';

	/**
	 * Days the sample table keeps its rows; sample-based figures cover at most this window.
	 */
	public const SAMPLE_DAYS = 14;

	/**
	 * Milliseconds per hour.
	 */
	private const MS_PER_HOUR = 3600000;

	/**
	 * Most events returned for a period.
	 */
	private const EVENTS_MAX = 500;

	/**
	 * Snapshot rows read when averaging the overhead.
	 */
	private const OVERHEAD_SCAN = 60;

	/**
	 * Database handle.
	 *
	 * @var \wpdb
	 */
	private $wpdb;

	/**
	 * Bot list.
	 *
	 * @var BotList
	 */
	private $bots;

	/**
	 * Dictionary.
	 *
	 * @var Dictionary
	 */
	private $dict;

	/**
	 * Table names keyed by short name.
	 *
	 * @var array<string, string>
	 */
	private $t;

	/**
	 * Per-instance cache.
	 *
	 * @var array<string, mixed>
	 */
	private $cache = array();

	/**
	 * Constructor.
	 *
	 * @param \wpdb   $wpdb Database handle.
	 * @param BotList $bots Bot list.
	 */
	public function __construct( \wpdb $wpdb, BotList $bots ) {
		$this->wpdb = $wpdb;
		$this->bots = $bots;
		$this->dict = new Dictionary( $wpdb );
		$this->t    = Schema::tables();
	}

	/**
	 * Reports on the global database with the bundled bot list.
	 *
	 * @throws \RuntimeException When the bundled bot list is missing.
	 */
	public static function create(): self {
		global $wpdb;
		return new self( $wpdb, BotList::fromFile( dirname( __DIR__, 2 ) . '/data/bots.json' ) );
	}

	/**
	 * The bot list in use.
	 */
	public function bots(): BotList {
		return $this->bots;
	}

	/**
	 * Display label of a client class, for rows without a bot.
	 *
	 * @param string $client_class Client class.
	 */
	public static function classLabel( string $client_class ): string {
		switch ( $client_class ) {
			case 'people':
				return __( 'People', 'bot-insight' );
			case 'suspected':
				return __( 'Suspected automated', 'bot-insight' );
			case 'library':
				return __( 'Script libraries', 'bot-insight' );
			case 'unknown_bot':
				return __( 'Unknown bots', 'bot-insight' );
			case 'search':
				return __( 'Search engines', 'bot-insight' );
			case 'ai_search':
				return __( 'AI search', 'bot-insight' );
			case 'ai_assistant':
				return __( 'AI assistants', 'bot-insight' );
			case 'ai_training':
				return __( 'AI training crawlers', 'bot-insight' );
			case 'seo':
				return __( 'SEO tools', 'bot-insight' );
			case 'social':
				return __( 'Social previews', 'bot-insight' );
			case 'monitoring':
				return __( 'Monitoring', 'bot-insight' );
			case 'feed':
				return __( 'Feed readers', 'bot-insight' );
		}
		return $client_class;
	}

	/**
	 * Headline numbers.
	 *
	 * `unique_client_days` counts client-days over whole UTC days in the period: the sum of
	 * `daily.unique_ips` for every UTC day that lies fully inside it (0 or 1 day for `24h`).
	 *
	 * @param Period $p Period.
	 * @return array{requests: int, php_hours: float, bot_share: float, bot_php_hours: float, verified_search_requests: int, unique_client_days: int}
	 */
	public function totals( Period $p ): array {
		return $this->memo(
			'totals',
			$p,
			function () use ( $p ) {
				$in      = $this->in( self::SEARCH_CLASSES );
				$row     = $this->row(
					"SELECT COALESCE(SUM(requests), 0) AS requests, COALESCE(SUM(php_ms_sum), 0) AS php,
						COALESCE(SUM(CASE WHEN client_class <> %s THEN php_ms_sum ELSE 0 END), 0) AS bot_php,
						COALESCE(SUM(CASE WHEN verified = 1 AND client_class IN ({$in}) THEN requests ELSE 0 END), 0) AS vsr
					FROM {$this->t['hourly']} WHERE hour >= %d AND hour < %d",
					array_merge( array( 'people' ), self::SEARCH_CLASSES, array( $p->start, $p->end ) )
				);
				$php     = (int) ( $row['php'] ?? 0 );
				$bot_php = (int) ( $row['bot_php'] ?? 0 );
				$first   = (int) ( ceil( $p->start / DAY_IN_SECONDS ) * DAY_IN_SECONDS );
				$stop    = $p->end - $p->end % DAY_IN_SECONDS;
				$uniques = $first >= $stop ? 0 : $this->wpdb->get_var(
					$this->wpdb->prepare(
						"SELECT COALESCE(SUM(unique_ips), 0) FROM {$this->t['daily']} WHERE day >= %s AND day < %s",
						gmdate( 'Y-m-d', $first ),
						gmdate( 'Y-m-d', $stop )
					)
				);
				return array(
					'requests'                 => (int) ( $row['requests'] ?? 0 ),
					'php_hours'                => self::hours( $php ),
					'bot_share'                => self::share( $bot_php, $php ),
					'bot_php_hours'            => self::hours( $bot_php ),
					'verified_search_requests' => (int) ( $row['vsr'] ?? 0 ),
					'unique_client_days'       => (int) $uniques,
				);
			}
		);
	}

	/**
	 * PHP hours per site-local day and class. Every local day of the period is present, in
	 * order, also without data.
	 *
	 * @param Period $p Period.
	 * @return list<array{day: string, by_class: array<string, float>}>
	 */
	public function perDay( Period $p ): array {
		return $this->memo(
			'perDay',
			$p,
			function () use ( $p ) {
				$tz   = wp_timezone();
				$ms   = array();
				$day  = ( new \DateTimeImmutable( '@' . $p->start ) )->setTimezone( $tz )->setTime( 0, 0 );
				$last = ( new \DateTimeImmutable( '@' . ( $p->end - 1 ) ) )->setTimezone( $tz )->format( 'Y-m-d' );
				$date = $day->format( 'Y-m-d' );
				while ( $date <= $last ) {
					$ms[ $date ] = array();
					$day         = $day->modify( '+1 day' );
					$date        = $day->format( 'Y-m-d' );
				}
				$rows = $this->rows(
					"SELECT hour, client_class, SUM(php_ms_sum) AS php FROM {$this->t['hourly']}
					WHERE hour >= %d AND hour < %d GROUP BY hour, client_class",
					array( $p->start, $p->end )
				);
				foreach ( $rows as $row ) {
					$date  = ( new \DateTimeImmutable( '@' . (int) $row['hour'] ) )->setTimezone( $tz )->format( 'Y-m-d' );
					$class = (string) $row['client_class'];
					if ( isset( $ms[ $date ] ) ) {
						$ms[ $date ][ $class ] = ( $ms[ $date ][ $class ] ?? 0 ) + (int) $row['php'];
					}
				}
				$out = array();
				foreach ( $ms as $date => $classes ) {
					$out[] = array(
						'day'      => (string) $date,
						'by_class' => array_map( array( self::class, 'hours' ), self::byClassOrder( $classes ) ),
					);
				}
				return $out;
			}
		);
	}

	/**
	 * Visitors: one row per known bot, one per impersonated bot, plus one per class for rows
	 * without a bot (including the aggregator's overflow rows, which keep their class). Sorted by
	 * PHP hours, most first.
	 *
	 * `id` is the bots.json id or ''. `verified` sums requests by verified code (1 yes, 2 no,
	 * 3 unchecked); `findability` comes from the bot list. Requests that claimed a bot and failed
	 * verification (`unknown_bot`, verified 2) form their own row: label "<name>" (the
	 * claimed bot's name), class `unknown_bot`, findability false, `impostor` true.
	 *
	 * @param Period $p     Period.
	 * @param int    $limit Most rows.
	 * @return list<array{id: string, bot: string, class: string, requests: int, php_hours: float, verified: array{yes: int, no: int, unchecked: int}, findability: bool, impostor: bool}>
	 */
	public function visitors( Period $p, int $limit = 25 ): array {
		$all = $this->memo(
			'visitors',
			$p,
			function () use ( $p ) {
				$rows  = $this->rows(
					"SELECT bot_id, client_class, verified, SUM(requests) AS requests, SUM(php_ms_sum) AS php
					FROM {$this->t['hourly']} WHERE hour >= %d AND hour < %d GROUP BY bot_id, client_class, verified",
					array( $p->start, $p->end )
				);
				$names = $this->dictValues( 'bot', array_column( $rows, 'bot_id' ) );
				$keys  = array(
					1 => 'yes',
					2 => 'no',
					3 => 'unchecked',
				);
				$acc   = array();
				foreach ( $rows as $row ) {
					$bot_id = (int) $row['bot_id'];
					$class  = (string) $row['client_class'];
					$id     = $bot_id > 0 ? ( $names[ $bot_id ] ?? '' ) : '';
					$fake   = '' !== $id && 'unknown_bot' === $class && 2 === (int) $row['verified'];
					if ( $fake ) {
						$key = 'i:' . $id;
					} else {
						$key = '' !== $id ? 'b:' . $id : 'c:' . $class;
					}
					if ( ! isset( $acc[ $key ] ) ) {
						$acc[ $key ] = array(
							'id'       => $id,
							'impostor' => $fake,
							'requests' => 0,
							'php'      => 0,
							'verified' => array(
								'yes'       => 0,
								'no'        => 0,
								'unchecked' => 0,
							),
							'classes'  => array(),
						);
					}
					$requests                         = (int) $row['requests'];
					$acc[ $key ]['requests']         += $requests;
					$acc[ $key ]['php']              += (int) $row['php'];
					$acc[ $key ]['classes'][ $class ] = ( $acc[ $key ]['classes'][ $class ] ?? 0 ) + $requests;
					$code                             = (int) $row['verified'];
					if ( isset( $keys[ $code ] ) ) {
						$acc[ $key ]['verified'][ $keys[ $code ] ] += $requests;
					}
				}
				$out = array();
				foreach ( $acc as $entry ) {
					$bot = '' !== $entry['id'] ? $this->bots->get( $entry['id'] ) : null;
					arsort( $entry['classes'] );
					if ( $entry['impostor'] ) {
						$class = 'unknown_bot';
					} else {
						$class = null !== $bot ? (string) $bot['class'] : (string) array_key_first( $entry['classes'] );
					}
					// An impostor row keeps the claimed bot's name; `impostor: true` marks it.
					if ( null !== $bot ) {
						$label = (string) $bot['name'];
					} elseif ( '' !== $entry['id'] ) {
						$label = $entry['id'];
					} else {
						$label = self::classLabel( $class );
					}
					$out[] = array(
						'id'          => $entry['id'],
						'bot'         => $label,
						'class'       => $class,
						'requests'    => $entry['requests'],
						'php_ms'      => $entry['php'],
						'verified'    => $entry['verified'],
						'findability' => ! $entry['impostor'] && null !== $bot && ! empty( $bot['findability'] ),
						'impostor'    => $entry['impostor'],
					);
				}
				usort(
					$out,
					static function ( array $a, array $b ): int {
						return array( $b['php_ms'], $b['requests'], $a['bot'] ) <=> array( $a['php_ms'], $a['requests'], $b['bot'] );
					}
				);
				foreach ( $out as $i => $row ) {
					$out[ $i ] = array(
						'id'          => $row['id'],
						'bot'         => $row['bot'],
						'class'       => $row['class'],
						'requests'    => $row['requests'],
						'php_hours'   => self::hours( $row['php_ms'] ),
						'verified'    => $row['verified'],
						'findability' => $row['findability'],
						'impostor'    => $row['impostor'],
					);
				}
				return $out;
			}
		);
		return array_slice( $all, 0, max( 0, $limit ) );
	}

	/**
	 * Query-parameter signatures by PHP time, with the bot share of each. Requests without
	 * parameters are `(none)`; the aggregator's overflow rows (`status_class` `xxx`, signature
	 * dropped) are grouped apart as `(other combinations)`.
	 *
	 * @param Period $p     Period.
	 * @param int    $limit Most rows.
	 * @return list<array{params: string, requests: int, php_hours: float, bot_share: float}>
	 */
	public function params( Period $p, int $limit = 15 ): array {
		$rows   = $this->rows(
			"SELECT param_sig_id, IF(status_class = %s, 1, 0) AS overflow, SUM(requests) AS requests, SUM(php_ms_sum) AS php,
				SUM(CASE WHEN client_class <> %s THEN php_ms_sum ELSE 0 END) AS bot_php
			FROM {$this->t['hourly']} WHERE hour >= %d AND hour < %d
			GROUP BY param_sig_id, overflow ORDER BY php DESC, requests DESC, param_sig_id ASC, overflow ASC LIMIT %d",
			array( self::OVERFLOW_STATUS, 'people', $p->start, $p->end, max( 0, $limit ) )
		);
		$values = $this->dictValues( 'param_sig', array_column( $rows, 'param_sig_id' ) );
		$out    = array();
		foreach ( $rows as $row ) {
			$value = $values[ (int) $row['param_sig_id'] ] ?? '';
			if ( 1 === (int) $row['overflow'] ) {
				$value = __( '(other combinations)', 'bot-insight' );
			} elseif ( '' === $value ) {
				$value = __( '(none)', 'bot-insight' );
			}
			$out[] = array(
				'params'    => $value,
				'requests'  => (int) $row['requests'],
				'php_hours' => self::hours( (int) $row['php'] ),
				'bot_share' => self::share( (int) $row['bot_php'], (int) $row['php'] ),
			);
		}
		return $out;
	}

	/**
	 * Countries of unknown bots, suspected clients and script libraries by PHP time. An unknown
	 * country is `??`.
	 *
	 * @param Period $p     Period.
	 * @param int    $limit Most rows.
	 * @return list<array{country: string, requests: int, php_hours: float}>
	 */
	public function unknownCountries( Period $p, int $limit = 10 ): array {
		$in   = $this->in( self::UNKNOWN_CLASSES );
		$rows = $this->rows(
			"SELECT IF(country = '', '??', country) AS cc, SUM(requests) AS requests, SUM(php_ms_sum) AS php
			FROM {$this->t['hourly']} WHERE hour >= %d AND hour < %d AND client_class IN ({$in})
			GROUP BY cc ORDER BY php DESC, requests DESC, cc ASC LIMIT %d",
			array_merge( array( $p->start, $p->end ), self::UNKNOWN_CLASSES, array( max( 0, $limit ) ) )
		);
		$out  = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'country'   => (string) $row['cc'],
				'requests'  => (int) $row['requests'],
				'php_hours' => self::hours( (int) $row['php'] ),
			);
		}
		return $out;
	}

	/**
	 * Estimated requests per signal among suspected clients: the weights (the sample rate each row
	 * was taken at) of sample rows of class `suspected`, per signal bit, within the sample window.
	 * Every signal slug is present.
	 *
	 * @param Period $p Period.
	 * @return array<string, int>
	 */
	public function signalsBreakdown( Period $p ): array {
		$rows   = $this->rows(
			"SELECT signals, SUM(weight) AS n FROM {$this->t['sample']}
			WHERE ts >= %d AND ts < %d AND client_class = %s GROUP BY signals",
			array( $this->sampleStart( $p ), $p->end, 'suspected' )
		);
		$counts = array_fill_keys( array_values( Classifier::SIGNALS ), 0 );
		foreach ( $rows as $row ) {
			foreach ( Classifier::SIGNALS as $bit => $slug ) {
				if ( (int) $row['signals'] & $bit ) {
					$counts[ $slug ] += (int) $row['n'];
				}
			}
		}
		return $counts;
	}

	/**
	 * Days that sample-based figures cover when the period is longer than the sample window, else 0.
	 *
	 * @param Period $p Period.
	 */
	public function sampleDays( Period $p ): int {
		return $this->sampleStart( $p ) > $p->start ? self::SAMPLE_DAYS : 0;
	}

	/**
	 * Start of the sample window for a period: the period start, or SAMPLE_DAYS before the
	 * period's last hour when that is later.
	 *
	 * @param Period $p Period.
	 */
	private function sampleStart( Period $p ): int {
		return max( $p->start, $p->end - HOUR_IN_SECONDS - self::SAMPLE_DAYS * DAY_IN_SECONDS );
	}

	/**
	 * The plugin's own time per request: the mean of `overhead_p95_us` over the newest seven
	 * snapshots that have a non-zero value, and how many snapshots that used.
	 *
	 * @return array{p95_us: int, days: int}
	 */
	public function overhead(): array {
		$rows   = $this->wpdb->get_col(
			$this->wpdb->prepare( "SELECT data FROM {$this->t['snapshot']} ORDER BY day DESC LIMIT %d", self::OVERHEAD_SCAN )
		);
		$values = array();
		foreach ( (array) $rows as $json ) {
			$data = json_decode( (string) $json, true );
			$us   = is_array( $data ) ? (int) ( $data['overhead_p95_us'] ?? 0 ) : 0;
			if ( $us > 0 ) {
				$values[] = $us;
				if ( 7 === count( $values ) ) {
					break;
				}
			}
		}
		return array(
			'p95_us' => array() === $values ? 0 : (int) round( array_sum( $values ) / count( $values ) ),
			'days'   => count( $values ),
		);
	}

	/**
	 * The newest snapshot, decoded, or null when there is none.
	 *
	 * @return array<string, mixed>|null
	 */
	public function latestSnapshot(): ?array {
		if ( ! array_key_exists( 'latestSnapshot', $this->cache ) ) {
			$json = $this->wpdb->get_var(
				$this->wpdb->prepare( "SELECT data FROM {$this->t['snapshot']} ORDER BY day DESC LIMIT %d", 1 )
			);
			$data = is_string( $json ) ? json_decode( $json, true ) : null;

			$this->cache['latestSnapshot'] = is_array( $data ) ? $data : null;
		}
		return $this->cache['latestSnapshot'];
	}

	/**
	 * Site events in the period, oldest first, at most 500.
	 *
	 * @param Period $p Period.
	 * @return list<array{ts: int, type: string, subject: string, detail: string}>
	 */
	public function events( Period $p ): array {
		$rows = $this->rows(
			"SELECT ts, type, subject, detail FROM {$this->t['events']}
			WHERE ts >= %d AND ts < %d ORDER BY ts ASC, id ASC LIMIT %d",
			array( $p->start, $p->end, self::EVENTS_MAX )
		);
		$out  = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'ts'      => (int) $row['ts'],
				'type'    => (string) $row['type'],
				'subject' => (string) $row['subject'],
				'detail'  => (string) $row['detail'],
			);
		}
		return $out;
	}

	/**
	 * Hourly export: one row per hour, class and bot, ordered by hour. `hour` is ISO-8601 UTC,
	 * `bot` the bots.json id or '', `verified` the code with the most requests.
	 *
	 * @param Period $p Period.
	 * @return list<array{hour: string, class: string, bot: string, requests: int, php_ms: int, verified: int}>
	 */
	public function exportHourly( Period $p ): array {
		$rows  = $this->rows(
			"SELECT hour, client_class, bot_id, verified, SUM(requests) AS requests, SUM(php_ms_sum) AS php
			FROM {$this->t['hourly']} WHERE hour >= %d AND hour < %d
			GROUP BY hour, client_class, bot_id, verified ORDER BY hour ASC, client_class ASC, bot_id ASC, verified ASC",
			array( $p->start, $p->end )
		);
		$names = $this->dictValues( 'bot', array_column( $rows, 'bot_id' ) );
		$acc   = array();
		foreach ( $rows as $row ) {
			$key = $row['hour'] . '|' . $row['client_class'] . '|' . $row['bot_id'];
			if ( ! isset( $acc[ $key ] ) ) {
				$bot_id      = (int) $row['bot_id'];
				$acc[ $key ] = array(
					'hour'     => (int) $row['hour'],
					'class'    => (string) $row['client_class'],
					'bot'      => $bot_id > 0 ? ( $names[ $bot_id ] ?? '' ) : '',
					'requests' => 0,
					'php_ms'   => 0,
					'by_code'  => array(),
				);
			}
			$code                            = (int) $row['verified'];
			$acc[ $key ]['requests']        += (int) $row['requests'];
			$acc[ $key ]['php_ms']          += (int) $row['php'];
			$acc[ $key ]['by_code'][ $code ] = ( $acc[ $key ]['by_code'][ $code ] ?? 0 ) + (int) $row['requests'];
		}
		$out = array();
		foreach ( $acc as $entry ) {
			$verified = 0;
			$most     = -1;
			foreach ( $entry['by_code'] as $code => $requests ) {
				if ( $requests > $most ) {
					$verified = (int) $code;
					$most     = $requests;
				}
			}
			$out[] = array(
				'hour'     => gmdate( 'Y-m-d\TH:i:s\Z', $entry['hour'] ),
				'class'    => $entry['class'],
				'bot'      => $entry['bot'],
				'requests' => $entry['requests'],
				'php_ms'   => $entry['php_ms'],
				'verified' => $verified,
			);
		}
		usort(
			$out,
			static function ( array $a, array $b ): int {
				return array( $a['hour'], $a['class'], $a['bot'] ) <=> array( $b['hour'], $b['class'], $b['bot'] );
			}
		);
		return $out;
	}

	/**
	 * Requests in total and requests that reached PHP without passing the CDN (`via_cdn = 0`).
	 *
	 * @param Period $p Period.
	 * @return array{requests: int, direct_requests: int, direct_share: float}
	 */
	public function originRequests( Period $p ): array {
		$row      = $this->row(
			"SELECT COALESCE(SUM(requests), 0) AS requests, COALESCE(SUM(CASE WHEN via_cdn = 0 THEN requests ELSE 0 END), 0) AS direct
			FROM {$this->t['hourly']} WHERE hour >= %d AND hour < %d",
			array( $p->start, $p->end )
		);
		$requests = (int) ( $row['requests'] ?? 0 );
		$direct   = (int) ( $row['direct'] ?? 0 );
		return array(
			'requests'        => $requests,
			'direct_requests' => $direct,
			'direct_share'    => self::share( $direct, $requests ),
		);
	}

	/**
	 * Estimated GET requests with an `add-to-cart` parameter from every class except people: the
	 * weights of the matching sample rows within the sample window (see sampleDays()). Only the
	 * sample table knows the method.
	 *
	 * @param Period $p Period.
	 */
	public function getAddToCartRequests( Period $p ): int {
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COALESCE(SUM(s.weight), 0) FROM {$this->t['sample']} s
				JOIN {$this->t['dict']} d ON d.id = s.param_sig_id AND d.type = %s
				WHERE s.ts >= %d AND s.ts < %d AND s.method = %s AND s.client_class <> %s
				AND FIND_IN_SET(%s, d.value) > 0",
				'param_sig',
				$this->sampleStart( $p ),
				$p->end,
				'GET',
				'people',
				'add-to-cart'
			)
		);
	}

	/**
	 * Share (0..1) of all PHP time spent on requests with an uncached reason.
	 *
	 * @param Period $p      Period.
	 * @param string $reason Uncached reason, e.g. `query_string`.
	 */
	public function uncachedShare( Period $p, string $reason ): float {
		$row = $this->row(
			"SELECT COALESCE(SUM(php_ms_sum), 0) AS php, COALESCE(SUM(CASE WHEN uncached_reason = %s THEN php_ms_sum ELSE 0 END), 0) AS hit
			FROM {$this->t['hourly']} WHERE hour >= %d AND hour < %d",
			array( $reason, $p->start, $p->end )
		);
		return self::share( (int) ( $row['hit'] ?? 0 ), (int) ( $row['php'] ?? 0 ) );
	}

	/**
	 * Sessions created by every class except people.
	 *
	 * @param Period $p Period.
	 */
	public function botSessions( Period $p ): int {
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COALESCE(SUM(sessions_created), 0) FROM {$this->t['hourly']} WHERE hour >= %d AND hour < %d AND client_class <> %s",
				$p->start,
				$p->end,
				'people'
			)
		);
	}

	/**
	 * Milliseconds as hours, two decimals.
	 *
	 * @param int $ms Milliseconds.
	 */
	public static function hours( int $ms ): float {
		return round( $ms / self::MS_PER_HOUR, 2 );
	}

	/**
	 * Part of a whole (0..1), four decimals; 0 for an empty whole.
	 *
	 * @param int $part  Part.
	 * @param int $whole Whole.
	 */
	private static function share( int $part, int $whole ): float {
		return $whole > 0 ? round( $part / $whole, 4 ) : 0.0;
	}

	/**
	 * Sample rate in effect now: an integer `sample_rate` setting wins, else option
	 * `bot_insight_sample_rate`. Same rule as the folder.
	 */
	public function sampleRateInEffect(): int {
		$settings = get_option( 'bot_insight_settings', array() );
		if ( is_array( $settings ) && isset( $settings['sample_rate'] ) && is_int( $settings['sample_rate'] ) ) {
			return max( 1, $settings['sample_rate'] );
		}
		return max( 1, (int) get_option( 'bot_insight_sample_rate', 1 ) );
	}

	/**
	 * Classes in Classifier order, unknown ones last.
	 *
	 * @param array<string, int> $by_class Values by class.
	 * @return array<string, int>
	 */
	private static function byClassOrder( array $by_class ): array {
		$out = array();
		foreach ( Classifier::CLASSES as $class ) {
			if ( isset( $by_class[ $class ] ) ) {
				$out[ $class ] = $by_class[ $class ];
				unset( $by_class[ $class ] );
			}
		}
		ksort( $by_class );
		return $out + $by_class;
	}

	/**
	 * Cached result of a period query.
	 *
	 * @param string   $name Method name.
	 * @param Period   $p    Period.
	 * @param callable $producer Producer.
	 * @return mixed
	 */
	private function memo( string $name, Period $p, callable $producer ) {
		$key = $name . ':' . $p->start . ':' . $p->end;
		if ( ! array_key_exists( $key, $this->cache ) ) {
			$this->cache[ $key ] = $producer();
		}
		return $this->cache[ $key ];
	}

	/**
	 * Dictionary values for ids, 0 left out.
	 *
	 * @param string       $type Dictionary type.
	 * @param array<mixed> $ids  Ids.
	 * @return array<int, string>
	 */
	private function dictValues( string $type, array $ids ): array {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		return array() === $ids ? array() : $this->dict->values( $type, $ids );
	}

	/**
	 * `%s` placeholders for a list.
	 *
	 * @param array<mixed> $values Values.
	 */
	private function in( array $values ): string {
		return implode( ',', array_fill( 0, count( $values ), '%s' ) );
	}

	/**
	 * Rows of a prepared query.
	 *
	 * @param string       $sql  SQL with placeholders.
	 * @param array<mixed> $args Values.
	 * @return array<int, array<string, mixed>>
	 */
	private function rows( string $sql, array $args ): array {
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $args ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * First row of a prepared query.
	 *
	 * @param string       $sql  SQL with placeholders.
	 * @param array<mixed> $args Values.
	 * @return array<string, mixed>
	 */
	private function row( string $sql, array $args ): array {
		$row = $this->wpdb->get_row( $this->wpdb->prepare( $sql, $args ), ARRAY_A );
		return is_array( $row ) ? $row : array();
	}
}
