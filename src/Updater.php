<?php
/**
 * Update checker for the direct-download flavour.
 *
 * This file is removed from the WordPress.org zip. It contacts github.com (twice a day, from the
 * admin/cron side, never during a visitor request) and is disclosed in readme.txt.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

/**
 * Offers new releases from GitHub to sites running the direct-download zip.
 */
final class Updater {

	public const REPO = 'https://github.com/epicwp/bot-insight/';

	/**
	 * Registers the update checker.
	 *
	 * Pre-releases (1.0.0-beta.N) are offered only when BOT_INSIGHT_BETA is defined as true.
	 *
	 * @param string $plugin_file Absolute path of the main plugin file.
	 */
	public static function register( string $plugin_file ): void {
		$checker = PucFactory::buildUpdateChecker( self::REPO, $plugin_file, 'bot-insight' );

		/**
		 * GitHub API wrapper; its class name carries the library minor version, so only
		 * the instance (not a hard-coded namespace) is used at runtime.
		 *
		 * @var \YahnisElsts\PluginUpdateChecker\v5p7\Vcs\GitHubApi $api
		 */
		$api = $checker->getVcsApi();
		$api->enableReleaseAssets( '/bot-insight-direct\.zip/' );

		if ( defined( 'BOT_INSIGHT_BETA' ) && true === constant( 'BOT_INSIGHT_BETA' ) ) {
			$api->setReleaseFilter(
				static function (): bool {
					return true;
				},
				$api::RELEASE_FILTER_ALL,
				20
			);
		}
	}
}
