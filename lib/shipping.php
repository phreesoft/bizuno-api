<?php
/**
 * WooCommerce - Bizuno API - Shipping methods
 * This class contains the  methods to handle shipping rate quotes
 *
 * @copyright  2008-2024, PhreeSoft, Inc.
 * @author     David Premo, PhreeSoft, Inc.
 * @version    3.x Last Update: 2024-02009
 * @filesource /wp-content/plugins/bizuno-api/lib/shipping.php
 */

namespace bizuno;

class api_shipping extends api_common
{

    function __construct($options=[])
    {
        parent::__construct($options);
    }
    /**************** REST Endpoints to retrieve shipping rates *************/
    /**
     * Fetches the shipping rates using the Bizuno carriers and settings.
     * @param array $request
     */
    public function rates_list($request)
    {
        $package= $this->rest_open($request); // do not use request since this is a post
        $this->getRatesPrep();
        $layout = ['pkg'=>['destination'=>$package], 'rates'=>[]];
        compose('bizuno', 'export', 'shippingRates', $layout);
        $output = ['rates'=>$layout['rates']];
        return $this->rest_close($output);
    }
    /********************** Local get rate and return either local or REST *******************/
    public function getRates($package=[])
    {
        $package['destination']['totalWeight'] = WC()->cart->get_cart_contents_weight();
        $layout = ['pkg'=>$package, 'rates'=>[]];
        if (empty($package['destination']['postcode'])) { return $layout['rates']; }
        $this->client_open();
        if ($this->api_local) { // we're here so just update the db
            compose('bizuno', 'export', 'shippingRates', $layout);
        } else { // Use REST to connect and transmit data
            msgDebug("\nCalling RESTful API with package = ".print_r($package, true));
            $resp = $this->restGo('get', $this->options['url'], 'shipping/rates', $package['destination']);
            msgDebug("\nBizuno-API getRates received back from REST: ".print_r($resp, true));
            if (isset($resp['message'])) { msgMerge($resp['message']); }
            $layout['rates'] = !empty($resp['rates']) ? $resp['rates'] : [];
        }
        $this->client_close();
        msgDebug("\nReturned from API getRates with layout = ".print_r($layout, true));
        return $layout['rates'];
    }
    private function getRatesPrep()
    {
        $_POST['country_s']  = clean('country',    ['format'=>'alpha_num', 'default'=>''], 'get');
        $_POST['state_s']    = clean('state',      ['format'=>'alpha_num', 'default'=>''], 'get');
        $_POST['postcode_s'] = clean('postcode',   ['format'=>'alpha_num', 'default'=>''], 'get');
        $_POST['city_s']     = clean('city',       ['format'=>'alpha_num', 'default'=>''], 'get');
        $_POST['address1_s'] = clean('address1',   ['format'=>'alpha_num', 'default'=>''], 'get');
        $_POST['address2_s'] = clean('address2',   ['format'=>'alpha_num', 'default'=>''], 'get');
        $_POST['totalWeight']= round(clean('totalWeight',['format'=>'float', 'default'=>0], 'get'), 1);
    }
}
