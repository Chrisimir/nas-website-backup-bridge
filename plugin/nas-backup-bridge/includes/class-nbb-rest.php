<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API (namespace nbb/v1). Every route requires an HMAC signature (NBB_Auth).
 * Works with pretty permalinks (/wp-json/nbb/v1/...) and with ?rest_route=/nbb/v1/...
 */
class NBB_Rest {

	const NS              = 'nbb/v1';
	const ID              = '(?P<id>[a-f0-9]{24})';
	const PACK_MAX_FILES  = 5000;
	const CHUNK           = 1048576;

	/** Mark every response of this API so the client can tell it apart from a theme page. */
	public static function mark_response( $response, $server, $request ) {
		if ( NBB_Util::starts_with( (string) $request->get_route(), '/' . self::NS ) && $response instanceof WP_HTTP_Response ) {
			$response->header( 'X-NBB-Protocol', (string) NBB_PROTOCOL );
		}
		return $response;
	}

	public static function register_routes() {
		$auth = array( 'NBB_Auth', 'check' );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'mark_response' ), 10, 3 );

		register_rest_route(
			self::NS,
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'status' ),
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			self::NS,
			'/jobs',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create_job' ),
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			self::NS,
			'/jobs/' . self::ID,
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_job' ),
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			self::NS,
			'/jobs/' . self::ID . '/step',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'step_job' ),
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			self::NS,
			'/jobs/' . self::ID . '/artifact',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'artifact' ),
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			self::NS,
			'/jobs/' . self::ID . '/complete',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'complete_job' ),
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			self::NS,
			'/file',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'file' ),
				'permission_callback' => $auth,
			)
		);
		$restore = array(
			'/restore'                        => 'start',
			'/restore/' . self::ID . '/sql'    => 'sql',
			'/restore/' . self::ID . '/swap'   => 'swap',
			'/restore/' . self::ID . '/pack'   => 'pack',
			'/restore/' . self::ID . '/chunk'  => 'chunk',
			'/restore/' . self::ID . '/delete' => 'delete',
			'/restore/' . self::ID . '/mkdir'  => 'mkdir',
			'/restore/' . self::ID . '/finish' => 'finish',
			'/restore/' . self::ID . '/abort'  => 'abort',
		);
		foreach ( $restore as $route => $method ) {
			register_rest_route(
				self::NS,
				$route,
				array(
					'methods'             => 'POST',
					'callback'            => array( 'NBB_Restore', $method ),
					'permission_callback' => $auth,
				)
			);
		}
		register_rest_route(
			self::NS,
			'/pack',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'pack' ),
				'permission_callback' => $auth,
			)
		);
	}

	public static function status() {
		global $wp_version;
		$active = null;
		foreach ( NBB_Job::all() as $job ) {
			if ( $job->is_active() ) {
				$active = $job->id();
				break;
			}
		}
		return array(
			'plugin_version'     => NBB_VERSION,
			'protocol'           => NBB_PROTOCOL,
			'site_url'           => site_url(),
			'wp_version'         => $wp_version,
			'php_version'        => PHP_VERSION,
			'roots'              => array_keys( NBB_Scanner::roots() ),
			'storage_public'     => NBB_Storage::is_public(),
			'max_execution_time' => (int) ini_get( 'max_execution_time' ),
			'memory_limit'       => (string) ini_get( 'memory_limit' ),
			'active_job'         => $active,
			'last_job'           => get_option( NBB_Settings::OPT_LAST, null ),
			'server_time'        => time(),
			'restore_allowed'    => NBB_Restore::allowed(),
			'last_restore'       => get_option( NBB_Restore::OPT_LAST, null ),
			'max_body'           => self::max_body(),
			'plugin_path'        => self::plugin_path(),
		);
	}

	/** Largest request body PHP will accept (bytes), or 0 if unknown. */
	private static function max_body() {
		$post = wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) );
		return $post > 0 ? $post : 0;
	}

	/** Where this plugin lives, as {r, p}, so the NAS never overwrites or deletes it. */
	private static function plugin_path() {
		$dir = NBB_Util::norm_dir( realpath( NBB_DIR ) );
		foreach ( NBB_Scanner::roots() as $id => $root ) {
			$base = NBB_Util::norm_dir( realpath( $root['path'] ) );
			if ( null === $root['only'] && NBB_Util::is_inside( $dir, $base ) ) {
				return array(
					'r' => $id,
					'p' => ltrim( substr( $dir, strlen( $base ) ), '/' ),
				);
			}
		}
		return null;
	}

	public static function create_job( WP_REST_Request $request ) {
		$job = NBB_Job::create( (bool) $request->get_param( 'force' ), (bool) $request->get_param( 'skip_db' ) );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		return $job->status();
	}

	private static function job_or_error( WP_REST_Request $request ) {
		$job = NBB_Job::load( $request['id'] );
		if ( ! $job ) {
			return new WP_Error( 'nbb_not_found', 'Copia no encontrada.', array( 'status' => 404 ) );
		}
		return $job;
	}

	public static function get_job( WP_REST_Request $request ) {
		$job = self::job_or_error( $request );
		return is_wp_error( $job ) ? $job : $job->status();
	}

	public static function step_job( WP_REST_Request $request ) {
		$job = self::job_or_error( $request );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		NBB_Util::extend_limits();
		return $job->step( NBB_Util::step_budget( $request->get_param( 'budget' ) ) );
	}

	public static function complete_job( WP_REST_Request $request ) {
		$job = self::job_or_error( $request );
		return is_wp_error( $job ) ? $job : $job->complete();
	}

	public static function artifact( WP_REST_Request $request ) {
		$job = self::job_or_error( $request );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		$path = $job->artifact_path( $request->get_param( 'name' ) );
		if ( ! $path ) {
			return new WP_Error( 'nbb_not_found', 'Artefacto no encontrado.', array( 'status' => 404 ) );
		}
		self::stream_file( $path, null );
	}

	/**
	 * Resolve a (root, relative path) pair to an absolute, readable file inside that root.
	 *
	 * @return string|WP_Error
	 */
	public static function resolve( $root_id, $rel ) {
		$roots = NBB_Scanner::roots();
		if ( ! is_string( $root_id ) || ! isset( $roots[ $root_id ] ) ) {
			return new WP_Error( 'nbb_bad_root', 'Raíz no válida.', array( 'status' => 400 ) );
		}
		$rel = is_string( $rel ) ? str_replace( '\\', '/', $rel ) : '';
		if ( '' === $rel || false !== strpos( $rel, "\0" ) || '/' === $rel[0] || preg_match( '#(^|/)\.\.?(/|$)#', $rel ) ) {
			return new WP_Error( 'nbb_bad_path', 'Ruta no válida.', array( 'status' => 400 ) );
		}
		$root = $roots[ $root_id ];
		if ( null !== $root['only'] && ! in_array( $rel, $root['only'], true ) ) {
			return new WP_Error( 'nbb_bad_path', 'Ruta no permitida.', array( 'status' => 403 ) );
		}
		$abs  = $root['path'] . '/' . $rel;
		$real = realpath( $abs );
		if ( ! $real || ! is_file( $real ) || is_link( $abs ) ) {
			return new WP_Error( 'nbb_not_found', 'Archivo no encontrado.', array( 'status' => 404 ) );
		}
		if ( ! NBB_Util::is_inside( $real, realpath( $root['path'] ) ) ) {
			return new WP_Error( 'nbb_bad_path', 'Ruta fuera de la raíz.', array( 'status' => 403 ) );
		}
		$storage = realpath( NBB_Storage::dir() );
		if ( $storage && NBB_Util::is_inside( $real, $storage ) ) {
			return new WP_Error( 'nbb_bad_path', 'Ruta no permitida.', array( 'status' => 403 ) );
		}
		return $real;
	}

	public static function file( WP_REST_Request $request ) {
		$path = self::resolve( $request->get_param( 'root' ), $request->get_param( 'path' ) );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		$offset = null;
		$range  = $request->get_header( 'range' );
		if ( $range && preg_match( '/^bytes=(\d+)-$/', trim( $range ), $m ) ) {
			$offset = (int) $m[1];
		}
		self::stream_file( $path, $offset );
	}

	private static function begin_stream() {
		NBB_Util::extend_limits();
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		if ( function_exists( 'apache_setenv' ) ) {
			@apache_setenv( 'no-gzip', '1' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		@ini_set( 'zlib.output_compression', '0' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.IniSet
		header( 'X-NBB-Protocol: ' . NBB_PROTOCOL );
		header( 'Cache-Control: no-store, private' );
		header( 'X-Accel-Buffering: no' );
		header( 'X-Robots-Tag: noindex' );
		header( 'Content-Type: application/octet-stream' );
	}

	private static function stream_file( $path, $offset ) {
		$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $fh ) {
			status_header( 500 );
			exit;
		}
		$stat = fstat( $fh );
		$size = (int) $stat['size'];
		self::begin_stream();
		header( 'X-NBB-Size: ' . $size );
		header( 'X-NBB-Mtime: ' . (int) $stat['mtime'] );
		header( 'Accept-Ranges: bytes' );
		if ( null !== $offset && $offset > 0 && $offset < $size ) {
			status_header( 206 );
			header( 'Content-Range: bytes ' . $offset . '-' . ( $size - 1 ) . '/' . $size );
			header( 'Content-Length: ' . ( $size - $offset ) );
			fseek( $fh, $offset );
			$remaining = $size - $offset;
		} else {
			status_header( 200 );
			header( 'Content-Length: ' . $size );
			$remaining = $size;
		}
		while ( $remaining > 0 && ! feof( $fh ) ) {
			$chunk = fread( $fh, (int) min( self::CHUNK, $remaining ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput
			$remaining -= strlen( $chunk );
			flush();
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * Stream many small files in one response.
	 *
	 * Body: {"files":[{"r":"site","p":"wp-content/a.jpg"}, ...]}
	 * Response (application/octet-stream):
	 *   "NBBPACK1\n"
	 *   per file: <json header line>\n<exactly "s" bytes><"\nOK\n" | "\nTRUNC\n">
	 *             header = {"r":..,"p":..,"ok":true,"s":size,"m":mtime}
	 *             or     {"r":..,"p":..,"ok":false,"e":"reason"} (no data, no trailer)
	 *   "END\n"
	 */
	public static function pack( WP_REST_Request $request ) {
		$body  = json_decode( $request->get_body(), true );
		$files = is_array( $body ) && isset( $body['files'] ) && is_array( $body['files'] ) ? $body['files'] : null;
		if ( null === $files || count( $files ) > self::PACK_MAX_FILES ) {
			return new WP_Error( 'nbb_bad_request', 'Lista de archivos no válida.', array( 'status' => 400 ) );
		}
		self::begin_stream();
		status_header( 200 );
		echo "NBBPACK1\n"; // phpcs:ignore WordPress.Security.EscapeOutput
		foreach ( $files as $item ) {
			$r    = isset( $item['r'] ) ? (string) $item['r'] : '';
			$p    = isset( $item['p'] ) ? (string) $item['p'] : '';
			$path = self::resolve( $r, $p );
			if ( is_wp_error( $path ) ) {
				echo wp_json_encode( array( 'r' => $r, 'p' => $p, 'ok' => false, 'e' => $path->get_error_code() ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
				continue;
			}
			$fh = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			if ( ! $fh ) {
				echo wp_json_encode( array( 'r' => $r, 'p' => $p, 'ok' => false, 'e' => 'unreadable' ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
				continue;
			}
			$stat = fstat( $fh );
			$size = (int) $stat['size'];
			echo wp_json_encode( array( 'r' => $r, 'p' => $p, 'ok' => true, 's' => $size, 'm' => (int) $stat['mtime'] ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
			$remaining = $size;
			while ( $remaining > 0 ) {
				$chunk = fread( $fh, (int) min( self::CHUNK, $remaining ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				if ( false === $chunk || '' === $chunk ) {
					break;
				}
				echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput
				$remaining -= strlen( $chunk );
			}
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( $remaining > 0 ) {
				// File shrank while reading: pad so the stream stays aligned, flag it.
				while ( $remaining > 0 ) {
					$n = (int) min( self::CHUNK, $remaining );
					echo str_repeat( "\0", $n ); // phpcs:ignore WordPress.Security.EscapeOutput
					$remaining -= $n;
				}
				echo "\nTRUNC\n";
			} else {
				echo "\nOK\n";
			}
			flush();
		}
		echo "END\n";
		exit;
	}
}
