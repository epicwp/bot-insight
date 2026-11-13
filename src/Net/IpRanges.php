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
 * Each list is a PHP file returning an array of CIDR strings, so opcache serves it and no
 * parsing happens at runtime. Lists are loaded lazily, once per list per request.
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
	 * Loaded lists, keyed by list name.
	 *
	 * @var array<string,string[]>
	 */
	private $loaded = array();

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
		$ranges = $this->load( $name );
		if ( ! $ranges ) {
			return false;
		}
		$length = strlen( $bin );
		foreach ( $ranges as $cidr ) {
			$slash = strpos( $cidr, '/' );
			if ( false === $slash ) {
				continue;
			}
			// Cheap family check before decoding the range.
			if ( ( false !== strpos( $cidr, ':' ) ) !== ( 16 === $length ) ) {
				continue;
			}
			$net = @inet_pton( substr( $cidr, 0, $slash ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Bundled data, but never trust it to be clean.
			if ( false === $net || strlen( $net ) !== $length ) {
				continue;
			}
			$prefix = substr( $cidr, $slash + 1 );
			if ( '' === $prefix || ! ctype_digit( $prefix ) || (int) $prefix > $length * 8 ) {
				continue;
			}
			$bits = (int) $prefix;
			if ( $this->prefix_matches( $bin, $net, $bits ) ) {
				return true;
			}
		}
		return false;
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
	 * @return string[]
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
			if ( is_file( $file ) ) {
				$data   = include $file;
				$ranges = is_array( $data ) ? array_values( array_filter( $data, 'is_string' ) ) : array();
			}
		}
		$this->loaded[ $name ] = $ranges;
		return $ranges;
	}

	/**
	 * Lists the override directory supplies: those fetched in its last run, when that run is newer
	 * than the bundled lists. Worked out once per instance; empty without an override directory.
	 *
	 * @return array<string, bool>
	 */
	private function overrideLists(): array {
		if ( null === $this->from_override ) {
			$this->from_override = array();
			if ( null !== $this->override ) {
				$over = self::meta( $this->override );
				if ( '' !== $over['fetched'] && strcmp( $over['fetched'], self::meta( $this->dir )['fetched'] ) > 0 ) {
					foreach ( $over['ok'] as $name ) {
						$this->from_override[ $name ] = true;
					}
				}
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
