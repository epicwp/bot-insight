<?php
/**
 * Dashboard page under Tools.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Tests\Integration;

use BotInsight\Admin\DashboardPage;

final class DashboardPageTest extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->resetAdminState();
	}

	public function tear_down(): void {
		$this->resetAdminState();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_admin_gets_a_tools_menu_item(): void {
		$this->admin();
		DashboardPage::menu();
		$this->assertSame( 'tools_page_bot-insight', DashboardPage::hookSuffix() );
		$this->assertContains( 'bot-insight', $this->toolsSlugs() );
	}

	public function test_subscriber_gets_no_menu_item(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		DashboardPage::menu();
		$this->assertSame( '', DashboardPage::hookSuffix() );
		$this->assertNotContains( 'bot-insight', $this->toolsSlugs() );
	}

	public function test_render_prints_the_app_root(): void {
		$this->admin();
		ob_start();
		DashboardPage::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="bot-insight-app"', $html );
		$this->assertStringContainsString( '<div class="wrap bot-insight">', $html );
		$this->assertStringContainsString( 'nav-tab-active', $html );
	}

	public function test_render_escapes_translated_text(): void {
		$this->admin();
		$filter = static function ( $translation, $text, $domain ) {
			return 'bot-insight' === $domain ? '<script>alert(1)</script>' . $text : $translation;
		};
		add_filter( 'gettext', $filter, 10, 3 );
		ob_start();
		DashboardPage::render();
		$html = (string) ob_get_clean();
		remove_filter( 'gettext', $filter, 10 );

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	public function test_render_prints_nothing_for_a_subscriber(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		ob_start();
		DashboardPage::render();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_assets_enqueued_only_on_the_page_hook(): void {
		$this->admin();
		DashboardPage::menu();

		DashboardPage::enqueue( 'index.php' );
		$this->assertFalse( wp_script_is( DashboardPage::HANDLE, 'enqueued' ) );
		$this->assertFalse( wp_style_is( DashboardPage::HANDLE, 'enqueued' ) );

		DashboardPage::enqueue( 'tools_page_bot-insight' );
		$this->assertTrue( wp_script_is( DashboardPage::HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( DashboardPage::HANDLE, 'enqueued' ) );
	}

	public function test_script_depends_on_api_fetch_and_i18n_and_gets_config(): void {
		$this->admin();
		DashboardPage::menu();
		DashboardPage::enqueue( 'tools_page_bot-insight' );

		$script = wp_scripts()->registered[ DashboardPage::HANDLE ];
		$this->assertContains( 'wp-api-fetch', $script->deps );
		$this->assertContains( 'wp-i18n', $script->deps );
		$this->assertSame( 'bot-insight', $script->textdomain );
		$this->assertStringEndsWith( 'assets/dashboard.js', $script->src );

		$before = implode( "\n", (array) wp_scripts()->get_data( DashboardPage::HANDLE, 'before' ) );
		$this->assertStringContainsString( 'botInsightDashboard', $before );
		$this->assertStringContainsString( '"restPath":"\/bot-insight\/v1"', $before );
		$this->assertStringContainsString( '"version"', $before );
	}

	public function test_plugins_screen_row_gets_a_settings_link(): void {
		$links = DashboardPage::actionLinks( array( 'deactivate' => '<a href="#">Deactivate</a>' ) );
		$this->assertCount( 2, $links );
		$first = (string) reset( $links );
		$this->assertStringContainsString( 'tools.php?page=bot-insight', $first );
		$this->assertStringContainsString( '>Settings</a>', $first );
	}

	public function test_hooks_are_registered_at_boot(): void {
		$this->assertNotFalse( has_action( 'admin_menu', array( DashboardPage::class, 'menu' ) ) );
		$this->assertNotFalse( has_action( 'admin_enqueue_scripts', array( DashboardPage::class, 'enqueue' ) ) );
		$this->assertNotFalse( has_filter( 'plugin_action_links_' . plugin_basename( BOT_INSIGHT_FILE ), array( DashboardPage::class, 'actionLinks' ) ) );
	}

	private function admin(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Slugs of the Tools submenu.
	 *
	 * @return string[]
	 */
	private function toolsSlugs(): array {
		global $submenu;
		return array_map(
			static function ( $item ) {
				return (string) $item[2];
			},
			$submenu['tools.php'] ?? array()
		);
	}

	private function resetAdminState(): void {
		global $submenu, $_registered_pages, $_parent_pages, $admin_page_hooks;
		unset( $submenu['tools.php'] );
		// wp-admin/menu.php registers the Tools top-level page; the test runtime does not.
		$admin_page_hooks['tools.php'] = 'tools'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$_registered_pages = array();
		$_parent_pages     = array();
		DashboardPage::reset();
		wp_dequeue_script( DashboardPage::HANDLE );
		wp_deregister_script( DashboardPage::HANDLE );
		wp_dequeue_style( DashboardPage::HANDLE );
		wp_deregister_style( DashboardPage::HANDLE );
	}
}
