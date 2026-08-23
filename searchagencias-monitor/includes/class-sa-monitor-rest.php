<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SA_Monitor_Rest {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			'searchagencias-monitor/v1',
			'/ping',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_ping' ),
				'permission_callback' => array( __CLASS__, 'verify_request' ),
			)
		);
	}

	public static function verify_request( $request ) {
		$settings = SA_Monitor_Checker::get_settings();
		$secret   = trim( (string) $settings['cron_secret'] );
		if ( $secret === '' ) {
			return current_user_can( 'manage_options' );
		}
		$provided = trim( (string) $request->get_param( 'key' ) );
		if ( $provided === '' ) {
			$header = $request->get_header( 'x-searchagencias-monitor-key' );
			$provided = is_string( $header ) ? trim( $header ) : '';
		}
		return hash_equals( $secret, $provided );
	}

	public static function handle_ping( $request ) {
		$health    = SA_Monitor_Checker::run_check();
		$keepalive = SA_Monitor_Keepalive::run();
		$ok        = ( ! empty( $health['ok'] ) || ! empty( $health['skipped'] ) )
			&& ( ! empty( $keepalive['ok'] ) || ! empty( $keepalive['skipped'] ) );
		return new WP_REST_Response(
			array(
				'ok'        => $ok,
				'health'    => $health,
				'keepalive' => $keepalive,
			),
			$ok ? 200 : 503
		);
	}
}
