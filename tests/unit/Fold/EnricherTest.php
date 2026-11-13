<?php
/**
 * Tests for the enricher.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Tests\Unit\Fold;

use BotInsight\Bots\BotList;
use BotInsight\Bots\Classifier;
use BotInsight\Bots\UaParser;
use BotInsight\Bots\Verifier;
use BotInsight\Capture\Record;
use BotInsight\Fold\Enricher;
use BotInsight\Geo\GeoLookup;
use BotInsight\Net\IpRanges;
use BotInsight\Request\HeaderFlags;
use BotInsight\Storage\Salt;
use PHPUnit\Framework\TestCase;

final class EnricherTest extends TestCase {

	private const GOOGLEBOT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
	private const CHROME    = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';

	/**
	 * Option store for the salt.
	 *
	 * @var array<string,mixed>
	 */
	private $options = array();

	private function enricher(): Enricher {
		$this->options = array();
		$list          = BotList::fromFile( dirname( __DIR__, 2 ) . '/fixtures/bots.json' );
		$verifier      = new Verifier(
			new IpRanges( dirname( __DIR__, 2 ) . '/fixtures/ranges' ),
			function ( string $ip ) {
				return false;
			},
			function ( string $host ) {
				return false;
			},
			function ( string $ip ) {
				return null;
			},
			function ( string $ip, int $result ): void {},
			50
		);
		$salt          = new Salt(
			function ( $name ) {
				return $this->options[ $name ] ?? false;
			},
			function ( $name, $value ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		return new Enricher(
			new UaParser( $list, $list->currentMajors() ),
			new Classifier(),
			$verifier,
			$salt,
			new GeoLookup( null, null, array() )
		);
	}

	/**
	 * @param array<string,mixed> $over Overrides.
	 * @return array<string,mixed>
	 */
	private function record( array $over = array() ): array {
		$record = array();
		foreach ( Record::TYPES as $key => $type ) {
			$record[ $key ] = 'string' === $type ? '' : 0;
		}
		return array_merge(
			$record,
			array(
				'ts' => 1794571234,
				'st' => 200,
				'm'  => 'GET',
				'ua' => self::CHROME,
				'ip' => '203.0.113.77',
				'hf' => HeaderFlags::SEC_FETCH | HeaderFlags::ACCEPT_LANGUAGE,
				'js' => 1,
			),
			$over
		);
	}

	public function test_googlebot_from_range_is_verified_search(): void {
		$row = $this->enricher()->enrich(
			$this->record(
				array(
					'ua' => self::GOOGLEBOT,
					'ip' => '198.51.100.7',
				)
			)
		);
		$this->assertSame( 'search', $row['class'] );
		$this->assertSame( 1, $row['verified'] );
		$this->assertSame( 'googlebot', $row['bot'] );
		$this->assertSame( 'Googlebot', $row['ua_family'] );
		$this->assertSame( 0, $row['ua_outdated'] );
	}

	public function test_googlebot_from_other_ip_is_impostor(): void {
		$row = $this->enricher()->enrich( $this->record( array( 'ua' => self::GOOGLEBOT ) ) );
		$this->assertSame( 'unknown_bot', $row['class'] );
		$this->assertSame( 2, $row['verified'] );
		$this->assertSame( 'googlebot', $row['bot'] );
	}

	public function test_browser_is_people(): void {
		$row = $this->enricher()->enrich( $this->record() );
		$this->assertContains( $row['class'], array( 'people', 'suspected' ) );
		$this->assertSame( 'people', $row['class'] );
		$this->assertSame( '', $row['bot'] );
		$this->assertSame( 0, $row['verified'] );
		$this->assertSame( 'Chrome 140', $row['ua_family'] );
	}

	public function test_raw_ip_and_ua_do_not_survive(): void {
		$row = $this->enricher()->enrich( $this->record() );
		$this->assertArrayNotHasKey( 'ip', $row );
		$this->assertArrayNotHasKey( 'ua', $row );
		$this->assertNotContains( '203.0.113.77', $row );
		$this->assertNotContains( self::CHROME, $row );
	}

	public function test_ip_fields(): void {
		$enricher = $this->enricher();
		$row      = $enricher->enrich( $this->record() );
		$this->assertSame( '203.0.113.0', $row['ip_prefix'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', $row['ip_hash'] );
		$this->assertSame( $row['ip_hash'], $enricher->enrich( $this->record() )['ip_hash'] );
		$this->assertNotSame( $row['ip_hash'], $enricher->enrich( $this->record( array( 'ip' => '203.0.113.78' ) ) )['ip_hash'] );
	}

	public function test_empty_ip(): void {
		$row = $this->enricher()->enrich( $this->record( array( 'ip' => '' ) ) );
		$this->assertSame( '', $row['ip_hash'] );
		$this->assertSame( '', $row['ip_prefix'] );
		$this->assertSame( '??', $row['country'] );
		$this->assertSame( 0, $row['asn'] );
		$this->assertSame( 'unknown', $row['asn_type'] );
		$this->assertSame( '', $row['asn_org'] );
	}

	public function test_cdn_country_is_used(): void {
		$row = $this->enricher()->enrich( $this->record( array( 'cc' => 'nl' ) ) );
		$this->assertSame( 'NL', $row['country'] );
	}

	public function test_hour_and_status_class(): void {
		$row = $this->enricher()->enrich(
			$this->record(
				array(
					'ts' => 1794571234,
					'st' => 404,
				)
			)
		);
		$this->assertSame( 1794571234 - 1794571234 % 3600, $row['hour'] );
		$this->assertSame( '4xx', $row['status_class'] );
		$this->assertSame( '2xx', $this->enricher()->enrich( $this->record( array( 'st' => 200 ) ) )['status_class'] );
		$this->assertSame( '3xx', $this->enricher()->enrich( $this->record( array( 'st' => 301 ) ) )['status_class'] );
		$this->assertSame( '5xx', $this->enricher()->enrich( $this->record( array( 'st' => 503 ) ) )['status_class'] );
		$this->assertSame( 'xxx', $this->enricher()->enrich( $this->record( array( 'st' => 0 ) ) )['status_class'] );
		$this->assertSame( 'xxx', $this->enricher()->enrich( $this->record( array( 'st' => 999 ) ) )['status_class'] );
	}

	public function test_other_record_keys_are_kept(): void {
		$row = $this->enricher()->enrich(
			$this->record(
				array(
					'r'   => 'product',
					'dbq' => 12,
					'ms'  => 33.5,
				)
			)
		);
		$this->assertSame( 'product', $row['r'] );
		$this->assertSame( 12, $row['dbq'] );
		$this->assertSame( 33.5, $row['ms'] );
	}
}
