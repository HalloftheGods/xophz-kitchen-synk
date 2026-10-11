<?php

if ( ! defined( 'WPINC' ) ) {
	die;
}

trait Kitchen_Synk_API_Auth_Trait {

    /**
     * Public by design: this is the first step of sign-up. It sends an email, so one address (IP)
     * and one mailbox can only ask for a few codes per hour.
     */
    public function rest_send_otp( WP_REST_Request $request ) {
        $limited = Kitchen_Synk_Rate_Limit::hit( 'otp_ip', Kitchen_Synk_Rate_Limit::client_ip(), 15, HOUR_IN_SECONDS, 'Too many verification requests from this network. Please try again later.' );
        if ( is_wp_error( $limited ) ) {
            return $limited;
        }

        $params = (array) $request->get_json_params();
        $email  = sanitize_email( is_string( $params['email'] ?? null ) ? $params['email'] : '' );

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

        $limited = Kitchen_Synk_Rate_Limit::hit( 'otp_email', $email, 5, HOUR_IN_SECONDS, 'Too many codes were requested for this email address. Please try again later.' );
        if ( is_wp_error( $limited ) ) {
            return $limited;
        }

        $otp = sprintf( '%06d', wp_rand( 100000, 999999 ) );
        $transient_key = 'ks_otp_' . md5( strtolower( $email ) );

        set_transient( $transient_key, array(
            'otp'      => $otp,
            'username' => $suggested_username,
            'email'    => $email,
            'attempts' => 0,
            'expires'  => time() + 15 * MINUTE_IN_SECONDS,
        ), 15 * MINUTE_IN_SECONDS );

        wp_mail( $email, 'Kitchen Synk Verification Code', "Your Kitchen Synk verification code is: {$otp}" );

        return rest_ensure_response( array(
            'success'            => true,
            'message'            => 'Verification code sent to your email address.',
            'suggested_username' => $suggested_username,
        ) );
    }

