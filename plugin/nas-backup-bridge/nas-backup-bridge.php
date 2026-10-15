<?php
/**
 * Plugin Name:       NAS Backup Bridge
 * Description:       Permite que un servidor de copias (NAS) descargue copias completas del sitio —base de datos y archivos— mediante una API firmada. No envía datos a terceros.
 * Version:           1.2.0
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            Chris Lund
 * License:           GPL-2.0-or-later
 * Text Domain:       nas-backup-bridge
 * Plugin URI:        https://github.com/Chrisimir/nas-website-backup-bridge
 * Update URI:        https://github.com/Chrisimir/nas-website-backup-bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NBB_VERSION', '1.2.0' );
define( 'NBB_PROTOCOL', 1 );
define( 'NBB_FILE', __FILE__ );
define( 'NBB_DIR', __DIR__ );

require_once NBB_DIR . '/includes/class-nbb-util.php';
require_once NBB_DIR . '/includes/class-nbb-settings.php';
require_once NBB_DIR . '/includes/class-nbb-auth.php';
require_once NBB_DIR . '/includes/class-nbb-storage.php';
require_once NBB_DIR . '/includes/class-nbb-db-dumper.php';
require_once NBB_DIR . '/includes/class-nbb-scanner.php';
require_once NBB_DIR . '/includes/class-nbb-job.php';
require_once NBB_DIR . '/includes/class-nbb-rest.php';
require_once NBB_DIR . '/includes/class-nbb-restore.php';
require_once NBB_DIR . '/includes/class-nbb-updater.php';
require_once NBB_DIR . '/includes/class-nbb-admin.php';

register_activation_hook( __FILE__, array( 'NBB_Settings', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'NBB_Settings', 'deactivate' ) );

add_action( 'rest_api_init', array( 'NBB_Rest', 'register_routes' ) );
add_action( 'nbb_cleanup', array( 'NBB_Job', 'cleanup_stale' ) );
add_action( 'template_redirect', array( 'NBB_Restore', 'maintenance_gate' ), 0 );
NBB_Updater::init();

if ( is_admin() ) {
	NBB_Admin::init();
}
