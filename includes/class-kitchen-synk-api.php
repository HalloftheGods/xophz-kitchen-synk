<?php
/**
 * REST API & Endpoint Handler for Xophz Kitchen Synk
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Kitchen_Synk_API {

    public function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function register_routes() {
        register_rest_route( 'kitchen-synk/v1', '/barcode-lookup', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'rest_barcode_lookup' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'kitchen-synk/v1', '/gemini/scan-barcode', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'rest_scan_barcode' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'kitchen-synk/v1', '/gemini/scan-pantry', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'rest_scan_pantry' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'kitchen-synk/v1', '/gemini/generate-recipes', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'rest_generate_recipes' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'kitchen-synk/v1', '/connectors/stripe', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'rest_get_stripe_keys' ),
                'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            ),
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'rest_save_stripe_keys' ),
                'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            ),
        ) );

        register_rest_route( 'kitchen-synk/v1', '/connectors/google-tag', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'rest_get_google_tag' ),
                'permission_callback' => '__return_true',
            ),
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'rest_save_google_tag' ),
                'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            ),
        ) );

        register_rest_route( 'kitchen-synk/v1', '/send-otp', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'rest_send_otp' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'kitchen-synk/v1', '/register-and-checkout', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'rest_register_and_checkout' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'kitchen-synk/v1', '/checkout/session', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'rest_create_checkout_session' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'kitchen-synk/v1', '/checkout/verify', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'rest_verify_checkout_session' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'kitchen-synk/v1', '/stripe/webhook', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'rest_stripe_webhook' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'kitchen-synk/v1', '/license', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'rest_get_license' ),
            'permission_callback' => 'is_user_logged_in',
        ) );
    }

    private function get_gemini_key() {
        $key = getenv( 'GEMINI_API_KEY' );
        if ( ! empty( $key ) ) {
            return $key;
        }
        if ( defined( 'GEMINI_API_KEY' ) && ! empty( GEMINI_API_KEY ) ) {
            return GEMINI_API_KEY;
        }
        return '';
    }

    private function call_gemini( $contents, $schema = null ) {
        $api_key = $this->get_gemini_key();
        if ( empty( $api_key ) ) {
            return new WP_Error( 'no_api_key', 'GEMINI_API_KEY is not configured on the server.', array( 'status' => 500 ) );
        }

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . $api_key;

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

    // --- REST Route Callbacks ---

    public function rest_barcode_lookup( WP_REST_Request $request ) {
        $params = $request->get_json_params();
        $barcode = isset( $params['barcode'] ) ? $params['barcode'] : '';
        return $this->handle_barcode_lookup( $barcode );
    }

    public function rest_scan_barcode( WP_REST_Request $request ) {
        $params = $request->get_json_params();
        return $this->handle_scan_barcode( $params );
    }

    public function rest_scan_pantry( WP_REST_Request $request ) {
        $params = $request->get_json_params();
        return $this->handle_scan_pantry( $params );
    }

    public function rest_generate_recipes( WP_REST_Request $request ) {
        $params = $request->get_json_params();
        return $this->handle_generate_recipes( $params );
    }

    // --- Core Logic Implementation ---

    public function handle_barcode_lookup( $barcode ) {
        if ( empty( $barcode ) || ! is_string( $barcode ) ) {
            return new WP_REST_Response( array( 'error' => 'Barcode string is required' ), 400 );
        }

        $cleanBarcode = preg_replace( '/[^0-9a-zA-Z]/', '', trim( $barcode ) );
        if ( empty( $cleanBarcode ) ) {
            return new WP_REST_Response( array( 'error' => 'Invalid barcode format' ), 400 );
        }

        $candidateBarcodes = array( $cleanBarcode );
        if ( strlen( $cleanBarcode ) === 12 ) {
            $candidateBarcodes[] = '0' . $cleanBarcode;
        } else if ( strlen( $cleanBarcode ) === 13 && strpos( $cleanBarcode, '0' ) === 0 ) {
            $candidateBarcodes[] = substr( $cleanBarcode, 1 );
        }

        $productData = null;
        $matchedBarcode = $cleanBarcode;

        foreach ( $candidateBarcodes as $code ) {
            $offUrl = "https://world.openfoodfacts.org/api/v2/product/{$code}.json";
            $res = wp_remote_get( $offUrl, array(
                'headers' => array( 'User-Agent' => 'KitchenSyncBarcodeLookup/1.0' ),
                'timeout' => 10,
            ) );

            if ( ! is_wp_error( $res ) && wp_remote_retrieve_response_code( $res ) === 200 ) {
                $json = json_decode( wp_remote_retrieve_body( $res ), true );
                if ( isset( $json['status'] ) && $json['status'] === 1 && ! empty( $json['product'] ) ) {
                    $productData = $json['product'];
                    $matchedBarcode = $code;
                    break;
                }
            }
        }

        if ( ! $productData ) {
            $searchUrl = "https://world.openfoodfacts.org/cgi/search.pl?search_terms={$cleanBarcode}&search_simple=1&action=process&json=1";
            $searchRes = wp_remote_get( $searchUrl, array(
                'headers' => array( 'User-Agent' => 'KitchenSyncBarcodeLookup/1.0' ),
                'timeout' => 10,
            ) );

            if ( ! is_wp_error( $searchRes ) && wp_remote_retrieve_response_code( $searchRes ) === 200 ) {
                $searchJson = json_decode( wp_remote_retrieve_body( $searchRes ), true );
                if ( ! empty( $searchJson['products'] ) && is_array( $searchJson['products'] ) ) {
                    $productData = $searchJson['products'][0];
                }
            }
        }

        if ( ! $productData ) {
            return new WP_REST_Response( array(
                'found'   => false,
                'barcode' => $cleanBarcode,
                'message' => "Barcode {$cleanBarcode} was not found in the Open Food Facts global database. You can manually enter the product details below.",
                'source'  => 'Open Food Facts Global Database',
            ), 200 );
        }

        $productName = isset( $productData['product_name_en'] ) ? $productData['product_name_en'] : ( isset( $productData['product_name'] ) ? $productData['product_name'] : "Product {$cleanBarcode}" );
        $brand = isset( $productData['brands'] ) ? $productData['brands'] : ( isset( $productData['brand_owner'] ) ? $productData['brand_owner'] : '' );
        $categoriesRaw = strtolower( isset( $productData['categories'] ) ? $productData['categories'] : '' );

        $quantityRaw = isset( $productData['quantity'] ) ? $productData['quantity'] : '1';
        $imageUrl = isset( $productData['image_front_small_url'] ) ? $productData['image_front_small_url'] : ( isset( $productData['image_url'] ) ? $productData['image_url'] : null );
        $ingredients = isset( $productData['ingredients_text_en'] ) ? $productData['ingredients_text_en'] : ( isset( $productData['ingredients_text'] ) ? $productData['ingredients_text'] : '' );
        $nutriscore = isset( $productData['nutriscore_grade'] ) ? strtoupper( $productData['nutriscore_grade'] ) : null;

        $category = 'Pantry';
        if ( strpos( $categoriesRaw, 'frozen' ) !== false || strpos( $categoriesRaw, 'ice cream' ) !== false || strpos( $categoriesRaw, 'freezer' ) !== false ) {
            $category = 'Freezer';
        } else if ( strpos( $categoriesRaw, 'dairy' ) !== false || strpos( $categoriesRaw, 'milk' ) !== false || strpos( $categoriesRaw, 'cheese' ) !== false || strpos( $categoriesRaw, 'yogurt' ) !== false || strpos( $categoriesRaw, 'fresh' ) !== false || strpos( $categoriesRaw, 'meat' ) !== false || strpos( $categoriesRaw, 'juice' ) !== false ) {
            $category = 'Fridge';
        }

        $estimatedDaysToExpire = 14;
        if ( $category === 'Freezer' ) {
            $estimatedDaysToExpire = 120;
        } else if ( strpos( $categoriesRaw, 'milk' ) !== false ) {
            $estimatedDaysToExpire = 9;
        } else if ( strpos( $categoriesRaw, 'yogurt' ) !== false ) {
            $estimatedDaysToExpire = 18;
        } else if ( strpos( $categoriesRaw, 'cheese' ) !== false ) {
            $estimatedDaysToExpire = 30;
        } else if ( strpos( $categoriesRaw, 'meat' ) !== false || strpos( $categoriesRaw, 'fish' ) !== false || strpos( $categoriesRaw, 'poultry' ) !== false ) {
            $estimatedDaysToExpire = 3;
        } else if ( strpos( $categoriesRaw, 'bread' ) !== false ) {
            $estimatedDaysToExpire = 6;
        } else if ( strpos( $categoriesRaw, 'canned' ) !== false ) {
            $estimatedDaysToExpire = 180;
        } else if ( strpos( $categoriesRaw, 'pasta' ) !== false || strpos( $categoriesRaw, 'rice' ) !== false ) {
            $estimatedDaysToExpire = 365;
        }

        $isStaple = (
            strpos( $categoriesRaw, 'milk' ) !== false ||
            strpos( $categoriesRaw, 'egg' ) !== false ||
            strpos( $categoriesRaw, 'bread' ) !== false ||
            strpos( $categoriesRaw, 'butter' ) !== false ||
            strpos( $categoriesRaw, 'rice' ) !== false ||
            strpos( $categoriesRaw, 'pasta' ) !== false ||
            strpos( $categoriesRaw, 'oil' ) !== false ||
            strpos( $categoriesRaw, 'flour' ) !== false
        );

        $fullTitle = $brand ? "{$brand} {$productName}" : $productName;

        return new WP_REST_Response( array(
            'found'                 => true,
            'barcode'               => $matchedBarcode,
            'name'                  => $fullTitle,
            'brand'                 => $brand,
            'category'              => $category,
            'estimatedQuantity'     => $quantityRaw,
            'unit'                  => 'item',
            'estimatedDaysToExpire' => $estimatedDaysToExpire,
            'isStaple'              => $isStaple,
            'imageUrl'              => $imageUrl,
            'ingredients'           => $ingredients,
            'nutriscore'            => $nutriscore,
            'categoriesText'        => isset( $productData['categories'] ) ? $productData['categories'] : '',
            'source'                => 'Open Food Facts Database',
        ), 200 );
    }

    public function handle_scan_barcode( $params ) {
        $barcode = isset( $params['barcode'] ) ? $params['barcode'] : '';
        $imageBase64 = isset( $params['imageBase64'] ) ? $params['imageBase64'] : '';

        if ( empty( $barcode ) && empty( $imageBase64 ) ) {
            return new WP_REST_Response( array( 'error' => 'Provide either a barcode number or an image' ), 400 );
        }

        $contents = array();
        if ( ! empty( $imageBase64 ) ) {
            $cleanBase64 = preg_replace( '/^data:image\/\w+;base64,/', '', $imageBase64 );
            $contents[] = array(
                'parts' => array(
                    array(
                        'inlineData' => array(
                            'mimeType' => 'image/jpeg',
                            'data'     => $cleanBase64,
                        ),
                    ),
                    array(
                        'text' => 'Scan this image for product barcode numbers or item packaging text. Identify the product name, brand, storage location (Fridge, Pantry, Freezer), typical quantity/unit, estimated days to expire, and whether it is a kitchen staple.',
                    ),
                ),
            );
        } else {
            $contents[] = array(
                'parts' => array(
                    array(
                        'text' => "Look up food item information for barcode UPC/EAN code: \"{$barcode}\". Identify Product full name, brand, Storage Category (Fridge, Pantry, Freezer), typical quantity, unit, estimated shelf life in days, and staple boolean.",
                    ),
                ),
            );
        }

        $schema = array(
            'type'       => 'OBJECT',
            'properties' => array(
                'name'                  => array( 'type' => 'STRING' ),
                'brand'                 => array( 'type' => 'STRING' ),
                'category'              => array( 'type' => 'STRING' ),
                'estimatedQuantity'     => array( 'type' => 'STRING' ),
                'unit'                  => array( 'type' => 'STRING' ),
                'estimatedDaysToExpire' => array( 'type' => 'INTEGER' ),
                'isStaple'              => array( 'type' => 'BOOLEAN' ),
                'nutritionHighlights'   => array( 'type' => 'STRING' ),
                'barcodeFound'          => array( 'type' => 'STRING' ),
            ),
            'required'   => array( 'name', 'category', 'estimatedQuantity', 'unit', 'estimatedDaysToExpire', 'isStaple' ),
        );

        $result = $this->call_gemini( $contents, $schema );
        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( array( 'error' => $result->get_error_message() ), $result->get_error_data()['status'] );
        }

        return new WP_REST_Response( $result, 200 );
    }

    public function handle_scan_pantry( $params ) {
        $imageBase64 = isset( $params['imageBase64'] ) ? $params['imageBase64'] : '';
        $notes = isset( $params['notes'] ) ? $params['notes'] : '';

        if ( empty( $imageBase64 ) ) {
            return new WP_REST_Response( array( 'error' => 'No image provided' ), 400 );
        }

        $cleanBase64 = preg_replace( '/^data:image\/\w+;base64,/', '', $imageBase64 );
        $prompt = "Analyze this image of food items, refrigerator shelves, or pantry contents. Identify all visible ingredients, produce, packaged items, or condiments.\n" . ( $notes ? "User context: \"{$notes}\"" : "" );

        $contents = array(
            array(
                'parts' => array(
                    array(
                        'inlineData' => array(
                            'mimeType' => 'image/jpeg',
                            'data'     => $cleanBase64,
                        ),
                    ),
                    array( 'text' => $prompt ),
                ),
            ),
        );

        $schema = array(
            'type'       => 'OBJECT',
            'properties' => array(
                'items' => array(
                    'type'  => 'ARRAY',
                    'items' => array(
                        'type'       => 'OBJECT',
                        'properties' => array(
                            'name'                  => array( 'type' => 'STRING' ),
                            'category'              => array( 'type' => 'STRING' ),
                            'estimatedQuantity'     => array( 'type' => 'STRING' ),
                            'unit'                  => array( 'type' => 'STRING' ),
                            'estimatedDaysToExpire' => array( 'type' => 'INTEGER' ),
                            'isStaple'              => array( 'type' => 'BOOLEAN' ),
                            'confidenceScore'       => array( 'type' => 'NUMBER' ),
                            'notes'                 => array( 'type' => 'STRING' ),
                        ),
                        'required'   => array( 'name', 'category', 'estimatedQuantity', 'unit', 'estimatedDaysToExpire', 'isStaple' ),
                    ),
                ),
            ),
            'required'   => array( 'items' ),
        );

        $result = $this->call_gemini( $contents, $schema );
        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( array( 'error' => $result->get_error_message() ), $result->get_error_data()['status'] );
        }

        return new WP_REST_Response( array( 'items' => isset( $result['items'] ) ? $result['items'] : array() ), 200 );
    }

    public function handle_generate_recipes( $params ) {
        $items = isset( $params['items'] ) ? $params['items'] : array();
        $dietaryPreferences = isset( $params['dietaryPreferences'] ) ? $params['dietaryPreferences'] : array();
        $maxPrepTime = isset( $params['maxPrepTime'] ) ? $params['maxPrepTime'] : null;
        $mealType = isset( $params['mealType'] ) ? $params['mealType'] : null;
        $customRequest = isset( $params['customRequest'] ) ? $params['customRequest'] : '';
        $userProfile = isset( $params['userProfile'] ) ? $params['userProfile'] : null;

        if ( empty( $items ) || ! is_array( $items ) ) {
            return new WP_REST_Response( array( 'error' => 'No inventory items provided for recipe creation' ), 400 );
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
2. Create 3 distinct, appetizing, realistic recipes that can be made primarily with the user's available ingredients.
3. If Diabetic profile is enabled, mark recipes clearly with \"Diabetic Friendly\", \"Low GI\", or \"Keto\" tags and ensure they don't cause blood sugar spikes.
4. Identify which ingredients from user inventory are used, and if any extra minor staple ingredients are missing, list them in missingIngredients.
5. Calculate a wasteSavingTip highlighting how much money or food this recipe saves.";

        $contents = array(
            array(
                'parts' => array(
                    array( 'text' => $prompt ),
                ),
            ),
        );

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
                            'difficulty'         => array( 'type' => 'STRING' ),
                            'usedExpiringItems'  => array(
                                'type'  => 'ARRAY',
                                'items' => array( 'type' => 'STRING' ),
                            ),
                            'otherUsedItems'     => array(
                                'type'  => 'ARRAY',
                                'items' => array( 'type' => 'STRING' ),
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
                        ),
                        'required'   => array( 'id', 'title', 'description', 'prepTime', 'cookTime', 'difficulty', 'usedExpiringItems', 'instructions', 'wasteSavingTip' ),
                    ),
                ),
            ),
            'required'   => array( 'recipes' ),
        );

        $result = $this->call_gemini( $contents, $schema );
        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( array( 'error' => $result->get_error_message() ), $result->get_error_data()['status'] );
        }

        return new WP_REST_Response( array( 'recipes' => isset( $result['recipes'] ) ? $result['recipes'] : array() ), 200 );
    }

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

    public function rest_send_otp( WP_REST_Request $request ) {
        $params = $request->get_json_params();
        $email  = sanitize_email( $params['email'] ?? '' );

        if ( empty( $email ) || ! is_email( $email ) ) {
            return new WP_Error( 'invalid_email', 'Valid email address is required.', array( 'status' => 400 ) );
        }

        if ( email_exists( $email ) ) {
            return new WP_Error( 'email_exists', 'An account with this email address already exists. Please log in.', array( 'status' => 400 ) );
        }

        $base_username = sanitize_user( current( explode( '@', $email ) ) );
        if ( empty( $base_username ) ) {
            $base_username = 'chef_' . wp_rand( 1000, 9999 );
        }

        $suggested_username = $base_username;
        if ( username_exists( $suggested_username ) ) {
            $suggested_username .= '_' . wp_rand( 10, 999 );
        }

        $otp = sprintf( '%06d', wp_rand( 100000, 999999 ) );
        $transient_key = 'ks_otp_' . md5( strtolower( $email ) );

        set_transient( $transient_key, array(
            'otp'      => $otp,
            'username' => $suggested_username,
            'email'    => $email,
        ), 15 * MINUTE_IN_SECONDS );

        wp_mail( $email, 'Kitchen Synk Verification Code', "Your Kitchen Synk verification code is: {$otp}" );

        return rest_ensure_response( array(
            'success'            => true,
            'message'            => 'Verification code sent to your email address.',
            'suggested_username' => $suggested_username,
            'debug_otp'          => ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? $otp : null,
        ) );
    }

    public function rest_register_and_checkout( WP_REST_Request $request ) {
        $params   = $request->get_json_params();
        $email    = sanitize_email( $params['email'] ?? '' );
        $otp      = sanitize_text_field( $params['otp'] ?? '' );
        $username = sanitize_user( $params['username'] ?? '' );
        $password = $params['password'] ?? '';
        $name     = sanitize_text_field( $params['name'] ?? '' );
        $tier     = sanitize_text_field( $params['tier'] ?? 'starter' );
        $price_id = sanitize_text_field( $params['price_id'] ?? '' );

        if ( empty( $email ) || ! is_email( $email ) ) {
            return new WP_Error( 'invalid_email', 'Valid email address is required.', array( 'status' => 400 ) );
        }

        if ( empty( $otp ) ) {
            return new WP_Error( 'missing_otp', 'Verification code (OTP) is required.', array( 'status' => 400 ) );
        }

        $transient_key = 'ks_otp_' . md5( strtolower( $email ) );
        $stored = get_transient( $transient_key );

        if ( ! $stored || empty( $stored['otp'] ) || (string) $stored['otp'] !== (string) $otp ) {
            return new WP_Error( 'invalid_otp', 'Invalid or expired verification code. Please check code or resend.', array( 'status' => 400 ) );
        }

        delete_transient( $transient_key );

        if ( email_exists( $email ) ) {
            return new WP_Error( 'email_exists', 'An account with this email address already exists.', array( 'status' => 400 ) );
        }

        if ( empty( $username ) ) {
            $username = $stored['username'] ?? sanitize_user( current( explode( '@', $email ) ) );
        }
        if ( username_exists( $username ) ) {
            $username .= '_' . wp_rand( 100, 999 );
        }

        if ( empty( $password ) ) {
            $password = wp_generate_password( 16, true );
        }

        $user_id = wp_create_user( $username, $password, $email );
        if ( is_wp_error( $user_id ) ) {
            return $user_id;
        }

        $display_name = ! empty( $name ) ? $name : $username;
        wp_update_user( array(
            'ID'           => $user_id,
            'display_name' => $display_name,
            'first_name'   => $display_name,
        ) );

        if ( $tier === 'starter' || $tier === 'free' || empty( $price_id ) ) {
            if ( class_exists( 'Xophz_Compass_Golden_Keys_API' ) ) {
                Xophz_Compass_Golden_Keys_API::generate_license_key( $user_id, 'starter' );
            }
            wp_set_current_user( $user_id );
            wp_set_auth_cookie( $user_id, true );

            return rest_ensure_response( array(
                'success'      => true,
                'status'       => 'registered',
                'user_id'      => $user_id,
                'user'         => array(
                    'id'    => $user_id,
                    'name'  => $display_name,
                    'email' => $email,
                ),
                'redirect_url' => home_url( '/kitchen-synk/#/inventory?welcome=1' ),
            ) );
        }

        $session = $this->create_stripe_checkout_session( $user_id, $email, $tier, $price_id );
        if ( is_wp_error( $session ) ) {
            return $session;
        }

        return rest_ensure_response( array(
            'success'      => true,
            'status'       => 'checkout_required',
            'user_id'      => $user_id,
            'checkout_url' => $session['url'],
            'session_id'   => $session['id'],
        ) );
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

        $user_id   = $session['client_reference_id'] ?? $session['metadata']['wp_user_id'] ?? null;
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
            if ( class_exists( 'Xophz_Compass_Golden_Keys_API' ) ) {
                Xophz_Compass_Golden_Keys_API::generate_license_key( $user_id, $user_type );
            }

            wp_set_current_user( $user_id );
            wp_set_auth_cookie( $user_id, true );

            $user_obj = get_userdata( $user_id );
            return rest_ensure_response( array(
                'success' => true,
                'nonce'   => wp_create_nonce( 'wp_rest' ),
                'user'    => array(
                    'isLoggedIn' => true,
                    'id'         => $user_id,
                    'name'       => $user_obj ? $user_obj->display_name : 'User',
                    'email'      => $user_obj ? $user_obj->user_email : $email,
                    'tier'       => $user_type,
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
            $user_id   = $data['client_reference_id'] ?? $data['metadata']['wp_user_id'] ?? null;
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
                if ( class_exists( 'Xophz_Compass_Golden_Keys_API' ) ) {
                    Xophz_Compass_Golden_Keys_API::generate_license_key( $user_id, $user_type );
                }
            }
        } elseif ( $type === 'customer.subscription.deleted' ) {
            $user_id = $data['metadata']['wp_user_id'] ?? null;
            if ( $user_id ) {
                update_user_meta( $user_id, 'kitchensynk_user_type', 'starter' );
                update_user_meta( $user_id, 'kitchensynk_subscription_status', 'canceled' );
                delete_user_meta( $user_id, 'kitchensynk_stripe_subscription_id' );
            }
        }

        return rest_ensure_response( array( 'received' => true ) );
    }

    public function rest_get_license( WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        $license = get_user_meta( $user_id, 'xophz_golden_license', true );
        $tier    = get_user_meta( $user_id, 'kitchensynk_user_type', true ) ?: 'starter';

        return rest_ensure_response( array(
            'has_license' => ! empty( $license ),
            'tier'        => $tier,
            'license'     => $license ?: null,
        ) );
    }
}
