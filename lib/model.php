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
 * @version    7.x Last Update: 2025-10-26
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
    portalModuleListScan($modList, 'BIZBOOKS_EXT/controllers/'); // Pro
    portalModuleListScan($modList, 'BIZUNO_PATH/controllers/'); // Web root
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

function portalSkins() {
    if (!defined('BIZTHEMES_EASYUI')) { return [['id'=>'default', 'text'=>ucwords('default')]]; }
    $output = [];
    foreach (BIZTHEMES_EASYUI as $choice) { $output[] = ['id'=>$choice, 'text'=>ucwords(str_replace('-', ' ', $choice))]; }
    return $output;
}

function portalIcons(&$icons=[]) {
    if (!defined('BIZTHEMES_ICONS')) { return [['id'=>'default', 'text'=>lang('default')]]; }
    $output = [];
    foreach (BIZTHEMES_ICONS as $choice) { $output[] = ['id'=>$choice, 'text'=>ucwords(str_replace('-', ' ', $choice))]; }
    return $output;
}

/*
final class portal
{
    public $restHeaders = [];

    function __construct() { }

    public function logout(&$layout=[])
    {
        $email = getUserCache('profile', 'email');
        msgDebug("\nEntering portal/logout with email = $email");
        bizClrCookie('bizunoSession');
        $layout = array_replace_recursive($layout, ['type'=>'page', 'jsHead'=>['redir'=>"window.location='".BIZUNO_SRVR."'"]]);
    }

    public function restRequest($type, $server, $endpoint='', $data=[], $opts=[]) {
        if (!empty($this->useOauth)) {
            msgDebug("\nSending REST request via oAuth");
            $token = $this->restOauthToken();
            $optsEP= array_replace_recursive(['headers'=>['authorization'=>"Bearer $token", 'x-locale'=>'en_US', 'content-type'=>'application/json']], $opts);
        } else {
            msgDebug("\nSending REST request via User/Password");
            $optsEP = array_replace_recursive(['headers'=>$this->restHeaders,'cookies'=>[]], $opts);
        }
        $url = empty($endpoint) ? $server : "$server/$endpoint";
//        msgDebug("\nHeaders: ".print_r($optsEP, true));
        msgDebug("\nSending request of type $type to url $url and data of size : ".sizeof($data));
        $response= json_decode($this->cURL($url, $data, strtolower($type), $optsEP), true);
        msgDebug("\nLast response is: ".print_r($response, true));
        if (empty($response) && !is_array($response)) { msgAdd(sprintf(lang('err_no_communication'), $server), 'trap'); }
        if (isset($response['message']) && is_string($response['message'])) { // unexpected message returned
        // Commented out as errors need to be handled individually.
//          msgAdd("Woo restRequest received back from server: {$response['message']}");
//          unset($response['message']);
        }
        return $response;
    }

    public function restOauthToken($server='', $id='', $secret='')
    {
        msgDebug("\nEntering restTokenValidate with path = $server");
        if (empty($server)) { return msgAdd("Error! no server name passed!"); }
        $token = getModuleCache('bizuno', 'rest');
        if (empty($token[$server]['token']) || $token[$server]['expires_in'] < time()-10) { // get a new token for today
            // get an authorization code
            $code = json_decode($this->cURL("{$server}/oauth/authorize", "response_type=code&client_id=$id", 'get'), true);
            if (!is_array($code)) { return msgAdd('A string was returned for the OAuth2 code! Not good.'); }
            // get an access token
            // WHAT TO DO WITH $code['code?']
            $optsA = ['headers'=>['Content-Type'=>'application/x-www-form-urlencoded']];
            $dataA = "grant_type=client_credentials&client_id=$id&client_secret=$secret";
            $tokenA= json_decode($this->cURL("{$server}/oauth/token", $dataA, 'post', $optsA), true);
            if (!is_array($tokenA)) { return msgAdd("A string was returned! Not good."); }
            if (!empty($tokenA['error'])) { return msgAdd("REST Token Request Error: ".print_r($tokenA['errors'], true)); }
            msgDebug("\nread token = {$tokenA['access_token']} and expires_in = {$tokenA['expires_in']}");
            if (empty($tokenA['access_token'])) { return msgAdd("Error retrieving token from $server, all APIs will be unavailable!"); }
            $token[$server]['token']   = $tokenA['access_token'];
            $token[$server]['expires_in']= time()+$tokenA['expires_in'];
            setModuleCache('bizuno', 'rest', '', $token);
        }
        return $token[$server]['token'];
    }

    function cURL($url, $data=[], $type='get', $opts=[]) {
        $useragent = 'Mozilla/5.0 (Windows NT 5.1; rv:31.0) Gecko/20100101 Firefox/31.0';
        $size = is_array($data) ? 'array('.sizeof($data).')' : strlen($data);
        msgDebug("\nAt class portal, sending request of length $size to url: $url via $type"); //  ."with opts = ".print_r($opts, true)
        $rData = is_array($data) ? http_build_query($data) : $data;
        if ($type == 'get') { $url = $url.'?'.$rData; }
        $headers = [];
        if (!empty($opts['headers'])) { foreach ($opts['headers'] as $key => $value) { $headers[] = "$key: $value"; } }
        if (!empty($opts['cookies'])) { foreach ($opts['cookies'] as $key => $value) { $headers[] = "$key: $value"; } }
        unset($opts['headers'], $opts['cookies']);
        $options = [];
        $ch = curl_init();
        if (!empty($options)) { foreach ($options as $opt => $value) {
            switch ($opt) {
                case 'useragent': curl_setopt($ch, CURLOPT_USERAGENT, $useragent); break;
                default:          curl_setopt($ch, constant($opt), $value); break;
            }
        } }
        curl_setopt($ch, CURLOPT_URL,           $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER,    $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER,true);
        curl_setopt($ch, CURLOPT_TIMEOUT,       30); // in seconds
        curl_setopt($ch, CURLOPT_HEADER,        false);
        curl_setopt($ch, CURLOPT_VERBOSE,       false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER,false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST,false);
        if (strtolower($type) == 'post') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $rData);
        }
// for debugging cURL issues, uncomment below
//$fp = fopen(BIZUNO_DATA."cURL_trace.txt", 'w');
//curl_setopt($ch, CURLOPT_VERBOSE, true);
//curl_setopt($ch, CURLOPT_STDERR, $fp);
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            msgDebug('cURL Error # '.curl_errno($ch).'. '.curl_error($ch));
            msgAdd('cURL Error # '.curl_errno($ch).'. '.curl_error($ch));
            curl_close ($ch);
            return;
        } elseif (empty($response)) { // had an issue connecting with TLSv1.2, returned no error but no response (ALPN, server did not agree to a protocol)
            msgAdd("Oops! I Received an empty response back from the cURL request. There was most likely a problem with the connection that was not reported.", 'caution');
        }
        curl_close ($ch);
        return $response;
    }
    
    function dbDump($filename='bizuno_backup', $dirWrite='', $dbTable='')
    {
        global $io;
        // set execution time limit to a large number to allow extra time
        set_time_limit(20000);
        $dbHost = BIZPORTAL['host'];
        $dbName = BIZPORTAL['name'];
        $dbUser = BIZPORTAL['user'];
        $dbPass = BIZPORTAL['pass'];
        $dbPath = BIZUNO_DATA.$dirWrite;
        $dbFile = $filename.".sql.gz";
        if (!$dbTable && BIZUNO_DB_PREFIX <> '') { // fetch table list (will be entire db if no prefix)
            if (!$stmt= dbGetResult("SHOW TABLES FROM $dbName LIKE '".BIZUNO_DB_PREFIX."%'")) { return; }
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            if ($rows) { foreach ($rows as $row) { $dbTable .= array_shift($row).' '; } }
        }
        if (!$io->validatePath($dirWrite.$dbFile, true)) { return; }
        $cmd    = "mysqldump --opt -h $dbHost -u $dbUser -p$dbPass $dbName $dbTable | gzip > $dbPath$dbFile";
        msgDebug("\n Executing command: $cmd");
        if (!function_exists('exec')) { msgAdd("php exec is disabled, the backup cannot be achieved this way!"); }
        $result = exec($cmd, $retValue);
        if (!file_exists($dbPath.$dbFile)) { return; } // for some reason the dump failed, could be out of disk space
        chmod($dbPath.$dbFile, 0664);
        return true;
    }

   function dbRestore($filename)
   {
       msgDebug("\npath = ".BIZBOOKS_ROOT."...myFolder.../$filename");
       $dbFile = BIZUNO_DATA.$filename;
       $dbHost = BIZPORTAL['host'];
       $dbName = BIZPORTAL['name'];
       $dbUser = BIZPORTAL['user'];
       $dbPass = BIZPORTAL['pass'];
       $ext = strtolower(pathinfo($dbFile, PATHINFO_EXTENSION));
       if (in_array($ext, ['sql'])) { // raw sql in text format
           $cmd = "mysql --host=$dbHost --user=$dbUser --password=$dbPass --default_character_set=utf8 --database=$dbName < $dbFile";
       } elseif (in_array($ext, ['zip'])) { // in zip format
           $cmd = "unzip -p $dbFile | mysql --host=$dbHost --user=$dbUser --password=$dbPass --default_character_set=utf8 --database=$dbName";
       } else { // assume gz format
           $cmd = "gunzip < $dbFile | mysql --host=$dbHost --user=$dbUser --password=$dbPass --default_character_set=utf8 --database=$dbName";
       }
       msgDebug("\n Executing command: $cmd");
       if (!function_exists('exec')) { return msgAdd("php exec is disabled, the restore cannot be achieved this way!"); }
       $result = exec($cmd, $output, $retValue); // start the restore, script may time out but restore will continue until it's finished
       msgDebug("\n returned result: ".print_r($result,  true));
//     msgDebug("\n returned output: ".print_r($output,  true)); // echoes the uncompressed sql, VERY LONG makes large debug files!
       msgDebug("\n returned status value: " .print_r($retValue, true));
       return (!empty($retValue)) ? false : true;
   }
} */
