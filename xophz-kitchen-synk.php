<?php
/**
 * Plugin Name:       Xophz Kitchen Synk
 * Plugin URI:        https://github.com/HalloftheGods/xophz-kitchen-synk
 * Description:       Standalone WordPress backend and router for the Kitchen Synk web app.
 * Version:           26.8.30-246
 * Author:            Hall of the Gods, Inc.
 * Author URI:        https://www.hallofthegods.com/
 * Category:          Command Deck
 * Group:             Ecosystem
 * Text Domain:       xophz-kitchen-synk
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'XOPHZ_KITCHEN_SYNK_VERSION', '26.8.30-246' );
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

        header( 'Access-Control-Allow-Origin: *' );
        header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
        header( 'Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With' );

        if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'OPTIONS' === $_SERVER['REQUEST_METHOD'] ) {
            status_header( 200 );
            exit;
        }

        $input_raw  = file_get_contents( 'php://input' );
        $input_data = json_decode( $input_raw, true );
        if ( ! is_array( $input_data ) ) {
            $input_data = array();
        }

        if ( strpos( $path, 'barcode-lookup' ) !== false ) {
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
