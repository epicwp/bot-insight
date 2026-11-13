<?php
/**
 * GeoUpdater with faked HTTP responses (no real network).
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Tests\Integration;

use BotInsight\Geo\GeoUpdater;
use BotInsight\Plugin;

final class GeoUpdaterTest extends \WP_UnitTestCase {

	/**
	 * Requested URLs.
	 *
	 * @var string[]
	 */
	private $urls = array();

	/**
	 * Months that answer 404.
	 *
	 * @var string[]
	 */
	private $missing = array();

	private $dir = '';

	public function set_up(): void {
		parent::set_up();
		$this->dir = sys_get_temp_dir() . '/bi-geo-' . wp_generate_password( 8, false );
		mkdir( $this->dir );
		update_option( 'bot_insight_buffer_dir', $this->dir );
		$this->urls    = array();
		$this->missing = array();
		add_filter( 'pre_http_request', array( $this, 'fake' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'fake' ), 10 );
		$this->setOptIn( false );
		delete_option( 'bot_insight_buffer_dir' );
		foreach ( (array) glob( $this->dir . '/geo/{,.}*', GLOB_BRACE ) as $f ) {
			if ( is_file( $f ) ) {
				unlink( $f );
			}
		}
		@rmdir( $this->dir . '/geo' );
		@rmdir( $this->dir );
		parent::tear_down();
	}

	private function setOptIn( bool $on ): void {
		update_option( 'bot_insight_settings', array( 'geo_updates' => $on ) );
		$prop = new \ReflectionProperty( Plugin::class, 'settings' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
	}

	/**
	 * Fake the HTTP layer.
	 *
	 * @param mixed  $pre  Pre value.
	 * @param array  $args Request args.
	 * @param string $url  URL.
	 * @return array
	 */
	public function fake( $pre, $args, $url ) {
		$this->urls[] = $url;
		foreach ( $this->missing as $month ) {
			if ( false !== strpos( $url, $month . '.mmdb.gz' ) ) {
				return array(
					'headers'  => array(),
					'body'     => '',
					'response' => array(
						'code'    => 404,
						'message' => 'Not Found',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			}
		}
		file_put_contents( $args['filename'], gzencode( 'MMDB:' . basename( $url ) ) );
		return array(
			'headers'  => array(),
			'body'     => '',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => $args['filename'],
		);
	}

	public function test_nothing_happens_when_opted_out(): void {
		$this->setOptIn( false );
		$this->assertFalse( ( new GeoUpdater() )->run( 1794000000 ) );
		$this->assertSame( array(), $this->urls );
		$this->assertFileDoesNotExist( GeoUpdater::countryPath() );
	}

	public function test_files_are_written_when_opted_in(): void {
		$this->setOptIn( true );
		$this->assertTrue( ( new GeoUpdater() )->run( 1794000000 ) ); // 2026-11-.
		$this->assertSame( 'MMDB:dbip-country-lite-2026-11.mmdb.gz', file_get_contents( GeoUpdater::countryPath() ) );
		$this->assertSame( 'MMDB:dbip-asn-lite-2026-11.mmdb.gz', file_get_contents( GeoUpdater::asnPath() ) );
		$this->assertFileDoesNotExist( GeoUpdater::countryPath() . '.tmp' );
		$this->assertFileExists( $this->dir . '/geo/index.php' );
		$this->assertFileExists( $this->dir . '/geo/.htaccess' );
	}

	public function test_falls_back_to_previous_month_on_404(): void {
		$this->setOptIn( true );
		$this->missing = array( '2026-11' );
		$this->assertTrue( ( new GeoUpdater() )->run( 1794000000 ) );
		$this->assertSame( 'MMDB:dbip-country-lite-2026-10.mmdb.gz', file_get_contents( GeoUpdater::countryPath() ) );
		$this->assertSame( 'MMDB:dbip-asn-lite-2026-10.mmdb.gz', file_get_contents( GeoUpdater::asnPath() ) );
	}

	public function test_fails_when_both_months_are_missing(): void {
		$this->setOptIn( true );
		$this->missing = array( '2026-11', '2026-10' );
		$this->assertFalse( ( new GeoUpdater() )->run( 1794000000 ) );
		$this->assertFileDoesNotExist( GeoUpdater::countryPath() );
	}
}
