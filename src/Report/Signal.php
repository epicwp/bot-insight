<?php
/**
 * Signal contract.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Report;

/**
 * A factual statement about the site's traffic or setup. Never advice.
 */
interface Signal {

	/**
	 * Stable id, snake_case.
	 */
	public function id(): string;

	/**
	 * The signal for a period, or null when it does not apply.
	 *
	 * @param Reports $r Report queries.
	 * @param Period  $p Period.
	 * @return array{id: string, severity: string, title: string, facts: array<string, int|float|string>}|null
	 *         Severity is `info` or `notice`; the title is a translated factual sentence; facts
	 *         hold the raw numbers.
	 */
	public function evaluate( Reports $r, Period $p ): ?array;
}
