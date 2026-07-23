<?php
/**
 * Plugin Name:       Xophz Kitchen Synk
 * Description:       Standalone WordPress backend and router for the Kitchen Synk web app.
 * Version:           26.7.22
 * Author:            Hall of the Gods, Inc.
 * Category:          Command Deck
 * Group:             Ecosystem
 * Text Domain:       xophz-kitchen-synk
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'XOPHZ_KITCHEN_SYNK_VERSION', '26.7.22' );
define( 'XOPHZ_KITCHEN_SYNK_PATH', plugin_dir_path( __FILE__ ) );
define( 'XOPHZ_KITCHEN_SYNK_URL', plugin_dir_url( __FILE__ ) );

require_once XOPHZ_KITCHEN_SYNK_PATH . 'class-kitchen-synk-api.php';

class Xophz_Kitchen_Synk {
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_plugin_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        
        // Flush rewrites when setting is saved
        add_action( 'update_option_xophz_kitchen_synk_custom_slug', array( $this, 'flush_rewrites_on_save' ), 10, 2 );

        // Disable WordPress canonical redirect trailing slash adding for app assets
        add_filter( 'redirect_canonical', array( $this, 'disable_canonical_for_app_assets' ), 10, 2 );

        // Public rewrite and template
        add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
        add_action( 'init', array( $this, 'register_rewrites' ) );
        add_action( 'template_redirect', array( $this, 'template_redirect' ) );
    }

    public function disable_canonical_for_app_assets( $redirect_url, $requested_url ) {
        $slug = get_option( 'xophz_kitchen_synk_custom_slug', 'kitchen-synk' );
        if ( ! empty( $slug ) && ( strpos( $requested_url, '/' . $slug . '/assets/' ) !== false || strpos( $requested_url, '/assets/' ) !== false ) ) {
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
        register_setting( 'xophz_kitchen_synk_options', 'xophz_kitchen_synk_custom_slug' );
    }

    public function display_plugin_setup_page() {
        ?>
        <div class="wrap">
            <h2>Kitchen Synk Settings</h2>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'xophz_kitchen_synk_options' );
                do_settings_sections( 'xophz_kitchen_synk_options' );
                $slug = get_option( 'xophz_kitchen_synk_custom_slug', 'kitchen-synk' );
                ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Deployment Slug</th>
                        <td>
                            <input type="text" name="xophz_kitchen_synk_custom_slug" value="<?php echo esc_attr( $slug ); ?>" class="regular-text" />
                            <p class="description">The URL slug where the app will be loaded (e.g. <code>kitchen-synk</code> for <code>/kitchen-synk</code>). Leave blank to disable standalone rendering.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
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
        return $vars;
    }

    public function register_rewrites() {
        $slug = get_option( 'xophz_kitchen_synk_custom_slug', 'kitchen-synk' );
        
        if ( ! empty( $slug ) ) {
            // Match exactly /slug or /slug/
            add_rewrite_rule(
                '^' . $slug . '/?$',
                'index.php?xophz_kitchen_synk=1',
                'top'
            );
            // Catch-all for frontend routing
            add_rewrite_rule(
                '^' . $slug . '/(.*)?$',
                'index.php?xophz_kitchen_synk=1',
                'top'
            );
        }
    }

    private function is_dev_mode() {
        if ( isset( $_GET['dev'] ) || isset( $_GET['vite'] ) ) {
            return true;
        }
        if ( ( defined( 'WP_ENV' ) && WP_ENV === 'development' ) || ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
            return true;
        }
        // Auto-detect if Vite dev server is active on port 3005
        $connection = @fsockopen( 'compass', 3005, $errno, $errstr, 1 );
        if ( is_resource( $connection ) ) {
            fclose( $connection );
            return true;
        }
        return false;
    }

    public function template_redirect() {
        if ( get_query_var( 'xophz_kitchen_synk' ) ) {
            $is_dev = $this->is_dev_mode();
            $vite_port = '3005';
            if ( isset( $_SERVER['HTTP_HOST'] ) ) {
                $host_parts = explode(':', $_SERVER['HTTP_HOST']);
                $wp_host = $host_parts[0];
            } else {
                $wp_host = wp_parse_url( home_url(), PHP_URL_HOST );
            }
            $vite_url = "//" . $wp_host . ":" . $vite_port;

            if ( $is_dev ) {
                $internal_host = 'compass';
                $dev_html = @file_get_contents("http://{$internal_host}:{$vite_port}/");
                if ($dev_html) {
                    // Rewrite relative src/href/import/from for dev server
                    $dev_html = str_replace('src="/', 'src="' . $vite_url . '/', $dev_html);
                    $dev_html = str_replace('href="/', 'href="' . $vite_url . '/', $dev_html);
                    $dev_html = str_replace('import("/', 'import("' . $vite_url . '/', $dev_html);
                    $dev_html = str_replace('from "/', 'from "' . $vite_url . '/', $dev_html);
                    $dev_html = str_replace("from '/", "from '" . $vite_url . "/", $dev_html);

                    // Inject Vite client if not present
                    if (strpos($dev_html, '/@vite/client') === false) {
                        $vite_client = '<script type="module" src="' . esc_url($vite_url) . '/@vite/client"></script>';
                        $dev_html = str_replace('</head>', $vite_client . "\n</head>", $dev_html);
                    }

                    $nonce = wp_create_nonce('wp_rest');
                    $user_id = get_current_user_id();
                    $wp_api_settings = "<script>window.wpApiSettings = { root: '" . esc_url_raw(rest_url()) . "', nonce: '" . $nonce . "', pluginUrl: '" . esc_url_raw(XOPHZ_KITCHEN_SYNK_URL) . "', version: '" . esc_js(XOPHZ_KITCHEN_SYNK_VERSION) . "', userId: " . $user_id . " };</script>";
                    $dev_html = str_replace('</head>', $wp_api_settings . "\n</head>", $dev_html);

                    echo $dev_html;
                    exit;
                }
            }

            // Production static dist serving
            $dist_path = XOPHZ_KITCHEN_SYNK_PATH . 'public/dist/';
            $request_uri = $_SERVER['REQUEST_URI'];
            $path = parse_url($request_uri, PHP_URL_PATH);
            $slug = get_option( 'xophz_kitchen_synk_custom_slug', 'kitchen-synk' );
            $slug_prefix = '/' . $slug;

            // Remove slug prefix if present to find file in dist/
            $rel_path = $path;
            if ( strpos( $rel_path, $slug_prefix ) === 0 ) {
                $rel_path = substr( $rel_path, strlen( $slug_prefix ) );
            }
            $rel_path = ltrim( $rel_path, '/' );

            $target_file = $dist_path . ( empty( $rel_path ) ? 'index.html' : $rel_path );

            if ( file_exists( $target_file ) && ! is_dir( $target_file ) ) {
                $mime_types = array(
                    'css'  => 'text/css; charset=UTF-8',
                    'js'   => 'application/javascript; charset=UTF-8',
                    'json' => 'application/json; charset=UTF-8',
                    'png'  => 'image/png',
                    'jpg'  => 'image/jpeg',
                    'jpeg' => 'image/jpeg',
                    'gif'  => 'image/gif',
                    'svg'  => 'image/svg+xml',
                    'ico'  => 'image/x-icon',
                    'woff' => 'font/woff',
                    'woff2'=> 'font/woff2',
                    'ttf'  => 'font/ttf',
                    'html' => 'text/html; charset=UTF-8',
                );
                $ext = strtolower( pathinfo( $target_file, PATHINFO_EXTENSION ) );
                if ( isset( $mime_types[ $ext ] ) ) {
                    header( 'Content-Type: ' . $mime_types[ $ext ] );
                }
                readfile( $target_file );
                exit;
            }

            // Fallback to serving public/dist/index.html
            $index_path = $dist_path . 'index.html';
            if ( file_exists( $index_path ) ) {
                $content = file_get_contents( $index_path );
                $dist_url = XOPHZ_KITCHEN_SYNK_URL . 'public/dist/';
                
                // Rewrite absolute paths for production assets
                $content = str_replace( '"/assets/', '"' . $dist_url . 'assets/', $content );
                $content = str_replace( "'/assets/", "'" . $dist_url . "assets/", $content );
                $content = str_replace( '"/vite.svg"', '"' . $dist_url . 'vite.svg"', $content );

                // Inject wpApiSettings for production REST API authentication
                $nonce = wp_create_nonce('wp_rest');
                $user_id = get_current_user_id();
                $wp_api_settings = "<script>window.wpApiSettings = { root: '" . esc_url_raw(rest_url()) . "', nonce: '" . $nonce . "', pluginUrl: '" . esc_url_raw(XOPHZ_KITCHEN_SYNK_URL) . "', version: '" . esc_js(XOPHZ_KITCHEN_SYNK_VERSION) . "', userId: " . $user_id . " };</script>";
                $content = str_replace('</head>', $wp_api_settings . "\n</head>", $content);

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

