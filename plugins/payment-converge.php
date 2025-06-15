<?php
/**
 * WooCommerce - Bizuno API - Payment Method - Elavon/Converge
 * This class contains the  methods to handle cart payment methods
 *
 * @copyright  2008-2024, PhreeSoft, Inc.
 * @author     David Premo, PhreeSoft, Inc.
 * @version    3.x Last Update: 2023-06-09
 * @filesource /bizuno-api/lib/payment_converge.php
 */

/***************************************************************************************************/
//  Payment Method - Credit Card (Elavon/Converge)
/***************************************************************************************************/
function bizuno_payment_converge_method_init() {
    if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) { return; }
    if ( class_exists( 'WC_Gateway_Biz_Credit_Card' ) ) { return; }
    class WC_Gateway_Biz_Credit_Card extends WC_Payment_Gateway {
        public function __construct() {
            $this->id                  = 'converge';
            $this->icon                = apply_filters( 'woocommerce_converge_icon', '' );
            $this->has_fields          = true; // in case you need a custom credit card form, if simple offline gateway then should be false so the values are  true/false (bool).
            $this->method_title        = __( 'Elavon Credit Card payments', 'Elavon Credit Card payment method', 'bizuno' );
            $this->method_description  = __( 'Accept payment via Credit Card processed through the Elavon gateway.', 'bizuno' );
            $this->supports            = ['products'];
            $this->init_form_fields();
            $this->init_settings();
            $this->title               = $this->get_option( 'title' );
            $this->description         = $this->get_option( 'description' );
            $this->instructions        = $this->get_option( 'instructions' );
            $this->enabled             = $this->get_option( 'enabled' );
            $this->elavon_merch_id     = $this->get_option( 'elavon_merch_id' );
            $this->elavon_user_id      = $this->get_option( 'elavon_user_id' );
            $this->elavon_pin          = $this->get_option( 'elavon_pin' );
            $this->elavon_sandbox      = $this->get_option( 'elavon_sandbox' );
            $this->elavon_auth_only    = $this->get_option( 'elavon_auth_only' );
            $this->elavon_cardtypes    = $this->get_option( 'elavon_cardtypes');
            $this->elavon_meta_cartspan= $this->get_option( 'elavon_meta_cartspan');
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options'] );
            $this->avs = [
                'A' => 'Address matches - Postal Code does not match.',
                'B' => 'Street address match, Postal code in wrong format. (International issuer)',
                'C' => 'Street address and postal code in wrong formats.',
                'D' => 'Street address and postal code match. (international issuer)',
                'E' => 'AVS Error.',
                'G' => 'Service not supported by non-US issuer.',
                'I' => 'Address information not verified by international issuer.',
                'M' => 'Street address and Postal code match. (international issuer)',
                'N' => 'No match on address (street) or postal code.',
                'O' => 'No response sent.',
                'P' => 'Postal code matches, street address not verified due to incompatible formats.',
                'R' => 'Retry, system unavailable or timed out.',
                'S' => 'Service not supported by issuer.',
                'U' => 'Address information is unavailable.',
                'W' => '9 digit postal code matches, address (street) does not match.',
                'X' => 'Exact AVS match.',
                'Y' => 'Address (street) and 5 digit postal code match.',
                'Z' => '5 digit postal code matches, address (street) does not match.'];
            $this->cvv = ['M' => 'CVV2 matches', 'N' => 'CVV2 does not match', 'P' => 'Not Processed',
                'S' => 'Issuer indicates that CVV2 data should be present on the card, but the merchant has indicated that the CVV2 data is not present on the card.',
                'U' => 'Issuer has not certified for CVV2 or issuer has not provided Visa with the CVV2 encryption keys.'];
            if (!defined("ELAVON_SANDBOX"))           { define("ELAVON_SANDBOX",           $this->elavon_sandbox=='yes'       ? true : false); }
            if (!defined("ELAVON_TRANSACTION_MODE"))  { define("ELAVON_TRANSACTION_MODE",  $this->elavon_auth_only=='yes'? true : false); }
            if (!defined('PAYMENT_CONVERGE_URL'))     { define('PAYMENT_CONVERGE_URL',     'https://www.myvirtualmerchant.com/VirtualMerchant/processxml.do'); }
            if (!defined('PAYMENT_CONVERGE_URL_TEST')){ define('PAYMENT_CONVERGE_URL_TEST','https://demo.myvirtualmerchant.com/VirtualMerchantDemo/processxml.do'); }
        }
        public function init_form_fields()
        {
            $this->form_fields = [
                'enabled'         => ['title'=>__( 'Enable/Disable', 'woocommerce' ), 'type'=>'checkbox', 'default'=>'no',
                    'label'       => __( 'Enable Card Payment through the Converge gateway', 'bizuno' )],
                'title'           => ['title'=>__( 'Title', 'Credit/Debit Card' ), 'type'=>'text', 'desc_tip'=>true,
                    'description' => __( 'This controls the title which the buyer sees during checkout.', 'woocommerce' ),
                    'default'     => __( 'Credit/Debit Card', 'woocommerce' )],
                'description'     => ['title'=>__( 'Description', 'woocommerce' ), 'type'=>'textarea', 'desc_tip'=>true,
                    'description' => __( 'This controls the description which the buyer sees during checkout.', 'woocommerce' ),
                    'default'     => __( '', 'woocommerce' )],
                'instructions'    => ['title'=>'Instructions', 'type'=>'textarea', 'desc_tip'=>true, 'default'=>'',
                    'description' => 'Instructions that will be added to the thank you page and emails.'],
                'elavon_merch_id' => ['title'=>__( 'API Merchant ID supplied by Elavon', 'bizuno' ), 'type'=>'text', 'desc_tip'=>true, 'default'=>'',
                    'description' => __( 'This is the API Merchant ID provided by Elavon.', 'bizuno' ),
                    'placeholder' => 'Elavon API Merchant ID'],
                'elavon_user_id'  => ['title'=>__( 'API User ID supplied by Elavon', 'bizuno' ), 'type'=>'text', 'desc_tip'=>true,
                    'description' => __( 'This is the API Login ID for the Converge gateway.', 'bizuno' ), 'default'=>'',
                    'placeholder' => 'Elavon API User ID'],
                'elavon_pin'      => ['title'=>__( 'API pin supplied by Elavon', 'bizuno' ), 'type'=> 'text', 'default'=>'', 'desc_tip'=>true,
                    'description' => __( 'This is the PIN provided to you by Elavon.', 'bizuno' ),
                    'placeholder' => 'Elavon Transaction Key'],
                'elavon_sandbox'  => ['title'=>__( 'Converge sandbox', 'bizuno' ), 'type'=>'checkbox', 'default'=>'no', 'desc_tip'=>true,
                    'label'       => __( 'Enable Converge sandbox (Live Mode if Unchecked)', 'bizuno' ),
                    'description' => __( 'If checked its in sandbox mode and if unchecked its in live mode', 'bizuno' )],
                'elavon_auth_only'=> ['title'=>__( 'Authorize Only', 'bizuno' ), 'type'=>'checkbox', 'default'=>'no', 'desc_tip'=>true,
                    'label'       => __( 'Enable Authorize Only Mode (Authorize & Capture If Unchecked).', 'bizuno' ),
                    'description' => __( 'If checked will only authorize the credit card only upon checkout.', 'bizuno' )],
                'elavon_cardtypes'=> ['title'=>__( 'Accepted Cards', 'woocommerce' ), 'type'=>'multiselect', 'class'=>'chosen_select', 'css'=>'width: 350px;',
                    'desc_tip'    => __( 'Select the card types to accept.', 'woocommerce' ),
                    'options'     => ['mastercard'=>'MasterCard', 'visa'=>'Visa', 'discover'=>'Discover', 'amex'=>'American Express'],
                    'default'     => ['mastercard', 'visa', 'discover', 'amex']]];
        }
        public function payment_fields() {
            $MonthOp = $YearOp = '';
            if ( $this->description ) { echo wpautop( wp_kses_post( $this->description ) ); }
            $uniqid = uniqid();
?>
<input type="hidden" name="uniqueId" value="<?php echo $uniqid; ?>" />
<p class="form-row form-row-first">
  <label for="bizcc_card_number">Card number<span class="required">*</span></label>
  <input type="text" class="input-text" class="input-text wc-credit-card-form-card-number" placeholder="Card Number" name="bizcc_card_number" maxlength="16" autocomplete="off" />
</p><br />
<p class="form-row form-row-first">
  <label for="cc-expire-month">Expiration date<span class="required">*</span></label>
  <select class="input-text" name="bizcc_card_expiration_month" id="cc-expire-month">
  <?php for ($i = 1; $i < 13; $i++) { $MonthOp .="<option value='".sprintf('%02d',$i)."'>".sprintf('%02d', $i)."</option>"; } ?>
  <option value="">Month</option><?php echo $MonthOp; ?></select>
  <select class="input-text" name="bizcc_card_expiration_year" id="cc-expire-year" style="width:190px;padding-left:5px;">
  <?php for ($y = date('Y'); $y < date('Y')+15; $y++) { $YearOp .="<option value='".sprintf('%02d', $y)."'>".sprintf('%02d', $y)."</option>"; } ?>
  <option value="">Year</option><?php echo $YearOp; ?></select>
</p>
<p class="form-row form-row-first">
  <label for="bizcc_card_csc"><?php _e("Card security code", 'woothemes') ?><span class="required">*</span></label>
  <input type="password" maxlength="4" class="input-text wc-credit-card-form-card-cvc" id="bizcc_card_csc" name="bizcc_card_csc" style="width:190px" placeholder="CVC" autocomplete="off"/>
</p>
<?php
        }
        public function validate_fields()
        {
			$CardNo      = $_REQUEST['bizcc_card_number'];
			$cvv		 = $_REQUEST['bizcc_card_csc'];
			$expiresMonth= $_REQUEST['bizcc_card_expiration_month'];
			$expiresYear = $_REQUEST['bizcc_card_expiration_year'];
			$errorMsg = $this->validateCardInfo($CardNo, $cvv, $expiresYear, $expiresMonth);
			if (!empty($errorMsg) && strlen($errorMsg) > 1) {
				wc_add_notice( __($errorMsg, 'woocommerce' ), 'error' );
				return false;
			}
        }
        private function validateCardInfo($cardNum,$cvv,$year,$month) {
			$msg0 = $this->validateCardNum($cardNum);
			if (!empty($msg0) && strlen($msg0)>1) { return $msg0; }
			$msg1 = $this->validateCVV($cvv);
			if (!empty($msg1) && strlen($msg1)>1) { return $msg1; }
			$msg2 = $this->validateExpiresDate($year,$month);
			if (!empty($msg2) && strlen($msg2)>1) { return $msg2; }
			return $msg2;
		}
        private function validateCardNum($cardNum) {
			$msg = "";
			if(empty($cardNum) || !is_numeric($cardNum) || strlen($cardNum)<15 || strlen($cardNum)>16 ||
				!$this->card_check_by_luhn($cardNum)) {
				$msg = 'The <strong>credit card number</strong> is incorrect !';
			}
			return $msg;
		}
        private function card_check_by_luhn($cardNum){
			$str = '';
			foreach(array_reverse(str_split($cardNum)) as $i => $c) $str .= ($i % 2 ? $c * 2 : $c);
			return array_sum(str_split($str)) % 10 == 0;
		}
        private function validateCVV($cvv) {
			$msg = "";
			if(empty($cvv) || !is_numeric($cvv) || strlen($cvv)<3 || strlen($cvv)>4) { $msg = '<strong>CVV/CSC</strong> Code is incorrect !'; }
			return $msg;
		}
        private function validateExpiresDate($year,$month) {
			$msg = "";
			if(empty($year) || !is_numeric($year) || strlen($year) !=4) {
				$msg = 'The <strong>year</strong> of expiry date is incorrect !';
			} else if(empty($month) || !is_numeric($month) || strlen($month) !=2 || $month < 1 || $month>12) {
				$msg = 'The <strong>month</strong> of expiry date is incorrect !';
			} else {
				$currentDate  = new DateTime(date("Y-m",time()));
				$inputDate    = new DateTime($year."-".$month);
				if($year<date("Y",time()) || $inputDate->format('U') < $currentDate->format('U')) {
					$msg = 'The <strong>expire date</strong> is expired!';
				}
			}
			return $msg;
		}
        public function process_payment( $order_id ) {
            $wc_order   = wc_get_order( $order_id );
            $cardtype   = $this->get_card_type(sanitize_text_field(str_replace(' ','',$_POST['bizcc_card_number'])));
            if (!in_array($cardtype , $this->elavon_cardtypes )) {
                wc_add_notice('There was a problem processing your card of type: '.$cardtype.'. Please try another payment method.', 'error' );
                return;
            }
            $card_num   = sanitize_text_field(str_replace(' ', '', $_POST['bizcc_card_number']));
            $exp_month  = sanitize_text_field($_POST['bizcc_card_expiration_month']);
            $exp_year   = sanitize_text_field($_POST['bizcc_card_expiration_year']);
            if (strlen($exp_year) == 2) { $exp_year += 2000; }
            $cvc        = sanitize_text_field($_POST['bizcc_card_csc']);
            $this->hint = substr($card_num, 0, 2);
            for ($a = 0; $a < (strlen($card_num) - 6); $a++) { $this->hint .= '*'; }
            $this->hint.= substr($card_num, -4);
            $submit_data = [
                'ssl_transaction_type'  => $this->elavon_auth_only=='yes' ? 'CCAUTHONLY' : 'CCSALE',
                'ssl_merchant_id'       => $this->elavon_merch_id,
                'ssl_user_id'           => $this->elavon_user_id,
                'ssl_pin'               => $this->elavon_pin,
                'ssl_card_number'       => $card_num,
                'ssl_exp_date'          => $exp_month . substr($exp_year, -2), // requires 2 digit year
                'ssl_amount'            => $wc_order->get_total(),
                'ssl_cvv2cvc2'          => $cvc,
                'ssl_invoice_number'    => $wc_order->get_order_number(),
                'ssl_cvv2cvc2_indicator'=> $cvc ? '1' : '9', // if cvv2 exists, present else not present
                'ssl_description'       => get_bloginfo('blogname').' Order #'.$wc_order->get_order_number(),
                'ssl_company'           => str_replace('&', '-', $wc_order->get_billing_company()),
                'ssl_first_name'        => $wc_order->get_billing_first_name(),
                'ssl_last_name'         => $wc_order->get_billing_last_name(),
                'ssl_avs_address'       => str_replace('&', '-', substr($wc_order->get_billing_address_1(), 0, 20)), // maximum of 20 characters per spec
                'ssl_address2'          => str_replace('&', '-', substr($wc_order->get_billing_address_2(), 0, 20)),
                'ssl_city'              => $wc_order->get_billing_city(),
                'ssl_state'             => $wc_order->get_billing_state(),
                'ssl_country'           => $wc_order->get_billing_country(),
                'ssl_avs_zip'           => preg_replace("/[^A-Za-z0-9]/",  "", $wc_order->get_billing_postcode()),
                'ssl_phone'             => substr(preg_replace("/[^0-9]/", "", $wc_order->get_billing_phone()), 0, 14),
                'ssl_email'             => $wc_order->get_billing_email(),
                'ssl_show_form'         => 'FALSE',
                'ssl_result_format'     => 'ASCII'];
            $response = $this->queryMerchant($submit_data, $wc_order, $order_id);
            if ($response) { return [ 'result'=>'success', 'redirect'=>$this->get_return_url( $wc_order ) ]; }
        }
        private function queryMerchant($request=[], $wc_order, $order_id)
        {
            global $woocommerce;
            $tags = '';
            foreach ($request as $key => $value) { if ($value <> '') { $tags .= "<$key>".urlencode(str_replace('&', '+', $value))."</$key>"; } }
            $data = "xmldata=<txn>$tags</txn>";
            $url = ELAVON_SANDBOX ? PAYMENT_CONVERGE_URL_TEST : PAYMENT_CONVERGE_URL;
            $args  = [
                'method'     => 'POST',
                'timeout'    => 45,
                'redirection'=> 5,
                'httpversion'=> '1.0',
                'blocking'   => true,
                'headers'    => [],
                'body'       => $data,
                'cookies'    => [],
                'sslverify'  => false];
            $strXML = wp_remote_post($url, $args);
            if (empty($strXML)) { return; }
            $result = simplexml_load_string(trim($strXML['body']), 'SimpleXMLElement', LIBXML_NOCDATA);
            $resp   = json_decode(str_replace(':{}',':null',json_encode($result)));
            if (isset($resp->errorCode)) {
                $wc_order->add_order_note( __( "Error: Code $resp->errorCode - $resp->errorMessage on ".wp_date("d-M-Y h:i:s e"), 'bizuno' ) );
                wc_add_notice($resp->errorMessage, 'error' );
                return false;
            } elseif (isset($resp->ssl_result) && $resp->ssl_result == '0') { // update the db with the transaction ID
                if (isset($resp->ssl_cvv2_response) && $resp->ssl_cvv2_response != 'M') {
                    $wc_order->add_order_note( __( 'CVV Mismatch: '.$this->cvv[$resp->ssl_cvv2_response] , 'bizuno' ) );
                }
                if (isset($resp->ssl_avs_response) && !in_array($resp->ssl_avs_response, ['X','Y'])) {
                    $wc_order->add_order_note( __( 'AVS Mismatch: '.$this->avs[$resp->ssl_avs_response] , 'bizuno' ) );
                }
                $wc_order->add_order_note( __( "Elavon result: $resp->ssl_result_message with Transaction ID = $resp->ssl_txn_id and auth code $resp->ssl_approval_code" ), false );
                add_post_meta( $order_id, '_payment_cap_auth',       ELAVON_TRANSACTION_MODE=='yes' ? 'auth' : 'cap' ); // either cap or auth
                add_post_meta( $order_id, '_payment_auth_code',      $resp->ssl_approval_code );
                add_post_meta( $order_id, '_payment_transaction_id', $resp->ssl_txn_id ); // transaction ID from gateway from credit cards that need to be captured to complete the sale
                add_post_meta( $order_id, '_payment_card_hint',      $this->hint ); // credit card hint, format 12**********7890
                $wc_order->payment_complete(); // $args=$resp->ssl_txn_id
//              $wc_order->wc_reduce_stock_levels(); // causes fatal error, should be done in cart anyway.
                $woocommerce->cart->empty_cart();
                return true;
            } elseif (isset($resp->ssl_result) && $resp->ssl_result <> '0') { // update the db with the transaction ID
                wc_add_notice("Oh snap! We had an error processing your card, The message from the gateway: $resp->ssl_result_message", 'error' );
            }
        }
        private function get_card_type($numb)
        {
            $numb1 = trim(str_replace(' ', '', $numb));
            $number= preg_replace('/[^\d]/','',$numb1);
            if     (preg_match('/^3[47][0-9]{13}$/',$number))                { return 'amex'; }
            elseif (preg_match('/^6(?:011|5[0-9][0-9])[0-9]{12}$/',$number)) { return 'discover'; }
            elseif (preg_match('/^5[1-5][0-9]{14}$/',$number))               { return 'mastercard'; }
            elseif (preg_match('/^4[0-9]{12}(?:[0-9]{3})?$/',$number))       { return 'visa'; }
            else { return 'unknown card'; }
        }
        public function thankyou_page()
        {
            if ( $this->instructions ) { echo wpautop( wptexturize( $this->instructions ) ); }
        }
        public function email_instructions( $order, $sent_to_admin, $plain_text=false )
        {
            if ( $this->instructions && ! $sent_to_admin && 'offline' === $order->payment_method && $order->has_status( 'on-hold' ) ) {
                echo wpautop( wptexturize( $this->instructions ) ) . PHP_EOL;
            }
        }
    }
}
add_action( 'plugins_loaded', 'bizuno_payment_converge_method_init' );
function bizuno_payment_cc_add_to_gateways( $gateways ) {
    $gateways[] = 'WC_Gateway_Biz_Credit_Card';
    return $gateways;
}
add_filter( 'woocommerce_payment_gateways', 'bizuno_payment_cc_add_to_gateways' );
