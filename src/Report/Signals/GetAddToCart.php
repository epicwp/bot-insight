<?php
/**
 * Signal: GET add-to-cart requests from bots.
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
 * Bots and suspected automated clients sent GET requests with an `add-to-cart` parameter.
 */
final class GetAddToCart implements Signal {

	/**
	 * Id.
	 */
	public function id(): string {
		return 'get_add_to_cart';
	}

	/**
	 * Evaluate.
	 *
	 * @param Reports $r Report queries.
	 * @param Period  $p Period.
	 * @return array{id: string, severity: string, title: string, facts: array<string, int|float|string>}|null
	 */
	public function evaluate( Reports $r, Period $p ): ?array {
		$requests = $r->getAddToCartRequests( $p );
		if ( $requests <= 0 ) {
			return null;
		}
		$days = $r->sampleDays( $p );
		if ( $days > 0 ) {
			$title = sprintf(
				/* translators: 1: estimated number of requests, 2: number of days */
				__( 'About %1$s GET requests with add-to-cart came from bots and suspected automated clients in the last %2$s days.', 'bot-insight' ),
				number_format_i18n( $requests ),
				number_format_i18n( $days )
			);
		} else {
			$title = sprintf(
				/* translators: %s: estimated number of requests */
				__( 'About %s GET requests with add-to-cart came from bots and suspected automated clients.', 'bot-insight' ),
				number_format_i18n( $requests )
			);
		}
		return array(
			'id'       => $this->id(),
			'severity' => 'notice',
			'title'    => $title,
			'facts'    => array(
				'requests'    => $requests,
				'sample_days' => $days,
			),
		);
	}
}
