<?php

if ( ! defined( 'WPINC' ) ) {
	die;
}

trait Xophz_Kitchen_Synk_API_AI {

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

    private function get_gemini_model() {
        if ( function_exists( 'wp_get_connectors' ) ) {
            $connectors = wp_get_connectors();
            if ( ! empty( $connectors['google']['options']['model']['setting_name'] ) ) {
                $model = get_option( $connectors['google']['options']['model']['setting_name'], '' );
                if ( ! empty( $model ) ) {
                    return $model;
                }
            }
        }
        return 'gemini-3.6-flash';
    }

    public function get_user_quota_info( $user_id = null ) {
        if ( empty( $user_id ) ) {
            $user_id = get_current_user_id() ?: 1;
        }

        $tier = get_user_meta( $user_id, 'kitchensynk_user_type', true ) ?: 'starter';
        
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

        $limit = isset( $limits[ $tier ] ) ? $limits[ $tier ] : 20;

        $today = date( 'Y-m-d' );
        $last_date = get_user_meta( $user_id, 'ks_ai_quota_date', true );
        $used = (int) get_user_meta( $user_id, 'ks_ai_quota_used', true );

        if ( $last_date !== $today ) {
            $used = 0;
            update_user_meta( $user_id, 'ks_ai_quota_date', $today );
            update_user_meta( $user_id, 'ks_ai_quota_used', 0 );
        }

        return array(
            'tier'      => $tier,
            'limit'     => $limit,
            'used'      => $used,
            'remaining' => max( 0, $limit - $used ),
            'reset'     => $today . ' 23:59:59',
        );
    }

