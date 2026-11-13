<?php
/**
 * Settings tab, opt-ins, delete-all and manual notes.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Tests\Integration;

use BotInsight\Admin\DashboardPage;
use BotInsight\Admin\SettingsPage;
use BotInsight\Fold\Folder;
use BotInsight\Plugin;
use BotInsight\Site\Events;
use BotInsight\Storage\Schema;

final class SettingsPageTest extends \WP_UnitTestCase {

	/**
	 * @var string
	 */
	private $dir = '';

	public function set_up(): void {
		parent::set_up();
		$_GET  = array();
		$_POST = array();
		Schema::install();
		$this->dir = Plugin::ensureBufferDir();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Plugin::resetSettings();
	}

	public function tear_down(): void {
		$_GET  = array();
		$_POST = array();
		unset( $_REQUEST['_wpnonce'], $_REQUEST['_wp_http_referer'] );
		remove_all_filters( 'wp_redirect' );
		Plugin::resetSettings();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_sanitize_clamps_and_casts(): void {
		$out = SettingsPage::sanitize(
			array(
				'sample_rate'    => '5000',
				'exclude_admins' => '1',
				'retention_days' => '5',
				'evil'           => 'x',
			)
		);
		$this->assertSame( 1000, $out['sample_rate'] );
		$this->assertSame( 30, $out['retention_days'] );
		$this->assertTrue( $out['exclude_admins'] );
		$this->assertFalse( $out['range_updates'] );
		$this->assertFalse( $out['geo_updates'] );

		$out = SettingsPage::sanitize(
			array(
				'sample_rate'    => '0',
				'retention_days' => '9999',
				'range_updates'  => 'on',
			)
		);
		$this->assertSame( 1, $out['sample_rate'] );
		$this->assertSame( 730, $out['retention_days'] );
		$this->assertTrue( $out['range_updates'] );
		$this->assertFalse( $out['exclude_admins'] );

		$this->assertSame( 'auto', SettingsPage::sanitize( array( 'sample_rate' => 'banana' ) )['sample_rate'] );
		$this->assertSame( 'auto', SettingsPage::sanitize( array( 'sample_rate' => ' AUTO ' ) )['sample_rate'] );
		$this->assertSame( 12, SettingsPage::sanitize( array( 'sample_rate' => '12' ) )['sample_rate'] );
		$this->assertSame( 400, SettingsPage::sanitize( array() )['retention_days'] );
		$this->assertSame( Plugin::DEFAULT_SETTINGS['sample_rate'], SettingsPage::sanitize( 'junk' )['sample_rate'] );
	}

	public function test_unknown_keys_are_dropped(): void {
		$out = SettingsPage::sanitize( array( 'evil' => 'x', 'retention_days' => 60 ) );
		$this->assertSame( array_keys( Plugin::DEFAULT_SETTINGS ), array_keys( $out ) );
	}

	public function test_saving_through_the_settings_api_keeps_autoload_and_resets_the_cache(): void {
		SettingsPage::register();
		Plugin::settings();
		update_option( 'bot_insight_settings', array( 'range_updates' => '1', 'retention_days' => '90', 'junk' => 1 ) );

		$this->assertSame( 90, get_option( 'bot_insight_settings' )['retention_days'] );
		$this->assertArrayNotHasKey( 'junk', get_option( 'bot_insight_settings' ) );
		$this->assertTrue( Plugin::settings()['range_updates'] );

		global $wpdb;
		$values = function_exists( 'wp_autoload_values_to_autoload' ) ? wp_autoload_values_to_autoload() : array( 'yes' );
		$this->assertContains( $wpdb->get_var( "SELECT autoload FROM $wpdb->options WHERE option_name = 'bot_insight_settings'" ), $values );
	}

	public function test_settings_tab_renders_opt_ins_and_attribution(): void {
		$_GET['tab'] = 'settings';
		ob_start();
		DashboardPage::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="bot_insight_settings[range_updates]"', $html );
		$this->assertStringContainsString( 'name="bot_insight_settings[geo_updates]"', $html );
		$this->assertStringContainsString( 'download.db-ip.com', $html );
		$this->assertStringContainsString( 'CC BY 4.0', $html );
		$this->assertStringContainsString( 'href="https://db-ip.com"', $html );
		$this->assertStringContainsString( 'IP geolocation by DB-IP', $html );
		$this->assertStringContainsString( 'value="bot_insight_delete_all"', $html );
		$this->assertStringContainsString( 'value="bot_insight_add_note"', $html );
		$this->assertStringNotContainsString( 'id="bot-insight-app"', $html );
		$this->assertMatchesRegularExpression( '/nav-tab nav-tab-active"[^>]*>Settings</', $html );
	}

	public function test_unknown_tab_falls_back_to_dashboard(): void {
		$_GET['tab'] = 'Nope!';
		$this->assertSame( 'dashboard', DashboardPage::currentTab() );
		ob_start();
		DashboardPage::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'id="bot-insight-app"', $html );
	}

	public function test_dashboard_assets_not_enqueued_on_settings_tab(): void {
		global $submenu, $admin_page_hooks;
		unset( $submenu['tools.php'] );
		$admin_page_hooks['tools.php'] = 'tools'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		DashboardPage::menu();
		$_GET['tab'] = 'settings';
		DashboardPage::enqueue( DashboardPage::hookSuffix() );
		$this->assertFalse( wp_script_is( DashboardPage::HANDLE, 'enqueued' ) );
		DashboardPage::reset();
	}

	public function test_delete_all_without_nonce_dies_with_403(): void {
		$this->seedData();
		$_POST['confirm'] = '1';
		try {
			SettingsPage::handleDeleteAll();
			$this->fail( 'Expected wp_die' );
		} catch ( \WPDieException $e ) {
			$this->assertSame( 403, $e->getCode() );
		}
		$this->assertSame( 1, $this->rows( 'events' ) );
	}

	public function test_delete_all_as_subscriber_dies_with_403(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->seedData();
		$this->postWithNonce( 'bot_insight_delete_all', array( 'confirm' => '1' ) );
		try {
			SettingsPage::handleDeleteAll();
			$this->fail( 'Expected wp_die' );
		} catch ( \WPDieException $e ) {
			$this->assertSame( 403, $e->getCode() );
		}
		$this->assertSame( 1, $this->rows( 'events' ) );
	}

	public function test_delete_all_requires_the_confirm_checkbox(): void {
		$this->seedData();
		$this->postWithNonce( 'bot_insight_delete_all', array() );
		$url = $this->captureRedirect( array( SettingsPage::class, 'handleDeleteAll' ) );
		$this->assertStringContainsString( 'bi_notice=confirm', $url );
		$this->assertSame( 1, $this->rows( 'events' ) );
	}

	public function test_delete_all_empties_tables_and_files_but_keeps_settings_secret_and_protection(): void {
		$this->seedData();
		update_option( 'bot_insight_settings', array_merge( Plugin::DEFAULT_SETTINGS, array( 'retention_days' => 90 ) ) );
		update_option( 'bot_insight_secret', 'secret-for-test' );
		$secret = 'secret-for-test';

		$this->postWithNonce( 'bot_insight_delete_all', array( 'confirm' => '1' ) );
		$url = $this->captureRedirect( array( SettingsPage::class, 'handleDeleteAll' ) );

		$this->assertStringContainsString( 'bi_notice=deleted', $url );
		foreach ( array_keys( Schema::tables() ) as $short ) {
			$this->assertSame( 0, $this->rows( $short ), $short );
		}
		foreach ( array( 'buffer-202601010000-ab-1.php', 'folding-x.php', 'quarantine-y.php', 'uniq-2026011010.php', 'uniq-20260110.php', 'geo/db.mmdb', 'ranges/google.php' ) as $f ) {
			$this->assertFileDoesNotExist( $this->dir . '/' . $f );
		}
		$this->assertDirectoryDoesNotExist( $this->dir . '/geo' );
		$this->assertDirectoryDoesNotExist( $this->dir . '/ranges' );
		$this->assertDirectoryExists( $this->dir );
		$this->assertFileExists( $this->dir . '/index.php' );
		$this->assertFileExists( $this->dir . '/.htaccess' );

		foreach ( array( 'folded_files', 'fold_failures', 'fold_status', 'daily_status', 'load', 'last_closed_day', 'sample_rate', 'salt' ) as $name ) {
			$this->assertFalse( get_option( 'bot_insight_' . $name ), $name );
		}
		$this->assertFalse( get_option( 'bot_insight_dns_x' ) );
		$this->assertFalse( get_transient( 'bot_insight_dns_abc' ) );
		$this->assertSame( 90, get_option( 'bot_insight_settings' )['retention_days'] );
		$this->assertSame( $secret, get_option( 'bot_insight_secret' ) );
	}

	public function test_delete_all_refuses_while_a_fold_runs(): void {
		global $wpdb;
		$this->seedData();
		$other = new \mysqli( ...$this->hostAndPort() );
		$other->query( "SELECT GET_LOCK('" . $other->real_escape_string( Folder::lockName( $wpdb ) ) . "', 0)" );

		$this->postWithNonce( 'bot_insight_delete_all', array( 'confirm' => '1' ) );
		$url = $this->captureRedirect( array( SettingsPage::class, 'handleDeleteAll' ) );
		$other->close();

		$this->assertStringContainsString( 'bi_notice=busy', $url );
		$this->assertSame( 1, $this->rows( 'events' ) );
		$this->assertFileExists( $this->dir . '/buffer-202601010000-ab-1.php' );
	}

	public function test_add_note_stores_an_event_and_trims(): void {
		$this->postWithNonce( 'bot_insight_add_note', array( 'note' => str_repeat( 'é', 300 ) ) );
		$url = $this->captureRedirect( array( SettingsPage::class, 'handleAddNote' ) );
		$this->assertStringContainsString( 'bi_notice=noted', $url );

		global $wpdb;
		$subject = (string) $wpdb->get_var( 'SELECT subject FROM ' . Schema::tables()['events'] . " WHERE type = 'note'" );
		$this->assertSame( 191, mb_strlen( $subject, 'UTF-8' ) );
	}

	public function test_add_note_needs_nonce_and_capability(): void {
		$_POST['note'] = 'hello';
		try {
			SettingsPage::handleAddNote();
			$this->fail( 'Expected wp_die' );
		} catch ( \WPDieException $e ) {
			$this->assertSame( 403, $e->getCode() );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->postWithNonce( 'bot_insight_add_note', array( 'note' => 'hello' ) );
		try {
			SettingsPage::handleAddNote();
			$this->fail( 'Expected wp_die' );
		} catch ( \WPDieException $e ) {
			$this->assertSame( 403, $e->getCode() );
		}
		$this->assertSame( 0, $this->rows( 'events' ) );
	}

	public function test_empty_note_is_not_stored(): void {
		$this->postWithNonce( 'bot_insight_add_note', array( 'note' => '   ' ) );
		$this->captureRedirect( array( SettingsPage::class, 'handleAddNote' ) );
		$this->assertSame( 0, $this->rows( 'events' ) );
	}

	public function test_events_list_is_escaped_limited_and_newest_first(): void {
		Events::add( 'note', '<script>alert(1)</script>', '<b>d</b>', 1700000000 );
		for ( $i = 0; $i < 25; $i++ ) {
			Events::add( 'note', 'n' . $i, '', 1700001000 + $i * 100 );
		}
		$_GET['tab'] = 'settings';
		ob_start();
		DashboardPage::render();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( '<script>alert', $html );
		$this->assertSame( 20, preg_match_all( '/<tr class="bot-insight-event"/', $html ) );
		$this->assertStringContainsString( 'n24', $html );
		$this->assertStringNotContainsString( '>n4<', $html );
	}

	public function test_escaped_event_is_visible_when_listed(): void {
		Events::add( 'note', '<script>alert(1)</script>', '<b>d</b>', 1700000000 );
		$_GET['tab'] = 'settings';
		ob_start();
		DashboardPage::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
		$this->assertStringContainsString( '&lt;b&gt;d&lt;/b&gt;', $html );
	}

	public function test_hooks_are_registered_at_boot(): void {
		$this->assertNotFalse( has_action( 'admin_post_bot_insight_delete_all', array( SettingsPage::class, 'handleDeleteAll' ) ) );
		$this->assertNotFalse( has_action( 'admin_post_bot_insight_add_note', array( SettingsPage::class, 'handleAddNote' ) ) );
		$this->assertNotFalse( has_action( 'admin_init', array( SettingsPage::class, 'register' ) ) );
	}

	/**
	 * @param string               $action Nonce action.
	 * @param array<string,string> $fields Extra POST fields.
	 */
	private function postWithNonce( string $action, array $fields ): void {
		$nonce                    = wp_create_nonce( $action );
		$_POST                    = array_merge( $fields, array( '_wpnonce' => $nonce ) );
		$_REQUEST                 = $_POST; // phpcs:ignore WordPress.Security.NonceVerification
		$_REQUEST['_wpnonce']     = $nonce;
	}

	/**
	 * Run a handler that ends in a redirect and return the target.
	 *
	 * @param callable $handler Handler.
	 */
	private function captureRedirect( callable $handler ): string {
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new \RuntimeException( (string) $location );
			}
		);
		try {
			$handler();
		} catch ( \RuntimeException $e ) {
			return $e->getMessage();
		}
		$this->fail( 'No redirect' );
	}

	/**
	 * Connection arguments for a second MySQL session (to hold the fold lock).
	 *
	 * @return array{0:string,1:string,2:string,3:string,4:int}
	 */
	private function hostAndPort(): array {
		$parts = explode( ':', DB_HOST );
		return array( $parts[0], DB_USER, DB_PASSWORD, DB_NAME, (int) ( $parts[1] ?? 3306 ) );
	}

	private function rows( string $short ): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::tables()[ $short ] );
	}

	private function seedData(): void {
		global $wpdb;
		$t = Schema::tables();
		Events::add( 'note', 'seed' );
		$wpdb->query( "INSERT INTO {$t['dict']} (type, value) VALUES ('ua', 'seed')" );
		foreach ( array( 'buffer-202601010000-ab-1.php', 'folding-x.php', 'quarantine-y.php', 'uniq-2026011010.php', 'uniq-20260110.php' ) as $f ) {
			file_put_contents( $this->dir . '/' . $f, 'x' );
		}
		foreach ( array( 'geo', 'ranges' ) as $sub ) {
			wp_mkdir_p( $this->dir . '/' . $sub );
		}
		file_put_contents( $this->dir . '/geo/db.mmdb', 'x' );
		file_put_contents( $this->dir . '/ranges/google.php', 'x' );
		foreach ( array( 'folded_files', 'fold_failures', 'fold_status', 'daily_status', 'load', 'last_closed_day', 'sample_rate', 'salt', 'dns_x' ) as $name ) {
			update_option( 'bot_insight_' . $name, 'x' );
		}
		set_transient( 'bot_insight_dns_abc', 'x', 3600 );
	}
}
