%5+1
%5+1
<?php
namespace Elementor\Core\Logger\Items;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class PHP extends File {

	const FORMAT = 'PHP: date [type X times][file::line] message [meta]';

	public function get_name() {
		return 'PHP';
	}
}
