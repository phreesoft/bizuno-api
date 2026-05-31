<?php
/**
 * Bizuno API WordPress Plugin - self-contained Bizuno compatibility shim
 *
 * Provides the small set of Bizuno messaging helpers this plugin uses
 * (messageStack, msgAdd, msgMerge) so bizuno-api runs with NO dependency on the
 * Bizuno library / bizuno-accounting plugin. Every definition is guarded with
 * class_exists()/function_exists() so that when the full Bizuno library IS loaded
 * (e.g. alongside bizuno-accounting) its versions win and there is no
 * redeclaration conflict.
 *
 * @name       Bizuno API
 * @author     PhreeSoft, Inc. <support@phreesoft.com>
 * @copyright  2008-2026, PhreeSoft, Inc.
 * @license    https://www.gnu.org/licenses/agpl-3.0.txt
 * @version    7.x Last Update: 2026-05-30
 * @filesource /lib/biz_compat.php
 */

namespace bizuno;

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'bizuno\\messageStack' ) ) {
    /**
     * Minimal, self-contained message accumulator. Mirrors the Bizuno library's
     * messageStack->error contract: a typed array keyed by error|warning|info|success,
     * each holding ['text'=>..] entries. rest_close() returns this in the REST
     * response 'message' key; setNotices() parses the same shape from API replies.
     */
    class messageStack
    {
        public $error = [];

        public function add( $message, $level = 'error', $title = '' )
        {
            switch ( $level ) {
                default:
                case 'error':   $this->error['error'][]   = [ 'text' => $message ]; break;
                case 'caution':
                case 'warning': $this->error['warning'][] = [ 'text' => $message ]; break;
                case 'info':    $this->error['info'][]    = [ 'text' => $message, 'title' => $title !== '' ? $title : 'Information' ]; break;
                case 'success': $this->error['success'][] = [ 'text' => $message ]; break;
            }
            return true;
        }
    }
}

if ( ! function_exists( 'bizuno\\msgAdd' ) ) {
    /** Add a message to the global stack. Mirrors the Bizuno helper (void return). */
    function msgAdd( $msg, $level = 'error', $title = '' )
    {
        global $msgStack;
        if ( is_object( $msgStack ) ) { $msgStack->add( $msg, $level, $title ); }
    }
}

if ( ! function_exists( 'bizuno\\msgMerge' ) ) {
    /** Merge a returned message structure into the global stack, retaining levels. */
    function msgMerge( $msg = [] )
    {
        global $msgStack;
        if ( is_object( $msgStack ) ) {
            $msgStack->error = array_merge_recursive( $msgStack->error, (array) $msg );
        }
    }
}

// No-op stubs: the verbose msgDebug() trace logging used during troubleshooting was
// removed. These guarded stubs keep the plugin from fataling on any stray call and
// let it stay self-sufficient when the Bizuno library isn't loaded.
if ( ! function_exists( 'bizuno\\msgDebug' ) )      { function msgDebug( $msg = '', $trap = false ) {} }
if ( ! function_exists( 'bizuno\\msgDebugWrite' ) ) { function msgDebugWrite( $f = false, $a = false, $force = false ) {} }
if ( ! function_exists( 'bizuno\\msgPrint' ) )      { function msgPrint( $value = '' ) { return print_r( $value, true ); } }
