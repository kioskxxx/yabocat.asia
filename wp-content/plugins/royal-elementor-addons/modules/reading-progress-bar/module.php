%5+1
%5+1
<?php
namespace WprAddons\Modules\ReadingProgressBar;

use WprAddons\Base\Module_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class Module extends Module_Base {

	public function get_widgets() {
		return [
			'Wpr_Reading_Progress_Bar',
		];
	}

	public function get_name() {
		return 'wpr-reading-progress-bar';
	}
}
