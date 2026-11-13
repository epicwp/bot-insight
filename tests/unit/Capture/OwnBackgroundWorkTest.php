<?php
/**
 * Tests for the site's own background requests, which are not recorded.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Tests\Unit\Capture;

use BotInsight\Capture\Recorder;
use PHPUnit\Framework\TestCase;

final class OwnBackgroundWorkTest extends TestCase {

	private const HOME = 'shop.example.com';

	public function test_cron_is_own_work(): void {
		$this->assertTrue( Recorder::isOwnBackgroundWork( true, '', 'Mozilla/5.0', self::HOME ) );
	}

	public function test_action_scheduler_async_runner_is_own_work(): void {
		$this->assertTrue( Recorder::isOwnBackgroundWork( false, 'as_async_request_queue_runner', 'Mozilla/5.0', self::HOME ) );
		$this->assertFalse( Recorder::isOwnBackgroundWork( false, 'woocommerce_get_refreshed_fragments', 'Mozilla/5.0', self::HOME ) );
	}

	public function test_loopback_from_this_site_is_own_work(): void {
		$this->assertTrue( Recorder::isOwnBackgroundWork( false, '', 'WordPress/6.8.1; https://shop.example.com', self::HOME ) );
		$this->assertTrue( Recorder::isOwnBackgroundWork( false, '', 'WordPress/7.1; http://SHOP.example.com/', self::HOME ) );
		$this->assertTrue( Recorder::isOwnBackgroundWork( false, '', 'WordPress/7.1; https://shop.example.com:8443/blog', self::HOME ) );
	}

	public function test_other_wordpress_sites_are_not_own_work(): void {
		$this->assertFalse( Recorder::isOwnBackgroundWork( false, '', 'WordPress/6.8.1; https://other.example.org', self::HOME ) );
		$this->assertFalse( Recorder::isOwnBackgroundWork( false, '', 'WordPress/6.8.1; https://shop.example.com.evil.example', self::HOME ) );
		$this->assertFalse( Recorder::isOwnBackgroundWork( false, '', 'WordPress/6.8.1', self::HOME ) );
	}

	public function test_this_sites_url_in_another_ua_is_not_own_work(): void {
		$this->assertFalse( Recorder::isOwnBackgroundWork( false, '', 'curl/8.0; https://shop.example.com', self::HOME ) );
		$this->assertFalse( Recorder::isOwnBackgroundWork( false, '', 'WordPress/6.8.1; https://shop.example.com', '' ) );
	}
}
