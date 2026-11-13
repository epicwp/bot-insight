<?php
/**
 * Fold-time record enricher.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Fold;

use BotInsight\Bots\Classifier;
use BotInsight\Bots\UaParser;
use BotInsight\Bots\Verifier;
use BotInsight\Geo\GeoLookup;
use BotInsight\Net\ClientIp;
use BotInsight\Storage\Salt;

/**
 * Turns one raw buffer record into an enriched row.
 *
 * The raw IP and raw user agent are consumed here and are not part of the returned row.
 */
final class Enricher {

	/**
	 * User agent parser.
	 *
	 * @var UaParser
	 */
	private $parser;

	/**
	 * Classifier.
	 *
	 * @var Classifier
	 */
	private $classifier;

	/**
	 * Bot verifier.
	 *
	 * @var Verifier
	 */
	private $verifier;

	/**
	 * Daily IP salt.
	 *
	 * @var Salt
	 */
	private $salt;

	/**
	 * Country and network lookup.
	 *
	 * @var GeoLookup
	 */
	private $geo;

	/**
	 * Constructor.
	 *
	 * @param UaParser   $parser     User agent parser.
	 * @param Classifier $classifier Classifier.
	 * @param Verifier   $verifier   Bot verifier.
	 * @param Salt       $salt       Daily IP salt.
	 * @param GeoLookup  $geo        Country and network lookup.
	 */
	public function __construct( UaParser $parser, Classifier $classifier, Verifier $verifier, Salt $salt, GeoLookup $geo ) {
		$this->parser     = $parser;
		$this->classifier = $classifier;
		$this->verifier   = $verifier;
		$this->salt       = $salt;
		$this->geo        = $geo;
	}

	/**
	 * Enrich a decoded buffer record.
	 *
	 * @param array<string,mixed> $record Decoded record (see Record::TYPES).
	 * @return array<string,mixed> Row without `ip` and `ua`, plus the derived fields and `asn_org`.
	 */
	public function enrich( array $record ): array {
		$ua = (string) $record['ua'];
		$ip = (string) $record['ip'];
		$ts = (int) $record['ts'];
		$st = (int) $record['st'];

		$parsed   = $this->parser->parse( $ua );
		$verified = $this->verifier->verify( $parsed['bot'], $ip );
		$class    = $this->classifier->classify( $parsed, (int) $record['hf'], (int) $record['js'], (string) $record['m'], (string) $record['p'], $verified );
		$geo      = $this->geo->lookup( $ip, (string) $record['cc'] );

		$row = $record;
		unset( $row['ua'], $row['ip'] );

		$row['ua_family']    = $parsed['family'];
		$row['ua_outdated']  = $parsed['outdated'];
		$row['bot']          = null === $parsed['bot'] ? '' : (string) $parsed['bot']['id'];
		$row['class']        = $class['class'];
		$row['verified']     = $verified;
		$row['signals']      = $class['signals'];
		$row['ip_hash']      = '' === $ip ? '' : $this->salt->hash( $ip, gmdate( 'Ymd', $ts ) );
		$row['ip_prefix']    = ClientIp::prefix( $ip );
		$row['country']      = $geo['country'];
		$row['asn']          = $geo['asn'];
		$row['asn_org']      = $geo['asn_org'];
		$row['asn_type']     = $geo['asn_type'];
		$row['hour']         = $ts - $ts % 3600;
		$row['status_class'] = ( $st >= 100 && $st <= 599 ) ? ( (int) ( $st / 100 ) ) . 'xx' : 'xxx';

		return $row;
	}
}
