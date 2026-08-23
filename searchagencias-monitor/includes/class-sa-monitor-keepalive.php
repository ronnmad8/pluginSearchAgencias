<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SA_Monitor_Keepalive {

	const CRON_HOOK = 'searchagencias_monitor_keepalive';

	public static function run() {
		$settings = SA_Monitor_Checker::get_settings();
		if ( empty( $settings['enabled'] ) || empty( $settings['daily_keepalive_enabled'] ) ) {
			$result = array(
				'ok'      => true,
				'skipped' => true,
				'reason'  => 'keepalive_disabled',
			);
			SA_Monitor_Checker::record_keepalive_attempt( $result );
			return $result;
		}

		$status = SA_Monitor_Recovery::invoke_endpoint_with_retry( $settings, 'plugin-status', array(), 2 );
		if ( empty( $status['ok'] ) ) {
			$watch = SA_Monitor_Recovery::invoke_endpoint_with_retry( $settings, 'cron-watchdog', array(), 2 );
			$result = array(
				'ok'      => ! empty( $watch['ok'] ),
				'action'  => 'cron-watchdog',
				'message' => 'plugin-status falló; watchdog',
				'status'  => $status,
				'watchdog'=> $watch,
			);
			SA_Monitor_Checker::record_keepalive_attempt( $result );
			return $result;
		}

		$body   = is_array( $status['response'] ?? null ) ? $status['response'] : array();
		$action = (string) ( $body['recommended_action'] ?? 'idle' );
		if ( $action === '' || $action === 'idle' ) {
			$result = array(
				'ok'      => true,
				'skipped' => true,
				'reason'  => 'idle',
				'action'  => 'idle',
				'message' => 'Sin trabajo pendiente',
				'plugin_status' => $body,
			);
			SA_Monitor_Checker::record_keepalive_attempt( $result );
			return $result;
		}

		$slice = SA_Monitor_Recovery::invoke_endpoint_with_retry( $settings, $action, array(), 2 );
		$result = array(
			'ok'            => ! empty( $slice['ok'] ) || ! empty( $slice['skipped'] ),
			'action'        => $action,
			'message'       => 'Tanda ' . $action,
			'slice'         => $slice,
			'plugin_status' => $body,
		);
		SA_Monitor_Checker::record_keepalive_attempt( $result );
		return $result;
	}
}
