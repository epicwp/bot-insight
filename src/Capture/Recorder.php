<?php
/**
 * Per-request recorder.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Capture;

use BotInsight\Buffer\FileBuffer;
use BotInsight\Net\ClientIp;
use BotInsight\Net\IpRanges;
use BotInsight\Plugin;
use BotInsight\Request\HeaderFlags;
use BotInsight\Request\Route;
use BotInsight\Request\Shape;
use BotInsight\Request\UrlPattern;
use BotInsight\Request\WpContext;

/**
 * Builds one record per PHP request at `shutdown` and appends it to the file buffer.
 *
 * Hot path: no database write, no remote call, no output, nothing thrown.
 */
final class Recorder {

	/**
	 * Inline script that marks a browser that runs JavaScript.
	 */
	public const JS = 'if(document.cookie.indexOf("bi_js=1")<0){document.cookie="bi_js=1;path=/;SameSite=Lax"+(location.protocol==="https:"?";Secure":"")}';

	/**
	 * Methods recorded as-is; anything else becomes `OTHER`.
	 */
	private const METHODS = array(
		'GET'     => true,
		'HEAD'    => true,
		'POST'    => true,
		'PUT'     => true,
		'PATCH'   => true,
		'DELETE'  => true,
		'OPTIONS' => true,
	);

	/**
	 * Side effects seen during this request.
	 *
	 * @var array{se:int,ca:int,or:int,pc:int}
	 */
	private static $effects = array(
		'se' => 0,
		'ca' => 0,
		'or' => 0,
		'pc' => 0,
	);

