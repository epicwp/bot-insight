<?php
/**
 * Per-process, per-minute append-only buffer files.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Buffer;

use BotInsight\Capture\Record;

// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions -- Runs on the visitor's request and in unit tests without WordPress; a failing write must stay silent and WP_Filesystem is not available or wanted here.

/**
 * Appends encoded records to `buffer-<YmdHi UTC>-<host hash>-<pid>.php` files.
 *
 * Each PHP process writes only its own file, so appends never contend. Every file starts with
 * `<?php exit; ?>` so a direct web request to it shows nothing. Nothing here throws or warns.
 */
final class FileBuffer {

	/**
	 * First line of every buffer file.
	 */
	public const HEADER = "<?php exit; ?>\n";

	/**
	 * Text in the `index.php` of every buffer directory; marks a directory as the plugin's own.
	 */
	public const INDEX_MARKER = 'Bot Insight buffer directory';

	/**
	 * Buffer directory without a trailing slash.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Host hash and process id part of the file name, computed once per process.
	 *
	 * @var string|null
	 */
	private static $suffix = null;

	/**
	 * Constructor.
	 *
	 * @param string $dir Buffer directory.
	 */
	public function __construct( string $dir ) {
		$this->dir = rtrim( $dir, '/\\' );
	}

	/**
	 * Buffer directory without a trailing slash.
	 *
	 * @return string
	 */
	public function dir(): string {
		return $this->dir;
	}

	/**
	 * Append one encoded line to this process's file for the minute of `$now`.
	 *
	 * @param string $line Encoded record, `\n` terminated.
	 * @param int    $now  Unix time.
	 * @return bool False when the write failed. Never throws, never warns.
	 */
	public function append( string $line, int $now ): bool {
		try {
			$file = $this->dir . '/buffer-' . gmdate( 'YmdHi', $now ) . '-' . self::suffix() . '.php';
			if ( ! @is_file( $file ) ) {
				$line = self::HEADER . $line;
			}
			return false !== @file_put_contents( $file, $line, FILE_APPEND | LOCK_EX );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Buffer files of minutes before the minute of `$now`, oldest first.
	 *
	 * @param int $now Unix time.
	 * @return string[] Absolute paths.
	 */
	public function foldable( int $now ): array {
		$files = @glob( $this->dir . '/buffer-*.php' );
		if ( ! is_array( $files ) ) {
			return array();
		}
		$current = gmdate( 'YmdHi', $now );
		$out     = array();
		foreach ( $files as $file ) {
			$name = basename( $file );
			if ( 1 !== preg_match( '/^buffer-(\d{12})-[0-9a-f]+-\d+\.php$/', $name, $m ) ) {
				continue;
			}
			if ( strcmp( $m[1], $current ) < 0 ) {
				$out[ $name ] = $file;
			}
		}
		ksort( $out, SORT_STRING );
		return array_values( $out );
	}

	/**
	 * Decoded records of a buffer file. The header line and broken lines are skipped.
	 *
	 * @param string $file Absolute path.
	 * @return \Generator<int, array<string, mixed>>
	 */
	public function read( string $file ): \Generator {
		$handle = @fopen( $file, 'rb' );
		if ( false === $handle ) {
			return;
		}
		try {
			while ( true ) {
				$line = fgets( $handle );
				if ( false === $line ) {
					break;
				}
				$record = Record::decode( rtrim( $line, "\r\n" ) );
				if ( null !== $record ) {
					yield $record;
				}
			}
		} finally {
			fclose( $handle );
		}
	}

	/**
	 * Remove a buffer file. A missing file is fine.
	 *
	 * @param string $file Absolute path.
	 */
	public function delete( string $file ): void {
		@unlink( $file );
	}

	/**
	 * Create the directory with an `index.php` and an `.htaccess` that deny web access.
	 *
	 * @return bool Whether the directory exists and is writable afterwards.
	 */
	public function ensureDir(): bool {
		if ( ! @is_dir( $this->dir ) && ! @mkdir( $this->dir, 0755, true ) && ! @is_dir( $this->dir ) ) {
			return false;
		}
		if ( ! @is_file( $this->dir . '/index.php' ) ) {
			@file_put_contents( $this->dir . '/index.php', "<?php\n// " . self::INDEX_MARKER . ". Silence is golden.\n" );
		}
		if ( ! @is_file( $this->dir . '/.htaccess' ) ) {
			@file_put_contents(
				$this->dir . '/.htaccess',
				"<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n"
			);
		}
		return @is_writable( $this->dir );
	}

	/**
	 * Whether a directory was made by `ensureDir()`: a real directory (not a symlink) whose
	 * `index.php` carries the marker.
	 *
	 * @param string $dir Directory.
	 * @return bool
	 */
	public static function isOwnDir( string $dir ): bool {
		$dir = rtrim( $dir, '/\\' );
		if ( '' === $dir || @is_link( $dir ) || ! @is_dir( $dir ) || ! @is_file( $dir . '/index.php' ) ) {
			return false;
		}
		$head = @file_get_contents( $dir . '/index.php', false, null, 0, 512 );
		return is_string( $head ) && false !== strpos( $head, self::INDEX_MARKER );
	}

	/**
	 * Delete the directory and everything in it, but only when it is the plugin's own.
	 *
	 * Symbolic links are removed, never followed.
	 *
	 * @return bool Whether the directory is gone afterwards.
	 */
	public function removeDir(): bool {
		if ( ! self::isOwnDir( $this->dir ) ) {
			return false;
		}
		try {
			$items = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $this->dir, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $items as $item ) {
				if ( ! $item instanceof \SplFileInfo ) {
					continue;
				}
				$path = $item->getPathname();
				if ( $item->isDir() && ! $item->isLink() ) {
					@rmdir( $path );
				} else {
					@unlink( $path );
				}
			}
		} catch ( \Throwable $e ) {
			return false;
		}
		@rmdir( $this->dir );
		return ! @is_dir( $this->dir );
	}

	/**
	 * `<crc32(hostname) hex>-<pid>`, cached per process.
	 *
	 * @return string
	 */
	private static function suffix(): string {
		if ( null === self::$suffix ) {
			$host         = gethostname();
			self::$suffix = dechex( crc32( false === $host ? '' : $host ) ) . '-' . (int) getmypid();
		}
		return self::$suffix;
	}
}
