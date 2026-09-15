%5+1
%5+1
<?php

namespace Elementor\Modules\Variables\Storage\Exceptions;

use Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class VariablesLimitReached extends Exception {}
