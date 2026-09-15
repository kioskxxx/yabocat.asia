<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the website, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'yabocat_asia' );

/** Database username */
define( 'DB_USER', 'yabocat_asia' );

/** Database password */
define( 'DB_PASSWORD', 'fEeNcHEKiN' );

/** Database hostname */
define( 'DB_HOST', 'localhost' );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8mb4' );

/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',         '9x}x6B3E9;x$wzH4M?;qHfe*n/nEp?{.4-Zv+}m)~]9[sse@kP^9&=@w3E+MR{Vt' );
define( 'SECURE_AUTH_KEY',  '/j7*u^adH2q0>E*e]13~&. k<p.3xC/~rl_J?gcM*i-s![]^d])QS+p;?DK~-xCo' );
define( 'LOGGED_IN_KEY',    '.Iifh?f9Vs4,RT8%`}~mPD3aW+`DrSAVn-PlkQ^m<mrQTb4,-|2]`+:9u]Cb!{xl' );
define( 'NONCE_KEY',        'PN1/raulC`XT7Q/V`UP9/M-/s%CO/Heq@qklI9#)eaYW[JwAb Pi~/ _1B`Y$@w5' );
define( 'AUTH_SALT',        '^6NfoNV{vqu&:zz2}(5;@=]9]C 2!b%sY|N4YXhUk3PG])(dz>|.9M3?kI-Bb$ $' );
define( 'SECURE_AUTH_SALT', '$$5C*g @5Q|l>_`rq+Q4OMAG_x[bC9%h`Y{=Y22aBkk[p{ C-95Z- AJqxcX0af<' );
define( 'LOGGED_IN_SALT',   ']Bnoh,Tx9{(a^|W<No>&$EYm4zkn6X4t1 ,(DO;@L,3lgDCU$0q0KgOSj?KV%Kbo' );
define( 'NONCE_SALT',       'tA4,Ht6a##%}!.3!nI|EcLp7xrfrb$F[u~<mQFH%?i=w-6Hw_[~vEz^m}o(hLY7f' );

/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 *
 * At the installation time, database tables are created with the specified prefix.
 * Changing this value after WordPress is installed will make your site think
 * it has not been installed.
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/#table-prefix
 */
$table_prefix = 'wp_';

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://developer.wordpress.org/advanced-administration/debug/debug-wordpress/
 */
define( 'WP_DEBUG', false );

/* Add any custom values between this line and the "stop editing" line. */



/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
