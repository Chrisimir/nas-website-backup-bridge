<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Restore endpoints (opt-in per site: Ajustes › NAS Backup › "Permitir restauraciones").
 *
 * The NAS drives the whole restore:
 *   1. POST /restore                       start a session (optionally maintenance mode)
 *   2. POST /restore/{id}/sql              import tables into temporary tables nbbr_<hash>
 *   3. POST /restore/{id}/delete|mkdir     remove extra files / create folders
 *      POST /restore/{id}/pack|chunk       upload files (small in batches, big in chunks)
 *   4. POST /restore/{id}/swap             atomically swap temp tables in (one RENAME TABLE)
 *   5. POST /restore/{id}/finish|abort     clean up, end maintenance mode
 *
 * The live site keeps working until the swap: tables are imported next to the real ones.
 */
class NBB_Restore {

	const OPT_ALLOW   = 'nbb_allow_restore';
	const OPT_SESSION = 'nbb_restore_session';
	const OPT_MAINT   = 'nbb_maintenance_until';
	const OPT_LAST    = 'nbb_last_restore';
	const TMP_PREFIX  = 'nbbr_';
	const OLD_PREFIX  = 'nbbo_';
	const SESSION_TTL = 21600; // 6 h.

	public static function allowed() {
		if ( defined( 'NBB_ALLOW_RESTORE' ) ) {
			return (bool) NBB_ALLOW_RESTORE;
		}
		return (bool) get_option( self::OPT_ALLOW, false );
	}

	/* ------------------------------------------------------------------ */
	/* Maintenance mode for visitors while a restore runs                 */
	/* ------------------------------------------------------------------ */

