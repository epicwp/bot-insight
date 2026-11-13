<?php
/**
 * CIDR range matching against bundled vendor IP lists.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Net;

/**
 * Answers "is this IP inside the published ranges of list X".
 *
 * Each list is a PHP file returning an array of CIDR strings, so opcache serves it. Lists are
 * loaded lazily and parsed once per process into packed networks (static cache keyed by file),
 * so a request that builds a new instance pays no `inet_pton` per range. `resetCache()` forgets
 * the parsed lists after a refresh in the same process.
 *
 * An optional override directory (refreshed lists in the private uploads dir) wins per list when
 * its `_meta.php` date is newer than the bundled one and the list was fetched in that run.
 */
final class IpRanges {

	/**
	 * Directory holding the range files.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Override directory, or null.
	 *
	 * @var string|null
	 */
	private $override;

	/**
	 * Lists to read from the override directory, once worked out.
	 *
	 * @var array<string, bool>|null
	 */
	private $from_override = null;

	/**
	 * Loaded lists of this instance, keyed by list name: packed networks with their prefix length,
	 * grouped by address length (4 or 16).
	 *
	 * @var array<string, array<int, list<array{0: string, 1: int}>>>
	 */
	private $loaded = array();

	/**
	 * Parsed lists of this process, keyed by file path.
	 *
	 * @var array<string, array<int, list<array{0: string, 1: int}>>>
	 */
	private static $parsed = array();

	/**
	 * Override decisions of this process, keyed by bundled and override directory.
	 *
	 * @var array<string, array<string, bool>>
	 */
	private static $overrides = array();

	/**
	 * Constructor.
	 *
	 * @param string      $dir         Directory with one `<list>.php` file per list.
	 * @param string|null $override_dir Directory with refreshed lists, or null.
	 */
	public function __construct( string $dir, ?string $override_dir = null ) {
		$this->dir      = rtrim( $dir, '/\\' );
		$this->override = null === $override_dir ? null : rtrim( $override_dir, '/\\' );
	}

	/**
	 * Fetch date (`Y-m-d`) in a directory's `_meta.php`, or '' when missing or invalid.
	 *
	 * @param string $dir Range directory.
	 */
	public static function fetched( string $dir ): string {
		return (string) self::meta( $dir )['fetched'];
	}

