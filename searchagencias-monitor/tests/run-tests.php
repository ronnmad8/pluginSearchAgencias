<?php
/**
 * Comprueba versión del plugin y el mapa de endpoints.
 */
$root = dirname( __DIR__ );
$main = file_get_contents( $root . '/searchagencias-monitor.php' );
if ( $main === false ) {
	fwrite( STDERR, "No se lee searchagencias-monitor.php\n" );
	exit( 1 );
}
preg_match( '/^\s*\*\s*Version:\s*(.+)$/m', $main, $header );
preg_match( "/define\(\s*'SEARCHAGENCIAS_MONITOR_VERSION'\s*,\s*'([^']+)'/", $main, $const );
if ( ! $header || ! $const || trim( $header[1] ) !== $const[1] ) {
	fwrite( STDERR, "Versión desincronizada\n" );
	exit( 1 );
}

$recovery = file_get_contents( $root . '/includes/class-sa-monitor-recovery.php' );
foreach (
	array(
		'/api/plugin-status',
		'/api/cron-recover',
		'/api/cron-enrich-slice',
		'/api/cron-search-slice',
		'/api/cron-social-slice',
		'/api/cron-prepare-slice',
		'/api/cron-execute-pending',
		'/api/cron-followup-slice',
		'/api/cron-directory-slice',
		'/api/cron-watchdog',
	) as $path
) {
	if ( strpos( $recovery, $path ) === false ) {
		fwrite( STDERR, "Falta endpoint $path\n" );
		exit( 1 );
	}
}

if ( strpos( $recovery, 'x-cron-secret' ) === false ) {
	fwrite( STDERR, "Falta header x-cron-secret hacia la API\n" );
	exit( 1 );
}

$keepalive = file_get_contents( $root . '/includes/class-sa-monitor-keepalive.php' );
if ( $keepalive === false ) {
	fwrite( STDERR, "No se lee class-sa-monitor-keepalive.php\n" );
	exit( 1 );
}
if ( strpos( $keepalive, 'const MAX_ACTIONS_PER_RUN = 4;' ) === false ) {
	fwrite( STDERR, "Keepalive debe permitir varias acciones por ping (MAX_ACTIONS_PER_RUN = 4)\n" );
	exit( 1 );
}
if ( strpos( $keepalive, 'const MAX_SEND_BURST = 2;' ) === false ) {
	fwrite( STDERR, "Keepalive debe permitir ráfaga de envío (MAX_SEND_BURST = 2)\n" );
	exit( 1 );
}
if ( strpos( $keepalive, 'const MAX_SEARCH_BURST = 3;' ) === false ) {
	fwrite( STDERR, "Keepalive debe permitir ráfaga de búsqueda (MAX_SEARCH_BURST = 3)\n" );
	exit( 1 );
}
if ( strpos( $keepalive, 'const MAX_ENRICH_BURST = 2;' ) === false ) {
	fwrite( STDERR, "Keepalive debe permitir ráfaga de enrich (MAX_ENRICH_BURST = 2)\n" );
	exit( 1 );
}

if ( ! is_file( $root . '/includes/class-sa-monitor-stats.php' ) ) {
	fwrite( STDERR, "Falta class-sa-monitor-stats.php\n" );
	exit( 1 );
}
$boot = file_get_contents( $root . '/searchagencias-monitor.php' );
if ( strpos( $boot, "add_action( 'admin_init'" ) === false ) {
	fwrite( STDERR, "El schedule no debe ir en cada plugins_loaded de tienda\n" );
	exit( 1 );
}

echo "OK plugin tests v{$const[1]}\n";
exit( 0 );
