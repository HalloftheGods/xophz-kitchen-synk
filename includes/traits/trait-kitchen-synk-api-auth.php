<?php

if ( ! defined( 'WPINC' ) ) {
	die;
}

trait Kitchen_Synk_API_Auth_Trait {

    public function rest_send_otp( WP_REST_Request $request ) {
        $params = $request->get_json_params();
        $email  = sanitize_email( $params['email'] ?? '' );

        if ( empty( $email ) || ! is_email( $email ) ) {
            return new WP_Error( 'invalid_email', 'Valid email address is required.', array( 'status' => 400 ) );
        }

        if ( email_exists( $email ) ) {
            return new WP_Error( 'email_exists', 'An account with this email address already exists. Please log in.', array( 'status' => 400 ) );
        }

        $base_username = sanitize_user( current( explode( '@', $email ) ) );
        if ( empty( $base_username ) ) {
            $base_username = 'chef_' . wp_rand( 1000, 9999 );
        }

        $suggested_username = $base_username;
        if ( username_exists( $suggested_username ) ) {
            $suggested_username .= '_' . wp_rand( 10, 999 );
        }

        $otp = sprintf( '%06d', wp_rand( 100000, 999999 ) );
        $transient_key = 'ks_otp_' . md5( strtolower( $email ) );

        set_transient( $transient_key, array(
            'otp'      => $otp,
            'username' => $suggested_username,
            'email'    => $email,
        ), 15 * MINUTE_IN_SECONDS );

        wp_mail( $email, 'Kitchen Synk Verification Code', "Your Kitchen Synk verification code is: {$otp}" );

        return rest_ensure_response( array(
            'success'            => true,
            'message'            => 'Verification code sent to your email address.',
            'suggested_username' => $suggested_username,
            'debug_otp'          => ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? $otp : null,
        ) );
    }

    public function rest_register_and_checkout( WP_REST_Request $request ) {
        $params   = $request->get_json_params();
        $email    = sanitize_email( $params['email'] ?? '' );
        $otp      = sanitize_text_field( $params['otp'] ?? '' );
        $username = sanitize_user( $params['username'] ?? '' );
        $password = $params['password'] ?? '';
        $name     = sanitize_text_field( $params['name'] ?? '' );
        $tier     = sanitize_text_field( $params['tier'] ?? 'starter' );
        $price_id = sanitize_text_field( $params['price_id'] ?? '' );

        if ( empty( $email ) || ! is_email( $email ) ) {
            return new WP_Error( 'invalid_email', 'Valid email address is required.', array( 'status' => 400 ) );
        }

        if ( empty( $otp ) ) {
            return new WP_Error( 'missing_otp', 'Verification code (OTP) is required.', array( 'status' => 400 ) );
        }

        $transient_key = 'ks_otp_' . md5( strtolower( $email ) );
        $stored = get_transient( $transient_key );

        if ( ! $stored || empty( $stored['otp'] ) || (string) $stored['otp'] !== (string) $otp ) {
            return new WP_Error( 'invalid_otp', 'Invalid or expired verification code. Please check code or resend.', array( 'status' => 400 ) );
        }

        delete_transient( $transient_key );

        if ( email_exists( $email ) ) {
            return new WP_Error( 'email_exists', 'An account with this email address already exists.', array( 'status' => 400 ) );
        }

        if ( empty( $username ) ) {
            $username = $stored['username'] ?? sanitize_user( current( explode( '@', $email ) ) );
        }
        if ( username_exists( $username ) ) {
            $username .= '_' . wp_rand( 100, 999 );
        }

        if ( empty( $password ) ) {
            $password = wp_generate_password( 16, true );
        }

        $user_id = wp_create_user( $username, $password, $email );
        if ( is_wp_error( $user_id ) ) {
            return $user_id;
        }

        $display_name = ! empty( $name ) ? $name : $username;
        wp_update_user( array(
            'ID'           => $user_id,
            'display_name' => $display_name,
            'first_name'   => $display_name,
        ) );

        if ( $tier === 'starter' || $tier === 'free' || empty( $price_id ) ) {
            if ( class_exists( 'Xophz_Compass_Golden_Keys_API' ) ) {
                Xophz_Compass_Golden_Keys_API::generate_license_key( $user_id, 'starter' );
            }
            wp_set_current_user( $user_id );
            wp_set_auth_cookie( $user_id, true );

            return rest_ensure_response( array(
                'success'      => true,
                'status'       => 'registered',
                'user_id'      => $user_id,
                'user'         => array(
                    'id'    => $user_id,
                    'name'  => $display_name,
                    'email' => $email,
                ),
                'redirect_url' => home_url( '/kitchen-synk/#/inventory?welcome=1' ),
            ) );
        }

        $session = $this->create_stripe_checkout_session( $user_id, $email, $tier, $price_id );
        if ( is_wp_error( $session ) ) {
            return $session;
        }

        return rest_ensure_response( array(
            'success'      => true,
            'status'       => 'checkout_required',
            'user_id'      => $user_id,
            'checkout_url' => $session['url'],
            'session_id'   => $session['id'],
        ) );
    }

