<?php
/**
 * Bundled list of known bots.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Bots;

/**
 * Matches user agents against the bundled bot list. Entries are checked in file order,
 * so more specific bots (Googlebot-Image) must come before general ones (Googlebot).
 */
final class BotList {

	/**
	 * Bot entries in file order.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $bots;

	/**
	 * Bot entries by id.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private $by_id = array();

	/**
	 * Lowercased patterns as [pattern, index into $bots].
	 *
	 * @var array<int,array{0:string,1:int}>
	 */
	private $patterns = array();

	/**
	 * List version.
	 *
	 * @var string
	 */
	private $version;

	/**
	 * Current stable browser majors.
	 *
	 * @var array<string,int>
	 */
	private $majors;

	/**
	 * Constructor.
	 *
	 * @param string                         $version List version.
	 * @param array<int,array<string,mixed>> $bots    Entries.
	 * @param array<string,int>              $majors  Current browser majors.
	 */
	private function __construct( string $version, array $bots, array $majors ) {
		$this->version = $version;
		$this->bots    = $bots;
		$this->majors  = $majors;
		foreach ( $bots as $index => $bot ) {
			$this->by_id[ $bot['id'] ] = $bot;
			foreach ( $bot['patterns'] as $pattern ) {
				$this->patterns[] = array( strtolower( $pattern ), $index );
			}
		}
	}

	/**
	 * Loads the list from a JSON file.
	 *
	 * @param string $path Path of bots.json.
	 * @throws \RuntimeException When the file is missing or invalid.
	 */
	public static function fromFile( string $path ): BotList {
		$json = is_readable( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local bundled file.
		$data = false === $json ? null : json_decode( $json, true );
		if ( ! is_array( $data ) || ! isset( $data['bots'] ) || ! is_array( $data['bots'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
			throw new \RuntimeException( 'Invalid bot list: ' . basename( $path ) );
		}
		$bots = array();
		foreach ( $data['bots'] as $entry ) {
			$verify = isset( $entry['verify'] ) && is_array( $entry['verify'] ) ? $entry['verify'] : array();
			$bots[] = array(
				'id'          => (string) $entry['id'],
				'name'        => (string) $entry['name'],
				'patterns'    => array_map( 'strval', (array) $entry['patterns'] ),
				'class'       => (string) $entry['class'],
				'owner'       => (string) $entry['owner'],
				'verify'      => array(
					'ranges' => isset( $verify['ranges'] ) ? array_map( 'strval', (array) $verify['ranges'] ) : null,
					'dns'    => isset( $verify['dns'] ) ? array_map( 'strval', (array) $verify['dns'] ) : null,
				),
				'findability' => (bool) $entry['findability'],
			);
		}
		$majors = array();
		foreach ( isset( $data['current_majors'] ) && is_array( $data['current_majors'] ) ? $data['current_majors'] : array() as $browser => $major ) {
			$majors[ (string) $browser ] = (int) $major;
		}
		return new self( (string) ( $data['version'] ?? '' ), $bots, $majors );
	}

	/**
	 * Finds the bot a user agent claims to be.
	 *
	 * @param string $ua Raw user agent.
	 * @return array<string,mixed>|null
	 */
	public function match( string $ua ): ?array {
		$ua = strtolower( $ua );
		foreach ( $this->patterns as $pair ) {
			if ( false !== strpos( $ua, $pair[0] ) ) {
				return $this->bots[ $pair[1] ];
			}
		}
		return null;
	}

	/**
	 * Entry by id.
	 *
	 * @param string $id Bot id.
	 * @return array<string,mixed>|null
	 */
	public function get( string $id ): ?array {
		return $this->by_id[ $id ] ?? null;
	}

	/**
	 * All entries in file order.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function all(): array {
		return $this->bots;
	}

	/**
	 * List version, e.g. `2026.11.1`.
	 */
	public function version(): string {
		return $this->version;
	}

	/**
	 * Current stable browser majors, keyed by browser.
	 *
	 * @return array<string,int>
	 */
	public function currentMajors(): array {
		return $this->majors;
	}
}
