<?php
/**
 * Client IP, source and CDN path resolution.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Net;

/**
 * Works out the visitor's IP from server variables without trusting forgeable headers.
 *
 * CF-Connecting-IP is only honoured when the connecting hop is inside Cloudflare's published ranges.
 */
final class ClientIp {

	/**
	 * Maximum number of X-Forwarded-For entries inspected (the last ones).
	 */
	private const MAX_XFF_ENTRIES = 20;

	/**
	 * Range matcher.
	 *
	 * @var IpRanges
	 */
	private $ranges;

	/**
	 * Constructor.
	 *
	 * @param IpRanges $ranges Range matcher holding the `cloudflare` list.
	 */
	public function __construct( IpRanges $ranges ) {
		$this->ranges = $ranges;
	}

	/**
	 * Resolve the client IP.
	 *
	 * @param array<string,mixed> $server Server variables ($_SERVER style keys).
	 * @return array{ip:string,source:string,via_cdn:int}
	 */
	public function resolve( array $server ): array {
		$remote = $this->header( $server, 'REMOTE_ADDR' );
		if ( '' === $remote || false === filter_var( $remote, FILTER_VALIDATE_IP ) ) {
			return array(
				'ip'      => '',
				'source'  => 'remote_addr',
				'via_cdn' => 0,
			);
		}

		$fallback    = array(
			'ip'      => $remote,
			'source'  => 'remote_addr',
			'via_cdn' => 0,
		);
		$was_private = self::is_private( $remote );
		$xff_hop     = '';
		$proxy_ip    = $remote;

		if ( $was_private ) {
			$xff_hop = $this->rightmost_public( $this->header( $server, 'HTTP_X_FORWARDED_FOR' ) );
			if ( '' !== $xff_hop ) {
				$proxy_ip = $xff_hop;
			}
		}

		if ( $this->ranges->contains( $proxy_ip, 'cloudflare' ) ) {
			$cf = $this->header( $server, 'HTTP_CF_CONNECTING_IP' );
			if ( '' !== $cf && false !== filter_var( $cf, FILTER_VALIDATE_IP ) ) {
				return array(
					'ip'      => $cf,
					'source'  => 'cf_connecting_ip',
					'via_cdn' => 1,
				);
			}
			return $fallback;
		}

		if ( '' !== $xff_hop ) {
			$header_cdn = '' !== $this->header( $server, 'HTTP_FASTLY_CLIENT_IP' )
				|| '' !== $this->header( $server, 'HTTP_X_SUCURI_CLIENTIP' );
			return array(
				'ip'      => $xff_hop,
				'source'  => 'x_forwarded_for',
				'via_cdn' => $header_cdn ? 1 : 0,
			);
		}

		return $fallback;
	}

	/**
	 * Zero the host part: /24 for IPv4, /48 for IPv6. Invalid input gives ''.
	 *
	 * @param string $ip IP address.
	 * @return string
	 */
	public static function prefix( string $ip ): string {
		$packed = inet_pton( $ip );
		if ( false === $packed ) {
			return '';
		}
		if ( 4 === strlen( $packed ) ) {
			return (string) inet_ntop( substr( $packed, 0, 3 ) . "\0" );
		}
		return (string) inet_ntop( substr( $packed, 0, 6 ) . str_repeat( "\0", 10 ) );
	}

	/**
	 * Whether an IP is private, loopback or link-local.
	 *
	 * @param string $ip Valid IP address.
	 * @return bool
	 */
	private static function is_private( string $ip ): bool {
		$packed = inet_pton( $ip );
		if ( false === $packed ) {
			return false;
		}
		if ( 16 === strlen( $packed ) ) {
			// IPv4-mapped IPv6: judge the embedded IPv4 address.
			if ( str_repeat( "\0", 10 ) . "\xff\xff" === substr( $packed, 0, 12 ) ) {
				$packed = substr( $packed, 12 );
			} else {
				$first  = ord( $packed[0] );
				$second = ord( $packed[1] );
				if ( "\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\x01" === $packed ) {
					return true; // ::1.
				}
				return 0xfc === ( $first & 0xfe ) || ( 0xfe === $first && 0x80 === ( $second & 0xc0 ) );
			}
		}
		$a = ord( $packed[0] );
		$b = ord( $packed[1] );
		return 10 === $a
			|| 127 === $a
			|| ( 172 === $a && $b >= 16 && $b <= 31 )
			|| ( 192 === $a && 168 === $b )
			|| ( 169 === $a && 254 === $b );
	}

	/**
	 * Rightmost valid, non-private entry of an X-Forwarded-For value.
	 *
	 * @param string $value Header value.
	 * @return string IP or ''.
	 */
	private function rightmost_public( string $value ): string {
		if ( '' === $value ) {
			return '';
		}
		$parts = explode( ',', $value );
		$parts = array_slice( $parts, -self::MAX_XFF_ENTRIES );
		for ( $i = count( $parts ) - 1; $i >= 0; $i-- ) {
			$entry = trim( $parts[ $i ] );
			if ( '' === $entry || false === filter_var( $entry, FILTER_VALIDATE_IP ) ) {
				continue;
			}
			if ( ! self::is_private( $entry ) ) {
				return $entry;
			}
		}
		return '';
	}

	/**
	 * Read a string header value, tolerating missing or non-string values.
	 *
	 * @param array<string,mixed> $server Server variables.
	 * @param string              $key    Key.
	 * @return string
	 */
	private function header( array $server, string $key ): string {
		return isset( $server[ $key ] ) && is_string( $server[ $key ] ) ? trim( $server[ $key ] ) : '';
	}
}
