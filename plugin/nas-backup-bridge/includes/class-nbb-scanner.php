<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resumable file scanner. Produces manifest.ndjson.gz with one JSON object per line:
 *   {"t":"f","r":"site","p":"wp-content/uploads/a.jpg","s":1234,"m":1700000000}
 *   {"t":"d","r":"site","p":"wp-content/uploads"}
 *   {"t":"l","r":"site","p":"some/link","l":"target"}      (symlinks are recorded, never followed)
 *
 * Roots:
 *   site       ABSPATH (the whole WordPress install)
 *   wp-content WP_CONTENT_DIR, only when it lives outside ABSPATH
 *   parent     only wp-config.php, when it lives one level above ABSPATH
 */
class NBB_Scanner {

	const FLUSH_SECONDS = 1.0;
	const FLUSH_LINES   = 2000;

	/** @var string */
	private $dir;
	/** @var array */
	private $state;
	/** @var callable|null */
	private $persist;
	/** @var string */
	private $buffer = '';
	/** @var int */
	private $buffered_lines = 0;

	public function __construct( $dir, array $state, $persist = null ) {
		$this->dir     = $dir;
		$this->state   = $state;
		$this->persist = $persist;
	}

	public function state() {
		return $this->state;
	}

	/** @return array<string,array{path:string,only:array|null}> */
	public static function roots() {
		$roots = array();
		$site  = realpath( ABSPATH );
		$roots['site'] = array(
			'path' => NBB_Util::norm_dir( $site ),
			'only' => null,
		);
		$content = realpath( WP_CONTENT_DIR );
		if ( $content && ! NBB_Util::is_inside( $content, $site ) ) {
			$roots['wp-content'] = array(
				'path' => NBB_Util::norm_dir( $content ),
				'only' => null,
			);
		}
		if ( ! file_exists( $site . '/wp-config.php' ) ) {
			$parent = NBB_Util::norm_dir( dirname( $site ) );
			if ( @is_file( $parent . '/wp-config.php' ) && ! @is_file( $parent . '/wp-settings.php' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				$roots['parent'] = array(
					'path' => $parent,
					'only' => array( 'wp-config.php' ),
				);
			}
		}
		return $roots;
	}

	public static function init_state() {
		$stack = array();
		foreach ( self::roots() as $id => $root ) {
			$stack[] = array( $id, '' );
		}
		return array(
			'stack'  => array_reverse( $stack ),
			'files'  => 0,
			'dirs'   => 0,
			'links'  => 0,
			'bytes'  => 0,
			'errors' => array(),
			'out'    => 0,
			'done'   => false,
		);
	}

	public static function is_excluded( $rel, array $patterns ) {
		foreach ( $patterns as $pattern ) {
			$pattern = trim( $pattern, '/' );
			if ( '' === $pattern ) {
				continue;
			}
			if ( fnmatch( $pattern, $rel ) ) {
				return true;
			}
		}
		return false;
	}

	private function manifest_file() {
		return $this->dir . '/manifest.ndjson.gz';
	}

	private function size_of( $file ) {
		clearstatcache( true, $file );
		return file_exists( $file ) ? (int) filesize( $file ) : 0;
	}

	private function emit( array $entry ) {
		$this->buffer .= wp_json_encode( $entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE ) . "\n";
		$this->buffered_lines++;
	}

	private function flush( array $stack ) {
		if ( '' !== $this->buffer ) {
			$gz = gzopen( $this->manifest_file(), 'ab6' );
			if ( ! $gz ) {
				throw new RuntimeException( 'No se puede escribir el manifiesto.' );
			}
			gzwrite( $gz, $this->buffer );
			gzclose( $gz );
			$this->buffer         = '';
			$this->buffered_lines = 0;
		}
		$this->state['stack'] = $stack;
		$this->state['out']   = $this->size_of( $this->manifest_file() );
		if ( $this->persist ) {
			call_user_func( $this->persist, $this->state );
		}
	}

	private function add_error( $msg ) {
		if ( count( $this->state['errors'] ) < 100 ) {
			$this->state['errors'][] = $msg;
		}
	}

	/**
	 * @param float $deadline microtime(true) value to stop at.
	 * @return bool true when the scan is complete.
	 */
	public function run( $deadline ) {
		// Discard anything written after the last checkpoint (interrupted request).
		$file = $this->manifest_file();
		if ( $this->size_of( $file ) > $this->state['out'] ) {
			$fh = fopen( $file, 'r+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( $fh ) {
				ftruncate( $fh, $this->state['out'] );
				fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}

		$roots      = self::roots();
		$patterns   = NBB_Settings::excludes();
		$storage    = realpath( NBB_Storage::dir() );
		$stack      = $this->state['stack'];
		$last_flush = microtime( true );

		while ( ! empty( $stack ) ) {
			if ( microtime( true ) >= $deadline || ! NBB_Util::memory_ok() ) {
				$this->flush( $stack );
				return false;
			}
			list( $root_id, $rel_dir ) = array_pop( $stack );
			if ( ! isset( $roots[ $root_id ] ) ) {
				continue;
			}
			$root = $roots[ $root_id ];
			$abs  = '' === $rel_dir ? $root['path'] : $root['path'] . '/' . $rel_dir;

			if ( null !== $root['only'] ) {
				foreach ( $root['only'] as $name ) {
					$path = $abs . '/' . $name;
					if ( is_file( $path ) && ! is_link( $path ) ) {
						$this->emit(
							array(
								't' => 'f',
								'r' => $root_id,
								'p' => $name,
								's' => (int) filesize( $path ),
								'm' => (int) filemtime( $path ),
							)
						);
						$this->state['files']++;
						$this->state['bytes'] += (int) filesize( $path );
					}
				}
				continue;
			}

			$items = @scandir( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false === $items ) {
				$this->add_error( 'No legible: ' . $root_id . ':' . $rel_dir );
				continue;
			}
			$subdirs = array();
			foreach ( $items as $name ) {
				if ( '.' === $name || '..' === $name ) {
					continue;
				}
				$rel  = '' === $rel_dir ? $name : $rel_dir . '/' . $name;
				$path = $abs . '/' . $name;
				if ( self::is_excluded( $rel, $patterns ) ) {
					continue;
				}
				if ( is_link( $path ) ) {
					$this->emit(
						array(
							't' => 'l',
							'r' => $root_id,
							'p' => $rel,
							'l' => (string) @readlink( $path ), // phpcs:ignore WordPress.PHP.NoSilencedErrors
						)
					);
					$this->state['links']++;
					continue;
				}
				if ( is_dir( $path ) ) {
					$real = realpath( $path );
					if ( $storage && $real && NBB_Util::is_inside( $real, $storage ) ) {
						continue; // Never back up our own working folder.
					}
					$this->emit(
						array(
							't' => 'd',
							'r' => $root_id,
							'p' => $rel,
						)
					);
					$this->state['dirs']++;
					$subdirs[] = $rel;
					continue;
				}
				if ( is_file( $path ) ) {
					$size = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
					$time = @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
					if ( false === $size || ! is_readable( $path ) ) {
						$this->add_error( 'No legible: ' . $root_id . ':' . $rel );
						continue;
					}
					$this->emit(
						array(
							't' => 'f',
							'r' => $root_id,
							'p' => $rel,
							's' => (int) $size,
							'm' => (int) $time,
						)
					);
					$this->state['files']++;
					$this->state['bytes'] += (int) $size;
				}
			}
			// Push in reverse so the walk is alphabetical.
			for ( $i = count( $subdirs ) - 1; $i >= 0; $i-- ) {
				$stack[] = array( $root_id, $subdirs[ $i ] );
			}

			if ( $this->buffered_lines >= self::FLUSH_LINES || NBB_Util::elapsed( $last_flush ) >= self::FLUSH_SECONDS ) {
				$this->flush( $stack );
				$last_flush = microtime( true );
			}
		}

		$this->state['done'] = true;
		$this->flush( $stack );
		return true;
	}
}
