<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A backup job: dump DB -> scan files -> finalize (meta + checksums).
 * Driven by the NAS calling /step repeatedly; nothing runs in the background.
 */
class NBB_Job {

	const STALE_SECONDS  = 7200;  // An unfinished job untouched for 2 h is abandoned.
	const PURGE_SECONDS  = 86400; // Leftover job folders are purged after 24 h.

	/** @var string */
	private $id;
	/** @var string */
	private $dir;
	/** @var array */
	public $state;

	private function __construct( $id ) {
		$this->id  = $id;
		$this->dir = self::jobs_dir() . '/' . $id;
	}

	public static function jobs_dir() {
		$dir = NBB_Storage::dir() . '/jobs';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return $dir;
	}

	public static function valid_id( $id ) {
		return is_string( $id ) && (bool) preg_match( '/^[a-f0-9]{24}$/', $id );
	}

	public function id() {
		return $this->id;
	}

	public function dir() {
		return $this->dir;
	}

	private function state_file() {
		return $this->dir . '/state.json';
	}

	public static function load( $id ) {
		if ( ! self::valid_id( $id ) ) {
			return null;
		}
		$job        = new self( $id );
		$job->state = NBB_Util::read_json( $job->state_file() );
		return is_array( $job->state ) ? $job : null;
	}

	/** Jobs currently on disk, newest first. */
	public static function all() {
		$jobs = array();
		$dir  = self::jobs_dir();
		$list = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		foreach ( (array) $list as $name ) {
			$job = self::valid_id( $name ) ? self::load( $name ) : null;
			if ( $job ) {
				$jobs[] = $job;
			}
		}
		usort(
			$jobs,
			function ( $a, $b ) {
				return $b->state['created'] - $a->state['created'];
			}
		);
		return $jobs;
	}

	public function is_active() {
		return ! in_array( $this->state['phase'], array( 'done', 'error' ), true )
			&& ( time() - (int) $this->state['updated'] ) < self::STALE_SECONDS;
	}

	/**
	 * @param bool $force Replace a running job.
	 * @return NBB_Job|WP_Error
	 */
	public static function create( $force = false ) {
		foreach ( self::all() as $job ) {
			if ( $job->is_active() && ! $force ) {
				return new WP_Error( 'nbb_busy', 'Ya hay una copia en curso.', array( 'status' => 409, 'job' => $job->id() ) );
			}
			$job->destroy(); // Only one job folder at a time: they contain a full DB dump.
		}
		$job = new self( bin2hex( random_bytes( 12 ) ) );
		if ( ! wp_mkdir_p( $job->dir . '/db' ) ) {
			return new WP_Error( 'nbb_storage', 'No se puede crear la carpeta de trabajo.', array( 'status' => 500 ) );
		}
		$now        = time();
		$job->state = array(
			'id'      => $job->id,
			'created' => $now,
			'updated' => $now,
			'phase'   => 'db',
			'steps'   => 0,
			'db'      => NBB_Db_Dumper::init_state( NBB_Settings::all_tables() ),
			'scan'    => NBB_Scanner::init_state(),
			'error'   => null,
		);
		$job->save();
		return $job;
	}

	public function save() {
		$this->state['updated'] = time();
		NBB_Util::write_json_atomic( $this->state_file(), $this->state );
	}

