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
	 * Largest download accepted (compressed), in bytes.
	 */
	private const MAX_DOWNLOAD = 104857600;

	/**
	 * Default ceiling for the unpacked database, in bytes.
	 */
	private const MAX_UNPACKED = 314572800;

	/**
	 * Checks an unpacked file before it replaces the live one.
	 *
	 * @var callable
	 */
	private $validator;

	/**
	 * Ceiling for the unpacked size, in bytes.
	 *
	 * @var int
	 */
	private $max_unpacked;

	/**
	 * Constructor.
	 *
	 * @param callable|null $validator    Takes a path, returns whether it is a usable MMDB. Default reads the MaxMind metadata.
	 * @param int|null      $max_unpacked Ceiling for the unpacked size in bytes (default 300 MB).
	 */
	public function __construct( ?callable $validator = null, ?int $max_unpacked = null ) {
		$this->validator    = $validator ?? array( self::class, 'isValidMmdb' );
		$this->max_unpacked = null === $max_unpacked ? self::MAX_UNPACKED : $max_unpacked;
	}

	/**
	 * Default validator: the file opens as MMDB and its metadata is sane.
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	public static function isValidMmdb( string $path ): bool {
		try {
			$reader = new \MaxMind\Db\Reader( $path );
			$meta   = $reader->metadata();
			$major  = $meta->binaryFormatMajorVersion; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$type   = $meta->databaseType; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$valid  = 2 === $major && is_string( $type ) && '' !== $type;
			$reader->close();
			return $valid;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

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
	public function run( ?int $now = null ): bool {
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
		$gz = tempnam( get_temp_dir(), 'bot-insight-geo' );
		if ( ! $gz ) {
			return 'error';
		}
		$tmp = $target . '.' . bin2hex( random_bytes( 4 ) ) . '.tmp';
		try {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'             => 60,
					'stream'              => true,
					'limit_response_size' => self::MAX_DOWNLOAD,
					'filename'            => $gz,
				)
			);
			if ( is_wp_error( $response ) ) {
				return 'error';
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( 404 === $code ) {
				return 'missing';
			}
			if ( 200 !== $code || ! $this->gunzip( $gz, $tmp ) || ! (bool) call_user_func( $this->validator, $tmp ) ) {
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
		$crc     = hash_init( 'crc32b' );
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
				hash_update( $crc, $chunk );
				$written += strlen( $chunk );
				if ( $written > $this->max_unpacked ) {
					$ok = false;
					break;
				}
			}
		}
		// A truncated archive stops before the end of the stream.
		$ok = $ok && gzeof( $in );
		gzclose( $in );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $ok && $written > 0 && $this->trailerMatches( $source, hash_final( $crc, true ), $written );
	}

	/**
	 * Compare the gzip trailer (CRC32 and size modulo 2^32) with what was unpacked.
	 *
	 * The zlib reader ends quietly on a truncated archive, so the trailer is the proof of completeness.
	 *
	 * @param string $source  Gzip file.
	 * @param string $crc     Raw big-endian CRC32 of the unpacked bytes.
	 * @param int    $written Unpacked size in bytes.
	 * @return bool
	 */
	private function trailerMatches( string $source, string $crc, int $written ): bool {
		$size = filesize( $source );
		if ( false === $size || $size < 18 ) {
			return false;
		}
		$fh = fopen( $source, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $fh ) {
			return false;
		}
		fseek( $fh, -8, SEEK_END );
		$trailer = fread( $fh, 8 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( ! is_string( $trailer ) || 8 !== strlen( $trailer ) ) {
			return false;
		}
		$parts = unpack( 'Vcrc/Visize', $trailer );
		return is_array( $parts )
			&& strrev( $crc ) === pack( 'V', $parts['crc'] )
			&& ( $written & 0xFFFFFFFF ) === $parts['isize'];
	}
}
