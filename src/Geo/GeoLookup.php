<?php
/**
 * Country and network type lookup from the DB-IP Lite databases.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Geo;

/**
 * Resolves an IP to a country, an ASN and a network type. Runs at fold time only, never per request.
 *
 * Any problem (no file, unreadable file, reader exception) yields "no data", never an exception.
 */
final class GeoLookup {

	/**
	 * Keyword groups are checked in this order.
	 */
	private const TYPE_ORDER = array( 'cdn', 'hosting', 'mobile' );

	/**
	 * Path to the country database.
	 *
	 * @var string|null
	 */
	private $country_db;

	/**
	 * Path to the ASN database.
	 *
	 * @var string|null
	 */
	private $asn_db;

	/**
	 * Keywords per network type.
	 *
	 * @var array<string,string[]>
	 */
	private $asn_types;

	/**
	 * Factory returning an object with `get( string $ip )`.
	 *
	 * @var callable|null
	 */
	private $reader_factory;

	/**
	 * Opened readers by path. False means the open failed.
	 *
	 * @var array<string,object|false>
	 */
	private $readers = array();

	/**
	 * Constructor.
	 *
	 * @param string|null         $country_db     Path to the country MMDB.
	 * @param string|null         $asn_db         Path to the ASN MMDB.
	 * @param array<string,mixed> $asn_types      Keywords per type: cdn, hosting, mobile.
	 * @param callable|null       $reader_factory Takes a path, returns an object with get( $ip ).
	 */
	public function __construct( ?string $country_db, ?string $asn_db, array $asn_types, ?callable $reader_factory = null ) {
		$this->country_db     = $country_db;
		$this->asn_db         = $asn_db;
		$this->asn_types      = $asn_types;
		$this->reader_factory = $reader_factory;
	}

	/**
	 * Look an IP up.
	 *
	 * @param string $ip         Client IP.
	 * @param string $cdn_country Country code from a CDN header, or ''.
	 * @return array{country:string,asn:int,asn_org:string,asn_type:string}
	 */
	public function lookup( string $ip, string $cdn_country ): array {
		$country = self::validCountry( $cdn_country );
		if ( '??' === $country ) {
			$record  = $this->read( $this->country_db, $ip );
			$iso     = is_array( $record ) && isset( $record['country']['iso_code'] ) && is_string( $record['country']['iso_code'] )
				? $record['country']['iso_code']
				: '';
			$country = self::validCountry( $iso );
		}

		$asn = 0;
		$org = '';
		$rec = $this->read( $this->asn_db, $ip );
		if ( is_array( $rec ) ) {
			$asn = isset( $rec['autonomous_system_number'] ) ? (int) $rec['autonomous_system_number'] : 0;
			$org = isset( $rec['autonomous_system_organization'] )
				? $rec['autonomous_system_organization']
				: '';
		}

		return array(
			'country'  => $country,
			'asn'      => $asn,
			'asn_org'  => $org,
			'asn_type' => $this->type( $asn, $org ),
		);
	}

	/**
	 * Normalise a country code: two letters, uppercase, not XX or T1.
	 *
	 * @param string $code Raw code.
	 * @return string Code or '??'.
	 */
	private static function validCountry( string $code ): string {
		$code = strtoupper( trim( $code ) );
		if ( 1 !== preg_match( '/^[A-Z]{2}$/', $code ) || 'XX' === $code ) {
			return '??';
		}
		return $code;
	}

	/**
	 * Network type from the ASN organisation name.
	 *
	 * @param int    $asn ASN.
	 * @param string $org Organisation name.
	 * @return string
	 */
	private function type( int $asn, string $org ): string {
		if ( $asn <= 0 && '' === $org ) {
			return 'unknown';
		}
		$haystack = strtolower( $org );
		// The more specific multi-word keywords win over the generic ones inside a group,
		// and across groups ("Akamai Connected Cloud" is hosting, plain "Akamai" is a CDN).
		$best      = '';
		$best_type = '';
		foreach ( self::TYPE_ORDER as $type ) {
			$keywords = $this->asn_types[ $type ] ?? array();
			foreach ( $keywords as $keyword ) {
				$keyword = strtolower( (string) $keyword );
				if ( '' !== $keyword && false !== strpos( $haystack, $keyword ) && strlen( $keyword ) > strlen( $best ) ) {
					$best      = $keyword;
					$best_type = $type;
				}
			}
		}
		return '' !== $best_type ? $best_type : 'residential';
	}

	/**
	 * Read one record, lazily opening the reader.
	 *
	 * @param string|null $path Database path.
	 * @param string      $ip   IP.
	 * @return mixed Record or null.
	 */
	private function read( ?string $path, string $ip ) {
		if ( null === $path || '' === $path ) {
			return null;
		}
		if ( ! array_key_exists( $path, $this->readers ) ) {
			$this->readers[ $path ] = $this->open( $path );
		}
		$reader = $this->readers[ $path ];
		if ( false === $reader ) {
			return null;
		}
		try {
			return $reader->get( $ip );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Open a reader.
	 *
	 * @param string $path Database path.
	 * @return object|false
	 */
	private function open( string $path ) {
		try {
			if ( null === $this->reader_factory ) {
				if ( ! is_readable( $path ) ) {
					return false;
				}
				return new \MaxMind\Db\Reader( $path );
			}
			$reader = ( $this->reader_factory )( $path );
			return is_object( $reader ) ? $reader : false;
		} catch ( \Throwable $e ) {
			return false;
		}
	}
}
