<?php
/**
 * Schema, activation and uninstall against a real database.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Tests\Integration;

use BotInsight\Buffer\FileBuffer;
use BotInsight\Plugin;
use BotInsight\Storage\Schema;

/**
 * DDL statements commit implicitly, so these tests use real (not temporary) tables and clean up
 * after themselves.
 */
final class SchemaTest extends \WP_UnitTestCase {

	/**
	 * Short table names the schema must create.
	 */
	private const SHORT_NAMES = array( 'hourly', 'daily', 'dict', 'sample', 'events', 'snapshot' );

	public function set_up(): void {
		parent::set_up();
		// Real tables: SHOW TABLES and dbDelta do not see temporary ones.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$this->cleanSlate();
	}

	public function tear_down(): void {
		parent::tear_down();
		$this->cleanSlate();
		$GLOBALS['wpdb']->query( 'COMMIT' );
	}

	public function test_tables_maps_short_names_to_prefixed_names(): void {
		global $wpdb;
		$tables = Schema::tables();
		$this->assertSame( self::SHORT_NAMES, array_keys( $tables ) );
		$this->assertSame( $wpdb->prefix . 'botinsight_hourly', $tables['hourly'] );
	}

	public function test_install_creates_six_tables(): void {
		foreach ( Schema::tables() as $table ) {
			$this->assertFalse( $this->tableExists( $table ), $table );
		}

		Schema::install();

		foreach ( Schema::tables() as $table ) {
			$this->assertTrue( $this->tableExists( $table ), $table );
		}
		$this->assertSame( Schema::DB_VERSION, (int) get_option( 'bot_insight_db_version' ) );
	}

	public function test_install_is_idempotent(): void {
		global $wpdb;
		Schema::install();
		$before = $this->describeAll();

		$wpdb->last_error = '';
		Schema::install();

		$this->assertSame( '', $wpdb->last_error );
		$this->assertSame( $before, $this->describeAll() );
	}

	public function test_hourly_supports_upsert_on_hour_and_dims(): void {
		global $wpdb;
		Schema::install();
		$table = Schema::tables()['hourly'];
		$dims  = md5( 'people|home', true );
		$sql   = "INSERT INTO $table (`hour`, `dims`, `client_class`, `requests`, `php_ms_sum`) VALUES (%d, %s, %s, %d, %d) ON DUPLICATE KEY UPDATE `requests` = `requests` + VALUES(`requests`), `php_ms_sum` = `php_ms_sum` + VALUES(`php_ms_sum`)";

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Table name from Schema.
		$wpdb->query( $wpdb->prepare( $sql, 1700000000, $dims, 'people', 2, 30 ) );
		$wpdb->query( $wpdb->prepare( $sql, 1700000000, $dims, 'people', 3, 70 ) );
		$rows = $wpdb->get_results( "SELECT `requests`, `php_ms_sum` FROM $table", ARRAY_A );
		// phpcs:enable

		$this->assertSame(
			array(
				array(
					'requests'   => '5',
					'php_ms_sum' => '100',
				),
			),
			$rows
		);
	}

