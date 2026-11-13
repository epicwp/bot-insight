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
 */
final class IpRanges {

	/**
	 * Directory holding the range files.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Loaded lists, keyed by list name.
	 *
	 * @var array<string,string[]>
	 */
	private $loaded = array();

	/**
	 * Constructor.
	 *
	 * @param string $dir Directory with one `<list>.php` file per list.
	 */
	public function __construct( string $dir ) {
		$this->dir = rtrim( $dir, '/\\' );
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
			$bits = (int) substr( $cidr, $slash + 1 );
			if ( $bits < 0 || $bits > $length * 8 ) {
				continue;
			}
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
				$names[] = $name;
			}
		}
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
			if ( is_file( $file ) ) {
				$data   = include $file;
				$ranges = is_array( $data ) ? array_values( array_filter( $data, 'is_string' ) ) : array();
			}
		}
		$this->loaded[ $name ] = $ranges;
		return $ranges;
	}
}
