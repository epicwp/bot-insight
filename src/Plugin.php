<?php
/**
 * Plugin bootstrap.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight;

/**
 * Entry point of the plugin.
 */
final class Plugin {

	public const VERSION = '0.0.0';

	/**
	 * Boots the plugin.
	 *
	 * @param string $file Absolute path of the main plugin file.
	 */
	public static function boot( string $file ): void {
		// Only the direct-download zip ships the updater and its library.
		if ( class_exists( Updater::class ) && class_exists( \YahnisElsts\PluginUpdateChecker\v5\PucFactory::class ) ) {
			Updater::register( $file );
		}
	}
}
