<?php
/**
 * Hourly aggregator with a cardinality guard.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Fold;

/**
 * Turns enriched rows into hourly aggregate rows and bounds the number of rows per hour.
 */
final class Aggregator {

	/**
	 * Maximum distinct dimension combinations per hour (before overflow rows).
	 */
	public const MAX_PER_HOUR = 200;

	/**
	 * Dimension columns, in the order they are hashed into `dims`.
	 */
	public const DIMENSIONS = array(
		'client_class',
		'bot_id',
		'route',
		'param_sig_id',
		'lang',
		'country',
		'asn_type',
		'via_cdn',
		'verified',
		'js_seen',
		'status_class',
		'uncached_reason',
	);

	/**
	 * Raw 16-byte key for a dimension tuple.
	 *
	 * @param array<string, mixed> $dimension_values Dimension values keyed by column name.
	 * @return string
	 */
	public static function dimsKey( array $dimension_values ): string {
		$parts = array();
		foreach ( self::DIMENSIONS as $name ) {
			$parts[] = (string) ( $dimension_values[ $name ] ?? '' );
		}
		return md5( implode( "\x1f", $parts ), true );
	}

	/**
	 * Aggregate enriched rows into hourly rows.
	 *
	 * @param array<array<string, mixed>>|\Traversable<int, array<string, mixed>> $rows           Enriched rows.
	 * @param callable                                                            $dict_id        Resolver: ( string $type, string $value ): int.
	 * @param array<int, array{count: int, keys: array<string, bool>}>            $existing_slots Rows already in the table per hour.
	 * @return array{rows: list<array<string, mixed>>, dims: list<string>} Hourly rows, and for each
	 *         input row the final dims key it was counted under.
	 */
	public static function aggregate( iterable $rows, callable $dict_id, array $existing_slots ): array {
		$groups = array();
		$inputs = array();

		foreach ( $rows as $row ) {
			$hour = (int) $row['hour'];
			$dim  = array(
				'client_class'    => (string) $row['class'],
				'bot_id'          => '' === (string) $row['bot'] ? 0 : (int) $dict_id( 'bot', (string) $row['bot'] ),
				'route'           => (string) $row['r'],
				'param_sig_id'    => '' === (string) $row['p'] ? 0 : (int) $dict_id( 'param_sig', (string) $row['p'] ),
				'lang'            => (string) $row['lg'],
				'country'         => (string) $row['country'],
				'asn_type'        => (string) $row['asn_type'],
				'via_cdn'         => (int) $row['cdn'],
				'verified'        => (int) $row['verified'],
				'js_seen'         => (int) $row['js'],
				'status_class'    => (string) $row['status_class'],
				'uncached_reason' => (string) $row['unc'],
			);
			$key  = self::dimsKey( $dim );
			if ( ! isset( $groups[ $hour ][ $key ] ) ) {
				$groups[ $hour ][ $key ] = self::blank( $dim );
			}
			$ms = (float) $row['ms'];
			$a  = &$groups[ $hour ][ $key ];
			++$a['requests'];
			$a['ms']   += $ms;
			$a['cpu']  += (float) $row['cpu'];
			$a['dbq']  += (int) $row['dbq'];
			$a['mem']   = max( $a['mem'], (int) $row['mem'] );
			$a['slow'] += $ms >= 2000 ? 1 : 0;
			$a['se']   += (int) $row['se'];
			$a['ca']   += (int) $row['ca'];
			$a['or']   += (int) $row['or'];
			unset( $a );
			$inputs[] = array( $hour, $key );
		}

		// Decide per hour which new combos keep their own row; map the others to overflow.
		$remap = array();
		$final = array();
		foreach ( $groups as $hour => $combos ) {
			$existing = $existing_slots[ $hour ]['keys'] ?? array();
			$free     = max( 0, self::MAX_PER_HOUR - (int) ( $existing_slots[ $hour ]['count'] ?? 0 ) );

			$fresh = array();
			foreach ( $combos as $key => $acc ) {
				if ( isset( $existing[ $key ] ) ) {
					$final[ $hour ][ $key ] = $acc;
				} else {
					$fresh[ $key ] = $acc['ms'];
				}
			}
			$keys = array_keys( $fresh );
			usort(
				$keys,
				static function ( $a, $b ) use ( $fresh ) {
					$cmp = round( $fresh[ $b ] ) <=> round( $fresh[ $a ] );
					return 0 !== $cmp ? $cmp : strcmp( (string) $a, (string) $b );
				}
			);
			foreach ( $keys as $i => $key ) {
				$key = (string) $key;
				if ( $i < $free ) {
					$final[ $hour ][ $key ] = $combos[ $key ];
					continue;
				}
				$over     = self::overflowDims( $combos[ $key ]['dim'] );
				$over_key = self::dimsKey( $over );
				if ( ! isset( $final[ $hour ][ $over_key ] ) ) {
					$final[ $hour ][ $over_key ] = self::blank( $over );
				}
				self::merge( $final[ $hour ][ $over_key ], $combos[ $key ] );
				$remap[ $hour ][ $key ] = $over_key;
			}
		}

		$out = array();
		foreach ( $final as $hour => $combos ) {
			foreach ( $combos as $key => $acc ) {
				$out[] = self::toRow( $hour, (string) $key, $acc );
			}
		}

		$dims = array();
		foreach ( $inputs as $in ) {
			$dims[] = $remap[ $in[0] ][ $in[1] ] ?? $in[1];
		}

		return array(
			'rows' => $out,
			'dims' => $dims,
		);
	}

