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
 * @version    7.x Last Update: 2026-01-19
 * @filesource /lib/admin.php
 */

namespace bizuno;

if ( ! defined( 'ABSPATH' ) ) exit;

class admin extends common
{
    private $bizLib    = "bizuno-wp";
    public  $api_local = false; // ISP Hosted so books are at another url
    public  $options   = [];

    function __construct() {
        $this->defaults = ['url'=>'',
            'rest_user_name' => '',   'rest_user_pass' => '',
            'inv_stock_mgt'  => '',   'inv_backorders' => 'no',
            'prefix_order'   => 'WC', 'prefix_customer'=> 'WC',
            'journal_id'     => 0,    'autodownload'   => '',
            'tax_enable'     => 'no', 'tax_nexus'      => []];
        $this->processOptions($this->defaults);
    }

    /************************************************* Filters ***************************************************/
    /**
     * Reorders the My Account tabs and adds new tabs
     * @param array $items - The list of tabs before the filter
     * @return array - modified list of tabs
     */
    public function ps_my_business_link_my_account( $items ) {

        unset($items['orders'], $items['downloads'], $items['edit-address'], $items['payment-methods'], $items['downloads'], $items['edit-account'], $items['customer-logout']);
        $items['dashboard']       = __( 'Dashboard', 'bizuno-api' );
        $items['edit-account']    = __( 'Account details', 'bizuno-api' );
        $items['my-business']     = __( 'My Business', 'bizuno-api' ); // New tab
        $items['biz-users']       = __( 'Business Users', 'bizuno-api' ); // New tab
        $items['orders']          = __( 'Orders', 'bizuno-api' );
        $items['edit-address']    = _n( 'Addresses', 'Address', (int) wc_shipping_enabled(), 'bizuno-api' );
        $items['payment-methods'] = __( 'Payment methods', 'bizuno-api' );
        $items['downloads']       = __( 'Downloads', 'bizuno-api' );
        $items['customer-logout'] = __( 'Logout', 'bizuno-api' );
        return $items;
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
        add_submenu_page( 'options-general.php', 'Bizuno', 'Bizuno', 'manage_options', 'bizuno_api', [$this, 'bizuno_api_setting_submenu']);
        if ( defined( 'BIZUNO_FS_LIBRARY' ) && is_plugin_active ( "$this->bizLib/$this->bizLib.php" )) {
            add_menu_page( 'Bizuno', 'Bizuno', 'manage_options', 'bizuno', 'bizuno_html', 
                plugins_url( 'icon_16.png', WP_PLUGIN_DIR . "/$this->bizLib/$this->bizLib.php" ), 90);            
        } elseif ( !defined( 'BIZUNO_FS_LIBRARY' ) ) {
            add_menu_page( 'GET BIZUNO', 'GET BIZUNO', 'manage_options', 'get-bizuno', 'get_bizuno_html',
                plugins_url( 'icon_16.png', WP_PLUGIN_DIR . "/bizuno-api/bizuno-apip.php" ), 1);
        }
    }

