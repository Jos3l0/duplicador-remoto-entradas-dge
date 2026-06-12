<?php
/**
 * Plugin Name: Duplicador Remoto de Entradas DGE
 * Plugin URI:  https://www.mendoza.edu.ar/
 * Description: Duplica entradas publicadas hacia otro WordPress mediante REST API, Application Passwords y sincronizacion de medios internos.
 * Version:     1.0.7
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

define( 'EW_RPD_VERSION', '1.0.7' );
define( 'EW_RPD_FILE', __FILE__ );
define( 'EW_RPD_PATH', plugin_dir_path( __FILE__ ) );
define( 'EW_RPD_URL', plugin_dir_url( __FILE__ ) );
define( 'EW_RPD_BASENAME', plugin_basename( __FILE__ ) );
define( 'EW_RPD_OPTION_NAME', 'ew_rpd_settings' );

require_once EW_RPD_PATH . 'includes/class-ew-rpd-plugin.php';

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
ew_rpd_register_forced_post_columns();

/**
 * Add the synchronization status column after the title column.
 *
 * @param array $columns Existing columns.
 * @return array
 */
function ew_rpd_force_sync_status_column( $columns ) {
	if ( ! is_array( $columns ) || isset( $columns['ew_rpd_sync_status'] ) ) {
		return $columns;
	}

	$new_columns = array();
	$inserted    = false;

	foreach ( $columns as $key => $label ) {
		$new_columns[ $key ] = $label;

		if ( 'title' === $key ) {
			$new_columns['ew_rpd_sync_status'] = __( 'Sincronizado', 'ew-remote-post-duplicator' );
			$inserted = true;
		}
	}

	if ( ! $inserted ) {
		$new_columns['ew_rpd_sync_status'] = __( 'Sincronizado', 'ew-remote-post-duplicator' );
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

	if ( 'ew_rpd_sync_status' !== $column ) {
		return;
	}

	$post_id = absint( $post_id );
	$key     = $post_id . ':' . $column;

	if ( isset( $rendered[ $key ] ) ) {
		return;
	}

	$rendered[ $key ] = true;

	$remote_id  = absint( get_post_meta( $post_id, '_ew_rpd_remote_post_id', true ) );
	$status     = sanitize_key( (string) get_post_meta( $post_id, '_ew_rpd_last_sync_status', true ) );
	$error      = (string) get_post_meta( $post_id, '_ew_rpd_last_sync_error', true );
	$remote_url = esc_url( (string) get_post_meta( $post_id, '_ew_rpd_remote_url', true ) );
	$sync_gmt   = (string) get_post_meta( $post_id, '_ew_rpd_last_sync_gmt', true );

	if ( '' === $status && $remote_id > 0 ) {
		$status = 'synced';
	} elseif ( '' === $status ) {
		$status = 'not_synced';
	}

	$label = __( 'No sincronizado', 'ew-remote-post-duplicator' );
	$class = 'not-synced';
	$icon  = 'dashicons-minus';

	if ( 'synced' === $status ) {
		$label = __( 'Sincronizado', 'ew-remote-post-duplicator' );
		$class = 'synced';
		$icon  = 'dashicons-cloud-saved';
	} elseif ( 'error' === $status ) {
		$label = __( 'Error de sincronizacion', 'ew-remote-post-duplicator' );
		$class = 'error';
		$icon  = 'dashicons-warning';
	} elseif ( 'partial' === $status ) {
		$label = __( 'Sincronizacion parcial', 'ew-remote-post-duplicator' );
		$class = 'partial';
		$icon  = 'dashicons-cloud';
	}

	$title_parts = array( $label );

	if ( $remote_id > 0 ) {
		$title_parts[] = sprintf( __( 'ID remoto: %d', 'ew-remote-post-duplicator' ), $remote_id );
	}

	if ( '' !== $sync_gmt ) {
		$title_parts[] = sprintf( __( 'Ultima sincronizacion: %s GMT', 'ew-remote-post-duplicator' ), $sync_gmt );
	}

	if ( '' !== $error ) {
		$title_parts[] = wp_strip_all_tags( $error );
	}

	echo '<div class="ew-rpd-sync-status ew-rpd-sync-status--' . esc_attr( $class ) . '" title="' . esc_attr( implode( ' | ', $title_parts ) ) . '">';
	echo '<span class="dashicons ' . esc_attr( $icon ) . '"></span>';
	echo '<span class="screen-reader-text">' . esc_html( $label ) . '</span>';
	echo '</div>';

	if ( $remote_id > 0 ) {
		echo '<div class="ew-rpd-sync-meta">ID ' . esc_html( (string) $remote_id ) . '</div>';
	}

	if ( '' !== $remote_url ) {
		echo '<div class="ew-rpd-sync-meta"><a href="' . esc_url( $remote_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Ver remoto', 'ew-remote-post-duplicator' ) . '</a></div>';
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
		.fixed .column-ew_rpd_sync_status { width: 120px; text-align: center; }
		.ew-rpd-sync-status .dashicons { font-size: 22px; width: 22px; height: 22px; line-height: 22px; }
		.ew-rpd-sync-status--synced .dashicons { color: #2271b1; }
		.ew-rpd-sync-status--error .dashicons { color: #b32d2e; }
		.ew-rpd-sync-status--partial .dashicons { color: #996800; }
		.ew-rpd-sync-status--not-synced .dashicons { color: #8c8f94; }
		.ew-rpd-sync-meta { margin-top: 3px; font-size: 11px; line-height: 1.2; color: #646970; text-align: center; }
	</style>
	<?php
}


register_activation_hook( __FILE__, array( 'EW_RPD_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'EW_RPD_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'EW_RPD_Plugin', 'instance' ) );
