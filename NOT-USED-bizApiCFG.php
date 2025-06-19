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
if (file_exists($_SERVER['PHP_DOCUMENT_ROOT'].'/private/portalCFG.php')) { // load business specific creds
    require    ($_SERVER['PHP_DOCUMENT_ROOT'].'/private/portalCFG.php');
}

// If not set up properly set the constants to prevent access

