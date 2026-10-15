<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings › NAS Backup.
 */
class NBB_Admin {

	const SLUG = 'nas-backup-bridge';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_nbb_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_nbb_regenerate', array( __CLASS__, 'regenerate' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( NBB_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::SLUG ) ) . '">Ajustes</a>' );
		return $links;
	}

	public static function menu() {
		add_options_page( 'NAS Backup', 'NAS Backup', 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	private static function back( $msg ) {
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'nbb_msg' => $msg ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No autorizado.' );
		}
		check_admin_referer( 'nbb_save' );
		$raw      = isset( $_POST['nbb_excludes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['nbb_excludes'] ) ) : '';
		$patterns = array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $raw ) ), 'strlen' ) );
		update_option( NBB_Settings::OPT_EXCLUDES, $patterns, false );
		update_option( NBB_Settings::OPT_ALL_TBL, ! empty( $_POST['nbb_all_tables'] ), false );
		update_option( NBB_Restore::OPT_ALLOW, ! empty( $_POST['nbb_allow_restore'] ), false );
		self::back( 'saved' );
	}

	public static function regenerate() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No autorizado.' );
		}
		check_admin_referer( 'nbb_regenerate' );
		NBB_Settings::regenerate_secret();
		self::back( 'regenerated' );
	}

	private static function site_slug() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = preg_replace( '/^www\./', '', (string) $host );
		return sanitize_title( str_replace( '.', '-', $host ) );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$secret   = NBB_Settings::secret();
		$last     = get_option( NBB_Settings::OPT_LAST, null );
		$jobs     = NBB_Job::all();
		$storage  = NBB_Storage::dir();
		$public   = NBB_Storage::is_public();
		$msg      = isset( $_GET['nbb_msg'] ) ? sanitize_key( wp_unslash( $_GET['nbb_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$snippet  = wp_json_encode(
			array(
				'name'   => self::site_slug(),
				'url'    => home_url( '/' ),
				'secret' => $secret,
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);
		?>
		<div class="wrap">
			<h1>NAS Backup Bridge</h1>
			<?php if ( 'saved' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p>Ajustes guardados.</p></div>
			<?php elseif ( 'regenerated' === $msg ) : ?>
				<div class="notice notice-warning is-dismissible"><p>Clave regenerada. Actualiza la configuración del NAS o las copias fallarán.</p></div>
			<?php endif; ?>

			<p>Este plugin no hace copias por sí solo ni envía datos a terceros: el servidor de copias (NAS) se conecta con una clave firmada, pide la copia y la descarga.</p>

			<h2>Estado</h2>
			<table class="widefat striped" style="max-width:860px">
				<tbody>
					<tr><th style="width:220px">Última copia completada</th><td>
						<?php
						if ( is_array( $last ) ) {
							echo esc_html(
								sprintf(
									'%s — %s archivos (%s), %d tablas, %s filas',
									wp_date( 'Y-m-d H:i', (int) $last['completed'] ),
									number_format_i18n( $last['files'] ),
									size_format( $last['bytes'] ),
									(int) $last['tables'],
									number_format_i18n( $last['rows'] )
								)
							);
						} else {
							echo 'Ninguna todavía.';
						}
						?>
					</td></tr>
					<tr><th>Copia en curso</th><td>
						<?php
						$active = null;
						foreach ( $jobs as $job ) {
							if ( $job->is_active() ) {
								$active = $job;
								break;
							}
						}
						echo $active ? esc_html( $active->id() . ' — fase: ' . $active->state['phase'] ) : 'No';
						?>
					</td></tr>
					<tr><th>Carpeta de trabajo</th><td><code><?php echo esc_html( $storage ); ?></code>
						<?php if ( $public ) : ?>
							<br><span style="color:#b32d2e">Dentro de la raíz web (protegida con .htaccess y nombre aleatorio). En Nginx, asegúrate de que no se sirve.</span>
						<?php else : ?>
							<br><span style="color:#008a20">Fuera de la raíz web.</span>
						<?php endif; ?>
					</td></tr>
					<tr><th>Límites PHP</th><td>max_execution_time: <?php echo esc_html( ini_get( 'max_execution_time' ) ); ?>s · memory_limit: <?php echo esc_html( ini_get( 'memory_limit' ) ); ?></td></tr>
				</tbody>
			</table>

			<h2>Conexión con el NAS</h2>
			<p>Añade este bloque a <code>sites.json</code> en el NAS. <strong>La clave es secreta:</strong> guárdala en el gestor de contraseñas, no la envíes por correo.</p>
			<?php if ( NBB_Settings::secret_from_constant() ) : ?>
				<p><em>La clave está definida en <code>wp-config.php</code> (constante <code>NBB_SECRET</code>).</em></p>
			<?php endif; ?>
			<p><button type="button" class="button" id="nbb-reveal">Mostrar configuración</button></p>
			<textarea id="nbb-snippet" readonly rows="6" style="display:none;width:100%;max-width:860px;font-family:monospace"><?php echo esc_textarea( $snippet ); ?></textarea>
			<script>
				document.getElementById('nbb-reveal').addEventListener('click', function () {
					var t = document.getElementById('nbb-snippet');
					t.style.display = t.style.display === 'none' ? 'block' : 'none';
					if (t.style.display === 'block') { t.select(); }
				});
			</script>
			<?php if ( ! NBB_Settings::secret_from_constant() ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('¿Regenerar la clave? El NAS dejará de poder conectarse hasta que la actualices.');">
					<?php wp_nonce_field( 'nbb_regenerate' ); ?>
					<input type="hidden" name="action" value="nbb_regenerate">
					<p><button class="button button-secondary">Regenerar clave</button></p>
				</form>
			<?php endif; ?>

			<h2>Qué se copia</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'nbb_save' ); ?>
				<input type="hidden" name="action" value="nbb_save">
				<p>Se copia la instalación completa (<code><?php echo esc_html( ABSPATH ); ?></code>) y las tablas con prefijo <code><?php echo esc_html( $GLOBALS['wpdb']->base_prefix ); ?></code>.</p>
				<p><label><input type="checkbox" name="nbb_all_tables" value="1" <?php checked( NBB_Settings::all_tables() ); ?>> Copiar todas las tablas de la base de datos (no solo las de este WordPress)</label></p>
				<p><strong>Exclusiones</strong> — una por línea, rutas relativas a la instalación, admiten <code>*</code>. Una carpeta excluida se omite entera.</p>
				<textarea name="nbb_excludes" rows="12" style="width:100%;max-width:860px;font-family:monospace"><?php echo esc_textarea( implode( "\n", NBB_Settings::excludes() ) ); ?></textarea>
				<h2>Restauraciones</h2>
				<p><label><input type="checkbox" name="nbb_allow_restore" value="1" <?php checked( NBB_Restore::allowed() ); ?> <?php disabled( defined( 'NBB_ALLOW_RESTORE' ) ); ?>> Permitir que el NAS restaure copias en esta web (base de datos y archivos)</label></p>
				<p class="description" style="max-width:860px">Con esta opción, quien tenga la clave puede sobrescribir la web entera. Actívala solo si la clave está guardada de forma segura. Durante una restauración, los visitantes ven una página de mantenimiento; los usuarios con sesión iniciada siguen viendo la web.</p>
				<?php
				$last_restore = get_option( NBB_Restore::OPT_LAST, null );
				if ( is_array( $last_restore ) ) :
					?>
					<p>Última restauración: <?php echo esc_html( wp_date( 'Y-m-d H:i', (int) $last_restore['finished'] ) . ( $last_restore['source'] ? ' — desde ' . $last_restore['source'] : '' ) ); ?></p>
				<?php endif; ?>
				<p><button class="button button-primary">Guardar</button></p>
			</form>
		</div>
		<?php
	}
}
