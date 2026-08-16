<?php

if ( ! defined( 'WPINC' ) ) {
	die;
}

trait Xophz_Kitchen_Synk_API_Recipes {

    public function save_recipe( WP_REST_Request $request ) {
        $params = $request->get_json_params();
        $recipe = $params['recipe'] ?? null;

        if ( ! $recipe || empty( $recipe['title'] ) ) {
            return new WP_Error( 'invalid_data', 'Missing recipe data', array( 'status' => 400 ) );
        }

        $user_id = get_current_user_id() ?: 1;

        $post_id = wp_insert_post( array(
            'post_title'   => sanitize_text_field( $recipe['title'] ),
            'post_content' => wp_kses_post( $recipe['description'] ),
            'post_status'  => 'publish',
            'post_author'  => $user_id,
            'post_type'    => 'ks_saved_recipe',
        ) );

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        update_post_meta( $post_id, 'prepTime', sanitize_text_field( $recipe['prepTime'] ) );
        update_post_meta( $post_id, 'cookTime', sanitize_text_field( $recipe['cookTime'] ) );
        update_post_meta( $post_id, 'difficulty', sanitize_text_field( $recipe['difficulty'] ) );
        update_post_meta( $post_id, 'caloriesPerServing', intval( $recipe['caloriesPerServing'] ) );
        update_post_meta( $post_id, 'servings', intval( $recipe['servings'] ) );
        update_post_meta( $post_id, 'wasteSavingTip', sanitize_text_field( $recipe['wasteSavingTip'] ) );

        update_post_meta( $post_id, 'instructions', $recipe['instructions'] );
        update_post_meta( $post_id, 'missingIngredients', $recipe['missingIngredients'] );
        update_post_meta( $post_id, 'dietaryTags', $recipe['dietaryTags'] );
        update_post_meta( $post_id, 'usedExpiringItems', $recipe['usedExpiringItems'] );
        update_post_meta( $post_id, 'otherUsedItems', $recipe['otherUsedItems'] );

        // Generate or fetch an image
        $image_data = $this->fetch_recipe_image_data( $recipe['title'], $recipe );
        if ( $image_data ) {
            $upload_dir = wp_upload_dir();
            $filename = sanitize_title( $recipe['title'] ) . '-' . time() . '.jpg';
            $filepath = $upload_dir['path'] . '/' . $filename;
            
            if ( file_put_contents( $filepath, $image_data ) ) {
                $filetype = wp_check_filetype( $filename, null );
                $attachment = array(
                    'post_mime_type' => $filetype['type'],
                    'post_title'     => sanitize_file_name( $filename ),
                    'post_content'   => '',
                    'post_status'    => 'inherit'
                );
                
                require_once( ABSPATH . 'wp-admin/includes/image.php' );
                require_once( ABSPATH . 'wp-admin/includes/file.php' );
                require_once( ABSPATH . 'wp-admin/includes/media.php' );
                
                $attach_id = wp_insert_attachment( $attachment, $filepath, $post_id );
                if ( ! is_wp_error( $attach_id ) ) {
                    $attach_data = wp_generate_attachment_metadata( $attach_id, $filepath );
                    wp_update_attachment_metadata( $attach_id, $attach_data );
                    set_post_thumbnail( $post_id, $attach_id );
                    
                    $image_url = wp_get_attachment_url( $attach_id );
                }
            }
        }

        $post_url = get_permalink( $post_id );
        $edit_url = get_edit_post_link( $post_id, 'raw' );

        return rest_ensure_response( array(
            'success'     => true,
            'id'          => $post_id,
            'imageUrl'    => $image_url ?? null,
            'postUrl'     => $post_url ?: null,
            'postEditUrl' => $edit_url ?: null,
        ) );
    }

