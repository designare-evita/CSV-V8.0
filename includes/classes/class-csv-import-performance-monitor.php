<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Direkten Zugriff verhindern
}
// ===================================================================
// PERFORMANCE MONITORING SYSTEM
// ===================================================================

class CSV_Import_Performance_Monitor {
    
    private static $start_time;
    private static $start_memory;

    public static function start() {
        self::$start_time = microtime(true);
        self::$start_memory = memory_get_usage();
        
        add_action('shutdown', [__CLASS__, 'log_performance']);
    }
    
    public static function log_performance() {
        if (!self::$start_time) {
            return;
        }

        $end_time = microtime(true);
        $end_memory = memory_get_usage();
        
        $execution_time = $end_time - self::$start_time;
        $memory_used = $end_memory - self::$start_memory;

        // Log performance if it exceeds certain thresholds
        if ($execution_time > 5 || $memory_used > 10 * 1024 * 1024) { // 5 seconds or 10MB
             if (function_exists('csv_import_log')) {
                csv_import_log('info', 'Performance Metrics', [
                    'execution_time_seconds' => round($execution_time, 2),
                    'memory_used' => size_format($memory_used),
                    'peak_memory' => size_format(memory_get_peak_usage()),
                    'request_uri' => $_SERVER['REQUEST_URI'] ?? 'N/A'
                ]);
            }
        }
    }
}

// Initialize the monitor
CSV_Import_Performance_Monitor::start();
