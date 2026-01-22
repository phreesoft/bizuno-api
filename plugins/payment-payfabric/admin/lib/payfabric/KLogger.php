<?php
/**
 * WordPress-compliant logging class using WP_Filesystem.
 * Original KLogger adapted for WP plugin standards.
 */
class KLogger
{
    const EMERG   = 0;
    const ALERT   = 1;
    const CRIT    = 2;
    const ERR     = 3;
    const WARN    = 4;
    const NOTICE  = 5;
    const INFO    = 6;
    const DEBUG   = 7;
    const OFF     = 8;

    // Deprecated alias
    const FATAL   = 2;

    const STATUS_LOG_OPEN    = 1;
    const STATUS_OPEN_FAILED = 2;
    const STATUS_LOG_CLOSED  = 3;

    const NO_ARGUMENTS = 'KLogger::NO_ARGUMENTS';

    public  $_logStatus       = self::STATUS_LOG_CLOSED;
    private $_messageQueue    = array();
    private $_logFilePath     = null;
    private $_severityThreshold = self::INFO;

    private static $_defaultSeverity   = self::DEBUG;
    private static $_dateFormat        = 'Y-m-d H:i:s';
    private static $_defaultPermissions = 0644;     // safer default for files
    private static $_dirPermissions     = 0755;     // for directories

    private static $instances = array();

    /**
     * Singleton-like instance getter
     */
    public static function instance( $logDirectory = false, $severity = false )
    {
        if ( $severity === false ) {
            $severity = self::$_defaultSeverity;
        }
        if ( $logDirectory === false ) {
            $logDirectory = wp_upload_dir()['basedir'] . '/bizuno-logs'; // safer default
        }

        $logDirectory = rtrim( $logDirectory, '/\\' );

        if ( isset( self::$instances[ $logDirectory ] ) ) {
            return self::$instances[ $logDirectory ];
        }

        self::$instances[ $logDirectory ] = new self( $logDirectory, $severity );
        return self::$instances[ $logDirectory ];
    }

    /**
     * Constructor
     */
    public function __construct( $logDirectory, $severity )
    {
        $this->_severityThreshold = $severity;

        if ( $severity === self::OFF ) {
            $this->_logStatus = self::STATUS_LOG_CLOSED;
            return;
        }

        $logDirectory = rtrim( $logDirectory, '/\\' );
        $this->_logFilePath = $logDirectory . '/PayFabric_' . gmdate( 'Y-m-d' ) . '.log';

        // Ensure directory exists
        if ( ! $this->ensure_directory( dirname( $this->_logFilePath ) ) ) {
            $this->_logStatus = self::STATUS_OPEN_FAILED;
            $this->_messageQueue[] = 'Failed to create or verify log directory.';
            return;
        }

        // Test writability (creates empty file if missing)
        if ( ! $this->is_log_file_writable_or_creatable() ) {
            $this->_logStatus = self::STATUS_OPEN_FAILED;
            $this->_messageQueue[] = 'Log file/path is not writable.';
            return;
        }

        $this->_logStatus = self::STATUS_LOG_OPEN;
        $this->_messageQueue[] = 'Log file ready.';
    }

    /**
     * Ensure directory exists using WP_Filesystem (recursive)
     *
     * @param string $path Directory path
     * @return bool
     */
    private function ensure_directory( $path )
    {
        global $wp_filesystem;

        if ( ! $this->init_filesystem() ) {
            return false;
        }

        $path = trailingslashit( $path );

        if ( $wp_filesystem->is_dir( $path ) ) {
            return true;
        }

        if ( ! $wp_filesystem->mkdir( $path, self::$_dirPermissions ) ) {
            error_log( "KLogger: Failed to create directory: $path" );
            return false;
        }

        return true;
    }

    /**
     * Check if log file exists and is writable (create if missing)
     *
     * @return bool
     */
    private function is_log_file_writable_or_creatable()
    {
        global $wp_filesystem;

        if ( ! $this->init_filesystem() ) {
            return false;
        }

        if ( ! $wp_filesystem->exists( $this->_logFilePath ) ) {
            // Create empty file
            if ( ! $wp_filesystem->put_contents( $this->_logFilePath, '', self::$_defaultPermissions ) ) {
                return false;
            }
        }

        return $wp_filesystem->is_writable( $this->_logFilePath );
    }

