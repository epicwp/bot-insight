<?php
/**
 * Database schema.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Storage;

use BotInsight\Plugin;

/**
 * Creates, upgrades and drops the plugin's tables and seeds its per-request options.
 *
 * Only runs on activation, on a plugin update (db version bump) and on uninstall; never on a
 * visitor's request otherwise.
 */
final class Schema {

	/**
	 * Version of the table definitions. Bump it when a definition changes.
	 */
	public const DB_VERSION = 2;

	/**
	 * Option that holds the installed schema version.
	 */
	public const VERSION_OPTION = 'bot_insight_db_version';

	/**
	 * Full table names keyed by short name.
	 *
	 * @return array<string, string>
	 */
	public static function tables(): array {
		global $wpdb;
		$out = array();
		foreach ( array( 'hourly', 'daily', 'dict', 'sample', 'events', 'snapshot' ) as $short ) {
			$out[ $short ] = $wpdb->prefix . 'botinsight_' . $short;
		}
		return $out;
	}

	/**
	 * Create or upgrade the tables with dbDelta, seed the options and store the schema version.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$from = (int) get_option( self::VERSION_OPTION, 0 );
		dbDelta( self::ddl( self::tables(), $wpdb->get_charset_collate() ) );
		if ( $from > 0 && $from < 2 ) {
			self::weighV1Samples();
		}

		self::seedOptions();
		if ( false === get_option( self::VERSION_OPTION ) ) {
			add_option( self::VERSION_OPTION, self::DB_VERSION, '', true );
		} else {
			update_option( self::VERSION_OPTION, self::DB_VERSION );
		}
	}

	/**
	 * Version 2 added `weight` to the sample table. Rows from version 1 get the rate that the
	 * reports multiplied them by until then: the fixed setting, else the stored daily rate.
	 */
	private static function weighV1Samples(): void {
		global $wpdb;
		$settings = get_option( 'bot_insight_settings', array() );
		if ( is_array( $settings ) && isset( $settings['sample_rate'] ) && is_int( $settings['sample_rate'] ) ) {
			$rate = $settings['sample_rate'];
		} else {
			$rate = (int) get_option( 'bot_insight_sample_rate', 1 );
		}
		$table = self::tables()['sample'];
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET weight = %d", max( 1, min( 65535, $rate ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- One-off upgrade of the plugin's own table; name from tables().
	}

	/**
	 * Add the options read on every request, autoloaded, so a missing option never costs a
	 * query. `add_option()` never overwrites an existing value.
	 */
	public static function seedOptions(): void {
		add_option( 'bot_insight_settings', Plugin::DEFAULT_SETTINGS, '', true );
		add_option(
			'bot_insight_langs',
			array(
				'slugs' => array(),
				'keep'  => array(),
			),
			'',
			true
		);
		add_option( 'bot_insight_buffer_dir', '', '', true );
		// Directory of refreshed range lists, '' when none: spares the recorder a directory check.
		add_option( 'bot_insight_ranges_override', '', '', true );
	}

	/**
	 * Drop all tables.
	 */
	public static function drop(): void {
		global $wpdb;
		foreach ( self::tables() as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS `$table`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.SchemaChange -- Uninstall of the plugin's own tables; names come from tables().
		}
	}

