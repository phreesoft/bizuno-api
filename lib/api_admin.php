<?php
/**
 * Bizuno API WordPress Plugin - admin class
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * DISCLAIMER
 * Do not edit or add to this file if you wish to upgrade Bizuno to newer
 * versions in the future. If you wish to customize Bizuno for your
 * needs please contact PhreeSoft for more information.
 *
 * @name       Bizuno ERP
 * @author     Dave Premo, PhreeSoft <support@phreesoft.com>
 * @copyright  2008-2026, PhreeSoft, Inc.
 * @license    https://www.gnu.org/licenses/agpl-3.0.txt
 * @version    7.x Last Update: 2026-02-10
 * @filesource /lib/admin.php
 */

namespace bizuno;

if ( ! defined( 'ABSPATH' ) ) exit;

class api_admin extends api_common
{
    function __construct()
    {
        parent::__construct();
    }

    /*********************************************** Settings ************************************************/
    public function add_shipped_to_order_statuses( $order_statuses ) {
        $new_order_statuses = [];
        foreach ( $order_statuses as $key => $status ) {
            $new_order_statuses[ $key ] = $status;
            if ( 'wc-processing' === $key ) { $new_order_statuses['wc-shipped'] = 'Shipped'; }
        }
        return $new_order_statuses;
    }
    public function bizuno_api_order_column_header( $columns ) { // Legacy mode - Add column to order summary page
        $reordered_columns = [];
        foreach ( $columns as $key => $column ) {
            $reordered_columns[$key] = $column;
            if ( $key == 'order_status' ) { $reordered_columns['bizuno_download'] = __( 'Exported', 'bizuno-api'); }
        }
        return $reordered_columns;
    }
    public function bizuno_api_order_column_header_hpos ( $columns ) { // HPOS mode
        return $this->bizuno_api_order_column_header( $columns );
    }
    
    public function bizuno_api_order_column_content ( $column ) // $order
    {
        global $the_order; // the global order object
        if ($column == 'bizuno_download') {
            $exported = $the_order->get_meta( 'bizuno_order_exported', true );
            if (empty($exported)) { ?>
                <button type="button" class="order-status status-processing tips" data-tip=""><?php echo esc_html ( __( 'No', 'bizuno-api' ) ) ?></button>
                <?php
            } else { echo 'X&nbsp;'; }
        }
    }
    public function bizuno_api_order_column_content_hpos ( $column, $order ) {
        if ( 'bizuno_download' === $column ) {
            $exported= $order->get_meta( 'bizuno_order_exported', true );
            $status  = $order->get_status();
            if (empty($exported) && !in_array($status, ['cancelled', 'on-hold'])) {
?>
    <button type="button" class="order-status status-processing tips" data-tip="<?php echo \esc_html( $status ) ?>"><?php echo esc_html ( __( 'No', 'bizuno-api' ) ) ?></button>
<?php
            } else { echo \esc_html('&nbsp;'); }
        }
    }
    
    public function bizuno_api_order_preview_filter( $data, $order ) { // Add download button to Preview pop up
        $data['bizuno_order_exported'] = $order->get_meta('bizuno_order_exported', true, 'edit') ? 'none' : 'block';
        return $data;
    }
    public function bizuno_api_order_preview_action() {
        $url = admin_url( 'admin-ajax.php?action=bizuno_api_order_download' );
        echo '<span style="display:{{ data.bizuno_order_exported }}"><a class="button button-primary button-large" onClick="window.location = \''.esc_url ($url).'&biz_order_id={{ data.data.id }}\';">' . esc_html ( __( 'Export order to Bizuno', 'bizuno-api' ) ) . '</a></span>'."\n";
    }
    public function bizuno_api_add_order_meta_box_filter( $actions ) { // add download button to order edit page
        if (get_post_meta( get_the_ID(), 'bizuno_order_exported', true ) ) { return $actions; }
        $actions['bizuno_export_action'] = __('Export order to Bizuno', 'bizuno-api');
        return $actions;
    }

