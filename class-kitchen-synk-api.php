<?php

if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once XOPHZ_KITCHEN_SYNK_PATH . 'includes/traits/trait-xophz-kitchen-synk-api-auth.php';
require_once XOPHZ_KITCHEN_SYNK_PATH . 'includes/traits/trait-xophz-kitchen-synk-api-ai.php';
require_once XOPHZ_KITCHEN_SYNK_PATH . 'includes/traits/trait-xophz-kitchen-synk-api-recipes.php';

class Xophz_Kitchen_Synk_API {
    use Xophz_Kitchen_Synk_API_Auth;
    use Xophz_Kitchen_Synk_API_AI;
    use Xophz_Kitchen_Synk_API_Recipes;

    public function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
        add_action( 'init', array( $this, 'register_post_types' ) );
    }

    public function register_routes() {
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

        register_rest_route( 'kitchen-synk/v1', '/quota', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_ai_quota' ),
            'permission_callback' => '__return_true',
        ) );
    }

    public function register_post_types() {
        register_post_type( 'ks_saved_recipe', array(
            'labels' => array(
                'name'               => 'Saved Recipes',
                'singular_name'      => 'Saved Recipe',
                'menu_name'          => 'Saved Recipes',
                'name_admin_bar'     => 'Saved Recipe',
                'add_new'            => 'Add New',
                'add_new_item'       => 'Add New Saved Recipe',
                'new_item'           => 'New Saved Recipe',
                'edit_item'          => 'Edit Saved Recipe',
                'view_item'          => 'View Saved Recipe',
                'all_items'          => 'All Saved Recipes',
                'search_items'       => 'Search Saved Recipes',
                'not_found'          => 'No saved recipes found.',
                'not_found_in_trash' => 'No saved recipes found in Trash.',
            ),
            'public'              => true,
            'show_ui'             => true,
            'show_in_menu'        => 'xophz-kitchen-synk',
            'show_in_rest'        => true,
            'rest_base'           => 'ks-saved-recipes',
            'supports'            => array( 'title', 'editor', 'thumbnail', 'author', 'custom-fields' ),
            'has_archive'         => true,
            'exclude_from_search' => false,
            'publicly_queryable'  => true,
        ) );

        register_post_type( 'ks_suggested_meal', array(
            'labels' => array(
                'name'               => 'Suggested Meals',
                'singular_name'      => 'Suggested Meal',
                'menu_name'          => 'Suggested Meals',
                'name_admin_bar'     => 'Suggested Meal',
                'add_new'            => 'Add New',
                'add_new_item'       => 'Add New Suggested Meal',
                'new_item'           => 'New Suggested Meal',
                'edit_item'          => 'Edit Suggested Meal',
                'view_item'          => 'View Suggested Meal',
                'all_items'          => 'All Suggested Meals',
                'search_items'       => 'Search Suggested Meals',
                'not_found'          => 'No suggested meals found.',
                'not_found_in_trash' => 'No suggested meals found in Trash.',
            ),
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => 'xophz-kitchen-synk',
            'show_in_rest'        => false,
            'supports'            => array( 'title', 'editor', 'thumbnail', 'author', 'custom-fields' ),
            'has_archive'         => false,
            'exclude_from_search' => true,
            'publicly_queryable'  => false,
        ) );
    }
}
new Xophz_Kitchen_Synk_API();
