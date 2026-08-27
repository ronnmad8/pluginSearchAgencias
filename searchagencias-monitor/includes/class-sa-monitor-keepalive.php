<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SA_Monitor_Keepalive {

	const CRON_HOOK = 'searchagencias_monitor_keepalive';
	const MAX_ACTIONS_PER_RUN = 1;

	public static function run() {
		$settings = SA_Monitor_Checker::get_settings();
		if ( empty( $settings['enabled'] ) || empty( $settings['daily_keepalive_enabled'] ) ) {
			return self::finish(
				array(
					'ok'      => true,
					'skipped' => true,
					'reason'  => 'keepalive_disabled',
				)
			);
		}

		$slices  = array();
		$actions = array();
		$status  = null;

		for ( $i = 0; $i < self::MAX_ACTIONS_PER_RUN; $i++ ) {
			$status = SA_Monitor_Recovery::invoke_endpoint_with_retry( $settings, 'plugin-status', array(), 2 );
			if ( empty( $status['ok'] ) ) {
				if ( $i === 0 ) {
					$watch = SA_Monitor_Recovery::invoke_endpoint_with_retry( $settings, 'cron-watchdog', array(), 2 );
					return self::finish(
						array(
							'ok'       => ! empty( $watch['ok'] ),
							'action'   => 'cron-watchdog',
							'message'  => 'plugin-status falló; watchdog',
							'status'   => $status,
							'watchdog' => $watch,
						)
					);
				}
				break;
			}

			$body   = is_array( $status['response'] ?? null ) ? $status['response'] : array();
			$action = (string) ( $body['recommended_action'] ?? 'idle' );
			if ( $action === '' || $action === 'idle' ) {
				if ( $i === 0 ) {
					return self::finish(
						array(
							'ok'            => true,
							'skipped'       => true,
							'reason'        => 'idle',
							'action'        => 'idle',
							'message'       => 'Sin trabajo pendiente',
							'plugin_status' => $body,
						)
					);
				}
				break;
			}
			if ( in_array( $action, $actions, true ) ) {
				break;
			}

			$slice = SA_Monitor_Recovery::invoke_endpoint_with_retry( $settings, $action, array(), 2 );
			$actions[] = $action;
			$slices[]  = $slice;
		}

		return self::finish(
			array(
				'ok'            => ! empty( $slices ),
				'action'        => implode( ',', $actions ),
				'message'       => 'Tandas ' . implode( ' + ', $actions ),
				'slices'        => $slices,
				'plugin_status' => is_array( $status['response'] ?? null ) ? $status['response'] : array(),
			)
		);
	}

	private static function finish( array $result ) {
		SA_Monitor_Checker::record_keepalive_attempt( $result );
		SA_Monitor_Stats::record_from_keepalive( $result );
		return $result;
	}
}
