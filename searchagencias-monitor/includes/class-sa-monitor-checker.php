<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SA_Monitor_Checker {

	const OPTION_SETTINGS = 'searchagencias_monitor_settings';
	const OPTION_STATE    = 'searchagencias_monitor_state';
	const OPTION_HISTORY  = 'searchagencias_monitor_history';
	const OPTION_KEEPALIVE_HISTORY = 'searchagencias_monitor_keepalive_history';
	const CRON_HOOK       = 'searchagencias_monitor_health_check';
	const MAX_HISTORY     = 200;
	const MAX_KEEPALIVE_HISTORY = 200;

	public static function default_settings() {
		return array(
			'enabled'                 => true,
			'health_url'              => 'https://searchagencias.vercel.app/api/health',
			'alert_email'             => 'develop@renekay.com',
			'expected_keyword'        => '"status":"ok"',
			'timeout_seconds'         => 20,
			'cooldown_minutes'        => 240,
			'notify_recovery'         => true,
			'cron_secret'             => '',
			'api_cron_secret'         => '',
			'auto_recover_enabled'    => true,
			'daily_keepalive_enabled' => true,
			'daily_keepalive_interval_minutes' => 30,
		);
	}

	public static function get_settings() {
		$stored = get_option( self::OPTION_SETTINGS, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( self::default_settings(), $stored );
	}

	public static function save_settings( array $settings ) {
		$defaults = self::default_settings();
		$clean    = array(
			'enabled'                 => ! empty( $settings['enabled'] ),
			'health_url'              => esc_url_raw( (string) ( $settings['health_url'] ?? $defaults['health_url'] ) ),
			'alert_email'             => sanitize_email( (string) ( $settings['alert_email'] ?? $defaults['alert_email'] ) ),
			'expected_keyword'        => sanitize_text_field( (string) ( $settings['expected_keyword'] ?? $defaults['expected_keyword'] ) ),
			'timeout_seconds'         => max( 5, min( 60, (int) ( $settings['timeout_seconds'] ?? 20 ) ) ),
			'cooldown_minutes'        => max( 30, min( 1440, (int) ( $settings['cooldown_minutes'] ?? 240 ) ) ),
			'notify_recovery'         => ! empty( $settings['notify_recovery'] ),
			'cron_secret'             => sanitize_text_field( (string) ( $settings['cron_secret'] ?? '' ) ),
			'api_cron_secret'         => sanitize_text_field( (string) ( $settings['api_cron_secret'] ?? '' ) ),
			'auto_recover_enabled'    => ! empty( $settings['auto_recover_enabled'] ),
			'daily_keepalive_enabled' => ! empty( $settings['daily_keepalive_enabled'] ),
			'daily_keepalive_interval_minutes' => max( 5, min( 60, (int) ( $settings['daily_keepalive_interval_minutes'] ?? 30 ) ) ),
		);
		update_option( self::OPTION_SETTINGS, $clean, false );
		return $clean;
	}

	public static function get_state() {
		$state = get_option( self::OPTION_STATE, array() );
		return is_array( $state ) ? $state : array();
	}

	public static function save_state( array $state ) {
		update_option( self::OPTION_STATE, $state, false );
	}

	public static function record_keepalive_attempt( $result ) {
		$history = get_option( self::OPTION_KEEPALIVE_HISTORY, array() );
		if ( ! is_array( $history ) ) {
			$history = array();
		}
		$history[] = array(
			'at'      => gmdate( 'c' ),
			'ok'      => ! empty( $result['ok'] ),
			'skipped' => ! empty( $result['skipped'] ),
			'action'  => (string) ( $result['action'] ?? $result['reason'] ?? '' ),
			'message' => (string) ( $result['message'] ?? '' ),
		);
		if ( count( $history ) > self::MAX_KEEPALIVE_HISTORY ) {
			$history = array_slice( $history, -self::MAX_KEEPALIVE_HISTORY );
		}
		update_option( self::OPTION_KEEPALIVE_HISTORY, $history, false );
	}

	public static function keepalive_history() {
		$history = get_option( self::OPTION_KEEPALIVE_HISTORY, array() );
		return is_array( $history ) ? array_reverse( $history ) : array();
	}

	public static function run_check() {
		$settings = self::get_settings();
		if ( empty( $settings['enabled'] ) ) {
			return array(
				'ok'      => true,
				'skipped' => true,
				'reason'  => 'disabled',
			);
		}

		$url     = (string) $settings['health_url'];
		$timeout = (int) $settings['timeout_seconds'];
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => $timeout,
				'redirection' => 3,
				'headers'     => array(
					'Accept'     => 'application/json',
					'User-Agent' => 'SearchAgenciasMonitor/' . SEARCHAGENCIAS_MONITOR_VERSION,
				),
			)
		);

		$ok    = false;
		$error = '';
		$code  = 0;
		$body  = '';
		if ( is_wp_error( $response ) ) {
			$error = $response->get_error_message();
		} else {
			$code = (int) wp_remote_retrieve_response_code( $response );
			$body = (string) wp_remote_retrieve_body( $response );
			$needle = (string) $settings['expected_keyword'];
			$ok     = $code >= 200 && $code < 300 && ( $needle === '' || strpos( $body, $needle ) !== false );
			if ( ! $ok ) {
				$error = 'HTTP ' . $code;
			}
		}

		$state = self::get_state();
		$was_down = ! empty( $state['down'] );
		$state['last_check_at'] = gmdate( 'c' );
		$state['last_ok']       = $ok;
		$state['last_code']     = $code;
		$state['last_error']    = $error;

		if ( $ok ) {
			$state['down'] = false;
			$state['consecutive_failures'] = 0;
			if ( $was_down && ! empty( $settings['notify_recovery'] ) ) {
				self::send_alert( $settings, true, 'SearchAgencias ha vuelto (health OK).' );
			}
		} else {
			$state['consecutive_failures'] = (int) ( $state['consecutive_failures'] ?? 0 ) + 1;
			$state['down'] = true;
			$last_alert = strtotime( (string) ( $state['last_alert_at'] ?? '' ) );
			$cooldown   = (int) $settings['cooldown_minutes'] * 60;
			if ( ! $last_alert || ( time() - $last_alert ) >= $cooldown ) {
				self::send_alert( $settings, false, 'SearchAgencias no responde: ' . $error );
				$state['last_alert_at'] = gmdate( 'c' );
			}
			if ( ! empty( $settings['auto_recover_enabled'] ) ) {
				SA_Monitor_Recovery::invoke_endpoint( $settings, 'cron-watchdog' );
			}
		}

		self::save_state( $state );
		SA_Monitor_Stats::maybe_alert_stalled();
		$history = get_option( self::OPTION_HISTORY, array() );
		if ( ! is_array( $history ) ) {
			$history = array();
		}
		$history[] = array(
			'at'   => gmdate( 'c' ),
			'ok'   => $ok,
			'code' => $code,
		);
		if ( count( $history ) > self::MAX_HISTORY ) {
			$history = array_slice( $history, -self::MAX_HISTORY );
		}
		update_option( self::OPTION_HISTORY, $history, false );

		return array(
			'ok'    => $ok,
			'code'  => $code,
			'error' => $error,
			'url'   => $url,
		);
	}

	public static function send_alert( $settings, $recovered, $message ) {
		$email = sanitize_email( (string) ( $settings['alert_email'] ?? '' ) );
		if ( $email === '' ) {
			return;
		}
		$subject = $recovered
			? '[SearchAgencias] Recuperada'
			: '[SearchAgencias] Caída / health fail';
		wp_mail( $email, $subject, $message . "\n\n" . (string) $settings['health_url'] );
	}
}
