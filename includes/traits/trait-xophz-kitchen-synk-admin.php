<?php

if ( ! defined( 'WPINC' ) ) {
	die;
}

trait Xophz_Kitchen_Synk_Admin {

    public function add_plugin_admin_menu() {
        add_options_page(
            'Kitchen Synk Settings',
            'Kitchen Synk',
            'manage_options',
            'xophz-kitchen-synk',
            array( $this, 'display_plugin_setup_page' )
        );
    }

    public function register_settings() {
        register_setting( 'xophz_kitchen_synk_options', 'xophz_kitchen_synk_load_mode', array(
            'type'              => 'string',
            'default'           => 'custom_slug',
            'sanitize_callback' => array( $this, 'sanitize_load_mode' ),
        ) );

        register_setting( 'xophz_kitchen_synk_options', 'xophz_kitchen_synk_load_page_id', array(
            'type'              => 'integer',
            'default'           => 0,
            'sanitize_callback' => 'absint',
        ) );

        register_setting( 'xophz_kitchen_synk_options', 'xophz_kitchen_synk_custom_slug', array(
            'type'              => 'string',
            'default'           => 'kitchen-synk',
            'sanitize_callback' => 'sanitize_title',
        ) );

        register_setting( 'xophz_kitchen_synk_options', 'xophz_kitchen_synk_show_admin_bar', array(
            'type'              => 'boolean',
            'default'           => true,
            'sanitize_callback' => 'rest_sanitize_boolean',
        ) );
    }

    public function sanitize_load_mode( $value ) {
        $valid_modes = array( 'custom_slug', 'homepage', 'specific_page' );
        return in_array( $value, $valid_modes, true ) ? $value : 'custom_slug';
    }

    /**
     * Add Quick Access dropdown menu to WordPress Admin Bar.
     */
    public function add_admin_bar_button( $wp_admin_bar ) {
        $show_admin_bar = get_option( 'xophz_kitchen_synk_show_admin_bar', true );
        if ( ! $show_admin_bar ) {
            return;
        }

        $base_url = $this->get_kitchen_synk_base_url();
        $prod_url = home_url( '/kitchen-synk-prod/' );

        $wp_admin_bar->add_node( array(
            'id'    => 'kitchen-synk-menu',
            'title' => '<span class="ab-icon dashicons dashicons-carrot" style="top:2px;"></span><span class="ab-label">Kitchen Synk</span>',
            'href'  => $base_url,
            'meta'  => array(
                'title' => 'Kitchen Synk App',
            ),
        ) );

        $wp_admin_bar->add_node( array(
            'parent' => 'kitchen-synk-menu',
            'id'     => 'kitchen-synk-app',
            'title'  => '🍳 Open App (Dev)',
            'href'   => $base_url,
        ) );

        $wp_admin_bar->add_node( array(
            'parent' => 'kitchen-synk-menu',
            'id'     => 'kitchen-synk-app-prod',
            'title'  => '📦 Open App (Prod Static)',
            'href'   => $prod_url,
        ) );

        $wp_admin_bar->add_node( array(
            'parent' => 'kitchen-synk-menu',
            'id'     => 'kitchen-synk-inventory',
            'title'  => '📦 Inventory',
            'href'   => $base_url . '#inventory',
        ) );

        $wp_admin_bar->add_node( array(
            'parent' => 'kitchen-synk-menu',
            'id'     => 'kitchen-synk-recipes',
            'title'  => '👨‍🍳 AI Recipes',
            'href'   => $base_url . '#recipes',
        ) );

        $wp_admin_bar->add_node( array(
            'parent' => 'kitchen-synk-menu',
            'id'     => 'kitchen-synk-scanner',
            'title'  => '📷 Barcode Scanner',
            'href'   => $base_url . '#scanner',
        ) );

        $wp_admin_bar->add_node( array(
            'parent' => 'kitchen-synk-menu',
            'id'     => 'kitchen-synk-grocery',
            'title'  => '🛒 Grocery List',
            'href'   => $base_url . '#grocery',
        ) );

        $wp_admin_bar->add_node( array(
            'parent' => 'kitchen-synk-menu',
            'id'     => 'kitchen-synk-settings',
            'title'  => '⚙️ WP Settings',
            'href'   => admin_url( 'options-general.php?page=xophz-kitchen-synk' ),
        ) );
    }

