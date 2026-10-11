<?php

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * The daily AI quota, kept per user in user meta (ks_ai_quota_date, ks_ai_quota_used).
 *
 * Nothing in here falls back to another account. With no logged-in user a quota reads as a limit
 * of 0, and counting a call is refused with 401.
 */
trait Xophz_Kitchen_Synk_API_Quota {

    /**
     * One user's daily AI quota. It only reads; the counter rolls over to a new day in
     * check_and_increment_ai_quota(), so looking at a quota never changes it.
     */
    public function get_user_quota_info( $user_id = null ) {
        $user_id = absint( empty( $user_id ) ? get_current_user_id() : $user_id );

        $tier = $user_id ? ( get_user_meta( $user_id, 'kitchensynk_user_type', true ) ?: 'starter' ) : 'starter';

        $limits = array(
            'starter'           => 3,
            'free'              => 3,
            'individual'        => 20,
            'pro'               => 20,
            'pro_chef'          => 20,
            'family'            => 50,
            'lifetime'          => 25,
            'enterprise_pantry' => 50,
            'commercial'        => 50,
        );

        $limit = $user_id ? ( isset( $limits[ $tier ] ) ? $limits[ $tier ] : 20 ) : 0;

        $today = date( 'Y-m-d' );
        $used  = ( $user_id && get_user_meta( $user_id, 'ks_ai_quota_date', true ) === $today )
            ? (int) get_user_meta( $user_id, 'ks_ai_quota_used', true )
            : 0;

        return array(
            'tier'      => $tier,
            'limit'     => $limit,
            'used'      => $used,
            'remaining' => max( 0, $limit - $used ),
            'reset'     => $today . ' 23:59:59',
        );
    }

    public function check_and_increment_ai_quota( $user_id = null ) {
        $user_id = absint( empty( $user_id ) ? get_current_user_id() : $user_id );
        if ( ! $user_id ) {
            return new WP_Error( 'rest_forbidden', 'Log in to use Kitchen Synk AI.', array( 'status' => 401 ) );
        }

        $info = $this->get_user_quota_info( $user_id );

        if ( $info['used'] >= $info['limit'] ) {
            return new WP_Error(
                'ks_ai_quota_exceeded',
                "Daily AI quota limit ({$info['limit']} requests/day) reached for tier '{$info['tier']}'. Please upgrade your subscription or wait for daily reset.",
                array(
                    'status' => 429,
                    'data'   => array(
                        'tier'      => $info['tier'],
                        'limit'     => $info['limit'],
                        'used'      => $info['used'],
                        'remaining' => 0,
                        'reset'     => $info['reset'],
                    )
                )
            );
        }

        $new_used = $info['used'] + 1;
        update_user_meta( $user_id, 'ks_ai_quota_date', date( 'Y-m-d' ) );
        update_user_meta( $user_id, 'ks_ai_quota_used', $new_used );

        $info['used'] = $new_used;
        $info['remaining'] = max( 0, $info['limit'] - $new_used );

        return $info;
    }

    /**
     * GET /quota: the caller's own quota. The route needs a logged-in user. Only manage_options
     * may name another user, with an explicit user_id; anyone else asking for a different user
     * gets 403. Nothing here writes, so reading a quota can never reset it.
     */
    public function get_ai_quota( WP_REST_Request $request ) {
        $user_id   = get_current_user_id();
        $requested = absint( $request->get_param( 'user_id' ) );

        if ( $requested && $requested !== $user_id ) {
            if ( ! current_user_can( 'manage_options' ) ) {
                return new WP_Error( 'forbidden_target', 'You can only read your own quota.', array( 'status' => 403 ) );
            }
            if ( ! get_userdata( $requested ) ) {
                return new WP_Error( 'user_not_found', 'User not found.', array( 'status' => 404 ) );
            }
            $user_id = $requested;
        }

        return rest_ensure_response( array( 'success' => true, 'quota' => $this->get_user_quota_info( $user_id ) ) );
    }
}
