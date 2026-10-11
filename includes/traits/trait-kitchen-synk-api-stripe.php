<?php

if ( ! defined( 'WPINC' ) ) {
	die;
}

trait Kitchen_Synk_API_Stripe_Trait {

    /**
     * Non-secret Stripe settings plus configured flags. The secret key and the webhook signing
     * secret live in Settings > Connectors and are never returned by this route.
     */
    public function rest_get_stripe_keys( WP_REST_Request $request ) {
        $config = get_option( 'xophz_stripe_keys', array() );
        $config = is_array( $config ) ? $config : array();
        $secret = Kitchen_Synk_Connectors::stripe_secret_key();

        return rest_ensure_response( array(
            'publishable_key'           => (string) ( $config['publishable_key'] ?? '' ),
            'mode'                      => (string) ( $config['mode'] ?? 'test' ),
            'secret_key_configured'     => '' !== $secret,
            'secret_key_masked'         => '' !== $secret ? '••••' . substr( $secret, -4 ) : '',
            'webhook_secret_configured' => '' !== Kitchen_Synk_Connectors::stripe_webhook_secret(),
        ) );
    }

    /** Saves the non-secret Stripe settings only. Secrets are written through Settings > Connectors. */
    public function rest_save_stripe_keys( WP_REST_Request $request ) {
        $params   = $request->get_json_params();
        $existing = get_option( 'xophz_stripe_keys', array() );
        $existing = is_array( $existing ) ? $existing : array();

        $updated = array(
            'publishable_key' => sanitize_text_field( $params['publishable_key'] ?? $existing['publishable_key'] ?? '' ),
            'mode'            => sanitize_text_field( $params['mode'] ?? 'test' ),
        );

        update_option( 'xophz_stripe_keys', $updated );
        return rest_ensure_response( array( 'success' => true, 'message' => 'Stripe settings updated. Set the secret key and webhook secret under Settings > Connectors.' ) );
    }