	/**
	 * Whether the IP falls inside any range of the list.
	 *
	 * @param string $ip   IPv4 or IPv6 address.
	 * @param string $name List name, e.g. `google`.
	 */
	public function contains( string $ip, string $name ): bool {
		$bin = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid input is an expected, handled case.
		if ( false === $bin ) {
			return false;
		}
		// Treat IPv4-mapped IPv6 (::ffff:a.b.c.d) as plain IPv4.
		if ( 16 === strlen( $bin ) && "\0\0\0\0\0\0\0\0\0\0\xff\xff" === substr( $bin, 0, 12 ) ) {
			$bin = substr( $bin, 12 );
		}
		$ranges = $this->load( $name )[ strlen( $bin ) ] ?? array();
		foreach ( $ranges as $range ) {
			if ( $this->prefix_matches( $bin, $range[0], $range[1] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Forget the parsed lists and override decisions of this process (after a refresh).
	 */
	public static function resetCache(): void {
		self::$parsed    = array();
		self::$overrides = array();
	}

	/**
	 * Names of the available lists, sorted.
	 *
	 * @return string[]
	 */
	public function lists(): array {
		$files = glob( $this->dir . '/*.php' );
		$names = array();
		foreach ( false === $files ? array() : $files as $file ) {
			$name = basename( $file, '.php' );
			if ( '_' !== $name[0] ) {
				$names[ $name ] = true;
			}
		}
		foreach ( array_keys( $this->overrideLists() ) as $name ) {
			if ( is_file( $this->override . '/' . $name . '.php' ) ) {
				$names[ $name ] = true;
			}
		}
		$names = array_keys( $names );
		sort( $names );
		return $names;
	}

	/**
	 * Compares the first $bits bits of two packed addresses.
	 *
	 * @param string $a    Packed address.
	 * @param string $b    Packed network address, same length as $a.
	 * @param int    $bits Prefix length.
	 */
	private function prefix_matches( string $a, string $b, int $bits ): bool {
		$bytes = intdiv( $bits, 8 );
		if ( 0 !== $bytes && substr( $a, 0, $bytes ) !== substr( $b, 0, $bytes ) ) {
			return false;
		}
		$rest = $bits % 8;
		if ( 0 === $rest ) {
			return true;
		}
		$mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;
		return ( ord( $a[ $bytes ] ) & $mask ) === ( ord( $b[ $bytes ] ) & $mask );
	}

	/**
	 * Loads a list on first use.
	 *
	 * @param string $name List name.
	 * @return array<int, list<array{0: string, 1: int}>>
	 */
	private function load( string $name ): array {
		if ( isset( $this->loaded[ $name ] ) ) {
			return $this->loaded[ $name ];
		}
		$ranges = array();
		// Only plain list names: no path traversal, no `_meta`.
		if ( 1 === preg_match( '/^[a-z0-9][a-z0-9-]*$/', $name ) ) {
			$file = $this->dir . '/' . $name . '.php';
			if ( isset( $this->overrideLists()[ $name ] ) && is_file( $this->override . '/' . $name . '.php' ) ) {
				$file = $this->override . '/' . $name . '.php';
			}
			if ( isset( self::$parsed[ $file ] ) ) {
				$ranges = self::$parsed[ $file ];
			} elseif ( is_file( $file ) ) {
				$data                  = include $file;
				$ranges                = self::parse( is_array( $data ) ? $data : array() );
				self::$parsed[ $file ] = $ranges;
			}
		}
		$this->loaded[ $name ] = $ranges;
		return $ranges;
	}

	/**
	 * Pack CIDR strings into networks and prefix lengths, grouped by address length. Malformed
	 * entries are skipped.
	 *
	 * @param array<mixed> $cidrs CIDR strings.
	 * @return array<int, list<array{0: string, 1: int}>>
	 */
	private static function parse( array $cidrs ): array {
		$out = array();
		foreach ( $cidrs as $cidr ) {
			if ( ! is_string( $cidr ) ) {
				continue;
			}
			$slash = strpos( $cidr, '/' );
			if ( false === $slash ) {
				continue;
			}
			$net = @inet_pton( substr( $cidr, 0, $slash ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Bundled data, but never trust it to be clean.
			if ( false === $net ) {
				continue;
			}
			$length = strlen( $net );
			$prefix = substr( $cidr, $slash + 1 );
			if ( '' === $prefix || ! ctype_digit( $prefix ) || (int) $prefix > $length * 8 ) {
				continue;
			}
			$out[ $length ][] = array( $net, (int) $prefix );
		}
		return $out;
	}

	/**
	 * Lists the override directory supplies: those fetched in its last run, when that run is newer
	 * than the bundled lists. Worked out once per process; empty without an override directory.
	 *
	 * @return array<string, bool>
	 */
	private function overrideLists(): array {
		if ( null === $this->from_override ) {
			$this->from_override = array();
			if ( null !== $this->override ) {
				$key = $this->dir . "\0" . $this->override;
				if ( ! isset( self::$overrides[ $key ] ) ) {
					self::$overrides[ $key ] = array();
					$over                    = self::meta( $this->override );
					if ( '' !== $over['fetched'] && strcmp( $over['fetched'], self::meta( $this->dir )['fetched'] ) > 0 ) {
						foreach ( $over['ok'] as $name ) {
							self::$overrides[ $key ][ $name ] = true;
						}
					}
				}
				$this->from_override = self::$overrides[ $key ];
			}
		}
		return $this->from_override;
	}

	/**
	 * Read a directory's `_meta.php`.
	 *
	 * @param string $dir Range directory.
	 * @return array{fetched: string, ok: string[]}
	 */
	private static function meta( string $dir ): array {
		$out  = array(
			'fetched' => '',
			'ok'      => array(),
		);
		$file = rtrim( $dir, '/\\' ) . '/_meta.php';
		if ( ! is_file( $file ) ) {
			return $out;
		}
		$data = include $file;
		if ( ! is_array( $data ) || ! isset( $data['fetched'] ) || ! is_string( $data['fetched'] ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $data['fetched'] ) ) {
			return $out;
		}
		$out['fetched'] = $data['fetched'];
		foreach ( is_array( $data['ok'] ?? null ) ? array_keys( $data['ok'] ) : array() as $name ) {
			if ( is_string( $name ) && 1 === preg_match( '/^[a-z0-9][a-z0-9-]*$/', $name ) ) {
				$out['ok'][] = $name;
			}
		}
		return $out;
	}
}
