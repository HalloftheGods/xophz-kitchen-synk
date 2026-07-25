<?php

if ( ! defined( 'WPINC' ) ) {
	die;
}

trait Kitchen_Synk_API_Barcode_Trait {

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
}
