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
        if ( ! empty( $slug ) && ( strpos( $requested_url, '/' . $slug . '/_next/' ) !== false || strpos( $requested_url, '/_next/' ) !== false ) ) {
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
        return ( defined( 'WP_ENV' ) && WP_ENV === 'development' ) || ( defined( 'WP_DEBUG' ) && WP_DEBUG );
    }

    public function template_redirect() {
        if ( get_query_var( 'xophz_kitchen_synk' ) ) {
            $is_dev = $this->is_dev_mode();
            $next_port = '3005';
            if ( isset( $_SERVER['HTTP_HOST'] ) ) {
                $host_parts = explode(':', $_SERVER['HTTP_HOST']);
                $wp_host = $host_parts[0];
            } else {
                $wp_host = wp_parse_url( home_url(), PHP_URL_HOST );
            }
            $next_url = "//" . $wp_host . ":" . $next_port;
            $slug = get_option( 'xophz_kitchen_synk_custom_slug', 'kitchen-synk' );

            if ( $is_dev ) {
                $internal_host = 'compass';
                $request_uri = $_SERVER['REQUEST_URI'];
                
                // If this is an asset request (e.g. /_next/ or static files like .css, .js, images)
                $path = parse_url($request_uri, PHP_URL_PATH);
                $extension = pathinfo($path, PATHINFO_EXTENSION);
                $is_asset = strpos($request_uri, '/_next/') !== false || in_array(strtolower($extension), array('css', 'js', 'json', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'eot', 'map'));

                if ($is_asset) {
                    $context = stream_context_create(array(
                        'http' => array(
                            'method' => $_SERVER['REQUEST_METHOD'],
                            'ignore_errors' => true
                        )
                    ));
                    $asset_data = @file_get_contents("http://{$internal_host}:{$next_port}" . $request_uri, false, $context);
                    if ($asset_data !== false) {
                        if (isset($http_response_header)) {
                            foreach ($http_response_header as $header) {
                                if (preg_match('/^Content-Type:/i', $header)) {
                                    header($header);
                                }
                            }
                        }
                        echo $asset_data;
                        exit;
                    }
                }
                
                // Fetch HTML from Next.js server
                $dev_html = @file_get_contents("http://{$internal_host}:{$next_port}" . $request_uri);
                
                if ($dev_html) {
                    $base_path = '/' . $slug;
                    // Replace /kitchen-synk/_next/ with $next_url/kitchen-synk/_next/ cleanly without double replacing
                    $dev_html = str_replace($base_path . '/_next/', $next_url . $base_path . '/_next/', $dev_html);
                    $dev_html = str_replace('src="/_next/', 'src="' . $next_url . '/_next/', $dev_html);
                    $dev_html = str_replace('href="/_next/', 'href="' . $next_url . '/_next/', $dev_html);
                    
                    // Rewrite API calls in HTML if present
                    $dev_html = str_replace('"' . $base_path . '/api/', '"' . $next_url . $base_path . '/api/', $dev_html);

                    $nonce = wp_create_nonce('wp_rest');
                    $user_id = get_current_user_id();
                    $wp_api_settings = "<script>window.wpApiSettings = { root: '" . esc_url_raw(rest_url()) . "', nonce: '" . $nonce . "', pluginUrl: '" . esc_url_raw(XOPHZ_KITCHEN_SYNK_URL) . "', version: '" . esc_js(XOPHZ_KITCHEN_SYNK_VERSION) . "', userId: " . $user_id . " };</script>";
                    
                    if (strpos($dev_html, '</head>') !== false) {
                        $dev_html = str_replace('</head>', $wp_api_settings . "\n</head>", $dev_html);
                    } else if (strpos($dev_html, '<body') !== false) {
                        $dev_html = str_replace('<body', $wp_api_settings . "\n<body", $dev_html);
                    } else {
                        $dev_html = $wp_api_settings . $dev_html;
                    }

                    echo $dev_html;
                    exit;
                }
            }

            // Production static dist serving (matches xophz-compass-phone and xophz-compass-yellow-links)
            $dist_path = XOPHZ_KITCHEN_SYNK_PATH . 'public/dist/';
            $request_uri = $_SERVER['REQUEST_URI'];
            $path = parse_url($request_uri, PHP_URL_PATH);
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
                
                // Rewrite asset paths for production
                $content = str_replace( '"/' . $slug . '/_next/', '"' . $dist_url . '_next/', $content );
                $content = str_replace( "'/" . $slug . "/_next/", "'" . $dist_url . "_next/", $content );
                $content = str_replace( '"/_next/', '"' . $dist_url . '_next/', $content );
                $content = str_replace( "'/_next/", "'" . $dist_url . "_next/", $content );

                // Inject wpApiSettings for production REST API authentication
                $nonce = wp_create_nonce('wp_rest');
                $user_id = get_current_user_id();
                $wp_api_settings = "<script>window.wpApiSettings = { root: '" . esc_url_raw(rest_url()) . "', nonce: '" . $nonce . "', pluginUrl: '" . esc_url_raw(XOPHZ_KITCHEN_SYNK_URL) . "', version: '" . esc_js(XOPHZ_KITCHEN_SYNK_VERSION) . "', userId: " . $user_id . " };</script>";
                if ( strpos( $content, '</head>' ) !== false ) {
                    $content = str_replace( '</head>', $wp_api_settings . "\n</head>", $content );
                } else if ( strpos( $content, '<body' ) !== false ) {
                    $content = str_replace( '<body', $wp_api_settings . "\n<body", $content );
                } else {
                    $content = $wp_api_settings . $content;
                }

                header( 'Content-Type: text/html; charset=UTF-8' );
                echo $content;
            } else {
                echo '<h2>Kitchen Synk is not built yet.</h2><p>Please run <code>npm run build</code> in the <code>apps/kitchen-synk</code> directory.</p>';
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

