<?php
/**
 * Tests for the in-day sample rate adaptation.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Tests\Unit\Fold;

use BotInsight\Fold\SampleRate;
use PHPUnit\Framework\TestCase;

final class SampleRateTest extends TestCase {

	public function test_quiet_day_keeps_the_start_rate(): void {
		$this->assertSame( 1, SampleRate::adapt( 1, 0, 1200, 43200 ) );
		$this->assertSame( 4, SampleRate::adapt( 4, 0, 1200, 43200 ) );
	}

	public function test_busy_day_raises_the_rate_to_the_projected_budget(): void {
		// Half the day gone, 250,000 requests: on course for 500,000, so 1 in 100.
		$this->assertSame( 100, SampleRate::adapt( 1, 0, 250000, 43200 ) );
	}

	public function test_first_hour_counts_as_a_whole_hour(): void {
		// Five minutes in, 1,000 requests: projected over one hour, not five minutes.
		$this->assertSame( 5, SampleRate::adapt( 1, 0, 1000, 300 ) );
	}

	public function test_never_lower_than_the_rate_already_used_today(): void {
		$this->assertSame( 40, SampleRate::adapt( 1, 40, 10, 43200 ) );
		$this->assertSame( 40, SampleRate::adapt( 40, 3, 10, 43200 ) );
	}

	public function test_capped_to_the_weight_column(): void {
		$this->assertSame( SampleRate::MAX, SampleRate::adapt( 1, 0, PHP_INT_MAX, 86399 ) );
		$this->assertSame( 1, SampleRate::adapt( 0, 0, 0, 0 ) );
	}
}
