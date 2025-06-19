<?php
/**
 * ISP Hosted WordPress Plugin - shipping class
 *
 * @copyright  2008-2025, PhreeSoft, Inc.
 * @author     David Premo, PhreeSoft, Inc.
 * @version    3.x Last Update: 2025-06-19
 * @filesource ISP WordPress /bizuno-api/lib/shipping.php
 */

namespace bizuno;

class shipping extends common
{

    function __construct($options=[])
    {
        parent::__construct($options);
    }

    /********************** Local get rate and return either local or REST *******************/
    public function getRates($package=[])
    {
        $package['destination']['totalWeight'] = WC()->cart->get_cart_contents_weight();
        $layout = ['pkg'=>$package, 'rates'=>[]];
        if (empty($package['destination']['postcode'])) { return $layout['rates']; }
        $this->client_open();
        msgDebug("\nCalling API with package = ".print_r($package['destination'], true));
        $resp = json_decode($this->cURL('get', $package['destination'], 'shipGetRates'), true);
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
