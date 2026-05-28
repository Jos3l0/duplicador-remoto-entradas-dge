<?php
/**
 * Plugin Name: Duplicador Remoto de Entradas DGE
 * Plugin URI:  https://www.mendoza.edu.ar/
 * Description: Duplica entradas publicadas hacia otro WordPress mediante REST API, Application Passwords y sincronizacion de medios internos.
 * Version:     1.0.3
 * Author:      Por Equipo del Portal DGE Gob. de Mendoza
 * Author URI:  https://www.mendoza.edu.ar/
 * Text Domain: ew-remote-post-duplicator
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 *
 * @package EW_Remote_Post_Duplicator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EW_RPD_VERSION', '1.0.3' );
define( 'EW_RPD_FILE', __FILE__ );
define( 'EW_RPD_PATH', plugin_dir_path( __FILE__ ) );
define( 'EW_RPD_URL', plugin_dir_url( __FILE__ ) );
define( 'EW_RPD_BASENAME', plugin_basename( __FILE__ ) );
define( 'EW_RPD_OPTION_NAME', 'ew_rpd_settings' );

require_once EW_RPD_PATH . 'includes/class-ew-rpd-plugin.php';

register_activation_hook( __FILE__, array( 'EW_RPD_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'EW_RPD_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'EW_RPD_Plugin', 'instance' ) );
