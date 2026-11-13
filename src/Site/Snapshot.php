<?php
/**
 * Daily snapshot of the site's setup.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Site;

use BotInsight\Bots\BotList;
use BotInsight\Net\IpRanges;
use BotInsight\Storage\Schema;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages go to an option, never to HTML. The plugin's own tables (names from Schema::tables()) and read-only order counts; values go through prepare().

/**
 * Collects the facts the advice needs: versions, plugins, caching, CDN, robots.txt, sitemap,
 * languages, order countries (country codes and counts only), load and the plugin's own overhead.
 * Never holds personal data: no emails, names, addresses or order ids.
 */
final class Snapshot {

	/**
	 * Page cache plugins recognised by slug.
	 */
	private const CACHE_PLUGINS = array( 'wp-rocket', 'litespeed-cache', 'w3-total-cache', 'wp-super-cache', 'wp-fastest-cache', 'sg-cachepress' );

	/**
	 * Below this many sample rows the p95 is computed in PHP.
	 */
	private const PHP_P95_LIMIT = 10000;

	/**
	 * Collect the snapshot of a day.
	 *
	 * @param int|null $day Start (UTC) of the day the snapshot describes; default yesterday.
	 * @return array<string, mixed>
	 */
	public static function collect( ?int $day = null ): array {
		if ( null === $day ) {
			$now = time();
			$day = $now - $now % DAY_IN_SECONDS - DAY_IN_SECONDS;
		}
		$day     = $day - $day % DAY_IN_SECONDS;
		$plugins = self::plugins();
		$robots  = self::robotsText();
		$langs   = Langs::detect();
		$theme   = wp_get_theme();
		$maps    = self::robotsSitemaps( $robots );

		return array(
			'wp'                    => (string) get_bloginfo( 'version' ),
			'wc'                    => defined( 'WC_VERSION' ) ? (string) WC_VERSION : '',
			'php'                   => PHP_VERSION,
			'plugins'               => $plugins,
			'theme'                 => array(
				'slug'     => (string) get_stylesheet(),
				'template' => (string) get_template(),
				'version'  => (string) $theme->get( 'Version' ),
			),
			'page_cache'            => self::pageCache( $plugins ),
			'object_cache'          => (bool) wp_using_ext_object_cache(),
			'server'                => isset( $_SERVER['SERVER_SOFTWARE'] ) ? substr( sanitize_text_field( wp_unslash( (string) $_SERVER['SERVER_SOFTWARE'] ) ), 0, 100 ) : '',
			'htaccess'              => is_file( ABSPATH . '.htaccess' ),
			'cdn'                   => self::cdn( $day ),
			'robots'                => array(
				'hash'   => md5( self::normaliseRobots( $robots ) ),
				'groups' => self::parseRobots( $robots ),
			),
			'sitemap'               => array() !== $maps ? $maps[0] : self::coreSitemap(),
			'lang_plugin'           => $langs['plugin'],
			'languages'             => $langs['slugs'],
			'billing_countries_90d' => self::billingCountries( $day + DAY_IN_SECONDS - 90 * DAY_IN_SECONDS ),
			'shipping_countries'    => self::shippingCountries(),
			'load'                  => self::load( $day ),
			'overhead_p95_us'       => self::overheadP95( $day ),
			'bots_version'          => self::botsVersion(),
			'ranges_date'           => self::rangesDate(),
		);
	}

	/**
	 * Parse robots.txt into groups. Consecutive `User-agent` lines form one group; `Allow` and
	 * `Disallow` apply to the current group; rules before any group, comments, `Sitemap` and
	 * unknown lines are ignored. Field names are case-insensitive.
	 *
	 * @param string $txt robots.txt text.
	 * @return array<int, array{agents: string[], allow: string[], disallow: string[]}>
	 */
	public static function parseRobots( string $txt ): array {
		$groups = array();
		$cur    = -1;
		$open   = false; // True while the current group still collects User-agent lines.
		foreach ( self::records( $txt ) as $record ) {
			list( $field, $value ) = $record;
			if ( 'user-agent' === $field ) {
				if ( ! $open ) {
					$groups[] = array(
						'agents'   => array(),
						'allow'    => array(),
						'disallow' => array(),
					);
					$cur      = count( $groups ) - 1;
					$open     = true;
				}
				if ( '' !== $value ) {
					$groups[ $cur ]['agents'][] = $value;
				}
				continue;
			}
			if ( 'sitemap' === $field ) {
				continue;
			}
			$open = false;
			if ( $cur < 0 || '' === $value ) {
				continue;
			}
			if ( 'allow' === $field || 'disallow' === $field ) {
				$groups[ $cur ][ $field ][] = $value;
			}
		}
		return $groups;
	}

	/**
	 * Sitemap URLs listed in robots.txt.
	 *
	 * @param string $txt robots.txt text.
	 * @return string[]
	 */
	public static function robotsSitemaps( string $txt ): array {
		$out = array();
		foreach ( self::records( $txt ) as $record ) {
			if ( 'sitemap' === $record[0] && '' !== $record[1] ) {
				$out[] = $record[1];
			}
		}
		return $out;
	}

