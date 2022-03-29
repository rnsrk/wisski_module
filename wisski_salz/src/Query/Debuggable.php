<?php

namespace Drupal\wisski_salz\Query;

/** Debuggable represents anything with a debug method */
trait Debuggable {
    /** checks if debugging mode is enabled */
    static function debug_enabled() {
        if(WISSKI_DEVEL) return true;
        return false;
    }
    /** Log a debug message to the output */
    static function debug(string $message) {
        // for logging in production, write to the system log
        if (self::debug_enabled()) {
            \Drupal::logger('wisski_query')->debug($message);
        }

        // for debugging ad-hoc turn this into true
        if (FALSE) {
            dpm($message);
        }
    }
}