<?php
/**
 * ISP Hosted WordPress Plugin - product class
 *
 * @copyright  2008-2025, PhreeSoft, Inc.
 * @author     David Premo, PhreeSoft, Inc.
 * @version    3.x Last Update: 2025-04-16
 * @filesource ISP WordPress /bizuno-erp/lib/product.php
 */

namespace bizuno;

class product extends common
{
    public $userID = 0;

    function __construct($options=[])
    {
        parent::__construct($options);
        $this->fileBirdActive = is_plugin_active ( 'filebird/filebird.php' ) || is_plugin_active ( 'filebird-pro/filebird.php' ) ? true : false;
    }

    /********************** Cron Events ************************/
    public function cron_image()
    {
        // This takes a LONG LONG LONG time, typically makes the script time out so it was separated from the main upload script and moved here to a cron
        msgDebug("\nEntering cron_image.");
        $imageQueue = \get_option('bizuno_image_queue');
        if (empty($imageQueue)) { return; } // queue is empty, nothing to do
        foreach ($imageQueue as $image_id => $filename) {
            $attach_data = \wp_generate_attachment_metadata( $image_id, $filename );
            msgDebug("\nFinished wp_generate_attachment_metadata");
            \wp_update_attachment_metadata( $image_id, $attach_data ); // TAKES REALLY LONG, UP TO A MINUTE, MOVE TO CRON
            msgDebug("\nfinshed wp_update_attachment_metadata");
            unset($imageQueue[$image_id]);
            \update_option( 'bizuno_image_queue', $imageQueue ); // save as we go if the script times out the queue will still be reduced for the next iteration
        }
    }

    /********************** REST Endpoints ************************/
    public function product_price($request)
    {
        $this->rest_open($request);
        $sku   = clean('sku', 'text', 'get');
        $cID   = intval(substr(clean('cID', 'text', 'get'), 1));
        $qty   = clean('qty', 'text', 'get');
        $args  = ['sku'=>$sku, 'cID'=>$cID, 'qty'=>$qty];
        msgDebug("\ncalling get Price with args = ".print_r($args, true));
        $price = $this->productPrice($args);
        $output= ['result'=>!empty($price)?'Success':'Fail', 'price'=>$price];
        msgDebug("\nREST returning results: ".print_r($output, true));
        return $this->rest_close($output);
    }
    public function product_update($request)
    {
        $data  = $this->rest_open($request);
        $postID= $this->productImport($data['data']);
        $output= ['result'=>!empty($postID)?'Success':'Fail', 'ID'=>$postID];
        return $this->rest_close($output);
    }
    public function product_refresh($request)
    {
        $data   = $this->rest_open($request);
        $success= $this->productRefresh($data['data']);
        $output = ['result'=>!empty($success)?'Success':'Fail'];
        return $this->rest_close($output);
    }
    public function product_sync($request)
    {
        $data  = $this->rest_open($request);
        $result= $this->productSync($data['data']);
        $output= ['result'=>$result?'Success':'Fail'];
        return $this->rest_close($output);
    }

    /************** Bizuno Processing ******************/
    // WooCommerce Filter Hook call to get users price from Bizuno
    public function adjustPrice($price, $product)
    {
        $user = \wp_get_current_user();
        if (empty($user) || !$this->bizActive) { return $price; } // not logged in
        $sku = $product->get_sku();
        if (!empty($GLOBALS['bizBooks']['sku'][$sku]['price'])) { // see if it is cached
            return $GLOBALS['bizBooks']['sku'][$sku]['price'];
        }
        $this->client_open();
        $wID = \get_user_meta( $user->ID, 'bizuno_wallet_id', true);
        msgDebug("\nRead wallet ID = $wID");
        if (empty($wID)) { $this->client_close(); return $price; } // not linked to Bizuno
        $args= ['sku'=>$sku, 'cID'=>$wID, 'qty'=>1];
        if ($this->api_local) { // we're here so just go and get it
            $bizPrice = $this->productPrice($args);
        } else { // Use REST to connect and fetch the data
            $resp = $this->restGo('get', $this->options['url'], 'product/price', $args);
            if (isset($resp['message'])) { msgMerge($resp['message']); }
            $bizPrice = !empty($resp['price']) ? $resp['price'] : $price;
        }
        if (!empty($bizPrice)) { $price = $bizPrice; }
        $this->client_close();
        $GLOBALS['bizBooks']['sku'][$args['sku']]['price'] = $price;
        return $price;
    }

    /**
     * Fetches the price via the Bizuno price manager for a given customer
     * @param type $args
     * @return type
     */
    public function productPrice($args=[])
    {
        $layout = ['args'=>$args];
        \bizuno\compose('inventory', 'prices', 'quote', $layout);
        if     (!empty($layout['content']['sale_price'])){ $price = $layout['content']['sale_price']; }
        elseif (!empty($layout['content']['price']))     { $price = $layout['content']['price']; }
        return $price;
    }