    public function bizuno_api_setting_submenu() {
        $user_id = \get_current_user_id();
        if (!current_user_can('manage_options')) { wp_die( \esc_html ( __('You do not have sufficient permissions to access this page.', 'bizuno-api') ) ); }
        $is_post = ( isset( $_POST['_wpnonce'] ) && \wp_verify_nonce( $_POST['_wpnonce'], 'api-settings_' . $user_id ) && \current_user_can( 'edit_user', $user_id ) ) ? true : false;
        if (!empty($is_post)) {
            echo '<div class="updated"><p><strong>'.\esc_html ( __('Settings Saved.', 'bizuno-api' ) ) .'</strong></p></div>';
        }
        $html = '';
        ?>
<div class="wrap"><h2><?php echo esc_html ( __( 'Bizuno Settings', 'bizuno-api' ) ) ?></h2>
  <p>The Bizuno interface passes order, product and other information between your business external website and internal Bizuno site.</p>
  <form name="formBizAPI" method="post" action="">
    <input type="hidden" name="bizuno_api_form_updated" value="Y">
    <?php wp_nonce_field( 'api-settings_' . $user_id, '_wpnonce', true, true ); ?>
    <table class="form-table" role="presentation"><tbody>
<tr><td colspan="2"><h3>RESTful API Settings</h3></td></tr>
      <tr><th scope="row">Server URL:</th><td>
        <input type="text" name="bizuno_api_url" value="<?php echo esc_url ( $this->options['url'] ) ?>" size="30"><br />
          Enter the full URL to the root of the website you are connecting to, e.g. https://biz.yoursite.com.
      </td></tr>
        <?php
        // When using OAuth2 add the following to the instructions and uncomment the added inputs:
        // Note for added security to remote domains, this API has the option of using OAuth2 which requires a Client ID and Client Secret from the destination site. The WordPress plugin <b>WP OAuth Server - CE</b> is required if using oAuth!
/*        $html.= '
      <tr><th scope="row">OAuth2 Client ID:</th><td>
        <input type="text" name="bizuno_api_oauth_client_id" value="<?php $this->options['oauth_client_id'].'" size="70"><br />
          Enter the Client ID as provided by the OAUTH2 plugin on the destination WordPress install. Not used if Bizuno and your WooCommerce store are on the same domain.
      </td></tr>
      <tr><th scope="row">OAuth2 Client Secret:</th><td>
        <input type="text" name="bizuno_api_oauth_client_secret" value="<?php $this->options['oauth_client_secret'].'" size="70"><br />
          Enter the Client Secret as provided by the OAUTH2 plugin on the destination WordPress install. Not used if Bizuno and your WooCommerce store are on the same domain.
      </td></tr>'; */
?>
      <tr><th scope="row">AJAX/REST User Name:</th><td>
        <input type="text" name="bizuno_api_rest_user_name" value="<?php echo esc_html ($this->options['rest_user_name'] )?>" size="40"><br />
          Enter the WordPress user name for the API to connect to. The user must have the proper privileges to perform the requested action.
      </td></tr>
      <tr><th scope="row">REST User Password:</th><td>
        <input type="password" name="bizuno_api_rest_user_pass" value="<?php echo esc_html ($this->options['rest_user_pass']) ?>" size="40"><br />
          Enter the WordPress password for the API to connect to.
      </td></tr>
<tr><td colspan="2"><h3>Product Settings</h3></td></tr>
      <tr><th scope="row">Stock management</th><td>
        <input type="checkbox" name="bizuno_api_inv_stock_mgt" <?php echo in_array($this->options['inv_stock_mgt'], ['on', 'yes', 1]) ? ' checked' : 'off' ?>><br />
          If checked, the Stock Management box in WooCommerce -> Inventory will be checked for the product.
      </td></tr>
      <tr><th scope="row">Allow backorders?</th><td>
        <select name="bizuno_api_inv_backorders">
          <option value="no"    <?php echo $this->options['inv_backorders']=='no'    ? ' selected' : '' ?>>Do not allow</option>
          <option value="notify"<?php echo $this->options['inv_backorders']=='notify'? ' selected' : '' ?>>Allow, but notify customer</option>
          <option value="yes"   <?php echo $this->options['inv_backorders']=='yes'   ? ' selected' : '' ?>>Allow</option>
        </select><br />
       Pre-selects the backorder option in WooCommerce -> Product -> Allow Backorders for the product.
      </td></tr>
<tr><td colspan="2"><h3>Order Settings</h3></td></tr>
      <tr><th scope="row">Prefix Orders with:</th><td>
        <input type="text" name="bizuno_api_prefix_order" value="<?php echo esc_html ( $this->options['prefix_order'] ); ?>" size="8"><br />
          Placing a value here will help identify where the orders originated from.
      </td></tr>
      <tr><th scope="row">Prefix Customers with:</th><td>
        <input type="text" name="bizuno_api_prefix_customer" value="<?php echo esc_html ( $this->options['prefix_customer'] ); ?>" size="8"><br />
          Placing a value here will help identify where your customers originated from.
      </td></tr>
      <tr><th scope="row">Download As:</th><td>
        <select name="bizuno_api_journal_id">
          <option value="0" <?php echo !in_array($this->options['journal_id'], [10,12])? ' selected' : '' ?>>Auto-Journal</option>
          <option value="10"<?php echo $this->options['journal_id']==10? ' selected' : '' ?>>Sales Order</option>
          <option value="12"<?php echo $this->options['journal_id']==12? ' selected' : '' ?>>Invoice</option>
        </select><br />
       Options: Auto-Journal - will create Invoice if everything is in stock, otherwise will create a Sales Order. Sales Order - Will always create a sales order. Invoice - Will always create an invoice.
      </td></tr>
      <tr><th scope="row">Autodownload Orders:</th><td>
        <input type="checkbox" name="bizuno_api_autodownload"<?php echo in_array($this->options['autodownload'], ['on', 'yes', 1]) ? ' checked' : '' ?>><br />
          If checked, your orders will automatically be downloaded to Bizuno and status at the cart marked complete just after the customer completes the order.
      </td></tr>
      <tr><th scope="row">Use PhreeSoft Tax calculator?</th><td>
        <select name="bizuno_api_tax_enable">
          <option value="no" <?php echo $this->options['tax_enable']=='no'  ? ' selected' : ''?>>No</option>
          <option value="yes"<?php echo $this->options['tax_enable']=='yes' ? ' selected' : ''?>>Yes</option>
        </select><br />
       Select Yes to use the PhreeSoft sales tax calculator. Selecting No will use the sales tax calculator as defined in the WooCommerce settings.
      </td></tr>
      <tr><th scope="row">States with Sales Tax Nexus</th><td>
        <select name="bizuno_api_tax_nexus[]" multiple size="10">
            <?php $this->getStates('USA', $this->options['tax_nexus']); ?>
        </select><br />
        Select all of the states that your business has a nexus in. Selected states will have their tax rates tax calculated via the PhreeSoft tax service.
      </td></tr>
    </tbody></table>
    <input type="submit" name="submit" id="submit" class="button button-primary" value="Save Changes">
  </form>
</div>
<?php
    }

