<?php
/**
 * Plugin Name:       Xophz Kitchen Synk
 * Plugin URI:        https://kitchensynk.app
 * Description:       Standalone WordPress backend and router for the Kitchen Synk web app.
 * Version:           26.10.11-294
 * Author:            Hall of the Gods, Inc.
 * Author URI:        https://www.hallofthegods.com/
 * Category:          Command Deck
 * Group:             Ecosystem
 * Text Domain:       xophz-kitchen-synk
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'XOPHZ_KITCHEN_SYNK_VERSION', '26.10.11-294' );
define( 'XOPHZ_KITCHEN_SYNK_PATH', plugin_dir_path( __FILE__ ) );
define( 'XOPHZ_KITCHEN_SYNK_URL', plugin_dir_url( __FILE__ ) );

if ( ! function_exists( 'xophz_kitchen_synk_get_base_url' ) ) {
    function xophz_kitchen_synk_get_base_url( $path = '', $custom_slug = '' ) {
        $load_mode = get_option( 'xophz_kitchen_synk_load_mode', 'custom_slug' );
        $slug      = ! empty( $custom_slug ) ? $custom_slug : get_option( 'xophz_kitchen_synk_custom_slug', 'kitchen-synk' );
        $slug      = ! empty( $slug ) ? $slug : 'kitchen-synk';

        if ( 'homepage' === $load_mode && empty( $custom_slug ) ) {
            $base_url = home_url( '/' );
        } elseif ( 'specific_page' === $load_mode && empty( $custom_slug ) ) {
            $page_id = (int) get_option( 'xophz_kitchen_synk_load_page_id', 0 );
            if ( $page_id > 0 ) {
                $base_url = trailingslashit( get_permalink( $page_id ) );
            } else {
                $base_url = home_url( '/' . $slug . '/' );
            }
        } else {
            $base_url = home_url( '/' . $slug . '/' );
        }

        return $base_url . ltrim( $path, '/' );
    }
}

require_once XOPHZ_KITCHEN_SYNK_PATH . 'includes/class-kitchen-synk-connectors.php';
Kitchen_Synk_Connectors::init();
require_once XOPHZ_KITCHEN_SYNK_PATH . 'includes/class-kitchen-synk-ai-gate.php';
require_once XOPHZ_KITCHEN_SYNK_PATH . 'includes/class-kitchen-synk-rate-limit.php';
require_once XOPHZ_KITCHEN_SYNK_PATH . 'class-kitchen-synk-api.php';
require_once XOPHZ_KITCHEN_SYNK_PATH . 'includes/class-kitchen-synk-api.php';
require_once XOPHZ_KITCHEN_SYNK_PATH . 'includes/traits/trait-xophz-kitchen-synk-admin.php';
require_once XOPHZ_KITCHEN_SYNK_PATH . 'includes/traits/trait-xophz-kitchen-synk-frontend.php';

class Xophz_Kitchen_Synk {
    use Xophz_Kitchen_Synk_Admin;
    use Xophz_Kitchen_Synk_Frontend;

    private $api;

    public function __construct() {
        $this->api = new Kitchen_Synk_API();

        add_action( 'admin_menu', array( $this, 'add_plugin_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_button' ), 90 );

        // Flush rewrites when settings are saved
        add_action( 'update_option_xophz_kitchen_synk_load_mode', array( $this, 'flush_rewrites_on_save' ), 10, 2 );
        add_action( 'update_option_xophz_kitchen_synk_custom_slug', array( $this, 'flush_rewrites_on_save' ), 10, 2 );
        add_action( 'update_option_xophz_kitchen_synk_load_page_id', array( $this, 'flush_rewrites_on_save' ), 10, 2 );

        // Disable WordPress canonical redirect trailing slash adding for app assets
        add_filter( 'redirect_canonical', array( $this, 'disable_canonical_for_app_assets' ), 10, 2 );

        // Public rewrite and template
        add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
        add_action( 'init', array( $this, 'register_rewrites' ) );
        add_action( 'init', array( $this, 'handle_api_request' ), 5 );
        add_action( 'template_redirect', array( $this, 'handle_api_request' ), 5 );
        add_action( 'template_redirect', array( $this, 'template_redirect' ) );
        add_action( 'wp_head', array( $this, 'inject_recipe_json_ld' ) );
    }

    public function flush_rewrites_on_save( $old_value, $new_value ) {
        if ( $old_value !== $new_value ) {
            $this->register_rewrites();
            flush_rewrite_rules();
        }
    }

    public function register_query_vars( $vars ) {
        $vars[] = 'xophz_kitchen_synk';
        $vars[] = 'xophz_kitchen_synk_force_prod';
        $vars[] = 'xophz_kitchen_synk_api';
        return $vars;
    }

    public function register_rewrites() {
        $slug = get_option( 'xophz_kitchen_synk_custom_slug', 'kitchen-synk' );

        add_rewrite_rule(
            '^api/(.*)?$',
            'index.php?xophz_kitchen_synk_api=1',
            'top'
        );

        if ( ! empty( $slug ) ) {
            add_rewrite_rule(
                '^' . preg_quote( $slug, '/' ) . '/api/(.*)?$',
                'index.php?xophz_kitchen_synk_api=1',
                'top'
            );
            add_rewrite_rule(
                '^' . preg_quote( $slug, '/' ) . '/?$',
                'index.php?xophz_kitchen_synk=1',
                'top'
            );
            add_rewrite_rule(
                '^' . preg_quote( $slug, '/' ) . '/(.*)?$',
                'index.php?xophz_kitchen_synk=1',
                'top'
            );

            $prod_slug = $slug . '-prod';
            add_rewrite_rule(
                '^' . preg_quote( $prod_slug, '/' ) . '/?$',
                'index.php?xophz_kitchen_synk=1&xophz_kitchen_synk_force_prod=1',
                'top'
            );
            add_rewrite_rule(
                '^' . preg_quote( $prod_slug, '/' ) . '/(.*)?$',
                'index.php?xophz_kitchen_synk=1&xophz_kitchen_synk_force_prod=1',
                'top'
            );
        }

        add_rewrite_rule(
            '^kitchen-synk-prod/?$',
            'index.php?xophz_kitchen_synk=1&xophz_kitchen_synk_force_prod=1',
            'top'
        );
        add_rewrite_rule(
            '^kitchen-synk-prod/(.*)?$',
            'index.php?xophz_kitchen_synk=1&xophz_kitchen_synk_force_prod=1',
            'top'
        );
    }

    public function handle_api_request() {
        if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
            return;
        }
        $path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
        $slug = get_option( 'xophz_kitchen_synk_custom_slug', 'kitchen-synk' );

        $is_api_call = (
            strpos( $path, '/api/barcode-lookup' ) !== false ||
            strpos( $path, '/api/gemini/scan-barcode' ) !== false ||
            strpos( $path, '/api/gemini/scan-pantry' ) !== false ||
            strpos( $path, '/api/gemini/generate-recipes' ) !== false ||
            ( ! empty( $slug ) && strpos( $path, '/' . $slug . '/api/' ) !== false ) ||
            get_query_var( 'xophz_kitchen_synk_api' )
        );

        if ( ! $is_api_call ) {
            return;
        }

        // CORS: only an origin on the allowlist is answered, and it is echoed back exactly. A
        // request with no Origin header (same-origin or a server call) needs no CORS headers.
        // Credentials are never allowed cross-origin on these paths.
        $origin         = $this->normalize_origin( isset( $_SERVER['HTTP_ORIGIN'] ) ? wp_unslash( $_SERVER['HTTP_ORIGIN'] ) : '' );
        $origin_allowed = '' !== $origin && in_array( $origin, $this->get_allowed_origins(), true );

        header( 'Vary: Origin', false );
        if ( $origin_allowed ) {
            header( 'Access-Control-Allow-Origin: ' . $origin );
            header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
            header( 'Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With' );
        }

        if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'OPTIONS' === $_SERVER['REQUEST_METHOD'] ) {
            // A preflight from an origin that is not allowed gets no CORS headers and a 403.
            status_header( ( '' !== $origin && ! $origin_allowed ) ? 403 : 200 );
            exit;
        }

        // Read at most one image's worth of body, so a huge upload cannot fill memory.
        $max_body = Kitchen_Synk_AI_Gate::MAX_BODY_BYTES;
        if ( isset( $_SERVER['CONTENT_LENGTH'] ) && (int) $_SERVER['CONTENT_LENGTH'] > $max_body ) {
            $this->send_json_error( 413, 'Request body is too large.' );
        }
        $input_raw = file_get_contents( 'php://input', false, null, 0, $max_body + 1 );
        if ( false !== $input_raw && strlen( $input_raw ) > $max_body ) {
            $this->send_json_error( 413, 'Request body is too large.' );
        }
        $input_data = json_decode( (string) $input_raw, true );
        if ( ! is_array( $input_data ) ) {
            $input_data = array();
        }

        // The Gemini paths spend the owner's key: logged-in users only, before any quota is counted.
        $is_gemini_path = (
            strpos( $path, 'scan-barcode' ) !== false ||
            strpos( $path, 'scan-pantry' ) !== false ||
            strpos( $path, 'generate-recipes' ) !== false
        );
        $gate = $is_gemini_path ? Kitchen_Synk_AI_Gate::can_use_ai() : true;

        if ( is_wp_error( $gate ) ) {
            $response = $gate;
        } elseif ( strpos( $path, 'barcode-lookup' ) !== false ) {
            $barcode  = isset( $input_data['barcode'] ) ? $input_data['barcode'] : '';
            $response = $this->api->handle_barcode_lookup( $barcode );
        } elseif ( strpos( $path, 'scan-barcode' ) !== false ) {
            $response = $this->api->handle_scan_barcode( $input_data );
        } elseif ( strpos( $path, 'scan-pantry' ) !== false ) {
            $response = $this->api->handle_scan_pantry( $input_data );
        } elseif ( strpos( $path, 'generate-recipes' ) !== false ) {
            $response = $this->api->handle_generate_recipes( $input_data );
        } else {
            return;
        }

        header( 'Content-Type: application/json; charset=UTF-8' );
        if ( is_wp_error( $response ) ) {
            $status = $response->get_error_data() && isset( $response->get_error_data()['status'] ) ? $response->get_error_data()['status'] : 500;
            status_header( $status );
            echo wp_json_encode( array( 'error' => $response->get_error_message() ) );
        } elseif ( $response instanceof WP_REST_Response ) {
            status_header( $response->get_status() );
            echo wp_json_encode( $response->get_data() );
        } else {
            status_header( 200 );
            echo wp_json_encode( $response );
        }
        exit;
    }

    /**
     * Origins that may read the /api paths from a browser: this site's own origin (home and site
     * URL, with scheme and port), plus whatever the xophz_kitchen_synk_allowed_origins filter adds.
     * The plugin configures no other origin.
     *
     * @return string[]
     */
    private function get_allowed_origins() {
        $origins = apply_filters( 'xophz_kitchen_synk_allowed_origins', array( home_url(), site_url() ) );

        return array_values( array_unique( array_filter( array_map( array( $this, 'normalize_origin' ), (array) $origins ) ) ) );
    }

    /**
     * Reduces a URL or an Origin header to scheme://host[:port] in lower case, or '' when it is not
     * an http(s) origin.
     *
     * @param mixed $value URL or Origin header value.
     * @return string
     */
    private function normalize_origin( $value ) {
        $parts = is_string( $value ) ? wp_parse_url( trim( $value ) ) : false;
        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return '';
        }

        $scheme = strtolower( $parts['scheme'] );
        if ( 'http' !== $scheme && 'https' !== $scheme ) {
            return '';
        }

        return $scheme . '://' . strtolower( $parts['host'] ) . ( ! empty( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
    }

    private function send_json_error( $status, $message ) {
        status_header( $status );
        header( 'Content-Type: application/json; charset=UTF-8' );
        echo wp_json_encode( array( 'error' => $message ) );
        exit;
    }
}

function run_xophz_kitchen_synk() {
    new Xophz_Kitchen_Synk();
}
add_action( 'plugins_loaded', 'run_xophz_kitchen_synk' );

function xophz_kitchen_synk_activate() {
    $plugin = new Xophz_Kitchen_Synk();
    $plugin->register_rewrites();
    flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'xophz_kitchen_synk_activate' );

function xophz_kitchen_synk_action_links( $links ) {
    $settings_link = '<a href="options-general.php?page=xophz-kitchen-synk">' . __( 'Settings', 'xophz-kitchen-synk' ) . '</a>';
    $new_links = array( 'settings' => $settings_link );
    foreach ( $links as $key => $value ) {
        $new_links[ $key ] = $value;
    }
    return $new_links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'xophz_kitchen_synk_action_links' );
