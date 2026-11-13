<?php
/**
 * GeoLookup tests, with a reader double instead of real MMDB files.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Tests\Unit\Geo;

use BotInsight\Geo\GeoLookup;
use PHPUnit\Framework\TestCase;

final class GeoLookupTest extends TestCase {

	private const TYPES = array(
		'cdn'     => array( 'cloudflare', 'fastly', 'akamai' ),
		'hosting' => array( 'hetzner', 'akamai connected cloud', 'amazon' ),
		'mobile'  => array( 'mobile', 'wireless' ),
	);

	/**
	 * Build a lookup whose readers answer from arrays.
	 *
	 * @param array|null $country Country record or null.
	 * @param array|null $asn     ASN record or null.
	 * @return GeoLookup
	 */
	private function make( $country, $asn ): GeoLookup {
		$file = tempnam( sys_get_temp_dir(), 'bi' );
		$fac  = static function ( string $path ) use ( $country, $asn ) {
			$data = false !== strpos( $path, 'country' ) ? $country : $asn;
			return new class( $data ) {
				private $data;
				public function __construct( $data ) {
					$this->data = $data;
				}
				public function get( string $ip ) {
					return $this->data;
				}
			};
		};
		return new GeoLookup( $file . '-country', $file . '-asn', self::TYPES, $fac );
	}

	private function asn( string $org, int $number = 1 ): array {
		return array(
			'autonomous_system_number'       => $number,
			'autonomous_system_organization' => $org,
		);
	}

	public function test_cdn_header_wins(): void {
		$r = $this->make( array( 'country' => array( 'iso_code' => 'NL' ) ), null )->lookup( '192.0.2.1', 'de' );
		$this->assertSame( 'DE', $r['country'] );
	}

	public function test_xx_and_t1_are_ignored(): void {
		$g = $this->make( array( 'country' => array( 'iso_code' => 'NL' ) ), null );
		$this->assertSame( 'NL', $g->lookup( '192.0.2.1', 'XX' )['country'] );
		$this->assertSame( 'NL', $g->lookup( '192.0.2.1', 'T1' )['country'] );
		$this->assertSame( 'NL', $g->lookup( '192.0.2.1', '' )['country'] );
	}

	public function test_no_db_files_gives_unknown(): void {
		$g = new GeoLookup( null, null, self::TYPES );
		$this->assertSame(
			array(
				'country'  => '??',
				'asn'      => 0,
				'asn_org'  => '',
				'asn_type' => 'unknown',
			),
			$g->lookup( '192.0.2.1', '' )
		);
	}

	public function test_missing_file_with_default_factory_gives_unknown(): void {
		$g = new GeoLookup( '/nonexistent/c.mmdb', '/nonexistent/a.mmdb', self::TYPES );
		$this->assertSame( '??', $g->lookup( '192.0.2.1', '' )['country'] );
	}

	public function test_reader_exception_gives_no_data(): void {
		$fac = static function () {
			return new class() {
				public function get( string $ip ) {
					throw new \RuntimeException( 'boom' );
				}
			};
		};
		$f   = tempnam( sys_get_temp_dir(), 'bi' );
		$g   = new GeoLookup( $f, $f, self::TYPES, $fac );
		$r   = $g->lookup( '192.0.2.1', '' );
		$this->assertSame( '??', $r['country'] );
		$this->assertSame( 'unknown', $r['asn_type'] );
	}

	public function test_bad_country_code_gives_unknown(): void {
		$r = $this->make( array( 'country' => array( 'iso_code' => 'Netherlands' ) ), null )->lookup( '192.0.2.1', '' );
		$this->assertSame( '??', $r['country'] );
	}

	public function test_keyword_mapping(): void {
		$cases = array(
			'Hetzner Online GmbH'    => 'hosting',
			'Cloudflare, Inc.'       => 'cdn',
			'KPN B.V.'               => 'residential',
			'Akamai Connected Cloud' => 'hosting',
			'Akamai Technologies'    => 'cdn',
			'Vodafone Wireless'      => 'mobile',
		);
		foreach ( $cases as $org => $type ) {
			$r = $this->make( null, $this->asn( $org, 1136 ) )->lookup( '192.0.2.1', '' );
			$this->assertSame( $type, $r['asn_type'], $org );
			$this->assertSame( 1136, $r['asn'] );
			$this->assertSame( $org, $r['asn_org'] );
		}
	}
}
