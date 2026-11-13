<?php
/**
 * Signal: requests that reached PHP without passing the CDN.
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
 * The snapshot shows Cloudflare in front of the site, yet more than 1% of requests reached PHP
 * without passing through it.
 */
final class OriginBypass implements Signal {

	/**
	 * Share of direct requests above which the signal fires.
	 */
	public const THRESHOLD = 0.01;

	/**
	 * Id.
	 */
	public function id(): string {
		return 'origin_bypass';
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
		if ( null === $snapshot || 'cloudflare' !== ( $snapshot['cdn'] ?? '' ) ) {
			return null;
		}
		$origin = $r->originRequests( $p );
		if ( $origin['direct_share'] <= self::THRESHOLD ) {
			return null;
		}
		return array(
			'id'       => $this->id(),
			'severity' => 'notice',
			'title'    => sprintf(
				/* translators: 1: number of requests, 2: percentage of all requests */
				__( '%1$s requests (%2$s%% of all requests) reached PHP without passing through Cloudflare.', 'bot-insight' ),
				number_format_i18n( $origin['direct_requests'] ),
				Signals::number( $origin['direct_share'] * 100, 1 )
			),
			'facts'    => $origin,
		);
	}
}