	/**
	 * CREATE TABLE statements in dbDelta format (two spaces before PRIMARY KEY, one key per line).
	 *
	 * @param array<string, string> $t       Full table names keyed by short name.
	 * @param string                $collate Charset and collation clause.
	 * @return string[]
	 */
	private static function ddl( array $t, string $collate ): array {
		return array(
			"CREATE TABLE {$t['hourly']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  hour int(10) unsigned NOT NULL,
  dims binary(16) NOT NULL,
  client_class varchar(16) NOT NULL DEFAULT '',
  bot_id int(10) unsigned NOT NULL DEFAULT 0,
  route varchar(64) NOT NULL DEFAULT '',
  param_sig_id int(10) unsigned NOT NULL DEFAULT 0,
  lang varchar(12) NOT NULL DEFAULT '',
  country char(2) NOT NULL DEFAULT '',
  asn_type varchar(12) NOT NULL DEFAULT '',
  via_cdn tinyint(3) unsigned NOT NULL DEFAULT 0,
  verified tinyint(3) unsigned NOT NULL DEFAULT 0,
  js_seen tinyint(3) unsigned NOT NULL DEFAULT 0,
  status_class char(3) NOT NULL DEFAULT '',
  uncached_reason varchar(16) NOT NULL DEFAULT '',
  requests int(10) unsigned NOT NULL DEFAULT 0,
  php_ms_sum bigint(20) unsigned NOT NULL DEFAULT 0,
  cpu_ms_sum bigint(20) unsigned NOT NULL DEFAULT 0,
  db_queries_sum bigint(20) unsigned NOT NULL DEFAULT 0,
  mem_peak_max int(10) unsigned NOT NULL DEFAULT 0,
  slow_2s int(10) unsigned NOT NULL DEFAULT 0,
  unique_ips int(10) unsigned NOT NULL DEFAULT 0,
  ips_seen_once int(10) unsigned NOT NULL DEFAULT 0,
  top_ip_share smallint(5) unsigned NOT NULL DEFAULT 0,
  sessions_created int(10) unsigned NOT NULL DEFAULT 0,
  cart_changes int(10) unsigned NOT NULL DEFAULT 0,
  orders int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY hour_dims (hour,dims),
  KEY hour_class (hour,client_class)
) ENGINE=InnoDB $collate;",
			"CREATE TABLE {$t['daily']} (
  day date NOT NULL,
  client_class varchar(16) NOT NULL,
  requests int(10) unsigned NOT NULL DEFAULT 0,
  php_ms_sum bigint(20) unsigned NOT NULL DEFAULT 0,
  unique_ips int(10) unsigned NOT NULL DEFAULT 0,
  ips_seen_once int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (day,client_class)
) ENGINE=InnoDB $collate;",
			"CREATE TABLE {$t['dict']} (
  id int(10) unsigned NOT NULL AUTO_INCREMENT,
  type varchar(12) NOT NULL,
  value varchar(191) NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY type_value (type,value)
) ENGINE=InnoDB $collate;",
			"CREATE TABLE {$t['sample']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  ts int(10) unsigned NOT NULL,
  hour int(10) unsigned NOT NULL DEFAULT 0,
  php_ms float NOT NULL DEFAULT 0,
  cpu_ms float NOT NULL DEFAULT 0,
  mem_kb int(10) unsigned NOT NULL DEFAULT 0,
  db_queries mediumint(8) unsigned NOT NULL DEFAULT 0,
  status smallint(5) unsigned NOT NULL DEFAULT 0,
  status_class char(3) NOT NULL DEFAULT '',
  uncached_reason varchar(16) NOT NULL DEFAULT '',
  method varchar(7) NOT NULL DEFAULT '',
  route varchar(64) NOT NULL DEFAULT '',
  param_sig_id int(10) unsigned NOT NULL DEFAULT 0,
  depth varchar(8) NOT NULL DEFAULT '',
  lang varchar(12) NOT NULL DEFAULT '',
  url_pattern_id int(10) unsigned NOT NULL DEFAULT 0,
  referer_type varchar(8) NOT NULL DEFAULT '',
  ip_source varchar(20) NOT NULL DEFAULT '',
  ip_hash char(16) NOT NULL DEFAULT '',
  ip_prefix varchar(45) NOT NULL DEFAULT '',
  via_cdn tinyint(3) unsigned NOT NULL DEFAULT 0,
  cdn_country char(2) NOT NULL DEFAULT '',
  country char(2) NOT NULL DEFAULT '',
  asn int(10) unsigned NOT NULL DEFAULT 0,
  asn_type varchar(12) NOT NULL DEFAULT '',
  header_flags smallint(5) unsigned NOT NULL DEFAULT 0,
  js_seen tinyint(3) unsigned NOT NULL DEFAULT 0,
  session_created tinyint(3) unsigned NOT NULL DEFAULT 0,
  cart_changed tinyint(3) unsigned NOT NULL DEFAULT 0,
  order_created tinyint(3) unsigned NOT NULL DEFAULT 0,
  payment_callback tinyint(3) unsigned NOT NULL DEFAULT 0,
  ua_family_id int(10) unsigned NOT NULL DEFAULT 0,
  ua_outdated tinyint(3) unsigned NOT NULL DEFAULT 0,
  bot_id int(10) unsigned NOT NULL DEFAULT 0,
  client_class varchar(16) NOT NULL DEFAULT '',
  verified tinyint(3) unsigned NOT NULL DEFAULT 0,
  signals smallint(5) unsigned NOT NULL DEFAULT 0,
  self_us int(10) unsigned NOT NULL DEFAULT 0,
  weight smallint(5) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY  (id),
  KEY ts (ts)
) ENGINE=InnoDB $collate;",
			"CREATE TABLE {$t['events']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  ts int(10) unsigned NOT NULL,
  type varchar(24) NOT NULL,
  subject varchar(191) NOT NULL DEFAULT '',
  detail text NOT NULL,
  PRIMARY KEY  (id),
  KEY ts (ts)
) ENGINE=InnoDB $collate;",
			"CREATE TABLE {$t['snapshot']} (
  day date NOT NULL,
  data longtext NOT NULL,
  PRIMARY KEY  (day)
) ENGINE=InnoDB $collate;",
		);
	}
}
