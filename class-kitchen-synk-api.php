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
            'supports'     => array( 'title', 'editor', 'author', 'custom-fields', 'thumbnail' ),
            'show_in_rest' => true,
        );
        register_post_type( 'ks_suggested_meal', $suggested_args );

        // Saved Recipe CPT (User Cookbook)
        $saved_args = array(
            'public'       => false,
            'show_ui'      => true,
            'label'        => 'Cookbook Recipes',
            'menu_icon'    => 'dashicons-book-alt',
            'supports'     => array( 'title', 'editor', 'author', 'custom-fields', 'thumbnail' ),
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

        register_rest_route( 'kitchen-synk/v1', '/regenerate-recipe-image', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'regenerate_recipe_image' ),
            'permission_callback' => '__return_true',
        ) );
    }

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
        if ( ! empty( get_option( 'connectors_ai_google_api_key' ) ) ) {
            return get_option( 'connectors_ai_google_api_key' );
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

        $raw_names = array_map(function($i) {
            return is_array($i) && isset($i['name']) ? trim($i['name']) : (is_string($i) ? trim($i) : '');
        }, $items);
        $item_names = array_values( array_unique( array_filter( $raw_names ) ) );

        $prompt = "You are a zero-waste culinary AI expert named Xophz-COMPASS. Generate 5 to 6 unique recipes, including a mix of full meals, snacks, and drinks.\n";
        $prompt .= "Available Inventory: " . json_encode($items) . "\n";
        $prompt .= "Dietary Preferences: " . implode(', ', $dietary) . "\n";
        $prompt .= "Max Prep Time: {$maxPrep} minutes\n";
        if ( ! empty( $custom ) ) {
            $prompt .= "Custom Request: {$custom}\n";
        }
        if ( ! empty( $past_titles ) ) {
            $prompt .= "DO NOT suggest these recipes again (already suggested): " . implode(', ', $past_titles) . "\n";
        }
        $prompt .= "CRITICAL: The 'usedExpiringItems' and 'otherUsedItems' MUST EXACTLY match the names provided in 'Available Inventory'. Do not hallucinate items the user does not have. If an item is needed but not in inventory, put it in 'missingIngredients'.\n";

        $schema = array(
            'type' => 'array',
            'items' => array(
                'type' => 'object',
                'properties' => array(
                    'id' => array('type' => 'string'),
                    'title' => array('type' => 'string'),
                    'description' => array('type' => 'string'),
                    'prepTime' => array('type' => 'string'),
                    'cookTime' => array('type' => 'string'),
                    'difficulty' => array('type' => 'string', 'enum' => array('Easy', 'Medium', 'Advanced')),
                    'usedExpiringItems' => array(
                        'type' => 'array',
                        'items' => array('type' => 'string', 'enum' => empty($item_names) ? array('') : $item_names)
                    ),
                    'otherUsedItems' => array(
                        'type' => 'array',
                        'items' => array('type' => 'string', 'enum' => empty($item_names) ? array('') : $item_names)
                    ),
                    'missingIngredients' => array(
                        'type' => 'array',
                        'items' => array(
                            'type' => 'object',
                            'properties' => array(
                                'name' => array('type' => 'string'),
                                'amount' => array('type' => 'string'),
                            )
                        )
                    ),
                    'instructions' => array('type' => 'array', 'items' => array('type' => 'string')),
                    'dietaryTags' => array('type' => 'array', 'items' => array('type' => 'string')),
                    'caloriesPerServing' => array('type' => 'integer'),
                    'servings' => array('type' => 'integer'),
                    'wasteSavingTip' => array('type' => 'string'),
                    'usedIngredientAmounts' => array(
                        'type' => 'object',
                        'description' => 'Key-value map of used ingredient name to required amount string (e.g. {"Milk": "1 cup"})'
                    )
                ),
                'required' => array('id', 'title', 'description', 'prepTime', 'cookTime', 'difficulty', 'usedExpiringItems', 'otherUsedItems', 'missingIngredients', 'instructions', 'dietaryTags', 'caloriesPerServing', 'servings', 'wasteSavingTip')
            )
        );

        $body = array(
            'contents' => array(
                array( 'parts' => array( array( 'text' => $prompt ) ) )
            ),
            'generationConfig' => array(
                'responseMimeType' => 'application/json',
                'responseSchema' => $schema
            )
        );

        // Try gemini-2.0-flash first, fallback to gemini-2.0-flash-lite
        $models = array( 'gemini-2.0-flash', 'gemini-2.0-flash-lite' );
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
                $msg = $b['error']['message'];
                $last_err_msg = "Model {$model} error ({$c}): " . $msg;
            } else {
                $last_err_msg = "Model {$model} returned status {$c}";
            }
        }

        if ( $code !== 200 || empty( $body_res['candidates'][0]['content']['parts'][0]['text'] ) ) {
            $is_rate_limit = ( $code === 429 ) || ( stripos( $last_err_msg, 'quota' ) !== false ) || ( stripos( $last_err_msg, 'rate limit' ) !== false );
            $error_code = $is_rate_limit ? 'gemini_rate_limit' : 'gemini_api_error';
            $error_msg = ! empty( $last_err_msg ) ? $last_err_msg : 'Failed to generate recipes from AI.';
            
            return new WP_Error(
                $error_code,
                $error_msg,
                array(
                    'status' => $code === 429 ? 429 : 500,
                    'data' => array( 'retryAfter' => 30 )
                )
            );
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

        // Auto-save as suggested meals and verify hallucinations
        foreach ( $recipes as &$r ) {
            $used_expiring = $r['usedExpiringItems'] ?? array();
            $other_used = $r['otherUsedItems'] ?? array();
            
            // Validate against inventory
            $valid_expiring = array();
            $valid_other = array();
            $missing = $r['missingIngredients'] ?? array();

            foreach ( $used_expiring as $item ) {
                if ( in_array( $item, $item_names ) ) {
                    $valid_expiring[] = $item;
                } else if ( ! empty( $item ) ) {
                    $missing[] = array( 'name' => $item, 'amount' => 'To taste' );
                }
            }
            foreach ( $other_used as $item ) {
                if ( in_array( $item, $item_names ) ) {
                    $valid_other[] = $item;
                } else if ( ! empty( $item ) ) {
                    $missing[] = array( 'name' => $item, 'amount' => 'To taste' );
                }
            }

            $r['usedExpiringItems'] = $valid_expiring;
            $r['otherUsedItems'] = $valid_other;
            $r['missingIngredients'] = $missing;

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

    private function generate_fallback_recipes( $items, $dietary = array(), $maxPrep = 45, $custom = '', $profile = array() ) {
        $item_names = array();
        foreach ( (array) $items as $i ) {
            if ( is_string( $i ) && ! empty( trim( $i ) ) ) {
                $item_names[] = trim( $i );
            } else if ( is_array( $i ) && ! empty( $i['name'] ) ) {
                $item_names[] = trim( $i['name'] );
            }
        }
        $item_names = array_values( array_unique( array_filter( $item_names ) ) );

        $expiring_names = array();
        foreach ( (array) $items as $i ) {
            if ( is_array( $i ) && isset( $i['daysLeft'] ) && $i['daysLeft'] <= 3 && ! empty( $i['name'] ) ) {
                $expiring_names[] = trim( $i['name'] );
            }
        }
        $expiring_names = array_values( array_unique( array_filter( $expiring_names ) ) );
        $other_names = array_values( array_diff( $item_names, $expiring_names ) );

        return array(
            array(
                'id'                 => 'fallback_' . time() . '_1',
                'title'              => 'Quick ' . ( ! empty( $expiring_names ) ? $expiring_names[0] : 'Kitchen' ) . ' Skillet Fry',
                'description'        => 'A fast, delicious skillet fry using your available inventory ingredients (' . implode( ', ', array_slice( $item_names, 0, 3 ) ) . ').',
                'prepTime'           => '10 mins',
                'cookTime'           => '15 mins',
                'difficulty'         => 'Easy',
                'caloriesPerServing' => 380,
                'servings'           => 2,
                'wasteSavingTip'     => 'Uses up items close to expiration for zero food waste.',
                'instructions'       => array(
                    'Prep all available ingredients into uniform bite-sized pieces.',
                    'Heat 1 tbsp olive oil or butter in a skillet over medium-high heat.',
                    'Sauté aromatics first until fragrant and translucent.',
                    'Add remaining ingredients and stir-fry until tender and golden brown.',
                    'Season with salt, pepper, and herbs of choice. Serve warm!'
                ),
                'missingIngredients' => array(),
                'dietaryTags'        => array_merge( array( 'Quick', 'Easy' ), (array) $dietary ),
                'usedExpiringItems'  => $expiring_names,
                'otherUsedItems'     => $other_names
            ),
            array(
                'id'                 => 'fallback_' . time() . '_2',
                'title'              => 'Nourishing ' . ( ! empty( $other_names ) ? $other_names[0] : 'Pantry' ) . ' Harvest Bowl',
                'description'        => 'A balanced, nutrient-rich harvest bowl featuring your pantry staples and fresh greens.',
                'prepTime'           => '15 mins',
                'cookTime'           => '15 mins',
                'difficulty'         => 'Easy',
                'caloriesPerServing' => 420,
                'servings'           => 2,
                'wasteSavingTip'     => 'Great way to combine pantry staples with fresh produce.',
                'instructions'       => array(
                    'Prepare rice, grain, or greens as the bowl base.',
                    'Warm or gently sauté available vegetables and beans in a pan.',
                    'Arrange base and warm toppings neatly into serving bowls.',
                    'Drizzle with olive oil, lemon juice, or dressing of choice before serving.'
                ),
                'missingIngredients' => array(),
                'dietaryTags'        => array_merge( array( 'Healthy', 'Gluten-Free' ), (array) $dietary ),
                'usedExpiringItems'  => $expiring_names,
                'otherUsedItems'     => $other_names
            )
        );
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

        return rest_ensure_response( array( 'success' => true, 'id' => $post_id, 'imageUrl' => $image_url ?? null ) );
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

        $vessel = $is_drink
            ? "Served inside a clear glass cup, tall transparent drinking glass, or mason jar with a colorful straw"
            : "Served inside a clean round ceramic bowl or elegant plate";

        $ac_prompt = "Animal Crossing New Horizons food dish item icon, 3d game render of {$title}{$ing_clause} {$vessel}, cute stylized 3d game asset, isometric view, isolated on pure solid white background, no table, no wooden board, no trivet";

        $api_key = $this->get_api_key();
        if ( ! empty( $api_key ) ) {
            // Try Gemini Flash Image generation first
            $model = 'gemini-2.5-flash-image';
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

        return rest_ensure_response( array(
            'success' => true,
            'imageUrl' => $image_url
        ) );
    }
}

new Xophz_Kitchen_Synk_API();
