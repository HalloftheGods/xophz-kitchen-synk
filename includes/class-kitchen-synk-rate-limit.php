<?php

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Fixed-window counters for the Kitchen Synk routes that anyone can call.
 *
 * A route that sends email, creates accounts, checks passwords or reaches a paid API is limited per
 * client IP and, where the request names one, per target (an email address or a login). The IP is
 * REMOTE_ADDR only: X-Forwarded-For and similar headers are chosen by the caller, so they are never
 * trusted here. If the site sits behind a proxy, set REMOTE_ADDR from the proxy at the web server
 * (for example with mod_remoteip or the real_ip module) so each visitor gets their own counter.
 *
 * Counters live in transients, so an object cache makes them cheap. A read-then-write counter can
 * undercount when many requests land in the same instant, so it is a speed bump and not an exact
 * meter. Nothing here stores a plain email address or IP: subjects are hashed.
 */
class Kitchen_Synk_Rate_Limit {

	const PREFIX = 'ks_rl_';

	/**
	 * The caller's address as the web server reports it, or 'unknown'. A site behind a proxy it
	 * trusts can map the real visitor address with the xophz_kitchen_synk_client_ip filter, which
	 * must return a valid IP.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip = apply_filters( 'xophz_kitchen_synk_client_ip', $ip );

		return is_string( $ip ) && false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : 'unknown';
	}

	/**
	 * Counts one hit for a bucket and subject, or refuses it when the window is already full.
	 *
	 * @param string $bucket  Name of the limit, for example 'otp_ip'.
	 * @param string $subject Who is counted: an IP, an email address or a login.
	 * @param int    $max     Hits allowed per window.
	 * @param int    $window  Window length in seconds.
	 * @param string $message Text for the 429 error.
	 * @return true|WP_Error WP_Error with status 429 once the window is full.
	 */
	public static function hit( $bucket, $subject, $max, $window, $message = '' ) {
		$wait = self::seconds_until_free( $bucket, $subject, $max );
		if ( $wait > 0 ) {
			return self::too_many( $wait, $message );
		}

		self::record( $bucket, $subject, $window );

		return true;
	}

	/**
	 * Seconds until the subject may try again, or 0 when the window still has room. Read-only.
	 *
	 * @param string $bucket  Name of the limit.
	 * @param string $subject Who is counted.
	 * @param int    $max     Hits allowed per window.
	 * @return int
	 */
	public static function seconds_until_free( $bucket, $subject, $max ) {
		$state = self::open_state( self::key( $bucket, $subject ) );
		if ( null === $state || $state['count'] < $max ) {
			return 0;
		}

		return max( 1, $state['reset'] - time() );
	}

	/**
	 * Adds one hit. The window opens on the first hit and is not extended by later ones.
	 *
	 * @param string $bucket  Name of the limit.
	 * @param string $subject Who is counted.
	 * @param int    $window  Window length in seconds.
	 * @return void
	 */
	public static function record( $bucket, $subject, $window ) {
		$key   = self::key( $bucket, $subject );
		$now   = time();
		$state = self::open_state( $key );
		if ( null === $state ) {
			$state = array(
				'count' => 0,
				'reset' => $now + (int) $window,
			);
		}

		$state['count']++;
		set_transient( $key, $state, max( 1, $state['reset'] - $now ) );
	}

	/**
	 * Forgets a subject's hits, for example after a successful login.
	 *
	 * @param string $bucket  Name of the limit.
	 * @param string $subject Who is counted.
	 * @return void
	 */
	public static function clear( $bucket, $subject ) {
		delete_transient( self::key( $bucket, $subject ) );
	}

	/**
	 * The 429 error for a full window.
	 *
	 * @param int    $wait    Seconds until the subject may try again.
	 * @param string $message Text for the error.
	 * @return WP_Error
	 */
	public static function too_many( $wait, $message = '' ) {
		if ( '' === $message ) {
			$message = 'Too many requests. Please try again later.';
		}

		return new WP_Error(
			'rate_limited',
			$message,
			array(
				'status'      => 429,
				'retry_after' => (int) $wait,
				'retryAfter'  => (int) $wait,
			)
		);
	}

	private static function key( $bucket, $subject ) {
		return self::PREFIX . substr( md5( $bucket . '|' . strtolower( trim( (string) $subject ) ) ), 0, 32 );
	}

	/**
	 * The stored counter while its window is still open, otherwise null.
	 *
	 * @param string $key Transient name.
	 * @return array|null
	 */
	private static function open_state( $key ) {
		$state = get_transient( $key );
		if ( is_array( $state ) && isset( $state['count'], $state['reset'] ) && $state['reset'] > time() ) {
			return $state;
		}

		return null;
	}
}
