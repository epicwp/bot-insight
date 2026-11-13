<?php
/**
 * Signal: robots.txt Allow: / groups for AI training or SEO bots.
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
 * The robots.txt file has a group for an AI training or SEO bot with `Allow: /`, while the `*` group
 * disallows paths. Those bots then follow only their own group and ignore the `*` rules.
 */
final class RobotsPerBotAllow implements Signal {

	/**
	 * Bot classes checked.
	 */
	private const CLASSES = array( 'ai_training', 'seo' );

	/**
	 * Id.
	 */
	public function id(): string {
		return 'robots_per_bot_allow';
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
		$groups   = $snapshot['robots']['groups'] ?? null;
		if ( ! is_array( $groups ) ) {
			return null;
		}
		$star = 0;
		foreach ( $groups as $group ) {
			if ( in_array( '*', (array) ( $group['agents'] ?? array() ), true ) ) {
				$star += count(
					array_filter(
						(array) ( $group['disallow'] ?? array() ),
						static function ( $path ): bool {
							return '' !== (string) $path;
						}
					)
				);
			}
		}
		if ( 0 === $star ) {
			return null;
		}
		$known = array();
		foreach ( $r->bots()->all() as $bot ) {
			if ( in_array( $bot['class'], self::CLASSES, true ) ) {
				foreach ( array_merge( array( $bot['name'] ), (array) $bot['patterns'] ) as $name ) {
					$known[ strtolower( (string) $name ) ] = true;
				}
			}
		}
		$agents = array();
		foreach ( $groups as $group ) {
			if ( ! in_array( '/', (array) ( $group['allow'] ?? array() ), true ) ) {
				continue;
			}
			foreach ( (array) ( $group['agents'] ?? array() ) as $agent ) {
				$agent = trim( (string) $agent );
				if ( isset( $known[ strtolower( $agent ) ] ) && ! in_array( $agent, $agents, true ) ) {
					$agents[] = $agent;
				}
			}
		}
		if ( array() === $agents ) {
			return null;
		}
		$list = implode( ', ', $agents );
		return array(
			'id'       => $this->id(),
			'severity' => 'notice',
			'title'    => sprintf(
				/* translators: 1: comma-separated robots.txt user agents, 2: number of disallowed paths */
				_n(
					'robots.txt has an Allow: / group for %1$s while the * group disallows %2$s path.',
					'robots.txt has an Allow: / group for %1$s while the * group disallows %2$s paths.',
					$star,
					'bot-insight'
				),
				$list,
				number_format_i18n( $star )
			),
			'facts'    => array(
				'agents'         => $list,
				'star_disallows' => $star,
			),
		);
	}
}
