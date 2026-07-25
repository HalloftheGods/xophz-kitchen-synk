<?php
/**
 * Plugin Name:       Xophz Kitchen Synk
 * Description:       Standalone WordPress backend and router for the Kitchen Synk web app.
 * Version:           26.7.24.1428
 * Author:            Hall of the Gods, Inc.
 * Category:          Command Deck
 * Group:             Ecosystem
 * Text Domain:       xophz-kitchen-synk
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'XOPHZ_KITCHEN_SYNK_VERSION', '26.7.24.1426' );
define( 'XOPHZ_KITCHEN_SYNK_PATH', plugin_dir_path( __FILE__ ) );
define( 'XOPHZ_KITCHEN_SYNK_URL', plugin_dir_url( __FILE__ ) );

require_once XOPHZ_KITCHEN_SYNK_PATH . 'class-kitchen-synk-api.php';

require_once XOPHZ_KITCHEN_SYNK_PATH . 'includes/class-kitchen-synk-api.php';

class Xophz_Kitchen_Synk {
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
    }

    /**
     * Get the active base URL for Kitchen Synk based on current load mode.
     */
    public function get_kitchen_synk_base_url( $path = '', $custom_slug = '' ) {
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

    /**
     * Check if current page matches the configured load mode target (homepage or specific page).
     */
    private function is_configured_page() {
        $load_mode = get_option( 'xophz_kitchen_synk_load_mode', 'custom_slug' );

        if ( 'homepage' === $load_mode && is_front_page() ) {
            return true;
        }

        if ( 'specific_page' === $load_mode ) {
            $page_id = (int) get_option( 'xophz_kitchen_synk_load_page_id', 0 );
            if ( $page_id > 0 && is_page( $page_id ) ) {
                return true;
            }
        }

        return false;
    }

    public function disable_canonical_for_app_assets( $redirect_url, $requested_url ) {
        $load_mode = get_option( 'xophz_kitchen_synk_load_mode', 'custom_slug' );
        if ( 'homepage' === $load_mode && is_front_page() ) {
            return false;
        }

        if ( strpos( $requested_url, 'kitchen-synk-prod' ) !== false ) {
            return false;
        }

        $slug = get_option( 'xophz_kitchen_synk_custom_slug', 'kitchen-synk' );
        if ( ! empty( $slug ) && ( strpos( $requested_url, '/' . $slug . '/assets/' ) !== false || strpos( $requested_url, '/' . $slug . '-prod/assets/' ) !== false || strpos( $requested_url, '/assets/' ) !== false ) ) {
            return false;
        }
        $path = parse_url( $requested_url, PHP_URL_PATH );
        $extension = pathinfo( $path, PATHINFO_EXTENSION );
        if ( ! empty( $extension ) ) {
            return false;
        }
        return $redirect_url;
    }

    public function add_plugin_admin_menu() {
        add_options_page(
            'Kitchen Synk Settings',
            'Kitchen Synk',
            'manage_options',
            'xophz-kitchen-synk',
            array( $this, 'display_plugin_setup_page' )
        );
    }

    public function register_settings() {
        register_setting( 'xophz_kitchen_synk_options', 'xophz_kitchen_synk_load_mode', array(
            'type'              => 'string',
            'default'           => 'custom_slug',
            'sanitize_callback' => array( $this, 'sanitize_load_mode' ),
        ) );

        register_setting( 'xophz_kitchen_synk_options', 'xophz_kitchen_synk_load_page_id', array(
            'type'              => 'integer',
            'default'           => 0,
            'sanitize_callback' => 'absint',
        ) );

        register_setting( 'xophz_kitchen_synk_options', 'xophz_kitchen_synk_custom_slug', array(
            'type'              => 'string',
            'default'           => 'kitchen-synk',
            'sanitize_callback' => 'sanitize_title',
        ) );

        register_setting( 'xophz_kitchen_synk_options', 'xophz_kitchen_synk_show_admin_bar', array(
            'type'              => 'boolean',
            'default'           => true,
            'sanitize_callback' => 'rest_sanitize_boolean',
        ) );
    }

    public function sanitize_load_mode( $value ) {
        $valid_modes = array( 'custom_slug', 'homepage', 'specific_page' );
        return in_array( $value, $valid_modes, true ) ? $value : 'custom_slug';
    }

    /**
     * Add Quick Access dropdown menu to WordPress Admin Bar.
     */
    public function add_admin_bar_button( $wp_admin_bar ) {
        $show_admin_bar = get_option( 'xophz_kitchen_synk_show_admin_bar', true );
        if ( ! $show_admin_bar ) {
            return;
        }

        $base_url = $this->get_kitchen_synk_base_url();
        $prod_url = home_url( '/kitchen-synk-prod/' );

        $wp_admin_bar->add_node( array(
            'id'    => 'kitchen-synk-menu',
            'title' => '<span class="ab-icon dashicons dashicons-carrot" style="top:2px;"></span><span class="ab-label">Kitchen Synk</span>',
            'href'  => $base_url,
            'meta'  => array(
                'title' => 'Kitchen Synk App',
            ),
        ) );

        $wp_admin_bar->add_node( array(
            'parent' => 'kitchen-synk-menu',
            'id'     => 'kitchen-synk-app',
            'title'  => '🍳 Open App (Dev)',
            'href'   => $base_url,
        ) );

        $wp_admin_bar->add_node( array(
            'parent' => 'kitchen-synk-menu',
            'id'     => 'kitchen-synk-app-prod',
            'title'  => '📦 Open App (Prod Static)',
            'href'   => $prod_url,
        ) );

        $wp_admin_bar->add_node( array(
            'parent' => 'kitchen-synk-menu',
            'id'     => 'kitchen-synk-inventory',
            'title'  => '📦 Inventory',
            'href'   => $base_url . '#inventory',
        ) );

        $wp_admin_bar->add_node( array(
            'parent' => 'kitchen-synk-menu',
            'id'     => 'kitchen-synk-recipes',
            'title'  => '👨‍🍳 AI Recipes',
            'href'   => $base_url . '#recipes',
        ) );

        $wp_admin_bar->add_node( array(
            'parent' => 'kitchen-synk-menu',
            'id'     => 'kitchen-synk-scanner',
            'title'  => '📷 Barcode Scanner',
            'href'   => $base_url . '#scanner',
        ) );

        $wp_admin_bar->add_node( array(
            'parent' => 'kitchen-synk-menu',
            'id'     => 'kitchen-synk-grocery',
            'title'  => '🛒 Grocery List',
            'href'   => $base_url . '#grocery',
        ) );

        $wp_admin_bar->add_node( array(
            'parent' => 'kitchen-synk-menu',
            'id'     => 'kitchen-synk-settings',
            'title'  => '⚙️ WP Settings',
            'href'   => admin_url( 'options-general.php?page=xophz-kitchen-synk' ),
        ) );
    }

    public function display_plugin_setup_page() {
        $load_mode   = get_option( 'xophz_kitchen_synk_load_mode', 'custom_slug' );
        $page_id     = (int) get_option( 'xophz_kitchen_synk_load_page_id', 0 );
        $slug        = get_option( 'xophz_kitchen_synk_custom_slug', 'kitchen-synk' );
        $show_bar    = get_option( 'xophz_kitchen_synk_show_admin_bar', true );
        $is_dev      = $this->is_dev_mode();
        $app_url     = $this->get_kitchen_synk_base_url();
        $prod_app_url = home_url( '/kitchen-synk-prod/' );
        ?>
        <div class="wrap">
            <h1>🍳 Kitchen Synk Settings</h1>
            <p class="description">Configure how and where the Kitchen Synk application is served across your WordPress site.</p>

            <form method="post" action="options.php" style="margin-top: 20px;">
                <?php settings_fields( 'xophz_kitchen_synk_options' ); ?>

                <div class="card" style="max-width: 800px; padding: 20px; margin-bottom: 20px; border-radius: 8px;">
                    <h2 style="margin-top:0;">Deployment & Homepage Mode</h2>
                    <p>Choose where Kitchen Synk should be rendered on your site.</p>

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">Load Mode</th>
                            <td>
                                <fieldset>
                                    <label style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
                                        <input type="radio" name="xophz_kitchen_synk_load_mode" value="custom_slug" <?php checked( $load_mode, 'custom_slug' ); ?> />
                                        <strong>Custom Slug</strong> — Load on a specific URL path
                                    </label>
                                    <div id="slug_input_container" style="margin-left: 28px; margin-bottom: 16px;">
                                        <code><?php echo esc_url( home_url( '/' ) ); ?></code>
                                        <input type="text" id="xophz_kitchen_synk_custom_slug" name="xophz_kitchen_synk_custom_slug" value="<?php echo esc_attr( $slug ); ?>" class="regular-text" placeholder="kitchen-synk" style="width: 180px;" />
                                        <code>/</code>
                                        <p class="description">Default is <code>kitchen-synk</code> (e.g. <code>/kitchen-synk</code>).</p>
                                    </div>

                                    <label style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
                                        <input type="radio" name="xophz_kitchen_synk_load_mode" value="homepage" <?php checked( $load_mode, 'homepage' ); ?> />
                                        <strong>Homepage Mode</strong> — Set Kitchen Synk as the site's main front page (<code>/</code>)
                                    </label>
                                    <p class="description" style="margin-left: 28px; margin-bottom: 16px;">
                                        Replaces your WordPress landing page with the complete Kitchen Synk experience.
                                    </p>

                                    <label style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
                                        <input type="radio" name="xophz_kitchen_synk_load_mode" value="specific_page" <?php checked( $load_mode, 'specific_page' ); ?> />
                                        <strong>Target Page</strong> — Load on a specific WordPress Page
                                    </label>
                                    <div id="page_dropdown_container" style="margin-left: 28px; margin-bottom: 16px;">
                                        <?php
                                        wp_dropdown_pages( array(
                                            'name'              => 'xophz_kitchen_synk_load_page_id',
                                            'id'                => 'xophz_kitchen_synk_load_page_id',
                                            'selected'          => $page_id,
                                            'show_option_none'  => '— Select a Page —',
                                            'option_none_value' => '0',
                                        ) );
                                        ?>
                                        <p class="description">App will replace the chosen page content.</p>
                                    </div>
                                </fieldset>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="card" style="max-width: 800px; padding: 20px; margin-bottom: 20px; border-radius: 8px;">
                    <h2 style="margin-top:0;">Admin Bar Quick Links</h2>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">WordPress Admin Bar</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="xophz_kitchen_synk_show_admin_bar" value="1" <?php checked( $show_bar, true ); ?> />
                                    Show 🍳 <strong>Kitchen Synk</strong> menu in the WordPress Admin Bar for quick access to Inventory, Recipes, Scanner, etc.
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="card" style="max-width: 800px; padding: 20px; margin-bottom: 20px; border-radius: 8px; background: #fafafa;">
                    <h2 style="margin-top:0;">System & Integration Info</h2>
                    <ul style="line-height: 1.8;">
                        <li><strong>Active Portal Endpoint:</strong> <a href="<?php echo esc_url( $app_url ); ?>" target="_blank"><code><?php echo esc_html( $app_url ); ?></code></a></li>
                        <li><strong>Local Production Test Endpoint:</strong> <a href="<?php echo esc_url( $prod_app_url ); ?>" target="_blank"><code><?php echo esc_html( $prod_app_url ); ?></code></a></li>
                        <li><strong>Server Environment:</strong> <?php echo $is_dev ? '<span style="color:#d97706;font-weight:bold;">⚡ Vite Dev Mode (Port 3005)</span>' : '<span style="color:#16a34a;font-weight:bold;">📦 Production Static Bundle</span>'; ?></li>
                        <li><strong>REST API Namespace:</strong> <code><?php echo esc_url( rest_url( 'kitchen-synk/v1/' ) ); ?></code></li>
                    </ul>
                </div>

                <?php submit_button( 'Save Kitchen Synk Settings' ); ?>
            </form>
        </div>

        <script>
        (function() {
            const radios = document.querySelectorAll('input[name="xophz_kitchen_synk_load_mode"]');
            const slugContainer = document.getElementById('slug_input_container');
            const pageContainer = document.getElementById('page_dropdown_container');

            function updateVisibility() {
                const selected = document.querySelector('input[name="xophz_kitchen_synk_load_mode"]:checked');
                const val = selected ? selected.value : 'custom_slug';

                if (slugContainer) {
                    slugContainer.style.display = val === 'custom_slug' ? 'block' : 'none';
                }
                if (pageContainer) {
                    pageContainer.style.display = val === 'specific_page' ? 'block' : 'none';
                }
            }

            radios.forEach(r => r.addEventListener('change', updateVisibility));
            updateVisibility();
        })();
        </script>
        <?php
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

    private function is_dev_mode() {
        if ( get_query_var( 'xophz_kitchen_synk_force_prod' ) || ( isset( $_SERVER['REQUEST_URI'] ) && strpos( $_SERVER['REQUEST_URI'], 'kitchen-synk-prod' ) !== false ) || isset( $_GET['prod'] ) ) {
            return false;
        }
        if ( isset( $_GET['dev'] ) || isset( $_GET['vite'] ) ) {
            return true;
        }
        if ( ( defined( 'WP_ENV' ) && WP_ENV === 'development' ) || ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
            return true;
        }
        $connection = @fsockopen( 'compass', 3005, $errno, $errstr, 1 );
        if ( is_resource( $connection ) ) {
            fclose( $connection );
            return true;
        }
        return false;
    }

    public function template_redirect() {
        $load_mode                = get_option( 'xophz_kitchen_synk_load_mode', 'custom_slug' );
        $is_slug_match            = (bool) get_query_var( 'xophz_kitchen_synk' );
        $is_configured_page       = $this->is_configured_page();
        $is_homepage_404_fallback = ( 'homepage' === $load_mode && is_404() );
        $is_force_prod            = (bool) get_query_var( 'xophz_kitchen_synk_force_prod' ) || ( isset( $_SERVER['REQUEST_URI'] ) && strpos( $_SERVER['REQUEST_URI'], 'kitchen-synk-prod' ) !== false );

        if ( $is_slug_match || $is_configured_page || $is_homepage_404_fallback || $is_force_prod ) {
            status_header( 200 );
            global $wp_query;
            if ( is_object( $wp_query ) ) {
                $wp_query->is_404 = false;
            }

            $slug        = get_option( 'xophz_kitchen_synk_custom_slug', 'kitchen-synk' );
            $active_slug = $is_force_prod ? 'kitchen-synk-prod' : $slug;
            $is_dev      = $is_force_prod ? false : $this->is_dev_mode();

            $vite_port = '3005';
            if ( isset( $_SERVER['HTTP_HOST'] ) ) {
                $host_parts = explode( ':', $_SERVER['HTTP_HOST'] );
                $wp_host    = $host_parts[0];
            } else {
                $wp_host = wp_parse_url( home_url(), PHP_URL_HOST );
            }
            $vite_url = "//" . $wp_host . ":" . $vite_port;

            $nonce        = wp_create_nonce( 'wp_rest' );
            $current_user = wp_get_current_user();
            $is_logged_in = is_user_logged_in();
            $user_id      = $current_user->ID;
            $base_url     = $this->get_kitchen_synk_base_url( '', $active_slug );

            $wp_user_data = array(
                'isLoggedIn' => $is_logged_in,
                'id'         => $user_id,
                'name'       => $is_logged_in ? $current_user->display_name : 'Guest User',
                'login'      => $is_logged_in ? $current_user->user_login : '',
                'email'      => $is_logged_in ? $current_user->user_email : '',
                'avatar'     => $is_logged_in ? get_avatar_url( $user_id, array( 'size' => 96 ) ) : '',
                'roles'      => $is_logged_in ? array_values( $current_user->roles ) : array(),
                'tier'       => $is_logged_in ? ( get_user_meta( $user_id, 'kitchensynk_user_type', true ) ?: 'starter' ) : 'free',
                'loginUrl'   => wp_login_url( $base_url ),
                'logoutUrl'  => wp_logout_url( $base_url ),
            );

            $wp_api_settings = "<script>window.wpApiSettings = { "
                . "root: '" . esc_url_raw( rest_url() ) . "', "
                . "nonce: '" . esc_js( $nonce ) . "', "
                . "pluginUrl: '" . esc_url_raw( XOPHZ_KITCHEN_SYNK_URL ) . "', "
                . "version: '" . esc_js( XOPHZ_KITCHEN_SYNK_VERSION ) . "', "
                . "userId: " . (int) $user_id . ", "
                . "loadMode: '" . esc_js( $load_mode ) . "', "
                . "baseUrl: '" . esc_url_raw( $base_url ) . "', "
                . "slug: '" . esc_js( $active_slug ) . "', "
                . "wpUser: " . wp_json_encode( $wp_user_data ) . " "
                . "};</script>";

            if ( $is_dev ) {
                $internal_host = 'compass';
                $dev_html      = @file_get_contents( "http://{$internal_host}:{$vite_port}/" );
                if ( $dev_html ) {
                    $dev_html = str_replace( 'src="/', 'src="' . $vite_url . '/', $dev_html );
                    $dev_html = str_replace( 'href="/', 'href="' . $vite_url . '/', $dev_html );
                    $dev_html = str_replace( 'import("/', 'import("' . $vite_url . '/', $dev_html );
                    $dev_html = str_replace( 'from "/', 'from="' . $vite_url . '/', $dev_html );
                    $dev_html = str_replace( "from '/", "from '" . $vite_url . "/", $dev_html );

                    if ( strpos( $dev_html, '/@vite/client' ) === false ) {
                        $vite_client = '<script type="module" src="' . esc_url( $vite_url ) . '/@vite/client"></script>';
                        $dev_html    = str_replace( '</head>', $vite_client . "\n</head>", $dev_html );
                    }

                    $dev_html = str_replace( '</head>', $wp_api_settings . "\n</head>", $dev_html );

                    echo $dev_html;
                    exit;
                }
            }

            // Production static dist serving
            $dist_path   = XOPHZ_KITCHEN_SYNK_PATH . 'public/dist/';
            $dist_url    = XOPHZ_KITCHEN_SYNK_URL . 'public/dist/';
            $request_uri = $_SERVER['REQUEST_URI'];
            $path        = parse_url( $request_uri, PHP_URL_PATH );

            $rel_path = $path;
            if ( strpos( $rel_path, '/' . $active_slug ) === 0 ) {
                $rel_path = substr( $rel_path, strlen( '/' . $active_slug ) );
            } elseif ( strpos( $rel_path, '/' . $slug ) === 0 ) {
                $rel_path = substr( $rel_path, strlen( '/' . $slug ) );
            }
            $rel_path = ltrim( $rel_path, '/' );

            // If a specific non-HTML asset file (e.g. assets/main.js, icon-192.svg) is requested via plugin rewrite
            if ( ! empty( $rel_path ) && 'index.html' !== $rel_path && false === strpos( $rel_path, '.html' ) ) {
                $target_file = $dist_path . $rel_path;
                if ( file_exists( $target_file ) && ! is_dir( $target_file ) ) {
                    $mime_types = array(
                        'css'   => 'text/css; charset=UTF-8',
                        'js'    => 'application/javascript; charset=UTF-8',
                        'json'  => 'application/json; charset=UTF-8',
                        'png'   => 'image/png',
                        'jpg'   => 'image/jpeg',
                        'jpeg'  => 'image/jpeg',
                        'gif'   => 'image/gif',
                        'svg'   => 'image/svg+xml',
                        'ico'   => 'image/x-icon',
                        'woff'  => 'font/woff',
                        'woff2' => 'font/woff2',
                        'ttf'   => 'font/ttf',
                    );
                    $ext        = strtolower( pathinfo( $target_file, PATHINFO_EXTENSION ) );
                    if ( isset( $mime_types[ $ext ] ) ) {
                        header( 'Content-Type: ' . $mime_types[ $ext ] );
                    }
                    readfile( $target_file );
                    exit;
                }
            }

            // Fallback to serving public/dist/index.html with absolute dist asset URL replacements
            $index_path = $dist_path . 'index.html';
            if ( file_exists( $index_path ) ) {
                $content = file_get_contents( $index_path );

                // Inject base tag so relative asset resolution works cleanly
                $base_tag = '<base href="' . esc_url( $dist_url ) . '">';
                if ( strpos( $content, '<base ' ) === false ) {
                    $content = str_replace( '<head>', "<head>\n    " . $base_tag, $content );
                }

                // Rewrite all asset paths to absolute plugin dist URL
                $content = str_replace( 'src="./assets/', 'src="' . $dist_url . 'assets/', $content );
                $content = str_replace( 'href="./assets/', 'href="' . $dist_url . 'assets/', $content );
                $content = str_replace( 'src="assets/', 'src="' . $dist_url . 'assets/', $content );
                $content = str_replace( 'href="assets/', 'href="' . $dist_url . 'assets/', $content );
                $content = str_replace( 'href="/assets/', 'href="' . $dist_url . 'assets/', $content );
                $content = str_replace( 'src="/assets/', 'src="' . $dist_url . 'assets/', $content );
                $content = str_replace( 'href="./', 'href="' . $dist_url, $content );
                $content = str_replace( 'src="./', 'src="' . $dist_url, $content );

                $content = str_replace( '</head>', $wp_api_settings . "\n</head>", $content );

                header( 'Content-Type: text/html; charset=UTF-8' );
                echo $content;
            } else {
                echo '<h2>Kitchen Synk is not built yet.</h2><p>Please run <code>pnpm --filter kitchen-synk build</code> in the root directory.</p>';
            }
            exit;
        }
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