    /**
     * Starts the import of product to WooCommerce
     * @param type $product
     * @return type
     */
    public function productImport($product)
    {
        global $wcProduct;
        set_time_limit(60); // set timeout to 1 minute, imagemgk is verty slow when doing a full upload
        msgDebug("\nEntering productImport with sku = {$product['SKU']} and sizeof product = ".sizeof($product));
        if (empty($product['SKU'])) { return msgAdd("Bad SKU passed. Needs to be the inventory field id tag name (SKU)."); }
        $slug = !empty($product['WooCommerceSlug']) ? $product['WooCommerceSlug'] : $product['Description'];
        if (isset($product['WeightUOM'])) { // convert weight (need to convert kg,lb,oz,g)
            $weightUOM= !empty($product['WeightUOM']) ? strtolower($product['WeightUOM']) : 'lb';
            $wooWt    = \get_option('woocommerce_weight_unit');
            $wp_weight= !empty($wooWt) ? strtolower($wooWt) : 'lb';
            $weight   = isset($product['Weight']) ? $this->convertWeight($product['Weight'], $weightUOM, $wp_weight) : '';
        }
        if (isset($product['DimensionUOM'])) { //convert dim (need to convert m,cm,mm,in,yd)
            $dim = strtolower($product['DimensionUOM']);
            $wordpress_dim = strtolower(\get_option('woocommerce_dimension_unit'));
            $length = isset($product['ProductLength'])? $this->convertLength($product['ProductLength'],$dim, $wordpress_dim) : '';
            $width  = isset($product['ProductWidth']) ? $this->convertLength($product['ProductWidth'], $dim, $wordpress_dim) : '';
            $height = isset($product['ProductHeight'])? $this->convertLength($product['ProductHeight'],$dim, $wordpress_dim) : '';
        }

        $this->productType = !empty($product['Type']) ? strtolower($product['Type']) : 'si'; // allows change of product type on the fly
        $wcProduct = $this->getProduct($product['SKU']);
        // Let's go
        $product_id = $wcProduct->get_id();
        msgDebug("\nSetting fields and meta data");
        $wcProduct->set_date_modified(\wp_date('Y-m-d H:i:s'));
        $wcProduct->set_description(!empty($product['DescriptionLong']) ? $product['DescriptionLong'] : $product['DescriptionSales']);
        $wcProduct->set_length($length);
        $wcProduct->set_width($width);
        $wcProduct->set_height($height);
        $wcProduct->set_weight($weight);
        $wcProduct->set_manage_stock(!empty($product['Virtual']) ? 'no' : 'yes');
        $wcProduct->set_menu_order(!empty($product['MenuOrder']) ? (int)$product['MenuOrder'] : 99);
        $wcProduct->set_name($product['Description']);
        msgDebug("\nSetting price to ".$product['Price']);
        $wcProduct->set_price(floatval($product['Price']));
        $wcProduct->set_regular_price(floatval($product['Price']));
        $wcProduct->set_sale_price('');
//      $wcProduct->set_regular_price(!empty($product['RegularPrice']) ? $product['RegularPrice'] : '');
//      $wcProduct->set_sale_price(!empty($product['SalePrice']) ? $product['SalePrice'] : '');
        $wcProduct->set_short_description(!empty($product['DescriptionSales']) ? $product['DescriptionSales'] : $product['Description']);
        $wcProduct->set_slug($this->getPermaLink($slug));
//      $wcProduct->set_status('published');
        $wcProduct->set_stock_quantity($product['QtyStock'] > 0 ? $product['QtyStock'] : 0);
        $wcProduct->set_stock_status($product['QtyStock'] > 0 ? 'instock' : 'outofstock');
        $wcProduct->set_tax_status('taxable');
        msgDebug("\nChecking on sendMode and starting appropriate sequence");
        switch ($product['sendMode']) {
            default: // default needs to be here so the individula upload sends everyhthing.
            case 1: $replaceImage = true;// Full Upload (Slowest - replace/regenerate all images)
            case 2: // Full Product Details (Skip images if present)
                $this->productImage($product, $product_id, !empty($replaceImage) ? true : false); // Set images
            case 3: // Product Core Info (No Categories/Images)
                $this->productAttributes($product, $product_id); // Update attributes
                $this->productRelated($product); // Set related products
                if (!empty($product['invOptions'])) { $this->productVariations($product['invOptions'], $product_id); } // check for master stock type
                $this->productMetadata($product);
                $this->productTags($product, $product_id);
                $this->productCategory($product, $product_id); //update category
                $this->productPriceLevels($product, $product_id); // update the price levels, if present
                break;
        }
        msgDebug("\nSaving the product.");
        $wcProduct->save();
        msgDebug("\nChecking for Sell Qtys"); // Checking for price levels by Item
        if (!empty($product['PriceByItem'])) { $this->priceVariations($wcProduct, $product['PriceByItem']); }
        return $product_id;
    }

    private function getProduct($sku='')
    {
        $existingID = \wc_get_product_id_by_sku($sku);
        msgDebug("\nFetched product ID = $existingID");
        if (empty($existingID)) { // The new way returns zero for products uploaded in early versions of the API, try to old way, just in case
            $existingID = dbGetValue(PORTAL_DB_PREFIX.'postmeta', 'post_id', "`meta_key` = '_sku' AND `meta_value`='".addslashes($sku)."'", true);
            msgDebug("\nTried the old way, product ID is now = $existingID");
        }
        // check to make sure the type in WooCommerce matches the type being uploaded
        if ( empty($existingID) ) {
            $wcProduct = $this->newProduct($sku, $this->productType);
            msgDebug("\nMade new product, product ID is now = ".$wcProduct->get_id());
        } else {
            $wcProduct = $this->checkType($existingID, $this->productType);
        }
        return $wcProduct;
    }

    private function newProduct($sku, $type='si')
    {
        msgDebug("\nStarting class WC_Product_Simple or WC_Product_Variable");
        switch ($type) {
//          case 'external':$wcProduct = new WC_Product_External(); break; // not supported
//          case 'grouped': $wcProduct = new WC_Product_Grouped();  break; // not supported
            case 'ms': msgDebug("\nStarting WC_Product_Variable for new product");
                $wcProduct = new \WC_Product_Variable(); break;
            default:
            case 'si': msgDebug("\nStarting WC_Product_Simple for a new product");
                $wcProduct = new \WC_Product_Simple();   break;
        }
        $wcProduct->set_sku($sku);
//      $wcProduct->set_date_created(!empty($product['DateCreated']) ? $product['DateCreated'] : \wp_date('Y-m-d H:i:s'));
        $wcProduct->save(); // get an ID
        return $wcProduct;
    }

    private function checkType($product_id=0, $product_type='si')
    {
        msgDebug("\nEntering checkVariable");
        $product = \wc_get_product( $product_id );
/*
// This was removed as it puts products back to simple although the user may have set to variable in WordPress manually and enters variations at the site level.
// Also for PhreeSoft pricing customizations.
        if ( $product->is_type( 'variable' ) && $product_type<>'ms' ) { // Make sure it is of type variable product
            msgDebug("\nSetting type to simple");
            \wp_remove_object_terms( $product_id, 'variable', 'product_type' );
            \wp_set_object_terms( $product_id, 'simple', 'product_type', true );
        } else */
        if ( !$product->is_type( 'variable' ) && $product_type=='ms') { // changes the product type to variable if Master Stock type
            msgDebug("\nSetting type to variable");
            \wp_remove_object_terms( $product_id, 'simple', 'product_type' );
            \wp_set_object_terms( $product_id, 'variable', 'product_type', true );
        }
        return $product;
    }

    private function productRelated($product)
    {
//      global $wcProduct;
        msgDebug("\nEntering productRelated");
        // This needs to be updated to the new method, probably part of WC_Product_Simple
        //
        //
        // fetch related id
        if (!empty($product['invAccessory']) && is_array($product['invAccessory'])) {
            $product['related'] = [];
            foreach ($product['invAccessory'] as $related) {
                $product_id = dbGetValue(PORTAL_DB_PREFIX.'postmeta', 'post_id', "`meta_key` LIKE '_sku' AND `meta_value`='{$related}'", true);
                if ($product_id !== false) { $product['related'][] = $product_id; }
            }
            msgDebug("related items found:".print_r($product['related'], true));
        }
        if (isset($product['related'])) {
//            dbGetResult("DELETE FROM `". PORTAL_DB_PREFIX . "postmeta` WHERE post_id = '". (int)$product_id . "' AND meta_key = '_crosssell_ids';");
//            dbGetResult("INSERT INTO " . PORTAL_DB_PREFIX . "postmeta SET post_id = '"   . (int)$product_id . "', meta_key = '_crosssell_ids' , meta_value = '" . $product['related'] . "';");
        }
    }