    public function bizuno_api_add_setting_submenu( ) {
        if ( defined( 'BIZUNO_FS_LIBRARY' ) && is_plugin_active ( "$this->bizLib/$this->bizLib.php" )) {
            add_menu_page( 'Bizuno', 'Bizuno', 'manage_options', 'bizuno', 'bizuno_html', 
                plugins_url( 'icon_16.png', WP_PLUGIN_DIR . "/$this->bizLib/$this->bizLib.php" ), 90);            
        } elseif ( !defined( 'BIZUNO_FS_LIBRARY' ) ) {
            add_menu_page( 'GET BIZUNO', 'GET BIZUNO', 'manage_options', 'get-bizuno', 'bizuno_api_get_html',
                plugins_url( 'icon_16.png', WP_PLUGIN_DIR . "/bizuno-api/bizuno-apip.php" ), 1);
        }
    }

    /****************************************************************************************************************************/
    // General tab - common to all Bizuno plugins (should be duplicated in all stand-alone plugins)
    /****************************************************************************************************************************/
    function bizuno_ensure_shared_menu_and_general_tab()
    {
        if ( defined( 'BIZUNO_MENU_ALREADY_CREATED' ) ) { return; }
        add_submenu_page( 'options-general.php', 'Bizuno Settings', 'Bizuno New', 'manage_options', BIZUNO_SETTINGS_PAGE_SLUG, [ $this, 'bizuno_render_shared_settings_page' ] );
        define( 'BIZUNO_MENU_ALREADY_CREATED', true );
    }

    public function bizuno_register_general_settings()
    {
        if ( defined( 'BIZUNO_GEN_SETTINGS_ALREADY_CREATED' ) ) { return; }
        register_setting( 'bizuno_general_options', 'bizuno_general_options', [ $this, 'bizuno_general_sanitize_options' ] );
        register_setting( 'bizuno_general_options', 'bizuno_general_options', [ $this, 'bizuno_sanitize_password' ] );
        add_settings_section( 'bizuno_general_section', 'General Settings', null, BIZUNO_SETTINGS_PAGE_SLUG );
        add_settings_field( 'bizuno_tax_rest_user_name', 'Username @PhreeSoft', [ $this, 'phreesoft_user_field_callback' ], BIZUNO_SETTINGS_PAGE_SLUG, 'bizuno_general_section' );
        add_settings_field( 'bizuno_tax_rest_user_pass', 'Password @PhreeSoft', [ $this, 'phreesoft_pass_field_callback' ], BIZUNO_SETTINGS_PAGE_SLUG, 'bizuno_general_section' );
        define( 'BIZUNO_GEN_SETTINGS_ALREADY_CREATED', true );
    }

    public function bizuno_general_sanitize_options( $input )
    {
        $old_options = get_option( 'bizuno_general_options', [] );
        $new_options = $old_options;
        if ( isset( $input['bizuno_tax_rest_user_name'] ) ) {
            $username = sanitize_user( trim( $input['bizuno_tax_rest_user_name'] ), true ); // true = strict mode
            if ( ! empty( $username ) ) {
                $new_options['bizuno_tax_rest_user_name'] = $username;
            } elseif ( empty( $input['bizuno_tax_rest_user_name'] ) && ! empty( $old_options['bizuno_tax_rest_user_name'] ) ) { // User cleared it
//              $new_options['bizuno_tax_rest_user_name'] remains old value
            } else {
                $new_options['bizuno_tax_rest_user_name'] = '';
            }
        }
        if ( isset( $input['bizuno_tax_rest_user_pass'] ) ) {
            $raw_pass = wp_unslash( trim( $input['bizuno_tax_rest_user_pass'] ) );
            if ( '' === $raw_pass ) { // Field left blank
//              $new_options['bizuno_tax_rest_user_pass'] remains old value
            } elseif ( strlen( $raw_pass ) < 8 ) { // Keep old password on validation failure
                add_settings_error( 'bizuno_general_options', 'password_too_short', __( 'Password @PhreeSoft must be at least 8 characters long.', 'bizuno-sales-tax' ), 'error' );
            } else {
                $new_options['bizuno_tax_rest_user_pass'] = $this->encrypt_password( $raw_pass );
            }
        }
        return $new_options;
    }

