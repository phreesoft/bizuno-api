<?php
/*
 * PhreeSoft ISP Hosting - Config file
 *
 * @author     Dave Premo, PhreeSoft <support@phreesoft.com>
 * @copyright  2008-2025, PhreeSoft, Inc.
 * @license    PhreeSoft Proprietary
 * @version    6.x Last Update: 2025-03-18
 * @filesource ISP Wordpress/bizunoCFG.php
 */

namespace bizuno;

global $wpdb;

// BIZUNO_DEV_SITE

// Core Bizuno files
define('BIZUNO_REPO','/usr/share/bizuno/');
require(BIZUNO_REPO.'bizunoCFG.php'); // Config for current release
if (file_exists($_SERVER['PHP_DOCUMENT_ROOT'].'/private/portalCFG.php')) { // load business specific creds
    require    ($_SERVER['PHP_DOCUMENT_ROOT'].'/private/portalCFG.php');
}
define('PORTAL_DB_PREFIX',   $wpdb->prefix); // WordPress table prefix
//
// Set the PDF renderer application
$pdfRenderer = 'TCPDF'; // Options are 'TCPDF' (Default) and 'tFPDF'
if ('tFPDF'==$pdfRenderer) { // http://www.fpdf.org/
    define('BIZUNO_PDF_ENGINE', 'tFPDF');
    define('BIZUNO_3P_PDF', BIZBOOKS_ROOT.'assets/FPDF/');
} else { // Current: https://github.com/tecnickcom/tc-lib-pdf - was: https://tcpdf.org/
    define('BIZUNO_PDF_ENGINE', 'TCPDF');
    define('BIZUNO_3P_PDF', BIZBOOKS_ROOT.'assets/TCPDF/');
}

// If not set up properly set the constants to prevent access
if (!defined('BIZUNO_BIZID')) { define('BIZUNO_BIZID', 0); }
if (!defined('BIZUNO_DATA'))  { define('BIZUNO_DATA',  $_SERVER['PHP_DOCUMENT_ROOT'].'/private/'); }

//require ( __DIR__ . '/assets/plugin-update-checker/plugin-update-checker.php' ); // Source: https://github.com/YahnisElsts/plugin-update-checker
//use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
//$myUpdateChecker = PucFactory::buildUpdateChecker( 'https://www.phreesoft.com/biz-apps/bizuno-isp.json', __FILE__, 'bizuno-isp' );
// Library files for plugin operations
require ( dirname(__FILE__) . '/portal/model.php');
require ( dirname(__FILE__) . '/lib/common.php' );
require ( dirname(__FILE__) . '/lib/admin.php' );
require ( dirname(__FILE__) . '/lib/account.php' );
require ( dirname(__FILE__) . '/lib/order.php' );
require ( dirname(__FILE__) . '/lib/payment.php' );
require ( dirname(__FILE__) . '/lib/product.php' );
require ( dirname(__FILE__) . '/lib/shipping.php' );

require (BIZUNO_REPO  .'assets/vendor/autoload.php'); // Load the libraries
require (BIZBOOKS_ROOT.'model/functions.php');
//bizAutoLoad('portal/view.php');
if (defined('BIZBOOKS_ROOT')) {
    \bizuno\bizAutoLoad(BIZBOOKS_ROOT.'locale/cleaner.php',   'cleaner');
    \bizuno\bizAutoLoad(BIZBOOKS_ROOT.'locale/currency.php',  'currency');
    \bizuno\bizAutoLoad(BIZBOOKS_ROOT.'model/db.php',         'db');
//    \bizuno\bizAutoLoad(BIZBOOKS_ROOT.'model/encrypter.php',  'encryption');
    \bizuno\bizAutoLoad(BIZBOOKS_ROOT.'model/io.php',         'io');
    \bizuno\bizAutoLoad(BIZBOOKS_ROOT.'model/manager.php',    'mgrJournal');
    \bizuno\bizAutoLoad(BIZBOOKS_ROOT.'model/msg.php',        'messageStack');
    \bizuno\bizAutoLoad(BIZBOOKS_ROOT.'model/mail.php',       'bizunoMailer');
    \bizuno\bizAutoLoad(BIZBOOKS_ROOT.'view/main.php',        'view');
    \bizuno\bizAutoLoad(BIZBOOKS_ROOT.'view/easyUI/html5.php','html5');
}

if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
    require ( dirname( __FILE__ ) . '/plugins/payment-payfabric/payment-payfabric.php' );
    require ( dirname( __FILE__ ) . '/plugins/payment-purchase-order.php' );
    require ( dirname( __FILE__ ) . '/plugins/shipping-bizuno.php' );
}
