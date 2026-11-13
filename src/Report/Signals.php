<?php
/**
 * Signal registry.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Report;

use BotInsight\Report\Signals\BotSessions;
use BotInsight\Report\Signals\GetAddToCart;
use BotInsight\Report\Signals\NoPageCache;
use BotInsight\Report\Signals\OriginBypass;
use BotInsight\Report\Signals\QueryStringBypass;
use BotInsight\Report\Signals\RobotsPerBotAllow;

/**
 * The known signals and their safe evaluation.
 */
final class Signals {

	/**
	 * Filter that receives and returns the list of Signal instances.
	 */
	public const FILTER = 'bot_insight_signals';

	/**
	 * Allowed severities; anything else becomes `info`.
	 */
	private const SEVERITIES = array( 'info', 'notice' );

	/**
	 * Every registered signal. Filter `bot_insight_signals` may add or remove some; values that
	 * are not Signal instances are dropped.
	 *
	 * @return Signal[]
	 */
	public static function all(): array {
		$list = array(
			new OriginBypass(),
			new GetAddToCart(),
			new RobotsPerBotAllow(),
			new NoPageCache(),
			new QueryStringBypass(),
			new BotSessions(),
		);
		/**
		 * Filters the signals evaluated for the dashboard.
		 *
		 * @param Signal[] $list Signal instances.
		 */
		$filtered = apply_filters( 'bot_insight_signals', $list );
		if ( ! is_array( $filtered ) ) {
			return $list;
		}
		return array_values(
			array_filter(
				$filtered,
				static function ( $signal ): bool {
					return $signal instanceof Signal;
				}
			)
		);
	}

	/**
	 * Evaluate signals, each on its own: a signal that throws or returns something malformed is
	 * left out and never breaks the others.
	 *
	 * @param Reports       $r       Report queries.
	 * @param Period        $p       Period.
	 * @param Signal[]|null $signals Signals; default all().
	 * @return list<array{id: string, severity: string, title: string, facts: array<string, int|float|string>}>
	 */
	public static function evaluate( Reports $r, Period $p, ?array $signals = null ): array {
		$out = array();
		foreach ( null === $signals ? self::all() : $signals as $signal ) {
			try {
				/**
				 * Third-party signals may return anything; check it below.
				 *
				 * @var mixed $result
				 */
				$result = $signal->evaluate( $r, $p );
				$id     = $signal->id();
			} catch ( \Throwable $e ) {
				continue;
			}
			if ( ! is_array( $result ) || ! isset( $result['title'] ) || ! is_string( $result['title'] ) ) {
				continue;
			}
			$facts = array();
			foreach ( isset( $result['facts'] ) && is_array( $result['facts'] ) ? $result['facts'] : array() as $key => $value ) {
				if ( is_int( $value ) || is_float( $value ) || is_string( $value ) ) {
					$facts[ (string) $key ] = $value;
				}
			}
			$severity = $result['severity'] ?? 'info';
			$out[]    = array(
				'id'       => isset( $result['id'] ) && is_string( $result['id'] ) && '' !== $result['id'] ? $result['id'] : $id,
				'severity' => in_array( $severity, self::SEVERITIES, true ) ? $severity : 'info',
				'title'    => $result['title'],
				'facts'    => $facts,
			);
		}
		return $out;
	}

	/**
	 * A number for a title, localised, with no more decimals than it needs (at most `$max`).
	 *
	 * @param float $n   Number.
	 * @param int   $max Most decimals.
	 */
	public static function number( float $n, int $max = 2 ): string {
		$decimals = 0;
		while ( $decimals < $max && round( $n, $decimals ) !== round( $n, $max ) ) {
			++$decimals;
		}
		return number_format_i18n( $n, $decimals );
	}
}
