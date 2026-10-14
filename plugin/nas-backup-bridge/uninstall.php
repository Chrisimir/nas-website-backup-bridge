<?php
/**
 * Remove options and the private working folder.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$nbb_dir = get_option( 'nbb_storage_dir' );
if ( $nbb_dir && is_dir( $nbb_dir ) && preg_match( '#/(\.nbb-private-|nbb-)[a-z0-9]+$#', $nbb_dir ) ) {
	require_once __DIR__ . '/includes/class-nbb-util.php';
	NBB_Util::rrmdir( $nbb_dir );
}
foreach ( array( 'nbb_secret', 'nbb_excludes', 'nbb_all_tables', 'nbb_last_job', 'nbb_storage_dir' ) as $nbb_opt ) {
	delete_option( $nbb_opt );
}
wp_clear_scheduled_hook( 'nbb_cleanup' );