	/**
	 * Normalised robots.txt for hashing: unified line endings, lines trimmed, comments and blank
	 * lines dropped. A comment or whitespace edit is not a change.
	 *
	 * @param string $txt robots.txt text.
	 */
	public static function normaliseRobots( string $txt ): string {
		$lines = array();
		foreach ( self::lines( $txt ) as $line ) {
			$hash = strpos( $line, '#' );
			if ( false !== $hash ) {
				$line = substr( $line, 0, $hash );
			}
			$line = trim( $line );
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}
		return implode( "\n", $lines );
	}

	/**
	 * The site's robots.txt, read in-process: the physical file when there is one, else the
	 * output of WordPress's virtual robots.txt.
	 */
	public static function robotsText(): string {
		$file = ABSPATH . 'robots.txt';
		if ( is_file( $file ) && is_readable( $file ) ) {
			$txt = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
			return is_string( $txt ) ? substr( $txt, 0, 512000 ) : '';
		}
		if ( ! function_exists( 'do_robots' ) ) {
			return '';
		}
		ob_start();
		try {
			@do_robots(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- do_robots() sends a Content-Type header, which warns when headers are already out.
		} finally {
			$txt = (string) ob_get_clean();
		}
		return substr( $txt, 0, 512000 );
	}

	/**
	 * Field/value pairs of robots.txt, comments removed, field names lowercased.
	 *
	 * @param string $txt robots.txt text.
	 * @return array<int, array{0: string, 1: string}>
	 */
	private static function records( string $txt ): array {
		$out = array();
		foreach ( self::lines( $txt ) as $line ) {
			$hash = strpos( $line, '#' );
			if ( false !== $hash ) {
				$line = substr( $line, 0, $hash );
			}
			$colon = strpos( $line, ':' );
			if ( false === $colon ) {
				continue;
			}
			$field = strtolower( trim( substr( $line, 0, $colon ) ) );
			if ( '' === $field ) {
				continue;
			}
			$out[] = array( $field, trim( substr( $line, $colon + 1 ) ) );
		}
		return $out;
	}

	/**
	 * Lines of a text, BOM stripped.
	 *
	 * @param string $txt Text.
	 * @return string[]
	 */
	private static function lines( string $txt ): array {
		if ( 0 === strpos( $txt, "\xEF\xBB\xBF" ) ) {
			$txt = substr( $txt, 3 );
		}
		$lines = preg_split( '/\r\n|\r|\n/', $txt );
		return is_array( $lines ) ? $lines : array();
	}

	/**
	 * The core sitemap URL when core sitemaps are enabled, else ''.
	 */
	private static function coreSitemap(): string {
		if ( function_exists( 'wp_sitemaps_get_server' ) ) {
			$server = wp_sitemaps_get_server();
			if ( $server->sitemaps_enabled() ) {
				return home_url( '/wp-sitemap.xml' );
			}
		}
		return '';
	}

	/**
	 * Active plugins (site and network), slug => version.
	 *
	 * @return array<string, string>
	 */
	private static function plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all    = get_plugins();
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$out = array();
		foreach ( $active as $file ) {
			if ( is_string( $file ) && '' !== $file ) {
				$out[ Events::pluginSlug( $file ) ] = (string) ( $all[ $file ]['Version'] ?? '' );
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Page cache: whether the advanced-cache drop-in is active, and a known cache plugin if any.
	 *
	 * @param array<string, string> $plugins Active plugins.
	 * @return array{dropin: bool, plugin: string}
	 */
	private static function pageCache( array $plugins ): array {
		$plugin = '';
		foreach ( self::CACHE_PLUGINS as $slug ) {
			if ( isset( $plugins[ $slug ] ) ) {
				$plugin = $slug;
				break;
			}
		}
		return array(
			'dropin' => defined( 'WP_CACHE' ) && WP_CACHE && is_file( WP_CONTENT_DIR . '/advanced-cache.php' ),
			'plugin' => $plugin,
		);
	}

	/**
	 * `cloudflare` when any hourly row of the day came through the CDN, else `none`.
	 *
	 * @param int $day Day start.
	 */
	private static function cdn( int $day ): string {
		global $wpdb;
		$table = Schema::tables()['hourly'];
		$hit   = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE hour >= %d AND hour < %d AND via_cdn = 1 LIMIT 1", $day, $day + DAY_IN_SECONDS ) );
		return null === $hit ? 'none' : 'cloudflare';
	}

	/**
	 * Orders per billing country since a time: country code => count. Nothing else is read.
	 *
	 * @param int $since Unix time.
	 * @return array<string, int>
	 */
	private static function billingCountries( int $since ): array {
		global $wpdb;
		$date = gmdate( 'Y-m-d H:i:s', $since );
		if ( self::hposEnabled() ) {
			$addresses = $wpdb->prefix . 'wc_order_addresses';
			$orders    = $wpdb->prefix . 'wc_orders';
			$rows      = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT a.country AS country, COUNT(*) AS n FROM {$addresses} a JOIN {$orders} o ON o.id = a.order_id WHERE a.address_type = 'billing' AND o.type = 'shop_order' AND o.date_created_gmt > %s GROUP BY a.country",
					$date
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT m.meta_value AS country, COUNT(*) AS n FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_billing_country' WHERE p.post_type = 'shop_order' AND p.post_date_gmt > %s GROUP BY m.meta_value",
					$date
				),
				ARRAY_A
			);
		}
		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$country = strtoupper( (string) $row['country'] );
			if ( 1 === preg_match( '/^[A-Z]{2}$/', $country ) ) {
				$out[ $country ] = ( $out[ $country ] ?? 0 ) + (int) $row['n'];
			}
		}
		arsort( $out );
		return $out;
	}