    public function display_plugin_setup_page() {
        $load_mode   = get_option( 'xophz_kitchen_synk_load_mode', 'custom_slug' );
        $page_id     = (int) get_option( 'xophz_kitchen_synk_load_page_id', 0 );
        $slug        = get_option( 'xophz_kitchen_synk_custom_slug', 'kitchen-synk' );
        $show_bar    = get_option( 'xophz_kitchen_synk_show_admin_bar', true );
        $is_dev      = $this->is_dev_mode();
        $app_url     = $this->get_kitchen_synk_base_url();
        $prod_app_url = home_url( '/kitchen-synk-prod/' );
        ?>
        <div class="wrap">
            <h1>🍳 Kitchen Synk Settings</h1>
            <p class="description">Configure how and where the Kitchen Synk application is served across your WordPress site.</p>

            <form method="post" action="options.php" style="margin-top: 20px;">
                <?php settings_fields( 'xophz_kitchen_synk_options' ); ?>

                <div class="card" style="max-width: 800px; padding: 20px; margin-bottom: 20px; border-radius: 8px;">
                    <h2 style="margin-top:0;">Deployment & Homepage Mode</h2>
                    <p>Choose where Kitchen Synk should be rendered on your site.</p>

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">Load Mode</th>
                            <td>
                                <fieldset>
                                    <label style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
                                        <input type="radio" name="xophz_kitchen_synk_load_mode" value="custom_slug" <?php checked( $load_mode, 'custom_slug' ); ?> />
                                        <strong>Custom Slug</strong> — Load on a specific URL path
                                    </label>
                                    <div id="slug_input_container" style="margin-left: 28px; margin-bottom: 16px;">
                                        <code><?php echo esc_url( home_url( '/' ) ); ?></code>
                                        <input type="text" id="xophz_kitchen_synk_custom_slug" name="xophz_kitchen_synk_custom_slug" value="<?php echo esc_attr( $slug ); ?>" class="regular-text" placeholder="kitchen-synk" style="width: 180px;" />
                                        <code>/</code>
                                        <p class="description">Default is <code>kitchen-synk</code> (e.g. <code>/kitchen-synk</code>).</p>
                                    </div>

                                    <label style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
                                        <input type="radio" name="xophz_kitchen_synk_load_mode" value="homepage" <?php checked( $load_mode, 'homepage' ); ?> />
                                        <strong>Homepage Mode</strong> — Set Kitchen Synk as the site's main front page (<code>/</code>)
                                    </label>
                                    <p class="description" style="margin-left: 28px; margin-bottom: 16px;">
                                        Replaces your WordPress landing page with the complete Kitchen Synk experience.
                                    </p>

                                    <label style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
                                        <input type="radio" name="xophz_kitchen_synk_load_mode" value="specific_page" <?php checked( $load_mode, 'specific_page' ); ?> />
                                        <strong>Target Page</strong> — Load on a specific WordPress Page
                                    </label>
                                    <div id="page_dropdown_container" style="margin-left: 28px; margin-bottom: 16px;">
                                        <?php
                                        wp_dropdown_pages( array(
                                            'name'              => 'xophz_kitchen_synk_load_page_id',
                                            'id'                => 'xophz_kitchen_synk_load_page_id',
                                            'selected'          => $page_id,
                                            'show_option_none'  => '— Select a Page —',
                                            'option_none_value' => '0',
                                        ) );
                                        ?>
                                        <p class="description">App will replace the chosen page content.</p>
                                    </div>
                                </fieldset>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="card" style="max-width: 800px; padding: 20px; margin-bottom: 20px; border-radius: 8px;">
                    <h2 style="margin-top:0;">Admin Bar Quick Links</h2>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">WordPress Admin Bar</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="xophz_kitchen_synk_show_admin_bar" value="1" <?php checked( $show_bar, true ); ?> />
                                    Show 🍳 <strong>Kitchen Synk</strong> menu in the WordPress Admin Bar for quick access to Inventory, Recipes, Scanner, etc.
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="card" style="max-width: 800px; padding: 20px; margin-bottom: 20px; border-radius: 8px; background: #fafafa;">
                    <h2 style="margin-top:0;">System & Integration Info</h2>
                    <ul style="line-height: 1.8;">
                        <li><strong>Active Portal Endpoint:</strong> <a href="<?php echo esc_url( $app_url ); ?>" target="_blank"><code><?php echo esc_html( $app_url ); ?></code></a></li>
                        <li><strong>Local Production Test Endpoint:</strong> <a href="<?php echo esc_url( $prod_app_url ); ?>" target="_blank"><code><?php echo esc_html( $prod_app_url ); ?></code></a></li>
                        <li><strong>Server Environment:</strong> <?php echo $is_dev ? '<span style="color:#d97706;font-weight:bold;">⚡ Vite Dev Mode (Port 8084)</span>' : '<span style="color:#16a34a;font-weight:bold;">📦 Production Static Bundle</span>'; ?></li>
                        <li><strong>REST API Namespace:</strong> <code><?php echo esc_url( rest_url( 'kitchen-synk/v1/' ) ); ?></code></li>
                    </ul>
                </div>

                <?php submit_button( 'Save Kitchen Synk Settings' ); ?>
            </form>
        </div>

        <script>
        (function() {
            const radios = document.querySelectorAll('input[name="xophz_kitchen_synk_load_mode"]');
            const slugContainer = document.getElementById('slug_input_container');
            const pageContainer = document.getElementById('page_dropdown_container');

            function updateVisibility() {
                const selected = document.querySelector('input[name="xophz_kitchen_synk_load_mode"]:checked');
                const val = selected ? selected.value : 'custom_slug';

                if (slugContainer) {
                    slugContainer.style.display = val === 'custom_slug' ? 'block' : 'none';
                }
                if (pageContainer) {
                    pageContainer.style.display = val === 'specific_page' ? 'block' : 'none';
                }
            }

            radios.forEach(r => r.addEventListener('change', updateVisibility));
            updateVisibility();
        })();
        </script>
        <?php
    }
}