    /**
     * Enters the volume price if included with feed
     *
     * REQUIRES THE WooCommerce Bulk Pricing plug-in set to percentage discount (default)
     * https://wordpress.org/plugins/woocommerce-bulk-discount/
     *
     * @param type $product
     * @param type $product_id
     * @return type
     */
    protected function productPriceLevels($product, $product_id)
    {
        msgDebug("\nEntering productPriceLevels and checking for Woocommerce Bulk Discount plugin active");
        if ( !is_plugin_active( 'woocommerce-bulk-discount/woocommerce-bulk-discount.php' ) ) { return msgDebug("\nBulk Discount Plugin not active."); }
        $resetMeta = true;
        if (!empty($product['PriceLevels']) && is_array($product['PriceLevels']) ) {
            foreach ($product['PriceLevels'] as $price) {
                if (empty($price['default'])) { continue; } // only use values from default price sheet, if specified
                $description = '<table style="border:1px solid black;width:300px"><tr><th colspan="2">Quantity Pricing</th></tr><tr><th>Qty</th><th>Price</th></tr>';
                for ($i=0; $i<sizeof($price['levels']); $i++) {
                    if ($i==0) { // first price is single qty, save to calc discount
                        $full_price = !empty($price['levels'][$i]['price']) ? $price['levels'][$i]['price'] : 999999;
                        continue;
                    } // skip the first price level as it is the single unit price
                    $resetMeta = false; // if we are here, there is more than 1 price in this discount group
                    $description .= '<tr><td style="border: 1px solid black;text-align:center">'.$price['levels'][$i]['qty'].'</td><td style="border: 1px solid black;text-align:center">$ '.number_format($price['levels'][$i]['price'], 2).'</td></tr>';
                    msgDebug("\nUpdating pricing for quantity: ".$price['levels'][$i]['qty']);
                    update_post_meta($product_id, "_bulkdiscount_quantity_$i",      $price['levels'][$i]['qty']);
                    update_post_meta($product_id, "_bulkdiscount_discount_$i",      round(((1 - $price['levels'][$i]['price']/$full_price) * 100), 3));
                    update_post_meta($product_id, "_bulkdiscount_discount_fixed_$i",round(($full_price - $price['levels'][$i]['price']), 3));
                }
                // clean up extra levels, if they are present, i.e. number of discount levels were reduced.
                for ($j=$i+1; $j<100; $j++) {
                    $idx = get_post_meta( $product_id, "_bulkdiscount_quantity_$j");
                    if (empty($idx)) {
                        $j=100; // stop the loop
                    } else {
                        delete_post_meta( $product_id, "_bulkdiscount_quantity_$j");
                        delete_post_meta( $product_id, "_bulkdiscount_discount_$j");
                        delete_post_meta( $product_id, "_bulkdiscount_discount_fixed_$j");
                    }
                }
            }
            $description .= '</table>';
        }
        msgDebug("\nSetting the meta data for the description and enable flag");
        if ($resetMeta) { // clear any fields that may have been there from a prior discount
            update_post_meta( $product_id, '_bulkdiscount_enabled', 'no');
            delete_post_meta( $product_id, '_bulkdiscount_text_info');
        } else {
            update_post_meta( $product_id, '_bulkdiscount_enabled', 'yes');
            update_post_meta( $product_id, '_bulkdiscount_text_info', $description);
        }
    }

    private function productMetadata($product)
    {
        global $wcProduct;
        if (!empty($product['SearchCode']))      { $wcProduct->update_meta_data('biz_search_code',      $product['SearchCode']); }
        msgDebug("\nEntering productMetadata and checking for YOST SEO plugin active");
        if ( !is_plugin_active( 'wordpress-seo/wp-seo.php' ) ) { return; }
        if (!empty($product['MetaDescription'])) { $wcProduct->update_meta_data('_yoast_wpseo_metadesc',$product['MetaDescription']); }
    }

    /**
     * Set the tags
     * @param type $product
     * @param type $product_id
     * @return boolean
     */
    private function productTags($product, $product_id)
    {
        msgDebug("\nEntering productTags product_id = $product_id with WooCommerceTags = ".print_r($product['WooCommerceTags'], true));
        if (empty($product['WooCommerceTags'])) { return; }
        $IDs = [];
        $current = \get_the_terms($product_id, 'product_tag');
        msgDebug("\nRetrieved terms = ".print_r($current, true));
        foreach ( (array)$current as $term) {
            if (!empty($term->name)) { $IDs[] = $term->name; }
        }
        $sep = strpos($product['WooCommerceTags'], '|') !== false ? '|' : ';'; // new separator is the |
        $tags= explode($sep, $product['WooCommerceTags']);
        foreach ($tags as $tag) {
            if (!empty(trim($tag))) { $IDs[] = trim($tag); } // sanitize_title makes the slug (lower no spaces) and also is used as the label which we don't want
        }
        msgDebug("\nSetting post tags to IDs = ".print_r($IDs, true));
        $results = \wp_set_object_terms($product_id, $IDs, 'product_tag');
        msgDebug("\nResults from setting tags = ".print_r($results, true));
    }

    /**
     *
     * @param type $product
     * @param type $product_id
     * @return boolean
     */
    private function productCategory($product, $product_id)
    {
        msgDebug("\nEntering productCategory");
        if (empty($product['WooCommerceCategory'])) {
            return msgAdd("Error - the category was not passed for product: {$product['SKU']}, it must be set manually in WooCommerce.", 'caution');
        }
        $this->endCatOnly = false;
        msgDebug("\nWorking with raw category: {$product['WooCommerceCategory']}");
        // Multiple category breadcrumbs may be passed, use semi-colon as the separator
        $categories = explode(";", $product['WooCommerceCategory']);
        foreach ($categories as $category) {
            $parent = 0;
            $descName = $niceName = '';
            $sep = strpos($category, '|')!== false ? '|' : ':'; // new separator is the |
            $breadcrumbs = explode($sep, $category);
            foreach ($breadcrumbs as $breadcrumb) { // starts at the top level and goes down.
                $term = trim($breadcrumb);
                if (empty($term)) { continue; }
                $descName = trim($term);
                $niceName.= '-'.trim(strtolower(str_replace([':',' '], '-', $term)), " -"); // grow the nice name with the category
                $termIDs  = term_exists( $descName, 'product_cat', !empty($parent)?$parent:null );
                msgDebug("\nSearching for descName = $descName, parent = $parent and resulted in termIDs = ".print_r($termIDs, true));
                if (empty($termIDs)) {
                    $termData= [ 'slug'=>$niceName, 'parent'=>$parent ]; // 'description'=>$descName, // leave description blank so user can edit through WooCommerce
                    msgDebug("\nInserting term data: ".print_r($termData, true));
                    $termIDs = wp_insert_term( $descName, 'product_cat', $termData );
                }
                msgDebug("\nSetting post termIDs: ".print_r($termIDs, true));
                wp_set_post_terms( $product_id, [$termIDs['term_id']], 'product_cat', $this->endCatOnly?false:true );
                $parent = $termIDs['term_id'];
            }
            // Check just the lowest on the category tree if endCatOnly is true
            if ($this->endCatOnly) {
                wp_set_post_terms( $product_id, [$termIDs['term_id']], 'product_cat', true );
//              $wcProduct->set_category_ids();  // New way
            }
        }
        return true;
    }