    public function check_and_increment_ai_quota( $user_id = null ) {
        if ( empty( $user_id ) ) {
            $user_id = get_current_user_id() ?: 1;
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
        update_user_meta( $user_id, 'ks_ai_quota_used', $new_used );

        $info['used'] = $new_used;
        $info['remaining'] = max( 0, $info['limit'] - $new_used );

        return $info;
    }

    public function get_ai_quota( WP_REST_Request $request ) {
        $user_id = get_current_user_id() ?: 1;
        $quota = $this->get_user_quota_info( $user_id );
        return rest_ensure_response( array( 'success' => true, 'quota' => $quota ) );
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

        $raw_names = array_map(function($i) {
            return is_array($i) && isset($i['name']) ? trim($i['name']) : (is_string($i) ? trim($i) : '');
        }, $items);
        $item_names = array_values( array_unique( array_filter( $raw_names ) ) );

        $user_id = get_current_user_id() ?: 1;

        // Check 4-hour transient cache for identical request payload
        $cache_payload = array(
            'items'   => $item_names,
            'dietary' => $dietary,
            'maxPrep' => $maxPrep,
            'custom'  => $custom,
        );
        $cache_key = 'ks_recipes_' . md5( wp_json_encode( $cache_payload ) );
        $cached_recipes = get_transient( $cache_key );

        if ( ! empty( $cached_recipes ) && is_array( $cached_recipes ) ) {
            $quota_info = $this->get_user_quota_info( $user_id );
            return rest_ensure_response( array(
                'recipes' => $cached_recipes,
                'cached'  => true,
                'quota'   => $quota_info,
            ) );
        }

        // Server-enforced daily rate limit quota check
        $quota_res = $this->check_and_increment_ai_quota( $user_id );
        if ( is_wp_error( $quota_res ) ) {
            return $quota_res;
        }

        // Get past suggestions for context
        $history_args = array(
            'post_type'      => 'ks_suggested_meal',
            'posts_per_page' => 10,
            'post_status'    => 'publish',
            'author'         => $user_id,
        );
        $past_posts = get_posts( $history_args );
        $past_titles = array();
        foreach ( $past_posts as $p ) {
            $past_titles[] = $p->post_title;
        }

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

        $model = $this->get_gemini_model();
        $response = null;
        $code = 0;
        $body_res = array();
        $last_err_msg = '';

        $gemini_url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . $api_key;
        
        $res = wp_remote_post( $gemini_url, array(
                'headers' => array( 'Content-Type' => 'application/json' ),
                'body'    => wp_json_encode( $body ),
                'timeout' => 30,
            ) );

            if ( is_wp_error( $res ) ) {
                $last_err_msg = $res->get_error_message();
            } else {
                $c = wp_remote_retrieve_response_code( $res );
                $b = json_decode( wp_remote_retrieve_body( $res ), true );

                if ( $c === 200 && ! empty( $b['candidates'][0]['content']['parts'][0]['text'] ) ) {
                    $response = $res;
                    $code = $c;
                    $body_res = $b;
                } else {
                    if ( ! empty( $b['error']['message'] ) ) {
                        $msg = $b['error']['message'];
                        $last_err_msg = "Model {$model} error ({$c}): " . $msg;
                    } else {
                        $last_err_msg = "Model {$model} returned status {$c}";
                    }
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

        // Save generated recipes to 4-hour transient cache
        set_transient( $cache_key, $recipes, 4 * HOUR_IN_SECONDS );

        return rest_ensure_response( array(
            'recipes' => $recipes,
            'cached'  => false,
            'quota'   => is_array( $quota_res ) ? $quota_res : $this->get_user_quota_info( $user_id ),
        ) );
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
        $api_key = $this->get_api_key();
        if ( empty( $api_key ) ) {
            $api_key = get_option( 'ks_gemini_api_key', '' );
        }
        if ( empty( $api_key ) ) {
            return new WP_Error( 'missing_api_key', 'Gemini API Key is not set in Kitchen Synk plugin settings.', array( 'status' => 400 ) );
        }

        $params   = $request->get_json_params();
        $items    = $params['items'] ?? array();
        $dietary  = $params['dietaryPreferences'] ?? array();
        $slots    = $params['slots'] ?? array('Breakfast', 'Lunch', 'Dinner', 'Snack');
        $days     = $params['days'] ?? array('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday');

        $user_id = get_current_user_id() ?: 1;

        // Check 6-hour transient cache for identical meal plan request
        $cache_payload = array(
            'items'   => $items,
            'dietary' => $dietary,
            'slots'   => $slots,
            'days'    => $days,
        );
        $cache_key = 'ks_plan_' . md5( wp_json_encode( $cache_payload ) );
        $cached_plan = get_transient( $cache_key );

        if ( ! empty( $cached_plan ) && is_array( $cached_plan ) ) {
            $quota_info = $this->get_user_quota_info( $user_id );
            return rest_ensure_response( array(
                'entries' => $cached_plan,
                'cached'  => true,
                'quota'   => $quota_info,
            ) );
        }

        // Check server-side daily quota
        $quota_res = $this->check_and_increment_ai_quota( $user_id );
        if ( is_wp_error( $quota_res ) ) {
            return $quota_res;
        }

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

        $model = $this->get_gemini_model();
        $response = null;
        $code = 0;
        $body_res = array();
        $last_err_msg = '';

        $gemini_url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . $api_key;
        
        $res = wp_remote_post( $gemini_url, array(
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( $body ),
            'timeout' => 35,
        ) );

        if ( is_wp_error( $res ) ) {
            $last_err_msg = $res->get_error_message();
        } else {
            $c = wp_remote_retrieve_response_code( $res );
            $b = json_decode( wp_remote_retrieve_body( $res ), true );

            if ( $c === 200 && ! empty( $b['candidates'][0]['content']['parts'][0]['text'] ) ) {
                $response = $res;
                $code = $c;
                $body_res = $b;
            } else {
                if ( ! empty( $b['error']['message'] ) ) {
                    $last_err_msg = "Model {$model} error ({$c}): " . $b['error']['message'];
                } else {
                    $last_err_msg = "Model {$model} returned status {$c}";
                }
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

        // Cache meal plan in 6-hour transient cache
        set_transient( $cache_key, $entries, 6 * HOUR_IN_SECONDS );

        return rest_ensure_response( array(
            'entries' => $entries,
            'cached'  => false,
            'quota'   => is_array( $quota_res ) ? $quota_res : $this->get_user_quota_info( $user_id ),
        ) );
    }
}