    public function phreesoft_user_field_callback()
    {
        $options = get_option( 'bizuno_general_options', [] );
        $value   = isset( $options['bizuno_tax_rest_user_name'] ) ? esc_attr( $options['bizuno_tax_rest_user_name'] ) : '';
        echo '<input type="text" name="' . esc_attr( 'bizuno_general_options' ) . '[bizuno_tax_rest_user_name]" value="' . $value . '" class="regular-text" />';
        echo '<p class="description">Username for @PhreeSoft REST API access.</p>';
    }

    public function phreesoft_pass_field_callback()
    {
        $options = get_option( 'bizuno_general_options', [] );
        $has_pass= ! empty( $options['bizuno_tax_rest_user_pass'] );
        echo '<input type="password" name="' . esc_attr( 'bizuno_general_options' ) . '[bizuno_tax_rest_user_pass]" value="" autocomplete="new-password" class="regular-text code" />';
        if ( $has_pass ) { echo '<p class="description"><strong>A password is stored (hidden for security).</strong> Enter a new one to update, or leave blank to keep current.</p>'; }
        else             { echo '<p class="description">Password for @PhreeSoft REST API access.</p>'; }
    }

    public function bizuno_render_shared_settings_page()
    {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Access denied.', 'text-domain' ) ); }
        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';
        $tabs       = [];
        /**
         * Let every plugin register its tabs here
         * Plugins will add to this array via the filter below
         */
        $tabs = apply_filters( 'bizuno_settings_tabs', $tabs );
        if ( ! isset( $tabs['general'] ) ) {
            $tabs['general'] = ['label' => 'General', 'priority' => 10, 'callback' => [ $this, 'bizuno_general_tab_content' ] ];
        }
        uasort( $tabs, function( $a, $b ) {
            $pa = isset( $a['priority'] ) ? $a['priority'] : 50;
            $pb = isset( $b['priority'] ) ? $b['priority'] : 50;
            return $pa <=> $pb;
        } );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Bizuno Settings', 'text-domain' ); ?></h1>
            <h2 class="nav-tab-wrapper">
                <?php foreach ( $tabs as $tab_id => $tab ) : ?>
                    <?php
                    $class = ( $active_tab === $tab_id ) ? ' nav-tab-active' : '';
                    $url   = add_query_arg( [ 'page' => BIZUNO_SETTINGS_PAGE_SLUG, 'tab' => $tab_id ] );
                    ?>
                    <a href="<?php echo esc_url( $url ); ?>" class="nav-tab<?php echo esc_attr( $class ); ?>">
                        <?php echo esc_html( $tab['label'] ?? ucfirst( $tab_id ) ); ?>
                    </a>
                <?php endforeach; ?>
            </h2>
            <form method="post" action="options.php">
                <?php
                if ( isset( $tabs[ $active_tab ]['callback'] ) && is_callable( $tabs[ $active_tab ]['callback'] ) ) { call_user_func( $tabs[ $active_tab ]['callback'] ); }
                else { echo '<p>' . esc_html__( 'No content for this tab.', 'text-domain' ) . '</p>'; }
                if ( in_array( $active_tab, [ 'general' ], true ) ) {
                    settings_fields( 'bizuno_general_options' );
                    submit_button();
                }
                ?>
            </form>
        </div>
        <?php
    }

    public function bizuno_general_tab_content() {
        do_settings_sections( BIZUNO_SETTINGS_PAGE_SLUG );
    }

    /****************************************************************************************************************************/
    // Specific tab - applies only to this plugin
    /****************************************************************************************************************************/
    
    // Define your API-specific options group

    // 1. Add the "API" tab using the shared filter

    function bizuno_api_register_tab( $tabs ) {
        $tabs['api'] = [
            'label'    => 'API Settings',
            'priority' => 20,  // appears before Tax/General if desired
            'callback' => [ $this, 'bizuno_api_tab_content' ],
        ];
        return $tabs;
    }