    /**
     * Add product attributes, this method just hard codes the value and avoids terms and taxonomy which creates a new term for every possible value
     * @param type $product
     * @param type $product_id
     * @return type
     */
    private function productAttributes($product, $product_id)
    {
//      global $wcProduct; // new way
        msgDebug("\nEntering productAttributes");
        if (empty($product['Attributes'])) { return; }
        $result      = dbGetMulti(PORTAL_DB_PREFIX.'term_taxonomy', "taxonomy LIKE 'pa_%'");
        $pa_attr_ids = [];
        foreach ($result as $row) { $pa_attr_ids[] = $row['term_taxonomy_id']; }
        if (sizeof($pa_attr_ids)) { // clear out the current attributes
            dbGetResult("DELETE FROM ".PORTAL_DB_PREFIX."term_relationships WHERE object_id=$product_id AND term_taxonomy_id IN (".implode(',',$pa_attr_ids).")");
        }
        $productAttr = [];
        foreach ($product['Attributes'] as $idx => $row) {
            if (empty($row['title']) || empty($row['index'])) { continue; }
            $attrSlug= $this->getPermaLink($row['index']);
//          $attrSlug= $this->getPermaLink($product['AttributeCategory'].'_'.strtolower($row['index'])); // creates a lot of attributes and causes filtering issues
            $exists  = dbGetValue(PORTAL_DB_PREFIX.'woocommerce_attribute_taxonomies', 'attribute_name', "attribute_name='$attrSlug'");
            if (!$exists) {
                $newAttr = [
                    'attribute_name'   => $attrSlug,
                    'attribute_label'  => $row['title'],
                    'attribute_type'   => 'text',
                    'attribute_orderby'=> 'name_num',
                    'attribute_public' => 0];
                dbWrite(PORTAL_DB_PREFIX.'woocommerce_attribute_taxonomies', $newAttr);
            }
            $productAttr["pa_$attrSlug"] = ['name'=>$row['title'],'value'=>$row['value'],'position'=>$idx,'is_visible'=>1,'is_variation'=>0,'is_taxonomy'=>0];
            // Update postmeta with attribute key => value pair for searching...
            update_post_meta( $product_id, "biz_".strtolower($row['index']), $row['value'] );
        }
        msgDebug("\nUpdating product postmeta: ".print_r($productAttr, true));
        update_post_meta( $product_id, '_product_attributes', $productAttr );
    }

    /**
     *
     * @param type $value
     * @return type
     */
    private function getPermaLink($value)
    {
        $test1 = str_replace(':', '_', $value);
        $test2 = str_replace([' ', '/', '.'], '-', trim($test1));
        $test3 = preg_replace("/[^a-zA-Z0-9\-\_]/", "", $test2);
        while (strpos($test3, '--') !== false) { $test3 = str_replace('--', '-', $test3); }
        return strtolower($test3);
    }

    /**
     * Creates/Updates the variations for master stock type items
     * The variation data format, for each variation:
        $variation_data =  array( 'sku' => '','regular_price' => '22.00', 'sale_price' => '','stock_qty' => 10,
            'attributes' => array( 'size' => 'M', 'color' => 'Green', ) );
     * @param array $variations
     * @param integer $product_id
     * @return type
     */
    private function productVariations($variations, $product_id)
    {
        global $wcProduct;
        msgDebug("\nEntering productVariations with variations = ".print_r($variations, true));
        // Process the attributes
        $allAttrs = $wcProduct->get_attributes();
        $attrNames= [];
        foreach ($allAttrs as $tmp) { $attrNames[] = $tmp['name']; }
        $cnt      = 0;
        foreach ($variations['attributes'] as $attr) {
            msgDebug("\nProcessing attribute".print_r($attr, true));
            $attribute = new \WC_Product_Attribute();
            $attribute->set_name( $attr['name'] );
            $attribute->set_options( $attr['options'] );
            $attribute->set_position( $cnt );
            $attribute->set_visible( true );
            $attribute->set_variation( true ); // here it is
            $allAttrs[$key] = $attribute;
            $key = array_search($attr['name'], $attrNames);
            if (false===$key) { msgDebug("\nAdding new attribute"); $allAttrs[]     = $attribute; }
            else              { msgDebug("\nUpdating attribute");   $allAttrs[$key] = $attribute; }
            $cnt++;
        }
        $wcProduct->set_attributes( $allAttrs );
//      $wcProduct->save();

        // get the current variations keyed by sku for searching
        $existingIDs = $this->getCurrentVariations($product_id);
        // foreach variation in the request
        foreach ( $variations['variations'] as $value ) {
            msgDebug("\nWorking with variation value: ".print_r($value, true));
            $variation_id = 0;
            if (!empty($existingIDs)) { $variation_id = array_shift($existingIDs); }
            else { // make a new variation
                msgDebug("\nCreating new Variation post.");
                $product = \wc_get_product($product_id);
                $variation_post = [
                    'post_title'  => $product->get_name(),
                    'post_name'   => 'product-'.$product_id.'-variation',
                    'post_status' => 'publish',
                    'post_parent' => $product_id,
                    'post_type'   => 'product_variation',
                    'guid'        => $product->get_permalink()];
                $variation_id = \wp_insert_post( $variation_post );
            }
            msgDebug("\nVariation ID = $variation_id");
            $variation = new \WC_Product_Variation( $variation_id );
            msgDebug("\nSetting the SKU to {$value['sku']}");
            $variation->set_sku( $value['sku'] );
            msgDebug("\nSetting the attributes: ".print_r($value['attributes'], true));
            $variation->set_attributes( $value['attributes'] );
            $variation->set_weight(''); // weight (reseting)
            $variation->set_regular_price( $value['regular_price'] );
            if ( empty( $value['sale_price'] ) ) {
                $variation->set_price( $value['regular_price'] );
                $variation->set_sale_price( '' );
            } else {
                $variation->set_price( $value['sale_price'] );
                $variation->set_sale_price( $value['sale_price'] );
            }
            if ( ! empty($value['stock_qty']) ){
                $variation->set_stock_quantity( $value['stock_qty'] );
                $variation->set_manage_stock(true);
                $variation->set_stock_status('');
            } else {
                $variation->set_manage_stock(false);
            }
            $variation->save(); // Save the data
        }
        msgDebug("\nSetting default variations to ".print_r($variations['variations'][0]['attributes'], true));
        $wcProduct->set_default_attributes( $variations['variations'][0]['attributes'] );
        // delete left over variants that are no longer used
        if (sizeof($existingIDs) > 0) { // We still have some more variations, delete them
            foreach ($existingIDs as $exID) {
                $variation_id = $exID->ID;
                msgDebug("\nDeleting existing ID: $variation_id");
                $variation = new \WC_Product_Variation( $variation_id );
                $variation->delete();
            }
        }
    }

    /*
     * Get all variation ID's
     */
    private function getCurrentVariations($product_id)
    {
        $output = [];
        $args = ['post_type'=>'product_variation', 'post_status'=>array( 'private', 'publish' ),
            'numberposts'=>-1, 'orderby'=>'menu_order', 'order'=>'asc', 'post_parent'=>$product_id];
        $varIDs = get_posts( $args );
        foreach ($varIDs as $variation) {
            $variation_id = $variation->ID;
            $output[] = $variation_id;
        }
        msgDebug("\nReturning from getCurrentVariations with output: ".print_r($output, true));
        return $output;
    }

