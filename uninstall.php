<?php
/**
 * Uninstall cleanup.
 *
 * @package EW_Remote_Post_Duplicator
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove plugin options.
delete_option( 'ew_rpd_settings' );

// Remove log directory and all its contents.
$log_dir = WP_CONTENT_DIR . '/ew-rpd-logs';
if ( is_dir( $log_dir ) ) {
	$files = glob( trailingslashit( $log_dir ) . '*' );
	if ( is_array( $files ) ) {
		foreach ( $files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
	}
	rmdir( $log_dir );
}

// Preserve post meta (_ew_rpd_*) for auditability.
