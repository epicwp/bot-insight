<?php
/**
 * Request shape descriptors.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Request;

/**
 * Low-cardinality descriptions of a request: query parameter names, page depth, referer class.
 */
final class Shape {

	private const MAX_NAMES = 20;

	private const MAX_NAME_LENGTH = 40;

	/**
	 * Referer hosts of AI assistants. Checked first.
	 */
	private const AI = array(
		'chatgpt.com',
		'chat.openai.com',
		'perplexity.ai',
		'claude.ai',
		'gemini.google.com',
		'copilot.microsoft.com',
	);

	/**
	 * Search engines. A trailing dot means "some label of the host equals this".
	 */
	private const SEARCH = array(
		'google.',
		'bing.com',
		'duckduckgo.com',
		'yahoo.',
		'ecosia.org',
		'yandex.',
		'baidu.com',
	);

	/**
	 * Social networks.
	 */
	private const SOCIAL = array(
		'facebook.com',
		'instagram.com',
		't.co',
		'x.com',
		'twitter.com',
		'linkedin.com',
		'pinterest.',
		'reddit.com',
		'tiktok.com',
		'youtube.com',
	);

	/**
	 * Placeholder for parameter names that look random (cache busters, ids, tokens).
	 */
	public const RANDOM_NAME = '#';

	/**
	 * Comma-separated, cleaned, sorted query parameter names (never values). Names that are all
	 * digits, or 16 or more letters and digits without a separator that look like hex or base64
	 * (any digit, or hex only), collapse to `#`, so random names do not each make a new signature.
	 *
	 * @param array<int|string, mixed> $get A $_GET-like array.
	 * @return string
	 */
	public static function paramNames( array $get ): string {
		$names = array();
		foreach ( $get as $key => $unused ) {
			$name = (string) preg_replace( '/[^a-z0-9_\-\[\]]+/', '', strtolower( (string) $key ) );
			if ( '' === $name ) {
				continue;
			}
			if ( ctype_digit( $name ) || ( strlen( $name ) >= 16 && ctype_alnum( $name ) && ( 1 === preg_match( '/\d/', $name ) || ctype_xdigit( $name ) ) ) ) {
				$name = self::RANDOM_NAME;
			}
			$name           = substr( $name, 0, self::MAX_NAME_LENGTH );
			$names[ $name ] = true;
		}
		if ( array() === $names ) {
			return '';
		}
		$names = array_map( 'strval', array_keys( $names ) );
		sort( $names, SORT_STRING );

		return implode( ',', array_slice( $names, 0, self::MAX_NAMES ) );
	}

	/**
	 * Bucket a pagination number.
	 *
	 * @param int $paged Page number.
	 * @return string One of `1`, `2-5`, `6-20`, `21+`.
	 */
	public static function pageDepth( int $paged ): string {
		if ( $paged <= 1 ) {
			return '1';
		}
		if ( $paged <= 5 ) {
			return '2-5';
		}
		return $paged <= 20 ? '6-20' : '21+';
	}

	/**
	 * Classify a referer.
	 *
	 * @param string $referer  Referer header value.
	 * @param string $own_host  Host of this site.
	 * @return string One of none, own, search, social, ai, other.
	 */
	public static function refererType( string $referer, string $own_host ): string {
		if ( '' === $referer ) {
			return 'none';
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.parse_url_parse_url -- No WordPress in unit tests; malformed referers must not warn.
		$host = @parse_url( $referer, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return 'other';
		}
		$host = self::normaliseHost( $host );
		$own  = self::normaliseHost( $own_host );
		if ( '' === $host ) {
			return 'other';
		}

		if ( '' !== $own && self::matches( $host, $own ) ) {
			return 'own';
		}
		if ( self::matchesAny( $host, self::AI ) ) {
			return 'ai';
		}
		if ( self::matchesAny( $host, self::SEARCH ) ) {
			return 'search';
		}
		if ( self::matchesAny( $host, self::SOCIAL ) ) {
			return 'social';
		}
		return 'other';
	}

	/**
	 * Lowercase a host and drop a leading `www.`.
	 *
	 * @param string $host Host.
	 * @return string
	 */
	private static function normaliseHost( string $host ): string {
		$host = strtolower( $host );
		return 0 === strncmp( $host, 'www.', 4 ) ? substr( $host, 4 ) : $host;
	}

	/**
	 * Whether the host equals the domain or is a subdomain of it.
	 *
	 * @param string $host   Normalised host.
	 * @param string $domain Domain.
	 * @return bool
	 */
	private static function matches( string $host, string $domain ): bool {
		if ( $host === $domain ) {
			return true;
		}
		$len = strlen( $domain );
		return strlen( $host ) > $len && '.' === $host[ strlen( $host ) - $len - 1 ] && substr( $host, -$len ) === $domain;
	}

	/**
	 * Match a host against a pattern list.
	 *
	 * @param string   $host     Normalised host.
	 * @param string[] $patterns Domains, or labels ending in a dot.
	 * @return bool
	 */
	private static function matchesAny( string $host, array $patterns ): bool {
		$labels = null;
		foreach ( $patterns as $pattern ) {
			if ( '.' === substr( $pattern, -1 ) ) {
				if ( null === $labels ) {
					$labels = explode( '.', $host );
				}
				if ( in_array( substr( $pattern, 0, -1 ), $labels, true ) ) {
					return true;
				}
			} elseif ( self::matches( $host, $pattern ) ) {
				return true;
			}
		}
		return false;
	}
}
