%5+1
%5+1
<?php
namespace WprAddons\Modules\Testimonial;

use WprAddons\Base\Module_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class Module extends Module_Base {

	public function get_widgets() {
		return [
			'Wpr_Testimonial_Carousel',
		];
	}

	public function get_name() {
		return 'wpr-testimonial';
	}
}