    /**
     *
     * @param type $product
     * @param type $product_id
     * @return type
     */
    private function productImage($product, $product_id, $replace=false)
    {
        global $wcProduct;
        msgDebug("\nEntering productImage with product ID = $product_id");
        if (empty($product['ProductImageFilename'])) { return; }
        $media = [];
        require_once( ABSPATH.'wp-admin/includes/image.php' );
        $this->setImageProps($media, $product['ProductImageDirectory'], $product['ProductImageFilename'], $product['ProductImageData']);
        if (!empty($product['Images']) && is_array($product['Images'])) {
            msgDebug("\nReady to process extra Images with size of Images tag = ".sizeof($product['Images']));
            foreach ($product['Images'] as $image) {
                $this->setImageProps($media, $image['Path'], $image['Filename'], $image['Data']);
            }
        } else { msgDebug("\nOnly one image, it will become the primary."); }
        if (empty($media)) {
            msgDebug("\nReturning from productImage with no images found!");
            return;
        } // No images uploaded
        $this->setImageCleaner($product_id); // takes out the trash
        // ready to set images, since they are searched and id'ed based on the path, we only need the meta index to retain the position
        $props  = array_shift($media);
        $imgIdx = $this->setImage($props, $product_id, $replace);
        msgDebug("\nReturned from setImage with thumbnail post ID = $imgIdx");
        if (!empty($imgIdx)) {
//          update_post_meta( $product_id, '_thumbnail_id', $imgIdx ); // Old way
            $wcProduct->set_image_id($imgIdx);
        }
        // Set the image gallery (for the rest of the images)
        msgDebug("\nReady to process extra images with media = ".print_r($media, true));
        $xIDs   = [];
        foreach ($media as $props) {
            $imgIdx = $this->setImage($props, $product_id, $replace);
            if (!empty($imgIdx)) { $xIDs[] = $imgIdx; }
        }
        $wcProduct->set_gallery_image_ids($xIDs);
//      update_post_meta($product_id, '_product_image_gallery', implode(',', $xIDs));  // Old Way
    }

    private function setImageProps(&$media, $path='', $name='', $data='')
    {
        if (empty($data)) { return; }
        $tmp0 = 'products/'.(!empty($path) ? $path : ''); // from root upload folder
        $tmp1 = str_replace('/./', '/', $tmp0);
        $media[] = ['path' => rtrim($tmp1, '/').'/', 'name' => $name, 'data' => $data];
    }

    /**
     * Cleans out duplicates and other issues from earlier releases
     * @param type $activeIDs
     * @param type $product_id
     */
    private function setImageCleaner($product_id)
    {
        msgDebug("\nEntering setImageCleaner with product_id = $product_id");
        // first check thumbnails for multiple records, should only be one
        $metaIDs = dbGetMulti(PORTAL_DB_PREFIX.'postmeta', "post_id=$product_id AND meta_key='_thumbnail_id'");
        if (sizeof($metaIDs) > 1) {
            for ($i=1; $i<sizeof($metaIDs); $i++) { // earlier bug where multiple thumbnails were generated
                msgDebug("\nDeleting duplicate thumbnail with ID = ".print_r($metaIDs[$i], true));
                dbGetResult("DELETE FROM ".PORTAL_DB_PREFIX."postmeta WHERE meta_id={$metaIDs[$i]['meta_id']}");
                \wp_delete_post( $metaIDs[$i]['post_id'], true );
            }
        }
        // If the same image is used for multiple products, then multiple media posts were generated, clean these up and start over.
        $dupImages  = dbGetMulti(PORTAL_DB_PREFIX.'posts', "post_parent<>0 AND post_parent=$product_id AND post_type='attachment'", 'ID', ['ID']);
        foreach ($dupImages as $imageID) {
            msgDebug("\nDeleting duplicate images with ID = ".print_r($imageID, true));
            \wp_delete_post( $imageID['ID'], true );
        }
    }

    /**
     * Uploads the image and puts it in the proper folder, creates to folder if it can.
     * @param array $props
     * @param integer $product_id
     * @param integer $replace
     * @return type
     */
    private function setImage($props, $product_id, $replace=false) {
        msgDebug("\nEntering setImage with sizeof image = ".strlen($props['data']));
        $upload_folder= wp_upload_dir();
        $image_dir    = $upload_folder['basedir']."/{$props['path']}";
        $filename     = $image_dir.$props['name']; // '/path/to/uploads/2013/03/filename.jpg';
        $guid         = $props['path'] . $props['name'];
        msgDebug("\nLooking for all images at: $guid");
        // BOF - Clean out duplicate image posts pointing to the same file
        $postIDs = dbGetMulti(PORTAL_DB_PREFIX.'postmeta', "meta_key='_wp_attached_file' AND meta_value='$guid'", 'post_id', ['post_id']);
        msgDebug("\nRead the following ID's for this image path: ".print_r($postIDs, true));
        for ($i=1; $i<sizeof($postIDs); $i++) { // earlier bug where multiple thumbnails were generated pointing to same file location
            msgDebug("\nDeleting duplicate posts with same path and ID = {$postIDs[$i]['post_id']}");
            dbGetResult("DELETE FROM ".PORTAL_DB_PREFIX."posts WHERE ID={$postIDs[$i]['post_id']}");
            dbGetResult("DELETE FROM ".PORTAL_DB_PREFIX."postmeta WHERE post_id={$postIDs[$i]['post_id']}");
//            \wp_delete_post( $postIDs[$i]['post_id'], true ); // seemed to leave some orphan meta data
        }
        if (sizeof($postIDs)>0) {
            $postExists = dbGetValue(PORTAL_DB_PREFIX.'posts', 'ID', "ID={$postIDs[0]['post_id']}");
            msgDebug("\nRead to see if the record exists: ".print_r($postExists, true));
            if (empty($postExists)) { dbGetResult("DELETE FROM ".PORTAL_DB_PREFIX."postmeta WHERE post_id={$postIDs[0]['post_id']}"); }
            $imgID = !empty($postExists) ? $postIDs[0]['post_id'] :  0;
        } else {
            $imgID = 0;
        }
        // EOF - Clean out duplicate images
        msgDebug("\nWorking with image ID = $imgID. Now testing for image length = ".strlen($props['data']));
        if (!$props['data']) { return; } // no image was sent up to save, just return with no message
        // If skip overwrite and image is present, return with just the ID
        if (!$replace && !empty($imgID)) {
            msgDebug("\nReplace is set to false and the image exists, returning image ID = $imgID");
            return $imgID;
        }
        // NOTE: the str_replace is to necessary to fix a PHP 5 issue with spaces in the base64 encode... see php.net
        $contents    = base64_decode(str_replace(" ", "+", $props['data']));
        if (!is_dir($image_dir)) { if (!@mkdir($image_dir, 0755, true)) { return msgAdd("Cannot create image path: $image_dir"); } }
        $full_path   = $image_dir.$props['name'];
        $dirname     = dirname($full_path);
        if (!is_dir($dirname)) { mkdir($dirname, 0755, true); }
        if (!$handle = fopen($full_path, 'wb')) { return msgAdd("Cannot open Image path: $full_path"); }
        if (fwrite($handle, $contents) === false) { return msgAdd("Cannot write Image file."); }
        fclose($handle);
        msgDebug("\nWrote image image_directory = $image_dir and image_filename = {$props['name']} and image length = ".strlen($props['data']));
        $filetype = wp_check_filetype(basename( $filename ), null);
        $args = [
            'guid'          => $upload_folder['baseurl'] . "/$guid",
            'post_mime_type'=> $filetype['type'],
            'post_title'    => preg_replace( '/\.[^.]+$/', '', basename( $filename ) ),
            'post_content'  => '',
            'post_type'     => 'attachment',
            'post_status'   => 'inherit'];
        if (!empty($imgID)) { $args['ID'] = $imgID; }
        msgDebug("\nReady to insert image from $filename with args = ".print_r($args, true));
        $attach_id = \wp_insert_post( $args, true );
        msgDebug("\nFinished inserting Image with returned id = ".print_r($attach_id, true));
        if (!is_wp_error($attach_id)) {
            if ($attach_id==0) { msgDebug("\nForced attachment_id to be = $imgID"); $attach_id = $imgID; } // for some reason, WP returns 0 when the ID is set going into post and no error
            \update_post_meta( $attach_id, '_wp_attached_file', $guid ); // this needs to be there at a minimum or media details will not render image
            \update_post_meta( $attach_id, '_wp_attachment_metadata', ['file'=>$filename] ); // this needs to be there at a minimum or media details will not render image
            $fileParent = $this->getFBParent($props['path']);
            if (false !== $fileParent) { $this->setFBAttach($attach_id, $fileParent); }
            msgDebug("\nQueuing image to generate all requested sizes later via cron.");
            $imageQueue  = \get_option('bizuno_image_queue');
            if (empty($imageQueue)) {
                \add_option('bizuno_image_queue', []);
                $imageQueue = [];
            }
            $imageQueue[$attach_id] = $filename;
            \update_option( 'bizuno_image_queue', $imageQueue );
            msgDebug("\nReturning from setImage, set image ID = $attach_id");
            return $attach_id;
        }
        msgDebug("\nReturning from setImage with error!");
        return false;
    }

