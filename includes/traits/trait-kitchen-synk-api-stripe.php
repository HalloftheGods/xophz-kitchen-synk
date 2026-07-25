<?php

if ( ! defined( 'WPINC' ) ) {
	die;
}

trait Kitchen_Synk_API_Stripe_Trait {

    public function rest_get_stripe_keys( WP_REST_Request $request ) {
        $keys = get_option( 'xophz_stripe_keys', array(
            'publishable_key' => '',
            'secret_key'      => '',
            'webhook_secret'  => '',
            'mode'            => 'test',
        ) );
        $keys['secret_key_masked'] = ! empty( $keys['secret_key'] ) ? '••••' . substr( $keys['secret_key'], -4 ) : '';
        return rest_ensure_response( $keys );
    }

    public function rest_save_stripe_keys( WP_REST_Request $request ) {
        $params   = $request->get_json_params();
        $existing = get_option( 'xophz_stripe_keys', array() );

        $updated = array(
            'publishable_key' => sanitize_text_field( $params['publishable_key'] ?? $existing['publishable_key'] ?? '' ),
            'secret_key'      => ! empty( $params['secret_key'] ) ? sanitize_text_field( $params['secret_key'] ) : ( $existing['secret_key'] ?? '' ),
            'webhook_secret'  => ! empty( $params['webhook_secret'] ) ? sanitize_text_field( $params['webhook_secret'] ) : ( $existing['webhook_secret'] ?? '' ),
            'mode'            => sanitize_text_field( $params['mode'] ?? 'test' ),
        );

        update_option( 'xophz_stripe_keys', $updated );
        return rest_ensure_response( array( 'success' => true, 'message' => 'Stripe connector keys updated successfully.' ) );
    }

    public function rest_create_checkout_session( WP_REST_Request $request ) {
        $user_id  = get_current_user_id();
        $user     = $user_id ? wp_get_current_user() : null;
        $params   = $request->get_json_params();
        $tier     = sanitize_text_field( $params['tier'] ?? 'individual' );
        $price_id = sanitize_text_field( $params['price_id'] ?? '' );

        if ( empty( $price_id ) ) {
            return new WP_Error( 'missing_price_id', 'Stripe Price ID is required for checkout.', array( 'status' => 400 ) );
        }

        $email   = $user ? $user->user_email : '';
        $session = $this->create_stripe_checkout_session( $user_id, $email, $tier, $price_id );
        if ( is_wp_error( $session ) ) {
            return $session;
        }

        return rest_ensure_response( array(
            'success'      => true,
            'checkout_url' => $session['url'],
            'session_id'   => $session['id'],
        ) );
    }