    private function fetch_recipe_image_data( $title, $recipe_data = array() ) {
        $ingredients = array();
        if ( ! empty( $recipe_data['usedExpiringItems'] ) && is_array( $recipe_data['usedExpiringItems'] ) ) {
            $ingredients = array_merge( $ingredients, $recipe_data['usedExpiringItems'] );
        }
        if ( ! empty( $recipe_data['otherUsedItems'] ) && is_array( $recipe_data['otherUsedItems'] ) ) {
            $ingredients = array_merge( $ingredients, $recipe_data['otherUsedItems'] );
        }

        $ing_text = ! empty( $ingredients ) ? implode( ', ', array_unique( $ingredients ) ) : '';
        $ing_clause = ! empty( $ing_text ) ? " containing strictly only: {$ing_text}." : '';

        $lower_title = strtolower( $title );
        $is_drink = (
            strpos( $lower_title, 'smoothie' ) !== false ||
            strpos( $lower_title, 'shake' ) !== false ||
            strpos( $lower_title, 'juice' ) !== false ||
            strpos( $lower_title, 'drink' ) !== false ||
            strpos( $lower_title, 'tea' ) !== false ||
            strpos( $lower_title, 'lemonade' ) !== false ||
            strpos( $lower_title, 'latte' ) !== false ||
            strpos( $lower_title, 'cocktail' ) !== false ||
            strpos( $lower_title, 'cider' ) !== false ||
            strpos( $lower_title, 'beverage' ) !== false ||
            strpos( $lower_title, 'boba' ) !== false ||
            strpos( $lower_title, 'slushie' ) !== false
        );

        $is_soup = (
            strpos( $lower_title, 'soup' ) !== false ||
            strpos( $lower_title, 'stew' ) !== false ||
            strpos( $lower_title, 'chili' ) !== false ||
            strpos( $lower_title, 'curry' ) !== false ||
            strpos( $lower_title, 'broth' ) !== false ||
            strpos( $lower_title, 'bisque' ) !== false ||
            strpos( $lower_title, 'bowl' ) !== false ||
            strpos( $lower_title, 'chowder' ) !== false
        );

        $vessel = $is_drink
            ? "Served inside a clear glass cup, tall transparent drinking glass, or mason jar with a colorful straw"
            : ( $is_soup
                ? "Served inside a clean round ceramic bowl"
                : "Served on a flat elegant ceramic plate"
            );

        $ac_prompt = "A stylized mix of Animal Crossing New Horizons, Zelda Tears of the Kingdom, and real life photorealism food dish item icon, 3d game render of {$title}{$ing_clause} {$vessel}, cute yet highly detailed 3d game asset, isometric view, isolated on pure solid white background, no table, no wooden board, no trivet";

        $api_key = $this->get_api_key();
        if ( ! empty( $api_key ) ) {
            // Try Gemini Flash Image generation first
            $model = $this->get_gemini_model();
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . $api_key;
            $body = array(
                'contents' => array(
                    array( 'parts' => array( array( 'text' => $ac_prompt ) ) )
                ),
                'generationConfig' => array(
                    'responseModalities' => array( 'IMAGE' )
                )
            );

            $res = wp_remote_post( $url, array(
                'headers' => array( 'Content-Type' => 'application/json' ),
                'body'    => wp_json_encode( $body ),
                'timeout' => 25,
            ) );

            if ( ! is_wp_error( $res ) && wp_remote_retrieve_response_code( $res ) === 200 ) {
                $json = json_decode( wp_remote_retrieve_body( $res ), true );
                if ( ! empty( $json['candidates'][0]['content']['parts'][0]['inlineData']['data'] ) ) {
                    return base64_decode( $json['candidates'][0]['content']['parts'][0]['inlineData']['data'] );
                }
            }
        }

        // Fallback: AI Generation via Pollinations AI (Unlimited, 3D Animal Crossing Asset style)
        $encoded_prompt = urlencode( $ac_prompt );
        $pollinations_url = "https://image.pollinations.ai/prompt/{$encoded_prompt}?width=512&height=512&nologo=true";
        $fallback_res = wp_remote_get( $pollinations_url, array( 'timeout' => 30, 'redirection' => 5 ) );
        if ( ! is_wp_error( $fallback_res ) && wp_remote_retrieve_response_code( $fallback_res ) === 200 ) {
            return wp_remote_retrieve_body( $fallback_res );
        }

        return false;
    }

