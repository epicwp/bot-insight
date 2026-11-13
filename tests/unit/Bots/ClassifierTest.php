<?php
/**
 * Tests for the classifier.
 *
 * @package BotInsight
 * @license GPL-2.0-or-later
 */

namespace BotInsight\Tests\Unit\Bots;

use BotInsight\Bots\Classifier;
use BotInsight\Request\HeaderFlags;
use PHPUnit\Framework\TestCase;

final class ClassifierTest extends TestCase {

	private function browser( int $outdated = 0 ): array {
		return array(
			'family'   => 'Chrome',
			'outdated' => $outdated,
			'bot'      => null,
			'kind'     => 'browser',
		);
	}

	private function bot( string $class ): array {
		return array(
			'family'   => 'Googlebot',
			'outdated' => 0,
			'bot'      => array(
				'id'    => 'googlebot',
				'name'  => 'Googlebot',
				'class' => $class,
			),
			'kind'     => 'bot',
		);
	}

	public function test_first_visit_of_modern_browser_is_people(): void {
		$flags  = HeaderFlags::SEC_FETCH | HeaderFlags::ACCEPT_LANGUAGE;
		$result = ( new Classifier() )->classify( $this->browser(), $flags, 0, 'GET', '', 0 );
		$this->assertSame( 'people', $result['class'] );
		$this->assertSame( Classifier::SIG_NO_JS, $result['signals'] );
	}

	public function test_old_chrome_without_js_or_sec_fetch_is_suspected(): void {
		$result = ( new Classifier() )->classify( $this->browser( 1 ), HeaderFlags::ACCEPT_LANGUAGE, 0, 'GET', '', 0 );
		$this->assertSame( 'suspected', $result['class'] );
		$this->assertSame( Classifier::SIG_NO_JS | Classifier::SIG_NO_SEC_FETCH | Classifier::SIG_UA_OUTDATED, $result['signals'] );
	}

	public function test_get_add_to_cart_without_js_is_suspected(): void {
		$flags  = HeaderFlags::SEC_FETCH | HeaderFlags::ACCEPT_LANGUAGE;
		$result = ( new Classifier() )->classify( $this->browser(), $flags, 0, 'head', 'add-to-cart,filter_color', 0 );
		$this->assertSame( 'suspected', $result['class'] );
		$this->assertSame( Classifier::SIG_NO_JS | Classifier::SIG_GET_ADD_TO_CART, $result['signals'] );
	}

	public function test_add_to_cart_matches_whole_element_only(): void {
		$flags  = HeaderFlags::SEC_FETCH | HeaderFlags::ACCEPT_LANGUAGE;
		$result = ( new Classifier() )->classify( $this->browser(), $flags, 1, 'GET', 'x-add-to-cart-y', 0 );
		$this->assertSame( 0, $result['signals'] );
		$post = ( new Classifier() )->classify( $this->browser(), $flags, 1, 'POST', 'add-to-cart', 0 );
		$this->assertSame( 0, $post['signals'] );
	}

	public function test_missing_accept_language_is_flagged(): void {
		$result = ( new Classifier() )->classify( $this->browser(), HeaderFlags::SEC_FETCH, 1, 'GET', '', 0 );
		$this->assertSame( Classifier::SIG_NO_ACCEPT_LANG, $result['signals'] );
		$this->assertSame( 'people', $result['class'] );
	}

	public function test_fake_googlebot_is_unknown_bot(): void {
		$result = ( new Classifier() )->classify( $this->bot( 'search' ), 0, 0, 'GET', '', 2 );
		$this->assertSame( 'unknown_bot', $result['class'] );
		$this->assertSame( 0, $result['signals'] );
	}

	public function test_bot_keeps_class_unless_impostor(): void {
		foreach ( array( 0, 1, 3 ) as $verified ) {
			$result = ( new Classifier() )->classify( $this->bot( 'search' ), 0, 0, 'GET', '', $verified );
			$this->assertSame( 'search', $result['class'] );
		}
	}

	public function test_curl_is_library(): void {
		$parsed = array(
			'family'   => 'curl',
			'outdated' => 0,
			'bot'      => null,
			'kind'     => 'library',
		);
		$this->assertSame( 'library', ( new Classifier() )->classify( $parsed, 0, 0, 'GET', '', 0 )['class'] );
	}

	public function test_empty_and_other_are_unknown_bot(): void {
		foreach ( array( 'empty', 'other' ) as $kind ) {
			$parsed = array(
				'family'   => '',
				'outdated' => 0,
				'bot'      => null,
				'kind'     => $kind,
			);
			$this->assertSame( 'unknown_bot', ( new Classifier() )->classify( $parsed, 0, 0, 'GET', '', 0 )['class'] );
		}
	}

	public function test_constants(): void {
		$this->assertSame( 'suspected', Classifier::CLASSES[11] );
		$this->assertSame( 'no_js', Classifier::SIGNALS[ Classifier::SIG_NO_JS ] );
		$this->assertCount( 12, Classifier::CLASSES );
	}
}
