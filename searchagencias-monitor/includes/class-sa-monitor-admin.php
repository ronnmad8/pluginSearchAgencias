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
		$settings['daily_keepalive_interval_minutes'] = (int) ( $posted['daily_keepalive_interval_minutes'] ?? 15 );
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
		$action = sanitize_key( (string) ( $_POST['sa_action'] ?? 'keepalive' ) );
		if ( $action === 'health' ) {
			SA_Monitor_Checker::run_check();
		} elseif ( $action === 'refresh-status' ) {
			SA_Monitor_Checker::run_check();
			SA_Monitor_Stats::refresh_from_api();
		} else {
			SA_Monitor_Keepalive::run();
		}
		wp_safe_redirect( admin_url( 'admin.php?page=searchagencias-monitor&ran=' . rawurlencode( $action ) ) );
		exit;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings   = SA_Monitor_Checker::get_settings();
		$state      = SA_Monitor_Checker::get_state();
		$history    = array_slice( SA_Monitor_Checker::keepalive_history(), 0, 20 );
		$snap       = SA_Monitor_Stats::snapshot();
		$month      = SA_Monitor_Stats::month_rows();
		$stalled    = SA_Monitor_Stats::is_stalled();
		$interval   = max( 5, (int) ( $settings['daily_keepalive_interval_minutes'] ?? 15 ) );
		$ping       = rest_url( 'searchagencias-monitor/v1/ping' );
		$today      = $month ? $month[ count( $month ) - 1 ] : array();
		$alive_days = 0;
		foreach ( $month as $row ) {
			if ( ! empty( $row['alive'] ) ) {
				$alive_days++;
			}
		}
		$health_ok = ! empty( $state['last_ok'] );
		$next      = (string) ( $snap['recommended_action'] ?? '—' );
		?>
		<div class="wrap">
			<h1>SearchAgencias</h1>
			<p>Este plugin solo <strong>ayuda a la app</strong>: busca agencias, saca emails y envía tandas cortas. No sustituye el panel de SearchAgencias.</p>
			<?php if ( ! empty( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success"><p>Guardado.</p></div>
			<?php endif; ?>
			<?php if ( ! empty( $_GET['ran'] ) ) : ?>
				<div class="notice notice-info"><p>Hecho.</p></div>
			<?php endif; ?>
			<?php
			$last_ka = $history ? $history[0] : null;
			if ( is_array( $last_ka ) && empty( $last_ka['ok'] ) && empty( $last_ka['skipped'] ) ) :
				?>
				<div class="notice notice-error">
					<p>
						<strong>Última tanda falló:</strong>
						<?php echo esc_html( (string) ( $last_ka['message'] ?? 'error desconocido' ) ); ?>
						— sin <code>plugin-status</code> OK la app no busca agencias.
						Revisa <em>CRON_SECRET de la API</em> (debe coincidir con Vercel) y pulsa «Comprobar conexión ahora».
					</p>
				</div>
			<?php endif; ?>

			<h2>1. Salud de la conexión</h2>
			<div class="notice <?php echo $health_ok ? 'notice-success' : 'notice-error'; ?>" style="padding:12px;">
				<p style="margin:0;font-size:1.1em;">
					Conexión con la app:
					<strong><?php echo $health_ok ? 'OK' : 'FALLÓ'; ?></strong>
					<?php echo esc_html( (string) ( $state['last_check_at'] ?? '—' ) ); ?>
					<?php echo esc_html( (string) ( $state['last_error'] ?? '' ) ); ?>
				</p>
				<p class="description" style="margin:8px 0 0;">
					Versión API: <?php echo esc_html( (string) ( $snap['version'] ?? '—' ) ); ?>
					· snapshot <?php echo esc_html( (string) ( $snap['at'] ?? '—' ) ); ?>
				</p>
			</div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'sa_monitor_action' ); ?>
				<input type="hidden" name="action" value="sa_monitor_action">
				<button class="button" name="sa_action" value="refresh-status">Comprobar conexión ahora</button>
			</form>

			<h2>2. Ayudar a la app (crons)</h2>
			<div class="notice <?php echo $stalled ? 'notice-error' : 'notice-success'; ?>" style="padding:12px;">
				<p style="margin:0;font-size:1.1em;">
					Proceso:
					<strong><?php echo $stalled ? 'PARADO' : 'ANDANDO'; ?></strong>
					<?php if ( $stalled ) : ?>
						— hace más de ~<?php echo (int) max( 90, $interval * 2 ); ?> min sin ping. El cron del hosting debe llamar cada <?php echo (int) $interval; ?> min.
					<?php else : ?>
						— el plugin está despertando la app (cada <?php echo (int) $interval; ?> min).
					<?php endif; ?>
				</p>
			</div>
			<table class="widefat striped" style="max-width:640px;margin:12px 0;">
				<tbody>
					<tr><th>Siguiente cron</th><td><code><?php echo esc_html( $next ); ?></code> <span class="description">(buscar / listados / email / lote / envío / follow-up)</span></td></tr>
					<tr><th>Envíos hoy</th><td><?php echo (int) ( $snap['reserved_today'] ?? ( $today['reserved'] ?? 0 ) ); ?> / <?php echo (int) ( $snap['daily_email_cap'] ?? ( $today['cap'] ?? 0 ) ); ?> (quedan <?php echo (int) ( $snap['remaining_today'] ?? 0 ); ?>)</td></tr>
					<tr><th>Pings hoy</th><td><?php echo (int) ( $today['pings'] ?? 0 ); ?> pings · <?php echo (int) ( $today['ok'] ?? 0 ); ?> ok · <?php echo (int) ( $today['fail'] ?? 0 ); ?> fail · <?php echo (int) ( $today['idle'] ?? 0 ); ?> sin trabajo</td></tr>
				</tbody>
			</table>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'sa_monitor_action' ); ?>
				<input type="hidden" name="action" value="sa_monitor_action">
				<button class="button button-primary" name="sa_action" value="keepalive">Ayudar ahora (una tanda)</button>
			</form>
			<p class="description">El hosting debe pegar a: <code><?php echo esc_html( $ping ); ?>?key=CLAVE</code></p>

			<h2>3. Histórico (¿sigue andando?)</h2>
			<p class="description"><?php echo (int) $alive_days; ?> / 31 días con ping OK. Días en blanco = no hubo cron.</p>
			<table class="widefat striped">
				<thead>
					<tr><th>Día</th><th>Anduvo</th><th>Pings</th><th>OK</th><th>Fail</th><th>Sin trabajo</th><th>Enviados / tope</th></tr>
				</thead>
				<tbody>
				<?php foreach ( array_reverse( $month ) as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row['day'] ); ?></td>
						<td><?php echo ! empty( $row['alive'] ) ? 'sí' : '—'; ?></td>
						<td><?php echo (int) $row['pings']; ?></td>
						<td><?php echo (int) $row['ok']; ?></td>
						<td><?php echo (int) $row['fail']; ?></td>
						<td><?php echo (int) $row['idle']; ?></td>
						<td><?php echo (int) $row['reserved']; ?> / <?php echo (int) $row['cap']; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h3>Últimas tandas</h3>
			<table class="widefat striped">
				<thead><tr><th>UTC</th><th>OK</th><th>Cron</th><th>Mensaje</th></tr></thead>
				<tbody>
				<?php if ( ! $history ) : ?>
					<tr><td colspan="4">Aún no hay tandas. Configura el cron del hosting (WP-Cron solo corre si hay visitas).</td></tr>
				<?php else : ?>
					<?php foreach ( $history as $row ) : ?>
						<tr>
							<td><?php echo esc_html( (string) ( $row['at'] ?? '' ) ); ?></td>
							<td><?php echo ! empty( $row['ok'] ) ? 'sí' : 'no'; ?><?php echo ! empty( $row['skipped'] ) ? ' (idle)' : ''; ?></td>
							<td><?php echo esc_html( (string) ( $row['action'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $row['message'] ?? '' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>

			<details style="margin-top:2em;">
				<summary><strong>Conexión (URL y claves)</strong></summary>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'sa_monitor_save' ); ?>
				<input type="hidden" name="action" value="sa_monitor_save">
				<table class="form-table">
					<tr>
						<th>Activo</th>
						<td><label><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>> Health + ayudar a la app</label></td>
					</tr>
					<tr>
						<th>URL health</th>
						<td><input class="regular-text" type="url" name="health_url" value="<?php echo esc_attr( $settings['health_url'] ); ?>"></td>
					</tr>
					<tr>
						<th>CRON_SECRET de la API</th>
						<td>
							<input class="regular-text" type="password" name="api_cron_secret" value="<?php echo esc_attr( $settings['api_cron_secret'] ); ?>" autocomplete="off">
							<p class="description">El mismo que en Vercel SearchAgencias.</p>
						</td>
					</tr>
					<tr>
						<th>Clave ping WordPress</th>
						<td>
							<input class="regular-text" type="text" name="cron_secret" value="<?php echo esc_attr( $settings['cron_secret'] ); ?>">
							<p class="description"><code><?php echo esc_html( $ping ); ?>?key=ESTA_CLAVE</code></p>
						</td>
					</tr>
					<tr>
						<th>Email alerta</th>
						<td><input class="regular-text" type="email" name="alert_email" value="<?php echo esc_attr( $settings['alert_email'] ); ?>"></td>
					</tr>
					<tr>
						<th>Frecuencia</th>
						<td>
							<label><input type="checkbox" name="daily_keepalive_enabled" value="1" <?php checked( ! empty( $settings['daily_keepalive_enabled'] ) ); ?>> Activado</label>
							cada
							<input type="number" min="5" max="60" name="daily_keepalive_interval_minutes" value="<?php echo esc_attr( (string) $settings['daily_keepalive_interval_minutes'] ); ?>" style="width:4em">
							min
						</td>
					</tr>
					<tr>
						<th>Watchdog si health cae</th>
						<td><label><input type="checkbox" name="auto_recover_enabled" value="1" <?php checked( ! empty( $settings['auto_recover_enabled'] ) ); ?>> Sí</label></td>
					</tr>
					<tr>
						<th>Avisar recuperación</th>
						<td><label><input type="checkbox" name="notify_recovery" value="1" <?php checked( ! empty( $settings['notify_recovery'] ) ); ?>> Sí</label></td>
					</tr>
				</table>
				<?php submit_button( 'Guardar' ); ?>
			</form>
			</details>
		</div>
		<style>
			.sa-monitor-loading {
				position: fixed; inset: 0; z-index: 100000;
				display: none; align-items: center; justify-content: center;
				background: rgba(15, 23, 42, 0.35);
			}
			.sa-monitor-loading.is-visible { display: flex; }
			.sa-monitor-loading-box {
				background: #fff; padding: 1.25rem 1.75rem; border-radius: 4px;
				box-shadow: 0 8px 28px rgba(0,0,0,.18); text-align: center; min-width: 200px;
			}
			.sa-monitor-loading-box .spinner {
				display: inline-block; width: 22px; height: 22px;
				border: 3px solid #c3c4c7; border-top-color: #2271b1;
				border-radius: 50%; animation: sa-monitor-spin .7s linear infinite;
				vertical-align: middle; margin-right: 8px;
			}
			@keyframes sa-monitor-spin { to { transform: rotate(360deg); } }
			body.sa-monitor-busy { cursor: wait; }
			body.sa-monitor-busy .wrap { pointer-events: none; }
		</style>
		<div id="sa-monitor-loading" class="sa-monitor-loading" role="status" aria-live="polite" aria-busy="false" hidden>
			<div class="sa-monitor-loading-box">
				<span class="spinner" aria-hidden="true"></span>
				<span class="sa-monitor-loading-msg">Cargando…</span>
			</div>
		</div>
		<script>
		(function () {
			var overlay = document.getElementById('sa-monitor-loading');
			var msgEl = overlay ? overlay.querySelector('.sa-monitor-loading-msg') : null;
			function show(message) {
				if (!overlay) return;
				if (message && msgEl) msgEl.textContent = message;
				overlay.hidden = false;
				overlay.classList.add('is-visible');
				overlay.setAttribute('aria-busy', 'true');
				document.body.classList.add('sa-monitor-busy');
			}
			function hide() {
				if (!overlay) return;
				overlay.hidden = true;
				overlay.classList.remove('is-visible');
				overlay.setAttribute('aria-busy', 'false');
				document.body.classList.remove('sa-monitor-busy');
			}
			document.querySelectorAll('.wrap form').forEach(function (form) {
				form.addEventListener('submit', function (ev) {
					var btn = ev.submitter || form.querySelector('button[type="submit"], input[type="submit"]');
					var label = 'Cargando…';
					if (btn && btn.name === 'sa_action') {
						if (btn.value === 'keepalive') label = 'Ayudando a la app…';
						else if (btn.value === 'refresh-status' || btn.value === 'health') label = 'Comprobando conexión…';
					} else if (form.querySelector('input[name="action"][value="sa_monitor_save"]')) {
						label = 'Guardando…';
					}
					if (btn) {
						btn.disabled = true;
						btn.classList.add('disabled');
					}
					show(label);
				});
			});
			window.addEventListener('pageshow', hide);
		})();
		</script>
		<?php
	}
}
