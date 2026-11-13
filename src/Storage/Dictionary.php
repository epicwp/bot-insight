<?php
/**
 * Dictionary of repeated strings, stored once and referenced by id.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Storage;

/**
 * Note: values are compared under the table collation (utf8mb4_unicode_ci by default), which is
 * case- and accent-insensitive. Values here are already normalised, so this is accepted; the
 * in-process cache is keyed by the exact string.
 */
final class Dictionary {

	private const TYPES   = array( 'bot', 'ua_family', 'param_sig', 'url_pattern', 'asn_org' );
	private const MAX_LEN = 191;
	private const CHUNK   = 500;

	/**
	 * Database handle.
	 *
	 * @var \wpdb
	 */
	private $wpdb;

	/**
	 * Full table name.
	 *
	 * @var string
	 */
	private $table;

	/**
	 * Cache of ids by type and exact value.
	 *
	 * @var array<string,array<string,int>>
	 */
	private $cache = array();

	/**
	 * Create the dictionary.
	 *
	 * @param \wpdb $wpdb Database handle.
	 */
	public function __construct( \wpdb $wpdb ) {
		$this->wpdb  = $wpdb;
		$this->table = Schema::tables()['dict'];
	}

	/**
	 * Id for a value, created when missing.
	 *
	 * @param string $type  One of bot, ua_family, param_sig, url_pattern, asn_org.
	 * @param string $value Value, cut to 191 characters.
	 * @throws \InvalidArgumentException On an unknown type.
	 */
	public function id( string $type, string $value ): int {
		$this->assertType( $type );
		$value = $this->truncate( $value );
		if ( isset( $this->cache[ $type ][ $value ] ) ) {
			return $this->cache[ $type ][ $value ];
		}
		$table = $this->table;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$this->wpdb->query(
			$this->wpdb->prepare( "INSERT IGNORE INTO {$table} (type, value) VALUES (%s, %s)", $type, $value )
		);
		$id = (int) $this->wpdb->get_var(
			$this->wpdb->prepare( "SELECT id FROM {$table} WHERE type = %s AND value = %s", $type, $value )
		);
		// phpcs:enable
		$this->cache[ $type ][ $value ] = $id;
		return $id;
	}

	/**
	 * Values for ids of one type. Unknown ids are left out.
	 *
	 * @param string $type One of the dictionary types.
	 * @param int[]  $ids  Ids to resolve.
	 * @return array<int,string>
	 * @throws \InvalidArgumentException On an unknown type.
	 */
	public function values( string $type, array $ids ): array {
		$this->assertType( $type );
		$ids   = array_values( array_unique( array_map( 'intval', $ids ) ) );
		$out   = array();
		$table = $this->table;
		foreach ( array_chunk( $ids, self::CHUNK ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$rows = $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT id, value FROM {$table} WHERE type = %s AND id IN ({$placeholders})",
					array_merge( array( $type ), $chunk )
				),
				ARRAY_A
			);
			// phpcs:enable
			foreach ( (array) $rows as $row ) {
				$out[ (int) $row['id'] ] = (string) $row['value'];
			}
		}
		return $out;
	}

	/**
	 * Reject unknown types.
	 *
	 * @param string $type Type to check.
	 * @throws \InvalidArgumentException On an unknown type.
	 */
	private function assertType( string $type ): void {
		if ( ! in_array( $type, self::TYPES, true ) ) {
			throw new \InvalidArgumentException( 'Unknown dictionary type.' );
		}
	}

	/**
	 * Cut to the column length.
	 *
	 * @param string $value Value.
	 */
	private function truncate( string $value ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, self::MAX_LEN, 'UTF-8' );
		}
		return substr( $value, 0, self::MAX_LEN );
	}
}