	/**
	 * Run for up to $budget seconds.
	 *
	 * @return array|WP_Error Public status.
	 */
	public function step( $budget ) {
		$lock = fopen( $this->dir . '/state.lock', 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
			return new WP_Error( 'nbb_locked', 'Otro paso de esta copia está en ejecución.', array( 'status' => 409 ) );
		}
		// Re-read under the lock.
		$fresh = NBB_Util::read_json( $this->state_file() );
		if ( is_array( $fresh ) ) {
			$this->state = $fresh;
		}

		$deadline = microtime( true ) + $budget;
		$self     = $this;
		try {
			if ( 'db' === $this->state['phase'] ) {
				$dumper = new NBB_Db_Dumper(
					$this->dir,
					$this->state['db'],
					function ( $s ) use ( $self ) {
						$self->state['db'] = $s;
						$self->save();
					}
				);
				$finished          = $dumper->run( $deadline );
				$this->state['db'] = $dumper->state();
				if ( $finished ) {
					$this->state['phase'] = 'scan';
				}
			}
			if ( 'scan' === $this->state['phase'] && microtime( true ) < $deadline ) {
				$scanner = new NBB_Scanner(
					$this->dir,
					$this->state['scan'],
					function ( $s ) use ( $self ) {
						$self->state['scan'] = $s;
						$self->save();
					}
				);
				$finished            = $scanner->run( $deadline );
				$this->state['scan'] = $scanner->state();
				if ( $finished ) {
					$this->state['phase'] = 'finalize';
				}
			}
			if ( 'finalize' === $this->state['phase'] && microtime( true ) < $deadline ) {
				$this->finalize();
				$this->state['phase'] = 'done';
			}
		} catch ( Throwable $e ) {
			$this->state['phase'] = 'error';
			$this->state['error'] = $e->getMessage();
		}
		$this->state['steps']++;
		$this->save();
		flock( $lock, LOCK_UN );
		fclose( $lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $this->status();
	}

	private function finalize() {
		global $wp_version, $wpdb;
		$meta = array(
			'protocol'       => NBB_PROTOCOL,
			'plugin_version' => NBB_VERSION,
			'job'            => $this->id,
			'created'        => gmdate( 'c', (int) $this->state['created'] ),
			'finished'       => gmdate( 'c' ),
			'site_url'       => site_url(),
			'home_url'       => home_url(),
			'wp_version'     => $wp_version,
			'php_version'    => PHP_VERSION,
			'db_version'     => $wpdb->db_version(),
			'db_name'        => DB_NAME,
			'table_prefix'   => $wpdb->base_prefix,
			'multisite'      => is_multisite(),
			'tables'         => $this->state['db']['tables'],
			'views'          => $this->state['db']['views'],
			'rows'           => $this->state['db']['rows'],
			'roots'          => array_keys( NBB_Scanner::roots() ),
			'files'          => $this->state['scan']['files'],
			'dirs'           => $this->state['scan']['dirs'],
			'links'          => $this->state['scan']['links'],
			'bytes'          => $this->state['scan']['bytes'],
			'scan_errors'    => $this->state['scan']['errors'],
			'excludes'       => NBB_Settings::excludes(),
			'restore'        => 'Archivos: copiar files/<root>/ a su ruta. Base de datos: zcat db/*.sql.gz | mysql <db>.',
		);
		file_put_contents( $this->dir . '/meta.json', wp_json_encode( $meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$artifacts = array();
		$names     = array( 'meta.json', 'manifest.ndjson.gz' );
		foreach ( (array) glob( $this->dir . '/db/*.sql.gz' ) as $file ) {
			$names[] = 'db/' . basename( $file );
		}
		foreach ( $names as $name ) {
			$path = $this->dir . '/' . $name;
			if ( ! is_file( $path ) ) {
				if ( 'manifest.ndjson.gz' === $name ) {
					// Empty manifest (should never happen) — still produce a valid file.
					$gz = gzopen( $path, 'wb6' );
					gzclose( $gz );
				} else {
					continue;
				}
			}
			$artifacts[] = array(
				'name'   => $name,
				'size'   => (int) filesize( $path ),
				'sha256' => hash_file( 'sha256', $path ),
			);
		}
		$this->state['artifacts'] = $artifacts;
	}

	public function artifact_path( $name ) {
		if ( ! is_string( $name ) || ! preg_match( '#^(meta\.json|manifest\.ndjson\.gz|db/[0-9]{4}-[A-Za-z0-9_\-]+\.sql\.gz)$#', $name ) ) {
			return null;
		}
		$path = $this->dir . '/' . $name;
		return is_file( $path ) ? $path : null;
	}

	public function status() {
		$db   = $this->state['db'];
		$scan = $this->state['scan'];
		return array(
			'id'        => $this->id,
			'phase'     => $this->state['phase'],
			'error'     => $this->state['error'],
			'steps'     => $this->state['steps'],
			'created'   => (int) $this->state['created'],
			'updated'   => (int) $this->state['updated'],
			'db'        => array(
				'tables_total' => count( $db['tables'] ),
				'tables_done'  => (int) $db['idx'],
				'current'      => $db['cur'] ? $db['cur']['table'] : null,
				'rows'         => (int) $db['rows'],
			),
			'scan'      => array(
				'files'   => (int) $scan['files'],
				'dirs'    => (int) $scan['dirs'],
				'bytes'   => (int) $scan['bytes'],
				'pending' => count( $scan['stack'] ),
				'errors'  => count( $scan['errors'] ),
			),
			'artifacts' => isset( $this->state['artifacts'] ) ? $this->state['artifacts'] : null,
		);
	}

	/** Called by the NAS once it has everything. */
	public function complete() {
		$summary = array(
			'id'        => $this->id,
			'completed' => time(),
			'files'     => (int) $this->state['scan']['files'],
			'bytes'     => (int) $this->state['scan']['bytes'],
			'tables'    => count( $this->state['db']['tables'] ),
			'rows'      => (int) $this->state['db']['rows'],
		);
		if ( 'done' === $this->state['phase'] ) {
			update_option( NBB_Settings::OPT_LAST, $summary, false );
		}
		$this->destroy();
		return $summary;
	}

	public function destroy() {
		NBB_Util::rrmdir( $this->dir );
	}

	/** Daily cron: delete leftover job folders. */
	public static function cleanup_stale() {
		foreach ( self::all() as $job ) {
			if ( ( time() - (int) $job->state['updated'] ) > self::PURGE_SECONDS ) {
				$job->destroy();
			}
		}
	}
}
