<?php
/**
 * Signal: PHP time of requests that skipped the cache for a query string.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Report\Signals;

use BotInsight\Report\Period;
use BotInsight\Report\Reports;
use BotInsight\Report\Signal;
use BotInsight\Report\Signals;

/**
 * More than 20% of PHP time went to requests the page cache skipped because of a query string.
 */
final class QueryStringBypass implements Signal {

	/**
	 * Share of PHP time above which the signal fires.
	 */
	public const THRESHOLD = 0.2;

	/**
	 * Id.
	 */
	public function id(): string {
		return 'query_string_bypass';
	}

	/**
	 * Evaluate.
	 *
	 * @param Reports $r Report queries.
	 * @param Period  $p Period.
	 * @return array{id: string, severity: string, title: string, facts: array<string, int|float|string>}|null
	 */
	public function evaluate( Reports $r, Period $p ): ?array {
		$share = $r->uncachedShare( $p, 'query_string' );
		if ( $share <= self::THRESHOLD ) {
			return null;
		}
		$hours = round( $r->totals( $p )['php_hours'] * $share, 2 );
		return array(
			'id'       => $this->id(),
			'severity' => 'notice',
			'title'    => sprintf(
				/* translators: 1: percentage of PHP time, 2: hours of PHP time */
				__( '%1$s%% of PHP time (%2$s hours) went to requests that skipped the page cache because of a query string.', 'bot-insight' ),
				Signals::number( $share * 100, 1 ),
				Signals::number( $hours )
			),
			'facts'    => array(
				'share'     => $share,
				'php_hours' => $hours,
			),
		);
	}
}
