<?php
/**
 * PHPUnit bootstrap for integration tests (WordPress and a MySQL test database).
 *
 * Run `bin/install-wp-tests.sh` first. The WordPress test library comes from the
 * wp-phpunit/wp-phpunit Composer package; its config is the file that script generates.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

$bot_insight_root = dirname( __DIR__, 2 );

require_once $bot_insight_root . '/vendor/autoload.php';

$bot_insight_config = getenv( 'WP_PHPUNIT__TESTS_CONFIG' );
if ( false === $bot_insight_config || '' === $bot_insight_config ) {
	$bot_insight_config = $bot_insight_root . '/.wp-tests/wp-tests-config.php';
	putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . $bot_insight_config );
}
if ( ! is_readable( $bot_insight_config ) ) {
	fwrite( STDERR, "Missing $bot_insight_config. Run bin/install-wp-tests.sh first.\n" );
	exit( 1 );
}

$bot_insight_tests_dir = getenv( 'WP_PHPUNIT__DIR' );
if ( false === $bot_insight_tests_dir || '' === $bot_insight_tests_dir ) {
	$bot_insight_tests_dir = $bot_insight_root . '/vendor/wp-phpunit/wp-phpunit';
}

require_once $bot_insight_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $bot_insight_root ) {
		require $bot_insight_root . '/bot-insight.php';
	}
);

require $bot_insight_tests_dir . '/includes/bootstrap.php';
