<?php
/**
 * Dev tool: downloads published bot IP ranges and writes data/ranges/*.php.
 *
 * Usage: php bin/update-ranges.php
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

require_once dirname( __DIR__ ) . '/src/Net/RangeUpdater.php';

$bot_insight_ok = ( new \BotInsight\Net\RangeUpdater(
	static function ( $line ) {
		fwrite( STDERR, $line . "\n" );
	}
) )->run( dirname( __DIR__ ) . '/data/ranges' );

exit( $bot_insight_ok ? 0 : 1 );