    /**
     * Public by design: this is the last step of sign-up, and it creates the account. A code is
     * only good for five wrong guesses, and one address (IP) can only try so many times per hour.
     */
    public function rest_register_and_checkout( WP_REST_Request $request ) {
        $limited = Kitchen_Synk_Rate_Limit::hit( 'register_ip', Kitchen_Synk_Rate_Limit::client_ip(), 20, HOUR_IN_SECONDS, 'Too many sign-up attempts from this network. Please try again later.' );
        if ( is_wp_error( $limited ) ) {
            return $limited;
        }

        $params   = (array) $request->get_json_params();
        $email    = sanitize_email( is_string( $params['email'] ?? null ) ? $params['email'] : '' );
        $otp      = sanitize_text_field( is_string( $params['otp'] ?? null ) ? $params['otp'] : '' );
        $username = sanitize_user( is_string( $params['username'] ?? null ) ? $params['username'] : '' );
        $password = is_string( $params['password'] ?? null ) ? $params['password'] : '';
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

        if ( ! $stored || empty( $stored['otp'] ) ) {
            return new WP_Error( 'invalid_otp', 'Invalid or expired verification code. Please check code or resend.', array( 'status' => 400 ) );
        }

        if ( ! hash_equals( (string) $stored['otp'], (string) $otp ) ) {
            // Five wrong guesses burn the code, so it cannot be brute forced inside its 15 minutes.
            $attempts = (int) ( $stored['attempts'] ?? 0 ) + 1;
            if ( $attempts >= 5 ) {
                delete_transient( $transient_key );
            } else {
                $stored['attempts'] = $attempts;
                $remaining          = (int) ( $stored['expires'] ?? time() + 15 * MINUTE_IN_SECONDS ) - time();
                set_transient( $transient_key, $stored, max( 60, $remaining ) );
            }
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

        $ref_code = sanitize_text_field( $params['ref_code'] ?? $params['ref'] ?? '' );
        $referred_reward_applied = false;
        if ( ! empty( $ref_code ) ) {
            $referred_reward_applied = $this->process_referral_reward( $user_id, $ref_code );
        }

        if ( $tier === 'starter' || $tier === 'free' || empty( $price_id ) || $referred_reward_applied ) {
            if ( ! $referred_reward_applied && class_exists( 'Xophz_Compass_Golden_Keys_API' ) ) {
                Xophz_Compass_Golden_Keys_API::generate_license_key( $user_id, 'starter' );
            }
            wp_set_current_user( $user_id );
            wp_set_auth_cookie( $user_id, true );

            $active_tier = get_user_meta( $user_id, 'kitchensynk_user_type', true ) ?: 'starter';

            return rest_ensure_response( array(
                'success'                 => true,
                'status'                  => 'registered',
                'user_id'                 => $user_id,
                'referred_reward_applied' => $referred_reward_applied,
                'user'                    => array(
                    'id'    => $user_id,
                    'name'  => $display_name,
                    'email' => $email,
                    'tier'  => $active_tier,
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

    public function get_or_create_user_referral_code( $user_id ) {
        if ( ! $user_id ) {
            return '';
        }
        $code = get_user_meta( $user_id, 'ks_referral_code', true );
        if ( empty( $code ) ) {
            $user_obj = get_userdata( $user_id );
            $login    = $user_obj ? strtolower( preg_replace( '/[^a-zA-Z0-9]/', '', $user_obj->user_login ) ) : 'chef';
            $code     = $login . '-' . substr( md5( $user_id . 'ks_ref_salt' ), 0, 6 );
            update_user_meta( $user_id, 'ks_referral_code', $code );
        }
        return $code;
    }

    private function get_user_by_referral_code( $ref_code ) {
        if ( empty( $ref_code ) ) {
            return 0;
        }
        $users = get_users( array(
            'meta_key'   => 'ks_referral_code',
            'meta_value' => sanitize_text_field( $ref_code ),
            'number'     => 1,
            'fields'     => 'ID',
        ) );
        if ( ! empty( $users ) ) {
            return intval( $users[0] );
        }
        return 0;
    }

    private function process_referral_reward( $new_user_id, $ref_code ) {
        if ( empty( $ref_code ) || empty( $new_user_id ) ) {
            return false;
        }

        $referrer_id = $this->get_user_by_referral_code( $ref_code );
        if ( ! $referrer_id || (int) $referrer_id === (int) $new_user_id ) {
            return false;
        }

        update_user_meta( $new_user_id, 'ks_referred_by', $referrer_id );

        update_user_meta( $new_user_id, 'kitchensynk_user_type', 'pro_chef' );
        $referee_license = array(
            'license_key' => 'GOLDEN-PRO-REF-' . strtoupper( bin2hex( random_bytes( 4 ) ) ),
            'user_id'     => $new_user_id,
            'tier'        => 'pro_chef',
            'status'      => 'active',
            'created_at'  => current_time( 'mysql' ),
            'expires_at'  => date( 'Y-m-d H:i:s', strtotime( '+30 days' ) ),
            'referred_by' => $referrer_id,
        );
        update_user_meta( $new_user_id, 'xophz_golden_license', $referee_license );

        $list = get_user_meta( $referrer_id, 'ks_referrals_list', true );
        if ( ! is_array( $list ) ) {
            $list = array();
        }
        if ( ! in_array( $new_user_id, $list, true ) ) {
            $list[] = $new_user_id;
            update_user_meta( $referrer_id, 'ks_referrals_list', $list );
        }

        $bonus_months = (int) get_user_meta( $referrer_id, 'ks_bonus_months', true );
        update_user_meta( $referrer_id, 'ks_bonus_months', $bonus_months + 1 );

        $referrer_license = get_user_meta( $referrer_id, 'xophz_golden_license', true );
        $base_expire = ( is_array( $referrer_license ) && ! empty( $referrer_license['expires_at'] ) && strtotime( $referrer_license['expires_at'] ) > time() )
            ? strtotime( $referrer_license['expires_at'] )
            : time();
        $new_expire_date = date( 'Y-m-d H:i:s', strtotime( '+30 days', $base_expire ) );

        if ( is_array( $referrer_license ) ) {
            $referrer_license['expires_at'] = $new_expire_date;
            $referrer_license['status']     = 'active';
            update_user_meta( $referrer_id, 'xophz_golden_license', $referrer_license );
        } else {
            $new_ref_license = array(
                'license_key' => 'GOLDEN-PRO-REF-' . strtoupper( bin2hex( random_bytes( 4 ) ) ),
                'user_id'     => $referrer_id,
                'tier'        => 'pro_chef',
                'status'      => 'active',
                'created_at'  => current_time( 'mysql' ),
                'expires_at'  => $new_expire_date,
            );
            update_user_meta( $referrer_id, 'xophz_golden_license', $new_ref_license );
            update_user_meta( $referrer_id, 'kitchensynk_user_type', 'pro_chef' );
        }

        return true;
    }

    public function rest_get_referral_info( WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return new WP_Error( 'unauthorized', 'User is not logged in.', array( 'status' => 401 ) );
        }

        $code = $this->get_or_create_user_referral_code( $user_id );
        $list = get_user_meta( $user_id, 'ks_referrals_list', true );
        if ( ! is_array( $list ) ) {
            $list = array();
        }
        $bonus_months = (int) get_user_meta( $user_id, 'ks_bonus_months', true );
        $referral_url = xophz_kitchen_synk_get_base_url( '?ref=' . rawurlencode( $code ) );

        return rest_ensure_response( array(
            'success'        => true,
            'referral_code'  => $code,
            'referral_link'  => $referral_url,
            'referral_count' => count( $list ),
            'bonus_months'   => $bonus_months,
        ) );
    }

    /**
     * Changes the display name of the logged-in caller and nobody else. The route needs a logged-in
     * user; a user_id in the body is only accepted when it is the caller's own.
     */
    public function rest_update_username( WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return new WP_Error( 'rest_forbidden', 'Log in to change your username.', array( 'status' => 401 ) );
        }

        $params = (array) $request->get_json_params();
        if ( ! empty( $params['user_id'] ) && (int) $params['user_id'] !== $user_id ) {
            return new WP_Error( 'forbidden_target', 'You can only change your own username.', array( 'status' => 403 ) );
        }

        $raw_name = mb_substr( sanitize_text_field( is_string( $params['username'] ?? null ) ? $params['username'] : '' ), 0, 60 );
        if ( '' === $raw_name ) {
            return new WP_Error( 'missing_username', 'Username cannot be empty.', array( 'status' => 400 ) );
        }

        $limited = Kitchen_Synk_Rate_Limit::hit( 'username_user', (string) $user_id, 10, HOUR_IN_SECONDS, 'You changed your username too many times. Please try again later.' );
        if ( is_wp_error( $limited ) ) {
            return $limited;
        }

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
