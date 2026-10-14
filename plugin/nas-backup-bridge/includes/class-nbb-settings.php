<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Options, defaults and activation logic.
 */
class NBB_Settings {

	const OPT_SECRET   = 'nbb_secret';
	const OPT_EXCLUDES = 'nbb_excludes';
	const OPT_ALL_TBL  = 'nbb_all_tables';
	const OPT_LAST     = 'nbb_last_job';

	public static function default_excludes() {
		return array(
			'wp-content/cache',
			'wp-content/et-cache',
			'wp-content/upgrade',
			'wp-content/upgrade-temp-backup',
			'wp-content/updraft',
			'wp-content/ai1wm-backups',
			'wp-content/backups-dup-lite',
			'wp-content/backups-dup-pro',
			'wp-content/backup-db',
			'wp-content/backups',
			'wp-content/wpvividbackups',
			'wp-content/uploads/backwpup-*',
			'wp-content/uploads/wp-file-manager-pro/fm_backup',
			'*/error_log',
			'error_log',
			'*.log',
			'.git',
			'*/.git',
			'*/node_modules',
		);
	}

	public static function activate() {
		if ( ! get_option( self::OPT_SECRET ) ) {
			add_option( self::OPT_SECRET, NBB_Util::random_token( 32 ), '', 'no' );
		}
		if ( false === get_option( self::OPT_EXCLUDES, false ) ) {
			add_option( self::OPT_EXCLUDES, self::default_excludes(), '', 'no' );
		}
		NBB_Storage::ensure();
		if ( ! wp_next_scheduled( 'nbb_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'nbb_cleanup' );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'nbb_cleanup' );
	}

	/** Shared secret. A constant in wp-config.php takes precedence. */
	public static function secret() {
		if ( defined( 'NBB_SECRET' ) && is_string( NBB_SECRET ) && strlen( NBB_SECRET ) >= 32 ) {
			return NBB_SECRET;
		}
		$secret = get_option( self::OPT_SECRET );
		if ( ! $secret ) {
			$secret = NBB_Util::random_token( 32 );
			update_option( self::OPT_SECRET, $secret, false );
		}
		return $secret;
	}

	public static function secret_from_constant() {
		return defined( 'NBB_SECRET' ) && is_string( NBB_SECRET ) && strlen( NBB_SECRET ) >= 32;
	}

	public static function regenerate_secret() {
		update_option( self::OPT_SECRET, NBB_Util::random_token( 32 ), false );
	}

	public static function excludes() {
		$list = get_option( self::OPT_EXCLUDES, null );
		if ( ! is_array( $list ) ) {
			$list = self::default_excludes();
		}
		$list = array_values( array_filter( array_map( 'trim', $list ), 'strlen' ) );
		return apply_filters( 'nbb_exclude_patterns', $list );
	}

	public static function all_tables() {
		return (bool) get_option( self::OPT_ALL_TBL, false );
	}
}
