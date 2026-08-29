/**
 * Load Google's tag only after CookieConsent grants statistics.
 *
 * The page HTML is the same for every anonymous user (a small RL module).
 * This script checks the consent cookie and then injects gtag.js / GTM,
 * the same split CodeMirror uses for its library.
 *
 * Cookie name matches Extension:CookieConsent (cookieconsent_consent_statistics).
 */
( function () {
	const CONSENT_COOKIE = 'cookieconsent_consent_statistics';
	const CONSENT_GIVEN = 'given';

	/**
	 * @return {boolean}
	 */
	function hasStatisticsConsent() {
		return mw.cookie.get( CONSENT_COOKIE ) === CONSENT_GIVEN;
	}

	/**
	 * @param {string} code
	 */
	function appendInlineScript( code ) {
		const el = document.createElement( 'script' );
		el.text = code;
		const nonce = mw.config.get( 'wgCSPNonce' );
		if ( nonce ) {
			el.nonce = nonce;
		}
		document.head.appendChild( el );
	}

	/**
	 * @param {string} src
	 */
	function appendExternalScript( src ) {
		const el = document.createElement( 'script' );
		el.src = src;
		el.async = true;
		const nonce = mw.config.get( 'wgCSPNonce' );
		if ( nonce ) {
			el.nonce = nonce;
		}
		document.head.appendChild( el );
	}

	/**
	 * @param {Object} cfg
	 */
	function loadGtag( cfg ) {
		appendExternalScript(
			'https://www.googletagmanager.com/gtag/js?id=' + cfg.id
		);
		const configJson = JSON.stringify( cfg.config || {} );
		appendInlineScript(
			'window.dataLayer = window.dataLayer || [];\n' +
			'function gtag(){dataLayer.push(arguments);}\n' +
			'gtag(\'js\', new Date());\n' +
			'gtag(\'config\', ' + JSON.stringify( cfg.id ) + ', ' + configJson + ');\n'
		);
	}

	/**
	 * @param {string} id
	 */
	function loadGtm( id ) {
		appendInlineScript(
			'(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({\'gtm.start\':' +
			'new Date().getTime(),event:\'gtm.js\'});var f=d.getElementsByTagName(s)[0],' +
			'j=d.createElement(s),dl=l!=\'dataLayer\'?\'&l=\'+l:\'\';j.async=true;j.src=' +
			'\'https://www.googletagmanager.com/gtm.js?id=\'+i+dl;f.parentNode.insertBefore(j,f);' +
			'})(window,document,\'script\',\'dataLayer\',' + JSON.stringify( id ) + ');'
		);
	}

	const cfg = mw.config.get( 'wgGTagConsent' );
	if ( !cfg || !cfg.id ) {
		return;
	}
	if ( !hasStatisticsConsent() ) {
		return;
	}
	if ( cfg.tagType === 'GTM' ) {
		loadGtm( cfg.id );
	} else {
		loadGtag( cfg );
	}
}() );
