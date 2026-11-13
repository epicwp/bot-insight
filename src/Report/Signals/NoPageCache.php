<?php
/**
 * Signal: no page cache detected.
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
 * The snapshot found neither a page cache plugin nor an advanced-cache drop-in.
 */
final class NoPageCache implements Signal {

	/**
	 * Id.
	 */
	public function id(): string {
		return 'no_page_cache';
	}

	/**
	 * Evaluate.
	 *
	 * @param Reports $r Report queries.
	 * @param Period  $p Period.
	 * @return array{id: string, severity: string, title: string, facts: array<string, int|float|string>}|null
	 */
	public function evaluate( Reports $r, Period $p ): ?array {
		$snapshot = $r->latestSnapshot();
		if ( null === $snapshot || ! array_key_exists( 'page_cache', $snapshot ) ) {
			return null;
		}
		$cache = $snapshot['page_cache'];
		if ( is_array( $cache ) ? ( ! empty( $cache['dropin'] ) || '' !== (string) ( $cache['plugin'] ?? '' ) ) : (bool) $cache ) {
			return null;
		}
		$totals = $r->totals( $p );
		return array(
			'id'       => $this->id(),
			'severity' => 'notice',
			'title'    => sprintf(
				/* translators: 1: number of requests, 2: hours of PHP time */
				__( 'No page cache was detected; %1$s requests used %2$s hours of PHP time in this period.', 'bot-insight' ),
				number_format_i18n( $totals['requests'] ),
				Signals::number( $totals['php_hours'] )
			),
			'facts'    => array(
				'requests'  => $totals['requests'],
				'php_hours' => $totals['php_hours'],
			),
		);
	}
}
