<?php
/**
 * Plugin Name: SearchAgencias Monitor
 * Plugin URI: https://renekay.com
 * Description: Ayuda a SearchAgencias desde WordPress: salud de la app, tandas (buscar / email / envío) e histórico.
 * Version: 1.4.1
 * Author: RENEKAY
 * Author URI: https://renekay.com
 * Text Domain: searchagencias-monitor
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SEARCHAGENCIAS_MONITOR_VERSION', '1.4.1' );
define( 'SEARCHAGENCIAS_MONITOR_FILE', __FILE__ );
define( 'SEARCHAGENCIAS_MONITOR_DIR', plugin_dir_path( __FILE__ ) );
define( 'SEARCHAGENCIAS_MONITOR_URL', plugin_dir_url( __FILE__ ) );

require_once SEARCHAGENCIAS_MONITOR_DIR . 'includes/class-sa-monitor-checker.php';
require_once SEARCHAGENCIAS_MONITOR_DIR . 'includes/class-sa-monitor-recovery.php';
require_once SEARCHAGENCIAS_MONITOR_DIR . 'includes/class-sa-monitor-stats.php';
require_once SEARCHAGENCIAS_MONITOR_DIR . 'includes/class-sa-monitor-keepalive.php';
require_once SEARCHAGENCIAS_MONITOR_DIR . 'includes/class-sa-monitor-cron.php';
require_once SEARCHAGENCIAS_MONITOR_DIR . 'includes/class-sa-monitor-admin.php';
require_once SEARCHAGENCIAS_MONITOR_DIR . 'includes/class-sa-monitor-rest.php';

function searchagencias_monitor_init() {
	SA_Monitor_Cron::register_schedules();
	SA_Monitor_Admin::init();
	SA_Monitor_Rest::init();
}
add_action( 'plugins_loaded', 'searchagencias_monitor_init' );

function searchagencias_monitor_admin_schedule() {
	SA_Monitor_Cron::maybe_schedule();
	SA_Monitor_Cron::maybe_schedule_keepalive();
}
add_action( 'admin_init', 'searchagencias_monitor_admin_schedule' );

register_activation_hook( __FILE__, array( 'SA_Monitor_Cron', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SA_Monitor_Cron', 'deactivate' ) );
