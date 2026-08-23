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
		'/api/cron-search-slice',
		'/api/cron-prepare-slice',
		'/api/cron-execute-pending',
		'/api/cron-watchdog',
	) as $path
) {
	if ( strpos( $recovery, $path ) === false ) {
		fwrite( STDERR, "Falta endpoint $path\n" );
		exit( 1 );
	}
}

echo "OK plugin tests v{$const[1]}\n";
exit( 0 );
