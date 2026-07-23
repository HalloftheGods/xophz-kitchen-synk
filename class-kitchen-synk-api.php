<?php

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Xophz_Kitchen_Synk_API {
    
    public function __construct() {
        add_action( 'init', array( $this, 'register_cpts' ) );
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
    }

    public function register_cpts() {
        // Suggested Meal CPT (AI Memory)
        $suggested_args = array(
            'public'       => false,
            'show_ui'      => true,
            'label'        => 'Suggested Meals',
            'menu_icon'    => 'dashicons-carrot',
            'supports'     => array( 'title', 'editor', 'author', 'custom-fields' ),
            'show_in_rest' => true,
        );
        register_post_type( 'ks_suggested_meal', $suggested_args );

        // Saved Recipe CPT (User Cookbook)
        $saved_args = array(
            'public'       => false,
            'show_ui'      => true,
            'label'        => 'Cookbook Recipes',
            'menu_icon'    => 'dashicons-book-alt',
            'supports'     => array( 'title', 'editor', 'author', 'custom-fields' ),
            'show_in_rest' => true,
        );
        register_post_type( 'ks_saved_recipe', $saved_args );

        // Register post meta for REST API
        $meta_fields = array(
            'prepTime', 'cookTime', 'difficulty', 'caloriesPerServing', 'servings', 'wasteSavingTip'
        );
        $json_fields = array(
            'instructions', 'missingIngredients', 'dietaryTags', 'usedExpiringItems', 'otherUsedItems'
        );

        $post_types = array( 'ks_suggested_meal', 'ks_saved_recipe' );
        foreach ( $post_types as $pt ) {
            foreach ( $meta_fields as $field ) {
                register_post_meta( $pt, $field, array(
                    'show_in_rest' => true,
                    'single'       => true,
                    'type'         => 'string',
                ) );
            }
            foreach ( $json_fields as $field ) {
                register_post_meta( $pt, $field, array(
                    'show_in_rest' => array(
                        'schema' => array(
                            'type'  => 'array',
                            'items' => array(
                                'type' => 'object'
                            ),
                        ),
                    ),
                    'single'       => true,
                    'type'         => 'array',
                ) );
            }
        }
    }

    public function register_rest_routes() {
        register_rest_route( 'kitchen-synk/v1', '/generate-recipes', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'generate_recipes' ),
            'permission_callback' => '__return_true', // In a real app, verify user.
        ) );
        
        register_rest_route( 'kitchen-synk/v1', '/save-recipe', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'save_recipe' ),
            'permission_callback' => '__return_true',
        ) );
    }

    private function get_api_key() {
        if ( function_exists( 'wp_get_connectors' ) ) {
            $connectors = wp_get_connectors();
            if ( ! empty( $connectors['google']['authentication']['setting_name'] ) ) {
                $api_key = get_option( $connectors['google']['authentication']['setting_name'], '' );
                if ( ! empty( $api_key ) ) {
                    return $api_key;
                }
            }
        }

        if ( defined( 'GEMINI_API_KEY' ) && ! empty( GEMINI_API_KEY ) ) {
            return GEMINI_API_KEY;
        }
        if ( ! empty( $_ENV['GEMINI_API_KEY'] ) ) {
            return $_ENV['GEMINI_API_KEY'];
        }
        if ( ! empty( getenv( 'GEMINI_API_KEY' ) ) ) {
            return getenv( 'GEMINI_API_KEY' );
        }
        if ( ! empty( get_option( 'xophz_gemini_api_key' ) ) ) {
            return get_option( 'xophz_gemini_api_key' );
        }
        return get_option( 'gemini_api_key', '' );
    }

    public function generate_recipes( WP_REST_Request $request ) {
        $api_key = $this->get_api_key();
        if ( empty( $api_key ) ) {
            return new WP_Error( 'no_api_key', 'Gemini API key is not configured. Please set GEMINI_API_KEY in environment or site settings.', array( 'status' => 500 ) );
        }

        $params = $request->get_json_params();
        $items = $params['items'] ?? array();
        $dietary = $params['dietaryPreferences'] ?? array();
        $maxPrep = $params['maxPrepTime'] ?? 45;
        $custom = $params['customRequest'] ?? '';
        $profile = $params['userProfile'] ?? array();

        // Get past suggestions for context
        $history_args = array(
            'post_type'      => 'ks_suggested_meal',
            'posts_per_page' => 10,
            'post_status'    => 'publish',
            'author'         => get_current_user_id() ?: 1, // Fallback to 1 if not logged in
        );
        $past_posts = get_posts( $history_args );
        $past_titles = array();
        foreach ( $past_posts as $p ) {
            $past_titles[] = $p->post_title;
        }

        $prompt = "You are a zero-waste culinary AI expert named Xophz-COMPASS. Generate 3 unique recipes.\n";
        $prompt .= "Available Ingredients (prioritize expiring): " . json_encode($items) . "\n";
        $prompt .= "Dietary Preferences: " . implode(', ', $dietary) . "\n";
        $prompt .= "Max Prep Time: {$maxPrep} minutes\n";
        if ( ! empty( $custom ) ) {
            $prompt .= "Custom Request: {$custom}\n";
        }
        if ( ! empty( $past_titles ) ) {
            $prompt .= "DO NOT suggest these recipes again (already suggested): " . implode(', ', $past_titles) . "\n";
        }
        
        $prompt .= "Return ONLY a raw JSON array (no markdown backticks or formatting) where each object has these EXACT keys:\n";
        $prompt .= 'id (string), title (string), description (string), prepTime (string), cookTime (string), difficulty ("Easy" | "Medium" | "Advanced"), usedExpiringItems (array of strings), otherUsedItems (array of strings), missingIngredients (array of objects with "name" and "amount"), instructions (array of strings), dietaryTags (array of strings), caloriesPerServing (number), servings (number), wasteSavingTip (string).';

        $body = array(
            'contents' => array(
                array( 'parts' => array( array( 'text' => $prompt ) ) )
            )
        );

        // Try gemini-2.5-flash first, fallback to gemini-1.5-flash
        $models = array( 'gemini-2.5-flash', 'gemini-1.5-flash' );
        $response = null;
        $code = 0;
        $body_res = array();
        $last_err_msg = '';

        foreach ( $models as $model ) {
            $gemini_url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . $api_key;
            
            $res = wp_remote_post( $gemini_url, array(
                'headers' => array( 'Content-Type' => 'application/json' ),
                'body'    => wp_json_encode( $body ),
                'timeout' => 30,
            ) );

            if ( is_wp_error( $res ) ) {
                $last_err_msg = $res->get_error_message();
                continue;
            }

            $c = wp_remote_retrieve_response_code( $res );
            $b = json_decode( wp_remote_retrieve_body( $res ), true );

            if ( $c === 200 && ! empty( $b['candidates'][0]['content']['parts'][0]['text'] ) ) {
                $response = $res;
                $code = $c;
                $body_res = $b;
                break;
            }

            if ( ! empty( $b['error']['message'] ) ) {
                $last_err_msg = "Model {$model} error ({$c}): " . $b['error']['message'];
            } else {
                $last_err_msg = "Model {$model} returned status {$c}";
            }
        }

        if ( $code !== 200 || empty( $body_res['candidates'][0]['content']['parts'][0]['text'] ) ) {
            return new WP_Error( 'gemini_error', 'Gemini API Error: ' . ( $last_err_msg ?: 'Invalid response structure' ), array( 'status' => 500 ) );
        }

        $raw_text = $body_res['candidates'][0]['content']['parts'][0]['text'];
        
        // Clean markdown backticks if any
        $raw_text = preg_replace('/```json/i', '', $raw_text);
        $raw_text = preg_replace('/```/', '', $raw_text);
        $raw_text = trim($raw_text);

        $recipes = json_decode( $raw_text, true );
        
        if ( ! is_array( $recipes ) ) {
            return new WP_Error( 'json_parse_error', 'Failed to parse JSON from AI: ' . substr($raw_text, 0, 200), array( 'status' => 500 ) );
        }

        $user_id = get_current_user_id() ?: 1;

        // Auto-save as suggested meals
        foreach ( $recipes as &$r ) {
            $post_id = wp_insert_post( array(
                'post_title'   => sanitize_text_field( $r['title'] ),
                'post_content' => wp_kses_post( $r['description'] ),
                'post_status'  => 'publish',
                'post_author'  => $user_id,
                'post_type'    => 'ks_suggested_meal',
            ) );

            if ( $post_id && ! is_wp_error( $post_id ) ) {
                update_post_meta( $post_id, 'prepTime', sanitize_text_field( $r['prepTime'] ) );
                update_post_meta( $post_id, 'cookTime', sanitize_text_field( $r['cookTime'] ) );
                update_post_meta( $post_id, 'difficulty', sanitize_text_field( $r['difficulty'] ) );
                update_post_meta( $post_id, 'caloriesPerServing', intval( $r['caloriesPerServing'] ) );
                update_post_meta( $post_id, 'servings', intval( $r['servings'] ) );
                update_post_meta( $post_id, 'wasteSavingTip', sanitize_text_field( $r['wasteSavingTip'] ) );

                update_post_meta( $post_id, 'instructions', $r['instructions'] );
                update_post_meta( $post_id, 'missingIngredients', $r['missingIngredients'] );
                update_post_meta( $post_id, 'dietaryTags', $r['dietaryTags'] );
                update_post_meta( $post_id, 'usedExpiringItems', $r['usedExpiringItems'] );
                update_post_meta( $post_id, 'otherUsedItems', $r['otherUsedItems'] );
                
                // assign a real ID based on the post ID
                $r['id'] = 'suggested_' . $post_id;
            }
        }

        return rest_ensure_response( array( 'recipes' => $recipes ) );
    }

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

        return rest_ensure_response( array( 'success' => true, 'id' => $post_id ) );
    }
}

new Xophz_Kitchen_Synk_API();
