<?php
/**
 * Sample rate adaptation within a day.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Fold;

/**
 * Keeps the sample table near PER_DAY rows a day. The daily job sets the start rate of a day from
 * the day before; every fold raises it when today's traffic is on course for more than the budget.
 * Within a day the rate only goes up, and every sample row stores the rate it was taken at.
 */
final class SampleRate {

	/**
	 * Sample rows wanted per day.
	 */
	public const PER_DAY = 5000;

	/**
	 * Highest rate: the range of the sample `weight` column (smallint unsigned).
	 */
	public const MAX = 65535;

	/**
	 * Option holding the rate used so far today: array{day: string Ymd, rate: int}.
	 */
	public const DAY_OPTION = 'bot_insight_sample_rate_day';

	/**
	 * The rate for a fold run.
	 *
	 * @param int $start_rate       Start rate of the day (daily job).
	 * @param int $day_rate         Highest rate used earlier today, 0 when none.
	 * @param int $today_requests   Requests already folded today.
	 * @param int $seconds_into_day Seconds since 00:00 UTC; the first hour counts as a whole hour.
	 * @return int Between 1 and MAX.
	 */
	public static function adapt( int $start_rate, int $day_rate, int $today_requests, int $seconds_into_day ): int {
		$elapsed   = max( 3600, min( 86400, $seconds_into_day ) ) / 86400;
		$projected = (float) max( 0, $today_requests ) / ( self::PER_DAY * $elapsed );
		$rate      = $projected >= self::MAX ? self::MAX : (int) ceil( $projected );
		return max( 1, min( self::MAX, max( $start_rate, $day_rate, $rate ) ) );
	}
}
