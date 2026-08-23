<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SA_Monitor_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_sa_monitor_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_sa_monitor_action', array( __CLASS__, 'manual_action' ) );
	}

	public static function menu() {
		add_menu_page(
			'SearchAgencias',
			'SearchAgencias',
			'manage_options',
			'searchagencias-monitor',
			array( __CLASS__, 'render' ),
			'dashicons-email-alt',
			58
		);
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden' );
		}
		check_admin_referer( 'sa_monitor_save' );
		$settings = SA_Monitor_Checker::get_settings();
		$posted   = wp_unslash( $_POST );
		$settings['enabled'] = ! empty( $posted['enabled'] );
		$settings['health_url'] = (string) ( $posted['health_url'] ?? $settings['health_url'] );
		$settings['alert_email'] = (string) ( $posted['alert_email'] ?? $settings['alert_email'] );
		$settings['api_cron_secret'] = (string) ( $posted['api_cron_secret'] ?? '' );
		$settings['cron_secret'] = (string) ( $posted['cron_secret'] ?? $settings['cron_secret'] );
		$settings['auto_recover_enabled'] = ! empty( $posted['auto_recover_enabled'] );
		$settings['daily_keepalive_enabled'] = ! empty( $posted['daily_keepalive_enabled'] );
		$settings['notify_recovery'] = ! empty( $posted['notify_recovery'] );
		$settings['daily_keepalive_interval_minutes'] = (int) ( $posted['daily_keepalive_interval_minutes'] ?? 10 );
		SA_Monitor_Checker::save_settings( $settings );
		SA_Monitor_Cron::maybe_schedule( true );
		SA_Monitor_Cron::maybe_schedule_keepalive( true );
		wp_safe_redirect( admin_url( 'admin.php?page=searchagencias-monitor&saved=1' ) );
		exit;
	}

	public static function manual_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden' );
		}
		check_admin_referer( 'sa_monitor_action' );
		$action = sanitize_key( (string) ( $_POST['sa_action'] ?? 'plugin-status' ) );
		$settings = SA_Monitor_Checker::get_settings();
		if ( $action === 'health' ) {
			SA_Monitor_Checker::run_check();
		} elseif ( $action === 'keepalive' ) {
			SA_Monitor_Keepalive::run();
		} else {
			SA_Monitor_Recovery::invoke_endpoint( $settings, $action );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=searchagencias-monitor&ran=' . rawurlencode( $action ) ) );
		exit;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = SA_Monitor_Checker::get_settings();
		$state    = SA_Monitor_Checker::get_state();
		$history  = array_slice( SA_Monitor_Checker::keepalive_history(), 0, 15 );
		$ping     = rest_url( 'searchagencias-monitor/v1/ping' );
		$key      = (string) $settings['cron_secret'];
		?>
		<div class="wrap">
			<h1>SearchAgencias Monitor</h1>
			<p>Vercel solo tiene <strong>1 cron</strong> (08:00 UTC). Este plugin, en <code>renekay.com</code>, dispara tandas cortas: recover → búsqueda → lote → envío.</p>
			<?php if ( ! empty( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success"><p>Guardado.</p></div>
			<?php endif; ?>
			<?php if ( ! empty( $_GET['ran'] ) ) : ?>
				<div class="notice notice-info"><p>Acción: <?php echo esc_html( (string) $_GET['ran'] ); ?></p></div>
			<?php endif; ?>

			<h2>Estado</h2>
			<p>
				Health último:
				<strong><?php echo ! empty( $state['last_ok'] ) ? 'OK' : 'FALLÓ'; ?></strong>
				<?php echo esc_html( (string) ( $state['last_check_at'] ?? '—' ) ); ?>
				<?php echo esc_html( (string) ( $state['last_error'] ?? '' ) ); ?>
			</p>

			<h2>Configuración</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'sa_monitor_save' ); ?>
				<input type="hidden" name="action" value="sa_monitor_save">
				<table class="form-table">
					<tr>
						<th>Activo</th>
						<td><label><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>> Health + keepalive</label></td>
					</tr>
					<tr>
						<th>URL health</th>
						<td><input class="regular-text" type="url" name="health_url" value="<?php echo esc_attr( $settings['health_url'] ); ?>"></td>
					</tr>
					<tr>
						<th>CRON_SECRET de la API (Vercel)</th>
						<td>
							<input class="regular-text" type="password" name="api_cron_secret" value="<?php echo esc_attr( $settings['api_cron_secret'] ); ?>" autocomplete="off">
							<p class="description">El mismo <code>CRON_SECRET</code> que en SearchAgencias (Vercel).</p>
						</td>
					</tr>
					<tr>
						<th>Clave ping WordPress</th>
						<td>
							<input class="regular-text" type="text" name="cron_secret" value="<?php echo esc_attr( $settings['cron_secret'] ); ?>">
							<p class="description">Para el cron del hosting: <code><?php echo esc_html( $ping ); ?>?key=ESTA_CLAVE</code></p>
						</td>
					</tr>
					<tr>
						<th>Email alerta</th>
						<td><input class="regular-text" type="email" name="alert_email" value="<?php echo esc_attr( $settings['alert_email'] ); ?>"></td>
					</tr>
					<tr>
						<th>Keepalive</th>
						<td>
							<label><input type="checkbox" name="daily_keepalive_enabled" value="1" <?php checked( ! empty( $settings['daily_keepalive_enabled'] ) ); ?>> Activado</label>
							&nbsp; cada
							<input type="number" min="5" max="30" name="daily_keepalive_interval_minutes" value="<?php echo esc_attr( (string) $settings['daily_keepalive_interval_minutes'] ); ?>" style="width:4em">
							minutos
						</td>
					</tr>
					<tr>
						<th>Auto watchdog si health cae</th>
						<td><label><input type="checkbox" name="auto_recover_enabled" value="1" <?php checked( ! empty( $settings['auto_recover_enabled'] ) ); ?>> Sí</label></td>
					</tr>
					<tr>
						<th>Avisar recuperación</th>
						<td><label><input type="checkbox" name="notify_recovery" value="1" <?php checked( ! empty( $settings['notify_recovery'] ) ); ?>> Sí</label></td>
					</tr>
				</table>
				<?php submit_button( 'Guardar' ); ?>
			</form>

			<h2>Acciones manuales</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:8px;flex-wrap:wrap;">
				<?php wp_nonce_field( 'sa_monitor_action' ); ?>
				<input type="hidden" name="action" value="sa_monitor_action">
				<?php
				foreach (
					array(
						'health'               => 'Probar health',
						'keepalive'            => 'Keepalive ahora',
						'plugin-status'        => 'plugin-status',
						'cron-recover'         => 'Recover',
						'cron-search-slice'    => 'Buscar',
						'cron-prepare-slice'   => 'Preparar lote',
						'cron-execute-pending' => 'Ejecutar envíos',
						'cron-watchdog'        => 'Watchdog',
					) as $value => $label
				) :
					?>
					<button class="button" name="sa_action" value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></button>
				<?php endforeach; ?>
			</form>

			<h2>Últimos keepalive</h2>
			<table class="widefat striped">
				<thead><tr><th>UTC</th><th>OK</th><th>Acción</th><th>Mensaje</th></tr></thead>
				<tbody>
				<?php if ( ! $history ) : ?>
					<tr><td colspan="4">Sin tandas aún. Configura un cron real del hosting al ping (WP-Cron solo no basta si la web tiene poco tráfico).</td></tr>
				<?php else : ?>
					<?php foreach ( $history as $row ) : ?>
						<tr>
							<td><?php echo esc_html( (string) ( $row['at'] ?? '' ) ); ?></td>
							<td><?php echo ! empty( $row['ok'] ) ? 'sí' : 'no'; ?><?php echo ! empty( $row['skipped'] ) ? ' (skip)' : ''; ?></td>
							<td><?php echo esc_html( (string) ( $row['action'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $row['message'] ?? '' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
