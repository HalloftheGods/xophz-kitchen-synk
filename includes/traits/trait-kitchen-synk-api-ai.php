<?php

if ( ! defined( 'WPINC' ) ) {
	die;
}

trait Kitchen_Synk_API_AI_Trait {

    private function get_gemini_key() {
        if ( function_exists( 'wp_get_connectors' ) ) {
            $connectors = wp_get_connectors();
            if ( ! empty( $connectors['google']['authentication']['setting_name'] ) ) {
                $api_key = get_option( $connectors['google']['authentication']['setting_name'], '' );
                if ( ! empty( $api_key ) ) {
                    return $api_key;
                }
            }
        }
        
        $official_key = get_option( 'connectors_ai_google_api_key' );
        if ( ! empty( $official_key ) ) {
            return $official_key;
        }

        $key = getenv( 'GEMINI_API_KEY' );
        if ( ! empty( $key ) ) {
            return $key;
        }
        if ( defined( 'GEMINI_API_KEY' ) && ! empty( GEMINI_API_KEY ) ) {
            return GEMINI_API_KEY;
        }
        return '';
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

    private function call_gemini( $contents, $schema = null ) {
        $api_key = $this->get_gemini_key();
        if ( empty( $api_key ) ) {
            return new WP_Error( 'no_api_key', 'GEMINI_API_KEY is not configured on the server.', array( 'status' => 500 ) );
        }

        $model = $this->get_gemini_model();
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . $api_key;

        $body = array(
            'contents' => $contents,
            'generationConfig' => array(
                'responseMimeType' => 'application/json',
            ),
        );

        if ( ! empty( $schema ) ) {
            $body['generationConfig']['responseSchema'] = $schema;
        }

        $response = wp_remote_post( $url, array(
            'headers'     => array(
                'Content-Type' => 'application/json',
                'User-Agent'   => 'aistudio-build',
            ),
            'body'        => wp_json_encode( $body ),
            'method'      => 'POST',
            'data_format' => 'body',
            'timeout'     => 60,
        ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'gemini_error', $response->get_error_message(), array( 'status' => 500 ) );
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        $data = json_decode( $response_body, true );

        if ( $response_code !== 200 ) {
            $error_message = isset( $data['error']['message'] ) ? $data['error']['message'] : 'Failed to call Gemini API.';
            return new WP_Error( 'gemini_error', $error_message, array( 'status' => $response_code ) );
        }

        if ( isset( $data['candidates'][0]['content']['parts'][0]['text'] ) ) {
            $result_text = $data['candidates'][0]['content']['parts'][0]['text'];
            return json_decode( $result_text, true );
        }

        return new WP_Error( 'gemini_error', 'Invalid response format from Gemini API.', array( 'status' => 500 ) );
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

    public function rest_generate_recipes( WP_REST_Request $request ) {
        $params = $request->get_json_params();
        return $this->handle_generate_recipes( $params );
    }

    public function handle_generate_recipes( $params ) {
        $items = isset( $params['items'] ) ? $params['items'] : array();
        $dietaryPreferences = isset( $params['dietaryPreferences'] ) ? $params['dietaryPreferences'] : array();
        $maxPrepTime = isset( $params['maxPrepTime'] ) ? $params['maxPrepTime'] : null;
        $mealType = isset( $params['mealType'] ) ? $params['mealType'] : null;
        $customRequest = isset( $params['customRequest'] ) ? $params['customRequest'] : '';
        $userProfile = isset( $params['userProfile'] ) ? $params['userProfile'] : null;
        $existingRecipes = isset( $params['existingRecipes'] ) ? $params['existingRecipes'] : array();

        if ( empty( $items ) || ! is_array( $items ) ) {
            return new WP_REST_Response( array( 'error' => 'No inventory items provided for recipe creation' ), 400 );
        }

        $user_id = get_current_user_id() ?: 1;
        $quota_res = $this->check_and_increment_ai_quota( $user_id );
        if ( is_wp_error( $quota_res ) ) {
            return $quota_res;
        }

        $itemSummaryLines = array();
        foreach ( $items as $i ) {
            $name = isset( $i['name'] ) ? $i['name'] : 'Item';
            $qty = isset( $i['quantity'] ) ? $i['quantity'] : 'some';
            $days = isset( $i['daysLeft'] ) ? $i['daysLeft'] : 10;
            $expFlag = ( $days <= 3 ) ? ' 🚨 EXPIRING SOON!' : '';
            $itemSummaryLines[] = "- {$name} ({$qty}, expires in {$days} days{$expFlag})";
        }
        $itemSummary = implode( "\n", $itemSummaryLines );

        $diabeticSection = '';
        if ( ! empty( $userProfile['isDiabetic'] ) ) {
            $type = isset( $userProfile['diabeticType'] ) ? $userProfile['diabeticType'] : 'Diabetic Care';
            $maxCarbs = isset( $userProfile['maxCarbsPerMeal'] ) ? $userProfile['maxCarbsPerMeal'] : 35;
            $diabeticSection = "\n🚨 DIABETIC PROFILE ACTIVE ({$type}):\n- User target: Keep meals low-glycemic index (GI < 55) and net carbs under {$maxCarbs}g per serving.\n- Avoid added sugars, refined white flour/rice, and high-GI carbohydrates. Prioritize fiber, healthy fats, and lean proteins.";
        }

        $prefSection = ( ! empty( $dietaryPreferences ) && is_array( $dietaryPreferences ) ) ? 'User Dietary Preferences: ' . implode( ', ', $dietaryPreferences ) : '';
        $prepSection = $maxPrepTime ? "Max Preparation Time: {$maxPrepTime} minutes" : '';
        $mealSection = $mealType ? "Desired Meal Type: {$mealType}" : '';
        $customSection = $customRequest ? "Special Request: \"{$customRequest}\"" : '';
        $excludeSection = ( ! empty( $existingRecipes ) && is_array( $existingRecipes ) ) ? 'DO NOT suggest or repeat any of the following existing/previously generated recipe titles: ' . implode( ', ', $existingRecipes ) : '';

        $prompt = "You are an elite culinary chef and zero-waste food consultant specializing in clinical nutrition and diabetic-friendly cooking.
The user has the following items in their refrigerator and pantry:

{$itemSummary}
{$diabeticSection}
{$prefSection}
{$prepSection}
{$mealSection}
{$customSection}

CRITICAL DIRECTIVES FOR RECIPE GENERATION:
1. HIGHEST PRIORITY: You MUST create recipes that incorporate as many EXPIRING SOON items (expiring in <= 3 days) as possible to prevent food waste!
2. Create 5 to 6 distinct, appetizing, diverse recipes covering all meal categories (including Breakfast, Lunch, Dinner, and Quick Snacks/Appetizers) so the user has options for every meal planner slot. Ensure appropriate tags like 'Breakfast', 'Lunch', 'Dinner', or 'Snack' AND the specific Cuisine style (e.g. 'Italian', 'Mexican', 'Japanese', 'American') are included in dietaryTags.
3. If Diabetic profile is enabled, mark recipes clearly with \"Diabetic Friendly\", \"Low GI\", or \"Keto\" tags and ensure they don't cause blood sugar spikes.
4. While most recipes should prioritize existing inventory, YOU MUST INCLUDE at least 1-2 aspirational recipes that require ENTIRELY NEW ingredients not currently in the user's inventory to inspire new grocery purchases and meals. List these missing ingredients clearly.
5. Calculate a wasteSavingTip highlighting how much money or food this recipe saves.
6. {$excludeSection}
7. MANDATORY QUANTITY REQUIREMENT: Provide the exact required measurement/amount (e.g. '2 cups', '1 tbsp', '200g') for every item used in the recipe. Include a usedIngredientAmounts object mapping each used item name to its required amount.";

        $contents = array(
            array(
                'parts' => array(
                    array( 'text' => $prompt ),
                ),
            ),
        );

        $item_names = array_map(function($i) { return is_array($i) && isset($i['name']) ? trim($i['name']) : (is_string($i) ? trim($i) : ''); }, $items);
        $item_names = array_values( array_unique( array_filter( $item_names ) ) );
        if ( empty( $item_names ) ) {
            $item_names = array( '' );
        }

        $schema = array(
            'type'       => 'OBJECT',
            'properties' => array(
                'recipes' => array(
                    'type'  => 'ARRAY',
                    'items' => array(
                        'type'       => 'OBJECT',
                        'properties' => array(
                            'id'                 => array( 'type' => 'STRING' ),
                            'title'              => array( 'type' => 'STRING' ),
                            'description'        => array( 'type' => 'STRING' ),
                            'prepTime'           => array( 'type' => 'STRING' ),
                            'cookTime'           => array( 'type' => 'STRING' ),
                            'difficulty'         => array( 'type' => 'STRING', 'enum' => array('Easy', 'Medium', 'Advanced') ),
                            'usedExpiringItems'  => array(
                                'type'  => 'ARRAY',
                                'items' => array( 'type' => 'STRING', 'enum' => $item_names ),
                            ),
                            'otherUsedItems'     => array(
                                'type'  => 'ARRAY',
                                'items' => array( 'type' => 'STRING', 'enum' => $item_names ),
                            ),
                            'missingIngredients' => array(
                                'type'  => 'ARRAY',
                                'items' => array(
                                    'type'       => 'OBJECT',
                                    'properties' => array(
                                        'name'     => array( 'type' => 'STRING' ),
                                        'amount'   => array( 'type' => 'STRING' ),
                                        'category' => array( 'type' => 'STRING' ),
                                    ),
                                    'required'   => array( 'name', 'amount' ),
                                ),
                            ),
                            'instructions'       => array(
                                'type'  => 'ARRAY',
                                'items' => array( 'type' => 'STRING' ),
                            ),
                            'dietaryTags'        => array(
                                'type'  => 'ARRAY',
                                'items' => array( 'type' => 'STRING' ),
                            ),
                            'caloriesPerServing' => array( 'type' => 'INTEGER' ),
                            'servings'           => array( 'type' => 'INTEGER' ),
                            'wasteSavingTip'     => array( 'type' => 'STRING' ),
                            'usedIngredientAmounts' => array(
                                'type'       => 'OBJECT',
                                'description' => 'Key-value map of used ingredient name to required amount string',
                            ),
                        ),
                        'required'   => array( 'id', 'title', 'description', 'prepTime', 'cookTime', 'difficulty', 'usedExpiringItems', 'otherUsedItems', 'missingIngredients', 'instructions', 'dietaryTags', 'caloriesPerServing', 'servings', 'wasteSavingTip' ),
                    ),
                ),
            ),
            'required'   => array( 'recipes' ),
        );

        $result = $this->call_gemini( $contents, $schema );
        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( array( 'error' => $result->get_error_message() ), $result->get_error_data()['status'] );
        }

        $recipes = isset( $result['recipes'] ) ? $result['recipes'] : array();

        // Backend validation for hallucinations and unique ID generation
        foreach ( $recipes as &$r ) {
            $r['id'] = 'recipe-ks-' . wp_generate_uuid4();
            $used_expiring = $r['usedExpiringItems'] ?? array();
            $other_used = $r['otherUsedItems'] ?? array();
            
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
        }

        return new WP_REST_Response( array( 'recipes' => $recipes ), 200 );
    }
}
