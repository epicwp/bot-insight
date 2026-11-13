<?php
/**
 * Site events shown as markers on the dashboard timeline.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Site;

use BotInsight\Storage\Schema;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages go to an option, never to HTML. The plugin's own events table; the name comes from Schema::tables().

/**
 * Records updates, (de)activations, theme switches and changes the daily job detects. Writes only
 * from admin actions and cron, never on a visitor's request.
 */
final class Events {

	public const TYPES = array(
		'plugin_update',
		'theme_update',
		'core_update',
		'plugin_activated',
		'plugin_deactivated',
		'theme_switched',
		'robots_changed',
		'page_cache_changed',
		'cdn_changed',
		'note',
	);

	/**
	 * The same type and subject within this many seconds is stored once.
	 */
	private const DEDUPE_SECONDS = 60;

	private const SUBJECT_MAX = 191;

	private const DETAIL_MAX = 65535;

	/**
	 * Register the WordPress hooks.
	 */
	public static function hooks(): void {
		add_action( 'upgrader_process_complete', array( self::class, 'onUpgrade' ), 10, 2 );
		add_action( 'activated_plugin', array( self::class, 'onActivated' ) );
		add_action( 'deactivated_plugin', array( self::class, 'onDeactivated' ) );
		add_action( 'switch_theme', array( self::class, 'onSwitchTheme' ), 10, 3 );
	}

	/**
	 * Store an event. Unknown types are ignored; a repeat within 60 seconds is dropped. Never throws.
	 *
	 * @param string   $type    One of TYPES.
	 * @param string   $subject Plugin slug, theme slug, `robots.txt`, ... (max 191 characters).
	 * @param string   $detail  Free text, e.g. versions (max 65535 bytes).
	 * @param int|null $ts      Unix time (default now).
	 */
	public static function add( string $type, string $subject, string $detail = '', ?int $ts = null ): void {
		global $wpdb;
		if ( ! in_array( $type, self::TYPES, true ) || ! is_object( $wpdb ) ) {
			return;
		}
		$ts      = null === $ts ? time() : $ts;
		$subject = function_exists( 'mb_substr' ) ? mb_substr( $subject, 0, self::SUBJECT_MAX, 'UTF-8' ) : substr( $subject, 0, self::SUBJECT_MAX );
		$detail  = function_exists( 'mb_strcut' ) ? mb_strcut( $detail, 0, self::DETAIL_MAX, 'UTF-8' ) : substr( $detail, 0, self::DETAIL_MAX );
		$table   = Schema::tables()['events'];

		$seen = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE ts > %d AND ts <= %d AND type = %s AND subject = %s LIMIT 1",
				$ts - self::DEDUPE_SECONDS,
				$ts + self::DEDUPE_SECONDS,
				$type,
				$subject
			)
		);
		if ( null !== $seen ) {
			return;
		}
		$wpdb->insert(
			$table,
			array(
				'ts'      => $ts,
				'type'    => $type,
				'subject' => $subject,
				'detail'  => $detail,
			),
			array( '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * `upgrader_process_complete`: plugin, theme and core updates.
	 *
	 * @param mixed $upgrader Upgrader (unused).
	 * @param mixed $extra    Hook extra: action, type, plugins/plugin, themes/theme.
	 */
	public static function onUpgrade( $upgrader, $extra ): void {
		if ( ! is_array( $extra ) || 'update' !== ( $extra['action'] ?? '' ) ) {
			return;
		}
		$type = (string) ( $extra['type'] ?? '' );
		if ( 'core' === $type ) {
			self::add( 'core_update', 'core', self::coreVersion() );
			return;
		}
		if ( 'plugin' === $type ) {
			$items = isset( $extra['plugins'] ) ? (array) $extra['plugins'] : ( isset( $extra['plugin'] ) ? array( $extra['plugin'] ) : array() );
			foreach ( $items as $file ) {
				if ( is_string( $file ) && '' !== $file ) {
					self::add( 'plugin_update', self::pluginSlug( $file ), self::pluginVersion( $file ) );
				}
			}
			return;
		}
		if ( 'theme' === $type ) {
			$items = isset( $extra['themes'] ) ? (array) $extra['themes'] : ( isset( $extra['theme'] ) ? array( $extra['theme'] ) : array() );
			foreach ( $items as $slug ) {
				if ( is_string( $slug ) && '' !== $slug ) {
					$theme = wp_get_theme( $slug );
					self::add( 'theme_update', $slug, $theme->exists() ? (string) $theme->get( 'Version' ) : '' );
				}
			}
		}
	}

	/**
	 * `activated_plugin`.
	 *
	 * @param mixed $file Plugin file relative to the plugins directory.
	 */
	public static function onActivated( $file ): void {
		if ( is_string( $file ) && '' !== $file ) {
			self::add( 'plugin_activated', self::pluginSlug( $file ), self::pluginVersion( $file ) );
		}
	}

	/**
	 * `deactivated_plugin`.
	 *
	 * @param mixed $file Plugin file relative to the plugins directory.
	 */
	public static function onDeactivated( $file ): void {
		if ( is_string( $file ) && '' !== $file ) {
			self::add( 'plugin_deactivated', self::pluginSlug( $file ), self::pluginVersion( $file ) );
		}
	}

	/**
	 * `switch_theme`.
	 *
	 * @param mixed $name      New theme name.
	 * @param mixed $new_theme New theme.
	 * @param mixed $old_theme Old theme.
	 */
	public static function onSwitchTheme( $name, $new_theme = null, $old_theme = null ): void {
		$slug = $new_theme instanceof \WP_Theme ? $new_theme->get_stylesheet() : (string) get_stylesheet();
		$from = $old_theme instanceof \WP_Theme ? $old_theme->get_stylesheet() : '';
		self::add( 'theme_switched', $slug, '' === $from ? '' : 'from ' . $from );
	}

	/**
	 * Slug of a plugin file: its directory, or the file name for a single-file plugin.
	 *
	 * @param string $file Plugin file relative to the plugins directory.
	 */
	public static function pluginSlug( string $file ): string {
		$dir = dirname( $file );
		return '.' === $dir ? basename( $file, '.php' ) : $dir;
	}

	/**
	 * Version header of a plugin file ('' when unknown).
	 *
	 * @param string $file Plugin file relative to the plugins directory.
	 */
	private static function pluginVersion( string $file ): string {
		if ( ! defined( 'WP_PLUGIN_DIR' ) || false !== strpos( $file, '..' ) ) {
			return '';
		}
		$path = WP_PLUGIN_DIR . '/' . $file;
		if ( ! is_file( $path ) ) {
			return '';
		}
		$data = get_file_data( $path, array( 'Version' => 'Version' ) );
		return (string) ( $data['Version'] ?? '' );
	}

	/**
	 * WordPress version on disk (the global is stale right after a core update).
	 */
	private static function coreVersion(): string {
		$file = ABSPATH . 'wp-includes/version.php';
		if ( ! is_readable( $file ) ) {
			return (string) get_bloginfo( 'version' );
		}
		$read = static function ( string $path ): string {
			$wp_version = '';
			include $path;
			return (string) $wp_version;
		};
		return $read( $file );
	}
}
