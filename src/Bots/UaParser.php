<?php
/**
 * User agent parser.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Bots;

/**
 * Turns a raw user agent into a family label, a kind and an outdated flag.
 * Runs at fold time, never during a visitor request.
 */
final class UaParser {

	private const MAX_BYTES = 512;

	/**
	 * Canonical library tokens, matched case-insensitively as substrings.
	 * HeadlessChrome must be checked before browsers.
	 *
	 * @var array<int,array{0:string,1:string}> [lowercase token, canonical name].
	 */
	private $libraries = array();

	/**
	 * Bot list.
	 *
	 * @var BotList
	 */
	private $bots;

	/**
	 * Current browser majors.
	 *
	 * @var array<string,int>
	 */
	private $majors;

	/**
	 * Constructor.
	 *
	 * @param BotList           $bots   Bot list.
	 * @param array<string,int> $majors Current browser majors, keyed chrome, edge, firefox, safari.
	 */
	public function __construct( BotList $bots, array $majors ) {
		$this->bots   = $bots;
		$this->majors = $majors;
		foreach ( array( 'HeadlessChrome', 'PhantomJS', 'curl', 'Wget', 'python-requests', 'Python-urllib', 'aiohttp', 'httpx', 'Go-http-client', 'Java', 'okhttp', 'axios', 'node-fetch', 'undici', 'GuzzleHttp', 'libwww-perl', 'Scrapy', 'WordPress' ) as $name ) {
			// "Java" needs its slash so app names like JavaFX do not match.
			$this->libraries[] = array( 'Java' === $name ? 'java/' : strtolower( $name ), $name );
		}
	}

	/**
	 * Parses a user agent.
	 *
	 * @param string $ua Raw user agent.
	 * @return array{family:string,outdated:int,bot:?array<string,mixed>,kind:string}
	 */
	public function parse( string $ua ): array {
		$ua = substr( $ua, 0, self::MAX_BYTES );
		if ( '' === $ua ) {
			return $this->result( 'empty', 'empty' );
		}

		$bot = $this->bots->match( $ua );
		if ( null !== $bot ) {
			return array(
				'family'   => $bot['name'],
				'outdated' => 0,
				'bot'      => $bot,
				'kind'     => 'bot',
			);
		}

		$lower = strtolower( $ua );
		foreach ( $this->libraries as $library ) {
			if ( false !== strpos( $lower, $library[0] ) ) {
				return $this->result( 'library', $library[1] );
			}
		}

		if ( preg_match( '#Edg(?:A|iOS)?/(\d+)#', $ua, $m ) ) {
			return $this->browser( 'Edge', (int) $m[1], 'edge', (int) $m[1] );
		}
		if ( preg_match( '#OPR/(\d+)#', $ua, $m ) ) {
			// Opera's own numbering differs from Chrome's, so judge it by the embedded Chrome major.
			$chrome = preg_match( '#Chrome/(\d+)#', $ua, $c ) ? (int) $c[1] : null;
			return $this->browser( 'Opera', (int) $m[1], 'chrome', $chrome );
		}
		if ( preg_match( '#(?:Firefox|FxiOS)/(\d+)#', $ua, $m ) ) {
			return $this->browser( 'Firefox', (int) $m[1], 'firefox', (int) $m[1] );
		}
		if ( preg_match( '#(?:CriOS|Chrome)/(\d+)#', $ua, $m ) ) {
			return $this->browser( 'Chrome', (int) $m[1], 'chrome', (int) $m[1] );
		}
		if ( false !== strpos( $ua, 'Safari/' ) && preg_match( '#Version/(\d+)#', $ua, $m ) ) {
			return $this->browser( 'Safari', (int) $m[1], 'safari', (int) $m[1] );
		}

		// iOS in-app WebViews (Instagram, Facebook, LinkedIn, generic WKWebView apps) have no Version/ token.
		if ( false !== strpos( $ua, 'Mozilla/5.0' ) && false !== strpos( $ua, 'AppleWebKit/' ) && false !== strpos( $ua, 'Mobile/' ) && false === strpos( $ua, 'Version/' ) ) {
			return $this->result( 'browser', 'iOS WebView' );
		}

		return $this->result( 'other', 'other' );
	}

	/**
	 * Builds a browser result.
	 *
	 * @param string   $name       Family name.
	 * @param int      $major      Major shown in the family label.
	 * @param string   $key        Key in the current majors.
	 * @param int|null $compare    Major to compare with the current one, null when unknown.
	 * @return array{family:string,outdated:int,bot:null,kind:string}
	 */
	private function browser( string $name, int $major, string $key, ?int $compare ): array {
		$outdated = 0;
		if ( null !== $compare && isset( $this->majors[ $key ] ) ) {
			$window   = 'safari' === $key ? 2 : 12;
			$outdated = $compare < $this->majors[ $key ] - $window ? 1 : 0;
		}
		return array(
			'family'   => $name . ' ' . $major,
			'outdated' => $outdated,
			'bot'      => null,
			'kind'     => 'browser',
		);
	}

	/**
	 * Builds a non-browser, non-bot result.
	 *
	 * @param string $kind   Kind.
	 * @param string $family Family.
	 * @return array{family:string,outdated:int,bot:null,kind:string}
	 */
	private function result( string $kind, string $family ): array {
		return array(
			'family'   => $family,
			'outdated' => 0,
			'bot'      => null,
			'kind'     => $kind,
		);
	}
}
