<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SA_Monitor_Recovery {

	const RETRYABLE_HTTP_CODES = array( 408, 429, 500, 502, 503, 504 );

	public static function path_map() {
		return array(
			'plugin-status'         => '/api/plugin-status',
			'cron-recover'          => '/api/cron-recover',
			'cron-enrich-slice'     => '/api/cron-enrich-slice',
			'cron-enrich-and-send'  => '/api/cron-enrich-and-send',
			'cron-search-slice'     => '/api/cron-search-slice',
			'cron-social-slice'     => '/api/cron-social-slice',
			'cron-prepare-slice'    => '/api/cron-prepare-slice',
			'cron-execute-pending'  => '/api/cron-execute-pending',
			'cron-followup-slice'   => '/api/cron-followup-slice',
			'cron-directory-slice'  => '/api/cron-directory-slice',
			'cron-watchdog'         => '/api/cron-watchdog',
			'cron-morning'          => '/api/cron-morning',
			'app-logs'              => '/api/app-logs',
			'cron-status'           => '/api/cron-status',
		);
	}

	public static function base_url_from_health( $health_url ) {
		$url = rtrim( (string) $health_url, '/' );
		$url = preg_replace( '#/api/health$#', '', $url );
		return rtrim( (string) $url, '/' );
	}

	public static function invoke_endpoint( $settings, $endpoint, array $query = array(), $timeout_override = null ) {
		$secret = trim( (string) ( $settings['api_cron_secret'] ?? '' ) );
		if ( $secret === '' ) {
			return array(
				'ok'      => false,
				'skipped' => true,
				'reason'  => 'api_cron_secret vacío',
			);
		}

		$base = self::base_url_from_health( (string) ( $settings['health_url'] ?? '' ) );
		$map  = self::path_map();
		if ( $base === '' || ! isset( $map[ $endpoint ] ) ) {
			return array(
				'ok'    => false,
				'error' => 'Endpoint o URL base inválidos',
			);
		}

		$url = $base . $map[ $endpoint ];
		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		$timeout = $timeout_override !== null ? max( 8, (int) $timeout_override ) : 90;
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => $timeout,
				'redirection' => 3,
				'headers'     => array(
					'Authorization' => 'Bearer ' . $secret,
					'x-cron-secret' => $secret,
					'Accept'        => 'application/json',
					'User-Agent'    => 'SearchAgenciasMonitor/' . SEARCHAGENCIAS_MONITOR_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'       => false,
				'endpoint' => $endpoint,
				'error'    => $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			$data = array( 'raw' => wp_html_excerpt( $body, 400, '…' ) );
		}

		$ok = $code >= 200 && $code < 300 && ( empty( $data['ok'] ) ? $code < 300 : ! empty( $data['ok'] ) );
		if ( $code >= 200 && $code < 300 && ! array_key_exists( 'ok', $data ) ) {
			$ok = true;
		}

		return array(
			'ok'       => $ok,
			'endpoint' => $endpoint,
			'http_code'=> $code,
			'response' => $data,
			'message'  => (string) ( $data['recommended_action'] ?? $data['status'] ?? '' ),
			'skipped'  => ! empty( $data['skipped'] ),
		);
	}

	public static function invoke_endpoint_with_retry( $settings, $endpoint, array $query = array(), $max_attempts = 2 ) {
		$result = array( 'ok' => false, 'error' => 'sin intentos' );
		for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
			$result = self::invoke_endpoint( $settings, $endpoint, $query );
			$result['invoke_attempt'] = $attempt;
			if ( ! empty( $result['ok'] ) || ! empty( $result['skipped'] ) ) {
				return $result;
			}
			$code = (int) ( $result['http_code'] ?? 0 );
			if ( ! in_array( $code, self::RETRYABLE_HTTP_CODES, true ) && empty( $result['error'] ) ) {
				return $result;
			}
			if ( $attempt < $max_attempts ) {
				sleep( 2 );
			}
		}
		return $result;
	}
}
