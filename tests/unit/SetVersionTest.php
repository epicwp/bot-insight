<?php
/**
 * Tests for bin/set-version.php.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SetVersionTest extends TestCase {

	/**
	 * Temp directory used as fake plugin root.
	 *
	 * @var string
	 */
	private $tmp = '';

	protected function setUp(): void {
		$this->tmp = sys_get_temp_dir() . '/bot-insight-sv-' . uniqid( '', true );
		mkdir( $this->tmp . '/src', 0777, true );
		$repo = dirname( __DIR__, 2 );
		foreach ( array( 'bot-insight.php', 'readme.txt', 'src/Plugin.php' ) as $file ) {
			copy( $repo . '/' . $file, $this->tmp . '/' . $file );
		}
	}

	protected function tearDown(): void {
		foreach ( array( 'bot-insight.php', 'readme.txt', 'src/Plugin.php' ) as $file ) {
			@unlink( $this->tmp . '/' . $file );
		}
		@rmdir( $this->tmp . '/src' );
		@rmdir( $this->tmp );
	}

	private function run_script( string $version ): int {
		$cmd = sprintf(
			'%s %s %s --root=%s 2>&1',
			escapeshellarg( PHP_BINARY ),
			escapeshellarg( dirname( __DIR__, 2 ) . '/bin/set-version.php' ),
			escapeshellarg( $version ),
			escapeshellarg( $this->tmp )
		);
		exec( $cmd, $output, $code );
		return $code;
	}

	private function read( string $file ): string {
		$contents = file_get_contents( $this->tmp . '/' . $file );
		$this->assertIsString( $contents );
		return $contents;
	}

	public function test_rewrites_all_three_places(): void {
		$this->assertSame( 0, $this->run_script( '1.4.2' ) );
		$this->assertStringContainsString( ' * Version:           1.4.2', $this->read( 'bot-insight.php' ) );
		$this->assertStringContainsString( 'Stable tag: 1.4.2', $this->read( 'readme.txt' ) );
		$this->assertStringContainsString( "const VERSION = '1.4.2';", $this->read( 'src/Plugin.php' ) );
	}

	public function test_accepts_prerelease(): void {
		$this->assertSame( 0, $this->run_script( '1.0.0-beta.3' ) );
		$this->assertStringContainsString( "const VERSION = '1.0.0-beta.3';", $this->read( 'src/Plugin.php' ) );
	}

	public function test_rejects_non_semver(): void {
		$before = $this->read( 'src/Plugin.php' );
		$this->assertSame( 1, $this->run_script( '1.4' ) );
		$this->assertSame( $before, $this->read( 'src/Plugin.php' ) );
	}
}
