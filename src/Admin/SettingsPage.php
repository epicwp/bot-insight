<?php
/**
 * The Settings tab: options, opt-ins, manual notes and delete-all.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Admin;

use BotInsight\Buffer\FileBuffer;
use BotInsight\Fold\Folder;
use BotInsight\Plugin;
use BotInsight\Site\Events;
use BotInsight\Storage\Schema;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- The plugin's own tables; names come from Schema::tables().

/**
 * Second tab of Tools > Bot Insight. Settings go through the Settings API; the two actions that
 * are not settings (delete all data, add a note) are separate admin-post forms.
 */
final class SettingsPage {

	public const OPTION_GROUP = 'bot_insight';

	public const OPTION = 'bot_insight_settings';

	public const NOTE_MAX = 191;

	private const EVENTS_SHOWN = 20;

	/**
	 * Options that hold folded state, deleted by "delete all data". Settings, the secret and the
	 * buffer directory stay.
	 */
	private const STATE_OPTIONS = array(
		'bot_insight_folded_files',
		'bot_insight_fold_failures',
		'bot_insight_fold_status',
		'bot_insight_daily_status',
		'bot_insight_load',
		'bot_insight_last_closed_day',
		'bot_insight_sample_rate',
		'bot_insight_salt',
	);

	/**
	 * Hook the setting, the admin-post actions and the cache reset.
	 */
	public static function hooks(): void {
		add_action( 'admin_init', array( self::class, 'register' ) );
		add_action( 'admin_post_bot_insight_delete_all', array( self::class, 'handleDeleteAll' ) );
		add_action( 'admin_post_bot_insight_add_note', array( self::class, 'handleAddNote' ) );
		add_action( 'update_option_' . self::OPTION, array( Plugin::class, 'resetSettings' ) );
		add_action( 'add_option_' . self::OPTION, array( Plugin::class, 'resetSettings' ) );
	}

