<?php

if ( ! defined( 'WPINC' ) ) {
	die;
}

trait Xophz_Kitchen_Synk_API_Auth {

    public function get_me( WP_REST_Request $request ) {
        $is_logged_in = is_user_logged_in();
        $user_id      = get_current_user_id();
        $current_user = wp_get_current_user();
        $base_url     = home_url( '/kitchen-synk/' );

        $tier = $is_logged_in ? ( get_user_meta( $user_id, 'kitchensynk_user_type', true ) ?: 'starter' ) : 'free';

        $user_data = array(
            'isLoggedIn' => $is_logged_in,
            'id'         => $user_id,
            'name'       => $is_logged_in ? $current_user->display_name : 'Guest User',
            'login'      => $is_logged_in ? $current_user->user_login : '',
            'email'      => $is_logged_in ? $current_user->user_email : '',
            'avatar'     => $is_logged_in ? get_avatar_url( $user_id, array( 'size' => 96 ) ) : '',
            'roles'      => $is_logged_in ? array_values( $current_user->roles ) : array(),
            'tier'       => $tier,
            'loginUrl'   => wp_login_url( $base_url ),
            'logoutUrl'  => wp_logout_url( $base_url ),
        );

        $saved_profile = $is_logged_in ? get_user_meta( $user_id, 'ks_user_profile', true ) : null;
        if ( $is_logged_in ) {
            if ( ! is_array( $saved_profile ) ) {
                $saved_profile = array();
            }
            $saved_profile['planTier'] = $tier;
            $saved_profile['isPro']    = ( $tier !== 'free' && $tier !== 'starter' );
        }

        return rest_ensure_response( array(
            'user'    => $user_data,
            'profile' => is_array( $saved_profile ) ? $saved_profile : null,
        ) );
    }

    public function login_user( WP_REST_Request $request ) {
        $params   = $request->get_json_params();
        $username = sanitize_text_field( $params['username'] ?? '' );
        $password = $params['password'] ?? '';

        if ( empty( $username ) || empty( $password ) ) {
            return new WP_Error( 'missing_credentials', 'Username/Email and Password are required.', array( 'status' => 400 ) );
        }

        if ( is_email( $username ) ) {
            $user_obj = get_user_by( 'email', $username );
            if ( $user_obj ) {
                $username = $user_obj->user_login;
            }
        }

        remove_filter( 'authenticate', 'wp_authenticate_application_password', 20 );
        
        // Dynamically strip any CAPTCHA/Turnstile checks from the authenticate and wp_authenticate_user filters
        global $wp_filter;
        $filters_to_clean = array( 'authenticate', 'wp_authenticate_user' );
        foreach ( $filters_to_clean as $filter_name ) {
            if ( isset( $wp_filter[ $filter_name ] ) ) {
                foreach ( $wp_filter[ $filter_name ]->callbacks as $priority => $callbacks ) {
                    foreach ( $callbacks as $id => $callback ) {
                        $is_captcha = false;
                        if ( is_array( $callback['function'] ) ) {
                            $class_name  = is_object( $callback['function'][0] ) ? get_class( $callback['function'][0] ) : ( is_string( $callback['function'][0] ) ? $callback['function'][0] : '' );
                            $method_name = is_string( $callback['function'][1] ) ? $callback['function'][1] : '';
                            
                            if ( stripos( $class_name, 'turnstile' ) !== false || stripos( $class_name, 'captcha' ) !== false || stripos( $class_name, 'wp_defender' ) !== false ) {
                                $is_captcha = true;
                            }
                            if ( stripos( $method_name, 'turnstile' ) !== false || stripos( $method_name, 'captcha' ) !== false ) {
                                $is_captcha = true;
                            }
                        } elseif ( is_string( $callback['function'] ) ) {
                            if ( stripos( $callback['function'], 'turnstile' ) !== false || stripos( $callback['function'], 'captcha' ) !== false ) {
                                $is_captcha = true;
                            }
                        }
                        
                        if ( $is_captcha ) {
                            remove_filter( $filter_name, $callback['function'], $priority );
                        }
                    }
                }
            }
        }

        $creds = array(
            'user_login'    => $username,
            'user_password' => $password,
            'remember'      => true,
        );

        $user = wp_signon( $creds, is_ssl() );

        if ( is_wp_error( $user ) ) {
            return new WP_Error( 'login_failed', wp_strip_all_tags( $user->get_error_message() ), array( 'status' => 401 ) );
        }

        wp_set_current_user( $user->ID );
        wp_set_auth_cookie( $user->ID, true );

        $nonce    = wp_create_nonce( 'wp_rest' );
        $base_url = home_url( '/kitchen-synk/' );
        $tier     = get_user_meta( $user->ID, 'kitchensynk_user_type', true ) ?: 'starter';

        $user_data = array(
            'isLoggedIn' => true,
            'id'         => $user->ID,
            'name'       => $user->display_name,
            'login'      => $user->user_login,
            'email'      => $user->user_email,
            'avatar'     => get_avatar_url( $user->ID, array( 'size' => 96 ) ),
            'roles'      => array_values( $user->roles ),
            'tier'       => $tier,
            'loginUrl'   => wp_login_url( $base_url ),
            'logoutUrl'  => wp_logout_url( $base_url ),
        );

        $saved_profile = get_user_meta( $user->ID, 'ks_user_profile', true );
        if ( ! is_array( $saved_profile ) ) {
            $saved_profile = array();
        }
        $saved_profile['planTier'] = $tier;
        $saved_profile['isPro']    = ( $tier !== 'free' && $tier !== 'starter' );

        return rest_ensure_response( array(
            'success' => true,
            'nonce'   => $nonce,
            'user'    => $user_data,
            'profile' => is_array( $saved_profile ) ? $saved_profile : null,
        ) );
    }

