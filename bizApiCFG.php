<?php
/*
 * PhreeSoft ISP Hosting - Config file
 *
 * @author     Dave Premo, PhreeSoft <support@phreesoft.com>
 * @copyright  2008-2025, PhreeSoft, Inc.
 * @license    PhreeSoft Proprietary
 * @version    6.x Last Update: 2025-06-21
 * @filesource ISP Wordpress/bizunoCFG.php
 */

namespace bizuno;

/************** THIS NEEDS TO BE DYNAMIC TO SEE IF BIZUNO LIBRARY PLUGIN HAS BEEN LOADED *************/
define('BIZUNO_REPO', '/usr/share/bizuno/vendor/phreesoft/bizuno/');
define('BIZUNO_BIZID', 1);
//$upload_dir = wp_upload_dir();
//define ( 'BIZUNO_DATA',  $upload_dir['basedir'].'/bizuno/' );
define ( 'BIZUNO_DATA',  $_SERVER['PHP_DOCUMENT_ROOT'].'/private/' );
define('BIZUNO_KEY', '0123456S890yQ345'); // 16 alpha-num characters, randomly generated
// Database credentials
define('PORTAL_DB_PREFIX', $wpdb->prefix); // WordPress table prefix
define('BIZUNO_DB_PREFIX', $wpdb->prefix); // Will be different from the portal if the Bizuno DB is stored in a database other than the WordPress db
define('BIZPORTAL', ['type'=>'mysql','host'=>DB_HOST,'name'=>DB_NAME,'user'=>DB_USER,'pass'=>DB_PASSWORD,'prefix'=>PORTAL_DB_PREFIX]);

define('BIZUNO_PORTAL',  $_SERVER['SERVER_NAME']);

// File system
define('BIZUNO_PATH',   '/var/www/'.BIZUNO_PORTAL.'/web/');
define('BIZUNO_ASSETS', '/usr/share/bizuno/vendor/');
// URL's
define('BIZUNO_SRVR',    'https://'.BIZUNO_PORTAL.'/');
define('BIZUNO_SERVERS', [
    '75.112.81.5' =>'fl1', '75.112.81.6' =>'fl2', '75.112.81.7' =>'fl3', '75.112.81.8' =>'fl4',
    '128.92.62.26'=>'tx1', '128.92.62.27'=>'tx2', '128.92.62.28'=>'tx3', '128.92.62.29'=>'tx4']);
$subDom = explode('.', BIZUNO_SERVERS[$_SERVER['SERVER_ADDR']])[0];
define('BIZUNO_SCRIPTS', "https://$subDom.bizuno.com/scripts/"); // pulled from a shared server

require_once ( BIZUNO_REPO . 'bizunoCFG.php' ); // Config for current release
require_once ( BIZUNO_REPO . 'model/functions.php' );
require_once ( BIZUNO_ASSETS . 'autoload.php' ); // Load the libraries

//require_once ( dirname(__FILE__) . '/bizApiCFG.php' );
// Library files for plugin operations
require_once ( dirname(__FILE__) . '/lib/model.php' );
require_once ( dirname(__FILE__) . '/lib/common.php' );
require_once ( dirname(__FILE__) . '/lib/admin.php' );
//require_once ( dirname(__FILE__) . '/lib/account.php' );
require_once ( dirname(__FILE__) . '/lib/order.php' );
//require_once ( dirname(__FILE__) . '/lib/payment.php' );
require_once ( dirname(__FILE__) . '/lib/product.php' );
require_once ( dirname(__FILE__) . '/lib/sales_tax.php' );
require_once ( dirname(__FILE__) . '/lib/shipping.php' );

require_once ( BIZBOOKS_ROOT.'locale/cleaner.php');
//require_once(BIZBOOKS_ROOT.'locale/currency.php');
require_once ( BIZBOOKS_ROOT.'model/db.php');
//require_once(BIZBOOKS_ROOT.'model/encrypter.php');
require_once(BIZBOOKS_ROOT.'model/io.php');
require_once(BIZBOOKS_ROOT.'model/manager.php');
require_once(BIZBOOKS_ROOT.'model/msg.php');
//require_once(BIZBOOKS_ROOT.'model/mail.php');
//require_once(BIZBOOKS_ROOT.'view/main.php');
//require_once(BIZBOOKS_ROOT.'view/easyUI/html5.php');