	/**
	 * Register the setting and its fields.
	 */
	public static function register(): void {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => Plugin::DEFAULT_SETTINGS,
			)
		);

		add_settings_section( 'bot_insight_general', __( 'Measuring', 'bot-insight' ), '__return_null', self::OPTION_GROUP );
		add_settings_field( 'sample_rate', __( 'Sampling', 'bot-insight' ), array( self::class, 'fieldSampleRate' ), self::OPTION_GROUP, 'bot_insight_general', array( 'label_for' => 'bot-insight-sample-rate' ) );
		add_settings_field( 'exclude_admins', __( 'Logged-in administrators', 'bot-insight' ), array( self::class, 'fieldExcludeAdmins' ), self::OPTION_GROUP, 'bot_insight_general' );
		add_settings_field( 'retention_days', __( 'Keep data for', 'bot-insight' ), array( self::class, 'fieldRetention' ), self::OPTION_GROUP, 'bot_insight_general', array( 'label_for' => 'bot-insight-retention' ) );

		add_settings_section( 'bot_insight_optins', __( 'Optional downloads', 'bot-insight' ), array( self::class, 'optInsIntro' ), self::OPTION_GROUP );
		add_settings_field( 'range_updates', __( 'Refresh crawler IP ranges', 'bot-insight' ), array( self::class, 'fieldRangeUpdates' ), self::OPTION_GROUP, 'bot_insight_optins' );
		add_settings_field( 'geo_updates', __( 'Download the GeoIP databases', 'bot-insight' ), array( self::class, 'fieldGeoUpdates' ), self::OPTION_GROUP, 'bot_insight_optins' );
	}

	/**
	 * Cast every field, drop unknown keys and fill gaps with the defaults.
	 *
	 * Checkboxes are on when present and not empty.
	 *
	 * @param mixed $input Submitted value.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		$in       = is_array( $input ) ? $input : array();
		$defaults = Plugin::DEFAULT_SETTINGS;

		$rate = isset( $in['sample_rate'] ) && is_scalar( $in['sample_rate'] ) ? strtolower( trim( (string) $in['sample_rate'] ) ) : 'auto';
		if ( '' !== $rate && 'auto' !== $rate && is_numeric( $rate ) ) {
			$rate = max( 1, min( 1000, (int) $rate ) );
		} else {
			$rate = $defaults['sample_rate'];
		}

		$days = isset( $in['retention_days'] ) && is_numeric( $in['retention_days'] ) ? (int) $in['retention_days'] : $defaults['retention_days'];

		return array(
			'sample_rate'    => $rate,
			'exclude_admins' => ! empty( $in['exclude_admins'] ),
			'range_updates'  => ! empty( $in['range_updates'] ),
			'geo_updates'    => ! empty( $in['geo_updates'] ),
			'retention_days' => max( 30, min( 730, $days ) ),
		);
	}

	/**
	 * Intro of the opt-in section.
	 */
	public static function optInsIntro(): void {
		echo '<p>' . esc_html__( 'Bot Insight makes no external requests unless you switch one of these on. Nothing about your site, your visitors or your data is sent: each request is a plain download.', 'bot-insight' ) . '</p>';
	}

	/**
	 * Sampling field.
	 */
	public static function fieldSampleRate(): void {
		$value = Plugin::settings()['sample_rate'];
		printf(
			'<input type="text" id="bot-insight-sample-rate" class="small-text" name="%s[sample_rate]" value="%s" /> <p class="description">%s</p>',
			esc_attr( self::OPTION ),
			esc_attr( (string) $value ),
			esc_html__( 'Enter "auto" to let the plugin sample when the site is busy, or a number from 1 to 1000 to record one request in that many.', 'bot-insight' )
		);
	}

	/**
	 * Exclude-admins field.
	 */
	public static function fieldExcludeAdmins(): void {
		self::checkbox( 'exclude_admins', __( 'Do not count requests from logged-in administrators', 'bot-insight' ) );
	}

	/**
	 * Retention field.
	 */
	public static function fieldRetention(): void {
		printf(
			'<input type="number" id="bot-insight-retention" class="small-text" min="30" max="730" step="1" name="%s[retention_days]" value="%d" /> %s',
			esc_attr( self::OPTION ),
			(int) Plugin::settings()['retention_days'],
			esc_html__( 'days (30 to 730)', 'bot-insight' )
		);
	}

	/**
	 * Range-refresh opt-in.
	 */
	public static function fieldRangeUpdates(): void {
		self::checkbox( 'range_updates', __( 'Refresh the crawler IP ranges once a week', 'bot-insight' ) );
		echo '<p class="description">' . esc_html__( 'Fetches the IP lists that Google, Bing, Apple, OpenAI, Perplexity and Cloudflare publish for their crawlers. The requests carry no site data. Without this, the lists that ship with the plugin are used.', 'bot-insight' ) . '</p>';
	}

	/**
	 * GeoIP opt-in with the DB-IP attribution.
	 */
	public static function fieldGeoUpdates(): void {
		self::checkbox( 'geo_updates', __( 'Download the country and network databases once a month', 'bot-insight' ) );
		echo '<p class="description">' . esc_html__( 'Downloads the DB-IP Lite country and ASN databases from download.db-ip.com, licensed under CC BY 4.0. The request carries no site data. Without this, countries come only from the Cloudflare country header.', 'bot-insight' ) . '</p>';
		echo '<p class="description"><a href="https://db-ip.com" target="_blank" rel="noopener noreferrer">' . esc_html__( 'IP geolocation by DB-IP', 'bot-insight' ) . '</a></p>';
	}

	/**
	 * Print a checkbox bound to a boolean setting.
	 *
	 * @param string $key   Setting key.
	 * @param string $label Label text.
	 */
	private static function checkbox( string $key, string $label ): void {
		printf(
			'<label><input type="checkbox" name="%s[%s]" value="1"%s /> %s</label>',
			esc_attr( self::OPTION ),
			esc_attr( $key ),
			checked( ! empty( Plugin::settings()[ $key ] ), true, false ),
			esc_html( $label )
		);
	}

	/**
	 * Print the tab body (inside the page wrapper).
	 */
	public static function renderTab(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::notice();
		settings_errors();
		?>
	<form method="post" action="options.php" class="bot-insight-settings">
		<?php
		settings_fields( self::OPTION_GROUP );
		do_settings_sections( self::OPTION_GROUP );
		submit_button();
		?>
	</form>

	<h2><?php echo esc_html__( 'Notes', 'bot-insight' ); ?></h2>
	<p><?php echo esc_html__( 'A note shows as a marker on the dashboard timeline, for example "changed hosting".', 'bot-insight' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="bot_insight_add_note">
		<?php wp_nonce_field( 'bot_insight_add_note' ); ?>
		<label class="screen-reader-text" for="bot-insight-note"><?php echo esc_html__( 'Note', 'bot-insight' ); ?></label>
		<input type="text" id="bot-insight-note" name="note" class="regular-text" maxlength="<?php echo (int) self::NOTE_MAX; ?>">
		<?php submit_button( __( 'Add note', 'bot-insight' ), 'secondary', 'submit', false ); ?>
	</form>
		<?php self::renderEvents(); ?>

	<h2><?php echo esc_html__( 'Delete all data', 'bot-insight' ); ?></h2>
	<p><?php echo esc_html__( 'Empties every Bot Insight table and removes the buffer and tracker files, including the downloaded GeoIP and range files. Your settings stay. This cannot be undone.', 'bot-insight' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="bot_insight_delete_all">
		<?php wp_nonce_field( 'bot_insight_delete_all' ); ?>
		<p><label><input type="checkbox" name="confirm" value="1"> <?php echo esc_html__( 'Yes, delete all collected data', 'bot-insight' ); ?></label></p>
		<?php submit_button( __( 'Delete all data', 'bot-insight' ), 'delete', 'submit', false ); ?>
	</form>
		<?php
	}

	/**
	 * Print the notice named in the `bi_notice` query argument (allowlisted).
	 */
	private static function notice(): void {
		$messages = array(
			'deleted' => array( 'success', __( 'All collected data was deleted.', 'bot-insight' ) ),
			'busy'    => array( 'error', __( 'A fold is running, try again in a minute', 'bot-insight' ) ),
			'confirm' => array( 'error', __( 'Nothing was deleted: tick the confirmation box first.', 'bot-insight' ) ),
			'noted'   => array( 'success', __( 'Note added.', 'bot-insight' ) ),
			'empty'   => array( 'error', __( 'The note was empty.', 'bot-insight' ) ),
		);
		$code     = isset( $_GET['bi_notice'] ) && is_string( $_GET['bi_notice'] ) ? sanitize_key( wp_unslash( $_GET['bi_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Picks a fixed message.
		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $messages[ $code ][0] ),
			esc_html( $messages[ $code ][1] )
		);
	}

	/**
	 * Print the last events of all types.
	 */
	private static function renderEvents(): void {
		global $wpdb;
		$table = Schema::tables()['events'];
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT ts, type, subject, detail FROM {$table} ORDER BY ts DESC, id DESC LIMIT %d", self::EVENTS_SHOWN ),
			ARRAY_A
		);
		if ( ! is_array( $rows ) || array() === $rows ) {
			echo '<p>' . esc_html__( 'No events yet.', 'bot-insight' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped bot-insight-events"><thead><tr><th>' . esc_html__( 'When', 'bot-insight' ) . '</th><th>' . esc_html__( 'Type', 'bot-insight' ) . '</th><th>' . esc_html__( 'Subject', 'bot-insight' ) . '</th><th>' . esc_html__( 'Detail', 'bot-insight' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			printf(
				'<tr class="bot-insight-event"><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( (string) wp_date( 'Y-m-d H:i', (int) $row['ts'] ) ),
				esc_html( (string) $row['type'] ),
				esc_html( (string) $row['subject'] ),
				esc_html( (string) $row['detail'] )
			);
		}
		echo '</tbody></table>';
	}

	/**
	 * Admin-post: add a manual note event.
	 */
	public static function handleAddNote(): void {
		self::authorize( 'bot_insight_add_note' );
		$raw  = isset( $_POST['note'] ) && is_string( $_POST['note'] ) ? wp_unslash( $_POST['note'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Verified in authorize(); sanitized on the next line.
		$text = trim( sanitize_text_field( $raw ) );
		if ( '' === $text ) {
			self::redirect( 'empty' );
			return;
		}
		Events::add( 'note', $text );
		self::redirect( 'noted' );
	}

	/**
	 * Admin-post: delete all collected data.
	 */
	public static function handleDeleteAll(): void {
		self::authorize( 'bot_insight_delete_all' );
		if ( empty( $_POST['confirm'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in authorize().
			self::redirect( 'confirm' );
			return;
		}
		self::redirect( self::deleteAll() ? 'deleted' : 'busy' );
	}

	/**
	 * Delete every row, buffer file and folded-state option. Needs the fold lock.
	 *
	 * @return bool False when a fold holds the lock (nothing was deleted).
	 */
	public static function deleteAll(): bool {
		global $wpdb;
		$lock = Folder::lockName( $wpdb );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
			return false;
		}
		try {
			foreach ( Schema::tables() as $table ) {
				$wpdb->query( "TRUNCATE TABLE {$table}" );
			}
			$dir = get_option( 'bot_insight_buffer_dir', '' );
			if ( is_string( $dir ) && FileBuffer::isOwnDir( $dir ) ) {
				self::clearDir( rtrim( $dir, '/\\' ) );
			}
			foreach ( self::STATE_OPTIONS as $name ) {
				delete_option( $name );
			}
			self::deleteDnsTransients();
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
		return true;
	}

	/**
	 * Remove the plugin's files from the buffer directory; keep the directory, `index.php` and
	 * `.htaccess`. Symbolic links are removed, never followed.
	 *
	 * @param string $dir Buffer directory.
	 */
	private static function clearDir( string $dir ): void {
		$patterns = array( 'buffer-*.php', 'folding-*.php', 'quarantine-*.php', 'uniq-*.php' );
		foreach ( $patterns as $pattern ) {
			$files = glob( $dir . '/' . $pattern );
			foreach ( is_array( $files ) ? $files : array() as $file ) {
				if ( is_file( $file ) || is_link( $file ) ) {
					@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- The plugin's own buffer files.
				}
			}
		}
		foreach ( array( 'geo', 'ranges' ) as $sub ) {
			self::removeTree( $dir . '/' . $sub );
		}
	}

	/**
	 * Remove a directory tree without following links.
	 *
	 * @param string $path Directory (or link) to remove.
	 */
	private static function removeTree( string $path ): void {
		if ( is_link( $path ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		$names = scandir( $path );
		foreach ( is_array( $names ) ? $names : array() as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$child = $path . '/' . $name;
			if ( is_dir( $child ) && ! is_link( $child ) ) {
				self::removeTree( $child );
			} else {
				@unlink( $child ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
		@rmdir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rmdir_rmdir, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}

	/**
	 * Delete the cached reverse-DNS results.
	 */
	private static function deleteDnsTransients(): void {
		global $wpdb;
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_bot_insight_dns_' ) . '%'
			)
		);
		foreach ( is_array( $names ) ? $names : array() as $name ) {
			delete_transient( substr( (string) $name, strlen( '_transient_' ) ) );
		}
		// Plain options with the prefix (no expiry) and any transient kept in an external object cache.
		$plain = $wpdb->get_col(
			$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'bot_insight_dns_' ) . '%' )
		);
		foreach ( is_array( $plain ) ? $plain : array() as $name ) {
			delete_option( (string) $name );
		}
	}

	/**
	 * Capability and nonce check for an admin-post action. Ends the request with 403 on failure.
	 *
	 * @param string $action Nonce action.
	 */
	private static function authorize( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'bot-insight' ), '', array( 'response' => 403 ) );
		}
		if ( false === check_admin_referer( $action ) ) {
			wp_die( esc_html__( 'The link has expired. Go back and try again.', 'bot-insight' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Redirect back to the settings tab with a notice code and end the request.
	 *
	 * @param string $code Notice code.
	 */
	private static function redirect( string $code ): void {
		wp_safe_redirect( add_query_arg( 'bi_notice', $code, DashboardPage::url( 'settings' ) ) );
		exit;
	}
}