    public function regenerate_recipe_image( WP_REST_Request $request ) {
        $post_id = intval( $request->get_param( 'id' ) );
        $title = $request->get_param( 'title' );
        
        $post = null;
        if ( $post_id > 0 ) {
            $post = get_post( $post_id );
        }
        
        if ( ! $post && ! empty( $title ) ) {
            $post_by_title = get_page_by_title( html_entity_decode( $title ), OBJECT, 'ks_saved_recipe' );
            if ( $post_by_title ) {
                $post = $post_by_title;
                $post_id = $post->ID;
            } else {
                global $wpdb;
                $found = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_title LIKE %s AND post_type = 'ks_saved_recipe' LIMIT 1", '%' . $wpdb->esc_like( $title ) . '%' ) );
                if ( $found ) {
                    $post = get_post( $found );
                    $post_id = $post->ID;
                }
            }
        }

        $actual_title = $post ? $post->post_title : ( ! empty( $title ) ? $title : 'Recipe' );

        $recipe_data = array();
        if ( $post ) {
            $recipe_data['usedExpiringItems'] = get_post_meta( $post->ID, 'usedExpiringItems', true );
            $recipe_data['otherUsedItems']    = get_post_meta( $post->ID, 'otherUsedItems', true );
        }

        // Check if an image attachment already exists in WP Media for this dish title (Global Asset Recycling)
        global $wpdb;
        $title_slug = sanitize_title( $actual_title );
        $existing_attach_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_name LIKE %s LIMIT 1",
            $wpdb->esc_like( $title_slug ) . '%'
        ) );

        if ( $existing_attach_id ) {
            $image_url = wp_get_attachment_url( $existing_attach_id );
            if ( $image_url ) {
                if ( $post ) {
                    set_post_thumbnail( $post->ID, $existing_attach_id );
                }
                $target_id = $post ? $post->ID : $post_id;
                return rest_ensure_response( array(
                    'success'     => true,
                    'recycled'    => true,
                    'id'          => $target_id,
                    'imageUrl'    => $image_url,
                    'postUrl'     => $target_id ? get_permalink( $target_id ) : null,
                    'postEditUrl' => $target_id ? get_edit_post_link( $target_id, 'raw' ) : null,
                ) );
            }
        }

        $image_data = $this->fetch_recipe_image_data( $actual_title, $recipe_data );
        if ( ! $image_data ) {
            return new WP_Error( 'image_error', 'Could not generate or fetch recipe image.', array( 'status' => 500 ) );
        }

        $upload_dir = wp_upload_dir();
        $filename = sanitize_title( $actual_title ) . '-' . time() . '.jpg';
        $filepath = $upload_dir['path'] . '/' . $filename;
        
        if ( ! file_put_contents( $filepath, $image_data ) ) {
            return new WP_Error( 'fs_error', 'Could not save image to filesystem.', array( 'status' => 500 ) );
        }

        $filetype = wp_check_filetype( $filename, null );
        $attachment = array(
            'post_mime_type' => $filetype['type'],
            'post_title'     => sanitize_file_name( $filename ),
            'post_content'   => '',
            'post_status'    => 'inherit'
        );
        
        require_once( ABSPATH . 'wp-admin/includes/image.php' );
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        require_once( ABSPATH . 'wp-admin/includes/media.php' );
        
        $attach_id = wp_insert_attachment( $attachment, $filepath, $post ? $post->ID : 0 );
        if ( is_wp_error( $attach_id ) ) {
            return $attach_id;
        }

        $attach_data = wp_generate_attachment_metadata( $attach_id, $filepath );
        wp_update_attachment_metadata( $attach_id, $attach_data );

        if ( $post ) {
            $old_thumb_id = get_post_thumbnail_id( $post->ID );
            if ( $old_thumb_id ) {
                wp_delete_attachment( $old_thumb_id, true );
            }

            set_post_thumbnail( $post->ID, $attach_id );
        }
        
        $image_url = wp_get_attachment_url( $attach_id );

        $target_id = $post ? $post->ID : $post_id;
        $post_url  = $target_id ? get_permalink( $target_id ) : null;
        $edit_url  = $target_id ? get_edit_post_link( $target_id, 'raw' ) : null;

        return rest_ensure_response( array(
            'success'     => true,
            'id'          => $target_id,
            'imageUrl'    => $image_url,
            'postUrl'     => $post_url ?: null,
            'postEditUrl' => $edit_url ?: null,
        ) );
    }
}
