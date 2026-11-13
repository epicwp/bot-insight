<?php
/**
 * Per-hour and per-day unique IP hash counting.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Fold;

use BotInsight\Buffer\FileBuffer;

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged -- Plain files in the plugin's private buffer directory; runs inside the locked fold only.

/**
 * Keeps the salted IP hashes seen per hour (per dims) and per day (per client class) in
 * append-only files, and turns them into unique counts when an hour or day closes.
 *
 * Every file starts with `<?php exit; ?>` and ends in `.php`, like the buffer files.
 *
 * - Hour file `uniq-<YmdH>.php`: one line per request, `hex(dims)\tclass\tip_hash` (dims are raw
 *   bytes, so they are hex encoded to keep tabs and newlines out).
 * - Day file `uniq-<Ymd>.php`: written when an hour is rolled in, one line per class and hash of
 *   that hour, `YmdH\tclass\tip_hash\tcount`. Rolling the same hour in twice (a crash between the
 *   roll and the hour file delete) is harmless: per hour, class and hash the larger count wins.
 *
 * Lines are buffered in memory and written by `flush()`; every reading method flushes first.
 */
final class UniqueTracker {

	/**
	 * Requests of this many busiest hashes make up `top_ip_share`.
	 */
	private const TOP = 10;

	/**
	 * Directory without trailing slash.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Pending lines per file name.
	 *
	 * @var array<string, string>
	 */
	private $pending = array();

	/**
	 * Constructor.
	 *
	 * @param string $dir Directory for the tracker files (the buffer directory).
	 */
	public function __construct( string $dir ) {
		$this->dir = rtrim( $dir, '/\\' );
	}

	/**
	 * Flush pending lines when the object goes away.
	 */
	public function __destruct() {
		try {
			$this->flush();
		} catch ( \Throwable $e ) {
			// A destructor must not throw; the lines are lost (a few uniques, never requests).
			unset( $e );
		}
	}

	/**
	 * Count one request. An empty hash (no IP) is ignored.
	 *
	 * @param int    $hour         Hour start (UTC).
	 * @param string $dims         Raw dims key of the hourly row the request was counted under.
	 * @param string $client_class Client class.
	 * @param string $ip_hash      Salted IP hash.
	 */
	public function add( int $hour, string $dims, string $client_class, string $ip_hash ): void {
		if ( '' === $ip_hash ) {
			return;
		}
		$this->append( self::hourFile( $hour ), bin2hex( $dims ) . "\t" . self::clean( $client_class ) . "\t" . self::clean( $ip_hash ) . "\n" );
	}

	/**
	 * Write pending lines. A new file gets the `<?php exit; ?>` header first.
	 *
	 * @throws \RuntimeException When a file cannot be written.
	 */
	public function flush(): void {
		$pending       = $this->pending;
		$this->pending = array();
		$failed        = array();
		foreach ( $pending as $name => $lines ) {
			$path = $this->dir . '/' . $name;
			if ( ! @is_file( $path ) ) {
				$lines = FileBuffer::HEADER . $lines;
			}
			if ( false === @file_put_contents( $path, $lines, FILE_APPEND | LOCK_EX ) ) {
				$failed[] = $name;
			}
		}
		if ( array() !== $failed ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
			throw new \RuntimeException( 'Could not write unique tracker file: ' . implode( ', ', $failed ) );
		}
	}

	/**
	 * Hour starts that have a tracker file, oldest first.
	 *
	 * @return int[]
	 */
	public function openHours(): array {
		$out = array();
		foreach ( $this->names( '/^uniq-(\d{10})\.php$/' ) as $stamp ) {
			$time = \DateTime::createFromFormat( '!YmdH', $stamp, new \DateTimeZone( 'UTC' ) );
			if ( false !== $time ) {
				$out[] = $time->getTimestamp();
			}
		}
		sort( $out );
		return $out;
	}

	/**
	 * Days (Ymd, UTC) that have a tracker file, oldest first.
	 *
	 * @return string[]
	 */
	public function openDays(): array {
		$out = $this->names( '/^uniq-(\d{8})\.php$/' );
		sort( $out, SORT_STRING );
		return $out;
	}

