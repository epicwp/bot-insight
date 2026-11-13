<?php
/**
 * The wp-admin dashboard page under Tools.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Admin;

use BotInsight\Plugin;

/**
 * Tools > Bot Insight. The page prints an empty root; `assets/dashboard.js` fills it from the
 * read-only REST report. Measurement only: no actions change the site.
 */
final class DashboardPage {

	public const SLUG   = 'bot-insight';
	public const HANDLE = 'bot-insight-dashboard';

	/**
	 * Absolute path of the main plugin file.
	 *
	 * @var string
	 */
	private static $file = '';

	/**
	 * Hook suffix returned by add_management_page(), '' when the page is not registered.
	 *
	 * @var string
	 */
	private static $hook_suffix = '';

	/**
	 * Hook the menu, the assets and the Plugins screen link.
	 *
	 * @param string $file Absolute path of the main plugin file.
	 */
	public static function hooks( string $file ): void {
		self::$file = $file;
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( $file ), array( self::class, 'actionLinks' ) );
	}

	/**
	 * Add the page under Tools, for administrators only.
	 */
	public static function menu(): void {
		$suffix            = add_management_page(
			__( 'Bot Insight', 'bot-insight' ),
			__( 'Bot Insight', 'bot-insight' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render' )
		);
		self::$hook_suffix = is_string( $suffix ) ? $suffix : '';
	}

	/**
	 * The page's hook suffix, '' before menu() ran or when the user may not see it.
	 */
	public static function hookSuffix(): string {
		return self::$hook_suffix;
	}

	/**
	 * Forget the hook suffix (tests).
	 */
	public static function reset(): void {
		self::$hook_suffix = '';
	}

	/**
	 * Enqueue the script and style on the page itself only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public static function enqueue( $hook_suffix ): void {
		if ( '' === self::$hook_suffix || self::$hook_suffix !== $hook_suffix ) {
			return;
		}
		$file = '' !== self::$file ? self::$file : BOT_INSIGHT_FILE;

		wp_enqueue_style(
			self::HANDLE,
			plugins_url( 'assets/dashboard.css', $file ),
			array(),
			Plugin::VERSION
		);
		wp_enqueue_script(
			self::HANDLE,
			plugins_url( 'assets/dashboard.js', $file ),
			array( 'wp-api-fetch', 'wp-i18n' ),
			Plugin::VERSION,
			true
		);
		wp_set_script_translations( self::HANDLE, 'bot-insight' );
		wp_add_inline_script(
			self::HANDLE,
			'window.botInsightDashboard = ' . wp_json_encode(
				array(
					'restPath' => '/' . RestController::NAMESPACE,
					'version'  => Plugin::VERSION,
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Print the page shell. The script renders into `#bot-insight-app`.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
<div class="wrap bot-insight">
	<h1 class="wp-heading-inline"><?php echo esc_html__( 'Bot Insight', 'bot-insight' ); ?></h1>
	<hr class="wp-header-end">
	<nav class="nav-tab-wrapper bot-insight-tabs" aria-label="<?php echo esc_attr__( 'Bot Insight sections', 'bot-insight' ); ?>">
		<a href="<?php echo esc_url( self::url() ); ?>" class="nav-tab nav-tab-active" aria-current="page"><?php echo esc_html__( 'Dashboard', 'bot-insight' ); ?></a>
	</nav>
	<div id="bot-insight-app" class="bot-insight-app">
		<p class="bot-insight-loading"><?php echo esc_html__( 'Loading…', 'bot-insight' ); ?></p>
		<noscript><p><?php echo esc_html__( 'The Bot Insight dashboard needs JavaScript.', 'bot-insight' ); ?></p></noscript>
	</div>
</div>
		<?php
	}

	/**
	 * Add a "Settings" link to the plugin's row on the Plugins screen.
	 *
	 * @param mixed $links Existing links (an array unless another filter broke it).
	 * @return array<string|int, string>
	 */
	public static function actionLinks( $links ): array {
		$links = is_array( $links ) ? $links : array();
		return array_merge(
			array(
				'settings' => sprintf(
					'<a href="%s">%s</a>',
					esc_url( self::url() ),
					esc_html__( 'Settings', 'bot-insight' )
				),
			),
			$links
		);
	}

	/**
	 * URL of the page.
	 */
	public static function url(): string {
		return admin_url( 'tools.php?page=' . self::SLUG );
	}
}
