<?php
/*
 * PhreeSoft ISP Hosting - Config file
 *
 * @author     Dave Premo, PhreeSoft <support@phreesoft.com>
 * @copyright  2008-2025, PhreeSoft, Inc.
 * @license    PhreeSoft Proprietary
 * @version    6.x Last Update: 2025-11-22
 * @filesource ISP Wordpress/bizunoCFG.php
 */

namespace bizuno;

/************** THIS NEEDS TO BE DYNAMIC TO SEE IF BIZUNO LIBRARY PLUGIN HAS BEEN LOADED *************/
define('BIZUNO_FS_LIBRARY', '/usr/share/bizuno/vendor/phreesoft/bizuno/');
define('BIZUNO_BIZID', 1);
$homeDir = isset($_SERVER['HOME']) ? $_SERVER['HOME'] : $_SERVER['PHP_DOCUMENT_ROOT'];
define ( 'BIZUNO_DATA',  "$homeDir/private/" );
define('BIZUNO_KEY', '0123456S890yQ345'); // 16 alpha-num characters, randomly generated
// Database credentials
define('PORTAL_DB_PREFIX', $wpdb->prefix); // WordPress table prefix
define('BIZUNO_DB_PREFIX', $wpdb->prefix); // Will be different from the portal if the Bizuno DB is stored in a database other than the WordPress db
define('BIZUNO_DB_CREDS', ['type'=>'mysql','host'=>DB_HOST,'name'=>DB_NAME,'user'=>DB_USER,'pass'=>DB_PASSWORD,'prefix'=>PORTAL_DB_PREFIX]);

define('BIZUNO_PORTAL',  $_SERVER['SERVER_NAME']);

// File system
define('BIZUNO_PATH',   '/var/www/'.BIZUNO_PORTAL.'/web/');
define('BIZUNO_FS_ASSETS', '/usr/share/bizuno/vendor/');
// URL's
define('BIZUNO_URL_PORTAL',    'https://'.BIZUNO_PORTAL);
define('BIZUNO_URL_SCRIPTS', "https://ww2.bizuno.com/scripts/"); // pulled from a shared server

require_once ( BIZUNO_FS_LIBRARY . 'bizunoCFG.php' ); // Config for current release
require_once ( BIZUNO_FS_LIBRARY . 'model/functions.php' );
require_once ( BIZUNO_FS_ASSETS . 'autoload.php' ); // Load the libraries

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

require_once ( BIZUNO_FS_LIBRARY.'locale/cleaner.php');
//require_once(BIZUNO_FS_LIBRARY.'locale/currency.php');
require_once ( BIZUNO_FS_LIBRARY.'model/db.php');
//require_once(BIZUNO_FS_LIBRARY.'model/encrypter.php');
require_once(BIZUNO_FS_LIBRARY.'model/io.php');
require_once(BIZUNO_FS_LIBRARY.'model/manager.php');
require_once(BIZUNO_FS_LIBRARY.'model/msg.php');
//require_once(BIZUNO_FS_LIBRARY.'model/mail.php');
//require_once(BIZUNO_FS_LIBRARY.'view/main.php');
//require_once(BIZUNO_FS_LIBRARY.'view/easyUI/html5.php');
