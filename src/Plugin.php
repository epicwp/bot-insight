<?php
/**
 * Plugin bootstrap.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight;

use BotInsight\Capture\Recorder;

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

		add_action( 'plugins_loaded', array( Recorder::class, 'register' ) );
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