    private function getStates($iso3='USA', $vals=[])
    {
        $output = '';
        $countries = localeLoadDB();
        msgDebug("\ncountries = ".print_r($countries, true));
        foreach ($countries['Locale'] as $country) {
            if ($country['ISO3']<>$iso3 || !isset($country['Regions'])) { continue; }
            foreach($country['Regions'] as $state) {
                echo '        <option value="' . esc_html ( $state['Code'] ) . '"' . (in_array($state['Code'], (array)$vals)? ' selected' : '').'>' . esc_html ( $state['Title'] ). '</option>'."\n";
            } 
        }
        return $output;
    }

    public function processOptions($values)
    {
        require_once ABSPATH . 'wp-includes/pluggable.php';
        $user_id = \get_current_user_id();
        $is_post= ( isset( $_POST['_wpnonce'] ) && \wp_verify_nonce( $_POST['_wpnonce'], 'api-settings_' . $user_id ) && \current_user_can( 'edit_user', $user_id ) ) ? true : false;
        $output = [];
        foreach ($this->defaults as $key => $default) {
            if (!empty($is_post)) {
                $output[$key] = isset($_POST[ 'bizuno_api_'.$key ] ) ? $_POST[ 'bizuno_api_'.$key ] : $this->defaults[$key];
                \update_option ( 'bizuno_api_'.$key, $output[$key] );
            } else {
                $output[$key] = \get_option ( 'bizuno_api_'.$key, $default );
            }
        }
        $this->options = $output;
    }
}