    /**
     * Initialize WP_Filesystem once
     *
     * @return bool Success
     */
    private function init_filesystem()
    {
        if ( ! function_exists( 'WP_Filesystem' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        if ( ! WP_Filesystem() ) {
            error_log( 'KLogger: WP_Filesystem could not be initialized.' );
            return false;
        }

        return true;
    }

    /**
     * Main logging method
     */
    public function log( $line, $severity, $args = self::NO_ARGUMENTS )
    {
        if ( $this->_severityThreshold < $severity || $this->_logStatus !== self::STATUS_LOG_OPEN ) {
            return;
        }

        $status = $this->_getTimeLine( $severity );
        $message = "$status $line";

        if ( $args !== self::NO_ARGUMENTS ) {
            $message .= '; ' . var_export( $args, true );
        }

        $this->write( $message . PHP_EOL );
    }

    /**
     * Append line using WP_Filesystem
     */
private function write( $line )
{
    global $wp_filesystem;

    if ( ! $this->init_filesystem() ) {
        error_log( "KLogger fallback: $line" );
        return;
    }

    $file = $this->_logFilePath;

    // Read current contents (returns false/string)
    $current = $wp_filesystem->get_contents( $file );

    if ( false === $current ) {
        // File doesn't exist yet → start fresh
        $contents = $line;
    } else {
        // Append (add newline if needed; adjust based on your log format)
        $contents = $current . $line;  // or rtrim($current, "\n") . "\n" . $line if you want clean lines
    }

    $success = $wp_filesystem->put_contents(
        $file,
        $contents,
        FS_CHMOD_FILE  // Usually 0644; matches typical log file perms
    );

    if ( ! $success ) {
        $this->_messageQueue[] = 'Failed to write to log file.';
        error_log( "KLogger write failed: $line" );
    }
}
    // All your convenience methods remain unchanged
    public function logDebug( $line, $args = self::NO_ARGUMENTS ) { $this->log( $line, self::DEBUG, $args ); }
    public function logInfo( $line, $args = self::NO_ARGUMENTS )  { $this->log( $line, self::INFO, $args );  }
    public function logNotice( $line, $args = self::NO_ARGUMENTS ) { $this->log( $line, self::NOTICE, $args ); }
    public function logWarn( $line, $args = self::NO_ARGUMENTS )  { $this->log( $line, self::WARN, $args );  }
    public function logError( $line, $args = self::NO_ARGUMENTS ) { $this->log( $line, self::ERR, $args );   }
    public function logAlert( $line, $args = self::NO_ARGUMENTS ) { $this->log( $line, self::ALERT, $args );  }
    public function logCrit( $line, $args = self::NO_ARGUMENTS )  { $this->log( $line, self::CRIT, $args );   }
    public function logEmerg( $line, $args = self::NO_ARGUMENTS ) { $this->log( $line, self::EMERG, $args );  }
    public function logFatal( $line, $args = self::NO_ARGUMENTS ) { $this->log( $line, self::FATAL, $args );  } // @deprecated

    public function getMessage()      { return array_pop( $this->_messageQueue ); }
    public function getMessages()     { return $this->_messageQueue; }
    public function clearMessages()   { $this->_messageQueue = array(); }

    public static function setDateFormat( $dateFormat )
    {
        self::$_dateFormat = $dateFormat;
    }

    private function _getTimeLine( $level )
    {
        $time = gmdate( self::$_dateFormat ); // use gmdate for consistency
        $labels = [
            self::EMERG  => 'EMERG',
            self::ALERT  => 'ALERT',
            self::CRIT   => 'CRIT',
            self::FATAL  => 'FATAL',
            self::ERR    => 'ERROR',
            self::WARN   => 'WARN',
            self::NOTICE => 'NOTICE',
            self::INFO   => 'INFO',
            self::DEBUG  => 'DEBUG',
        ];
        $label = $labels[ $level ] ?? 'LOG';
        return "$time - $label -->";
    }
}