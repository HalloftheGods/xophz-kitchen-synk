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
        register_rest_route( 'kitchen-synk/v1', '/me', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_me' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'kitchen-synk/v1', '/login', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'login_user' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'kitchen-synk/v1', '/logout', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'logout_user' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'kitchen-synk/v1', '/register', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'register_user' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'kitchen-synk/v1', '/profile', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'update_profile' ),
            'permission_callback' => 'is_user_logged_in',
        ) );

        register_rest_route( 'kitchen-synk/v1', '/generate-recipes', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'generate_recipes' ),
            'permission_callback' => '__return_true',
        ) );
        
        register_rest_route( 'kitchen-synk/v1', '/generate-meal-plan', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'generate_meal_plan' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'kitchen-synk/v1', '/save-recipe', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'save_recipe' ),
            'permission_callback' => '__return_true',
        ) );
    }

    public function get_me( WP_REST_Request $request ) {
        $is_logged_in = is_user_logged_in();
        $user_id      = get_current_user_id();
        $current_user = wp_get_current_user();
        $base_url     = home_url( '/kitchen-synk/' );

        $user_data = array(
            'isLoggedIn' => $is_logged_in,
            'id'         => $user_id,
            'name'       => $is_logged_in ? $current_user->display_name : 'Guest User',
            'login'      => $is_logged_in ? $current_user->user_login : '',
            'email'      => $is_logged_in ? $current_user->user_email : '',
            'avatar'     => $is_logged_in ? get_avatar_url( $user_id, array( 'size' => 96 ) ) : '',
            'roles'      => $is_logged_in ? array_values( $current_user->roles ) : array(),
            'loginUrl'   => wp_login_url( $base_url ),
            'logoutUrl'  => wp_logout_url( $base_url ),
        );

        $saved_profile = $is_logged_in ? get_user_meta( $user_id, 'ks_user_profile', true ) : null;

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

        $user_data = array(
            'isLoggedIn' => true,
            'id'         => $user->ID,
            'name'       => $user->display_name,
            'login'      => $user->user_login,
            'email'      => $user->user_email,
            'avatar'     => get_avatar_url( $user->ID, array( 'size' => 96 ) ),
            'roles'      => array_values( $user->roles ),
            'loginUrl'   => wp_login_url( $base_url ),
            'logoutUrl'  => wp_logout_url( $base_url ),
        );

        $saved_profile = get_user_meta( $user->ID, 'ks_user_profile', true );

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

        update_user_meta( $user_id, 'ks_user_profile', $profile );

        return rest_ensure_response( array(
            'success' => true,
            'profile' => $profile,
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

    public function generate_meal_plan( WP_REST_Request $request ) {
        $api_key = get_option( 'ks_gemini_api_key', '' );
        if ( empty( $api_key ) ) {
            return new WP_Error( 'missing_api_key', 'Gemini API Key is not set in Kitchen Synk plugin settings.', array( 'status' => 400 ) );
        }

        $params   = $request->get_json_params();
        $items    = $params['items'] ?? array();
        $dietary  = $params['dietaryPreferences'] ?? array();
        $slots    = $params['slots'] ?? array('Breakfast', 'Lunch', 'Dinner', 'Snack');
        $days     = $params['days'] ?? array('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday');

        $prompt  = "You are a zero-waste culinary AI expert named Xophz-COMPASS. Generate a weekly meal plan.\n";
        $prompt .= "Target Days: " . implode(', ', $days) . "\n";
        $prompt .= "Target Slots: " . implode(', ', $slots) . "\n";
        $prompt .= "Available Pantry & Fridge Inventory (prioritize items expiring soonest): " . json_encode($items) . "\n";
        $prompt .= "Dietary Preferences: " . implode(', ', $dietary) . "\n";
        $prompt .= "Return ONLY a raw JSON array of meal plan objects (no markdown backticks or formatting) matching this format:\n";
        $prompt .= '[{"day": "Monday", "slot": "Breakfast", "recipe": {"id": "ai_plan_1", "title": "...", "description": "...", "prepTime": "15 mins", "cookTime": "10 mins", "difficulty": "Easy", "usedExpiringItems": [], "otherUsedItems": [], "missingIngredients": [{"name": "...", "amount": "..."}], "instructions": ["..."], "dietaryTags": ["..."], "caloriesPerServing": 350, "servings": 2, "wasteSavingTip": "..."}}]';

        $body = array(
            'contents' => array(
                array( 'parts' => array( array( 'text' => $prompt ) ) )
            )
        );

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
                'timeout' => 35,
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
        $raw_text = preg_replace('/```json/i', '', $raw_text);
        $raw_text = preg_replace('/```/', '', $raw_text);
        $raw_text = trim($raw_text);

        $entries = json_decode( $raw_text, true );
        
        if ( ! is_array( $entries ) ) {
            return new WP_Error( 'json_parse_error', 'Failed to parse JSON from AI: ' . substr($raw_text, 0, 200), array( 'status' => 500 ) );
        }

        return rest_ensure_response( array( 'entries' => $entries ) );
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
