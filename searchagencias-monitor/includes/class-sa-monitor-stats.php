<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Instantánea de la API + resumen diario/mensual para ver si el proceso sigue vivo.
 */
class SA_Monitor_Stats {

	const OPTION_SNAPSHOT = 'searchagencias_monitor_snapshot';
	const OPTION_DAILY    = 'searchagencias_monitor_daily';
	const DAYS_KEEP       = 40;

	public static function snapshot() {
		$snap = get_option( self::OPTION_SNAPSHOT, array() );
		return is_array( $snap ) ? $snap : array();
	}

	public static function daily_map() {
		$map = get_option( self::OPTION_DAILY, array() );
		return is_array( $map ) ? $map : array();
	}

	public static function record_from_keepalive( $result ) {
		$status = array();
		if ( isset( $result['plugin_status'] ) && is_array( $result['plugin_status'] ) ) {
			$status = $result['plugin_status'];
		} elseif ( isset( $result['status']['response'] ) && is_array( $result['status']['response'] ) ) {
			$status = $result['status']['response'];
		}
		self::save_snapshot( $status, $result );
		self::bump_daily( $result, $status );
	}

	public static function save_snapshot( array $status, $result = array() ) {
		$ready = isset( $status['ready_leads'] ) && is_array( $status['ready_leads'] )
			? $status['ready_leads']
			: array();
		update_option(
			self::OPTION_SNAPSHOT,
			array(
				'at'                  => gmdate( 'c' ),
				'version'             => (string) ( $status['version'] ?? '' ),
				'date'                => (string) ( $status['date'] ?? gmdate( 'Y-m-d' ) ),
				'recommended_action'  => (string) ( $status['recommended_action'] ?? ( $result['action'] ?? '' ) ),
				'daily_email_cap'     => (int) ( $status['daily_email_cap'] ?? 0 ),
				'reserved_today'      => (int) ( $status['reserved_today'] ?? 0 ),
				'remaining_today'     => (int) ( $status['remaining_today'] ?? 0 ),
				'morning_ran_today'   => ! empty( $status['morning_ran_today'] ),
				'needs_recover'       => ! empty( $status['needs_recover'] ),
				'needs_enrich'        => ! empty( $status['needs_enrich'] ),
				'needs_search'        => ! empty( $status['needs_search'] ),
				'needs_prepare'       => ! empty( $status['needs_prepare'] ),
				'needs_execute'       => ! empty( $status['needs_execute'] ),
				'ready_total'         => (int) ( $ready['total'] ?? 0 ),
				'last_action'         => (string) ( $result['action'] ?? '' ),
				'last_ok'             => ! empty( $result['ok'] ) || ! empty( $result['skipped'] ),
				'last_skipped'        => ! empty( $result['skipped'] ),
			),
			false
		);
	}

	public static function refresh_from_api() {
		$settings = SA_Monitor_Checker::get_settings();
		$status   = SA_Monitor_Recovery::invoke_endpoint( $settings, 'plugin-status', array(), 20 );
		$body     = ( isset( $status['response'] ) && is_array( $status['response'] ) ) ? $status['response'] : array();
		self::save_snapshot(
			$body,
			array(
				'ok'      => ! empty( $status['ok'] ),
				'skipped' => ! empty( $status['skipped'] ),
				'action'  => (string) ( $body['recommended_action'] ?? '' ),
			)
		);
		return $status;
	}

