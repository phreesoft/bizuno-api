<?php
/*
 * Bizuno API WordPress Plugin - model functions
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
 * @version    7.x Last Update: 2025-11-16
 * @filesource /lib/model.php
 */

namespace bizuno;

/**
 * Bizuno operates in local time. Returns WordPress safe date in PHP date() format if no timestamp is present, else PHP date() function
 * @param string $format - [default: 'Y-m-d'] From the PHP function date()
 * @param integer $timestamp - Unix timestamp, defaults to now
 * @return string
 */
function biz_date($format='Y-m-d', $timestamp=null) {
    return !is_null($timestamp) ? date($format, $timestamp) : date($format); // @TODO - This needs to be adjusted to the users locale
}

function bizIsActivated() { return true; } // always true since controlled by PhreeSoft

/**
 * Validates the user is logged in and returns the creds if true
 */
function getUserCookie() {
    $scramble = clean('bizunoSession', 'text', 'cookie');
    msgDebug("\nChecking cookie to validate creds. read scrambled value = $scramble");
    if (empty($scramble)) { return false; }
    $creds = json_decode(base64_decode($scramble), true);
    msgDebug("\nDecoded creds = ".print_r($creds ,true));
    return !empty($creds) ? $creds : false;
}

function setUserCookie($user)
{
    msgDebug("\nEntering setUserCookie with user = ".print_r($user, true));
    // get the mapped local contact ID from the db
    if     (dbTableExists('address_book')) { $user['userID'] = 0; } // for migration purposes to avoid errors on log in before migration
    elseif (empty($user['userID']) && $GLOBALS['bizunoInstalled']) { // try to get it from db
        $user['userID'] = dbGetValue(BIZUNO_DB_PREFIX.'contacts', 'id', "ctype_u='1' AND email='{$user['userEmail']}'");
        if (empty($user['userID'])) { // record not found in contacts table, create a new one
            $user['userID'] = dbWrite(BIZUNO_DB_PREFIX.'contacts', ['ctype_u'=>'1', 'email'=>$user['userEmail'], 'primary_name'=>$user['userName'], 'short_name'=>$user['userName']]);
            dbMetaSet(0, 'user_profile', ['email'=>$user['userEmail'], 'role_id'=>$user['userRole']], 'contacts', $user['userID']);
        }
    }
    setUserCache('profile', 'userID',  $user['userID']); // Local user ID
    setUserCache('profile', 'email',   $user['userEmail']);
    setUserCache('profile', 'psID',    $user['psID']); // PhreeSoft user ID
    setUserCache('profile', 'userRole',$user['userRole']);
    $args   = [$user['userID'], $user['psID'], $user['userEmail'], $user['userRole'], $_SERVER['REMOTE_ADDR']];
    msgDebug("\nSetting user session cookie bizunoSession with args = ".print_r($args, true));
    $cookie = base64_encode(json_encode($args));
    bizSetCookie('bizunoUser',    $user['userEmail'], time()+(60*60*24*7)); // 7 days
    bizSetCookie('bizunoSession', $cookie, time()+(60*60*10)); // 10 hours
}

function portalModuleList() {
    $modList = [];
    portalModuleListScan($modList, 'BIZBOOKS_ROOT/controllers/'); // Core
    portalModuleListScan($modList, 'BIZUNO_DATA/myExt/controllers/'); // Custom
    msgDebug("\nReturning from portalModuleList with list: ".print_r($modList, true));
    return $modList;
}

function portalModuleListScan(&$modList, $path) {
    $absPath= bizAutoLoadMap($path);
    msgDebug("\nIn portalModuleListScan with path = $path and mapped path = $absPath");
    if (!is_dir($absPath)) { return; }
    $custom = scandir($absPath);
    msgDebug("\nScanned folders = ".print_r($custom, true));
    foreach ($custom as $name) {
        if ($name=='.' || $name=='..' || !is_dir($absPath.$name)) { continue; }
        if (file_exists($absPath."$name/admin.php")) { $modList[$name] = $path."$name/"; }
    }
}

function portalGetBizIDVal($bizID, $idx=false) {
    return defined('BIZUNO_TITLE') ? BIZUNO_TITLE : 'My Business';
}