    // 2. Render the API tab content (your HTML, converted to Settings API)
    function bizuno_api_tab_content() {
        // Load current options (with defaults)
        $this->options = get_option( BIZUNO_API_OPT_GROUP, [
            'url'                => '',
            'rest_user_name'     => '',
            'rest_user_pass'     => '',
            'inv_stock_mgt'      => 'off',
            'inv_backorders'     => 'no',
            'prefix_order'       => 'WC',
            'prefix_customer'    => 'WC',
            'journal_id'         => '0',
            'autodownload'       => 'off',
        ] );

        // Use Settings API for proper nonce + saving
        ?>
        <div class="wrap">
            <h2><?php esc_html_e( 'Bizuno API Settings', 'bizuno-api' ); ?></h2>
            <p>The Bizuno interface passes order, product and other information between your business external website and internal Bizuno site.</p>

            <form method="post" action="options.php">
                <?php
                settings_fields( BIZUNO_API_OPT_GROUP );
                ?>

                <table class="form-table">
                    <tr>
                        <td colspan="2"><h3>RESTful API Settings</h3></td>
                    </tr>

                    <!-- Server URL -->
                    <tr>
                        <th scope="row">Server URL:</th>
                        <td>
                            <input type="url" name="<?php echo esc_attr( BIZUNO_API_OPT_GROUP ); ?>[url]" 
                                   value="<?php echo esc_url( $this->options['url'] ); ?>" size="50" class="regular-text">
                            <p class="description">Enter the full URL to the root of the website you are connecting to, e.g. https://biz.yoursite.com</p>
                        </td>
                    </tr>

                    <!-- REST User Name -->
                    <tr>
                        <th scope="row">AJAX/REST User Name:</th>
                        <td>
                            <input type="text" name="<?php echo esc_attr( BIZUNO_API_OPT_GROUP ); ?>[rest_user_name]" 
                                   value="<?php echo esc_attr( $this->options['rest_user_name'] ); ?>" size="40" class="regular-text">
                            <p class="description">Enter the WordPress user name for the API to connect to. The user must have the proper privileges.</p>
                        </td>
                    </tr>

                    <!-- REST User Password -->
                    <tr>
                        <th scope="row">REST User Password:</th>
                        <td>
                            <input type="password" name="<?php echo esc_attr( BIZUNO_API_OPT_GROUP ); ?>[rest_user_pass]" 
                                   value="" autocomplete="new-password" size="40" class="regular-text">
                            <?php if ( ! empty( $this->options['rest_user_pass'] ) ) : ?>
                                <p class="description"><strong>A password is currently stored (hidden for security).</strong><br>Enter a new value to update, or leave blank to keep existing.</p>
                            <?php else : ?>
                                <p class="description">Enter the WordPress password for the API user.</p>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <tr><td colspan="2"><h3>Product Settings</h3></td></tr>

                    <!-- Stock management -->
                    <tr>
                        <th scope="row">Stock management</th>
                        <td>
                            <input type="checkbox" name="<?php echo esc_attr( BIZUNO_API_OPT_GROUP ); ?>[inv_stock_mgt]" 
                                   <?php checked( $this->options['inv_stock_mgt'], 'on' ); ?> value="on">
                            <p class="description">If checked, the Stock Management box in WooCommerce → Inventory will be checked for the product.</p>
                        </td>
                    </tr>

                    <!-- Allow backorders -->
                    <tr>
                        <th scope="row">Allow backorders?</th>
                        <td>
                            <select name="<?php echo esc_attr( BIZUNO_API_OPT_GROUP ); ?>[inv_backorders]">
                                <option value="no" <?php selected( $this->options['inv_backorders'], 'no' ); ?>>Do not allow</option>
                                <option value="notify" <?php selected( $this->options['inv_backorders'], 'notify' ); ?>>Allow, but notify customer</option>
                                <option value="yes" <?php selected( $this->options['inv_backorders'], 'yes' ); ?>>Allow</option>
                            </select>
                            <p class="description">Pre-selects the backorder option in WooCommerce → Product → Allow Backorders.</p>
                        </td>
                    </tr>

                    <tr><td colspan="2"><h3>Order Settings</h3></td></tr>

                    <!-- Prefix Orders -->
                    <tr>
                        <th scope="row">Prefix Orders with:</th>
                        <td>
                            <input type="text" name="<?php echo esc_attr( BIZUNO_API_OPT_GROUP ); ?>[prefix_order]" 
                                   value="<?php echo esc_attr( $this->options['prefix_order'] ); ?>" size="10">
                            <p class="description">Placing a value here will help identify where the orders originated from.</p>
                        </td>
                    </tr>

                    <!-- Prefix Customers -->
                    <tr>
                        <th scope="row">Prefix Customers with:</th>
                        <td>
                            <input type="text" name="<?php echo esc_attr( BIZUNO_API_OPT_GROUP ); ?>[prefix_customer]" 
                                   value="<?php echo esc_attr( $this->options['prefix_customer'] ); ?>" size="10">
                            <p class="description">Placing a value here will help identify where your customers originated from.</p>
                        </td>
                    </tr>

                    <!-- Download As -->
                    <tr>
                        <th scope="row">Download As:</th>
                        <td>
                            <select name="<?php echo esc_attr( BIZUNO_API_OPT_GROUP ); ?>[journal_id]">
                                <option value="0" <?php selected( $this->options['journal_id'], '0' ); ?>>Auto-Journal</option>
                                <option value="10" <?php selected( $this->options['journal_id'], '10' ); ?>>Sales Order</option>
                                <option value="12" <?php selected( $this->options['journal_id'], '12' ); ?>>Invoice</option>
                            </select>
                            <p class="description">Auto-Journal: creates Invoice if in stock, otherwise Sales Order.<br>Sales Order: always creates a sales order.<br>Invoice: always creates an invoice.</p>
                        </td>
                    </tr>

                    <!-- Autodownload Orders -->
                    <tr>
                        <th scope="row">Autodownload Orders:</th>
                        <td>
                            <input type="checkbox" name="<?php echo esc_attr( BIZUNO_API_OPT_GROUP ); ?>[autodownload]" 
                                   <?php checked( $this->options['autodownload'], 'on' ); ?> value="on">
                            <p class="description">If checked, orders will automatically download to Bizuno and status marked complete after customer checkout.</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button( 'Save Changes', 'primary' ); ?>
            </form>
        </div>
        <?php
    }

