<?php
/**
 * Plugin Name:       Bot Insight
 * Description:       Shows how much of your server's PHP time goes to which bots.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            EpicWP
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bot-insight
 *
 * @package BotInsight
 */

defined( 'ABSPATH' ) || exit;

define( 'BOT_INSIGHT_FILE', __FILE__ );
define( 'BOT_INSIGHT_DIR', plugin_dir_path( __FILE__ ) );

require_once BOT_INSIGHT_DIR . 'vendor/autoload.php';

\BotInsight\Plugin::boot( __FILE__ );
