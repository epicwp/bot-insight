<?php
/**
 * Client classifier.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Bots;

use BotInsight\Request\HeaderFlags;

/**
 * Maps a parsed user agent plus request signals to a client class.
 * Runs at fold time, never during a visitor request.
 */
final class Classifier {

	public const SIG_NO_JS           = 1;
	public const SIG_NO_SEC_FETCH    = 2;
	public const SIG_NO_ACCEPT_LANG  = 4;
	public const SIG_GET_ADD_TO_CART = 8;
	public const SIG_UA_OUTDATED     = 16;

	public const CLASSES = array(
		'people',
		'search',
		'ai_search',
		'ai_assistant',
		'ai_training',
		'seo',
		'social',
		'monitoring',
		'feed',
		'library',
		'unknown_bot',
		'suspected',
	);

	public const SIGNALS = array(
		self::SIG_NO_JS           => 'no_js',
		self::SIG_NO_SEC_FETCH    => 'no_sec_fetch',
		self::SIG_NO_ACCEPT_LANG  => 'no_accept_lang',
		self::SIG_GET_ADD_TO_CART => 'get_add_to_cart',
		self::SIG_UA_OUTDATED     => 'ua_outdated',
	);

	/**
	 * Classify one client.
	 *
	 * @param array<string,mixed> $parsed       Result of UaParser::parse().
	 * @param int                 $header_flags HeaderFlags bitmask.
	 * @param int                 $js           1 when the bi_js cookie was present.
	 * @param string              $method       HTTP method.
	 * @param string              $params       Comma-joined sorted param names.
	 * @param int                 $verified     0 none, 1 verified, 2 impostor, 3 not checked.
	 * @return array{class:string,signals:int}
	 */
	public function classify( array $parsed, int $header_flags, int $js, string $method, string $params, int $verified ): array {
		$kind = $parsed['kind'] ?? 'other';

		if ( is_array( $parsed['bot'] ?? null ) ) {
			$class = 2 === $verified ? 'unknown_bot' : (string) ( $parsed['bot']['class'] ?? 'unknown_bot' );
			return array(
				'class'   => $class,
				'signals' => 0,
			);
		}
		if ( 'library' === $kind ) {
			return array(
				'class'   => 'library',
				'signals' => 0,
			);
		}
		if ( 'browser' !== $kind ) {
			return array(
				'class'   => 'unknown_bot',
				'signals' => 0,
			);
		}

		$signals = 0;
		if ( 0 === $js ) {
			$signals |= self::SIG_NO_JS;
		}
		if ( 0 === ( $header_flags & HeaderFlags::SEC_FETCH ) ) {
			$signals |= self::SIG_NO_SEC_FETCH;
		}
		if ( 0 === ( $header_flags & HeaderFlags::ACCEPT_LANGUAGE ) ) {
			$signals |= self::SIG_NO_ACCEPT_LANG;
		}
		$method = strtoupper( $method );
		if ( ( 'GET' === $method || 'HEAD' === $method ) && in_array( 'add-to-cart', explode( ',', $params ), true ) ) {
			$signals |= self::SIG_GET_ADD_TO_CART;
		}
		if ( ! empty( $parsed['outdated'] ) ) {
			$signals |= self::SIG_UA_OUTDATED;
		}

		$count = 0;
		for ( $bit = 1; $bit <= self::SIG_UA_OUTDATED; $bit <<= 1 ) {
			if ( $signals & $bit ) {
				++$count;
			}
		}

		return array(
			'class'   => $count >= 2 ? 'suspected' : 'people',
			'signals' => $signals,
		);
	}
}
