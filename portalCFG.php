<?php
/**
 * WordPress Plugin - bizuno-api - Portal Configuration
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
 * @copyright  2008-2026, PhreeSoft, Inc.
 * @license    https://www.gnu.org/licenses/agpl-3.0.txt
 * @version    7.x Last Update: 2026-01-19
 * @filesource /portalCFG.php
 */

namespace bizuno;

if ( ! defined( 'ABSPATH' ) ) exit;

global $wpdb, $msgStack, $cleaner, $io, $db;

// Business Specific
if ( !defined( 'BIZUNO_BIZID' ) )       { define( 'BIZUNO_BIZID',       '1' ); } // Bizuno Business ID [for multi-business]
if ( !defined( 'BIZUNO_DATA' ) )        { define( 'BIZUNO_DATA',        wp_get_upload_dir()['basedir']."/$this->bizSlug/" ); } // Path to user files, cache and backup
if ( !defined( 'BIZUNO_KEY' ) )         { define( 'BIZUNO_KEY',         '0123456789abcdef' ); } // Unique key used for encryption
if ( !defined( 'BIZUNO_DB_PREFIX' ) )   { define( 'BIZUNO_DB_PREFIX',   $wpdb->prefix . 'bizuno_' ); } // Database table prefix
if ( !defined( 'BIZUNO_DB_CREDS' ) )    { define( 'BIZUNO_DB_CREDS',    ['type'=>'mysql', 'host'=>DB_HOST, 'name'=>DB_NAME, 'user'=>DB_USER, 'pass'=>DB_PASSWORD, 'prefix'=>BIZUNO_DB_PREFIX ] ); }
// Platform Specific - File System Paths
if ( !defined( 'BIZUNO_FS_PORTAL' ) )   { define( 'BIZUNO_FS_PORTAL',   plugin_dir_path( __FILE__ ) ); } // file system path to the portal
if ( !defined( 'BIZUNO_FS_LIBRARY' ) )  { define( 'BIZUNO_FS_LIBRARY',  WP_PLUGIN_DIR . "/$this->bizLib/" ); }
if ( !defined( 'BIZUNO_FS_ASSETS' ) )   { define( 'BIZUNO_FS_ASSETS',   WP_PLUGIN_DIR . "/$this->bizLib/vendor/" ); } // contains third party php apps
// Platform Specific - URL's
if ( !defined( 'BIZUNO_URL_AJAX' ) )    { define( 'BIZUNO_URL_AJAX',    admin_url(). 'admin-ajax.php?action=bizuno_ajax' ); }
if ( !defined( 'BIZUNO_URL_API' ) )     { define( 'BIZUNO_URL_API',     plugin_dir_url( __FILE__ ) . "portalAPI.php?bizRt=" ); }
if ( !defined( 'BIZUNO_URL_FS' ) )      { define( 'BIZUNO_URL_FS',      plugin_dir_url( __FILE__ ) . "portalAPI.php?bizRt=portal/api/fs&src=" ); }
if ( !defined( 'BIZUNO_URL_PORTAL' ) )  { define( 'BIZUNO_URL_PORTAL',  home_url() . "/bizuno?" ); } // full url to Bizuno root folder
if ( !defined( 'BIZUNO_URL_SCRIPTS' ) ) { define( 'BIZUNO_URL_SCRIPTS', plugins_url()."/$this->bizLib/scripts/" );  } // contains third party js and css files
if ( !defined( 'BIZUNO_URL_VIEW' ) )    { define( 'BIZUNO_URL_VIEW',    WP_PLUGIN_URL . "/$this->bizLib" );  } // contains Bizuno images, icons, css and js

// Special case for WordPress
if ( !defined( 'BIZUNO_STRIP_SLASHES' ) ) { define('BIZUNO_STRIP_SLASHES', true); } // WordPress adds slashes to all input data

// Initialize & load Bizuno library
require_once ( BIZUNO_FS_LIBRARY . 'portal/controller.php' );
require_once ( BIZUNO_FS_LIBRARY . 'bizunoCFG.php' );

if (!isset($msgStack)|| !($msgStack instanceof \bizuno\messageStack)){ $msgStack = new \bizuno\messageStack(); }
if (!isset($cleaner) || !($cleaner  instanceof \bizuno\cleaner))     { $cleaner  = new \bizuno\cleaner(); }
if (!isset($io)      || !($io       instanceof \bizuno\io))          { $io       = new \bizuno\io(); }
if (!isset($db)      || !($db       instanceof \bizuno\db))          { $db       = new \bizuno\db(BIZUNO_DB_CREDS); }

msgDebug("\nFinished instantiating Bizuno.");
