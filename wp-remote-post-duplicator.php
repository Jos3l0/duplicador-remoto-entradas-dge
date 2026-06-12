<?php
/**
 * Plugin Name: Duplicador Remoto de Entradas DGE
 * Plugin URI:  https://www.mendoza.edu.ar/
 * Description: Duplica entradas publicadas hacia otro WordPress mediante REST API, Application Passwords y sincronizacion de medios internos.
 * Version:     1.0.9
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

define( 'EW_RPD_VERSION', '1.0.9' );
define( 'EW_RPD_FILE', __FILE__ );
define( 'EW_RPD_PATH', plugin_dir_path( __FILE__ ) );
define( 'EW_RPD_URL', plugin_dir_url( __FILE__ ) );
define( 'EW_RPD_BASENAME', plugin_basename( __FILE__ ) );
define( 'EW_RPD_OPTION_NAME', 'ew_rpd_settings' );

require_once EW_RPD_PATH . 'includes/class-ew-rpd-plugin.php';

register_activation_hook( __FILE__, array( 'EW_RPD_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'EW_RPD_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'EW_RPD_Plugin', 'instance' ) );

/**
 * Force sync status column registration early for the native post list table.
 * This intentionally runs from the plugin bootstrap, before WP_List_Table builds columns.
 */
function ew_rpd_register_forced_post_columns() {
	add_filter( 'manage_post_posts_columns', 'ew_rpd_force_sync_status_column', 1 );
	add_filter( 'manage_posts_columns', 'ew_rpd_force_sync_status_column', 1 );
	add_action( 'manage_post_posts_custom_column', 'ew_rpd_force_render_sync_status_column', 1, 2 );
	add_action( 'manage_posts_custom_column', 'ew_rpd_force_render_sync_status_column', 1, 2 );
	add_action( 'admin_head-edit.php', 'ew_rpd_force_sync_status_column_css' );
}
add_action( 'plugins_loaded', 'ew_rpd_register_forced_post_columns' );

/**
 * Add the synchronization status column after the title column.
 *
 * @param array $columns Existing columns.
 * @return array
 */
function ew_rpd_force_sync_status_column( $columns ) {
	if ( ! is_array( $columns ) || isset( $columns['ew_rpd_sync'] ) ) {
		return $columns;
	}

	$new_columns = array();
	$inserted    = false;

	foreach ( $columns as $key => $label ) {
		$new_columns[ $key ] = $label;

		if ( 'title' === $key ) {
			$new_columns['ew_rpd_sync'] = __( 'Sincronizado', 'ew-remote-post-duplicator' );
			$inserted = true;
		}
	}

	if ( ! $inserted ) {
		$new_columns['ew_rpd_sync'] = __( 'Sincronizado', 'ew-remote-post-duplicator' );
	}

	return $new_columns;
}

/**
 * Render the synchronization status column.
 *
 * @param string $column  Column key.
 * @param int    $post_id Post ID.
 * @return void
 */
function ew_rpd_force_render_sync_status_column( $column, $post_id ) {
	static $rendered = array();

	if ( 'ew_rpd_sync' !== $column ) {
		return;
	}

	$post_id = absint( $post_id );
	$key     = $post_id . ':' . $column;

	if ( isset( $rendered[ $key ] ) ) {
		return;
	}

	$rendered[ $key ] = true;

	$post = get_post( $post_id );

	if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
		echo '<span class="dashicons dashicons-minus ew-rpd-col-icon ew-rpd-col-gray" title="' . esc_attr__( 'Solo se sincronizan entradas publicadas', 'ew-remote-post-duplicator' ) . '"></span>';
		return;
	}

	$remote_id   = absint( get_post_meta( $post_id, '_ew_rpd_remote_post_id', true ) );
	$remote_url  = (string) get_post_meta( $post_id, '_ew_rpd_remote_url', true );
	$last_sync   = (string) get_post_meta( $post_id, '_ew_rpd_last_sync_gmt', true );
	$last_error  = (string) get_post_meta( $post_id, '_ew_rpd_last_error', true );

	if ( $remote_id > 0 ) {
		$tooltip_parts = array();
		$tooltip_parts[] = sprintf( __( 'ID remoto: %d', 'ew-remote-post-duplicator' ), $remote_id );

		if ( '' !== $last_sync ) {
			$local_date = get_date_from_gmt( $last_sync, 'Y-m-d H:i:s' );
			$tooltip_parts[] = sprintf( __( 'Ultima sincronizacion: %s', 'ew-remote-post-duplicator' ), $local_date );
		}

		$tooltip = implode( ' | ', $tooltip_parts );

		echo '<span class="dashicons dashicons-cloud ew-rpd-col-icon ew-rpd-col-blue" title="' . esc_attr( $tooltip ) . '"></span>';

		if ( '' !== $remote_url ) {
			echo ' <a href="' . esc_url( $remote_url ) . '" target="_blank" rel="noopener noreferrer" class="ew-rpd-col-link" title="' . esc_attr__( 'Ver entrada remota', 'ew-remote-post-duplicator' ) . '"><span class="dashicons dashicons-admin-links"></span></a>';
		}
	} elseif ( '' !== $last_error ) {
		$tooltip = sprintf( __( 'Error: %s', 'ew-remote-post-duplicator' ), $last_error );
		echo '<span class="dashicons dashicons-warning ew-rpd-col-icon ew-rpd-col-orange" title="' . esc_attr( $tooltip ) . '"></span>';
	} else {
		echo '<span class="dashicons dashicons-cloud ew-rpd-col-icon ew-rpd-col-gray" title="' . esc_attr__( 'No sincronizado', 'ew-remote-post-duplicator' ) . '"></span>';
	}
}

/**
 * Minimal CSS for the synchronization status column.
 *
 * @return void
 */
function ew_rpd_force_sync_status_column_css() {
	?>
	<style>
		.fixed .column-ew_rpd_sync { width: 120px; text-align: center; }
		.ew-rpd-col-icon { font-size: 18px; width: 18px; height: 18px; vertical-align: text-bottom; }
		.ew-rpd-col-blue { color: #2271b1; }
		.ew-rpd-col-orange { color: #dba617; }
		.ew-rpd-col-gray { color: #a7aaad; }
		.ew-rpd-col-link { text-decoration: none; vertical-align: text-bottom; }
		.ew-rpd-col-link .dashicons { font-size: 14px; width: 14px; height: 14px; vertical-align: text-bottom; color: #2271b1; }
	</style>
	<?php
}