    /**
     *
     * @param type $entry
     * @return string
     */
    private function getImageType($entry)
    {
        $ext = strtolower(substr($entry, strrpos($entry, '.')+1));
        if (in_array($ext, ['png'])) { return 'image/png'; }
        if (in_array($ext, ['jpg','jpeg'])) { return 'image/jpeg'; }
        if (in_array($ext, ['gif'])) { return 'image/gif'; }
        return '';
    }

    private function getFBParent($path)
    {
        global $wpdb;
        msgDebug("\nEntering getFBParent with path = $path");
        if ( !$this->fileBirdActive ) { return; }
        $clnPath= rtrim(trim($path), '/');
        msgDebug("\nCleaned path = $clnPath");
        if (empty($clnPath)) { return false; }
        $dirs   = explode("/", $clnPath);
        msgDebug("\nEXploded into dirs = ".print_r($dirs, true));
        $parent = 0;
        foreach ($dirs as $dir) {
            msgDebug("\nLooking for dir $dir with parent: $parent");
            $sql = "SELECT id FROM {$wpdb->prefix}fbv WHERE name='$dir' AND parent=$parent";
            $result = $wpdb->get_row($sql);
            if (is_null($result)) {
                msgDebug("\nInserting into fbv with dir = $dir and parent = $parent");
                $parent = dbWrite($wpdb->prefix.'fbv', ['name'=>$dir, 'parent'=>$parent]);
            } else {
                msgDebug("\nFound parent ID: $result->id");
                $parent = $result->id;
            }
        }
        msgDebug("\nreturning from getFBParent with parent: $parent");
        return $parent;
    }

    private function setFBAttach($attach_id, $fileParent)
    {
        global $wpdb;
        msgDebug("\nEntering setFBAttach with fileParent = $fileParent");
        if ( !$this->fileBirdActive ) { return; }
        $result = $wpdb->get_row("SELECT folder_id FROM {$wpdb->prefix}fbv_attachment_folder WHERE folder_id=$fileParent AND attachment_id=$attach_id");
        msgDebug("\nRead from fbv_attachment_folder: ".print_r($result, true));
        if (is_null($result)) {
            msgDebug("\nInserting into fbv_attachment_folder parent = $fileParent and attach_id = $attach_id");
            dbGetResult($wpdb->prepare("INSERT INTO {$wpdb->prefix}fbv_attachment_folder (folder_id, attachment_id) VALUES ($fileParent, $attach_id)"));
        }
    }

    /**
     * Inserts/updates the postmeta for a specified post_id
     * @param array $postData - for the post main record
     * @param array $postMeta - for the post meta table
     * @param integer $post_id - if known, database record, if empty then a new record will be created
     * @return integer - post id, relevant if a new record was created
     */
    protected function setPost($postData=[], $postMeta=[], $post_id=0)
    {
        $postData['ID'] = $post_id;
        $data = array_merge($postData, ['meta_input'=>$postMeta]);
        msgDebug("\nInserting/Updating db table posts/postmeta with data = ".print_r($data, true));
        $postID = wp_insert_post($data, true);
        if (is_wp_error($postID)) {
            $errors = $postID->get_error_messages();
            msgDebug("WP DB update error: ".print_r($errors, true));
            return 0;
        }
        return $postID;
    }

