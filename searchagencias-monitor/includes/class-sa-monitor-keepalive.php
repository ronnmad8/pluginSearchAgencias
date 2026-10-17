<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Despierta SearchAgencias y empuja varias tandas por ping.
 *
 * No depende solo del cron Vercel 8:00 UTC: cada wake puede encadenar
 * enrich / enviar / buscar según plugin-status (suelo diario de envíos en la API).
 */
class SA_Monitor_Keepalive {

	const CRON_HOOK = 'searchagencias_monitor_keepalive';
	/** Acciones distintas o repetidas por ping (Hobby: varias slices cortas). */
	const MAX_ACTIONS_PER_RUN = 4;
	/** Tope de envíos en un mismo wake (suelo diario ~2). */
	const MAX_SEND_BURST = 2;
	/** Tope de enrich en un mismo wake. */
	const MAX_ENRICH_BURST = 2;
	/** Tope de búsqueda Maps en un mismo wake. */
	const MAX_SEARCH_BURST = 3;

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

		$slices       = array();
		$actions      = array();
		$status       = null;
		$send_count   = 0;
		$enrich_count = 0;
		$search_count = 0;

		for ( $i = 0; $i < self::MAX_ACTIONS_PER_RUN; $i++ ) {
			$status = SA_Monitor_Recovery::invoke_endpoint_with_retry( $settings, 'plugin-status', array(), 2 );
			if ( empty( $status['ok'] ) ) {
				if ( $i === 0 ) {
					$watch        = SA_Monitor_Recovery::invoke_endpoint_with_retry( $settings, 'cron-watchdog', array(), 2 );
					$detail       = self::fail_detail( $status );
					$watch_detail = self::fail_detail( $watch );
					return self::finish(
						array(
							'ok'       => ! empty( $watch['ok'] ),
							'action'   => 'cron-watchdog',
							'message'  => 'plugin-status falló (' . $detail . '); watchdog (' . $watch_detail . ')',
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

			if ( ! self::allows_repeat( $action, $actions, $send_count, $enrich_count, $search_count ) ) {
				break;
			}

			$slice = SA_Monitor_Recovery::invoke_endpoint_with_retry( $settings, $action, array(), 2 );
			$actions[] = $action;
			$slices[]  = $slice;

			if ( in_array( $action, array( 'cron-execute-pending', 'cron-prepare-slice' ), true ) ) {
				$send_count++;
			} elseif ( $action === 'cron-enrich-slice' ) {
				$enrich_count++;
			} elseif ( in_array( $action, array( 'cron-search-slice', 'cron-social-slice' ), true ) ) {
				$search_count++;
			}

			if ( empty( $slice['ok'] ) ) {
				break;
			}
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

	/**
	 * Permite repetir enviar/enrich/buscar hasta su tope; recover/followup/directory solo 1.
	 *
	 * @param string $action       Acción recomendada.
	 * @param array  $actions      Ya ejecutadas en este wake.
	 * @param int    $send_count   Envíos en este wake.
	 * @param int    $enrich_count Enrich en este wake.
	 * @param int    $search_count Búsquedas en este wake.
	 */
	private static function allows_repeat( $action, array $actions, $send_count, $enrich_count, $search_count ) {
		if ( in_array( $action, array( 'cron-execute-pending', 'cron-prepare-slice' ), true ) ) {
			return $send_count < self::MAX_SEND_BURST;
		}
		if ( $action === 'cron-enrich-slice' ) {
			return $enrich_count < self::MAX_ENRICH_BURST;
		}
		if ( in_array( $action, array( 'cron-search-slice', 'cron-social-slice' ), true ) ) {
			return $search_count < self::MAX_SEARCH_BURST;
		}
		// recover / followup / directory: una sola vez por wake.
		return ! in_array( $action, $actions, true );
	}

	private static function fail_detail( array $result ) {
		if ( ! empty( $result['skipped'] ) ) {
			return (string) ( $result['reason'] ?? 'skipped' );
		}
		if ( ! empty( $result['error'] ) ) {
			return (string) $result['error'];
		}
		$code = (int) ( $result['http_code'] ?? 0 );
		if ( $code > 0 ) {
			$detail = isset( $result['response']['detail'] ) ? (string) $result['response']['detail'] : '';
			return 'HTTP ' . $code . ( $detail !== '' ? ' ' . $detail : '' );
		}
		return 'sin respuesta';
	}

	private static function finish( array $result ) {
		SA_Monitor_Checker::record_keepalive_attempt( $result );
		SA_Monitor_Stats::record_from_keepalive( $result );
		return $result;
	}
}