    /**
     * Public on purpose: a visitor who is not signed in can still start a checkout, and the
     * account is made after payment. Each call opens a Stripe session with the owner's key, so one
     * address (IP) gets a fixed number per hour, and the plan is checked against the price in
     * create_stripe_checkout_session().
     */
    public function rest_create_checkout_session( WP_REST_Request $request ) {
        $limited = Kitchen_Synk_Rate_Limit::hit( 'checkout_ip', Kitchen_Synk_Rate_Limit::client_ip(), 20, HOUR_IN_SECONDS, 'Too many checkout attempts from this network. Please try again later.' );
        if ( is_wp_error( $limited ) ) {
            return $limited;
        }

        $user_id  = get_current_user_id();
        $user     = $user_id ? wp_get_current_user() : null;
        $params   = (array) $request->get_json_params();
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
        $secret_key = Kitchen_Synk_Connectors::stripe_secret_key();

        if ( empty( $secret_key ) ) {
            return new WP_Error( 'stripe_missing_keys', 'Stripe secret key is not configured in WP Connector.', array( 'status' => 500 ) );
        }

        // The plan is not taken on the caller's word: the price must exist and be active, and a
        // user_type set on the Stripe price decides the plan.
        $tier = $this->resolve_checkout_tier( $secret_key, $price_id, $tier );
        if ( is_wp_error( $tier ) ) {
            return $tier;
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

    /**
     * Decides which plan a checkout session is for. The caller's tier is only a request: the price
     * has to be a real, active Stripe price, and if the price carries a user_type in its Stripe
     * metadata that wins, so a cheap price cannot be bought under an expensive plan name. When
     * Stripe cannot be reached the requested tier is used, as long as it is a plan this app sells.
     *
     * @return string|WP_Error The plan, or a 400 error.
     */
    private function resolve_checkout_tier( $secret_key, $price_id, $requested_tier ) {
        $plans = array( 'individual', 'pro', 'pro_chef', 'family', 'lifetime', 'life', 'enterprise_pantry', 'commercial' );
        $tier  = in_array( $requested_tier, $plans, true ) ? $requested_tier : '';

        if ( ! preg_match( '/^price_[A-Za-z0-9]{8,100}$/', (string) $price_id ) ) {
            return new WP_Error( 'invalid_price_id', 'That is not a valid Stripe price.', array( 'status' => 400 ) );
        }

        $response = wp_remote_get( 'https://api.stripe.com/v1/prices/' . $price_id, array(
            'headers' => array( 'Authorization' => 'Basic ' . base64_encode( $secret_key . ':' ) ),
            'timeout' => 10,
        ) );

        if ( ! is_wp_error( $response ) ) {
            $code  = wp_remote_retrieve_response_code( $response );
            $price = json_decode( wp_remote_retrieve_body( $response ), true );

            if ( 404 === $code || ( 200 === $code && is_array( $price ) && isset( $price['active'] ) && false === $price['active'] ) ) {
                return new WP_Error( 'invalid_price_id', 'That plan is not available.', array( 'status' => 400 ) );
            }

            if ( 200 === $code && is_array( $price ) && ! empty( $price['metadata']['user_type'] ) ) {
                $tagged = sanitize_text_field( $price['metadata']['user_type'] );
                if ( in_array( $tagged, $plans, true ) ) {
                    $tier = $tagged;
                }
            }
        }

        if ( '' === $tier ) {
            return new WP_Error( 'invalid_tier', 'Unknown plan.', array( 'status' => 400 ) );
        }

        return $tier;
    }

    /**
     * Public on purpose: it is where the browser lands after Stripe. It trusts Stripe, not the
     * caller. The session has to be complete and paid and less than a day old, a session id from an
     * unpaid or abandoned checkout does nothing, and a payment never signs anyone in to an account
     * that already existed (the buyer types the email address into Stripe, so a match proves
     * nothing). Each call asks Stripe about a session, so one address (IP) gets a fixed number.
     */
    public function rest_verify_checkout_session( WP_REST_Request $request ) {
        $limited = Kitchen_Synk_Rate_Limit::hit( 'verify_ip', Kitchen_Synk_Rate_Limit::client_ip(), 30, HOUR_IN_SECONDS, 'Too many checkout checks from this network. Please try again later.' );
        if ( is_wp_error( $limited ) ) {
            return $limited;
        }

        $params     = (array) $request->get_json_params();
        $session_id = sanitize_text_field( $params['session_id'] ?? '' );

        if ( empty( $session_id ) ) {
            return new WP_Error( 'missing_session_id', 'Stripe Session ID is required.', array( 'status' => 400 ) );
        }

        if ( ! preg_match( '/^cs_(test|live)_[A-Za-z0-9]{8,200}$/', $session_id ) ) {
            return new WP_Error( 'invalid_session', 'Could not verify Stripe checkout session.', array( 'status' => 400 ) );
        }

        $secret_key = Kitchen_Synk_Connectors::stripe_secret_key();

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

        $is_paid = 'complete' === ( $session['status'] ?? '' )
            && in_array( $session['payment_status'] ?? '', array( 'paid', 'no_payment_required' ), true );
        if ( ! $is_paid ) {
            return new WP_Error( 'payment_incomplete', 'This checkout has not been paid.', array( 'status' => 402 ) );
        }

        if ( (int) ( $session['created'] ?? 0 ) < time() - DAY_IN_SECONDS ) {
            return new WP_Error( 'session_expired', 'This checkout link has expired. Please log in.', array( 'status' => 400 ) );
        }

        $raw_uid   = $session['client_reference_id'] ?? $session['metadata']['wp_user_id'] ?? null;
        $user_id   = ( is_numeric( $raw_uid ) && intval( $raw_uid ) > 0 ) ? intval( $raw_uid ) : null;
        $user_type = sanitize_text_field( $session['metadata']['user_type'] ?? 'individual' );
        $email     = $session['customer_details']['email'] ?? $session['customer_email'] ?? '';

        if ( ! $user_id && ! empty( $email ) ) {
            $user = get_user_by( 'email', $email );
            if ( $user ) {
                // The buyer typed this address into Stripe, so it proves nothing about who owns the
                // account. No plan change and no login: the account owner signs in as usual.
                return rest_ensure_response( array(
                    'success' => false,
                    'message' => 'An account with this email address already exists. Please log in to use your plan.',
                ) );
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

        $webhook_secret = Kitchen_Synk_Connectors::stripe_webhook_secret();
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
