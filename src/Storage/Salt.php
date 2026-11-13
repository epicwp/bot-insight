<?php
/**
 * Daily rotating salt for hashing IP addresses.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Storage;

/**
 * Holds one random salt for the current UTC day. A newer day replaces the stored salt, so the
 * hashes of earlier days can no longer be reproduced.
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
	 * Salt for a day (Ymd, UTC) as 64 hex chars.
	 *
	 * An older day than the stored one gets a fresh random salt that is not stored, so old buffer
	 * data can still be hashed without exposing the stored day's salt.
	 *
	 * @param string $ymd Day in gmdate( 'Ymd' ) format.
	 * @throws \InvalidArgumentException When the day is malformed.
	 */
	public function forDay( string $ymd ): string {
		if ( 1 !== preg_match( '/^\d{8}$/', $ymd ) ) {
			throw new \InvalidArgumentException( 'Day must be in Ymd format.' );
		}
		$stored = ( $this->get_option )( self::OPTION );
		if ( is_array( $stored ) && isset( $stored['day'], $stored['salt'] ) && is_string( $stored['day'] ) && is_string( $stored['salt'] ) ) {
			if ( $stored['day'] === $ymd ) {
				return $stored['salt'];
			}
			if ( strcmp( $ymd, $stored['day'] ) < 0 ) {
				return bin2hex( random_bytes( 32 ) );
			}
		}
		$salt = bin2hex( random_bytes( 32 ) );
		( $this->set_option )(
			self::OPTION,
			array(
				'day'  => $ymd,
				'salt' => $salt,
			)
		);
		return $salt;
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
}
