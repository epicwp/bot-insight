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

		// Only the direct-download zip ships the updater and its library.
		if ( class_exists( Updater::class ) && class_exists( \YahnisElsts\PluginUpdateChecker\v5\PucFactory::class ) ) {
			Updater::register( $file );
		}

		register_activation_hook( $file, array( self::class, 'activate' ) );
		register_deactivation_hook( $file, array( Scheduler::class, 'unregister' ) );
		Scheduler::hooks();
		Events::hooks();
		RestController::hooks();
		DashboardPage::hooks( $file );
		// Before the recorder registers, so an upgrade's buffer dir is in place.
		add_action( 'plugins_loaded', array( self::class, 'maybeUpgrade' ), 5 );
		add_action( 'plugins_loaded', array( Recorder::class, 'register' ) );
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
}