	public function test_sample_table_has_no_ip_or_ua_column(): void {
		global $wpdb;
		Schema::install();
		$columns = $wpdb->get_col( 'DESCRIBE ' . Schema::tables()['sample'] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$this->assertNotContains( 'ip', $columns );
		$this->assertNotContains( 'ua', $columns );
		foreach ( array( 'ts', 'hour', 'bot_id', 'ua_family_id', 'param_sig_id', 'url_pattern_id', 'self_us', 'ip_hash', 'ip_prefix', 'client_class' ) as $column ) {
			$this->assertContains( $column, $columns );
		}
	}

	public function test_install_seeds_autoloaded_options_without_overwriting(): void {
		update_option( 'bot_insight_settings', array( 'retention_days' => 30 ) );

		Schema::install();

		$this->assertSame( array( 'retention_days' => 30 ), get_option( 'bot_insight_settings' ) );
		$this->assertSame(
			array(
				'slugs' => array(),
				'keep'  => array(),
			),
			get_option( 'bot_insight_langs' )
		);
		$this->assertSame( array( 'bot_insight_langs', 'bot_insight_db_version' ), array_values( array_intersect( array( 'bot_insight_langs', 'bot_insight_db_version' ), $this->autoloadedNames() ) ) );
	}

	public function test_install_seeds_default_settings(): void {
		Schema::install();

		$this->assertSame( Plugin::DEFAULT_SETTINGS, get_option( 'bot_insight_settings' ) );
		$this->assertContains( 'bot_insight_settings', $this->autoloadedNames() );
	}

	public function test_activate_creates_buffer_dir_and_secret(): void {
		Plugin::activate();

		$dir     = get_option( 'bot_insight_buffer_dir' );
		$uploads = wp_upload_dir( null, false )['basedir'];
		$this->assertIsString( $dir );
		$this->assertMatchesRegularExpression( '#^' . preg_quote( $uploads, '#' ) . '/bot-insight-[A-Za-z0-9]{12}$#', $dir );
		$this->assertDirectoryExists( $dir );
		$this->assertTrue( FileBuffer::isOwnDir( $dir ) );
		$this->assertFileExists( $dir . '/.htaccess' );
		$this->assertContains( 'bot_insight_buffer_dir', $this->autoloadedNames() );

		$secret = get_option( 'bot_insight_secret' );
		$this->assertIsString( $secret );
		$this->assertSame( 32, strlen( $secret ) );
		$this->assertNotContains( 'bot_insight_secret', $this->autoloadedNames() );
	}

	public function test_activate_keeps_existing_dir_and_secret(): void {
		Plugin::activate();
		$dir    = get_option( 'bot_insight_buffer_dir' );
		$secret = get_option( 'bot_insight_secret' );

		Plugin::activate();

		$this->assertSame( $dir, get_option( 'bot_insight_buffer_dir' ) );
		$this->assertSame( $secret, get_option( 'bot_insight_secret' ) );
	}

	public function test_activate_replaces_a_stored_dir_that_is_gone(): void {
		update_option( 'bot_insight_buffer_dir', '/nonexistent/bot-insight-gone' );

		Plugin::activate();

		$dir = get_option( 'bot_insight_buffer_dir' );
		$this->assertNotSame( '/nonexistent/bot-insight-gone', $dir );
		$this->assertDirectoryExists( $dir );
	}

	public function test_maybe_upgrade_installs_when_version_is_lower(): void {
		update_option( 'bot_insight_db_version', Schema::DB_VERSION - 1 );

		Plugin::maybeUpgrade();

		$this->assertSame( Schema::DB_VERSION, (int) get_option( 'bot_insight_db_version' ) );
		$this->assertTrue( $this->tableExists( Schema::tables()['hourly'] ) );
	}

	public function test_upgrade_from_v1_adds_sample_weights_at_the_rate_then_in_effect(): void {
		global $wpdb;
		Schema::install();
		$table = Schema::tables()['sample'];
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.SchemaChange
		$wpdb->query( "ALTER TABLE $table DROP COLUMN weight" );
		$this->assertNotContains( 'weight', $wpdb->get_col( "DESCRIBE $table" ) );
		update_option( 'bot_insight_db_version', 1 );
		update_option( 'bot_insight_sample_rate', 7, false );
		$wpdb->insert(
			$table,
			array(
				'ts'   => 1794528000,
				'hour' => 1794528000,
			)
		);

		Plugin::maybeUpgrade();

		$this->assertSame( 2, Schema::DB_VERSION );
		$this->assertSame( Schema::DB_VERSION, (int) get_option( 'bot_insight_db_version' ) );
		$this->assertContains( 'weight', $wpdb->get_col( "DESCRIBE $table" ) );
		$this->assertSame( array( '7' ), $wpdb->get_col( "SELECT weight FROM $table" ) );

		// A later install leaves the weights alone.
		update_option( 'bot_insight_sample_rate', 9, false );
		Schema::install();
		$this->assertSame( array( '7' ), $wpdb->get_col( "SELECT weight FROM $table" ) );
		// phpcs:enable
	}

	public function test_fresh_install_samples_weigh_one_by_default(): void {
		global $wpdb;
		update_option( 'bot_insight_sample_rate', 7, false );
		Schema::install();
		$table = Schema::tables()['sample'];
		$wpdb->insert(
			$table,
			array(
				'ts'   => 1794528000,
				'hour' => 1794528000,
			)
		);
		$this->assertSame( array( '1' ), $wpdb->get_col( "SELECT weight FROM $table" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	public function test_maybe_upgrade_does_nothing_when_current(): void {
		global $wpdb;
		update_option( 'bot_insight_db_version', Schema::DB_VERSION );
		$before = $wpdb->num_queries;

		Plugin::maybeUpgrade();

		$this->assertSame( $before, $wpdb->num_queries );
		$this->assertFalse( $this->tableExists( Schema::tables()['hourly'] ) );
	}

	public function test_uninstall_removes_everything(): void {
		global $wpdb;
		Plugin::activate();
		$dir = get_option( 'bot_insight_buffer_dir' );
		file_put_contents( $dir . '/buffer-202611130000-abc-1.php', FileBuffer::HEADER ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		set_transient( 'bot_insight_probe', 1, HOUR_IN_SECONDS );
		update_option( 'bot_insight_other', 1, false );
		wp_schedule_event( time() + 60, 'hourly', 'bot_insight_fold' );
		wp_schedule_event( time() + 60, 'daily', 'bot_insight_daily' );

		$uploads = wp_upload_dir( null, false )['basedir'];
		// An orphan buffer dir from an earlier activation: removed.
		$orphan = $uploads . '/bot-insight-orphan123456';
		( new FileBuffer( $orphan ) )->ensureDir();
		// A dir with the same prefix that is not ours: kept.
		$foreign = $uploads . '/bot-insight-foreign';
		wp_mkdir_p( $foreign );
		file_put_contents( $foreign . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'bot-insight/bot-insight.php' );
		}
		include dirname( __DIR__, 2 ) . '/uninstall.php';

		foreach ( Schema::tables() as $table ) {
			$this->assertFalse( $this->tableExists( $table ), $table );
		}
		$left = $wpdb->get_col( "SELECT option_name FROM $wpdb->options WHERE option_name LIKE '%bot\\_insight\\_%'" );
		$this->assertSame( array(), $left );
		$this->assertFalse( get_option( 'bot_insight_settings' ) );
		$this->assertFalse( get_transient( 'bot_insight_probe' ) );
		$this->assertDirectoryDoesNotExist( $dir );
		$this->assertDirectoryDoesNotExist( $orphan );
		$this->assertDirectoryExists( $foreign );
		$this->assertFalse( wp_next_scheduled( 'bot_insight_fold' ) );
		$this->assertFalse( wp_next_scheduled( 'bot_insight_daily' ) );

		unlink( $foreign . '/index.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		rmdir( $foreign ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * Drop the plugin's tables, options and buffer dirs.
	 */
	private function cleanSlate(): void {
		global $wpdb;
		foreach ( Schema::tables() as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS $table" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$names = $wpdb->get_col( "SELECT option_name FROM $wpdb->options WHERE option_name LIKE '%bot\\_insight\\_%'" );
		foreach ( $names as $name ) {
			delete_option( $name );
		}
		$dirs = glob( wp_upload_dir( null, false )['basedir'] . '/bot-insight-*', GLOB_ONLYDIR );
		foreach ( is_array( $dirs ) ? $dirs : array() as $dir ) {
			if ( FileBuffer::isOwnDir( $dir ) ) {
				( new FileBuffer( $dir ) )->removeDir();
			}
		}
	}

	/**
	 * Whether a real table exists.
	 *
	 * @param string $table Full table name.
	 * @return bool
	 */
	private function tableExists( string $table ): bool {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/**
	 * Column definitions and indexes of all plugin tables.
	 *
	 * @return array<string, mixed>
	 */
	private function describeAll(): array {
		global $wpdb;
		$out = array();
		foreach ( Schema::tables() as $short => $table ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$out[ $short ] = array(
				'columns' => $wpdb->get_results( "DESCRIBE $table", ARRAY_A ),
				'indexes' => $wpdb->get_results( "SHOW INDEX FROM $table", ARRAY_A ),
			);
			// phpcs:enable
		}
		return $out;
	}

	/**
	 * Names of the plugin's options that WordPress autoloads.
	 *
	 * @return string[]
	 */
	private function autoloadedNames(): array {
		global $wpdb;
		$values = function_exists( 'wp_autoload_values_to_autoload' ) ? wp_autoload_values_to_autoload() : array( 'yes' );
		$in     = implode( ',', array_fill( 0, count( $values ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in is a list of %s placeholders.
		return $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM $wpdb->options WHERE option_name LIKE 'bot\\_insight\\_%%' AND autoload IN ($in)", $values ) );
	}
}