    private function create_stripe_checkout_session( $user_id, $email, $tier, $price_id ) {
        $secret_key = get_option( 'compass_stripe_secret_key', '' );

        if ( empty( $secret_key ) ) {
            return new WP_Error( 'stripe_missing_keys', 'Stripe secret key is not configured in WP Connector.', array( 'status' => 500 ) );
        }

        $is_one_time = in_array( $tier, array( 'enterprise_pantry', 'lifetime', 'life', 'commercial' ), true );
        $mode = $is_one_time ? 'payment' : 'subscription';

        $success_url = home_url( '/kitchen-synk/#/inventory?session_id={CHECKOUT_SESSION_ID}&status=success' );
        $cancel_url  = home_url( '/kitchen-synk/#/pricing?status=cancelled' );

        $body_params = array(
            'payment_method_types[0]' => 'card',
            'managed_payments[enabled]' => 'false',
            'line_items[0][price]'    => $price_id,
            'line_items[0][quantity]' => '1',
            'mode'                    => $mode,
            'success_url'             => $success_url,
            'cancel_url'              => $cancel_url,
            'metadata[user_type]'     => $tier,
            'allow_promotion_codes'   => 'true',
        );

        if ( ! empty( $user_id ) && (int) $user_id > 0 ) {
            $body_params['client_reference_id']  = (string) $user_id;
            $body_params['metadata[wp_user_id]'] = (string) $user_id;
            
            if ( $mode === 'subscription' ) {
                $body_params['subscription_data[metadata][wp_user_id]'] = (string) $user_id;
                $body_params['subscription_data[metadata][user_type]']  = $tier;
            } elseif ( $mode === 'payment' ) {
                $body_params['payment_intent_data[metadata][wp_user_id]'] = (string) $user_id;
                $body_params['payment_intent_data[metadata][user_type]']  = $tier;
            }
        }

        if ( ! empty( $email ) ) {
            $body_params['customer_email'] = $email;
        }

        $response = wp_remote_post( 'https://api.stripe.com/v1/checkout/sessions', array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode( $secret_key . ':' ),
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ),
            'body'    => $body_params,
            'timeout' => 30,
        ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'stripe_api_error', $response->get_error_message(), array( 'status' => 500 ) );
        }

        $res_code = wp_remote_retrieve_response_code( $response );
        $res_body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $res_code !== 200 || empty( $res_body['url'] ) ) {
            $msg = isset( $res_body['error']['message'] ) ? $res_body['error']['message'] : 'Stripe checkout session creation failed.';
            return new WP_Error( 'stripe_checkout_failed', $msg, array( 'status' => $res_code ) );
        }

        return $res_body;
    }

    public function rest_verify_checkout_session( WP_REST_Request $request ) {
        $params     = $request->get_json_params();
        $session_id = sanitize_text_field( $params['session_id'] ?? '' );

        if ( empty( $session_id ) ) {
            return new WP_Error( 'missing_session_id', 'Stripe Session ID is required.', array( 'status' => 400 ) );
        }

        $secret_key = get_option( 'compass_stripe_secret_key', '' );

        if ( empty( $secret_key ) ) {
            return new WP_Error( 'stripe_missing_keys', 'Stripe secret key is not configured.', array( 'status' => 500 ) );
        }

        $response = wp_remote_get( 'https://api.stripe.com/v1/checkout/sessions/' . $session_id, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode( $secret_key . ':' ),
            ),
            'timeout' => 15,
        ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'stripe_api_error', $response->get_error_message(), array( 'status' => 500 ) );
        }

        $res_code = wp_remote_retrieve_response_code( $response );
        $session  = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $res_code !== 200 || empty( $session['id'] ) ) {
            return new WP_Error( 'invalid_session', 'Could not verify Stripe checkout session.', array( 'status' => 400 ) );
        }

        $raw_uid   = $session['client_reference_id'] ?? $session['metadata']['wp_user_id'] ?? null;
        $user_id   = ( is_numeric( $raw_uid ) && intval( $raw_uid ) > 0 ) ? intval( $raw_uid ) : null;
        $user_type = $session['metadata']['user_type'] ?? 'individual';
        $email     = $session['customer_details']['email'] ?? $session['customer_email'] ?? '';

        if ( ! $user_id && ! empty( $email ) ) {
            $user = get_user_by( 'email', $email );
            if ( $user ) {
                $user_id = $user->ID;
            } else {
                $username = sanitize_user( current( explode( '@', $email ) ) );
                if ( empty( $username ) || username_exists( $username ) ) {
                    $username .= '_' . wp_rand( 100, 999 );
                }
                $user_id = wp_create_user( $username, wp_generate_password( 16, true ), $email );
                if ( is_wp_error( $user_id ) ) {
                    return $user_id;
                }
            }
        }

        if ( $user_id ) {
            update_user_meta( $user_id, 'kitchensynk_user_type', $user_type );
            update_user_meta( $user_id, 'kitchensynk_subscription_status', 'active' );
            if ( ! empty( $session['customer'] ) ) {
                update_user_meta( $user_id, 'kitchensynk_stripe_customer_id', $session['customer'] );
            }
            $existing_profile = get_user_meta( $user_id, 'ks_user_profile', true );
            if ( ! is_array( $existing_profile ) ) {
                $existing_profile = array();
            }
            $existing_profile['planTier'] = $user_type;
            $existing_profile['isPro']    = ( $user_type !== 'free' && $user_type !== 'starter' );
            update_user_meta( $user_id, 'ks_user_profile', $existing_profile );

            if ( class_exists( 'Xophz_Compass_Golden_Keys_API' ) ) {
                Xophz_Compass_Golden_Keys_API::generate_license_key( $user_id, $user_type );
            }

            wp_set_current_user( $user_id );
            wp_set_auth_cookie( $user_id, true );

            $username_set = get_user_meta( $user_id, 'kitchensynk_username_set', true );
            $user_obj = get_userdata( $user_id );

            return rest_ensure_response( array(
                'success' => true,
                'nonce'   => wp_create_nonce( 'wp_rest' ),
                'user'    => array(
                    'isLoggedIn'               => true,
                    'id'                       => $user_id,
                    'name'                     => $user_obj ? $user_obj->display_name : 'User',
                    'email'                    => $user_obj ? $user_obj->user_email : $email,
                    'tier'                     => $user_type,
                    'roles'                    => $user_obj ? $user_obj->roles : array( 'subscriber' ),
                    'needs_username_selection' => empty( $username_set ),
                ),
            ) );
        }

        return rest_ensure_response( array( 'success' => false, 'message' => 'User account could not be resolved from checkout session.' ) );
    }

    public function rest_stripe_webhook( WP_REST_Request $request ) {
        $body = $request->get_body();
        $event = json_decode( $body, true );

        if ( ! is_array( $event ) || empty( $event['type'] ) ) {
            return new WP_Error( 'invalid_payload', 'Invalid webhook JSON payload.', array( 'status' => 400 ) );
        }

        $keys = get_option( 'xophz_stripe_keys', array() );
        $webhook_secret = $keys['webhook_secret'] ?? '';
        $signature_header = $request->get_header( 'stripe_signature' );

        if ( ! empty( $webhook_secret ) ) {
            if ( empty( $signature_header ) ) {
                return new WP_Error( 'missing_signature', 'Missing Stripe signature header.', array( 'status' => 400 ) );
            }
            $sig_parts = explode( ',', $signature_header );
            $timestamp = '';
            $signatures = array();
            foreach ( $sig_parts as $part ) {
                $split = explode( '=', trim( $part ), 2 );
                if ( count( $split ) === 2 ) {
                    if ( $split[0] === 't' ) {
                        $timestamp = $split[1];
                    } elseif ( $split[0] === 'v1' ) {
                        $signatures[] = $split[1];
                    }
                }
            }
            if ( empty( $timestamp ) || empty( $signatures ) ) {
                return new WP_Error( 'invalid_signature', 'Invalid Stripe signature format.', array( 'status' => 400 ) );
            }
            $signed_payload = $timestamp . '.' . $body;
            $expected_sig = hash_hmac( 'sha256', $signed_payload, $webhook_secret );
            $match = false;
            foreach ( $signatures as $sig ) {
                if ( hash_equals( $expected_sig, $sig ) ) {
                    $match = true;
                    break;
                }
            }
            if ( ! $match ) {
                return new WP_Error( 'signature_mismatch', 'Stripe signature mismatch.', array( 'status' => 400 ) );
            }
        }

        $type = $event['type'];
        $data = $event['data']['object'] ?? array();

        if ( $type === 'checkout.session.completed' || $type === 'invoice.paid' ) {
            $raw_uid   = $data['client_reference_id'] ?? $data['metadata']['wp_user_id'] ?? null;
            $user_id   = ( is_numeric( $raw_uid ) && intval( $raw_uid ) > 0 ) ? intval( $raw_uid ) : null;
            $user_type = $data['metadata']['user_type'] ?? 'individual';
            $email     = $data['customer_details']['email'] ?? $data['customer_email'] ?? '';

            if ( ! $user_id && ! empty( $email ) ) {
                $existing_user = get_user_by( 'email', $email );
                if ( $existing_user ) {
                    $user_id = $existing_user->ID;
                } else {
                    $username = sanitize_user( current( explode( '@', $email ) ) );
                    if ( empty( $username ) || username_exists( $username ) ) {
                        $username .= '_' . wp_rand( 100, 999 );
                    }
                    $user_id = wp_create_user( $username, wp_generate_password( 16, true ), $email );
                    if ( is_wp_error( $user_id ) ) {
                        $user_id = null;
                    }
                }
            }

            if ( $user_id ) {
                update_user_meta( $user_id, 'kitchensynk_user_type', $user_type );
                update_user_meta( $user_id, 'kitchensynk_subscription_status', 'active' );
                update_user_meta( $user_id, 'kitchensynk_stripe_customer_id', $data['customer'] ?? '' );
                if ( ! empty( $data['subscription'] ) ) {
                    update_user_meta( $user_id, 'kitchensynk_stripe_subscription_id', $data['subscription'] );
                }
                $existing_profile = get_user_meta( $user_id, 'ks_user_profile', true );
                if ( ! is_array( $existing_profile ) ) {
                    $existing_profile = array();
                }
                $existing_profile['planTier'] = $user_type;
                $existing_profile['isPro']    = ( $user_type !== 'free' && $user_type !== 'starter' );
                update_user_meta( $user_id, 'ks_user_profile', $existing_profile );

                if ( class_exists( 'Xophz_Compass_Golden_Keys_API' ) ) {
                    Xophz_Compass_Golden_Keys_API::generate_license_key( $user_id, $user_type );
                }
            }
        } elseif ( $type === 'customer.subscription.deleted' ) {
            $raw_uid = $data['metadata']['wp_user_id'] ?? null;
            $user_id = ( is_numeric( $raw_uid ) && intval( $raw_uid ) > 0 ) ? intval( $raw_uid ) : null;
            if ( $user_id ) {
                update_user_meta( $user_id, 'kitchensynk_user_type', 'starter' );
                update_user_meta( $user_id, 'kitchensynk_subscription_status', 'canceled' );
                delete_user_meta( $user_id, 'kitchensynk_stripe_subscription_id' );
            }
        }

        return rest_ensure_response( array( 'received' => true ) );
    }
}
