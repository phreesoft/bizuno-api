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
 * @version    7.x Last Update: 2026-05-30
 * @filesource /portalCFG.php
 */

namespace bizuno;

if ( ! defined( 'ABSPATH' ) ) exit;

// Self-contained messaging — no Bizuno library / bizuno-accounting dependency.
// The connector talks to the ERP purely over the WordPress HTTP API (see lib/api_common.php
// cURL()); it needs none of the Bizuno library's DB/model/portal machinery, only the
// lightweight message stack reproduced in biz_compat.php.
require_once __DIR__ . '/lib/biz_compat.php';

global $msgStack;
if ( ! isset( $msgStack ) || ! ( $msgStack instanceof messageStack ) ) { $msgStack = new messageStack(); }