	public static function bump_daily( $result, array $status ) {
		$day = gmdate( 'Y-m-d' );
		$map = self::daily_map();
		$row = isset( $map[ $day ] ) && is_array( $map[ $day ] ) ? $map[ $day ] : array(
			'pings'        => 0,
			'ok'           => 0,
			'fail'         => 0,
			'idle'         => 0,
			'actions'      => array(),
			'max_reserved' => 0,
			'cap'          => 0,
			'last_at'      => '',
		);
		$row['pings']   = (int) $row['pings'] + 1;
		$row['last_at'] = gmdate( 'c' );
		$ok = ! empty( $result['ok'] ) || ! empty( $result['skipped'] );
		if ( $ok ) {
			$row['ok'] = (int) $row['ok'] + 1;
		} else {
			$row['fail'] = (int) $row['fail'] + 1;
		}
		$action = (string) ( $result['action'] ?? $result['reason'] ?? '' );
		if ( $action === 'idle' || ! empty( $result['skipped'] ) ) {
			$row['idle'] = (int) $row['idle'] + 1;
		}
		if ( $action !== '' && $action !== 'idle' && $action !== 'keepalive_disabled' ) {
			$actions = isset( $row['actions'] ) && is_array( $row['actions'] ) ? $row['actions'] : array();
			foreach ( explode( ',', $action ) as $one ) {
				$one = trim( $one );
				if ( $one === '' ) {
					continue;
				}
				$actions[ $one ] = (int) ( $actions[ $one ] ?? 0 ) + 1;
			}
			$row['actions'] = $actions;
		}
		$reserved = (int) ( $status['reserved_today'] ?? 0 );
		$cap      = (int) ( $status['daily_email_cap'] ?? 0 );
		if ( $reserved > (int) $row['max_reserved'] ) {
			$row['max_reserved'] = $reserved;
		}
		if ( $cap > 0 ) {
			$row['cap'] = $cap;
		}
		$map[ $day ] = $row;
		ksort( $map );
		if ( count( $map ) > self::DAYS_KEEP ) {
			$map = array_slice( $map, -self::DAYS_KEEP, null, true );
		}
		update_option( self::OPTION_DAILY, $map, false );
	}

	public static function is_stalled() {
		$settings = SA_Monitor_Checker::get_settings();
		$interval = max( 5, (int) ( $settings['daily_keepalive_interval_minutes'] ?? 30 ) );
		$limit    = max( 90, $interval * 2 ) * 60;
		$history  = SA_Monitor_Checker::keepalive_history();
		$last     = isset( $history[0]['at'] ) ? strtotime( (string) $history[0]['at'] ) : 0;
		if ( ! $last ) {
			$snap = self::snapshot();
			$last = isset( $snap['at'] ) ? strtotime( (string) $snap['at'] ) : 0;
		}
		if ( ! $last ) {
			return true;
		}
		return ( time() - $last ) > $limit;
	}

	public static function maybe_alert_stalled() {
		$history = SA_Monitor_Checker::keepalive_history();
		if ( ! $history ) {
			return;
		}
		if ( ! self::is_stalled() ) {
			return;
		}
		$hour = (int) gmdate( 'G' );
		if ( $hour < 7 || $hour > 21 ) {
			return;
		}
		$state = SA_Monitor_Checker::get_state();
		$today = gmdate( 'Y-m-d' );
		if ( (string) ( $state['stalled_alert_day'] ?? '' ) === $today ) {
			return;
		}
		$settings = SA_Monitor_Checker::get_settings();
		SA_Monitor_Checker::send_alert(
			$settings,
			false,
			'SearchAgencias: el keepalive WP lleva demasiado tiempo sin ping. El proceso puede estar parado.'
		);
		$state['stalled_alert_day'] = $today;
		SA_Monitor_Checker::save_state( $state );
	}

	public static function month_rows() {
		$map  = self::daily_map();
		$rows = array();
		$step = defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;
		for ( $i = 30; $i >= 0; $i-- ) {
			$day = gmdate( 'Y-m-d', time() - ( $i * $step ) );
			$row = isset( $map[ $day ] ) ? $map[ $day ] : null;
			$rows[] = array(
				'day'      => $day,
				'pings'    => $row ? (int) $row['pings'] : 0,
				'ok'       => $row ? (int) $row['ok'] : 0,
				'fail'     => $row ? (int) $row['fail'] : 0,
				'idle'     => $row ? (int) $row['idle'] : 0,
				'reserved' => $row ? (int) $row['max_reserved'] : 0,
				'cap'      => $row ? (int) $row['cap'] : 0,
				'alive'    => $row && (int) $row['ok'] > 0,
			);
		}
		return $rows;
	}
}
