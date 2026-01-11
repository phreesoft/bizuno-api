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
 * @copyright  2008-2025, PhreeSoft, Inc.
 * @license    https://www.gnu.org/licenses/agpl-3.0.txt
 * @version    7.x Last Update: 2026-01-10
 * @filesource /lib/admin.php
 */

namespace bizuno;

class admin extends common
{
    public $api_local = false; // ISP Hosted so books are at another url

    function __construct() {
        $this->defaults = ['url'=>'',
            'rest_user_name' => '',   'rest_user_pass' => '',
            'inv_stock_mgt'  => false,'inv_backorders' => 'no',
            'prefix_order'   => 'WC', 'prefix_customer'=> 'WC',
            'journal_id'     => 0,    'autodownload'   => 'no',
            'tax_enable'     => 'no', 'tax_nexus'      => []];
        $this->is_post = isset($_POST['bizuno_api_form_updated']) && $_POST['bizuno_api_form_updated'] == 'Y' ? true : false;
        $this->options = $this->processOptions($this->defaults);
    }

    /************************************************* Filters ***************************************************/
    /**
     * Reorders the My Account tabs and adds new tabs
     * @param array $items - The list of tabs before the filter
     * @return array - modified list of tabs
     */
    public function ps_my_business_link_my_account( $items ) {

        unset($items['orders'], $items['downloads'], $items['edit-address'], $items['payment-methods'], $items['downloads'], $items['edit-account'], $items['customer-logout']);
        $items['dashboard']       = __( 'Dashboard', 'woocommerce' );
        $items['edit-account']    = __( 'Account details', 'woocommerce' );
        $items['my-business']     = __( 'My Business', 'phreesoft' ); // New tab
        $items['biz-users']       = __( 'Business Users', 'phreesoft' ); // New tab
        $items['orders']          = __( 'Orders', 'woocommerce' );
        $items['edit-address']    = _n( 'Addresses', 'Address', (int) wc_shipping_enabled(), 'woocommerce' );
        $items['payment-methods'] = __( 'Payment methods', 'woocommerce' );
        $items['downloads']       = __( 'Downloads', 'woocommerce' );
        $items['customer-logout'] = __( 'Logout', 'woocommerce' );
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
            if (empty($exported)) {
                $tip = '';
                echo '<button type="button" class="order-status status-processing tips" data-tip="'.$tip.'">'.__( 'No', 'bizuno_api' ).'</button>';
            } else { echo 'X&nbsp;'; }
        }
    }
    public function bizuno_api_order_column_content_hpos ( $column, $order ) {
        if ( 'bizuno_download' === $column ) {
            $exported= $order->get_meta( 'bizuno_order_exported', true );
            $status  = $order->get_status();
            msgDebug("\nread meta_value = ".print_r($exported, true));
            if (empty($exported) && !in_array($status, ['cancelled', 'on-hold'])) {
                $tip = "status = $status";
                echo '<button type="button" class="order-status status-processing tips" data-tip="'.$tip.'">'.__( 'No', 'bizuno_api' ).'</button>';
            } else { echo '&nbsp;'; }
        }
    }
    
    public function bizuno_api_order_preview_filter( $data, $order ) { // Add download button to Preview pop up
        $data['bizuno_order_exported'] = $order->get_meta('bizuno_order_exported', true, 'edit') ? 'none' : 'block';
        return $data;
    }
    public function bizuno_api_order_preview_action() {
        $url = admin_url( 'admin-ajax.php?action=bizuno_api_order_download' );
        echo '<span style="display:{{ data.bizuno_order_exported }}"><a class="button button-primary button-large" onClick="window.location = \''.$url.'&biz_order_id={{ data.data.id }}\';">'.__( 'Export order to Bizuno', 'bizuno-api' ).'</a></span>'."\n";
    }
    public function bizuno_api_add_order_meta_box_filter( $actions ) { // add download button to order edit page
        if (get_post_meta( get_the_ID(), 'bizuno_order_exported', true ) ) { return $actions; }
        $actions['bizuno_export_action'] = __('Export order to Bizuno', 'bizuno-api');
        return $actions;
    }

    public function bizuno_api_add_setting_submenu( ) {
        add_submenu_page( 'options-general.php', 'Bizuno', 'Bizuno', 'manage_options', 'bizuno_api', [$this, 'bizuno_api_setting_submenu']);
    }

    public function bizuno_api_setting_submenu() {
        if (!current_user_can('manage_options')) { wp_die( __('You do not have sufficient permissions to access this page.') ); }
        if (!empty($this->is_post)) {
            echo '<div class="updated"><p><strong>'.__('Settings Saved.', 'bizuno-api' ).'</strong></p></div>';
        }
        $html = '';
        $html.= '
<div class="wrap"><h2>'.__( 'Bizuno Settings', 'phreesoft' ).'</h2>
  <p>The Bizuno interface passes order, product and other information between your business external website and internal Bizuno site.</p>
  <form name="formBizAPI" method="post" action="">
    <input type="hidden" name="bizuno_api_form_updated" value="Y">
    <table class="form-table" role="presentation"><tbody>
<tr><td colspan="2"><h3>RESTful API Settings</h3></td></tr>
      <tr><th scope="row">Server URL:</th><td>
        <input type="text" name="bizuno_api_url" value="'.$this->options['url'].'" size="30"><br />
          Enter the full URL to the root of the website you are connecting to, e.g. https://biz.yoursite.com.
      </td></tr>';
        // When using OAuth2 add the following to the instructions and uncomment the added inputs:
        // Note for added security to remote domains, this API has the option of using OAuth2 which requires a Client ID and Client Secret from the destination site. The WordPress plugin <b>WP OAuth Server - CE</b> is required if using oAuth!
/*        $html.= '
      <tr><th scope="row">OAuth2 Client ID:</th><td>
        <input type="text" name="bizuno_api_oauth_client_id" value="'.$this->options['oauth_client_id'].'" size="70"><br />
          Enter the Client ID as provided by the OAUTH2 plugin on the destination WordPress install. Not used if Bizuno and your WooCommerce store are on the same domain.
      </td></tr>
      <tr><th scope="row">OAuth2 Client Secret:</th><td>
        <input type="text" name="bizuno_api_oauth_client_secret" value="'.$this->options['oauth_client_secret'].'" size="70"><br />
          Enter the Client Secret as provided by the OAUTH2 plugin on the destination WordPress install. Not used if Bizuno and your WooCommerce store are on the same domain.
      </td></tr>'; */
        $html.= '
      <tr><th scope="row">AJAX/REST User Name:</th><td>
        <input type="text" name="bizuno_api_rest_user_name" value="'.$this->options['rest_user_name'].'" size="40"><br />
          Enter the WordPress user name for the API to connect to. The user must have the proper privileges to perform the requested action.
      </td></tr>
      <tr><th scope="row">REST User Password:</th><td>
        <input type="password" name="bizuno_api_rest_user_pass" value="'.$this->options['rest_user_pass'].'" size="40"><br />
          Enter the WordPress password for the API to connect to.
      </td></tr>
<tr><td colspan="2"><h3>Product Settings</h3></td></tr>
      <tr><th scope="row">Stock management</th><td>
        <input type="checkbox" name="bizuno_api_inv_stock_mgt"'.(!empty($this->options['inv_stock_mgt'])?' checked':'').'><br />
          If checked, the Stock Management box in WooCommerce -> Inventory will be checked for the product.
      </td></tr>
      <tr><th scope="row">Allow backorders?</th><td>
        <select name="bizuno_api_inv_backorders">
          <option value="no"'    . ($this->options['inv_backorders']=='no'    ? ' selected' : '') . '>Do not allow</option>
          <option value="notify"'. ($this->options['inv_backorders']=='notify'? ' selected' : '') . '>Allow, but notify customer</option>
          <option value="yes"'   . ($this->options['inv_backorders']=='yes'   ? ' selected' : '') . '>Allow</option>
        </select><br />
       Pre-selects the backorder option in WooCommerce -> Product -> Allow Backorders for the product.
      </td></tr>
<tr><td colspan="2"><h3>Order Settings</h3></td></tr>
      <tr><th scope="row">Prefix Orders with:</th><td>
        <input type="text" name="bizuno_api_prefix_order" value="'.$this->options['prefix_order'].'" size="8"><br />
          Placing a value here will help identify where the orders originated from.
      </td></tr>
      <tr><th scope="row">Prefix Customers with:</th><td>
        <input type="text" name="bizuno_api_prefix_customer" value="'.$this->options['prefix_customer'].'" size="8"><br />
          Placing a value here will help identify where your customers originated from.
      </td></tr>
      <tr><th scope="row">Download As:</th><td>
        <select name="bizuno_api_journal_id">
          <option value="0"'. (!in_array($this->options['journal_id'], [10,12])? ' selected' : '').'>Auto-Journal</option>
          <option value="10"'.($this->options['journal_id']==10? ' selected' : '').'>Sales Order</option>
          <option value="12"'.($this->options['journal_id']==12? ' selected' : '').'>Invoice</option>
        </select><br />
       Options: Auto-Journal - will create Invoice if everything is in stock, otherwise will create a Sales Order. Sales Order - Will always create a sales order. Invoice - Will always create an invoice.
      </td></tr>
      <tr><th scope="row">Autodownload Orders:</th><td>
        <input type="checkbox" name="bizuno_api_autodownload"'.(in_array($this->options['autodownload'], ['yes', 1]) ? ' checked' : '').'><br />
          If checked, your orders will automatically be downloaded to Bizuno and status at the cart marked complete just after the customer completes the order.
      </td></tr>
      <tr><th scope="row">Use PhreeSoft Tax calculator?</th><td>
        <select name="bizuno_api_tax_enable">
          <option value="no"'    . ($this->options['tax_enable']=='no'  ? ' selected' : '') . '>No</option>
          <option value="yes"'   . ($this->options['tax_enable']=='yes' ? ' selected' : '') . '>Yes</option>
        </select><br />
       Select Yes to use the PhreeSoft sales tax calculator. Selecting No will use the sales tax calculator as defined the the WooCommerce settings.
      </td></tr>
      <tr><th scope="row">States with Sales Tax Nexus</th><td>
        <select name="bizuno_api_tax_nexus[]" multiple size="10">'."\n";
        $html.= $this->getStates('USA', $this->options['tax_nexus']);
        $html.= '        </select><br />
        Select all of the states that your business has a nexus in. Selected states will have thier tax rates tax calculated via the PhreeSoft tax service.
      </td></tr>
    </tbody></table>
    <input type="submit" name="submit" id="submit" class="button button-primary" value="Save Changes">
  </form>
</div>';
        echo $html;
    }

    private function getStates($iso3='USA', $vals=[])
    {
        $output = '';
        $countries = localeLoadDB();
        msgDebug("\ncountries = ".print_r($countries, true));
        foreach ($countries['Locale'] as $country) {
            if ($country['ISO3']<>$iso3 || !isset($country['Regions'])) { continue; }
            foreach($country['Regions'] as $state) {
                $output .= '<option value="'.$state['Code'].'"'.(in_array($state['Code'], (array)$vals)? ' selected' : '').'>'.$state['Title'].'</option>'."\n";
            } 
        }
        return $output;
    }

    private function processOptions($values)
    {
        $output = [];
        foreach ($values as $key => $default) {
            if (!empty($this->is_post)) {
                $output[$key] = $_POST[ 'bizuno_api_'.$key ];
                update_option ( 'bizuno_api_'.$key, $output[$key] );
            } else {
                $output[$key] = \get_option ( 'bizuno_api_'.$key, $default );
            }
        }
        return $output;
    }
}
