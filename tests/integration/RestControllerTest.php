<?php
/**
 * REST endpoints.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Tests\Integration;

use BotInsight\Fold\Folder;
use BotInsight\Plugin;
use BotInsight\Storage\Dictionary;
use BotInsight\Storage\Schema;

final class RestControllerTest extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$this->cleanSlate();
		Schema::install();
		update_option( 'timezone_string', 'UTC' );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		$this->cleanSlate();
		update_option( 'timezone_string', '' );
		$GLOBALS['wpdb']->query( 'COMMIT' );
		parent::tear_down();
	}

	public function test_anonymous_gets_401(): void {
		foreach ( array( '/bot-insight/v1/report', '/bot-insight/v1/export' ) as $route ) {
			$this->assertSame( 401, $this->get( $route )->get_status() );
		}
	}

	public function test_subscriber_gets_403(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		foreach ( array( '/bot-insight/v1/report', '/bot-insight/v1/export' ) as $route ) {
			$this->assertSame( 403, $this->get( $route )->get_status() );
		}
	}

	public function test_admin_gets_the_full_report(): void {
		$this->admin();
		$response = $this->get( '/bot-insight/v1/report' );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();

		$this->assertSame(
			array( 'period', 'totals', 'per_day', 'visitors', 'params', 'countries', 'signals', 'signal_breakdown', 'overhead', 'health', 'notes' ),
			array_keys( $data )
		);
		$this->assertSame( '7d', $data['period']['key'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:00:00Z$/', $data['period']['start'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:00:00Z$/', $data['period']['end'] );
		$this->assertSame( 3600 + 168 * 3600, strtotime( $data['period']['end'] ) - strtotime( $data['period']['start'] ) );
		$this->assertSame( array( 'buffer_writable', 'last_fold', 'last_error', 'page_cache' ), array_keys( $data['health'] ) );
		$this->assertFalse( $data['health']['buffer_writable'] );
		$this->assertSame( 0, $data['health']['last_fold'] );
		$this->assertSame( '', $data['health']['last_error'] );
		$this->assertFalse( $data['health']['page_cache'] );
		$this->assertSame( array( 'page_cache_unseen' => true ), $data['notes'] );
		$this->assertSame( array( 'p95_us' => 0, 'days' => 0 ), $data['overhead'] );
		$this->assertSame( array(), $data['signals'] );
		$this->assertSame( 0, $data['totals']['requests'] );
		$this->assertContains( 'no-store', array_map( 'trim', explode( ',', (string) ( $response->get_headers()['Cache-Control'] ?? '' ) ) ) );
	}

	public function test_report_uses_the_period_argument(): void {
		$this->admin();
		$data = $this->get( '/bot-insight/v1/report', array( 'period' => '24h' ) )->get_data();
		$this->assertSame( '24h', $data['period']['key'] );
		$this->assertSame( 25 * 3600, strtotime( $data['period']['end'] ) - strtotime( $data['period']['start'] ) );
	}

	public function test_invalid_period_gets_400(): void {
		$this->admin();
		$this->assertSame( 400, $this->get( '/bot-insight/v1/report', array( 'period' => '1y' ) )->get_status() );
		$this->assertSame( 400, $this->get( '/bot-insight/v1/export', array( 'period' => 'x' ) )->get_status() );
	}

	public function test_health_reflects_status_snapshot_and_buffer(): void {
		global $wpdb;
		$this->admin();
		$dir = get_temp_dir() . 'bi-rest-' . wp_generate_password( 8, false );
		mkdir( $dir );
		update_option( 'bot_insight_buffer_dir', $dir );
		update_option( Folder::STATUS_OPTION, array( 'last_run' => 1234, 'last_error' => 'boom' ) );
		$wpdb->replace(
			Schema::tables()['snapshot'],
			array(
				'day'  => gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ),
				'data' => wp_json_encode( array( 'page_cache' => array( 'dropin' => false, 'plugin' => 'wp-rocket' ) ) ),
			)
		);

		$health = $this->get( '/bot-insight/v1/report' )->get_data()['health'];
		rmdir( $dir );

		$this->assertTrue( $health['buffer_writable'] );
		$this->assertTrue( $health['page_cache'] );
		$this->assertIsInt( $health['last_fold'] );
		// The report folds first; a fold with errors keeps the previous last_run.
		$this->assertIsString( $health['last_error'] );
	}

	public function test_report_still_works_when_the_fold_fails(): void {
		$this->admin();
		// An unusable buffer dir makes the fold fail or skip; the report must not.
		update_option( 'bot_insight_buffer_dir', '/nonexistent/path' );
		$this->assertSame( 200, $this->get( '/bot-insight/v1/report' )->get_status() );
	}

	public function test_export_has_attachment_headers_and_body(): void {
		$this->admin();
		$this->seedRows();
		update_option( 'timezone_string', 'Pacific/Auckland' );
		$response = $this->get( '/bot-insight/v1/export', array( 'period' => '30d' ) );
		$this->assertSame( 200, $response->get_status() );
		$headers = $response->get_headers();
		$this->assertSame(
			'attachment; filename="bot-insight-30d-' . wp_date( 'Ymd' ) . '.json"',
			$headers['Content-Disposition']
		);
		$this->assertSame( 'no-store', $headers['Cache-Control'] );
		$data = $response->get_data();
		$this->assertSame( array( 'plugin_version', 'generated_at', 'period', 'rows' ), array_keys( $data ) );
		$this->assertSame( Plugin::VERSION, $data['plugin_version'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $data['generated_at'] );
		$this->assertSame( '30d', $data['period'] );
		$this->assertNotEmpty( $data['rows'] );
		$this->assertSame( array( 'hour', 'class', 'bot', 'requests', 'php_ms', 'verified' ), array_keys( $data['rows'][0] ) );
	}

	public function test_output_holds_no_ip_data(): void {
		$this->admin();
		$this->seedRows();
		foreach ( array( '/bot-insight/v1/report', '/bot-insight/v1/export' ) as $route ) {
			$json = wp_json_encode( $this->get( $route, array( 'period' => '30d' ) )->get_data() );
			$this->assertStringNotContainsString( 'ip_hash', $json );
			$this->assertStringNotContainsString( 'deadbeefdeadbeef', $json );
			$this->assertSame( 0, preg_match( '/\b(?:\d{1,3}\.){3}\d{1,3}\b/', $json ), $route . ' holds a full IPv4 address' );
			$this->assertStringNotContainsString( '2001:db8', $json );
		}
	}

	// Helpers.

	private function admin(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * @param string               $route  Route.
	 * @param array<string, mixed> $params Query params.
	 */
	private function get( string $route, array $params = array() ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'GET', $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	private function seedRows(): void {
		global $wpdb;
		$hour = time() - time() % HOUR_IN_SECONDS;
		$dict = new Dictionary( $wpdb );
		$wpdb->insert(
			Schema::tables()['hourly'],
			array(
				'hour'         => $hour,
				'dims'         => md5( 'rest', true ),
				'client_class' => 'ai_training',
				'bot_id'       => $dict->id( 'bot', 'gptbot' ),
				'requests'     => 10,
				'php_ms_sum'   => 5000,
			)
		);
		$wpdb->insert(
			Schema::tables()['sample'],
			array(
				'ts'           => $hour + 5,
				'hour'         => $hour,
				'client_class' => 'suspected',
				'ip_hash'      => 'deadbeefdeadbeef',
				'ip_prefix'    => '203.0.113.0/24',
				'country'      => 'NL',
			)
		);
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