	/**
	 * Whether WooCommerce stores orders in its own tables (HPOS).
	 */
	private static function hposEnabled(): bool {
		$check = array( 'Automattic\\WooCommerce\\Utilities\\OrderUtil', 'custom_orders_table_usage_is_enabled' );
		return class_exists( $check[0] ) && is_callable( $check ) && (bool) call_user_func( $check );
	}

	/**
	 * WooCommerce shipping scope: mode and, when limited, the country codes.
	 *
	 * @return array{mode: string, countries: string[]}
	 */
	private static function shippingCountries(): array {
		if ( ! defined( 'WC_VERSION' ) ) {
			return array(
				'mode'      => 'none',
				'countries' => array(),
			);
		}
		$ship = (string) get_option( 'woocommerce_ship_to_countries', '' );
		if ( 'disabled' === $ship ) {
			$mode = 'disabled';
			$list = array();
		} elseif ( 'specific' === $ship ) {
			$mode = 'specific';
			$list = get_option( 'woocommerce_specific_ship_to_countries', array() );
		} else {
			$allowed = (string) get_option( 'woocommerce_allowed_countries', 'all' );
			if ( 'specific' === $allowed ) {
				$mode = 'specific';
				$list = get_option( 'woocommerce_specific_allowed_countries', array() );
			} elseif ( 'all_except' === $allowed ) {
				$mode = 'all_except';
				$list = get_option( 'woocommerce_all_except_countries', array() );
			} else {
				$mode = 'all';
				$list = array();
			}
		}
		$countries = array();
		foreach ( is_array( $list ) ? $list : array() as $code ) {
			if ( is_string( $code ) && 1 === preg_match( '/^[A-Z]{2}$/', $code ) ) {
				$countries[] = $code;
			}
		}
		return array(
			'mode'      => $mode,
			'countries' => $countries,
		);
	}

	/**
	 * Hourly load samples of the day: `HH` => 1-minute load average.
	 *
	 * @param int $day Day start.
	 * @return array<string, float>
	 */
	private static function load( int $day ): array {
		$samples = get_option( Daily::LOAD_OPTION, array() );
		$out     = array();
		foreach ( is_array( $samples ) ? $samples : array() as $hour => $load ) {
			$hour = (int) $hour;
			if ( $hour >= $day && $hour < $day + DAY_IN_SECONDS ) {
				$out[ gmdate( 'H', $hour ) ] = (float) $load;
			}
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	/**
	 * 95th percentile (nearest rank) of the recorder's own time in the day's sample rows, in µs.
	 *
	 * @param int $day Day start.
	 */
	private static function overheadP95( int $day ): int {
		global $wpdb;
		$table = Schema::tables()['sample'];
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE ts >= %d AND ts < %d", $day, $day + DAY_IN_SECONDS ) );
		if ( 0 === $count ) {
			return 0;
		}
		$rank = (int) ceil( 0.95 * $count ) - 1;
		if ( $count < self::PHP_P95_LIMIT ) {
			$values = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT self_us FROM {$table} WHERE ts >= %d AND ts < %d", $day, $day + DAY_IN_SECONDS ) ) );
			sort( $values );
			return (int) ( $values[ min( $rank, count( $values ) - 1 ) ] ?? 0 );
		}
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT self_us FROM {$table} WHERE ts >= %d AND ts < %d ORDER BY self_us LIMIT 1 OFFSET %d", $day, $day + DAY_IN_SECONDS, $rank ) );
	}

	/**
	 * Version of the bundled bot list.
	 */
	private static function botsVersion(): string {
		try {
			return BotList::fromFile( dirname( __DIR__, 2 ) . '/data/bots.json' )->version();
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * Date of the range lists in use: the newer of the bundled and the refreshed ones.
	 */
	private static function rangesDate(): string {
		$bundled  = IpRanges::fetched( dirname( __DIR__, 2 ) . '/data/ranges' );
		$override = '' === Daily::rangesDir() ? '' : IpRanges::fetched( Daily::rangesDir() );
		return strcmp( $override, $bundled ) > 0 ? $override : $bundled;
	}
}
