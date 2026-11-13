<?php
/**
 * Opt-in download of the free DB-IP Lite databases.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Geo;

use BotInsight\Buffer\FileBuffer;
use BotInsight\Plugin;

/**
 * Downloads dbip-country-lite and dbip-asn-lite (CC BY 4.0, https://db-ip.com) into the plugin's
 * private uploads directory. Runs only from cron or an admin action, and only after the owner
 * enabled the `geo_updates` setting.
 */
final class GeoUpdater {

	private const BASE_URL = 'https://download.db-ip.com/free/';

	private const COUNTRY_FILE = 'dbip-country-lite.mmdb';

	private const ASN_FILE = 'dbip-asn-lite.mmdb';

	/**
	 * Directory holding the databases ('' when the plugin has no private directory yet).
	 *
	 * @return string
	 */
	private static function dir(): string {
		$base = get_option( 'bot_insight_buffer_dir', '' );
		return is_string( $base ) && '' !== $base ? rtrim( $base, '/\\' ) . '/geo' : '';
	}

	/**
	 * Path of the country database.
	 *
	 * @return string
	 */
	public static function countryPath(): string {
		$dir = self::dir();
		return '' === $dir ? '' : $dir . '/' . self::COUNTRY_FILE;
	}

	/**
	 * Path of the ASN database.
	 *
	 * @return string
	 */
	public static function asnPath(): string {
		$dir = self::dir();
		return '' === $dir ? '' : $dir . '/' . self::ASN_FILE;
	}

	/**
	 * Download and install both databases.
	 *
	 * @param int|null $now Unix time, for tests.
	 * @return bool True when both databases were installed.
	 */
	public function run( int $now = null ): bool {
		if ( empty( Plugin::settings()['geo_updates'] ) ) {
			return false;
		}
		$dir = self::dir();
		if ( '' === $dir || ! ( new FileBuffer( $dir ) )->ensureDir() ) {
			return false;
		}
		$now    = null === $now ? time() : $now;
		$year   = (int) gmdate( 'Y', $now );
		$month  = (int) gmdate( 'n', $now );
		$stamps = array( gmdate( 'Y-m', $now ), gmdate( 'Y-m', (int) gmmktime( 0, 0, 0, $month - 1, 1, $year ) ) );

		$ok = true;
		foreach ( array(
			'country' => self::COUNTRY_FILE,
			'asn'     => self::ASN_FILE,
		) as $kind => $target ) {
			$done = false;
			foreach ( $stamps as $stamp ) {
				$result = $this->fetch( self::BASE_URL . "dbip-{$kind}-lite-{$stamp}.mmdb.gz", $dir . '/' . $target );
				if ( 'ok' === $result ) {
					$done = true;
					break;
				}
				if ( 'missing' !== $result ) {
					break;
				}
			}
			$ok = $ok && $done;
		}
		return $ok;
	}

	/**
	 * Download one gzip file and install it atomically.
	 *
	 * @param string $url    Source URL.
	 * @param string $target Final path.
	 * @return string 'ok', 'missing' (HTTP 404) or 'error'.
	 */
	private function fetch( string $url, string $target ): string {
		$gz = wp_tempnam( $url );
		if ( ! $gz ) {
			return 'error';
		}
		$tmp = $target . '.tmp';
		try {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'  => 60,
					'stream'   => true,
					'filename' => $gz,
				)
			);
			if ( is_wp_error( $response ) ) {
				return 'error';
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( 404 === $code ) {
				return 'missing';
			}
			if ( 200 !== $code || ! $this->gunzip( $gz, $tmp ) ) {
				return 'error';
			}
			return rename( $tmp, $target ) ? 'ok' : 'error'; // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		} finally {
			wp_delete_file( $gz );
			if ( is_file( $tmp ) ) {
				wp_delete_file( $tmp );
			}
		}
	}

	/**
	 * Gunzip in chunks.
	 *
	 * @param string $source Gzip file.
	 * @param string $dest   Output file.
	 * @return bool
	 */
	private function gunzip( string $source, string $dest ): bool {
		$in = is_readable( $source ) ? gzopen( $source, 'rb' ) : false;
		if ( false === $in ) {
			return false;
		}
		$out = fopen( $dest, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $out ) {
			gzclose( $in );
			return false;
		}
		$ok      = true;
		$written = 0;
		while ( ! gzeof( $in ) ) {
			$chunk = gzread( $in, 65536 );
			if ( false === $chunk ) {
				$ok = false;
				break;
			}
			if ( '' !== $chunk ) {
				if ( false === fwrite( $out, $chunk ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
					$ok = false;
					break;
				}
				$written += strlen( $chunk );
			}
		}
		gzclose( $in );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $ok && $written > 0;
	}
}