	/**
	 * Unique metrics of an hour per dims, without deleting the file.
	 *
	 * @param int $hour Hour start (UTC).
	 * @return array<string, array{unique_ips:int, ips_seen_once:int, top_ip_share:int}> Keyed by raw dims.
	 */
	public function hourStats( int $hour ): array {
		$counts = array();
		foreach ( $this->hourLines( $hour ) as $parts ) {
			$dims = (string) hex2bin( $parts[0] );
			if ( ! isset( $counts[ $dims ][ $parts[2] ] ) ) {
				$counts[ $dims ][ $parts[2] ] = 0;
			}
			++$counts[ $dims ][ $parts[2] ];
		}
		$out = array();
		foreach ( $counts as $dims => $per_hash ) {
			$total = array_sum( $per_hash );
			rsort( $per_hash, SORT_NUMERIC );
			$top          = array_sum( array_slice( $per_hash, 0, self::TOP ) );
			$out[ $dims ] = array(
				'unique_ips'    => count( $per_hash ),
				'ips_seen_once' => self::once( $per_hash ),
				'top_ip_share'  => $total > 0 ? (int) round( $top / $total * 10000 ) : 0,
			);
		}
		return $out;
	}

	/**
	 * Append an hour's per-class, per-hash request counts to its day file.
	 *
	 * @param int $hour Hour start (UTC).
	 */
	public function rollIntoDay( int $hour ): void {
		$counts = array();
		foreach ( $this->hourLines( $hour ) as $parts ) {
			if ( ! isset( $counts[ $parts[1] ][ $parts[2] ] ) ) {
				$counts[ $parts[1] ][ $parts[2] ] = 0;
			}
			++$counts[ $parts[1] ][ $parts[2] ];
		}
		$stamp = gmdate( 'YmdH', $hour );
		$lines = '';
		foreach ( $counts as $class => $per_hash ) {
			foreach ( $per_hash as $hash => $count ) {
				$lines .= $stamp . "\t" . $class . "\t" . $hash . "\t" . $count . "\n";
			}
		}
		if ( '' !== $lines ) {
			$this->append( 'uniq-' . gmdate( 'Ymd', $hour ) . '.php', $lines );
			$this->flush();
		}
	}

	/**
	 * Unique metrics of a day per client class (from the hours rolled in), without deleting the file.
	 *
	 * @param string $ymd Day (Ymd, UTC).
	 * @return array<string, array{unique_ips:int, ips_seen_once:int}> Keyed by class, sorted.
	 */
	public function dayStats( string $ymd ): array {
		$per_hour = array();
		foreach ( $this->lines( 'uniq-' . self::ymd( $ymd ) . '.php' ) as $parts ) {
			if ( 4 !== count( $parts ) || 1 !== preg_match( '/^\d{10}$/', $parts[0] ) || ! ctype_digit( $parts[3] ) ) {
				continue;
			}
			$key              = $parts[0] . "\t" . $parts[1] . "\t" . $parts[2];
			$per_hour[ $key ] = max( $per_hour[ $key ] ?? 0, (int) $parts[3] );
		}
		$counts = array();
		foreach ( $per_hour as $key => $count ) {
			list( , $class, $hash )    = explode( "\t", (string) $key );
			$counts[ $class ][ $hash ] = ( $counts[ $class ][ $hash ] ?? 0 ) + $count;
		}
		ksort( $counts, SORT_STRING );
		$out = array();
		foreach ( $counts as $class => $per_hash ) {
			$out[ (string) $class ] = array(
				'unique_ips'    => count( $per_hash ),
				'ips_seen_once' => self::once( $per_hash ),
			);
		}
		return $out;
	}

	/**
	 * Delete an hour's file.
	 *
	 * @param int $hour Hour start (UTC).
	 */
	public function dropHour( int $hour ): void {
		$this->flush();
		@unlink( $this->dir . '/' . self::hourFile( $hour ) );
	}

	/**
	 * Delete a day's file.
	 *
	 * @param string $ymd Day (Ymd, UTC).
	 */
	public function dropDay( string $ymd ): void {
		$this->flush();
		@unlink( $this->dir . '/uniq-' . self::ymd( $ymd ) . '.php' );
	}

