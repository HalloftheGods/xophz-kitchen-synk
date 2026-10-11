<?php

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Single resolver for every credential Kitchen Synk uses (Gemini, Stripe).
 *
 * Secrets live in the WordPress core Connectors API and are read server-side only. Core exposes
 * no public function that returns a key's value, so this follows the precedence of core's
 * _wp_connectors_get_api_key_source(): environment variable, then PHP constant, then the database
 * option. Every name comes from the connector's own `authentication` definition, so nothing here
 * hard-codes an option, constant or env name. No value is ever logged, echoed or put in a URL.
 */
class Kitchen_Synk_Connectors {

	/**
	 * Connector ids, in lookup order. `google` is registered by the AI provider for Google;
	 * `google_gemini_api_key` is the COMPASS alias that shares the same setting.
	 */
	const GEMINI_CONNECTORS = array( 'google', 'google_gemini_api_key' );

	const STRIPE_SECRET_CONNECTOR  = 'stripe_secret_key';
	const STRIPE_WEBHOOK_CONNECTOR = 'kitchen_synk_stripe_webhook_secret';

	public static function init() {
		add_action( 'wp_connectors_init', array( __CLASS__, 'register_connectors' ) );
		add_action( 'init', array( __CLASS__, 'migrate_legacy_stripe_options' ), 20 );
	}

	/**
	 * One-time move of the Stripe secrets the old settings route kept in the `xophz_stripe_keys`
	 * option into their connectors' options, then removal from that option. A value already set on
	 * the connector is never overwritten. If a connector is not registered yet the legacy value is
	 * kept and retried on the next load.
	 */
	public static function migrate_legacy_stripe_options() {
		$legacy = get_option( 'xophz_stripe_keys', null );
		if ( ! is_array( $legacy ) ) {
			return;
		}

		$moves = array(
			'secret_key'     => self::STRIPE_SECRET_CONNECTOR,
			'webhook_secret' => self::STRIPE_WEBHOOK_CONNECTOR,
		);

		$changed = false;
		foreach ( $moves as $field => $connector_id ) {
			if ( ! array_key_exists( $field, $legacy ) ) {
				continue;
			}

			$value = self::as_string( $legacy[ $field ] );
			if ( '' !== $value ) {
				$setting_name = self::setting_name( $connector_id );
				if ( '' === $setting_name ) {
					continue;
				}
				if ( '' === self::from_option( $setting_name ) ) {
					update_option( $setting_name, $value );
				}
			}

			unset( $legacy[ $field ] );
			$changed = true;
		}

		if ( $changed ) {
			update_option( 'xophz_stripe_keys', $legacy );
		}
	}

	/**
	 * Registers the one credential no other plugin declares: the Stripe webhook signing secret.
	 *
	 * @param WP_Connector_Registry $registry Connector registry.
	 */
	public static function register_connectors( $registry ) {
		if ( ! is_object( $registry ) || ! method_exists( $registry, 'register' ) ) {
			return;
		}
		if ( function_exists( 'wp_is_connector_registered' ) && wp_is_connector_registered( self::STRIPE_WEBHOOK_CONNECTOR ) ) {
			return;
		}
		$registry->register(
			self::STRIPE_WEBHOOK_CONNECTOR,
			array(
				'name'           => 'Kitchen Synk Stripe Webhook Secret',
				'description'    => 'Signing secret (whsec_...) used to verify Stripe webhook calls to Kitchen Synk.',
				'type'           => 'api_key',
				'authentication' => array(
					'method'          => 'api_key',
					'setting_name'    => 'kitchen_synk_stripe_webhook_secret',
					'constant_name'   => 'KITCHEN_SYNK_STRIPE_WEBHOOK_SECRET',
					'env_var_name'    => 'KITCHEN_SYNK_STRIPE_WEBHOOK_SECRET',
					'credentials_url' => 'https://dashboard.stripe.com/webhooks',
				),
			)
		);
	}

	/** Gemini API key from the Google connector, or '' when none is configured. */
	public static function gemini_key(): string {
		foreach ( self::GEMINI_CONNECTORS as $connector_id ) {
			$key = self::get_key( $connector_id );
			if ( '' !== $key ) {
				return $key;
			}
		}
		return '';
	}

	/** Stripe secret key from the Stripe connector, or '' when none is configured. */
	public static function stripe_secret_key(): string {
		return self::get_key( self::STRIPE_SECRET_CONNECTOR );
	}

	/** Stripe webhook signing secret, or '' when none is configured. */
	public static function stripe_webhook_secret(): string {
		return self::get_key( self::STRIPE_WEBHOOK_CONNECTOR );
	}

	/**
	 * Request headers for a Gemini call. The key travels in a header, never in the URL, so it
	 * stays out of provider logs, proxy logs, Query Monitor and http_api_debug loggers.
	 *
	 * @param string $api_key  Key from gemini_key().
	 * @param array  $extra    Extra headers to merge in.
	 * @return array
	 */
	public static function gemini_headers( string $api_key, array $extra = array() ): array {
		return array_merge(
			array(
				'Content-Type'   => 'application/json',
				'x-goog-api-key' => $api_key,
			),
			$extra
		);
	}

	/**
	 * Reads the value of a registered connector. Guarded: an unregistered id resolves to ''.
	 */
	public static function get_key( string $connector_id ): string {
		$auth = self::authentication( $connector_id );

		$from_env = self::from_env( (string) ( $auth['env_var_name'] ?? '' ) );
		if ( '' !== $from_env ) {
			return $from_env;
		}

		$from_constant = self::from_constant( (string) ( $auth['constant_name'] ?? '' ) );
		if ( '' !== $from_constant ) {
			return $from_constant;
		}

		return self::from_option( (string) ( $auth['setting_name'] ?? '' ) );
	}

	/** The connector's declared `authentication` definition, or an empty array when unregistered. */
	private static function authentication( string $connector_id ): array {
		if ( ! function_exists( 'wp_get_connector' ) || ! function_exists( 'wp_is_connector_registered' ) ) {
			return array();
		}
		if ( ! wp_is_connector_registered( $connector_id ) ) {
			return array();
		}

		$connector = wp_get_connector( $connector_id );
		if ( ! is_array( $connector ) || ! isset( $connector['authentication'] ) || ! is_array( $connector['authentication'] ) ) {
			return array();
		}

		return $connector['authentication'];
	}

	private static function setting_name( string $connector_id ): string {
		$auth = self::authentication( $connector_id );
		return self::as_string( $auth['setting_name'] ?? '' );
	}

	private static function from_env( string $env_var_name ): string {
		if ( '' === $env_var_name ) {
			return '';
		}
		return self::as_string( getenv( $env_var_name ) );
	}

	private static function from_constant( string $constant_name ): string {
		if ( '' === $constant_name || ! defined( $constant_name ) ) {
			return '';
		}
		return self::as_string( constant( $constant_name ) );
	}

	private static function from_option( string $setting_name ): string {
		if ( '' === $setting_name ) {
			return '';
		}
		return self::as_string( get_option( $setting_name, '' ) );
	}

	private static function as_string( $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}
