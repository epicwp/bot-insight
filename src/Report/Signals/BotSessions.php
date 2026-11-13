<?php
/**
 * Signal: sessions created for bots.
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
 * Bots and suspected automated clients caused sessions to be created.
 */
final class BotSessions implements Signal {

	/**
	 * Id.
	 */
	public function id(): string {
		return 'bot_sessions';
	}

	/**
	 * Evaluate.
	 *
	 * @param Reports $r Report queries.
	 * @param Period  $p Period.
	 * @return array{id: string, severity: string, title: string, facts: array<string, int|float|string>}|null
	 */
	public function evaluate( Reports $r, Period $p ): ?array {
		$sessions = $r->botSessions( $p );
		if ( $sessions <= 0 ) {
			return null;
		}
		return array(
			'id'       => $this->id(),
			'severity' => 'info',
			'title'    => sprintf(
				/* translators: %s: number of sessions */
				_n(
					'%s session was created for bots and suspected automated clients.',
					'%s sessions were created for bots and suspected automated clients.',
					$sessions,
					'bot-insight'
				),
				number_format_i18n( $sessions )
			),
			'facts'    => array( 'sessions' => $sessions ),
		);
	}
}
