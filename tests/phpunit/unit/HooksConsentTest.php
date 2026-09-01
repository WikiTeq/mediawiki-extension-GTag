<?php

namespace MediaWiki\Extension\GTag\Tests;

use HashConfig;
use MediaWiki\Extension\GTag\Hooks;
use MediaWiki\Output\OutputPage;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Request\ContentSecurityPolicy;
use MediaWiki\Request\WebRequest;
use MediaWiki\User\User;
use MediaWikiUnitTestCase;
use Skin;

require_once dirname( __DIR__, 3 ) . '/src/Hooks.php';

/**
 * @covers \MediaWiki\Extension\GTag\Hooks
 */
class HooksConsentTest extends MediaWikiUnitTestCase {

	private function newHooks(
		PermissionManager $permissionManager,
		ExtensionRegistry $extensionRegistry
	): Hooks {
		return new Hooks( $permissionManager, $extensionRegistry );
	}

	/**
	 * @param array $configOverrides
	 * @param bool $exempt
	 * @param bool $cookieConsentLoaded
	 * @return array{0:Hooks,1:OutputPage,2:Skin}
	 */
	private function setupPage(
		array $configOverrides,
		bool $exempt = false,
		bool $cookieConsentLoaded = false
	): array {
		$config = new HashConfig( $configOverrides + [
			'GTagAnalyticsId' => 'G-TEST1234',
			'GTagAnonymizeIP' => false,
			'GTagHonorDNT' => false,
			'GTagEnableTCF' => false,
			'GTagTrackSensitivePages' => true,
		] );

		$user = $this->createMock( User::class );
		$request = $this->createMock( WebRequest::class );
		$request->method( 'getHeader' )->willReturn( false );

		$permissionManager = $this->createMock( PermissionManager::class );
		$permissionManager->method( 'userHasRight' )->willReturn( $exempt );

		$extensionRegistry = $this->createMock( ExtensionRegistry::class );
		$extensionRegistry->method( 'isLoaded' )->willReturnCallback(
			static function ( $name ) use ( $cookieConsentLoaded ) {
				return $cookieConsentLoaded && $name === 'CookieConsent';
			}
		);

		$csp = $this->createMock( ContentSecurityPolicy::class );
		$csp->method( 'getNonce' )->willReturn( null );

		$out = $this->createMock( OutputPage::class );
		$out->method( 'getUser' )->willReturn( $user );
		$out->method( 'getConfig' )->willReturn( $config );
		$out->method( 'getRequest' )->willReturn( $request );
		$out->method( 'getCSP' )->willReturn( $csp );

		$skin = $this->createMock( Skin::class );

		return [ $this->newHooks( $permissionManager, $extensionRegistry ), $out, $skin ];
	}

	public static function provideConsentAndTcfCombinations(): array {
		return [
			'CookieConsent, TCF true' => [ true, true ],
			'CookieConsent, TCF false' => [ true, false ],
			'no CookieConsent, TCF false' => [ false, false ],
			'no CookieConsent, TCF true' => [ false, true ],
		];
	}

	/**
	 * @dataProvider provideConsentAndTcfCombinations
	 * @param bool $cookieConsentLoaded
	 * @param bool $enableTCF
	 */
	public function testConsentAndTcfCombinations(
		bool $cookieConsentLoaded,
		bool $enableTCF
	): void {
		[ $hooks, $out, $skin ] = $this->setupPage(
			[ 'GTagEnableTCF' => $enableTCF ],
			false,
			$cookieConsentLoaded
		);

		$out->expects( $this->never() )->method( 'addModules' );
		$out->expects( $this->never() )->method( 'addJsConfigVars' );

		if ( $cookieConsentLoaded ) {
			$out->expects( $this->never() )->method( 'addInlineScript' );
			$out->expects( $this->exactly( 2 ) )->method( 'addScript' )->with(
				$this->callback( static function ( $html ) {
					return is_string( $html )
						&& str_contains( $html, 'type="text/plain"' )
						&& str_contains( $html, 'data-mw-cookieconsent="statistics"' )
						&& !str_contains( $html, 'gtag_enable_tcf_support' );
				} )
			);
		} else {
			$out->expects( $this->once() )->method( 'addScript' );
			if ( $enableTCF ) {
				$out->expects( $this->once() )->method( 'addInlineScript' )->with(
					$this->stringContains( 'gtag_enable_tcf_support' )
				);
			} else {
				$out->expects( $this->once() )->method( 'addInlineScript' )->with(
					$this->logicalNot( $this->stringContains( 'gtag_enable_tcf_support' ) )
				);
			}
		}

		$hooks->onBeforePageDisplay( $out, $skin );
	}

	public function testCookieConsentGtmUsesDataAttributesNotNoscriptSrc() {
		[ $hooks, $out, $skin ] = $this->setupPage(
			[ 'GTagAnalyticsId' => 'GTM-TEST1234' ],
			false,
			true
		);

		$out->expects( $this->never() )->method( 'addInlineScript' );
		$out->expects( $this->once() )->method( 'addScript' )->with(
			$this->callback( static function ( $html ) {
				return is_string( $html )
					&& str_contains( $html, 'type="text/plain"' )
					&& str_contains( $html, 'data-mw-cookieconsent="statistics"' )
					&& str_contains( $html, 'GTM-TEST1234' );
			} )
		);
		$out->expects( $this->once() )->method( 'addHTML' )->with(
			$this->callback( static function ( $html ) {
				return is_string( $html )
					&& str_contains( $html, 'data-mw-src=' )
					&& str_contains( $html, 'data-mw-cookieconsent="statistics"' )
					&& !str_contains( $html, ' src=' );
			} )
		);

		$hooks->onBeforePageDisplay( $out, $skin );
	}

	public function testNoAnalyticsIdDoesNotEmitTags() {
		[ $hooks, $out, $skin ] = $this->setupPage(
			[ 'GTagAnalyticsId' => '' ],
			false,
			true
		);

		$out->expects( $this->never() )->method( 'addScript' );
		$out->expects( $this->never() )->method( 'addInlineScript' );
		$out->expects( $this->never() )->method( 'addHTML' );

		$hooks->onBeforePageDisplay( $out, $skin );
	}
}
