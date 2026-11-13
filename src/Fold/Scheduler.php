<?php
/**
 * Scheduling of the background jobs.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Fold;

use BotInsight\Site\Daily;

/**
 * Schedules the fold (every 5 minutes) and the daily job (00:10 UTC) with Action Scheduler when
 * it is available, else with WP-Cron.
 */
final class Scheduler {

	public const FOLD     = 'bot_insight_fold';
	public const DAILY    = 'bot_insight_daily';
	public const GROUP    = 'bot-insight';
	public const SCHEDULE = 'bot_insight_five_minutes';
	public const INTERVAL = 300;

	/**
	 * Register the callbacks, the WP-Cron interval and the admin self-heal. Called at boot.
	 */
	public static function hooks(): void {
		add_filter( 'cron_schedules', array( self::class, 'addSchedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval, WordPress.WP.CronInterval.ChangeDetected -- 300 s is the fold interval by design.
		add_action( self::FOLD, array( self::class, 'runFold' ) );
		add_action( self::DAILY, array( self::class, 'runDaily' ) );
		add_action( 'bot_insight_folded', array( Daily::class, 'recordLoad' ), 10, 0 );
		add_action( 'init', array( self::class, 'selfHeal' ), 20 );
	}

	/**
	 * Add the five-minute WP-Cron interval.
	 *
	 * @param mixed $schedules Schedules.
	 * @return array<string, mixed>
	 */
	public static function addSchedule( $schedules ): array {
		$schedules                   = is_array( $schedules ) ? $schedules : array();
		$schedules[ self::SCHEDULE ] = array(
			'interval' => self::INTERVAL,
			'display'  => __( 'Every five minutes (Bot Insight)', 'bot-insight' ),
		);
		return $schedules;
	}

	/**
	 * Schedule both jobs when they are not scheduled yet. Idempotent.
	 */
	public static function register(): void {
		$now = time();
		if ( function_exists( 'as_schedule_recurring_action' ) ) {
			// Moving to Action Scheduler: drop WP-Cron copies so a job never runs twice.
			wp_clear_scheduled_hook( self::FOLD );
			wp_clear_scheduled_hook( self::DAILY );
			if ( ! self::asScheduled( self::FOLD ) ) {
				as_schedule_recurring_action( $now, self::INTERVAL, self::FOLD, array(), self::GROUP );
			}
			if ( ! self::asScheduled( self::DAILY ) ) {
				as_schedule_recurring_action( self::nextDaily( $now ), DAY_IN_SECONDS, self::DAILY, array(), self::GROUP );
			}
			return;
		}
		if ( false === wp_next_scheduled( self::FOLD ) ) {
			wp_schedule_event( $now, self::SCHEDULE, self::FOLD );
		}
		if ( false === wp_next_scheduled( self::DAILY ) ) {
			wp_schedule_event( self::nextDaily( $now ), 'daily', self::DAILY );
		}
	}

	/**
	 * Remove both jobs from Action Scheduler and WP-Cron.
	 */
	public static function unregister(): void {
		foreach ( array( self::FOLD, self::DAILY ) as $hook ) {
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( $hook, array(), self::GROUP );
			}
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * In admin requests, schedule what is missing (e.g. after a cron table reset).
	 */
	public static function selfHeal(): void {
		if ( ! is_admin() || wp_doing_ajax() ) {
			return;
		}
		if ( self::hasActionScheduler() ) {
			$missing = ! self::asScheduled( self::FOLD ) || ! self::asScheduled( self::DAILY );
		} else {
			$missing = false === wp_next_scheduled( self::FOLD ) || false === wp_next_scheduled( self::DAILY );
		}
		if ( $missing ) {
			self::register();
		}
	}

	/**
	 * Fold callback. Never throws.
	 */
	public static function runFold(): void {
		try {
			Folder::create()->run( time() );
		} catch ( \Throwable $e ) {
			$old = get_option( Folder::STATUS_OPTION, array() );
			update_option(
				Folder::STATUS_OPTION,
				array(
					'last_run'   => (int) ( is_array( $old ) ? ( $old['last_run'] ?? 0 ) : 0 ),
					'last_error' => substr( '' !== $e->getMessage() ? $e->getMessage() : get_class( $e ), 0, 500 ),
					'files'      => 0,
					'records'    => 0,
				),
				false
			);
		}
	}

	/**
	 * Daily callback. Never throws.
	 */
	public static function runDaily(): void {
		try {
			Daily::create()->run( time() );
		} catch ( \Throwable $e ) {
			update_option(
				Daily::STATUS_OPTION,
				array(
					'last_run' => time(),
					'day'      => '',
					'steps'    => array(),
					'errors'   => array( 'run' => substr( '' !== $e->getMessage() ? $e->getMessage() : get_class( $e ), 0, 500 ) ),
				),
				false
			);
		}
	}

	/**
	 * Next 00:10 UTC strictly after a time.
	 *
	 * @param int $now Unix time.
	 */
	public static function nextDaily( int $now ): int {
		$next = $now - $now % DAY_IN_SECONDS + 600;
		return $next > $now ? $next : $next + DAY_IN_SECONDS;
	}

	/**
	 * Whether Action Scheduler can be used.
	 */
	private static function hasActionScheduler(): bool {
		return function_exists( 'as_schedule_recurring_action' );
	}

	/**
	 * Whether a hook is scheduled in Action Scheduler.
	 *
	 * @param string $hook Hook.
	 */
	private static function asScheduled( string $hook ): bool {
		if ( function_exists( 'as_has_scheduled_action' ) ) {
			return (bool) as_has_scheduled_action( $hook, null, self::GROUP );
		}
		return function_exists( 'as_next_scheduled_action' ) && false !== as_next_scheduled_action( $hook, null, self::GROUP );
	}
}