	public static function maintenance_gate() {
		$until = (int) get_option( self::OPT_MAINT, 0 );
		if ( $until <= time() ) {
			return;
		}
		if ( is_user_logged_in() || is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}
		status_header( 503 );
		header( 'Retry-After: 600' );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>En mantenimiento</title>'
			. '<body style="font-family:system-ui,sans-serif;display:grid;place-items:center;min-height:90vh;margin:0;color:#222">'
			. '<div style="max-width:32rem;padding:2rem;text-align:center"><h1 style="font-weight:600">Volvemos en unos minutos</h1>'
			. '<p>Estamos haciendo tareas de mantenimiento en la web. Gracias por tu paciencia.</p></div></body></html>';
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Session                                                            */
	/* ------------------------------------------------------------------ */

	/** @return true|WP_Error */
	private static function require_allowed() {
		if ( ! self::allowed() ) {
			return new WP_Error( 'nbb_restore_disabled', 'Las restauraciones no están permitidas en esta web. Actívalas en Ajustes › NAS Backup.', array( 'status' => 403 ) );
		}
		return true;
	}

	/** @return array|WP_Error */
	private static function session( WP_REST_Request $request ) {
		$ok = self::require_allowed();
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$s = get_option( self::OPT_SESSION );
		if ( ! is_array( $s ) || ! isset( $s['id'] ) || ! hash_equals( (string) $s['id'], (string) $request['id'] ) ) {
			return new WP_Error( 'nbb_restore_session', 'Sesión de restauración no encontrada.', array( 'status' => 404 ) );
		}
		if ( time() - (int) $s['started'] > self::SESSION_TTL ) {
			return new WP_Error( 'nbb_restore_session', 'La sesión de restauración ha caducado.', array( 'status' => 410 ) );
		}
		return $s;
	}

	public static function start( WP_REST_Request $request ) {
		$ok = self::require_allowed();
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		self::drop_temp_tables();
		$session = array(
			'id'      => bin2hex( random_bytes( 12 ) ),
			'started' => time(),
		);
		update_option( self::OPT_SESSION, $session, false );
		if ( $request->get_param( 'maintenance' ) ) {
			update_option( self::OPT_MAINT, time() + 2 * HOUR_IN_SECONDS, true );
		}
		return $session;
	}

	public static function finish( WP_REST_Request $request ) {
		$s = self::session( $request );
		if ( is_wp_error( $s ) ) {
			return $s;
		}
		self::drop_temp_tables();
		delete_option( self::OPT_MAINT );
		delete_option( self::OPT_SESSION );
		$summary = array(
			'id'       => $s['id'],
			'finished' => time(),
			'source'   => sanitize_text_field( (string) $request->get_param( 'source' ) ),
		);
		update_option( self::OPT_LAST, $summary, false );
		self::flush_caches();
		return $summary;
	}

	public static function abort( WP_REST_Request $request ) {
		$s = self::session( $request );
		if ( is_wp_error( $s ) ) {
			return $s;
		}
		self::drop_temp_tables();
		delete_option( self::OPT_MAINT );
		delete_option( self::OPT_SESSION );
		return array( 'aborted' => true );
	}

	private static function flush_caches() {
		wp_cache_flush();
		if ( function_exists( 'opcache_reset' ) ) {
			@opcache_reset(); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		do_action( 'nbb_after_restore' );
	}

	/* ------------------------------------------------------------------ */
	/* Database                                                           */
	/* ------------------------------------------------------------------ */

	private static function db_exec( $sql ) {
		global $wpdb;
		if ( $wpdb->dbh instanceof mysqli ) {
			$ok = mysqli_query( $wpdb->dbh, $sql );
			if ( false === $ok ) {
				return mysqli_error( $wpdb->dbh );
			}
			if ( $ok instanceof mysqli_result ) {
				mysqli_free_result( $ok );
			}
			return true;
		}
		$wpdb->check_current_query = false;
		$ok                        = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL
		return false === $ok ? $wpdb->last_error : true;
	}

	/** Only statements produced by our own dumper (targeting temp tables) are accepted. */
	private static function statement_allowed( $sql ) {
		$head = ltrim( substr( $sql, 0, 200 ) );
		$t    = preg_quote( self::TMP_PREFIX, '/' );
		$ok   = array(
			'/^SET (NAMES|FOREIGN_KEY_CHECKS|SQL_MODE|UNIQUE_CHECKS|TIME_ZONE)\b/i',
			'/^DROP TABLE IF EXISTS `' . $t . '[a-f0-9]{12}`/',
			'/^CREATE TABLE `' . $t . '[a-f0-9]{12}`/',
			'/^INSERT INTO `' . $t . '[a-f0-9]{12}`/',
			'/^DROP VIEW IF EXISTS `/',
			'/^CREATE (ALGORITHM=\w+ )?(SQL SECURITY \w+ )?VIEW `/i',
		);
		foreach ( $ok as $re ) {
			if ( preg_match( $re, $head ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Parse the raw framed body: repeated "<len>\n<len bytes>".
	 *
	 * @return array|WP_Error
	 */
	private static function parse_frames( $body ) {
		$out = array();
		$pos = 0;
		$len = strlen( $body );
		while ( $pos < $len ) {
			$nl = strpos( $body, "\n", $pos );
			if ( false === $nl ) {
				return new WP_Error( 'nbb_bad_body', 'Cuerpo mal formado.', array( 'status' => 400 ) );
			}
			$n = substr( $body, $pos, $nl - $pos );
			if ( ! ctype_digit( $n ) ) {
				return new WP_Error( 'nbb_bad_body', 'Cuerpo mal formado.', array( 'status' => 400 ) );
			}
			$n   = (int) $n;
			$pos = $nl + 1;
			if ( $pos + $n > $len ) {
				return new WP_Error( 'nbb_bad_body', 'Cuerpo incompleto.', array( 'status' => 400 ) );
			}
			$out[] = substr( $body, $pos, $n );
			$pos  += $n;
		}
		return $out;
	}

	public static function sql( WP_REST_Request $request ) {
		$s = self::session( $request );
		if ( is_wp_error( $s ) ) {
			return $s;
		}
		NBB_Util::extend_limits();
		$stmts = self::parse_frames( $request->get_body() );
		if ( is_wp_error( $stmts ) ) {
			return $stmts;
		}
		foreach ( array( 'SET NAMES utf8mb4', 'SET FOREIGN_KEY_CHECKS=0', "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO'" ) as $init ) {
			self::db_exec( $init );
		}
		$done = 0;
		foreach ( $stmts as $i => $sql ) {
			if ( ! self::statement_allowed( $sql ) ) {
				return new WP_Error( 'nbb_sql_rejected', 'Sentencia no permitida (#' . $i . ').', array( 'status' => 400 ) );
			}
			$res = self::db_exec( $sql );
			if ( true !== $res ) {
				return new WP_Error( 'nbb_sql_error', 'Error SQL en la sentencia #' . $i . ': ' . $res, array( 'status' => 422, 'index' => $i ) );
			}
			$done++;
		}
		return array( 'executed' => $done );
	}

	private static function table_exists( $name ) {
		global $wpdb;
		return $name === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) );
	}

	private static function q( $ident ) {
		return '`' . str_replace( '`', '``', $ident ) . '`';
	}

	public static function temp_name( $table ) {
		return self::TMP_PREFIX . substr( sha1( $table ), 0, 12 );
	}

	private static function drop_temp_tables() {
		global $wpdb;
		foreach ( array( self::TMP_PREFIX, self::OLD_PREFIX ) as $prefix ) {
			$rows = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) );
			foreach ( (array) $rows as $t ) {
				if ( preg_match( '/^nbb[ro]_[a-f0-9]{12}$/', $t ) ) {
					$wpdb->query( 'DROP TABLE IF EXISTS ' . self::q( $t ) ); // phpcs:ignore WordPress.DB.PreparedSQL
				}
			}
		}
	}

	/**
	 * Body: {"tables":["wp_options", ...]} — every listed table must have its temp copy imported.
	 * One RENAME TABLE moves the live tables aside and the restored ones in, atomically.
	 */
	public static function swap( WP_REST_Request $request ) {
		global $wpdb;
		$s = self::session( $request );
		if ( is_wp_error( $s ) ) {
			return $s;
		}
		$body   = json_decode( $request->get_body(), true );
		$tables = is_array( $body ) && isset( $body['tables'] ) && is_array( $body['tables'] ) ? $body['tables'] : null;
		if ( empty( $tables ) ) {
			return new WP_Error( 'nbb_bad_body', 'Lista de tablas vacía.', array( 'status' => 400 ) );
		}

		$renames = array();
		$old     = array();
		foreach ( $tables as $table ) {
			if ( ! is_string( $table ) || '' === $table || strlen( $table ) > 64 ) {
				return new WP_Error( 'nbb_bad_body', 'Nombre de tabla no válido.', array( 'status' => 400 ) );
			}
			$tmp = self::temp_name( $table );
			if ( ! self::table_exists( $tmp ) ) {
				return new WP_Error( 'nbb_swap_missing', 'Falta la tabla importada para ' . $table . '.', array( 'status' => 409 ) );
			}
			if ( self::table_exists( $table ) ) {
				$o         = self::OLD_PREFIX . substr( sha1( $table ), 0, 12 );
				$renames[] = self::q( $table ) . ' TO ' . self::q( $o );
				$old[]     = $o;
			}
			$renames[] = self::q( $tmp ) . ' TO ' . self::q( $table );
		}

		// Keep this plugin's own settings (key, restore permission, storage) across the swap.
		$opt_table = $wpdb->base_prefix . 'options';
		$keep      = $wpdb->get_results( $wpdb->prepare( 'SELECT option_name, option_value, autoload FROM ' . self::q( $opt_table ) . ' WHERE option_name LIKE %s AND option_name <> %s', $wpdb->esc_like( 'nbb_' ) . '%', 'nbb_last_job' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

		$res = self::db_exec( 'RENAME TABLE ' . implode( ', ', $renames ) );
		if ( true !== $res ) {
			return new WP_Error( 'nbb_swap_error', 'No se pudieron intercambiar las tablas: ' . $res, array( 'status' => 500 ) );
		}
		foreach ( $old as $o ) {
			self::db_exec( 'DROP TABLE IF EXISTS ' . self::q( $o ) );
		}

		if ( self::table_exists( $opt_table ) ) {
			foreach ( (array) $keep as $row ) {
				$wpdb->replace( $opt_table, $row, array( '%s', '%s', '%s' ) );
			}
		}
		self::flush_caches();
		return array( 'swapped' => count( $tables ) );
	}

	/* ------------------------------------------------------------------ */
	/* Files                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Resolve a destination path for writing. Every existing component must be a
	 * real directory (no symlinks) inside the root; never this plugin or its storage.
	 *
	 * @return string|WP_Error Absolute path (may not exist yet).
	 */
	public static function resolve_write( $root_id, $rel ) {
		$roots = NBB_Scanner::roots();
		if ( ! is_string( $root_id ) || ! isset( $roots[ $root_id ] ) ) {
			return new WP_Error( 'nbb_bad_root', 'Raíz no válida.', array( 'status' => 400 ) );
		}
		$rel = is_string( $rel ) ? $rel : '';
		if ( '' === $rel || false !== strpos( $rel, "\0" ) || false !== strpos( $rel, '\\' ) || '/' === $rel[0] || preg_match( '#(^|/)\.\.?(/|$)#', $rel ) || false !== strpos( $rel, '//' ) ) {
			return new WP_Error( 'nbb_bad_path', 'Ruta no válida.', array( 'status' => 400 ) );
		}
		$root = $roots[ $root_id ];
		if ( null !== $root['only'] && ! in_array( $rel, $root['only'], true ) ) {
			return new WP_Error( 'nbb_bad_path', 'Ruta no permitida.', array( 'status' => 403 ) );
		}
		$base  = realpath( $root['path'] );
		$path  = $base;
		$parts = explode( '/', $rel );
		foreach ( $parts as $part ) {
			$path .= '/' . $part;
			if ( is_link( $path ) ) {
				return new WP_Error( 'nbb_bad_path', 'La ruta atraviesa un enlace simbólico.', array( 'status' => 403 ) );
			}
		}
		$protected = array( realpath( NBB_DIR ), realpath( NBB_Storage::dir() ) );
		foreach ( $protected as $p ) {
			if ( $p && NBB_Util::is_inside( $path, $p ) ) {
				return new WP_Error( 'nbb_bad_path', 'Ruta protegida.', array( 'status' => 403 ) );
			}
		}
		return $path;
	}

	private static function set_file( $target, $tmp, $mtime ) {
		$mode = file_exists( $target ) ? ( fileperms( $target ) & 0777 ) : 0644;
		if ( is_dir( $target ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return 'Existe una carpeta con ese nombre';
		}
		if ( ! @rename( $tmp, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return 'No se pudo escribir (permisos)';
		}
		@chmod( $target, $mode ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( $mtime > 0 ) {
			@touch( $target, $mtime ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		if ( function_exists( 'opcache_invalidate' ) && '.php' === substr( $target, -4 ) ) {
			@opcache_invalidate( $target, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		return true;
	}

	private static function tmp_name( $target ) {
		return dirname( $target ) . '/.nbbpart-' . substr( sha1( $target ), 0, 16 );
	}

	/**
	 * Body: "NBBUP1\n" then per file "<json {r,p,s,m}>\n<s bytes>", then "END\n".
	 */
	public static function pack( WP_REST_Request $request ) {
		$s = self::session( $request );
		if ( is_wp_error( $s ) ) {
			return $s;
		}
		NBB_Util::extend_limits();
		$body = $request->get_body();
		if ( 0 !== strncmp( $body, "NBBUP1\n", 7 ) ) {
			return new WP_Error( 'nbb_bad_body', 'Formato de subida no reconocido.', array( 'status' => 400 ) );
		}
		$pos     = 7;
		$len     = strlen( $body );
		$written = 0;
		$errors  = array();
		while ( $pos < $len ) {
			$nl = strpos( $body, "\n", $pos );
			if ( false === $nl ) {
				return new WP_Error( 'nbb_bad_body', 'Subida incompleta.', array( 'status' => 400 ) );
			}
			$line = substr( $body, $pos, $nl - $pos );
			$pos  = $nl + 1;
			if ( 'END' === $line ) {
				break;
			}
			$h = json_decode( $line, true );
			if ( ! is_array( $h ) || ! isset( $h['r'], $h['p'], $h['s'] ) || $pos + (int) $h['s'] > $len ) {
				return new WP_Error( 'nbb_bad_body', 'Cabecera de archivo no válida.', array( 'status' => 400 ) );
			}
			$data = substr( $body, $pos, (int) $h['s'] );
			$pos += (int) $h['s'];

			$target = self::resolve_write( $h['r'], $h['p'] );
			if ( is_wp_error( $target ) ) {
				$errors[] = array( 'r' => $h['r'], 'p' => $h['p'], 'e' => $target->get_error_message() );
				continue;
			}
			if ( ! is_dir( dirname( $target ) ) && ! wp_mkdir_p( dirname( $target ) ) ) {
				$errors[] = array( 'r' => $h['r'], 'p' => $h['p'], 'e' => 'No se pudo crear la carpeta' );
				continue;
			}
			$tmp = self::tmp_name( $target );
			if ( false === file_put_contents( $tmp, $data ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				$errors[] = array( 'r' => $h['r'], 'p' => $h['p'], 'e' => 'No se pudo escribir (permisos o espacio)' );
				continue;
			}
			$res = self::set_file( $target, $tmp, isset( $h['m'] ) ? (int) $h['m'] : 0 );
			if ( true === $res ) {
				$written++;
			} else {
				$errors[] = array( 'r' => $h['r'], 'p' => $h['p'], 'e' => $res );
			}
		}
		return array(
			'written' => $written,
			'errors'  => $errors,
		);
	}

	/** Big files: ?r=&p=&offset=N[&final=1&mtime=T], raw body = bytes. */
	public static function chunk( WP_REST_Request $request ) {
		$s = self::session( $request );
		if ( is_wp_error( $s ) ) {
			return $s;
		}
		NBB_Util::extend_limits();
		$target = self::resolve_write( $request->get_param( 'r' ), $request->get_param( 'p' ) );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		if ( ! is_dir( dirname( $target ) ) && ! wp_mkdir_p( dirname( $target ) ) ) {
			return new WP_Error( 'nbb_write', 'No se pudo crear la carpeta.', array( 'status' => 500 ) );
		}
		$tmp    = self::tmp_name( $target );
		$offset = (int) $request->get_param( 'offset' );
		clearstatcache( true, $tmp );
		$have = file_exists( $tmp ) ? (int) filesize( $tmp ) : 0;
		if ( 0 === $offset ) {
			$have = 0;
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		if ( $have !== $offset ) {
			return new WP_Error( 'nbb_offset', 'Desfase en la subida.', array( 'status' => 409, 'size' => $have ) );
		}
		$data = $request->get_body();
		if ( '' !== $data && false === file_put_contents( $tmp, $data, FILE_APPEND ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new WP_Error( 'nbb_write', 'No se pudo escribir (permisos o espacio).', array( 'status' => 500 ) );
		}
		if ( 0 === $offset && '' === $data ) {
			touch( $tmp ); // Empty file.
		}
		$size = $offset + strlen( $data );
		if ( $request->get_param( 'final' ) ) {
			$res = self::set_file( $target, $tmp, (int) $request->get_param( 'mtime' ) );
			if ( true !== $res ) {
				return new WP_Error( 'nbb_write', $res, array( 'status' => 500 ) );
			}
		}
		return array( 'size' => $size );
	}

	/** Body: {"files":[{"r","p"}], "dirs":[{"r","p"}]} — dirs removed only if empty (send deepest first). */
	public static function delete( WP_REST_Request $request ) {
		$s = self::session( $request );
		if ( is_wp_error( $s ) ) {
			return $s;
		}
		$body    = json_decode( $request->get_body(), true );
		$deleted = 0;
		$errors  = array();
		foreach ( array( 'files', 'dirs' ) as $kind ) {
			$items = is_array( $body ) && isset( $body[ $kind ] ) && is_array( $body[ $kind ] ) ? $body[ $kind ] : array();
			foreach ( $items as $it ) {
				$path = self::resolve_write( isset( $it['r'] ) ? $it['r'] : '', isset( $it['p'] ) ? $it['p'] : '' );
				if ( is_wp_error( $path ) ) {
					$errors[] = array( 'p' => isset( $it['p'] ) ? $it['p'] : '', 'e' => $path->get_error_message() );
					continue;
				}
				if ( 'files' === $kind ) {
					if ( is_file( $path ) && @unlink( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
						$deleted++;
					} elseif ( file_exists( $path ) ) {
						$errors[] = array( 'p' => $it['p'], 'e' => 'No se pudo borrar' );
					}
				} elseif ( is_dir( $path ) ) {
					$list = @scandir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
					if ( is_array( $list ) && count( $list ) <= 2 && @rmdir( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
						$deleted++;
					}
				}
			}
		}
		return array(
			'deleted' => $deleted,
			'errors'  => $errors,
		);
	}

	/** Body: {"dirs":[{"r","p"}]} */
	public static function mkdir( WP_REST_Request $request ) {
		$s = self::session( $request );
		if ( is_wp_error( $s ) ) {
			return $s;
		}
		$body    = json_decode( $request->get_body(), true );
		$created = 0;
		$errors  = array();
		$items   = is_array( $body ) && isset( $body['dirs'] ) && is_array( $body['dirs'] ) ? $body['dirs'] : array();
		foreach ( $items as $it ) {
			$path = self::resolve_write( isset( $it['r'] ) ? $it['r'] : '', isset( $it['p'] ) ? $it['p'] : '' );
			if ( is_wp_error( $path ) ) {
				$errors[] = array( 'p' => isset( $it['p'] ) ? $it['p'] : '', 'e' => $path->get_error_message() );
				continue;
			}
			if ( is_dir( $path ) ) {
				continue;
			}
			if ( wp_mkdir_p( $path ) ) {
				$created++;
			} else {
				$errors[] = array( 'p' => $it['p'], 'e' => 'No se pudo crear' );
			}
		}
		return array(
			'created' => $created,
			'errors'  => $errors,
		);
	}
}
