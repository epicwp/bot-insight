<?php
/**
 * The dashboard seed fixture fills every section of the report.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Tests\Integration;

use BotInsight\Plugin;
use BotInsight\Storage\Schema;

final class SeedFixtureTest extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$this->cleanSlate();
		Schema::install();
		update_option( 'timezone_string', 'UTC' );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		$this->cleanSlate();
		update_option( 'timezone_string', '' );
		$GLOBALS['wpdb']->query( 'COMMIT' );
		parent::tear_down();
	}

	public function test_seed_fills_every_dashboard_section(): void {
		$args = array( 'reset' );
		require dirname( __DIR__ ) . '/fixtures/seed.php';

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$data = rest_get_server()->dispatch( new \WP_REST_Request( 'GET', '/bot-insight/v1/report' ) )->get_data();

		$this->assertGreaterThan( 0, $data['totals']['requests'] );
		$this->assertGreaterThan( 0.5, $data['totals']['bot_share'] );
		$this->assertGreaterThan( 0, $data['totals']['verified_search_requests'] );
		$this->assertGreaterThan( 0, $data['totals']['unique_client_days'] );
		$this->assertGreaterThanOrEqual( 7, count( $data['per_day'] ) );
		$this->assertGreaterThan( 15, count( $data['visitors'] ) );
		$this->assertContains( true, array_column( $data['visitors'], 'impostor' ) );
		$this->assertContains( true, array_column( $data['visitors'], 'findability' ) );
		$this->assertContains( '(other combinations)', array_column( $data['params'], 'params' ) );
		$this->assertNotEmpty( $data['countries'] );
		$this->assertNotEmpty( $data['signals'] );
		$this->assertGreaterThan( 0, $data['signal_breakdown']['no_js'] );
		$this->assertGreaterThan( 0, $data['overhead']['p95_us'] );
	}

	private function cleanSlate(): void {
		global $wpdb;
		foreach ( Schema::tables() as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS $table" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$names = $wpdb->get_col( "SELECT option_name FROM $wpdb->options WHERE option_name LIKE '%bot\\_insight\\_%'" );
		foreach ( $names as $name ) {
			delete_option( $name );
		}
		wp_cache_flush();
		$prop = new \ReflectionProperty( Plugin::class, 'settings' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
	}
}
