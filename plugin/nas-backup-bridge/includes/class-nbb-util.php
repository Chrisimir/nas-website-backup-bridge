<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small helpers (PHP 7.4 compatible).
 */
class NBB_Util {

	public static function starts_with( $haystack, $needle ) {
		return '' === $needle || 0 === strncmp( $haystack, $needle, strlen( $needle ) );
	}

	/** Normalise a directory path: forward slashes, no trailing slash. */
	public static function norm_dir( $path ) {
		$path = str_replace( '\\', '/', (string) $path );
		return rtrim( $path, '/' );
	}

	/** Is $path equal to or inside $dir? Both should be realpaths. */
	public static function is_inside( $path, $dir ) {
		$path = self::norm_dir( $path );
		$dir  = self::norm_dir( $dir );
		return $path === $dir || self::starts_with( $path, $dir . '/' );
	}

	public static function random_token( $bytes = 32 ) {
		return rtrim( strtr( base64_encode( random_bytes( $bytes ) ), '+/', '-_' ), '=' );
	}

	/** Seconds elapsed since $start (microtime(true)). */
	public static function elapsed( $start ) {
		return microtime( true ) - $start;
	}

	/** Try to give the request as much time as the host allows. */
	public static function extend_limits() {
		if ( function_exists( 'set_time_limit' ) && false === strpos( (string) ini_get( 'disable_functions' ), 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		@ignore_user_abort( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
	}

	/** Effective time budget (seconds) for a single step request. */
	public static function step_budget( $requested ) {
		$budget = (int) $requested;
		if ( $budget <= 0 ) {
			$budget = 20;
		}
		$budget = min( $budget, 120 );
		$max    = (int) ini_get( 'max_execution_time' );
		if ( $max > 0 ) {
			// Leave a safety margin for bootstrap and response.
			$budget = min( $budget, max( 5, (int) floor( $max * 0.6 ) ) );
		}
		return $budget;
	}

	public static function memory_ok() {
		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		if ( $limit <= 0 ) {
			return true;
		}
		return memory_get_usage( true ) < $limit * 0.7;
	}

	public static function write_json_atomic( $file, $data ) {
		$tmp = $file . '.tmp';
		$ok  = file_put_contents( $tmp, wp_json_encode( $data, JSON_UNESCAPED_SLASHES ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $ok ) {
			return false;
		}
		return rename( $tmp, $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	public static function read_json( $file ) {
		if ( ! is_file( $file ) ) {
			return null;
		}
		$raw = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $raw ) {
			return null;
		}
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : null;
	}

	/** Recursively delete a directory (no symlink following). */
	public static function rrmdir( $dir ) {
		if ( is_link( $dir ) || is_file( $dir ) ) {
			return @unlink( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		if ( ! is_dir( $dir ) ) {
			return true;
		}
		$items = scandir( $dir );
		if ( false === $items ) {
			return false;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			self::rrmdir( $dir . '/' . $item );
		}
		return @rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
}