	/**
	 * Hook the recorder in. Runs on `plugins_loaded`.
	 */
	public static function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}
		if ( '' === self::bufferDir() ) {
			return;
		}

		add_action( 'shutdown', array( self::class, 'onShutdown' ), 9999 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueueJs' ) );

		if ( class_exists( 'WooCommerce' ) ) {
			add_action( 'woocommerce_set_cart_cookies', array( self::class, 'onSetCartCookies' ) );
			add_action( 'woocommerce_add_to_cart', array( self::class, 'markCartChanged' ), 10, 0 );
			add_action( 'woocommerce_cart_item_removed', array( self::class, 'markCartChanged' ), 10, 0 );
			add_action( 'woocommerce_cart_item_set_quantity', array( self::class, 'markCartChanged' ), 10, 0 );
			add_action( 'woocommerce_checkout_order_created', array( self::class, 'markOrderCreated' ), 10, 0 );
			add_action( 'woocommerce_new_order', array( self::class, 'markOrderCreated' ), 10, 0 );
			add_action( 'woocommerce_api_request', array( self::class, 'markPaymentCallback' ), 10, 0 );
		}
	}

	/**
	 * WooCommerce set its cart cookies; a session was created if the request carried none.
	 *
	 * @param mixed $set Whether the cookies are being set (true) or cleared.
	 */
	public static function onSetCartCookies( $set = true ): void {
		if ( ! $set ) {
			return;
		}
		foreach ( $_COOKIE as $name => $unused ) {
			if ( 0 === strncmp( (string) $name, 'wp_woocommerce_session_', 23 ) ) {
				return;
			}
		}
		self::$effects['se'] = 1;
	}

	/**
	 * The cart changed.
	 */
	public static function markCartChanged(): void {
		self::$effects['ca'] = 1;
	}

	/**
	 * An order was created.
	 */
	public static function markOrderCreated(): void {
		self::$effects['or'] = 1;
	}

	/**
	 * A WooCommerce API (payment gateway) callback ran.
	 */
	public static function markPaymentCallback(): void {
		self::$effects['pc'] = 1;
	}

	/**
	 * Add the JS-marker cookie script on every front-end page view.
	 *
	 * Not conditional on the request's own `bi_js` cookie: a page cache would store HTML without
	 * the script for every later visitor. The script itself checks the cookie.
	 */
	public static function enqueueJs(): void {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		wp_register_script( 'bot-insight-js', false, array(), Plugin::VERSION, true );
		wp_enqueue_script( 'bot-insight-js' );
		wp_add_inline_script( 'bot-insight-js', self::JS );
	}

	/**
	 * Build and append the record of this request. Never throws, never outputs.
	 */
	public static function onShutdown(): void {
		try {
			$start = microtime( true );
			$cpu   = self::cpuMicros();
			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				return;
			}
			$ua   = isset( $_SERVER['HTTP_USER_AGENT'] ) && is_string( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only compared.
			$home = 0 === strncmp( $ua, 'WordPress/', 10 ) ? (string) wp_parse_url( home_url(), PHP_URL_HOST ) : '';
			if ( self::isOwnBackgroundWork( wp_doing_cron(), self::ajaxAction(), $ua, $home ) ) {
				return;
			}
			$settings = Plugin::settings();
			if ( ! empty( $settings['exclude_admins'] ) && function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) {
				return;
			}
			$dir = self::bufferDir();
			if ( '' === $dir ) {
				return;
			}

			global $wpdb;

			$now     = time();
			$status  = http_response_code();
			$metrics = array(
				'ts'  => $now,
				'ms'  => isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? ( $start - (float) $_SERVER['REQUEST_TIME_FLOAT'] ) * 1000 : 0.0,
				'cpu' => Plugin::cpuStart() > 0 ? max( 0, $cpu - Plugin::cpuStart() ) / 1000 : 0.0,
				'mem' => (int) ( memory_get_peak_usage( true ) / 1024 ),
				'dbq' => is_object( $wpdb ) && isset( $wpdb->num_queries ) ? (int) $wpdb->num_queries : 0,
				'st'  => false === $status ? 200 : (int) $status,
			) + self::$effects;

			$override = get_option( 'bot_insight_ranges_override', '' );
			$record   = self::buildRecord(
				$_SERVER,
				$_COOKIE,
				$_GET, // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only parameter names are kept.
				WpContext::collect(),
				$metrics,
				new ClientIp( new IpRanges( dirname( __DIR__, 2 ) . '/data/ranges', is_string( $override ) && '' !== $override ? $override : null ) ),
				self::langs()
			);

			$record['self'] = 0;
			$line           = Record::encode( $record );
			// `self` is the CPU time (user + system) of collect, build and encode, so a busy host that
			// preempts the process does not inflate it; it excludes the file write.
			$line = Record::withSelf( $line, $cpu > 0 ? max( 0, self::cpuMicros() - $cpu ) : 0 );

			( new FileBuffer( $dir ) )->append( $line, $now );
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Whether a request is the site's own background work, which is not recorded: WP-Cron, the
	 * Action Scheduler async runner, and loopback requests whose user agent is WordPress's own
	 * (`WordPress/<version>; <url>`) with this site's host. Pure.
	 *
	 * @param bool   $doing_cron  Whether this is a WP-Cron request.
	 * @param string $ajax_action The admin-ajax action, '' when this is not an admin-ajax request.
	 * @param string $ua          Raw user agent.
	 * @param string $home_host   Host of the site's home URL ('' skips the loopback check).
	 * @return bool
	 */
	public static function isOwnBackgroundWork( bool $doing_cron, string $ajax_action, string $ua, string $home_host ): bool {
		if ( $doing_cron || 'as_async_request_queue_runner' === $ajax_action ) {
			return true;
		}
		if ( '' === $home_host || 0 !== strncmp( $ua, 'WordPress/', 10 ) ) {
			return false;
		}
		return 1 === preg_match( '#^WordPress/[^;]*;\s*https?://([^/:\s;]+)#i', $ua, $m ) && 0 === strcasecmp( $m[1], $home_host );
	}

	/**
	 * Build a record from request data. Pure: no WordPress, no globals.
	 *
	 * @param array<string, mixed>     $server  $_SERVER-like array.
	 * @param array<int|string, mixed> $cookies $_COOKIE-like array.
	 * @param array<int|string, mixed> $get     $_GET-like array.
	 * @param array<string, mixed>     $ctx     Context from `WpContext::collect()`.
	 * @param array<string, mixed>     $metrics ts, ms, cpu, mem, dbq, st and the side-effect flags se, ca, or, pc.
	 * @param ClientIp                 $client  Client IP resolver.
	 * @param array<string, mixed>     $langs   `slugs` and `keep` string lists.
	 * @return array<string, mixed> Record with `self` set to 0.
	 */
	public static function buildRecord( array $server, array $cookies, array $get, array $ctx, array $metrics, ClientIp $client, array $langs ): array {
		$slugs = self::strings( $langs['slugs'] ?? array() );
		$keep  = array_merge( $slugs, self::strings( $langs['keep'] ?? array() ) );

		if ( isset( $ctx['path'] ) && is_string( $ctx['path'] ) ) {
			$path = $ctx['path'];
		} else {
			$uri  = self::str( $server, 'REQUEST_URI' );
			$path = substr( $uri, 0, strcspn( $uri, '?#' ) );
		}

		$host = strtolower( self::str( $server, 'HTTP_HOST' ) );
		$host = (string) preg_replace( '/:\d*$/', '', $host );

		$cc = self::str( $server, 'HTTP_CF_IPCOUNTRY' );
		if ( 1 !== preg_match( '/^[A-Z]{2}$/', $cc ) ) {
			$cc = '';
		}

		$method = strtoupper( self::str( $server, 'REQUEST_METHOD' ) );
		if ( '' === $method ) {
			$method = 'GET';
		} elseif ( ! isset( self::METHODS[ $method ] ) ) {
			$method = 'OTHER';
		}

		$ip = $client->resolve( $server );

		return array(
			'ts'   => (int) ( $metrics['ts'] ?? 0 ),
			'ms'   => round( (float) ( $metrics['ms'] ?? 0 ), 3 ),
			'cpu'  => round( (float) ( $metrics['cpu'] ?? 0 ), 3 ),
			'mem'  => (int) ( $metrics['mem'] ?? 0 ),
			'dbq'  => (int) ( $metrics['dbq'] ?? 0 ),
			'st'   => (int) ( $metrics['st'] ?? 200 ),
			'unc'  => Route::uncachedReason( $ctx ),
			'm'    => $method,
			'r'    => Route::resolve( $ctx ),
			'p'    => Shape::paramNames( $get ),
			'dp'   => Shape::pageDepth( (int) ( $ctx['paged'] ?? 0 ) ),
			'lg'   => UrlPattern::lang( $path, $slugs ),
			'u'    => UrlPattern::collapse( $path, $keep ),
			'ref'  => Shape::refererType( self::str( $server, 'HTTP_REFERER' ), $host ),
			'ua'   => substr( self::str( $server, 'HTTP_USER_AGENT' ), 0, Record::MAX_UA ),
			'ip'   => $ip['ip'],
			'ips'  => $ip['source'],
			'cdn'  => $ip['via_cdn'],
			'cc'   => $cc,
			'hf'   => HeaderFlags::fromRequest( $server, $cookies ),
			'js'   => isset( $cookies['bi_js'] ) && '1' === $cookies['bi_js'] ? 1 : 0,
			'se'   => empty( $metrics['se'] ) ? 0 : 1,
			'ca'   => empty( $metrics['ca'] ) ? 0 : 1,
			'or'   => empty( $metrics['or'] ) ? 0 : 1,
			'pc'   => empty( $metrics['pc'] ) ? 0 : 1,
			'self' => 0,
		);
	}

	/**
	 * User plus system CPU time of this process in microseconds, or 0 when unavailable.
	 *
	 * @return int
	 */
	public static function cpuMicros(): int {
		if ( ! function_exists( 'getrusage' ) ) {
			return 0;
		}
		$u = getrusage();
		if ( ! is_array( $u ) ) {
			return 0;
		}
		return (int) ( ( $u['ru_utime.tv_sec'] + $u['ru_stime.tv_sec'] ) * 1000000 + $u['ru_utime.tv_usec'] + $u['ru_stime.tv_usec'] );
	}

	/**
	 * The admin-ajax action of this request, or ''.
	 *
	 * @return string
	 */
	private static function ajaxAction(): string {
		if ( ! wp_doing_ajax() || ! isset( $_REQUEST['action'] ) || ! is_string( $_REQUEST['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only compared.
			return '';
		}
		return $_REQUEST['action']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only compared.
	}

	/**
	 * Buffer directory from the autoloaded option, or ''.
	 *
	 * @return string
	 */
	private static function bufferDir(): string {
		$dir = get_option( 'bot_insight_buffer_dir', '' );
		return is_string( $dir ) ? $dir : '';
	}

	/**
	 * Language slugs and keep-words from the autoloaded option.
	 *
	 * @return array{slugs: string[], keep: string[]}
	 */
	private static function langs(): array {
		$langs = get_option( 'bot_insight_langs', array() );
		$langs = is_array( $langs ) ? $langs : array();
		return array(
			'slugs' => self::strings( $langs['slugs'] ?? array() ),
			'keep'  => self::strings( $langs['keep'] ?? array() ),
		);
	}

	/**
	 * Non-empty strings of a value that should be a list.
	 *
	 * @param mixed $value Candidate list.
	 * @return string[]
	 */
	private static function strings( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $item ) {
			if ( is_string( $item ) && '' !== $item ) {
				$out[] = $item;
			}
		}
		return $out;
	}

	/**
	 * A string value of an array, '' when missing or not a string.
	 *
	 * @param array<string, mixed> $arr Array.
	 * @param string               $key Key.
	 * @return string
	 */
	private static function str( array $arr, string $key ): string {
		return isset( $arr[ $key ] ) && is_string( $arr[ $key ] ) ? $arr[ $key ] : '';
	}
}
