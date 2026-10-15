<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Updates from the public GitHub repository, through WordPress' normal update screen.
 *
 * The repository publishes releases/latest.json:
 *   {"version":"1.2.0","package":"https://…/nas-backup-bridge-1.2.0.zip","sha256":"…",
 *    "requires":"5.6","requires_php":"7.4","tested":"7.1","changelog":"…"}
 *
 * The zip is verified against sha256 before WordPress installs it.
 * Disable with define( 'NBB_DISABLE_UPDATES', true ); point elsewhere with NBB_UPDATE_URL.
 */
class NBB_Updater {

	const DEFAULT_URL = 'https://raw.githubusercontent.com/Chrisimir/nas-website-backup-bridge/main/releases/latest.json';
	const CACHE_KEY   = 'nbb_update_info';
	const SLUG        = 'nas-backup-bridge';

	public static function init() {
		if ( defined( 'NBB_DISABLE_UPDATES' ) && NBB_DISABLE_UPDATES ) {
			return;
		}
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'verified_download' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush' ), 10, 0 );
	}

	private static function url() {
		return defined( 'NBB_UPDATE_URL' ) ? NBB_UPDATE_URL : self::DEFAULT_URL;
	}

	public static function flush() {
		delete_site_transient( self::CACHE_KEY );
	}

	/** @return array|null Remote release info, cached for 6 h (1 h after a failure). */
	public static function remote( $force = false ) {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( ! $force && is_array( $cached ) ) {
			return empty( $cached['version'] ) ? null : $cached;
		}
		$res  = wp_remote_get(
			self::url(),
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);
		$info = null;
		if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
			$data = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( is_array( $data ) && ! empty( $data['version'] ) && ! empty( $data['package'] )
				&& preg_match( '/^\d+\.\d+\.\d+$/', $data['version'] )
				&& 0 === strpos( $data['package'], 'https://' ) ) {
				$info = $data;
			}
		}
		set_site_transient( self::CACHE_KEY, $info ? $info : array( 'version' => '' ), $info ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
		return $info;
	}

	private static function plugin_file() {
		return plugin_basename( NBB_FILE );
	}

	public static function inject( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$info = self::remote();
		if ( ! $info ) {
			return $transient;
		}
		$item = (object) array(
			'id'           => 'github.com/Chrisimir/nas-website-backup-bridge',
			'slug'         => self::SLUG,
			'plugin'       => self::plugin_file(),
			'new_version'  => $info['version'],
			'url'          => 'https://github.com/Chrisimir/nas-website-backup-bridge',
			'package'      => $info['package'],
			'requires'     => isset( $info['requires'] ) ? $info['requires'] : '',
			'requires_php' => isset( $info['requires_php'] ) ? $info['requires_php'] : '',
			'tested'       => isset( $info['tested'] ) ? $info['tested'] : '',
		);
		if ( version_compare( $info['version'], NBB_VERSION, '>' ) ) {
			$transient->response[ self::plugin_file() ] = $item;
		} else {
			// Lets WordPress show the "enable auto-updates" toggle for this plugin.
			$transient->no_update[ self::plugin_file() ] = $item;
		}
		return $transient;
	}

	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$info = self::remote();
		if ( ! $info ) {
			return $result;
		}
		return (object) array(
			'name'          => 'NAS Backup Bridge',
			'slug'          => self::SLUG,
			'version'       => $info['version'],
			'author'        => 'Chris Lund',
			'homepage'      => 'https://github.com/Chrisimir/nas-website-backup-bridge',
			'download_link' => $info['package'],
			'requires'      => isset( $info['requires'] ) ? $info['requires'] : '',
			'requires_php'  => isset( $info['requires_php'] ) ? $info['requires_php'] : '',
			'tested'        => isset( $info['tested'] ) ? $info['tested'] : '',
			'sections'      => array(
				'description' => 'Copias completas del sitio descargadas por un servidor de copias propio (NAS) mediante una API firmada.',
				'changelog'   => isset( $info['changelog'] ) ? wpautop( esc_html( $info['changelog'] ) ) : '',
			),
		);
	}

	/** Download the package ourselves and refuse it if the checksum does not match. */
	public static function verified_download( $reply, $package, $upgrader, $hook_extra = array() ) {
		if ( false !== $reply ) {
			return $reply;
		}
		$info = self::remote();
		if ( ! $info || $package !== $info['package'] ) {
			return $reply; // Not ours.
		}
		if ( empty( $info['sha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/', $info['sha256'] ) ) {
			return new WP_Error( 'nbb_update_hash', 'La actualización no trae suma de verificación; no se instala.' );
		}
		$file = download_url( $package, 120 );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		if ( ! hash_equals( $info['sha256'], hash_file( 'sha256', $file ) ) ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new WP_Error( 'nbb_update_hash', 'El paquete descargado no coincide con la suma de verificación publicada; no se instala.' );
		}
		return $file;
	}
}