    public function rest_update_username( WP_REST_Request $request ) {
        $params   = $request->get_json_params();
        $user_id  = get_current_user_id();
        $raw_name = sanitize_text_field( $params['username'] ?? '' );

        if ( ! $user_id && ! empty( $params['user_id'] ) ) {
            $user_id = intval( $params['user_id'] );
        }

        if ( empty( $raw_name ) ) {
            return new WP_Error( 'missing_username', 'Username cannot be empty.', array( 'status' => 400 ) );
        }

        if ( $user_id ) {
            wp_update_user( array(
                'ID'           => $user_id,
                'display_name' => $raw_name,
            ) );
            update_user_meta( $user_id, 'kitchensynk_username_set', 'true' );
            update_user_meta( $user_id, 'kitchensynk_custom_username', $raw_name );

            $user_obj = get_userdata( $user_id );
            return rest_ensure_response( array(
                'success' => true,
                'nonce'   => wp_create_nonce( 'wp_rest' ),
                'user'    => array(
                    'id'                       => $user_id,
                    'name'                     => $user_obj ? $user_obj->display_name : $raw_name,
                    'email'                    => $user_obj ? $user_obj->user_email : '',
                    'needs_username_selection' => false,
                ),
            ) );
        }

        return rest_ensure_response( array(
            'success' => true,
            'name'    => $raw_name,
        ) );
    }

    public function rest_get_me( WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return rest_ensure_response( array(
                'success' => false,
                'message' => 'Not authenticated.',
                'user'    => array(
                    'isLoggedIn' => false,
                    'tier'       => 'starter',
                ),
            ) );
        }

        $user_obj = get_userdata( $user_id );
        $tier     = get_user_meta( $user_id, 'kitchensynk_user_type', true ) ?: 'starter';
        $profile  = get_user_meta( $user_id, 'ks_user_profile', true );
        if ( ! is_array( $profile ) ) {
            $profile = array();
        }
        $profile['planTier'] = $tier;
        $profile['isPro']    = ( $tier !== 'free' && $tier !== 'starter' );

        return rest_ensure_response( array(
            'success' => true,
            'user'    => array(
                'isLoggedIn' => true,
                'id'         => $user_id,
                'name'       => $user_obj ? $user_obj->display_name : 'User',
                'login'      => $user_obj ? $user_obj->user_login : '',
                'email'      => $user_obj ? $user_obj->user_email : '',
                'tier'       => $tier,
                'isPro'      => $profile['isPro'],
                'roles'      => $user_obj ? $user_obj->roles : array( 'subscriber' ),
            ),
            'profile' => $profile,
        ) );
    }

    public function rest_update_profile( WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return new WP_Error( 'unauthorized', 'User is not logged in.', array( 'status' => 401 ) );
        }
        $params  = $request->get_json_params();
        $profile = $params['profile'] ?? array();
        if ( is_array( $profile ) ) {
            update_user_meta( $user_id, 'ks_user_profile', $profile );
        }
        return rest_ensure_response( array( 'success' => true ) );
    }

    public function rest_get_license( WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        $license = get_user_meta( $user_id, 'xophz_golden_license', true );
        $tier    = get_user_meta( $user_id, 'kitchensynk_user_type', true ) ?: 'starter';

        return rest_ensure_response( array(
            'has_license' => ! empty( $license ),
            'tier'        => $tier,
            'license'     => $license ?: null,
        ) );
    }
}