    /**
     * Creates/Updates the variations for the sell levels
     * The variation data format, for each variation:
        $variation_data =  array( 'sku' => '','regular_price' => '22.00', 'sale_price' => '','stock_qty' => 10,
            'attributes' => array( 'size' => 'M', 'color' => 'Green', ) );
     * @param object $product
     * @param array $PriceByItem
     * @return null
     */
    private function priceVariations($product, $PriceByItem='')
    {
        msgDebug("\nEntering priceVariations with sellQtys = ".print_r($PriceByItem, true));
        $variations = $this->reformatSellUnits($PriceByItem);
        \update_post_meta( $product->get_id(), 'bizSellQtys', $variations); // save the raw data for view pages
        // Process the attributes
        $allAttrs = $product->get_attributes();
        $attrNames= [];
        foreach ($allAttrs as $key => $tmp) {
            if (is_int($key) || empty($key)) { unset($allAttrs[$key]); } // cleans out some earlier issues
            else { $attrNames[] = $tmp['name']; }
        }
        $cnt      = 0;
        msgDebug("\nEntering processing loop with attr keys = ".print_r($attrNames, true));
        foreach ($variations['attributes'] as $attr) {
            msgDebug("\nProcessing attribute: ".print_r($attr, true));
            $attribute = new \WC_Product_Attribute();
            $attribute->set_name( $attr['name'] );
            $attribute->set_options( $attr['options'] );
            $attribute->set_position( $cnt );
            $attribute->set_visible( true );
            $attribute->set_variation( true ); // identify it as a variation
            $slug= sanitize_title($attr['name']);
            $allAttrs[$slug]= $attribute;
//            $key = array_search($attr['name'], $attrNames);
//            msgDebug("\nSearsch results found key = $key");
//            if (false===$key) { msgDebug("\nAdding new attribute"); $allAttrs[$slug]= $attribute; }
//            else              { msgDebug("\nUpdating attribute");   $allAttrs[$slug] = $attribute; }
            $cnt++;
        }
        msgDebug("\nSetting attributes: ".print_r($allAttrs, true));
        $product->set_attributes( $allAttrs );
        // get the current variations keyed by product id for searching
        $existingIDs = $this->getCurrentVariations($product->get_id());
        // foreach variation in the request
        foreach ( $variations['variations'] as $value ) {
            $variation_id = !empty($existingIDs) ? array_shift($existingIDs) : 0;
            msgDebug("\nVariation ID = $variation_id and value = ".print_r($value, true));
            if ( empty($variation_id) ) {
                $vProd = \wc_get_product($product->get_id());
                $variation_post = [
                    'post_title'  => $vProd->get_name(),
                    'post_name'   => 'product-'.$product->get_id().'-variation',
                    'post_status' => 'publish',
                    'post_parent' => $vProd->get_id(),
                    'post_type'   => 'product_variation',
                    'guid'        => $vProd->get_permalink()];
                $variation_id = \wp_insert_post( $variation_post );
            }
            $variation = new \WC_Product_Variation( $variation_id );
            if ($this->variationNoDiff($variation, $value)) { msgDebug("\nNo changes, continuing..."); continue; }
//          $variation->set_sku( $value['sku'] ); // $product->sku (They all use the same SKU)
            msgDebug("\nSetting the attributes: ".print_r($value['attributes'], true));
            $variation->set_attributes( $value['attributes'] );
            $variation->set_weight($value['weight']); // weight (reseting)
//          $variation->set_length(); //Set the product length.
//          $variation->set_width(); //Set the product width.
//          $variation->set_height(); //Set the product height.
            $variation->set_regular_price( $value['price'] );
            if ( empty( $value['sale_price'] ) ) {
                $variation->set_price( $value['price'] );
                $variation->set_sale_price( '' );
            } else {
                $variation->set_price( $value['sale_price'] );
                $variation->set_sale_price( $value['sale_price'] );
            }
//          if ( ! empty($value['stock']) ) { // commented out to always manage stock
            $variation->set_stock_quantity( $value['stock'] );
            $variation->set_manage_stock(true);
            $variation->set_stock_status('');
            $variation->set_backorders('yes'); // Options: 'yes', 'no' or 'notify'
//          } else {
//              $variation->set_manage_stock(false);
//          }
            msgDebug("\nSaving variation");
            $variation->save(); // Save the data
        }
        // The line below will set the default variation selection, set to none for now
        $default_attr = []; // $variations['variations'][0]['attributes']
        msgDebug("\nSetting default variations to ".print_r($default_attr, true));
        $defAttr = $product->get_default_attributes( );
        if (json_encode($defAttr) <> json_encode($default_attr)) { $product->set_default_attributes( $default_attr ); }
        // delete left over variants that are no longer used
        if (sizeof($existingIDs) > 0) { // We still have some more variations, delete them
            msgDebug("\nLeft over variation ID's Deleting: ".print_r($existingIDs, true));
            foreach ($existingIDs as $variation_id) {
                $variation = new \WC_Product_Variation( $variation_id );
                $variation->delete();
            }
        }
        $product->save(); // samve the parent product
    }

    //     [PriceByItem] => {"total":3,"rows":[{"label":"Each (1 pieces)","qty":"1","weight":79.8,"price":288.47,"stock":7},{"label":"Pallet Layer (10 pieces)","qty":"10","weight":798,"price":2375.66,"stock":0},{"label":"Pallet (20 pieces)","qty":"20","weight":1596,"price":4072.56,"stock":0}]}

    private function reformatSellUnits($sellQtys)
    {
        $qtys = json_decode($sellQtys, true);
        $output = ['attributes'=>[['name'=>'price-discounts', 'options'=>[]]], 'variations'=>[]];
        foreach ($qtys['rows'] as $row) {
            $output['variations'][] = ['qty'=>$row['qty'], 'price'=>$row['price'], 'weight'=>$row['weight'],'stock'=>$row['stock'],
                'attributes'=>['price-discounts'=>$row['label']]];
            $output['attributes'][0]['options'][] = $row['label'];
        }
        msgDebug("\nReturning from reformatSellUnits with output = ".print_r($output, true));
        return $output;
    }

    /**
     * Checks to see if anything values changed in the variation from what is in the db
     * @param type $variation
     * @param type $value
     */
    private function variationNoDiff($variation, $value)
    {
        msgDebug("\nEntering variationNoDiff with value = ".print_r($value, true));
        unset($value['qty']);
        if (!$variation->backorders_allowed()) { return false; } // forse setting of backorders allowed
        $current = [
            'price'     => $variation->get_regular_price(),
            'weight'    => $variation->get_weight(),
            'stock'     => (string)$variation->get_stock_quantity(), // needs to be string so json compares properly
            'attributes'=> $variation->get_attributes()
        ];
        msgDebug("\nComparing to existing: ".print_r($current, true));
        return json_encode($value) == json_encode($current) ? true : false;
    }

    /**
     * Refreshes a block of products in the WooCommerce database
     */
    public function productRefresh($items=[], $verbose=true)
    {
        global $wpdb;
        msgDebug("\nEntering productRefresh with products = ".print_r($items, true));
        foreach ($items as $item) {
            $productID = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key='_sku' AND meta_value='%s' LIMIT 1", $item['SKU'] ) );
//          $productID = \wc_get_product_id_by_sku($item['SKU']); // doesn't always return a hit, flagging not found error below???
            if (empty($productID)) {
                if ($verbose) {msgAdd("SKU {$item['SKU']} needs to be created in your cart before you can refresh the price and stock. It will be skipped."); }
                continue;
            }
            $tempPrice = clean($item['Price'], 'currency');
            $price     = !empty($tempPrice) ? $tempPrice : '';
            $priceReg  = !empty($item['RegularPrice'])? clean($item['RegularPrice'],'currency') : $price;
            $priceSale = !empty($item['SalePrice'])   ? clean($item['SalePrice'],   'currency') : '';
            $stock     = !empty($item['QtyStock'])    ? $item['QtyStock']                       : '';
            $tempWeight= clean($item['Weight'],      'float');
            $itemWeight= !empty($tempWeight)? $tempWeight : 0;
            $data      = ['price'=>$price, 'priceReg'=>$priceReg, 'priceSale'=>$priceSale, 'stock'=>$stock, 'weight'=>$itemWeight];
            $product   = new \WC_Product( $productID );
//          $product   = \wc_get_product( $productID ); // old way
            if (empty($product)) { return msgAdd("Error - the variation is missing!"); }
            if (!$this->quickNoDiff($product, $data)) { 
                $this->productQuickUpdate($product, $data);
                $this->productPriceLevels($product, $productID); // update the price levels
            } else { msgDebug("\nSkipping product Update, no changes."); }
            $priceByItem = !empty($item['PriceByItem']) ? $item['PriceByItem'] : '';
            if (!$this->byItemNoDiff($product, $priceByItem)) {
                $this->priceVariations($product, $priceByItem);
            } else { msgDebug("\nSkipping variation update, no changes"); }
            // process byItem variations
            if (!empty($data['PriceByItem'])) { }
        }
    }

