<?php
/**
 * Buffer record codec.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Capture;

/**
 * Encodes one request as a single JSON line with short keys, and decodes it back.
 *
 * Free of WordPress so the hot path and the fold can share it.
 */
final class Record {

	/**
	 * Record keys in their fixed order, with the type of each value.
	 */
	public const TYPES = array(
		'ts'   => 'int',
		'ms'   => 'float',
		'cpu'  => 'float',
		'mem'  => 'int',
		'dbq'  => 'int',
		'st'   => 'int',
		'unc'  => 'string',
		'm'    => 'string',
		'r'    => 'string',
		'p'    => 'string',
		'dp'   => 'string',
		'lg'   => 'string',
		'u'    => 'string',
		'ref'  => 'string',
		'ua'   => 'string',
		'ip'   => 'string',
		'ips'  => 'string',
		'cdn'  => 'int',
		'cc'   => 'string',
		'hf'   => 'int',
		'js'   => 'int',
		'se'   => 'int',
		'ca'   => 'int',
		'or'   => 'int',
		'pc'   => 'int',
		'self' => 'int',
	);

	/**
	 * Record keys in their fixed order.
	 */
	public const KEYS = array( 'ts', 'ms', 'cpu', 'mem', 'dbq', 'st', 'unc', 'm', 'r', 'p', 'dp', 'lg', 'u', 'ref', 'ua', 'ip', 'ips', 'cdn', 'cc', 'hf', 'js', 'se', 'ca', 'or', 'pc', 'self' );

	/**
	 * Longest user agent kept, in bytes.
	 */
	public const MAX_UA = 256;

	/**
	 * Longest line decode() accepts, in bytes.
	 */
	private const MAX_LINE = 8192;

	/**
	 * Encode a record as one `\n`-terminated JSON line.
	 *
	 * Keys outside the record definition are dropped; missing keys get a typed zero value.
	 *
	 * @param array<string, mixed> $r Record values.
	 * @return string
	 */
	public static function encode( array $r ): string {
		if ( isset( $r['ua'] ) && is_string( $r['ua'] ) && strlen( $r['ua'] ) > self::MAX_UA ) {
			$r['ua'] = substr( $r['ua'], 0, self::MAX_UA );
		}
		$json = json_encode( self::normalise( $r ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- No WordPress in this class; flags are deliberate.
		return ( false === $json ? '{}' : $json ) . "\n";
	}

	/**
	 * Decode one line. Header lines, broken JSON and records without an integer `ts` give null.
	 *
	 * @param string $line One line, with or without the trailing newline.
	 * @return array<string, mixed>|null
	 */
	public static function decode( string $line ): ?array {
		if ( '' === $line || '{' !== $line[0] || strlen( $line ) > self::MAX_LINE ) {
			return null;
		}
		$data = json_decode( $line, true );
		if ( ! is_array( $data ) || ! isset( $data['ts'] ) || ! is_int( $data['ts'] ) ) {
			return null;
		}
		return self::normalise( $data );
	}

	/**
	 * Keep exactly the record keys, in order, cast to their types.
	 *
	 * @param array<string, mixed> $r Raw values.
	 * @return array<string, mixed>
	 */
	private static function normalise( array $r ): array {
		$out = array();
		foreach ( self::TYPES as $key => $type ) {
			$value = $r[ $key ] ?? null;
			if ( 'int' === $type ) {
				$out[ $key ] = is_numeric( $value ) || is_bool( $value ) ? (int) $value : 0;
			} elseif ( 'float' === $type ) {
				$out[ $key ] = is_numeric( $value ) ? (float) $value : 0.0;
			} else {
				$out[ $key ] = is_scalar( $value ) ? (string) $value : '';
			}
		}
		return $out;
	}
}
