<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HMAC request signing.
 *
 * Signed string (lines joined with "\n"):
 *   NBB1
 *   <unix timestamp>
 *   <HTTP method, upper case>
 *   <REST route, e.g. /nbb/v1/jobs/abc/step>
 *   <canonical query: params sorted by key, rawurlencoded, "k=v" joined by "&"; rest_route excluded>
 *   <hex sha256 of the raw request body ("" for no body)>
 *
 * Headers: X-NBB-Timestamp, X-NBB-Signature (hex HMAC-SHA256 with the shared secret).
 */
class NBB_Auth {

	const MAX_SKEW      = 300;
	const FAIL_LIMIT    = 20;
	const FAIL_WINDOW   = 600;

	public static function canonical_query( array $params ) {
		unset( $params['rest_route'] );
		ksort( $params, SORT_STRING );
		$parts = array();
		foreach ( $params as $k => $v ) {
			if ( is_array( $v ) ) {
				return null; // Arrays are never used by this API.
			}
			$parts[] = rawurlencode( (string) $k ) . '=' . rawurlencode( (string) $v );
		}
		return implode( '&', $parts );
	}

	public static function string_to_sign( $ts, $method, $route, $query, $body ) {
		return implode(
			"\n",
			array( 'NBB1', (string) $ts, strtoupper( $method ), $route, $query, hash( 'sha256', (string) $body ) )
		);
	}

	private static function client_ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
	}

	private static function fail_key() {
		return 'nbb_fail_' . md5( self::client_ip() );
	}

	private static function record_failure() {
		$key   = self::fail_key();
		$count = (int) get_transient( $key );
		set_transient( $key, $count + 1, self::FAIL_WINDOW );
	}

	/**
	 * permission_callback for every route.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function check( $request ) {
		// A valid signature always passes; repeated failures from one IP are throttled.
		if ( (int) get_transient( self::fail_key() ) >= self::FAIL_LIMIT && ! self::signature_valid( $request ) ) {
			return new WP_Error( 'nbb_rate_limited', 'Demasiados intentos fallidos.', array( 'status' => 429 ) );
		}

		$ts  = $request->get_header( 'x_nbb_timestamp' );
		$sig = $request->get_header( 'x_nbb_signature' );
		if ( ! $ts || ! $sig || ! ctype_digit( (string) $ts ) ) {
			self::record_failure();
			return new WP_Error( 'nbb_unauthorized', 'Falta la firma.', array( 'status' => 401 ) );
		}
		if ( abs( time() - (int) $ts ) > self::MAX_SKEW ) {
			self::record_failure();
			return new WP_Error( 'nbb_clock_skew', 'Marca de tiempo fuera de rango (revisa la hora del NAS).', array( 'status' => 401 ) );
		}

		if ( ! self::signature_valid( $request ) ) {
			self::record_failure();
			return new WP_Error( 'nbb_unauthorized', 'Firma no válida.', array( 'status' => 401 ) );
		}
		return true;
	}

	private static function signature_valid( $request ) {
		$ts    = (string) $request->get_header( 'x_nbb_timestamp' );
		$sig   = (string) $request->get_header( 'x_nbb_signature' );
		$query = self::canonical_query( $request->get_query_params() );
		if ( '' === $ts || '' === $sig || null === $query || ! ctype_digit( $ts ) || abs( time() - (int) $ts ) > self::MAX_SKEW ) {
			return false;
		}
		$expected = hash_hmac(
			'sha256',
			self::string_to_sign( $ts, $request->get_method(), $request->get_route(), $query, $request->get_body() ),
			NBB_Settings::secret()
		);
		return hash_equals( $expected, strtolower( $sig ) );
	}
}
