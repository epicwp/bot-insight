<?php
/**
 * Recorder inside a real WordPress request cycle.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Tests\Integration;

use BotInsight\Buffer\FileBuffer;
use BotInsight\Capture\Recorder;
use BotInsight\Plugin;
use BotInsight\Storage\Schema;

/**
 * Front-end and admin requests against the recorder's shutdown callback.
 */
final class RecorderTest extends \WP_UnitTestCase {

	/**
	 * Buffer directory of the current test.
	 *
	 * @var string
	 */
	private $dir = '';

	/**
	 * $_SERVER before the test.
	 *
	 * @var array<string, mixed>
	 */
	private $server = array();

	public function set_up(): void {
		parent::set_up();
		$this->server = $_SERVER;

		// What activation leaves behind, minus the tables (not needed here).
		Schema::seedOptions();
		delete_option( 'bot_insight_buffer_dir' );
		$this->dir = Plugin::ensureBufferDir();
		$this->assertNotSame( '', $this->dir );
		Recorder::register();

		$_SERVER['REQUEST_METHOD']  = 'GET';
		$_SERVER['REMOTE_ADDR']     = '203.0.113.7';
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; ExampleBot/1.0)';
		unset( $_COOKIE['bi_js'] );
	}

	public function tear_down(): void {
		if ( '' !== $this->dir ) {
			( new FileBuffer( $this->dir ) )->removeDir();
		}
		$_SERVER = $this->server;
		unset( $_COOKIE['bi_js'] );
		$GLOBALS['wp_scripts'] = null;
		parent::tear_down();
	}

	public function test_frontend_request_appends_one_record(): void {
		global $wpdb;
		$this->go_to( home_url( '/' ) );

		Recorder::onShutdown();

		$records = $this->records();
		$this->assertCount( 1, $records );
		$this->assertSame( 'home', $records[0]['r'] );
		$this->assertSame( 'GET', $records[0]['m'] );
		$this->assertSame( '203.0.113.7', $records[0]['ip'] );

		$hits = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->options WHERE option_value LIKE %s", '%' . $wpdb->esc_like( '203.0.113.7' ) . '%' ) );
		$this->assertSame( '0', $hits );
	}

	public function test_frontend_request_adds_no_db_queries(): void {
		global $wpdb;
		$this->go_to( home_url( '/' ) );
		Plugin::settings();

		$before = $wpdb->num_queries;
		Recorder::onShutdown();
		$after = $wpdb->num_queries;

		$this->assertCount( 1, $this->records() );
		$this->assertSame( $before, $after );
	}

	public function test_per_request_options_are_autoloaded(): void {
		$all = wp_load_alloptions();
		foreach ( array( 'bot_insight_settings', 'bot_insight_langs', 'bot_insight_buffer_dir' ) as $name ) {
			$this->assertArrayHasKey( $name, $all, $name );
		}
	}

	public function test_admin_excluded_by_default(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->go_to( home_url( '/' ) );

		Recorder::onShutdown();

		$this->assertSame( array(), $this->records() );
	}

	public function test_js_cookie_script_added_on_frontend_regardless_of_cookie(): void {
		foreach ( array( null, '1' ) as $cookie ) {
			$GLOBALS['wp_scripts'] = null;
			if ( null === $cookie ) {
				unset( $_COOKIE['bi_js'] );
			} else {
				$_COOKIE['bi_js'] = $cookie;
			}
			$this->go_to( home_url( '/' ) );

			do_action( 'wp_enqueue_scripts' );

			$this->assertTrue( wp_script_is( 'bot-insight-js', 'enqueued' ) );
			$this->assertContains( Recorder::JS, (array) wp_scripts()->get_data( 'bot-insight-js', 'after' ) );
		}
	}

	public function test_js_cookie_script_not_added_in_admin(): void {
		$GLOBALS['wp_scripts'] = null;
		set_current_screen( 'dashboard' );

		do_action( 'wp_enqueue_scripts' );

		$this->assertFalse( wp_script_is( 'bot-insight-js', 'enqueued' ) );
	}

	/**
	 * All records in the test's buffer dir.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function records(): array {
		$buffer = new FileBuffer( $this->dir );
		$files  = glob( $this->dir . '/buffer-*.php' );
		$out    = array();
		foreach ( is_array( $files ) ? $files : array() as $file ) {
			foreach ( $buffer->read( $file ) as $record ) {
				$out[] = $record;
			}
		}
		return $out;
	}
}
