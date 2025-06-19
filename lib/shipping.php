<?php
/**
 * ISP Hosted WordPress Plugin - shipping class
 *
 * @copyright  2008-2025, PhreeSoft, Inc.
 * @author     David Premo, PhreeSoft, Inc.
 * @version    3.x Last Update: 2025-03-13
 * @filesource ISP WordPress /bizuno-erp/lib/shipping.php
 */

namespace bizuno;

class shipping extends common
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
/*    public function rates_list($request)
    {
        $package= $this->rest_open($request); // do not use request since this is a post
        $this->getRatesPrep();
        $layout = ['pkg'=>['destination'=>$package], 'rates'=>[]];
        compose('bizuno', 'export', 'shippingRates', $layout);
        $output = ['rates'=>$layout['rates']];
        return $this->rest_close($output);
    } */
    /********************** Local get rate and return either local or REST *******************/
    public function getRates($package=[])
    {
//      global $io;
        $package['destination']['totalWeight'] = WC()->cart->get_cart_contents_weight();
        $layout = ['pkg'=>$package, 'rates'=>[]];
        if (empty($package['destination']['postcode'])) { return $layout['rates']; }
        $this->client_open();
        msgDebug("\nCalling API with package = ".print_r($package['destination'], true));
        $resp = json_decode($this->cURL('get', $package['destination'], 'shipping/getRates'), true);
        msgDebug("\nBizuno-API getRates received back from REST: ".print_r($resp, true));
        if (isset($resp['message'])) { msgMerge($resp['message']); }
        $layout['rates'] = !empty($resp['rates']) ? $resp['rates'] : [];
        msgDebug("\nSending back to WooCommerce: ".print_r($layout, true));
        $this->client_close();
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
