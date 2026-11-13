<?php
/**
 * Plugin bootstrap.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight;

use BotInsight\Admin\DashboardPage;
use BotInsight\Admin\RestController;
use BotInsight\Admin\SettingsPage;
use BotInsight\Buffer\FileBuffer;
use BotInsight\Capture\Recorder;
use BotInsight\Fold\Scheduler;
use BotInsight\Site\Events;
use BotInsight\Site\Langs;
use BotInsight\Storage\Schema;

/**
 * Entry point of the plugin.
 */
final class Plugin {

	public const VERSION = '0.0.0';

	/**
	 * Defaults of option `bot_insight_settings`.
	 */
	public const DEFAULT_SETTINGS = array(
		'sample_rate'    => 'auto',
		'exclude_admins' => true,
		'range_updates'  => false,
		'geo_updates'    => false,
		'retention_days' => 400,
	);

	/**
	 * Process CPU time in microseconds when the plugin file loaded.
	 *
	 * @var int
	 */
	private static $cpu_start = 0;

	/**
	 * Settings of this request, once read.
	 *
	 * @var array<string, mixed>|null
	 */
	private static $settings = null;

	/**
	 * Boots the plugin.
	 *
	 * @param string $file Absolute path of the main plugin file.
	 */
	public static function boot( string $file ): void {
		self::$cpu_start = Recorder::cpuMicros();

		// Only the direct-download zip ships the updater and its library. It is only needed where
		// WordPress checks or shows updates, so visitor requests never build it.
		if ( self::wantsUpdater() && class_exists( Updater::class ) ) {
			Updater::register( $file );
		}

		register_activation_hook( $file, array( self::class, 'activate' ) );
		register_deactivation_hook( $file, array( Scheduler::class, 'unregister' ) );
		Scheduler::hooks();
		Events::hooks();
		RestController::hooks();
		DashboardPage::hooks( $file );
		SettingsPage::hooks();
		// Before the recorder registers, so an upgrade's buffer dir is in place.
		add_action( 'plugins_loaded', array( self::class, 'maybeUpgrade' ), 5 );
		add_action( 'plugins_loaded', array( Recorder::class, 'register' ) );
		add_action( 'admin_init', array( self::class, 'addPrivacyPolicyContent' ) );
		add_action( 'before_woocommerce_init', array( self::class, 'declareWooCompatibility' ) );
	}

	/**
	 * Whether this request may check or show updates: admin screens, cron and WP-CLI.
	 */
	public static function wantsUpdater(): bool {
		return is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI );
	}

	/**
	 * Suggested text for the site's privacy policy (Settings > Privacy > Policy guide).
	 */
	public static function addPrivacyPolicyContent(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$paragraphs   = array(
			__( 'This site uses the Bot Insight plugin to measure how much server time automated clients (bots) use. The data stays on this site\'s own server and is not shared with anyone.', 'bot-insight' ),
			__( 'For each request that reaches the site, the visitor\'s IP address and browser user agent are held in a temporary file for a few minutes. They are then reduced to a hash of the IP address made with a random key that changes every day (so the hash cannot be traced back or linked across days), the network part of the address (the first three blocks of an IPv4 address, or the first 48 bits of an IPv6 address), and the browser or bot family. Of the visited address, only a URL pattern and the names of query parameters are stored, never their values. No names, email addresses, accounts or order details are stored.', 'bot-insight' ),
			__( 'The plugin sets a cookie named bi_js with the value 1 when the browser runs JavaScript. It is strictly necessary to tell browsers apart from bots, contains no identifier and is deleted when the browser session ends.', 'bot-insight' ),
		);
		$retention    = (int) self::settings()['retention_days'];
		$paragraphs[] = sprintf(
			/* translators: %d: number of days the aggregated data is kept. */
			__( 'The aggregated measurements are kept for %d days and then deleted.', 'bot-insight' ),
			$retention
		);
		$content = '';
		foreach ( $paragraphs as $paragraph ) {
			$content .= '<p>' . esc_html( $paragraph ) . '</p>';
		}
		wp_add_privacy_policy_content( __( 'Bot Insight', 'bot-insight' ), wp_kses_post( $content ) );
	}

	/**
	 * Declare compatibility with WooCommerce High-Performance Order Storage. The daily snapshot reads
	 * order counts per country through either storage.
	 */
	public static function declareWooCompatibility(): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', BOT_INSIGHT_FILE, true );
		}
	}

	/**
	 * Activation: tables, seeded options, buffer directory, hashing secret and language option.
	 * Idempotent.
	 */
	public static function activate(): void {
		Schema::install();
		self::ensureBufferDir();
		if ( false === get_option( 'bot_insight_secret' ) ) {
			add_option( 'bot_insight_secret', wp_generate_password( 32 ), '', false );
		}
		Langs::update();
		// An upgrade runs on plugins_loaded, before Action Scheduler is ready: schedule on init.
		if ( did_action( 'init' ) ) {
			Scheduler::register();
		} else {
			add_action( 'init', array( Scheduler::class, 'register' ), 20 );
		}
	}

	/**
	 * Run the activation routine when the stored schema version is lower than the code's.
	 *
	 * Covers plugin updates, which do not fire the activation hook. One autoloaded option read
	 * otherwise.
	 */
	public static function maybeUpgrade(): void {
		if ( (int) get_option( Schema::VERSION_OPTION, 0 ) < Schema::DB_VERSION ) {
			self::activate();
		}
	}

	/**
	 * Make sure the buffer directory exists and is stored in `bot_insight_buffer_dir`.
	 *
	 * Keeps a stored directory that still exists. Otherwise creates
	 * `<uploads>/bot-insight-<12 random chars>`: the random name keeps it from being guessed
	 * on servers that ignore its `.htaccess` (nginx).
	 *
	 * @return string The directory, or '' when it could not be created.
	 */
	public static function ensureBufferDir(): string {
		$stored = get_option( 'bot_insight_buffer_dir', '' );
		if ( is_string( $stored ) && '' !== $stored && is_dir( $stored ) ) {
			( new FileBuffer( $stored ) )->ensureDir();
			return $stored;
		}

		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) || ! is_string( $uploads['basedir'] ) || '' === $uploads['basedir'] ) {
			return '';
		}
		$dir = untrailingslashit( $uploads['basedir'] ) . '/bot-insight-' . wp_generate_password( 12, false );
		if ( ! ( new FileBuffer( $dir ) )->ensureDir() ) {
			return '';
		}
		if ( false === get_option( 'bot_insight_buffer_dir' ) ) {
			add_option( 'bot_insight_buffer_dir', $dir, '', true );
		} else {
			update_option( 'bot_insight_buffer_dir', $dir );
		}
		// Refreshed range lists lived in the old directory.
		update_option( 'bot_insight_ranges_override', '', true );
		return $dir;
	}

	/**
	 * Process CPU time in microseconds stored at boot (0 when unavailable).
	 *
	 * @return int
	 */
	public static function cpuStart(): int {
		return self::$cpu_start;
	}

	/**
	 * Option `bot_insight_settings` merged over the defaults, cached for the request.
	 *
	 * @return array<string, mixed>
	 */
	public static function settings(): array {
		if ( null === self::$settings ) {
			$stored         = get_option( 'bot_insight_settings', array() );
			self::$settings = array_merge( self::DEFAULT_SETTINGS, is_array( $stored ) ? $stored : array() );
		}
		return self::$settings;
	}

	/**
	 * Forget the per-request settings cache, so the next settings() call reads the option again.
	 */
	public static function resetSettings(): void {
		self::$settings = null;
	}
}
