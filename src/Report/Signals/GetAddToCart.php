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
		return array(
			'id'       => $this->id(),
			'severity' => 'notice',
			'title'    => sprintf(
				/* translators: %s: estimated number of requests */
				__( 'About %s GET requests with add-to-cart came from bots and suspected automated clients.', 'bot-insight' ),
				number_format_i18n( $requests )
			),
			'facts'    => array(
				'requests'    => $requests,
				'sample_rate' => $r->sampleRateInEffect(),
			),
		);
	}
}
