<?php
/**
 * Writes a release version into the plugin header, readme.txt and Plugin::VERSION.
 *
 * Usage: php bin/set-version.php <semver> [--root=<dir>]
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

// phpcs:ignoreFile

$version = $argv[1] ?? '';
$root    = dirname( __DIR__ );

foreach ( array_slice( $argv, 2 ) as $arg ) {
	if ( 0 === strpos( $arg, '--root=' ) ) {
		$root = rtrim( substr( $arg, 7 ), '/' );
	}
}

if ( ! preg_match( '/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', $version ) ) {
	fwrite( STDERR, "Invalid semver: {$version}\n" );
	exit( 1 );
}

$targets = array(
	'bot-insight.php' => array( '/^(\s*\*\s*Version:\s*).+$/m', '${1}' . $version ),
	'readme.txt'      => array( '/^(Stable tag:\s*).+$/m', '${1}' . $version ),
	'src/Plugin.php'  => array( "/(const VERSION = ')[^']*(';)/", '${1}' . $version . '${2}' ),
);

$new = array();
foreach ( $targets as $file => list( $pattern, $replacement ) ) {
	$path     = $root . '/' . $file;
	$contents = is_readable( $path ) ? file_get_contents( $path ) : false;
	if ( false === $contents ) {
		fwrite( STDERR, "Cannot read {$path}\n" );
		exit( 1 );
	}
	$count   = 0;
	$updated = preg_replace( $pattern, $replacement, $contents, 1, $count );
	if ( 1 !== $count || null === $updated ) {
		fwrite( STDERR, "No version found in {$file}\n" );
		exit( 1 );
	}
	$new[ $path ] = $updated;
}

foreach ( $new as $path => $contents ) {
	file_put_contents( $path, $contents );
}

echo "Version set to {$version}\n";