    private function quickNoDiff($product, $data)
    {
        unset($data['priceReg']);
        msgDebug("\nEntering quickNoDiff with data = ".print_r($data, true));
        $current = [
            'price'    => $product->get_price(),
            'priceSale'=> $product->get_sale_price(),
            'stock'    => $product->get_stock_quantity(),
            'weight'   => $product->get_weight()];
        msgDebug("\nPulled current data from db: ".print_r($current, true));
        $noDiffData = empty(array_diff_assoc($data, $current)) ? true : false;
        msgDebug("\narray diff = ".print_r(array_diff_assoc($data, $current), true));
        return $noDiffData;
    }
    
    private function byItemNoDiff($product, $byItem)
    {
        msgDebug("\nEntering byItemNoDiff with byItem = ".print_r($byItem, true));
        if (empty($byItem)) { return true; }
        $tempdbItem= \get_post_meta( $product->get_id(), 'bizSellQtys');
        $dbByItem  = json_encode(sizeof($tempdbItem)<2 ? array_shift($tempdbItem) : $tempdbItem);
        $tempByItem= $this->reformatSellUnits($byItem);
        $dataByItem= json_encode($tempByItem);
        msgDebug("\ndbByItem   = ".print_r($dbByItem, true));
        msgDebug("\ndataByItem = ".print_r($dataByItem, true));
        $noDiffItem = $dbByItem == $dataByItem ? true : false;
        if (!$noDiffItem) { 
            msgDebug("\nUpdating post meta with ".print_r($dataByItem, true));
            \update_post_meta( $product->get_id(), 'bizSellQtys', $tempByItem);
        }
        return $noDiffItem;
    }
    
    private function productQuickUpdate($product, $data=[])
    {
        msgDebug("\nEntering productQuickUpdate with data = ".print_r($data, true));
        $product->set_price         ($data['price']);
        $product->set_regular_price (!empty($data['priceReg']) ? $data['priceReg'] : $data['price']);
        $product->set_sale_price    (!empty($data['priceSale'])? $data['priceSale']: '');
        if (!empty($data['stock']) ){
            $product->set_stock_quantity( $data['stock'] );
            $product->set_manage_stock(true);
            $product->set_stock_status('');
        } else {
            $product->set_manage_stock(false);
        }
        if (!empty($data['weight'])) { $product->set_weight($data['weight']); }
        msgDebug("\nSaving product.");
        $product->save(); // Save to database and sync
        msgDebug("\nread back price = ".$product->get_price());
    }

    /**
     * This method syncs the products flagged in Bizuno to be listed and the actual listed products.
     * If syncDelete flag is set, the products in the cart will be deleted if they are not on the Bizuno list
     * @return messageStack entries
     */
    public function productSync($data)
    {
        msgDebug("\nEntered productSync with data = ".print_r($data, true));
        $bizSKUs = json_decode($data['syncSkus'], true); // need this if size > 1000 to avoid Apache truncation
        $wooProducts = $this->get_meta_values( $meta_key='_sku', 'product');
        msgDebug("\nRead WooCommerce Products of size: ".sizeof($wooProducts)."and values = ".print_r($wooProducts, true));
        $skus = array_diff($wooProducts, $bizSKUs);
        msgDebug("\ndiff results of size: ".sizeof($skus)." and values = ".print_r($skus, true));
        if (!empty($data['syncDelete'])) {
            foreach ($skus as $sku) {
                $post_id  = \wc_get_product_id_by_sku( $sku );
                $product  = \wc_get_product( $post_id );
                if ( !$product ) { continue; }
                $featured = $product->get_image_id();
                $galleries= $product->get_gallery_image_ids();
                msgDebug("\nDeleting sku = $sku");
                if ( !empty( $featured ) ) { \wp_delete_post( $featured, true ); }
                if ( !empty( $galleries ) ) {
                    foreach( $galleries as $image ) { \wp_delete_post( $image, true ); }
                }
                \wp_delete_post($post_id, true);
            }
        }
        if (sizeof($skus) > 0) { return msgAdd($this->lang['msg_sku_missing'].'Bizuno:'.'<br />'.implode(', ', $skus), 'info'); }
        msgAdd($this->lang['msg_sku_sync_success'], 'success');
        return true;
    }

    /**
     *
     * @param unknown $value
     * @param unknown $unit_original m,cm,mm,in,ft
     * @param unknown $unit_return m,cm,mm,in,yd
     */
    private function convertLength($value, $unit_original, $unit_return) {
        if($unit_original == $unit_return) { return $value; }
        switch ($unit_original) {
            case 'm':
                switch($unit_return) {
                    case 'cm': return $value/100;
                    case 'mm': return $value/1000;
                    case 'in': return $value*39.370;
                    case 'yd': return $value/1.0936;
                }
            case 'cm':
                switch($unit_return) {
                    case  'm': return $value*100;
                    case 'mm': return $value/10;
                    case 'in': return $value*0.39370;
                    case 'yd': return $value/109.36;
                }
            case 'mm':
                switch($unit_return) {
                    case  'm': return $value*1000;
                    case 'cm': return $value*10;
                    case 'in': return $value*0.039370;
                    case 'yd': return $value*0.039370*3*12;
                }
            case 'in':
                switch($unit_return) {
                    case  'm': return $value/39.370;
                    case 'cm': return $value/0.39370;
                    case 'mm': return $value/0.039370;
                    case 'yd': return $value*3*12;
                }
            case 'ft':
                switch($unit_return) {
                    case  'm': return $value/3.2808;
                    case 'cm': return $value/0.032808;
                    case 'mm': return $value/0.0032808;
                    case 'yd': return $value*3;
                    case 'in': return $value/12;
                }
            default:
                msgAdd("length conversion Error","warning");
                return $value;
        }
    }

    /**
     *
     * @param unknown $value
     * @param unknown $unit_original kg,lb
     * @param unknown $unit_return kg,g,lb,oz
     * @return void|unknown|string|number
     */
    private function convertWeight($value, $unit_original, $unit_return) {
        if ($unit_original == $unit_return) { return $value; }
        switch ($unit_original) {
            case 'kg':
                switch($unit_return) {
                    case 'g': return $value/1000;
                    case 'lb': return $value*2.2046;
                    case 'oz': return $value*35.274;
                    default: return $value; // covers kgs
                }
            case 'lb':
                switch($unit_return) {
                    case 'kg': return $value/2.2046;
                    case 'g': return $value/0.0022046;
                    case 'oz': return $value*16;
                    default: return $value; // covers lbs unit
                }
            default:
                msgAdd("Weight conversion Error, received unit $unit_original with return value: $unit_return", 'Warning');
                return $value;
        }
    }
}
