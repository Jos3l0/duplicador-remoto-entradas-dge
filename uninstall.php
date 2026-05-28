<?php
/**
 * Uninstall cleanup.
 *
 * @package EW_Remote_Post_Duplicator
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Preserve post meta and logs by default for auditability. Remove only plugin options.
delete_option( 'ew_rpd_settings' );
