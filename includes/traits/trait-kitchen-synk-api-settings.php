<?php

if ( ! defined( 'WPINC' ) ) {
	die;
}

trait Kitchen_Synk_API_Settings_Trait {

    public function rest_get_google_tag( WP_REST_Request $request ) {
        $tag_id = get_option( 'compass_google_tag_id', '' );
        if ( empty( $tag_id ) && function_exists( 'wp_get_connectors' ) ) {
            $connectors = wp_get_connectors();
            if ( ! empty( $connectors['google_tag_id']['authentication']['setting_name'] ) ) {
                $setting_name = $connectors['google_tag_id']['authentication']['setting_name'];
                $tag_id = get_option( $setting_name, '' );
            }
        }
        if ( empty( $tag_id ) ) {
            $tag_id = get_option( 'xophz_google_tag_id', '' );
        }
        return rest_ensure_response( array(
            'success' => true,
            'tag_id'  => $tag_id,
        ) );
    }

    public function rest_save_google_tag( WP_REST_Request $request ) {
        $params = $request->get_json_params();
        $tag_id = sanitize_text_field( $params['tag_id'] ?? '' );
        update_option( 'compass_google_tag_id', $tag_id );
        return rest_ensure_response( array(
            'success' => true,
            'message' => 'Google Tag ID updated successfully.',
            'tag_id'  => $tag_id,
        ) );
    }
}
