<?php
/**
 * Download of the published bot and CDN IP ranges.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Net;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Also runs as a CLI dev tool without WordPress; writes only into its own range directory.

/**
 * Fetches the vendor range lists and writes one `<list>.php` file per list plus `_meta.php`, in
 * the format `IpRanges` reads. Used by `bin/update-ranges.php` (bundled lists) and, after the
 * owner opted in, by the daily job (override lists in the private uploads directory).
 *
 * A list is written only when every one of its URLs answered with a non-empty set of valid CIDRs;
 * otherwise the previous (or bundled) list stays in use.
 */
final class RangeUpdater {

	/**
	 * List name => source URLs (all https).
	 */
	public const SETS = array(
		'google'              => array(
			'https://developers.google.com/static/search/apis/ipranges/googlebot.json',
			'https://developers.google.com/static/search/apis/ipranges/special-crawlers.json',
			'https://developers.google.com/static/search/apis/ipranges/user-triggered-fetchers.json',
		),
		'bing'                => array( 'https://www.bing.com/toolbox/bingbot.json' ),
		'apple'               => array( 'https://search.developer.apple.com/applebot.json' ),
		'openai-gptbot'       => array( 'https://openai.com/gptbot.json' ),
		'openai-searchbot'    => array( 'https://openai.com/searchbot.json' ),
		'openai-chatgpt-user' => array( 'https://openai.com/chatgpt-user.json' ),
		'perplexity-bot'      => array( 'https://www.perplexity.ai/perplexitybot.json' ),
		'perplexity-user'     => array( 'https://www.perplexity.ai/perplexity-user.json' ),
		'cloudflare'          => array( 'https://api.cloudflare.com/client/v4/ips' ),
	);

	/**
	 * Largest response accepted, in bytes.
	 */
	private const MAX_BODY = 5242880;

	private const USER_AGENT = 'bot-insight-range-updater';

	/**
	 * Receives progress lines: fn( string $line ).
	 *
	 * @var callable|null
	 */
	private $log;

	/**
	 * Constructor.
	 *
	 * @param callable|null $log Receives one progress line per URL and list (CLI output).
	 */
	public function __construct( ?callable $log = null ) {
		$this->log = $log;
	}

