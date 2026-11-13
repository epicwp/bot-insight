<?php
/**
 * Tests for the per-process file buffer.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Tests\Unit\Buffer;

use BotInsight\Buffer\FileBuffer;
use BotInsight\Capture\Record;
use PHPUnit\Framework\TestCase;

final class FileBufferTest extends TestCase {

	/**
	 * 2026-11-13 10:15:30 UTC.
	 */
	private const NOW = 1794564930;

	/**
	 * @var string
	 */
	private $dir;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/bi-buffer-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->dir );
	}

	protected function tearDown(): void {
		if ( ! is_dir( $this->dir ) ) {
			return;
		}
		chmod( $this->dir, 0755 );
		foreach ( (array) glob( $this->dir . '/{,.}*', GLOB_BRACE ) as $file ) {
			if ( is_file( (string) $file ) ) {
				unlink( (string) $file );
			}
		}
		rmdir( $this->dir );
	}

	private function line( int $ts ): string {
		return Record::encode( array( 'ts' => $ts ) );
	}

	public function test_two_appends_in_one_minute_share_one_file_with_one_header(): void {
		$buffer = new FileBuffer( $this->dir );
		$this->assertTrue( $buffer->append( $this->line( 1 ), self::NOW ) );
		$this->assertTrue( $buffer->append( $this->line( 2 ), self::NOW + 20 ) );

		$files = glob( $this->dir . '/buffer-*.php' );
		$this->assertIsArray( $files );
		$this->assertCount( 1, $files );
		$this->assertMatchesRegularExpression(
			'/^buffer-202611131015-[0-9a-f]{1,8}-' . getmypid() . '\.php$/',
			basename( $files[0] )
		);
		$this->assertSame(
			"<?php exit; ?>\n" . $this->line( 1 ) . $this->line( 2 ),
			file_get_contents( $files[0] )
		);
	}

	public function test_next_minute_gets_a_new_file(): void {
		$buffer = new FileBuffer( $this->dir );
		$buffer->append( $this->line( 1 ), self::NOW );
		$buffer->append( $this->line( 2 ), self::NOW + 60 );
		$this->assertCount( 2, (array) glob( $this->dir . '/buffer-*.php' ) );
	}

	public function test_foldable_excludes_the_current_minute_and_is_sorted(): void {
		$buffer = new FileBuffer( $this->dir );
		$buffer->append( $this->line( 3 ), self::NOW );
		$buffer->append( $this->line( 2 ), self::NOW - 60 );
		$buffer->append( $this->line( 1 ), self::NOW - 3600 );
		touch( $this->dir . '/not-a-buffer.php' );
		touch( $this->dir . '/buffer-garbage.php' );

		$files = $buffer->foldable( self::NOW );
		$this->assertCount( 2, $files );
		$this->assertStringContainsString( 'buffer-202611130915-', $files[0] );
		$this->assertStringContainsString( 'buffer-202611131014-', $files[1] );
		$this->assertSame( $this->dir, dirname( $files[0] ) );

		$this->assertCount( 3, $buffer->foldable( self::NOW + 60 ) );
	}

	public function test_foldable_on_missing_dir_is_empty(): void {
		$this->assertSame( array(), ( new FileBuffer( $this->dir . '/nope' ) )->foldable( self::NOW ) );
	}

	public function test_read_skips_header_and_broken_lines(): void {
		$file = $this->dir . '/buffer-202611131014-abc-1.php';
		file_put_contents(
			$file,
			"<?php exit; ?>\n" . $this->line( 1 ) . "{\"ts\":2,\"ms\n" . "\n" . $this->line( 3 ) . '{"ts":4'
		);
		$buffer = new FileBuffer( $this->dir );
		$ts     = array();
		foreach ( $buffer->read( $file ) as $record ) {
			$ts[] = $record['ts'];
		}
		$this->assertSame( array( 1, 3 ), $ts );
	}

	public function test_read_of_missing_file_yields_nothing(): void {
		$buffer = new FileBuffer( $this->dir );
		$this->assertSame( array(), iterator_to_array( $buffer->read( $this->dir . '/missing.php' ), false ) );
	}

	public function test_delete_removes_the_file_and_tolerates_missing(): void {
		$buffer = new FileBuffer( $this->dir );
		$buffer->append( $this->line( 1 ), self::NOW - 60 );
		$files = $buffer->foldable( self::NOW );
		$buffer->delete( $files[0] );
		$this->assertFileDoesNotExist( $files[0] );
		$buffer->delete( $files[0] );
		$this->assertSame( array(), $buffer->foldable( self::NOW ) );
	}

	public function test_ensure_dir_creates_dir_and_guards(): void {
		$dir    = $this->dir . '/sub/bot-insight';
		$buffer = new FileBuffer( $dir );
		$this->assertTrue( $buffer->ensureDir() );
		$this->assertDirectoryExists( $dir );
		$this->assertFileExists( $dir . '/index.php' );
		$htaccess = (string) file_get_contents( $dir . '/.htaccess' );
		$this->assertStringContainsString( 'Require all denied', $htaccess );
		$this->assertStringContainsString( 'Deny from all', $htaccess );
		$this->assertTrue( $buffer->ensureDir() );

		unlink( $dir . '/index.php' );
		unlink( $dir . '/.htaccess' );
		rmdir( $dir );
		rmdir( dirname( $dir ) );
	}

	/**
	 * Review Focus 2: a broken buffer dir must never surface to the visitor.
	 */
	public function test_unwritable_dir_returns_false_without_warning_or_exception(): void {
		chmod( $this->dir, 0555 );
		if ( is_writable( $this->dir ) ) {
			$this->markTestSkipped( 'Running as a user that ignores directory permissions.' );
		}
		$errors = array();
		set_error_handler(
			static function ( int $no, string $msg ) use ( &$errors ): bool {
				if ( error_reporting() & $no ) {
					$errors[] = $msg;
				}
				return true;
			}
		);
		try {
			$result = ( new FileBuffer( $this->dir ) )->append( $this->line( 1 ), self::NOW );
			$missing = ( new FileBuffer( $this->dir . '/missing' ) )->append( $this->line( 1 ), self::NOW );
		} finally {
			restore_error_handler();
		}
		$this->assertFalse( $result );
		$this->assertFalse( $missing );
		$this->assertSame( array(), $errors );
		$this->assertSame( array(), (array) glob( $this->dir . '/buffer-*.php' ) );
	}

	public function test_ensure_dir_on_unwritable_parent_returns_false_quietly(): void {
		chmod( $this->dir, 0555 );
		if ( is_writable( $this->dir ) ) {
			$this->markTestSkipped( 'Running as a user that ignores directory permissions.' );
		}
		$this->assertFalse( ( new FileBuffer( $this->dir . '/child' ) )->ensureDir() );
	}
}