	/**
	 * Unique metrics of an hour; then roll it into its day and delete its file.
	 *
	 * @param int $hour Hour start (UTC).
	 * @return array<string, array{unique_ips:int, ips_seen_once:int, top_ip_share:int}>
	 */
	public function closeHour( int $hour ): array {
		$out = $this->hourStats( $hour );
		$this->rollIntoDay( $hour );
		$this->dropHour( $hour );
		return $out;
	}

	/**
	 * Unique metrics of a day, then delete its file.
	 *
	 * @param string $ymd Day (Ymd, UTC).
	 * @return array<string, array{unique_ips:int, ips_seen_once:int}>
	 */
	public function closeDay( string $ymd ): array {
		$out = $this->dayStats( $ymd );
		$this->dropDay( $ymd );
		return $out;
	}

	/**
	 * Valid lines of an hour file: `[hex dims, class, hash]`.
	 *
	 * @param int $hour Hour start (UTC).
	 * @return \Generator<int, string[]>
	 */
	private function hourLines( int $hour ): \Generator {
		foreach ( $this->lines( self::hourFile( $hour ) ) as $parts ) {
			if ( 3 === count( $parts ) && '' !== $parts[0] && 0 === strlen( $parts[0] ) % 2 && ctype_xdigit( $parts[0] ) ) {
				yield $parts;
			}
		}
	}

	/**
	 * Queue lines for a file.
	 *
	 * @param string $name  File name.
	 * @param string $lines Lines.
	 */
	private function append( string $name, string $lines ): void {
		$this->pending[ $name ] = ( $this->pending[ $name ] ?? '' ) . $lines;
	}

	/**
	 * Tab-split lines of a tracker file, without the header.
	 *
	 * @param string $name File name.
	 * @return \Generator<int, string[]>
	 */
	private function lines( string $name ): \Generator {
		$this->flush();
		$handle = @fopen( $this->dir . '/' . $name, 'rb' );
		if ( false === $handle ) {
			return;
		}
		try {
			while ( true ) {
				$line = fgets( $handle );
				if ( false === $line ) {
					break;
				}
				$line = rtrim( $line, "\r\n" );
				if ( '' !== $line && 0 !== strpos( $line, '<?php' ) ) {
					yield explode( "\t", $line );
				}
			}
		} finally {
			fclose( $handle );
		}
	}

	/**
	 * First capture group of every tracker file name matching a pattern.
	 *
	 * @param string $pattern Regex on the base name.
	 * @return string[]
	 */
	private function names( string $pattern ): array {
		$this->flush();
		$files = @glob( $this->dir . '/uniq-*.php' );
		$out   = array();
		foreach ( is_array( $files ) ? $files : array() as $file ) {
			if ( 1 === preg_match( $pattern, basename( $file ), $m ) ) {
				$out[] = $m[1];
			}
		}
		return $out;
	}

	/**
	 * Hour file name.
	 *
	 * @param int $hour Hour start (UTC).
	 */
	private static function hourFile( int $hour ): string {
		return 'uniq-' . gmdate( 'YmdH', $hour ) . '.php';
	}

	/**
	 * Number of hashes seen exactly once.
	 *
	 * @param array<int|string, int> $per_hash Requests per hash.
	 */
	private static function once( array $per_hash ): int {
		$n = 0;
		foreach ( $per_hash as $count ) {
			$n += 1 === $count ? 1 : 0;
		}
		return $n;
	}

	/**
	 * Strip separators from a field.
	 *
	 * @param string $value Field.
	 */
	private static function clean( string $value ): string {
		return str_replace( array( "\t", "\n", "\r" ), '', $value );
	}

	/**
	 * Validate a day string.
	 *
	 * @param string $ymd Day.
	 * @throws \InvalidArgumentException When malformed.
	 */
	private static function ymd( string $ymd ): string {
		if ( 1 !== preg_match( '/^\d{8}$/', $ymd ) ) {
			throw new \InvalidArgumentException( 'Day must be in Ymd format.' );
		}
		return $ymd;
	}
}
