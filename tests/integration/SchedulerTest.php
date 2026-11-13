<?php
/**
 * Scheduler with WP-Cron (Action Scheduler is not loaded in the test site).
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Tests\Integration;

use BotInsight\Buffer\FileBuffer;
use BotInsight\Fold\Folder;
use BotInsight\Fold\Scheduler;

final class SchedulerTest extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Scheduler::unregister();
	}

	public function tear_down(): void {
		Scheduler::unregister();
		delete_option( 'bot_insight_fold_status' );
		parent::tear_down();
	}

	public function test_five_minute_schedule_is_registered(): void {
		$schedules = wp_get_schedules();
		$this->assertArrayHasKey( 'bot_insight_five_minutes', $schedules );
		$this->assertSame( 300, $schedules['bot_insight_five_minutes']['interval'] );
	}

	public function test_register_schedules_fold_and_daily(): void {
		$this->assertFalse( function_exists( 'as_schedule_recurring_action' ) );

		Scheduler::register();

		$this->assertNotFalse( wp_next_scheduled( 'bot_insight_fold' ) );
		$this->assertSame( 'bot_insight_five_minutes', wp_get_schedule( 'bot_insight_fold' ) );
		$daily = wp_next_scheduled( 'bot_insight_daily' );
		$this->assertNotFalse( $daily );
		$this->assertSame( 'daily', wp_get_schedule( 'bot_insight_daily' ) );
		$this->assertSame( '00:10:00', gmdate( 'H:i:s', $daily ) );
		$this->assertGreaterThan( time(), $daily );
	}

	public function test_register_is_idempotent(): void {
		Scheduler::register();
		$first = wp_next_scheduled( 'bot_insight_fold' );
		Scheduler::register();
		$this->assertSame( $first, wp_next_scheduled( 'bot_insight_fold' ) );
		$crons = _get_cron_array();
		$count = 0;
		foreach ( $crons as $hooks ) {
			$count += isset( $hooks['bot_insight_fold'] ) ? count( $hooks['bot_insight_fold'] ) : 0;
		}
		$this->assertSame( 1, $count );
	}

	public function test_unregister_removes_both(): void {
		Scheduler::register();
		Scheduler::unregister();
		$this->assertFalse( wp_next_scheduled( 'bot_insight_fold' ) );
		$this->assertFalse( wp_next_scheduled( 'bot_insight_daily' ) );
	}

	public function test_self_heal_in_admin_restores_missing_events(): void {
		set_current_screen( 'dashboard' );
		Scheduler::selfHeal();
		$this->assertNotFalse( wp_next_scheduled( 'bot_insight_fold' ) );
		$this->assertNotFalse( wp_next_scheduled( 'bot_insight_daily' ) );
		set_current_screen( 'front' );
	}

	public function test_self_heal_does_nothing_on_the_front_end(): void {
		update_option( 'bot_insight_buffer_dir', '/nonexistent/bot-insight-gone' );
		Scheduler::selfHeal();
		$this->assertFalse( wp_next_scheduled( 'bot_insight_fold' ) );
		$this->assertSame( '/nonexistent/bot-insight-gone', get_option( 'bot_insight_buffer_dir' ) );
		delete_option( 'bot_insight_buffer_dir' );
	}

	public function test_self_heal_in_admin_recreates_a_missing_buffer_dir(): void {
		foreach ( array( '', '/nonexistent/bot-insight-gone' ) as $stored ) {
			update_option( 'bot_insight_buffer_dir', $stored );
			set_current_screen( 'dashboard' );
			Scheduler::selfHeal();
			set_current_screen( 'front' );

			$dir = get_option( 'bot_insight_buffer_dir' );
			$this->assertNotSame( $stored, $dir );
			$this->assertDirectoryExists( $dir );
			$this->assertTrue( FileBuffer::isOwnDir( $dir ) );
			( new FileBuffer( $dir ) )->removeDir();
		}
		delete_option( 'bot_insight_buffer_dir' );
	}

	public function test_hooks_are_wired(): void {
		$this->assertNotFalse( has_action( 'bot_insight_fold', array( Scheduler::class, 'runFold' ) ) );
		$this->assertNotFalse( has_action( 'bot_insight_daily', array( Scheduler::class, 'runDaily' ) ) );
		$this->assertNotFalse( has_action( 'init', array( Scheduler::class, 'selfHeal' ) ) );
	}

	public function test_next_daily_is_ten_past_midnight_utc(): void {
		$this->assertSame( 1794528600, Scheduler::nextDaily( 1794500000 ) ); // 2026-11-12 16:13 -> 2026-11-13 00:10.
		$this->assertSame( 1794528600, Scheduler::nextDaily( 1794528599 ) );
		$this->assertSame( 1794528600 + 86400, Scheduler::nextDaily( 1794528600 ) );
	}

	public function test_run_fold_without_buffer_dir_records_the_error_and_does_not_throw(): void {
		$dir = get_option( 'bot_insight_buffer_dir' );
		update_option( 'bot_insight_buffer_dir', '' );

		Scheduler::runFold();

		$status = get_option( 'bot_insight_fold_status' );
		$this->assertIsArray( $status );
		$this->assertNotSame( '', $status['last_error'] );
		update_option( 'bot_insight_buffer_dir', $dir );
	}

	public function test_create_builds_a_folder(): void {
		$dir = get_option( 'bot_insight_buffer_dir' );
		update_option( 'bot_insight_buffer_dir', sys_get_temp_dir() );
		$this->assertInstanceOf( Folder::class, Folder::create() );
		update_option( 'bot_insight_buffer_dir', $dir );
	}
}
