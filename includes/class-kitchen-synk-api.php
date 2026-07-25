<?php
/**
 * REST API & Endpoint Handler for Xophz Kitchen Synk
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once XOPHZ_KITCHEN_SYNK_PATH . 'includes/traits/trait-kitchen-synk-api-stripe.php';
require_once XOPHZ_KITCHEN_SYNK_PATH . 'includes/traits/trait-kitchen-synk-api-ai.php';
require_once XOPHZ_KITCHEN_SYNK_PATH . 'includes/traits/trait-kitchen-synk-api-barcode.php';
require_once XOPHZ_KITCHEN_SYNK_PATH . 'includes/traits/trait-kitchen-synk-api-auth.php';
require_once XOPHZ_KITCHEN_SYNK_PATH . 'includes/traits/trait-kitchen-synk-api-settings.php';

class Kitchen_Synk_API {
    use Kitchen_Synk_API_Stripe_Trait;
    use Kitchen_Synk_API_AI_Trait;
    use Kitchen_Synk_API_Barcode_Trait;
    use Kitchen_Synk_API_Auth_Trait;
    use Kitchen_Synk_API_Settings_Trait;

    public function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function register_routes() {
        register_rest_route( 'kitchen-synk/v1', '/barcode-lookup', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'rest_barcode_lookup' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'kitchen-synk/v1', '/gemini/scan-barcode', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'rest_scan_barcode' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'kitchen-synk/v1', '/gemini/scan-pantry', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'rest_scan_pantry' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'kitchen-synk/v1', '/gemini/generate-recipes', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'rest_generate_recipes' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'kitchen-synk/v1', '/quota', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'get_ai_quota' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'kitchen-synk/v1', '/connectors/stripe', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'rest_get_stripe_keys' ),
                'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            ),
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'rest_save_stripe_keys' ),
                'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            ),
        ) );

        register_rest_route( 'kitchen-synk/v1', '/connectors/google-tag', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'rest_get_google_tag' ),
                'permission_callback' => '__return_true',
            ),
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'rest_save_google_tag' ),
                'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            ),
        ) );

        register_rest_route( 'kitchen-synk/v1', '/send-otp', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'rest_send_otp' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'kitchen-synk/v1', '/register-and-checkout', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'rest_register_and_checkout' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'kitchen-synk/v1', '/checkout/session', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'rest_create_checkout_session' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'kitchen-synk/v1', '/checkout/verify', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'rest_verify_checkout_session' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'kitchen-synk/v1', '/stripe/webhook', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'rest_stripe_webhook' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'kitchen-synk/v1', '/update-username', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'rest_update_username' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'kitchen-synk/v1', '/me', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'rest_get_me' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'kitchen-synk/v1', '/profile', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'rest_update_profile' ),
            'permission_callback' => 'is_user_logged_in',
        ) );

        register_rest_route( 'kitchen-synk/v1', '/license', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'rest_get_license' ),
            'permission_callback' => 'is_user_logged_in',
        ) );

        register_rest_route( 'kitchen-synk/v1', '/referral', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'rest_get_referral_info' ),
            'permission_callback' => 'is_user_logged_in',
        ) );
    }
}
