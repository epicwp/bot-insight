<?php
/**
 * Uninstall: remove every table, option, transient, scheduled hook and buffer directory.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/vendor/autoload.php';

global $wpdb;

// Scheduled work first, so nothing recreates data while the rest goes.
foreach ( array( 'bot_insight_fold', 'bot_insight_daily' ) as $bot_insight_hook ) {
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( $bot_insight_hook );
	}
	wp_clear_scheduled_hook( $bot_insight_hook );
}

// Buffer directories: the stored one and any orphan from an earlier activation. Only
// directories that carry the plugin's index.php marker are touched.
$bot_insight_dirs   = array();
$bot_insight_stored = get_option( 'bot_insight_buffer_dir', '' );
if ( is_string( $bot_insight_stored ) && '' !== $bot_insight_stored ) {
	$bot_insight_dirs[] = $bot_insight_stored;
}
$bot_insight_uploads = wp_upload_dir( null, false );
if ( empty( $bot_insight_uploads['error'] ) && is_string( $bot_insight_uploads['basedir'] ) && '' !== $bot_insight_uploads['basedir'] ) {
	$bot_insight_found = glob( untrailingslashit( $bot_insight_uploads['basedir'] ) . '/bot-insight-*', GLOB_ONLYDIR );
	if ( is_array( $bot_insight_found ) ) {
		$bot_insight_dirs = array_merge( $bot_insight_dirs, $bot_insight_found );
	}
}
foreach ( array_unique( $bot_insight_dirs ) as $bot_insight_dir ) {
	( new \BotInsight\Buffer\FileBuffer( $bot_insight_dir ) )->removeDir();
}

\BotInsight\Storage\Schema::drop();

// Options and transients. delete_option() keeps the options cache in step.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One-off lookup of the plugin's own option names.
$bot_insight_options = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( 'bot_insight_' ) . '%',
		$wpdb->esc_like( '_transient_bot_insight_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_bot_insight_' ) . '%'
	)
);
foreach ( $bot_insight_options as $bot_insight_option ) {
	delete_option( $bot_insight_option );
}
