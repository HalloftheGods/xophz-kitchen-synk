<?php

if ( ! defined( 'WPINC' ) ) {
	die;
}

trait Xophz_Kitchen_Synk_Frontend {

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

            $referral_code = '';
            $referral_link = '';
            $referral_count = 0;
            $bonus_months = 0;
            
            if ( $is_logged_in ) {
                $referral_code = get_user_meta( $user_id, 'ks_referral_code', true );
                if ( empty( $referral_code ) ) {
                    $login         = strtolower( preg_replace( '/[^a-zA-Z0-9]/', '', $current_user->user_login ) );
                    $referral_code = ( $login ? $login : 'chef' ) . '-' . substr( md5( $user_id . 'ks_ref_salt' ), 0, 6 );
                    update_user_meta( $user_id, 'ks_referral_code', $referral_code );
                }
                
                $list = get_user_meta( $user_id, 'ks_referrals_list', true );
                if ( is_array( $list ) ) {
                    $referral_count = count( $list );
                }
                
                $bonus_months = (int) get_user_meta( $user_id, 'ks_bonus_months', true );
                $referral_link = home_url( '/kitchen-synk/#/?ref=' . rawurlencode( $referral_code ) );
            }

            $wp_user_data = array(
                'isLoggedIn'     => $is_logged_in,
                'id'             => $user_id,
                'name'           => $is_logged_in ? $current_user->display_name : 'Guest User',
                'login'          => $is_logged_in ? $current_user->user_login : '',
                'email'          => $is_logged_in ? $current_user->user_email : '',
                'avatar'         => $is_logged_in ? get_avatar_url( $user_id, array( 'size' => 96 ) ) : '',
                'roles'          => $is_logged_in ? array_values( $current_user->roles ) : array(),
                'tier'           => $is_logged_in ? ( get_user_meta( $user_id, 'kitchensynk_user_type', true ) ?: 'starter' ) : 'free',
                'loginUrl'       => wp_login_url( $base_url ),
                'logoutUrl'      => wp_logout_url( $base_url ),
                'referralCode'   => $referral_code,
                'referralLink'   => $referral_link,
                'referralCount'  => $referral_count,
                'bonusMonths'    => $bonus_months,
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
                . "phpVersion: '" . esc_js( PHP_VERSION ) . "', "
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
                        'css'         => 'text/css; charset=UTF-8',
                        'js'          => 'application/javascript; charset=UTF-8',
                        'json'        => 'application/json; charset=UTF-8',
                        'webmanifest' => 'application/manifest+json; charset=UTF-8',
                        'png'         => 'image/png',
                        'jpg'         => 'image/jpeg',
                        'jpeg'        => 'image/jpeg',
                        'gif'         => 'image/gif',
                        'svg'         => 'image/svg+xml',
                        'ico'         => 'image/x-icon',
                        'woff'        => 'font/woff',
                        'woff2'       => 'font/woff2',
                        'ttf'         => 'font/ttf',
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

    public function inject_recipe_json_ld() {
        if ( ! is_singular( 'ks_saved_recipe' ) ) {
            return;
        }

        global $post;
        
        $prepTime = get_post_meta( $post->ID, 'prepTime', true );
        $cookTime = get_post_meta( $post->ID, 'cookTime', true );
        $calories = get_post_meta( $post->ID, 'caloriesPerServing', true );
        $servings = get_post_meta( $post->ID, 'servings', true );
        $instructions = get_post_meta( $post->ID, 'instructions', true );
        
        $instruction_steps = array();
        if ( is_array( $instructions ) ) {
            foreach ( $instructions as $step ) {
                $instruction_steps[] = array(
                    '@type' => 'HowToStep',
                    'text'  => wp_strip_all_tags( is_array($step) && isset($step['text']) ? $step['text'] : (string) $step )
                );
            }
        }
        
        $ingredients = array();
        $usedExpiring = get_post_meta( $post->ID, 'usedExpiringItems', true );
        $otherUsed = get_post_meta( $post->ID, 'otherUsedItems', true );
        if ( is_array( $usedExpiring ) ) { $ingredients = array_merge($ingredients, $usedExpiring); }
        if ( is_array( $otherUsed ) ) { $ingredients = array_merge($ingredients, $otherUsed); }

        $schema = array(
            '@context' => 'https://schema.org/',
            '@type'    => 'Recipe',
            'name'     => get_the_title( $post->ID ),
            'image'    => get_the_post_thumbnail_url( $post->ID, 'full' ),
            'description' => wp_strip_all_tags( $post->post_content ),
            'recipeIngredient' => array_values( array_unique( $ingredients ) ),
            'recipeInstructions' => $instruction_steps,
        );

        if ( $prepTime ) { $schema['prepTime'] = 'PT' . (int)$prepTime . 'M'; }
        if ( $cookTime ) { $schema['cookTime'] = 'PT' . (int)$cookTime . 'M'; }
        if ( $servings ) { $schema['recipeYield'] = (int)$servings; }
        
        if ( $calories ) {
            $schema['nutrition'] = array(
                '@type' => 'NutritionInformation',
                'calories' => $calories . ' calories'
            );
        }

        echo '<script type="application/ld+json">' . wp_json_encode( $schema ) . '</script>' . "\n";
    }
}
