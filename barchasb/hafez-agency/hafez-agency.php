<?php
/**
 * Plugin Name: Hafez Agency
 * Plugin URI:  https://hafez.agency/
 * Description: Customize Website
 * Author:      Hafez Agency
 * Author URI:  https://hafez.agency/
 * Version:     0.0.1
 */

defined( 'ABSPATH' ) || exit;

/**
 * Define Constants
 */
define( 'BJP_NAME', plugin_basename( __FILE__ ) );
define( 'BJP_DIR', plugin_dir_path( __FILE__ ) );
define( 'BJP_URI', plugin_dir_url( __FILE__ ) );
define( 'BJP_ASSETS', trailingslashit( BJP_URI . 'assets' ) );
define( 'BJP_INCS', trailingslashit( BJP_DIR . 'includes' ) );

/**
 * Include Files
 */
$includes = [
    'copyright',
    'custom-post-types',
    'shortcodes',
    'scripts',
    'pipe-openers',
];

foreach ( $includes as $file ) {
    $ext = '.php';
    $file = BJP_INCS . $file . $ext;
    if ( file_exists( $file ) ) {
        require_once wp_normalize_path( $file );
    }
}