    public function logout_user( WP_REST_Request $request ) {
        wp_logout();
        return rest_ensure_response( array( 'success' => true ) );
    }

    public function register_user( WP_REST_Request $request ) {
        $params   = $request->get_json_params();
        $username = sanitize_user( $params['username'] ?? '' );
        $email    = sanitize_email( $params['email'] ?? '' );
        $password = $params['password'] ?? '';
        $tier     = sanitize_text_field( $params['tier'] ?? 'starter' );

        if ( empty( $username ) || empty( $email ) || empty( $password ) ) {
            return new WP_Error( 'missing_fields', 'Username, Email, and Password are required.', array( 'status' => 400 ) );
        }

        if ( username_exists( $username ) ) {
            return new WP_Error( 'username_exists', 'Username already registered.', array( 'status' => 400 ) );
        }

        if ( email_exists( $email ) ) {
            return new WP_Error( 'email_exists', 'Email address already registered.', array( 'status' => 400 ) );
        }

        $user_id = wp_create_user( $username, $password, $email );

        if ( is_wp_error( $user_id ) ) {
            return new WP_Error( 'registration_failed', $user_id->get_error_message(), array( 'status' => 400 ) );
        }

        update_user_meta( $user_id, 'kitchensynk_user_type', $tier );

        $user = get_user_by( 'id', $user_id );
        wp_set_current_user( $user->ID );
        wp_set_auth_cookie( $user->ID, true );

        $nonce    = wp_create_nonce( 'wp_rest' );
        $base_url = home_url( '/kitchen-synk/' );

        $user_data = array(
            'isLoggedIn' => true,
            'id'         => $user->ID,
            'name'       => $user->display_name,
            'login'      => $user->user_login,
            'email'      => $user->user_email,
            'avatar'     => get_avatar_url( $user->ID, array( 'size' => 96 ) ),
            'roles'      => array_values( $user->roles ),
            'tier'       => $tier,
            'loginUrl'   => wp_login_url( $base_url ),
            'logoutUrl'  => wp_logout_url( $base_url ),
        );

        return rest_ensure_response( array(
            'success' => true,
            'nonce'   => $nonce,
            'user'    => $user_data,
        ) );
    }

    public function update_profile( WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return new WP_Error( 'not_logged_in', 'Must be logged in to update profile', array( 'status' => 401 ) );
        }

        $params  = $request->get_json_params();
        $profile = $params['profile'] ?? null;

        if ( ! is_array( $profile ) ) {
            return new WP_Error( 'invalid_data', 'Invalid profile payload', array( 'status' => 400 ) );
        }

        if ( ! empty( $profile['planTier'] ) ) {
            update_user_meta( $user_id, 'kitchensynk_user_type', sanitize_text_field( $profile['planTier'] ) );
        }

        update_user_meta( $user_id, 'ks_user_profile', $profile );

        return rest_ensure_response( array(
            'success' => true,
            'profile' => $profile,
        ) );
    }
}
