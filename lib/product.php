<?php
/**
 * Bizuno API WordPress Plugin - product class
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
 * @version    7.x Last Update: 2025-08-09
 * @filesource /lib/product.php
 */

namespace bizuno;

class product extends common
{
    public $productID  = 0;
    private $fileBirdActive;

    function __construct($options=[])
    {
        parent::__construct($options);
        $this->fileBirdActive = is_plugin_active ( 'filebird/filebird.php' ) || is_plugin_active ( 'filebird-pro/filebird.php' ) ? true : false;
    }

    /********************** Cron Events ************************/
    public function cron_image()
    {
        require_once( ABSPATH . 'wp-admin/includes/image.php' );
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

    /************** API Product Processing ******************/
    /**
     * Starts the import of product to WooCommerce
     * @param type $post
     * @return type
     */
    public function productImport($post)
    {
        global $wcProduct;
        set_time_limit(60); // set timeout to 1 minute, imagemgk is verty slow when doing a full upload
        msgDebug("\nEntering productImport with sku = {$post['SKU']} and sizeof product = ".sizeof($post));
        if (empty($post['SKU'])) { return msgAdd("Bad SKU passed. Needs to be the inventory field id tag name (SKU)."); }
        $wcProduct = $this->getProduct($post);
        
        $slug = !empty($post['WooCommerceSlug']) ? $post['WooCommerceSlug'] : $post['Description'];
        if (isset($post['WeightUOM'])) { // convert weight (need to convert kg,lb,oz,g)
            $weightUOM= !empty($post['WeightUOM']) ? strtolower($post['WeightUOM']) : 'lb';
            $wooWt    = \get_option('woocommerce_weight_unit');
            $wp_weight= !empty($wooWt) ? strtolower($wooWt) : 'lb';
            $weight   = isset($post['Weight']) ? $this->convertWeight($post['Weight'], $weightUOM, $wp_weight) : '';
        }
        if (isset($post['DimensionUOM'])) { //convert dim (need to convert m,cm,mm,in,yd)
            $dim = strtolower($post['DimensionUOM']);
            $wordpress_dim = strtolower(\get_option('woocommerce_dimension_unit'));
            $length = isset($post['ProductLength'])? $this->convertLength($post['ProductLength'],$dim, $wordpress_dim) : '';
            $width  = isset($post['ProductWidth']) ? $this->convertLength($post['ProductWidth'], $dim, $wordpress_dim) : '';
            $height = isset($post['ProductHeight'])? $this->convertLength($post['ProductHeight'],$dim, $wordpress_dim) : '';
        }
        // Let's go
        $product_id = $wcProduct->get_id();
        msgDebug("\nSetting fields and meta data");
        $wcProduct->set_date_modified(\wp_date('Y-m-d H:i:s'));
        $wcProduct->set_description(!empty($post['DescriptionLong']) ? $post['DescriptionLong'] : $post['DescriptionSales']);
        $wcProduct->set_length($length);
        $wcProduct->set_width($width);
        $wcProduct->set_height($height);
        $wcProduct->set_weight($weight);
        $wcProduct->set_manage_stock(!empty($this->options['inv_stock_mgt']) ? true : false);
        $wcProduct->set_backorders($this->options['inv_backorders']);
        $wcProduct->set_menu_order(!empty($post['MenuOrder']) ? (int)$post['MenuOrder'] : 99);
        $wcProduct->set_name($post['Description']);
        msgDebug("\nSetting price to ".$post['Price']);
        $wcProduct->set_price(floatval($post['Price']));
        $wcProduct->set_regular_price(floatval($post['Price']));
        $wcProduct->set_sale_price('');
//      $wcProduct->set_regular_price(!empty($post['RegularPrice']) ? $post['RegularPrice'] : '');
//      $wcProduct->set_sale_price(!empty($post['SalePrice']) ? $post['SalePrice'] : '');
        $wcProduct->set_short_description(!empty($post['DescriptionSales']) ? $post['DescriptionSales'] : $post['Description']);
        $wcProduct->set_slug($this->getPermaLink($slug));
//      $wcProduct->set_status('published');
        $wcProduct->set_stock_quantity($post['QtyStock'] > 0 ? $post['QtyStock'] : 0);
        $wcProduct->set_stock_status($post['QtyStock'] > 0 ? 'instock' : 'outofstock');
        $wcProduct->set_tax_status('taxable');
        msgDebug("\nChecking on sendMode and starting appropriate sequence");
        switch ($post['sendMode']) {
            default: // default needs to be here so the individula upload sends everyhthing.
            case 1: $replaceImage = true;// Full Upload (Slowest - replace/regenerate all images)
            case 2: // Full Product Details (Skip images if present)
                $this->productImage($post, $product_id, !empty($replaceImage) ? true : false); // Set images
            case 3: // Product Core Info (No Categories/Images)
                $this->productAttributes($post, $product_id); // Update attributes
                $this->productRelated($post); // Set related products
                if (!empty($post['invOptions'])) { $this->productVariations($post['invOptions'], $product_id); } // check for master stock type
                $this->productMetadata($post);
                $this->productTags($post, $product_id);
                $this->productCategory($post, $product_id); //update category
                break;
        }
        msgDebug("\nSaving the product.");
        $wcProduct->save();
        msgDebug("\nChecking for Sell Qtys"); // Checking for price levels by Item
        if (!empty($post['PriceVariations'])) { $this->priceVariations($wcProduct, $post['PriceVariations']); }
        return $product_id;
    }

    private function getProduct($post)
    {
        $this->productID = \wc_get_product_id_by_sku($post['SKU']);
        msgDebug("\nEntering getProduct, fetched product ID = $this->productID");
        if (empty($this->productID)) { // The new way returns zero for products uploaded in early versions of the API, try to old way, just in case
            $this->productID = dbGetValue(PORTAL_DB_PREFIX.'postmeta', 'post_id', "`meta_key` = '_sku' AND `meta_value`='".addslashes($post['SKU'])."'", true);
            msgDebug("\nTried the old way, product ID is now = $this->productID");
        }
        $productType = !empty($post['Type']) ? strtolower($post['Type']) : 'si'; // allows change of product type on the fly
        if ( empty($this->productID) ) { // new product
            msgDebug("\nNew product, starting class ... ");
            if ('ms'===$productType || !empty($post['PriceVariations'])) {
                msgDebug(" WC_Product_Variable");
                $product =  new \WC_Product_Variable();
            } else {
                msgDebug(" WC_Product_Simple");
                $product =  new \WC_Product_Simple();
            }
            $product->set_sku($post['SKU']);
//          $product->set_date_created(!empty($post['DateCreated']) ? $post['DateCreated'] : \wp_date('Y-m-d H:i:s'));
            $product->save(); // get an ID
            msgDebug("\nMade new product, product ID is now = ".$product->get_id());
        } else { // update existing product
            $product   = \wc_get_product( $this->productID );
            $changeType= false;
            if ($product->is_type( 'simple' )  && ('ms'===$productType || !empty($post['PriceVariations']))) { $changeType = 'variable'; }
            if ($product->is_type( 'variable' )&& ('ms'<> $productType &&  empty($post['PriceVariations']))) { $changeType = 'simple'; }
            if (!empty($changeType)) {
                msgDebug("\nSetting type to: $changeType");
                $classname= \WC_Product_Factory::get_product_classname( $this->productID, $changeType );
                $product= new $classname( $this->productID );
                $product->save();
            }
        }
        return $product;
    }

    private function productRelated($post)
    {
//      global $wcProduct;
        msgDebug("\nEntering productRelated");
        // This needs to be updated to the new method, probably part of WC_Product_Simple
        //
        //
        // fetch related id
        if (!empty($post['invAccessory']) && is_array($post['invAccessory'])) {
            $post['related'] = [];
            foreach ($post['invAccessory'] as $related) {
                $product_id = dbGetValue(PORTAL_DB_PREFIX.'postmeta', 'post_id', "`meta_key` LIKE '_sku' AND `meta_value`='{$related}'", true);
                if ($product_id !== false) { $post['related'][] = $product_id; }
            }
            msgDebug("related items found:".print_r($post['related'], true));
        }
        if (isset($post['related'])) {
//            dbGetResult("DELETE FROM `". PORTAL_DB_PREFIX . "postmeta` WHERE post_id = '". (int)$product_id . "' AND meta_key = '_crosssell_ids';");
//            dbGetResult("INSERT INTO " . PORTAL_DB_PREFIX . "postmeta SET post_id = '"   . (int)$product_id . "', meta_key = '_crosssell_ids' , meta_value = '" . $post['related'] . "';");
        }
    }

    private function productMetadata($post)
    {
        global $wcProduct;
        if (!empty($post['SearchCode']))      { $wcProduct->update_meta_data('biz_search_code',      $post['SearchCode']); }
        msgDebug("\nEntering productMetadata and checking for YOST SEO plugin active");
        if ( !is_plugin_active( 'wordpress-seo/wp-seo.php' ) ) { return; }
        if (!empty($post['MetaDescription'])) { $wcProduct->update_meta_data('_yoast_wpseo_metadesc',$post['MetaDescription']); }
    }

    /**
     * Set the tags
     * @param type $post
     * @param type $product_id
     * @return boolean
     */
    private function productTags($post, $product_id)
    {
        msgDebug("\nEntering productTags product_id = $product_id with WooCommerceTags = ".print_r($post['WooCommerceTags'], true));
        if (empty($post['WooCommerceTags'])) { return; }
        $IDs = [];
        $current = \get_the_terms($product_id, 'product_tag');
        msgDebug("\nRetrieved terms = ".print_r($current, true));
        foreach ( (array)$current as $term) {
            if (!empty($term->name)) { $IDs[] = $term->name; }
        }
        $sep = strpos($post['WooCommerceTags'], '|') !== false ? '|' : ';'; // new separator is the |
        $tags= explode($sep, $post['WooCommerceTags']);
        foreach ($tags as $tag) {
            if (!empty(trim($tag))) { $IDs[] = trim($tag); } // sanitize_title makes the slug (lower no spaces) and also is used as the label which we don't want
        }
        msgDebug("\nSetting post tags to IDs = ".print_r($IDs, true));
        $results = \wp_set_object_terms($product_id, $IDs, 'product_tag');
        msgDebug("\nResults from setting tags = ".print_r($results, true));
    }

    /**
     *
     * @param type $post
     * @param type $product_id
     * @return boolean
     */
    private function productCategory($post, $product_id)
    {
        msgDebug("\nEntering productCategory");
        if (empty($post['WooCommerceCategory'])) {
            return msgAdd("Error - the category was not passed for product: {$post['SKU']}, it must be set manually in WooCommerce.", 'caution');
        }
        $this->endCatOnly = false;
        msgDebug("\nWorking with raw category: {$post['WooCommerceCategory']}");
        // Multiple category breadcrumbs may be passed, use semi-colon as the separator
        $categories = explode(";", $post['WooCommerceCategory']);
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
     * @param type $post
     * @param type $product_id
     * @return type
     */
    private function productAttributes($post, $product_id)
    {
//      global $wcProduct; // new way
        msgDebug("\nEntering productAttributes");
        if (empty($post['Attributes'])) { return; }
        $result      = dbGetMulti(PORTAL_DB_PREFIX.'term_taxonomy', "taxonomy LIKE 'pa_%'");
        $pa_attr_ids = [];
        foreach ($result as $row) { $pa_attr_ids[] = $row['term_taxonomy_id']; }
        if (sizeof($pa_attr_ids)) { // clear out the current attributes
            dbGetResult("DELETE FROM ".PORTAL_DB_PREFIX."term_relationships WHERE object_id=$product_id AND term_taxonomy_id IN (".implode(',',$pa_attr_ids).")");
        }
        $productAttr = [];
        foreach ($post['Attributes'] as $idx => $row) {
            if (empty($row['title']) || empty($row['index'])) { continue; }
            $attrSlug= $this->getPermaLink($row['index']);
//          $attrSlug= $this->getPermaLink($post['AttributeCategory'].'_'.strtolower($row['index'])); // creates a lot of attributes and causes filtering issues
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
            if ( ! empty($value['stock_qty']) ) {
                $variation->set_stock_quantity( $value['stock_qty'] );
                $variation->set_stock_status('');
            }
            $variation->set_manage_stock(!empty($this->options['inv_stock_mgt']) ? true : false);
            $variation->set_backorders($this->options['inv_backorders']);
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
     * @param type $post
     * @param type $product_id
     * @return type
     */
    private function productImage($post, $product_id, $replace=false)
    {
        global $wcProduct;
        msgDebug("\nEntering productImage with product ID = $product_id");
        if (empty($post['ProductImageFilename'])) { return; }
        $media = [];
        require_once( ABSPATH.'wp-admin/includes/image.php' );
        $this->setImageProps($media, $post['ProductImageDirectory'], $post['ProductImageFilename'], $post['ProductImageData']);
        if (!empty($post['Images']) && is_array($post['Images'])) {
            msgDebug("\nReady to process extra Images with size of Images tag = ".sizeof($post['Images']));
            foreach ($post['Images'] as $image) {
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
     * @param array $PriceVar
     * @return null
     */
    private function priceVariations($product, $PriceVar='')
    {
        msgDebug("\nEntering priceVariations with sellQtys = ".print_r($PriceVar, true));
        $variations = $this->reformatSellUnits($PriceVar);
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
            $variation->set_stock_quantity( $value['stock'] );
            $variation->set_manage_stock(!empty($this->options['inv_stock_mgt']) ? true : false);
            $variation->set_stock_status('');
            $variation->set_backorders($this->options['inv_backorders']); // Options: 'yes', 'no' or 'notify'
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

    //     [Price ByItem] => {"total":3,"rows":[{"label":"Each (1 pieces)","qty":"1","weight":79.8,"price":288.47,"stock":7},{"label":"Pallet Layer (10 pieces)","qty":"10","weight":798,"price":2375.66,"stock":0},{"label":"Pallet (20 pieces)","qty":"20","weight":1596,"price":4072.56,"stock":0}]}

    private function reformatSellUnits($sellQtys)
    {
        msgDebug("\nEntering reformatSellUnits"); // with sellQtys = ".print_r($sellQtys, true));
//        $qtys = json_decode($sellQtys, true);
        $output = ['attributes'=>[['name'=>'price-discounts', 'options'=>[]]], 'variations'=>[]];
        foreach ($sellQtys as $row) {
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
        $missingSKUs = [];
        foreach ($items as $item) {
            $productID = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key='_sku' AND meta_value='%s' LIMIT 1", $item['SKU'] ) );
//          $productID = \wc_get_product_id_by_sku($item['SKU']); // doesn't always return a hit, flagging not found error below???
            if (empty($productID)) {
                $missingSKUs[] = $item['SKU'];
                continue;
            }
            $tempPrice = clean($item['Price'], 'currency');
            $price     = !empty($tempPrice) ? $tempPrice : '';
            $priceReg  = !empty($item['RegularPrice'])? clean($item['RegularPrice'],'currency') : $price;
            $priceSale = !empty($item['SalePrice'])   ? clean($item['SalePrice'],   'currency') : '';
            $stock     = !empty($item['QtyStock'])    ? $item['QtyStock']                       : '';
            $tempWeight= clean($item['Weight'],'float');
            $itemWeight= !empty($tempWeight)? $tempWeight : 0;
            $data      = ['price'=>$price, 'priceReg'=>$priceReg, 'priceSale'=>$priceSale, 'stock'=>$stock, 'weight'=>$itemWeight];
            $product   = new \WC_Product( $productID );
//          $product   = \wc_get_product( $productID ); // old way
            if (empty($product)) { return msgAdd("Error - the variation is missing!"); }
            if (!$this->quickNoDiff($product, $data)) { 
                $this->productQuickUpdate($product, $data);
            } else { msgDebug("\nSkipping product Update, no changes."); }
            $PriceVar = !empty($item['PriceVariations']) ? $item['PriceVariations'] : [];
            if (!$this->byItemNoDiff($product, $PriceVar)) {
                msgDebug("\nPricing variation update, make the changes.");
                $this->priceVariations($product, $PriceVar);
            }
        }
        if (!empty($missingSKUs) && $verbose) {
            msgAdd("The following SKUs are not in the cart yet you say they should be there: ".implode(', ', $missingSKUs));
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
            $product->set_stock_status('');
        }
        $product->set_manage_stock(!empty($this->options['inv_stock_mgt']) ? true : false);
        $product->set_backorders($this->options['inv_backorders']);
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
