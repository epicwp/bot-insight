<?php
/**
 * Tests for the daily rotating salt.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Tests\Unit\Storage;

use BotInsight\Storage\Salt;
use PHPUnit\Framework\TestCase;

final class SaltTest extends TestCase {

	/**
	 * In-memory option store.
	 *
	 * @var array<string,mixed>
	 */
	private $options = array();

	private function salt(): Salt {
		return new Salt(
			function ( $name ) {
				return $this->options[ $name ] ?? false;
			},
			function ( $name, $value ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
	}

	public function test_salt_is_64_hex_chars_and_stable_within_a_day(): void {
		$salt = $this->salt();
		$a    = $salt->forDay( '20261113' );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $a );
		$this->assertSame( $a, $salt->forDay( '20261113' ) );
		$this->assertSame( $a, $this->salt()->forDay( '20261113' ) );
	}

	public function test_salt_is_stored_in_the_option(): void {
		$a = $this->salt()->forDay( '20261113' );
		$this->assertSame(
			array(
				'day'  => '20261113',
				'salt' => $a,
			),
			$this->options['bot_insight_salt']
		);
	}

	public function test_next_day_gets_a_new_salt_and_replaces_the_old(): void {
		$salt = $this->salt();
		$a    = $salt->forDay( '20261113' );
		$b    = $salt->forDay( '20261114' );
		$this->assertNotSame( $a, $b );
		$this->assertSame( '20261114', $this->options['bot_insight_salt']['day'] );
	}

	public function test_previous_day_is_not_retrievable_and_not_stored(): void {
		$salt = $this->salt();
		$a    = $salt->forDay( '20261113' );
		$b    = $salt->forDay( '20261114' );
		$old  = $salt->forDay( '20261113' );
		$this->assertNotSame( $a, $old );
		$this->assertNotSame( $b, $old );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $old );
		$this->assertSame( $b, $salt->forDay( '20261114' ) );
		$this->assertSame( '20261114', $this->options['bot_insight_salt']['day'] );
	}

	public function test_hash_is_16_hex_chars_and_stable_per_day(): void {
		$salt = $this->salt();
		$h    = $salt->hash( '192.0.2.10', '20261113' );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', $h );
		$this->assertSame( $h, $salt->hash( '192.0.2.10', '20261113' ) );
		$this->assertNotSame( $h, $salt->hash( '192.0.2.11', '20261113' ) );
		$this->assertNotSame( $h, $salt->hash( '192.0.2.10', '20261114' ) );
	}

	public function test_invalid_day_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->salt()->forDay( '2026-11-13' );
	}
}
