<?php
/**
 * Daily rotating salt for hashing IP addresses.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Storage;

/**
 * Holds one random salt for the current UTC day, plus yesterday's until the fold has closed
 * yesterday (late buffer files of yesterday must hash like the earlier ones). A newer day replaces
 * the stored salt; older salts are gone, so the hashes of earlier days can no longer be reproduced.
 */
final class Salt {

	private const OPTION = 'bot_insight_salt';

	/**
	 * Reads an option: function ( string $name ).
	 *
	 * @var callable
	 */
	private $get_option;

	/**
	 * Writes an option: function ( string $name, mixed $value ).
	 *
	 * @var callable
	 */
	private $set_option;

	/**
	 * Create the salt store.
	 *
	 * @param callable $get_option Reads an option by name (get_option).
	 * @param callable $set_option Writes an option by name and value (update_option, autoload no).
	 */
	public function __construct( callable $get_option, callable $set_option ) {
		$this->get_option = $get_option;
		$this->set_option = $set_option;
	}

	/**
	 * The salt store on the WordPress options table.
	 */
	public static function fromOptions(): self {
		return new self(
			'get_option',
			static function ( $name, $value ) {
				return update_option( $name, $value, false );
			}
		);
	}

	/**
	 * Salt for a day (Ymd, UTC) as 64 hex chars.
	 *
	 * The stored day and, until closeDay(), the day before it give their stored salts. A newer day
	 * gets a new salt; the old one is kept as yesterday's only when it is the day before. An older
	 * day gets a fresh random salt that is not stored, so its data can still be hashed without
	 * exposing a stored salt.
	 *
	 * @param string $ymd Day in gmdate( 'Ymd' ) format.
	 * @throws \InvalidArgumentException When the day is malformed.
	 */
	public function forDay( string $ymd ): string {
		self::assertDay( $ymd );
		$stored = $this->stored();
		if ( null !== $stored ) {
			if ( $stored['day'] === $ymd ) {
				return $stored['salt'];
			}
			if ( '' !== $stored['prev_salt'] && $stored['prev_day'] === $ymd ) {
				return $stored['prev_salt'];
			}
			if ( strcmp( $ymd, $stored['day'] ) < 0 ) {
				return bin2hex( random_bytes( 32 ) );
			}
		}
		$keep = null !== $stored && self::dayBefore( $ymd ) === $stored['day'];
		$salt = bin2hex( random_bytes( 32 ) );
		$this->store( $ymd, $salt, $keep ? $stored['day'] : '', $keep ? $stored['salt'] : '' );
		return $salt;
	}

	/**
	 * Forget yesterday's salt once a day up to and including it is closed.
	 *
	 * @param string $ymd Closed day in gmdate( 'Ymd' ) format.
	 * @throws \InvalidArgumentException When the day is malformed.
	 */
	public function closeDay( string $ymd ): void {
		self::assertDay( $ymd );
		$stored = $this->stored();
		if ( null === $stored || '' === $stored['prev_salt'] || strcmp( $stored['prev_day'], $ymd ) > 0 ) {
			return;
		}
		$this->store( $stored['day'], $stored['salt'], '', '' );
	}

	/**
	 * Keyed hash of an IP for a day: first 16 hex chars of HMAC-SHA256.
	 *
	 * @param string $ip  IP address.
	 * @param string $ymd Day in gmdate( 'Ymd' ) format.
	 */
	public function hash( string $ip, string $ymd ): string {
		return substr( hash_hmac( 'sha256', $ip, $this->forDay( $ymd ) ), 0, 16 );
	}

	/**
	 * The stored salts, or null when there are none.
	 *
	 * @return array{day: string, salt: string, prev_day: string, prev_salt: string}|null
	 */
	private function stored(): ?array {
		$value = ( $this->get_option )( self::OPTION );
		if ( ! is_array( $value ) || ! isset( $value['day'], $value['salt'] ) || ! is_string( $value['day'] ) || ! is_string( $value['salt'] ) ) {
			return null;
		}
		$prev_day  = isset( $value['prev_day'] ) && is_string( $value['prev_day'] ) ? $value['prev_day'] : '';
		$prev_salt = isset( $value['prev_salt'] ) && is_string( $value['prev_salt'] ) ? $value['prev_salt'] : '';
		return array(
			'day'       => $value['day'],
			'salt'      => $value['salt'],
			'prev_day'  => '' === $prev_salt ? '' : $prev_day,
			'prev_salt' => '' === $prev_day ? '' : $prev_salt,
		);
	}

	/**
	 * Write the salts.
	 *
	 * @param string $day       Current day.
	 * @param string $salt      Its salt.
	 * @param string $prev_day  Yesterday, or ''.
	 * @param string $prev_salt Yesterday's salt, or ''.
	 */
	private function store( string $day, string $salt, string $prev_day, string $prev_salt ): void {
		( $this->set_option )(
			self::OPTION,
			array(
				'day'       => $day,
				'salt'      => $salt,
				'prev_day'  => $prev_day,
				'prev_salt' => $prev_salt,
			)
		);
	}

	/**
	 * The day before a day (Ymd, UTC).
	 *
	 * @param string $ymd Day.
	 */
	private static function dayBefore( string $ymd ): string {
		$date = \DateTime::createFromFormat( '!Ymd', $ymd, new \DateTimeZone( 'UTC' ) );
		return false === $date ? '' : gmdate( 'Ymd', $date->getTimestamp() - 86400 );
	}

	/**
	 * Reject a malformed day.
	 *
	 * @param string $ymd Day.
	 * @throws \InvalidArgumentException When the day is malformed.
	 */
	private static function assertDay( string $ymd ): void {
		if ( 1 !== preg_match( '/^\d{8}$/', $ymd ) ) {
			throw new \InvalidArgumentException( 'Day must be in Ymd format.' );
		}
	}
}
