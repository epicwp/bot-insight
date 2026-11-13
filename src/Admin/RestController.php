<?php
/**
 * Admin-only REST endpoints for the dashboard and the JSON export.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Admin;

use BotInsight\Fold\Folder;
use BotInsight\Plugin;
use BotInsight\Report\Period;
use BotInsight\Report\Reports;
use BotInsight\Report\Signals;

/**
 * Routes under `bot-insight/v1`. Read-only; every route needs `manage_options`, and core checks
 * the `X-WP-Nonce: wp_rest` header for cookie authentication.
 */
final class RestController {

	public const NAMESPACE = 'bot-insight/v1';

	/**
	 * Hook the route registration.
	 */
	public static function hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'register' ) );
	}

	/**
	 * Register the routes.
	 */
	public static function register(): void {
		$args = array(
			'period' => array(
				'type'              => 'string',
				'enum'              => array_keys( Period::KEYS ),
				'default'           => Period::DEFAULT_KEY,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'sanitize_key',
			),
		);
		register_rest_route(
			self::NAMESPACE,
			'/report',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'report' ),
				'permission_callback' => array( self::class, 'permission' ),
				'args'                => $args,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/export',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'export' ),
				'permission_callback' => array( self::class, 'permission' ),
				'args'                => $args,
			)
		);
	}

	/**
	 * Only administrators.
	 */
	public static function permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * The dashboard data. Folds what is waiting first, so the numbers are current.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function report( \WP_REST_Request $request ): \WP_REST_Response {
		$now = time();
		try {
			// A busy lock makes this a no-op; a failure must never break the report.
			Folder::create()->run( $now, 10 );
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		$period  = Period::fromKey( (string) $request->get_param( 'period' ), $now );
		$reports = Reports::create();
		$status  = get_option( Folder::STATUS_OPTION, array() );
		$status  = is_array( $status ) ? $status : array();
		$buffer  = get_option( 'bot_insight_buffer_dir', '' );
		$cache   = $reports->latestSnapshot()['page_cache'] ?? false;

		return self::respond(
			array(
				'period'           => self::periodData( $period ),
				'totals'           => $reports->totals( $period ),
				'per_day'          => $reports->perDay( $period ),
				'visitors'         => $reports->visitors( $period ),
				'params'           => $reports->params( $period ),
				'countries'        => $reports->unknownCountries( $period ),
				'signals'          => Signals::evaluate( $reports, $period ),
				'signal_breakdown' => $reports->signalsBreakdown( $period ),
				'overhead'         => $reports->overhead(),
				'health'           => array(
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Plain permission check on our own buffer dir.
					'buffer_writable' => is_string( $buffer ) && '' !== $buffer && is_writable( $buffer ),
					'last_fold'       => (int) ( $status['last_run'] ?? 0 ),
					'last_error'      => (string) ( $status['last_error'] ?? '' ),
					'page_cache'      => is_array( $cache ) ? ( ! empty( $cache['dropin'] ) || '' !== (string) ( $cache['plugin'] ?? '' ) ) : (bool) $cache,
				),
				'notes'            => array( 'page_cache_unseen' => true ),
			)
		);
	}

	/**
	 * The hourly rows as a downloadable JSON file.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function export( \WP_REST_Request $request ): \WP_REST_Response {
		$now      = time();
		$period   = Period::fromKey( (string) $request->get_param( 'period' ), $now );
		$response = self::respond(
			array(
				'plugin_version' => Plugin::VERSION,
				'generated_at'   => self::iso( $now ),
				'period'         => $period->key,
				'rows'           => Reports::create()->exportHourly( $period ),
			)
		);
		$response->header(
			'Content-Disposition',
			'attachment; filename="bot-insight-' . $period->key . '-' . wp_date( 'Ymd', $now ) . '.json"'
		);
		return $response;
	}

	/**
	 * A response that no cache may keep.
	 *
	 * @param array<string, mixed> $data Body.
	 */
	private static function respond( array $data ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Period as ISO-8601 UTC strings.
	 *
	 * @param Period $period Period.
	 * @return array{key: string, start: string, end: string}
	 */
	private static function periodData( Period $period ): array {
		return array(
			'key'   => $period->key,
			'start' => self::iso( $period->start ),
			'end'   => self::iso( $period->end ),
		);
	}

	/**
	 * ISO-8601 UTC.
	 *
	 * @param int $ts Unix time.
	 */
	private static function iso( int $ts ): string {
		return gmdate( 'Y-m-d\TH:i:s\Z', $ts );
	}
}