    // 3. Register settings & sanitizer

    function bizuno_api_register_settings() {
        register_setting(
            BIZUNO_API_OPT_GROUP,
            BIZUNO_API_OPT_GROUP,
            'bizuno_api_sanitize_options'
        );
    }

    // 4. Sanitizer callback (important for password!)
    function bizuno_api_sanitize_options( $input ) {
        $old = get_option( BIZUNO_API_OPT_GROUP, [] );
        $new = $old;

        // URL – sanitize as URL
        if ( isset( $input['url'] ) ) {
            $new['url'] = esc_url_raw( trim( $input['url'] ) );
        }

        // Username – sanitize as text
        if ( isset( $input['rest_user_name'] ) ) {
            $new['rest_user_name'] = sanitize_text_field( trim( $input['rest_user_name'] ) );
        }

        // Password – minimal handling, encrypt if possible
        if ( isset( $input['rest_user_pass'] ) ) {
            $pass = trim( wp_unslash( $input['rest_user_pass'] ) );
            if ( '' !== $pass ) {
                // Encrypt (recommended) - add your encrypt/decrypt functions
                $new['rest_user_pass'] = $this->encrypt_password( $pass );  // or $pass if plain
            }
            // Blank = keep old
        }
        $checkboxes = ['inv_stock_mgt', 'autodownload'];
        foreach ( $checkboxes as $key ) { $new[$key] = isset( $input[$key] ) && $input[$key] === 'on' ? 'on' : 'off'; }
        if ( isset( $input['inv_backorders'] ) ) {
            $allowed = ['no', 'notify', 'yes'];
            $new['inv_backorders'] = in_array( $input['inv_backorders'], $allowed ) ? $input['inv_backorders'] : 'no';
        }
        if ( isset( $input['journal_id'] ) ) {
            $allowed = ['0', '10', '12'];
            $new['journal_id'] = in_array( $input['journal_id'], $allowed ) ? $input['journal_id'] : '0';
        }
        if ( isset( $input['prefix_order'] ) ) { $new['prefix_order'] = sanitize_text_field( trim( $input['prefix_order'] ) ); }
        if ( isset( $input['prefix_customer'] ) ) { $new['prefix_customer'] = sanitize_text_field( trim( $input['prefix_customer'] ) );  }
        return $new;
    }

    // Optional: Simple encryption helpers (add if not already present)
    function bizuno_encrypt_password( $pass ) {
        // Implement your encryption here (e.g. openssl or base64 fallback)
        return base64_encode( $pass );  // Placeholder – replace with secure method
    }
}
