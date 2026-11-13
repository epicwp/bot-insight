<?php
/**
 * Privacy policy text and WooCommerce compatibility hooks.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Tests\Integration;

use BotInsight\Plugin;

final class PluginTest extends \WP_UnitTestCase {

	public function test_boot_registers_privacy_and_woocommerce_hooks(): void {
		$this->assertNotFalse( has_action( 'admin_init', array( Plugin::class, 'addPrivacyPolicyContent' ) ) );
		$this->assertNotFalse( has_action( 'before_woocommerce_init', array( Plugin::class, 'declareWooCompatibility' ) ) );
	}

	public function test_updater_is_wanted_only_in_admin_cron_and_cli(): void {
		set_current_screen( 'front' );
		$this->assertFalse( Plugin::wantsUpdater(), 'front end' );

		set_current_screen( 'dashboard' );
		$this->assertTrue( Plugin::wantsUpdater(), 'admin' );
		set_current_screen( 'front' );

		add_filter( 'wp_doing_cron', '__return_true' );
		$this->assertTrue( Plugin::wantsUpdater(), 'cron' );
		remove_filter( 'wp_doing_cron', '__return_true' );
	}

	public function test_privacy_policy_content_describes_storage_and_cookie(): void {
		require_once ABSPATH . 'wp-admin/includes/class-wp-privacy-policy-content.php';
		$store = new \ReflectionProperty( \WP_Privacy_Policy_Content::class, 'policy_content' );
		$store->setAccessible( true );
		$store->setValue( null, array() );
		Plugin::resetSettings();
		set_current_screen( 'dashboard' );

		// wp_add_privacy_policy_content() insists on running inside admin_init.
		$GLOBALS['wp_current_filter'][] = 'admin_init';
		Plugin::addPrivacyPolicyContent();
		array_pop( $GLOBALS['wp_current_filter'] );
		set_current_screen( 'front' );

		$entries = array_values(
			array_filter(
				(array) $store->getValue(),
				static function ( $entry ): bool {
					return 'Bot Insight' === $entry['plugin_name'];
				}
			)
		);
		$this->assertCount( 1, $entries );
		$text = $entries[0]['policy_text'];
		$this->assertStringContainsString( 'bi_js', $text );
		$this->assertStringContainsString( 'changes every day', $text );
		$this->assertStringContainsString( 'never longer than 48 hours', $text );
		$this->assertStringContainsString( 'deleted after 24 hours', $text );
		$this->assertStringContainsString( 'never their values', $text );
		$this->assertStringContainsString( 'kept for 400 days', $text );
	}
}
