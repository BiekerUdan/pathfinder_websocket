<?php
/**
 * Suppress PHP 8.4 deprecation warnings from vendor code (Ratchet library)
 * while still showing our own errors and warnings
 */

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    // Only filter deprecation warnings from vendor code
    if ($errno === E_DEPRECATED || $errno === E_USER_DEPRECATED) {
        // Check if error is from vendor code
        if (strpos($errfile, '/vendor/') !== false) {
            // Silently ignore vendor deprecations
            return true;
        }
    }

    // For all other errors, use default error handler
    return false;
}, E_ALL);
