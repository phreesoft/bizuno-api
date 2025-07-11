<?php
/**
 * ISP Hosted WordPress Plugin - admin class
 *
 * @copyright  2008-2025, PhreeSoft, Inc.
 * @author     David Premo, PhreeSoft, Inc.
 * @version    3.x Last Update: 2025-07-11
 * @filesource ISP WordPress /bizuno-erp/lib/admin.php
 */

namespace bizuno;

class admin extends common
{
    public $api_local = false; // ISP Hosted so books are at another url

    function __construct() {
        $this->defaults = ['url' => '',
//          'oauth_client_id'=> '',  'oauth_client_secret'=> '', // oAuth not implemented yet.
            'rest_user_name' => '',  'rest_user_pass'     => '',
            'prefix_order'   => 'WC','prefix_customer'    => 'WC',
            'journal_id'     => 0,   'autodownload'       => 0];
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
    public function bizuno_api_order_column_header( $columns ) { // Add column to order summary page
        $reordered_columns = [];
        foreach ( $columns as $key => $column ) {
            $reordered_columns[$key] = $column;
            if ( $key == 'order_status' ) { $reordered_columns['bizuno_download'] = __( 'Exported', 'bizuno_erp'); }
        }
        return $reordered_columns;
    }
    public function bizuno_api_order_column_content($column) // $order
    {
        global $the_order; // the global order object
        if ($column == 'bizuno_download') {
            $exported = $the_order->get_meta( 'bizuno_order_exported', true );
            if (empty($exported)) {
                $tip = '';
                echo '<button type="button" class="order-status status-processing tips" data-tip="'.$tip.'">'.__( 'No', 'bizuno_api' ).'</button>';
            } else { echo 'X&nbsp;'; }
        }
/*        if ( 'bizuno_download' === $column ) {
            echo 'X';
            $exported = $order->get_meta( 'bizuno_order_exported', true ); // $order->get_meta( 'bizuno_order_exported', true );
            if (empty($exported)) {
                $tip = '';
                echo '<button type="button" class="order-status status-processing tips" data-tip="'.$tip.'">'.__( 'No', 'bizuno_api' ).'</button>';
            } else { echo '&nbsp;'; }
        } */
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
        $actions['bizuno_tax_action']    = __('Fetch Tax Table Version', 'bizuno-api');
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
      <tr><td colspan="2"><h3>General Settings</h3></td></tr>
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
        <input type="checkbox" name="bizuno_api_autodownload"'.(!empty($this->options['autodownload'])?' checked':'').'><br />
          If checked, your orders will automatically be downloaded to Bizuno and status at the cart marked complete just after the customer completes the order.
      </td></tr>
    </tbody></table>
    <input type="submit" name="submit" id="submit" class="button button-primary" value="Save Changes">
  </form>
</div>'; //  interface to Bizuno Accounting
        echo $html;
    }

    private function processOptions($values)
    {
        $output = [];
        foreach ($values as $key => $default) {
            if (!empty($this->is_post)) {
                switch ($key) {
                    case 'autodownload':$output[$key] = isset( $_POST[ 'bizuno_api_'.$key ] ) ? 1 : 0; break;
                    default:            $output[$key] = $_POST[ 'bizuno_api_'.$key ];
                }
                update_option ( 'bizuno_api_'.$key, $output[$key] );
            } else {
                $output[$key] = \get_option ( 'bizuno_api_'.$key, $default );
            }
        }
        return $output;
    }
}
