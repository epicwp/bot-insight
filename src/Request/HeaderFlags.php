<?php
/**
 * Request header and cookie flags.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Request;

/**
 * Packs which headers and cookies a request carried into one integer bitmask.
 *
 * Only presence is recorded, never a value (apart from the bi_js cookie being `1`).
 */
final class HeaderFlags {

	public const ACCEPT_LANGUAGE = 1;
	public const SEC_FETCH       = 2;
	public const SEC_CH_UA       = 4;
	public const ACCEPT_HTML     = 8;
	public const ANY_COOKIE      = 16;
	public const CONSENT_COOKIE  = 32;
	public const WOO_SESSION     = 64;
	public const CART_COOKIE     = 128;
	public const LOGGED_IN       = 256;
	public const JS_COOKIE       = 512;

	/**
	 * Consent cookies matched by exact name.
	 */
	private const CONSENT_EXACT = array(
		'cookieyes-consent' => true,
		'CookieConsent'     => true,
		'borlabs-cookie'    => true,
		'moove_gdpr_popup'  => true,
	);

	/**
	 * Consent cookies matched by prefix.
	 */
	private const CONSENT_PREFIX = array( 'cmplz_', 'wp_consent_', 'cookielawinfo-' );

	/**
	 * Build the flag bitmask.
	 *
	 * @param array<string, mixed>     $server  The $_SERVER array.
	 * @param array<int|string, mixed> $cookies The $_COOKIE array.
	 * @return int
	 */
	public static function fromRequest( array $server, array $cookies ): int {
		$flags = 0;

		if ( isset( $server['HTTP_ACCEPT_LANGUAGE'] ) ) {
			$flags |= self::ACCEPT_LANGUAGE;
		}
		if ( isset( $server['HTTP_ACCEPT'] ) && is_string( $server['HTTP_ACCEPT'] )
			&& false !== strpos( $server['HTTP_ACCEPT'], 'text/html' ) ) {
			$flags |= self::ACCEPT_HTML;
		}
		foreach ( $server as $key => $unused ) {
			$key = (string) $key;
			if ( 0 === strncmp( $key, 'HTTP_SEC_', 9 ) ) {
				if ( 0 === strncmp( $key, 'HTTP_SEC_FETCH_', 15 ) ) {
					$flags |= self::SEC_FETCH;
				} elseif ( 0 === strncmp( $key, 'HTTP_SEC_CH_UA', 14 ) ) {
					$flags |= self::SEC_CH_UA;
				}
			}
		}

		if ( array() === $cookies ) {
			return $flags;
		}
		$flags |= self::ANY_COOKIE;

		if ( isset( $cookies['bi_js'] ) && '1' === $cookies['bi_js'] ) {
			$flags |= self::JS_COOKIE;
		}
		if ( isset( $cookies['woocommerce_items_in_cart'] ) || isset( $cookies['woocommerce_cart_hash'] ) ) {
			$flags |= self::CART_COOKIE;
		}

		foreach ( $cookies as $name => $unused ) {
			$name = (string) $name;
			if ( isset( self::CONSENT_EXACT[ $name ] ) ) {
				$flags |= self::CONSENT_COOKIE;
				continue;
			}
			foreach ( self::CONSENT_PREFIX as $prefix ) {
				if ( 0 === strncmp( $name, $prefix, strlen( $prefix ) ) ) {
					$flags |= self::CONSENT_COOKIE;
					continue 2;
				}
			}
			if ( 0 === strncmp( $name, 'wp_woocommerce_session_', 23 ) ) {
				$flags |= self::WOO_SESSION;
			} elseif ( 0 === strncmp( $name, 'wordpress_logged_in_', 20 ) ) {
				$flags |= self::LOGGED_IN;
			}
		}

		return $flags;
	}
}
