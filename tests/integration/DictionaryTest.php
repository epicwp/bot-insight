<?php
/**
 * Dictionary against a real database.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Tests\Integration;

use BotInsight\Storage\Dictionary;
use BotInsight\Storage\Schema;

final class DictionaryTest extends \WP_UnitTestCase {

	/**
	 * Create the dictionary table (temporary tables are used by the harness).
	 */
	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$table = Schema::tables()['dict'];
		// Real table so INSERT IGNORE and unique keys behave exactly as in production.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"CREATE TABLE {$table} (
  id int(10) unsigned NOT NULL AUTO_INCREMENT,
  type varchar(12) NOT NULL,
  value varchar(191) NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY type_value (type,value)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
		);
	}

	public function tear_down(): void {
		parent::tear_down();
		global $wpdb;
		$table = Schema::tables()['dict'];
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( 'COMMIT' );
	}

	private function dict(): Dictionary {
		global $wpdb;
		return new Dictionary( $wpdb );
	}

	public function test_same_value_twice_gives_same_id(): void {
		$d = $this->dict();
		$a = $d->id( 'bot', 'googlebot' );
		$this->assertGreaterThan( 0, $a );
		$this->assertSame( $a, $d->id( 'bot', 'googlebot' ) );
		// A fresh instance (empty cache) finds the same row.
		$this->assertSame( $a, $this->dict()->id( 'bot', 'googlebot' ) );
	}

	public function test_different_values_and_types_get_independent_ids(): void {
		$d = $this->dict();
		$a = $d->id( 'bot', 'x' );
		$b = $d->id( 'ua_family', 'x' );
		$c = $d->id( 'bot', 'y' );
		$this->assertCount( 3, array_unique( array( $a, $b, $c ) ) );
	}

	public function test_values_resolves_ids_and_ignores_unknown(): void {
		$d     = $this->dict();
		$a     = $d->id( 'param_sig', 'a|b' );
		$b     = $d->id( 'param_sig', 'c' );
		$other = $d->id( 'bot', 'zzz' );
		$out   = $d->values( 'param_sig', array( $a, $b, $other, 999999 ) );
		$this->assertSame(
			array(
				$a => 'a|b',
				$b => 'c',
			),
			$out
		);
	}

	public function test_values_with_empty_ids_returns_empty(): void {
		$this->assertSame( array(), $this->dict()->values( 'bot', array() ) );
	}

	public function test_values_chunks_large_id_lists(): void {
		$d   = $this->dict();
		$ids = array();
		for ( $i = 0; $i < 650; $i++ ) {
			$ids[] = $d->id( 'url_pattern', 'p' . $i );
		}
		$out = $d->values( 'url_pattern', $ids );
		$this->assertCount( 650, $out );
		$this->assertSame( 'p649', $out[ $ids[649] ] );
	}

	public function test_long_values_are_cut_to_191_chars(): void {
		$d    = $this->dict();
		$long = str_repeat( 'é', 250 );
		$id   = $d->id( 'asn_org', $long );
		$this->assertSame( $id, $d->id( 'asn_org', str_repeat( 'é', 191 ) ) );
		$this->assertSame( str_repeat( 'é', 191 ), $d->values( 'asn_org', array( $id ) )[ $id ] );
	}

	public function test_unknown_type_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->dict()->id( 'nope', 'x' );
	}

	public function test_values_unknown_type_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->dict()->values( 'nope', array( 1 ) );
	}
}
