<?php
/**
 * Reporting period.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Report;

/**
 * A span of whole hours: `start` inclusive, `end` exclusive, both UTC hour-start timestamps.
 * Every period ends with the current (partial) hour.
 */
final class Period {

	/**
	 * Full hours before the current one, per key.
	 */
	public const KEYS = array(
		'24h' => 24,
		'7d'  => 168,
		'30d' => 720,
	);

	/**
	 * Key used for an unknown key.
	 */
	public const DEFAULT_KEY = '7d';

	/**
	 * Period key.
	 *
	 * @var string
	 */
	public $key;

	/**
	 * First hour, inclusive.
	 *
	 * @var int
	 */
	public $start;

	/**
	 * End hour, exclusive.
	 *
	 * @var int
	 */
	public $end;

	/**
	 * Constructor.
	 *
	 * @param string $key   Period key.
	 * @param int    $start First hour, inclusive.
	 * @param int    $end   End hour, exclusive.
	 */
	private function __construct( string $key, int $start, int $end ) {
		$this->key   = $key;
		$this->start = $start;
		$this->end   = $end;
	}

	/**
	 * The period for a key (`24h`, `7d`, `30d`; anything else is `7d`) at a time.
	 *
	 * `24h` covers the last 24 full hours plus the current hour; `7d` and `30d` likewise.
	 *
	 * @param string $key Period key.
	 * @param int    $now Unix time.
	 */
	public static function fromKey( string $key, int $now ): Period {
		if ( ! isset( self::KEYS[ $key ] ) ) {
			$key = self::DEFAULT_KEY;
		}
		$hour = $now - $now % HOUR_IN_SECONDS;
		return new self( $key, $hour - self::KEYS[ $key ] * HOUR_IN_SECONDS, $hour + HOUR_IN_SECONDS );
	}
}
