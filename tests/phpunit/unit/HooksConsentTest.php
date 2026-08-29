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
			'GTagRequireCookieConsent' => true,
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
			'CookieConsent, require true, TCF true' => [ true, true, true ],
			'CookieConsent, require true, TCF false' => [ true, true, false ],
			'CookieConsent, require false, TCF false' => [ true, false, false ],
			'CookieConsent, require false, TCF true' => [ true, false, true ],
			'no CookieConsent, require true, TCF false' => [ false, true, false ],
			'no CookieConsent, require true, TCF true' => [ false, true, true ],
			'no CookieConsent, require false, TCF false' => [ false, false, false ],
			'no CookieConsent, require false, TCF true' => [ false, false, true ],
		];
	}

	/**
	 * @dataProvider provideConsentAndTcfCombinations
	 * @param bool $cookieConsentLoaded
	 * @param bool $requireCookieConsent
	 * @param bool $enableTCF
	 */
	public function testConsentAndTcfCombinations(
		bool $cookieConsentLoaded,
		bool $requireCookieConsent,
		bool $enableTCF
	): void {
		$useConsentPath = $requireCookieConsent && $cookieConsentLoaded;
		$expectTcf = $enableTCF && !$cookieConsentLoaded;

		[ $hooks, $out, $skin ] = $this->setupPage(
			[
				'GTagRequireCookieConsent' => $requireCookieConsent,
				'GTagEnableTCF' => $enableTCF,
			],
			false,
			$cookieConsentLoaded
		);

		if ( $useConsentPath ) {
			$out->expects( $this->once() )->method( 'addModules' )->with( 'ext.GTag.consent' );
			$out->expects( $this->once() )->method( 'addJsConfigVars' )->with(
				'wgGTagConsent',
				$this->callback( static function ( $value ) {
					return is_array( $value )
						&& $value['id'] === 'G-TEST1234'
						&& $value['tagType'] === 'G'
						&& empty( $value['enableTCF'] );
				} )
			);
			$out->expects( $this->never() )->method( 'addScript' );
			$out->expects( $this->never() )->method( 'addInlineScript' );
			$out->expects( $this->never() )->method( 'addHTML' );
		} else {
			$out->expects( $this->never() )->method( 'addModules' );
			$out->expects( $this->never() )->method( 'addJsConfigVars' );
			$out->expects( $this->once() )->method( 'addScript' );
			if ( $expectTcf ) {
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

	public function testConsentFlagDoesNotLoadGoogleWithoutAnalyticsId() {
		[ $hooks, $out, $skin ] = $this->setupPage(
			[
				'GTagAnalyticsId' => '',
				'GTagRequireCookieConsent' => true,
			],
			false,
			true
		);

		$out->expects( $this->never() )->method( 'addModules' );
		$out->expects( $this->never() )->method( 'addJsConfigVars' );
		$out->expects( $this->never() )->method( 'addScript' );

		$hooks->onBeforePageDisplay( $out, $skin );
	}
}
