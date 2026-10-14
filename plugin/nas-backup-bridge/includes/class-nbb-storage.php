<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Private working directory for job state, DB dumps and manifests.
 *
 * Preference: a folder ABOVE the web root (not reachable over HTTP). Fallback:
 * a randomly named folder inside uploads, protected with .htaccess / web.config
 * and an index.php. Job files are deleted as soon as the NAS finishes, and any
 * leftovers are purged daily.
 */
class NBB_Storage {

	const OPT_DIR = 'nbb_storage_dir';

	public static function dir() {
		$dir = get_option( self::OPT_DIR );
		if ( $dir && is_dir( $dir ) && wp_is_writable( $dir ) ) {
			return $dir;
		}
		return self::ensure();
	}

	/** Create (or re-create) the storage directory and return its path. */
	public static function ensure() {
		$existing = get_option( self::OPT_DIR );
		if ( $existing && is_dir( $existing ) && wp_is_writable( $existing ) ) {
			self::protect( $existing );
			return $existing;
		}

		$suffix     = strtolower( substr( NBB_Util::random_token( 12 ), 0, 16 ) );
		$suffix     = preg_replace( '/[^a-z0-9]/', 'x', $suffix );
		$candidates = array();

		$parent = NBB_Util::norm_dir( dirname( NBB_Util::norm_dir( ABSPATH ) ) );
		if ( self::usable_parent( $parent ) ) {
			$candidates[] = $parent . '/.nbb-private-' . $suffix;
		}
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['basedir'] ) ) {
			$candidates[] = NBB_Util::norm_dir( $uploads['basedir'] ) . '/nbb-' . $suffix;
		}
		$candidates[] = NBB_Util::norm_dir( WP_CONTENT_DIR ) . '/nbb-' . $suffix;

		foreach ( $candidates as $candidate ) {
			if ( wp_mkdir_p( $candidate ) && wp_is_writable( $candidate ) ) {
				@chmod( $candidate, 0700 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				self::protect( $candidate );
				update_option( self::OPT_DIR, $candidate, false );
				return $candidate;
			}
		}
		return '';
	}

	private static function usable_parent( $parent ) {
		if ( '' === $parent || '/' === $parent ) {
			return false;
		}
		$basedir = (string) ini_get( 'open_basedir' );
		if ( '' !== $basedir ) {
			$allowed = false;
			foreach ( explode( PATH_SEPARATOR, $basedir ) as $base ) {
				$base = NBB_Util::norm_dir( $base );
				if ( '' !== $base && NBB_Util::is_inside( $parent, $base ) ) {
					$allowed = true;
					break;
				}
			}
			if ( ! $allowed ) {
				return false;
			}
		}
		return @is_dir( $parent ) && @wp_is_writable( $parent ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	/** Deny web access in case the folder is under the document root. */
	public static function protect( $dir ) {
		$files = array(
			'.htaccess'  => "# NAS Backup Bridge\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
			'index.html' => '',
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
		);
		foreach ( $files as $name => $content ) {
			$path = $dir . '/' . $name;
			if ( ! file_exists( $path ) ) {
				file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
	}

	/** Is the storage directory below the web root (i.e. relying on .htaccess)? */
	public static function is_public() {
		$dir  = realpath( self::dir() );
		$root = realpath( ABSPATH );
		return $dir && $root && NBB_Util::is_inside( $dir, $root );
	}
}
