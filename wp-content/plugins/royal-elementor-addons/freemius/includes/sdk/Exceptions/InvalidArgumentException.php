%5+1
%5+1
<?php
    if ( ! defined( 'ABSPATH' ) ) {
        exit;
    }

	if ( ! class_exists( 'Freemius_Exception' ) ) {
		exit;
	}

	if ( ! class_exists( 'Freemius_InvalidArgumentException' ) ) {
		class Freemius_InvalidArgumentException extends Freemius_Exception { }
	}