	/**
	 * Fetch every list and write the valid ones into a directory, each atomically (tmp + rename).
	 * `_meta.php` is written last, when at least one list was written; its `ok` names the lists of
	 * this run.
	 *
	 * @param string $target_dir Directory to write into; created when missing.
	 * @return bool True when every list was written.
	 */
	public function run( string $target_dir ): bool {
		$dir = rtrim( $target_dir, '/\\' );
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Checked right after.
			return false;
		}
		$meta = array(
			'fetched' => gmdate( 'Y-m-d' ),
			'ok'      => array(),
			'failed'  => array(),
		);
		foreach ( self::SETS as $name => $urls ) {
			$ranges = $this->fetchSet( $urls );
			if ( null === $ranges ) {
				$meta['failed'][] = $name;
				$this->say( "DROPPED {$name}" );
				continue;
			}
			if ( ! self::write( $dir . '/' . $name . '.php', $ranges ) ) {
				$meta['failed'][] = $name;
				$this->say( "UNWRITABLE {$name}" );
				continue;
			}
			$meta['ok'][ $name ] = count( $ranges );
		}
		if ( array() === $meta['ok'] ) {
			return false;
		}
		return self::write( $dir . '/_meta.php', $meta ) && array() === $meta['failed'];
	}

	/**
	 * Extract CIDR strings from a vendor JSON (`prefixes[].ipv4Prefix|ipv6Prefix`), the Cloudflare
	 * API JSON (`result.ipv4_cidrs|ipv6_cidrs`) or a plain-text list.
	 *
	 * @param string $body Response body.
	 * @return string[]
	 */
	public static function parse( string $body ): array {
		$out  = array();
		$json = json_decode( $body, true );
		if ( is_array( $json ) ) {
			foreach ( is_array( $json['prefixes'] ?? null ) ? $json['prefixes'] : array() as $row ) {
				foreach ( array( 'ipv4Prefix', 'ipv6Prefix' ) as $key ) {
					if ( is_array( $row ) && isset( $row[ $key ] ) && is_string( $row[ $key ] ) ) {
						$out[] = $row[ $key ];
					}
				}
			}
			$result = is_array( $json['result'] ?? null ) ? $json['result'] : array();
			foreach ( array( 'ipv4_cidrs', 'ipv6_cidrs' ) as $key ) {
				foreach ( is_array( $result[ $key ] ?? null ) ? $result[ $key ] : array() as $cidr ) {
					if ( is_string( $cidr ) ) {
						$out[] = $cidr;
					}
				}
			}
			return $out;
		}
		foreach ( (array) preg_split( '/\s+/', trim( $body ) ) as $line ) {
			if ( is_string( $line ) && 1 === preg_match( '#^[0-9a-f:.]+/\d{1,3}$#i', $line ) ) {
				$out[] = $line;
			}
		}
		return $out;
	}

	/**
	 * Whether a string is a valid IPv4 or IPv6 CIDR.
	 *
	 * @param string $cidr Candidate.
	 */
	public static function isCidr( string $cidr ): bool {
		if ( 1 !== preg_match( '#^([0-9a-f:.]+)/(\d{1,3})$#i', $cidr, $m ) ) {
			return false;
		}
		$net = @inet_pton( $m[1] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid input is an expected, handled case.
		return false !== $net && (int) $m[2] <= strlen( $net ) * 8;
	}

	/**
	 * Fetch a URL: `wp_remote_get` inside WordPress, else curl, else `file_get_contents`.
	 *
	 * @param string $url URL.
	 * @return string|null Body of a 200 response, or null.
	 */
	public static function fetch( string $url ): ?string {
		if ( function_exists( 'wp_remote_get' ) ) {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'             => 20,
					'user-agent'          => self::USER_AGENT,
					'limit_response_size' => self::MAX_BODY,
				)
			);
			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				return null;
			}
			$body = wp_remote_retrieve_body( $response );
		} elseif ( function_exists( 'curl_init' ) ) {
			$ch = curl_init( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init -- CLI without WordPress.
			if ( false === $ch ) {
				return null;
			}
			curl_setopt_array( // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt_array -- CLI without WordPress.
				$ch,
				array(
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_FOLLOWLOCATION => true,
					CURLOPT_TIMEOUT        => 20,
					CURLOPT_USERAGENT      => self::USER_AGENT,
				)
			);
			$body = curl_exec( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_exec -- CLI without WordPress.
			$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo -- CLI without WordPress.
			curl_close( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close -- CLI without WordPress.
			if ( 200 !== $code || ! is_string( $body ) ) {
				return null;
			}
		} else {
			$ctx  = stream_context_create(
				array(
					'http' => array(
						'timeout'    => 20,
						'user_agent' => self::USER_AGENT,
					),
				)
			);
			$body = @file_get_contents( $url, false, $ctx, 0, self::MAX_BODY ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A failed fetch is an expected result.
			if ( ! is_string( $body ) ) {
				return null;
			}
		}
		return '' === $body || strlen( $body ) > self::MAX_BODY ? null : $body;
	}

	/**
	 * All ranges of one list, or null when any URL failed or gave an invalid or empty list.
	 *
	 * @param string[] $urls Source URLs.
	 * @return string[]|null
	 */
	private function fetchSet( array $urls ): ?array {
		$ranges = array();
		foreach ( $urls as $url ) {
			$body   = self::fetch( $url );
			$parsed = null === $body ? array() : self::parse( $body );
			if ( array() === $parsed ) {
				$this->say( "FAILED  {$url}" );
				return null;
			}
			foreach ( $parsed as $cidr ) {
				if ( ! self::isCidr( $cidr ) ) {
					$this->say( "INVALID {$url}" );
					return null;
				}
			}
			$this->say( 'ok      ' . $url . ' (' . count( $parsed ) . ')' );
			$ranges = array_merge( $ranges, $parsed );
		}
		$ranges = array_values( array_unique( $ranges ) );
		return array() === $ranges ? null : $ranges;
	}

	/**
	 * Write a PHP file returning a value, atomically.
	 *
	 * @param string $file  Target path.
	 * @param mixed  $value Value to export.
	 */
	private static function write( string $file, $value ): bool {
		$code = "<?php\n// Generated by the Bot Insight range updater. Do not edit.\nreturn " . var_export( $value, true ) . ";\n"; // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Generates a PHP data file.
		$tmp  = $file . '.' . bin2hex( random_bytes( 4 ) ) . '.tmp';
		if ( false === @file_put_contents( $tmp, $code ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Checked.
			return false;
		}
		if ( ! @rename( $tmp, $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Checked.
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best effort cleanup.
			return false;
		}
		if ( function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $file, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Restricted opcache API may warn.
		}
		return true;
	}

	/**
	 * Report a progress line.
	 *
	 * @param string $line Line.
	 */
	private function say( string $line ): void {
		if ( null !== $this->log ) {
			( $this->log )( $line );
		}
	}
}
