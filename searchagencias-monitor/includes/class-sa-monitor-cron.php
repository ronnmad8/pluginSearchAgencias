<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SA_Monitor_Cron {

	const SCHEDULE           = 'searchagencias_every_30_minutes';
	const KEEPALIVE_SCHEDULE = 'searchagencias_keepalive_interval';

	public static function register_schedules() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
		add_action( SA_Monitor_Checker::CRON_HOOK, array( __CLASS__, 'run' ) );
		add_action( SA_Monitor_Keepalive::CRON_HOOK, array( 'SA_Monitor_Keepalive', 'run' ) );
	}

	public static function add_schedule( $schedules ) {
		if ( ! isset( $schedules[ self::SCHEDULE ] ) ) {
			$schedules[ self::SCHEDULE ] = array(
				'interval' => 30 * MINUTE_IN_SECONDS,
				'display'  => __( 'Cada 30 minutos (SearchAgencias health)', 'searchagencias-monitor' ),
			);
		}
		$settings = SA_Monitor_Checker::get_settings();
		$minutes  = max( 5, min( 30, (int) ( $settings['daily_keepalive_interval_minutes'] ?? 10 ) ) );
		$schedules[ self::KEEPALIVE_SCHEDULE ] = array(
			'interval' => $minutes * MINUTE_IN_SECONDS,
			'display'  => sprintf(
				__( 'Cada %d minutos (SearchAgencias keepalive)', 'searchagencias-monitor' ),
				$minutes
			),
		);
		return $schedules;
	}

	public static function activate() {
		self::register_schedules();
		if ( get_option( SA_Monitor_Checker::OPTION_SETTINGS ) === false ) {
			$defaults = SA_Monitor_Checker::default_settings();
			$defaults['cron_secret'] = wp_generate_password( 32, false, false );
			update_option( SA_Monitor_Checker::OPTION_SETTINGS, $defaults, false );
		}
		self::maybe_schedule( true );
		self::maybe_schedule_keepalive( true );
	}

	public static function deactivate() {
		$timestamp = wp_next_scheduled( SA_Monitor_Checker::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, SA_Monitor_Checker::CRON_HOOK );
		}
		self::deactivate_keepalive();
	}

	public static function deactivate_keepalive() {
		$timestamp = wp_next_scheduled( SA_Monitor_Keepalive::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, SA_Monitor_Keepalive::CRON_HOOK );
		}
	}

	public static function maybe_schedule( $force = false ) {
		$settings = SA_Monitor_Checker::get_settings();
		if ( empty( $settings['enabled'] ) ) {
			self::deactivate();
			return;
		}
		if ( ! $force && wp_next_scheduled( SA_Monitor_Checker::CRON_HOOK ) ) {
			return;
		}
		$timestamp = wp_next_scheduled( SA_Monitor_Checker::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, SA_Monitor_Checker::CRON_HOOK );
		}
		wp_schedule_event( time() + 60, self::SCHEDULE, SA_Monitor_Checker::CRON_HOOK );
	}

	public static function maybe_schedule_keepalive( $force = false ) {
		$settings = SA_Monitor_Checker::get_settings();
		if ( empty( $settings['enabled'] ) || empty( $settings['daily_keepalive_enabled'] ) ) {
			self::deactivate_keepalive();
			return;
		}
		if ( ! $force && wp_next_scheduled( SA_Monitor_Keepalive::CRON_HOOK ) ) {
			return;
		}
		self::deactivate_keepalive();
		wp_schedule_event( time() + 90, self::KEEPALIVE_SCHEDULE, SA_Monitor_Keepalive::CRON_HOOK );
	}

	public static function run() {
		SA_Monitor_Checker::run_check();
	}
}