	/**
	 * Empty accumulator for a dimension tuple.
	 *
	 * @param array<string, mixed> $dim Dimension values.
	 * @return array<string, mixed>
	 */
	private static function blank( array $dim ): array {
		return array(
			'dim'      => $dim,
			'requests' => 0,
			'ms'       => 0.0,
			'cpu'      => 0.0,
			'dbq'      => 0,
			'mem'      => 0,
			'slow'     => 0,
			'se'       => 0,
			'ca'       => 0,
			'or'       => 0,
		);
	}

	/**
	 * Add accumulator $from into $into.
	 *
	 * @param array<string, mixed> $into Target accumulator.
	 * @param array<string, mixed> $from Source accumulator.
	 */
	private static function merge( array &$into, array $from ): void {
		foreach ( array( 'requests', 'ms', 'cpu', 'dbq', 'slow', 'se', 'ca', 'or' ) as $f ) {
			$into[ $f ] += $from[ $f ];
		}
		$into['mem'] = max( $into['mem'], $from['mem'] );
	}

	/**
	 * Dimensions of the overflow combo for a dropped combo.
	 *
	 * @param array<string, mixed> $dim Original dimension values.
	 * @return array<string, mixed>
	 */
	private static function overflowDims( array $dim ): array {
		return array(
			'client_class'    => $dim['client_class'],
			'bot_id'          => 0,
			'route'           => 'other',
			'param_sig_id'    => 0,
			'lang'            => '',
			'country'         => '??',
			'asn_type'        => 'unknown',
			'via_cdn'         => $dim['via_cdn'],
			'verified'        => $dim['verified'],
			'js_seen'         => 0,
			'status_class'    => 'xxx',
			'uncached_reason' => 'other',
		);
	}

	/**
	 * Build the hourly table row.
	 *
	 * @param int                  $hour Hour start.
	 * @param string               $key  Binary dims key.
	 * @param array<string, mixed> $acc  Accumulator.
	 * @return array<string, mixed>
	 */
	private static function toRow( int $hour, string $key, array $acc ): array {
		return array_merge(
			array(
				'hour' => $hour,
				'dims' => $key,
			),
			$acc['dim'],
			array(
				'requests'         => $acc['requests'],
				'php_ms_sum'       => (int) round( $acc['ms'] ),
				'cpu_ms_sum'       => (int) round( $acc['cpu'] ),
				'db_queries_sum'   => $acc['dbq'],
				'mem_peak_max'     => $acc['mem'],
				'slow_2s'          => $acc['slow'],
				'unique_ips'       => 0,
				'ips_seen_once'    => 0,
				'top_ip_share'     => 0,
				'sessions_created' => $acc['se'],
				'cart_changes'     => $acc['ca'],
				'orders'           => $acc['or'],
			)
		);
	}
}
