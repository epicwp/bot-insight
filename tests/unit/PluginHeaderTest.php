<?php
/**
 * Keeps plugin header, readme and Plugin::VERSION in sync.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Tests\Unit;

use BotInsight\Plugin;
use PHPUnit\Framework\TestCase;

final class PluginHeaderTest extends TestCase {

	private function root_file( string $name ): string {
		$contents = file_get_contents( dirname( __DIR__, 2 ) . '/' . $name );
		$this->assertIsString( $contents );
		return $contents;
	}

	private function field( string $contents, string $label ): string {
		$this->assertSame( 1, preg_match( '/^[ \t\/*#@]*' . preg_quote( $label, '/' ) . ':[ \t]*(.+)$/mi', $contents, $m ), "Missing field {$label}" );
		return trim( $m[1] );
	}

	public function test_header_version_matches_constant(): void {
		$this->assertSame( Plugin::VERSION, $this->field( $this->root_file( 'bot-insight.php' ), 'Version' ) );
	}

	public function test_readme_stable_tag_matches_constant(): void {
		$this->assertSame( Plugin::VERSION, $this->field( $this->root_file( 'readme.txt' ), 'Stable tag' ) );
	}

	public function test_header_requirements(): void {
		$header = $this->root_file( 'bot-insight.php' );
		$this->assertSame( '7.4', $this->field( $header, 'Requires PHP' ) );
		$this->assertSame( '6.2', $this->field( $header, 'Requires at least' ) );
		$this->assertSame( 'GPL-2.0-or-later', $this->field( $header, 'License' ) );
		$this->assertSame( 'bot-insight', $this->field( $header, 'Text Domain' ) );
	}
}
