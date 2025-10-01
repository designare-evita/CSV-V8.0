<?php
/**
 * Core-Funktionen für das CSV Import Pro Plugin
 * Diese Datei enthält alle grundlegenden Funktionen, die von anderen Plugin-Teilen
 * benötigt werden. Sie muss als erstes geladen werden.
 * Version: 5.3-final (Parse-Error behoben)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Direkten Zugriff verhindern
}

// Sicherstellen dass WordPress vollständig geladen ist
if (!function_exists('add_action')) {
    die('WordPress nicht geladen. Direkter Zugriff nicht erlaubt.');
}

// Kritische Funktionen prüfen
$required_functions = ['get_option', 'update_option', 'wp_send_json_success', 'wp_send_json_error'];
foreach ($required_functions as $func) {
    if (!function_exists($func)) {
        error_log("CSV Import Pro FATAL: Required WordPress function missing: $func");
        die('WordPress Kernfunktionen nicht verfügbar.');
    }
}

// ===================================================================
// VORBEUGENDE MAẞNAHMEN & SCHUTZ VOR HÄNGENDEN IMPORTS
// ===================================================================

/**
 * Prüft und behebt hängende Import-Prozesse automatisch
 */
function csv_import_check_stuck_imports() {
    $progress = get_option('csv_import_progress', []);
    
    if (!empty($progress['running']) && !empty($progress['start_time'])) {
        $runtime = time() - $progress['start_time'];
        
        if ($runtime > 600) {
            csv_import_force_reset_import_status();
            csv_import_log('warning', 'Hängender Import-Prozess wurde automatisch zurückgesetzt', [
                'runtime' => $runtime,
                'progress' => $progress
            ]);
            
            set_transient('csv_import_stuck_reset_notice', true, 300);
        }
    }
}

/**
 * Erzwingt das Zurücksetzen des Import-Status (Notfall-Reset)
 */
function csv_import_force_reset_import_status() {
    $import_options = [
        'csv_import_progress',
        'csv_import_session_id', 
        'csv_import_start_time',
        'csv_import_current_header',
        'csv_import_running_lock',
        'csv_import_batch_progress'
    ];
    
    foreach ($import_options as $option) {
        delete_option($option);
        delete_transient($option);
    }
    
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%csv_import%lock%'");
    
    csv_import_log('info', 'Import-Status wurde komplett zurückgesetzt (Notfall-Reset)');
}

/**
 * Sicherer Import-Status Check mit automatischer Bereinigung
 */
function csv_import_is_import_running() {
    $progress = get_option('csv_import_progress', []);
    
    if (empty($progress)) {
        return false;
    }
    
    if (empty($progress['running'])) {
        return false;
    }
    
    if (!empty($progress['start_time'])) {
        $runtime = time() - $progress['start_time'];
        
        if ($runtime > 900) {
            csv_import_force_reset_import_status();
            return false;
        }
    }
    
    return true;
}

/**
 * Sichere Import-Start Funktion mit Doppel-Check
 */
function csv_import_safe_start_import($source) {
    if (csv_import_is_import_running()) {
        $progress = get_option('csv_import_progress', []);
        $runtime = !empty($progress['start_time']) ? time() - $progress['start_time'] : 0;
        
        return [
            'success' => false,
            'message' => "Ein Import läuft bereits seit " . human_time_diff($progress['start_time']) . ". Bitte warten Sie oder führen Sie einen Reset durch.",
            'debug' => [
                'current_status' => $progress['status'] ?? 'unknown',
                'runtime_seconds' => $runtime,
                'processed' => $progress['processed'] ?? 0,
                'total' => $progress['total'] ?? 0
            ]
        ];
    }
    
    if (!function_exists('csv_import_start_import')) {
        csv_import_log('error', 'csv_import_start_import Funktion nicht verfügbar');
        return [
            'success' => false,
            'message' => 'Import-Funktion nicht verfügbar. Plugin möglicherweise nicht vollständig geladen.',
            'debug' => ['missing_function' => 'csv_import_start_import']
        ];
    }
    
    csv_import_set_import_lock();
    
    try {
        return csv_import_start_import($source);
    } catch (Exception $e) {
        csv_import_remove_import_lock();
        csv_import_log('error', 'Import-Start Fehler: ' . $e->getMessage(), [
            'source' => $source,
            'trace' => $e->getTraceAsString()
        ]);
        throw $e;
    }
}

/**
 * Setzt einen Import-Lock zur Verhinderung von Doppel-Imports
 */
function csv_import_set_import_lock() {
    $lock_data = [
        'locked_at' => time(),
        'locked_by' => get_current_user_id(),
        'process_id' => getmypid(),
        'server_ip' => $_SERVER['SERVER_ADDR'] ?? 'unknown'
    ];
    
    update_option('csv_import_running_lock', $lock_data);
    update_option('csv_import_start_time', time());
}

/**
 * Entfernt den Import-Lock
 */
function csv_import_remove_import_lock() {
    delete_option('csv_import_running_lock');
    delete_option('csv_import_start_time');
}

/**
 * Admin-Notice für automatische Resets anzeigen
 */
function csv_import_show_stuck_reset_notice() {
    if (get_transient('csv_import_stuck_reset_notice')) {
        echo '<div class="notice notice-warning is-dismissible">
            <p><strong>CSV Import:</strong> Ein hängender Import-Prozess wurde automatisch zurückgesetzt. Sie können nun wieder importieren.</p>
        </div>';
        delete_transient('csv_import_stuck_reset_notice');
    }
}
add_action('admin_notices', 'csv_import_show_stuck_reset_notice');

/**
 * Erweiterte Bereinigungsfunktion für tote Import-Prozesse
 */
function csv_import_cleanup_dead_processes() {
    global $wpdb;
    
    $yesterday = time() - 86400;
    $progress = get_option('csv_import_progress', []);
    
    if (!empty($progress['start_time']) && $progress['start_time'] < $yesterday) {
        csv_import_force_reset_import_status();
        csv_import_log('info', 'Alter Import-Prozess (>24h) automatisch bereinigt');
    }
    
    $wpdb->query($wpdb->prepare("
        DELETE FROM {$wpdb->options} 
        WHERE option_name LIKE %s 
        AND option_value < %s
    ", 'csv_import_session_%', date('Y-m-d H:i:s', $yesterday)));
}

/**
 * Notfall-Reset Funktion für Admin-Interface
 */
function csv_import_emergency_reset() {
    if (!current_user_can('manage_options')) { 
        wp_die('Keine Berechtigung für diese Aktion.');
    }
    
    if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'csv_import_emergency_reset')) {
        wp_die('Sicherheitscheck fehlgeschlagen.');
    }
    
    csv_import_force_reset_import_status();
    csv_import_cleanup_temp_files();
    csv_import_cleanup_dead_processes();
    
    wp_redirect(add_query_arg([
        'page' => 'csv-import',
        'reset' => 'success'
    ], admin_url('tools.php')));
    exit;
}

/**
 * Fügt Notfall-Reset Link zum Admin-Menü hinzu
 */
function csv_import_add_emergency_reset_link() {
    if (csv_import_is_import_running()) {
        $reset_url = wp_nonce_url(
            add_query_arg(['csv_emergency_reset' => '1'], admin_url('tools.php?page=csv-import')),
            'csv_import_emergency_reset'
        );
        
        echo '<div class="notice notice-error">
            <p><strong>Import läuft bereits!</strong> Falls der Import hängt: 
            <a href="' . esc_url($reset_url) . '" class="button button-secondary" 
               onclick="return confirm(\'Import-Status wirklich zurücksetzen?\')">
               Notfall-Reset
            </a></p>
        </div>';
    }
}

add_action('admin_init', 'csv_import_check_stuck_imports');
add_action('csv_import_daily_maintenance', 'csv_import_cleanup_dead_processes');

add_action('admin_init', function() {
    if (isset($_GET['csv_emergency_reset']) && $_GET['csv_emergency_reset'] === '1') {
        csv_import_emergency_reset();
    }
});

// ===================================================================
// KONFIGURATIONSFUNKTIONEN
// ===================================================================

/**
 * Holt die gesamte Plugin-Konfiguration aus der Datenbank
 */
function csv_import_get_config(): array {
    $config_keys = [
        'template_id', 'post_type', 'post_status', 'page_builder',
        'dropbox_url', 'local_path', 'image_source', 'image_folder',
        'memory_limit', 'time_limit', 'seo_plugin', 'required_columns',
        'skip_duplicates'
    ];

    $config = [];
    foreach ( $config_keys as $key ) {
        $config[ $key ] = get_option( 'csv_import_' . $key, csv_import_get_default_value( $key ) );
    }

    if ( is_string( $config['required_columns'] ) ) {
        $config['required_columns'] = array_filter(
            array_map( 'trim', explode( "\n", $config['required_columns'] ?? '' ) )
        );
    }

    return $config;
}

/**
 * Gibt Standardwerte für die Plugin-Einstellungen zurück
 */
function csv_import_get_default_value( string $key ) {
    $defaults = [
        'template_id'      => 0,
        'post_type'        => 'page',
        'post_status'      => 'draft',
        'page_builder'     => 'gutenberg',
        'dropbox_url'      => '',
        'local_path'       => '',
        'image_source'     => 'media_library',
        'image_folder'     => '',
        'memory_limit'     => '256M',
        'time_limit'       => 300,
        'seo_plugin'       => 'none',
        'required_columns' => "post_title\npost_name",
        'skip_duplicates'  => true
    ];

    return $defaults[ $key ] ?? null;
}

// ===================================================================
// VALIDIERUNGSFUNKTIONEN
// ===================================================================

/**
 * Validiert die Plugin-Konfiguration
 */
function csv_import_validate_config( $config ): array {
    $errors = [];
    $validation = [
        'valid' => true,
        'errors' => [],
        'dropbox_ready' => false,
        'local_ready' => false
    ];
    
    if ( empty( $config['post_type'] ) || ! post_type_exists( $config['post_type'] ) ) {
        $errors[] = 'Ungültiger oder fehlender Post-Typ: ' . ($config['post_type'] ?? 'nicht gesetzt');
    }
    
    $valid_statuses = ['publish', 'draft', 'private', 'pending'];
    if ( ! in_array( $config['post_status'] ?? '', $valid_statuses ) ) {
        $errors[] = 'Ungültiger Post-Status: ' . ($config['post_status'] ?? 'nicht gesetzt');
    }
    
    if ( in_array( $config['page_builder'] ?? '', ['elementor', 'gutenberg'] ) ) {
        if ( empty( $config['template_id'] ) || ! is_numeric( $config['template_id'] ) ) {
            $errors[] = 'Template ID ist erforderlich für den gewählten Page Builder';
        } else {
            $template_post = get_post( $config['template_id'] );
            if ( ! $template_post ) {
                $errors[] = 'Template mit ID ' . $config['template_id'] . ' wurde nicht gefunden';
            }
        }
    }
    
    if ( ! empty( $config['dropbox_url'] ) ) {
        if ( filter_var( $config['dropbox_url'], FILTER_VALIDATE_URL ) ) {
            if ( strpos( $config['dropbox_url'], 'dropbox.com' ) !== false ) {
                $validation['dropbox_ready'] = true;
            } else {
                $errors[] = 'URL ist kein gültiger Dropbox-Link';
            }
        } else {
            $errors[] = 'Dropbox URL ist nicht gültig: ' . $config['dropbox_url'];
        }
    }
    
    if ( ! empty( $config['local_path'] ) ) {
        $full_path = ABSPATH . ltrim( $config['local_path'], '/' );
        if ( file_exists( $full_path ) && is_readable( $full_path ) ) {
            $validation['local_ready'] = true;
        } else {
            $errors[] = 'Lokaler Pfad existiert nicht oder ist nicht lesbar: ' . $config['local_path'];
        }
    }
    
    if ( ! $validation['dropbox_ready'] && ! $validation['local_ready'] ) {
        $errors[] = 'Mindestens eine CSV-Quelle (Dropbox oder lokal) muss konfiguriert und verfügbar sein';
    }
    
    $required_columns = $config['required_columns'] ?? [];
    if ( is_string( $required_columns ) ) {
        $required_columns = array_filter( array_map( 'trim', explode( "\n", $required_columns ) ) );
    }
    if ( empty( $required_columns ) ) {
        $errors[] = 'Erforderliche Spalten müssen definiert sein';
    }
    
    if ( ( $config['image_source'] ?? 'none' ) !== 'none' ) {
        $image_dir = ABSPATH . ltrim( $config['image_folder'] ?? '', '/' );
        if ( ! is_dir( $image_dir ) ) {
            $errors[] = 'Bildordner existiert nicht: ' . ($config['image_folder'] ?? 'nicht gesetzt');
        } elseif ( ! is_writable( $image_dir ) ) {
            $errors[] = 'Bildordner ist nicht beschreibbar: ' . $config['image_folder'];
        }
    }
    
    $memory_limit = $config['memory_limit'] ?? '256M';
    $memory_bytes = csv_import_convert_to_bytes( $memory_limit );
    if ( $memory_bytes < csv_import_convert_to_bytes( '128M' ) ) {
        $errors[] = 'Memory Limit sollte mindestens 128M betragen (aktuell: ' . $memory_limit . ')';
    }
    
    $validation['errors'] = $errors;
    $validation['valid'] = empty( $errors );
    
    return $validation;
}

/**
 * Validiert eine CSV-Quelle (Dropbox oder lokal)
 */
function csv_import_validate_csv_source( string $type, array $config ): array {
    $result = [
        'valid' => false,
        'message' => '',
        'rows' => 0,
        'columns' => [],
        'sample_data' => []
    ];
    
    try {
        if ( $type === 'dropbox' ) {
            $result = csv_import_validate_dropbox_source( $config );
        } elseif ( $type === 'local' ) {
            $result = csv_import_validate_local_source( $config );
        } else {
            throw new Exception( 'Unbekannter Quelltyp: ' . $type );
        }
    } catch ( Exception $e ) {
        $result['message'] = 'Validierungsfehler: ' . $e->getMessage();
        
        if ( class_exists( 'CSV_Import_Error_Handler' ) ) {
            CSV_Import_Error_Handler::handle(
                CSV_Import_Error_Handler::LEVEL_ERROR,
                'CSV-Quellen-Validierung fehlgeschlagen: ' . $e->getMessage(),
                [
                    'type' => $type,
                    'config' => $config,
                    'trace' => $e->getTraceAsString()
                ]
            );
        } else {
            error_log( 'CSV Import Pro: CSV-Validierung fehlgeschlagen - ' . $e->getMessage() );
        }
    }
    
    return $result;
}

/**
 * Validiert Dropbox CSV-Quelle
 */
function csv_import_validate_dropbox_source( array $config ): array {
    if ( empty( $config['dropbox_url'] ) ) {
        throw new Exception( 'Dropbox URL nicht konfiguriert' );
    }
    
    $download_url = $config['dropbox_url'];
    if ( strpos( $download_url, 'dropbox.com' ) !== false ) {
        $download_url = str_replace( 'www.dropbox.com', 'dl.dropboxusercontent.com', $download_url );
        $download_url = str_replace( 'dropbox.com', 'dl.dropboxusercontent.com', $download_url );
        $download_url = str_replace( '?dl=0', '', $download_url );
        $download_url = str_replace( '?dl=1', '', $download_url );
        if ( strpos( $download_url, '?' ) === false ) {
            $download_url .= '?raw=1';
        }
    }
    
    $response = wp_remote_get( $download_url, [
        'timeout' => 30,
        'headers' => [
            'User-Agent' => 'CSV Import Pro/' . (defined('CSV_IMPORT_PRO_VERSION') ? CSV_IMPORT_PRO_VERSION : '5.3')
        ]
    ] );
    
    if ( is_wp_error( $response ) ) {
        throw new Exception( 'Dropbox-Datei konnte nicht abgerufen werden: ' . $response->get_error_message() );
    }
    
    $http_code = wp_remote_retrieve_response_code( $response );
    if ( $http_code !== 200 ) {
        throw new Exception( 'Dropbox-Datei nicht verfügbar (HTTP ' . $http_code . ')' );
    }
    
    $csv_content = wp_remote_retrieve_body( $response );
    if ( empty( $csv_content ) ) {
        throw new Exception( 'Dropbox-Datei ist leer oder konnte nicht gelesen werden' );
    }
    
    return csv_import_analyze_csv_content( $csv_content, 'Dropbox' );
}

/**
 * Validiert lokale CSV-Quelle
 */
function csv_import_validate_local_source( array $config ): array {
    if ( empty( $config['local_path'] ) ) {
        throw new Exception( 'Lokaler Pfad nicht konfiguriert' );
    }
    
    $file_path = ABSPATH . ltrim( $config['local_path'], '/' );
    
    if ( ! file_exists( $file_path ) ) {
        throw new Exception( 'Datei nicht gefunden: ' . $config['local_path'] . ' (Vollständiger Pfad: ' . $file_path . ')' );
    }
    
    if ( ! is_readable( $file_path ) ) {
        throw new Exception( 'Datei nicht lesbar: ' . $config['local_path'] );
    }
    
    $csv_content = file_get_contents( $file_path );
    if ( $csv_content === false ) {
        throw new Exception( 'Datei konnte nicht gelesen werden: ' . $config['local_path'] );
    }
    
    return csv_import_analyze_csv_content( $csv_content, 'Lokal (' . basename( $file_path ) . ')' );
}

/**
 * Analysiert CSV-Inhalt und gibt Validierungsergebnis zurück
 */
function csv_import_analyze_csv_content( string $csv_content, string $source_name ): array {
    if ( empty( trim( $csv_content ) ) ) {
        throw new Exception( 'CSV-Datei ist leer' );
    }

    $csv_content = str_replace( [ "\r\n", "\r" ], "\n", $csv_content );
    $delimiters = [',', ';', "\t", '|'];
    $best_result = null;
    $max_columns = 0;

    foreach ( $delimiters as $delimiter_char ) {
        $lines = explode("\n", $csv_content);
        if ( ! empty( $lines ) ) {
            $actual_delimiter = $delimiter_char === '\t' ? "\t" : $delimiter_char;
            $headers = str_getcsv( $lines[0], $actual_delimiter );
            
            if ( count( $headers ) > $max_columns ) {
                $max_columns = count( $headers );
                $best_result = [
                    'lines' => $lines,
                    'headers' => $headers,
                    'delimiter' => $delimiter_char,
                    'actual_delimiter' => $actual_delimiter
                ];
            }
        }
    }

    if ( ! $best_result || $max_columns < 2 ) {
        throw new Exception( 'Keine gültigen CSV-Daten gefunden. Stellen Sie sicher, dass die Datei korrekt formatiert ist und ein gängiges Trennzeichen (Komma, Semikolon, Tab) verwendet wird.' );
    }

    $lines = $best_result['lines'];
    $headers = $best_result['headers'];
    $delimiter = $best_result['delimiter'];
    $actual_delimiter = $best_result['actual_delimiter'];

    $headers = array_map( 'trim', $headers );
    $headers = array_filter( $headers );

    if ( empty( $headers ) ) {
        throw new Exception( 'Keine gültigen Spalten-Header in der CSV-Datei gefunden.' );
    }

    $sample_data = [];
    $data_lines = array_slice($lines, 1);

    foreach ( array_slice($data_lines, 0, 3) as $line ) {
        if ( ! empty( trim( $line ) ) ) {
            $row_data = str_getcsv( $line, $actual_delimiter );
            $sample_data[] = $row_data;
        }
    }

    $non_empty_rows = count(array_filter($data_lines, 'trim'));
    $total_rows = count($lines) -1;

    $message = "CSV erfolgreich validiert!<br>" .
               "<strong>Gesamtzeilen:</strong> {$total_rows}<br>" .
               "<strong>Datenzeilen:</strong> {$non_empty_rows}<br>" .
               "<strong>Spalten:</strong> " . count( $headers ) . "<br>" .
               "<strong>Erkanntes Trennzeichen:</strong> " . ($delimiter === '\t' ? 'Tabulator' : "'{$delimiter}'") . "<br>" .
               "<strong>Header-Vorschau:</strong> " . implode( ', ', array_slice( $headers, 0, 5 ) ) .
               ( count( $headers ) > 5 ? ' ... (und ' . (count( $headers ) - 5) . ' weitere)' : '' );

    return [
        'valid' => true,
        'message' => $message,
        'rows' => $non_empty_rows,
        'total_rows' => $total_rows,
        'columns' => $headers,
        'sample_data' => $sample_data,
        'delimiter' => $delimiter
    ];
}

// ===================================================================
// CSV VERARBEITUNGSFUNKTIONEN
// ===================================================================

/**
 * Lädt CSV-Daten von einer Quelle
 */
function csv_import_load_csv_data( string $source, array $config ): array {
    if ( $source === 'dropbox' ) {
        return csv_import_load_dropbox_csv( $config );
    } elseif ( $source === 'local' ) {
        return csv_import_load_local_csv( $config );
    } else {
        throw new Exception( 'Unbekannte CSV-Quelle: ' . $source );
    }
}

/**
 * Lädt CSV-Daten von Dropbox
 */
function csv_import_load_dropbox_csv( array $config ): array {
    if ( empty( $config['dropbox_url'] ) ) {
        throw new Exception( 'Dropbox URL nicht konfiguriert' );
    }
    
    $download_url = $config['dropbox_url'];
    if ( strpos( $download_url, 'dropbox.com' ) !== false ) {
        $download_url = str_replace( 'www.dropbox.com', 'dl.dropboxusercontent.com', $download_url );
        $download_url = str_replace( 'dropbox.com', 'dl.dropboxusercontent.com', $download_url );
        $download_url = str_replace( '?dl=0', '', $download_url );
        $download_url = str_replace( '?dl=1', '', $download_url );
        if ( strpos( $download_url, '?' ) === false ) {
            $download_url .= '?raw=1';
        }
    }
    
    $response = wp_remote_get( $download_url, [
        'timeout' => 60,
        'headers' => [
            'User-Agent' => 'CSV Import Pro/' . (defined('CSV_IMPORT_PRO_VERSION') ? CSV_IMPORT_PRO_VERSION : '5.3')
        ]
    ] );
    
    if ( is_wp_error( $response ) ) {
        throw new Exception( 'Dropbox-Datei konnte nicht geladen werden: ' . $response->get_error_message() );
    }
    
    $csv_content = wp_remote_retrieve_body( $response );
    return csv_import_parse_csv_content( $csv_content );
}

/**
 * Lädt CSV-Daten von lokaler Datei
 */
function csv_import_load_local_csv( array $config ): array {
    if ( empty( $config['local_path'] ) ) {
        throw new Exception( 'Lokaler Pfad nicht konfiguriert' );
    }
    
    $file_path = ABSPATH . ltrim( $config['local_path'], '/' );
    
    if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
        throw new Exception( 'CSV-Datei nicht gefunden oder nicht lesbar: ' . $config['local_path'] );
    }
    
    $csv_content = file_get_contents( $file_path );
    if ( $csv_content === false ) {
        throw new Exception( 'CSV-Datei konnte nicht gelesen werden' );
    }
    
    return csv_import_parse_csv_content( $csv_content );
}

/**
 * Parst CSV-Inhalt in ein Array
 */
function csv_import_parse_csv_content( string $csv_content ): array {
    if ( empty( trim( $csv_content ) ) ) {
        throw new Exception( 'CSV-Inhalt ist leer' );
    }
    
    $csv_content = csv_import_normalize_line_endings( $csv_content );
    
    $saved_delimiter = get_option( 'csv_import_delimiter', 'auto' );

    if ( ! empty( $saved_delimiter ) && 'auto' !== $saved_delimiter ) {
        $delimiter = str_replace( '\t', "\t", $saved_delimiter );
    } else {
        $delimiter = csv_import_detect_csv_delimiter( $csv_content );
    }
    
    $lines = str_getcsv( $csv_content, "\n" );
    if ( empty( $lines ) ) {
        throw new Exception( 'Keine CSV-Zeilen gefunden' );
    }
    
    $headers = str_getcsv( $lines[0], $delimiter );
    $headers = array_map( 'trim', $headers );
    
    if ( empty( array_filter( $headers ) ) ) {
        throw new Exception( 'Keine gültigen Header gefunden' );
    }
    
    $data = [];
    for ( $i = 1; $i < count( $lines ); $i++ ) {
        $line = trim( $lines[ $i ] );
        if ( empty( $line ) ) {
            continue;
        }
        
        $row = str_getcsv( $line, $delimiter );
        $row = array_map( 'trim', $row );
        
        $row_data = [];
        for ( $j = 0; $j < count( $headers ); $j++ ) {
            $row_data[ $headers[ $j ] ] = $row[ $j ] ?? '';
        }
        
        $data[] = $row_data;
    }
    
    return [
        'headers' => $headers,
        'data' => $data,
        'total_rows' => count( $data ),
        'delimiter' => $delimiter
    ];
}

/**
 * Erkennt das CSV-Trennzeichen automatisch
 */
function csv_import_detect_csv_delimiter( string $csv_content ): string {
    $delimiters = [',', ';', "\t", '|'];
    $line = strtok( $csv_content, "\n" );
    
    $delimiter_count = [];
    foreach ( $delimiters as $delimiter ) {
        $delimiter_count[ $delimiter ] = substr_count( $line, $delimiter );
    }
    
    arsort( $delimiter_count );
    return array_key_first( $delimiter_count );
}

// ===================================================================
// IMPORT HAUPT-FUNKTIONEN MIT SICHERHEITSCHECKS
// ===================================================================

/**
 * Startet den CSV-Import mit erweiterten Sicherheitschecks
 */
function csv_import_start_import( string $source, array $config = null ): array {
    try {
        if ( csv_import_is_import_running() ) {
            $progress = get_option('csv_import_progress', []);
            return [
                'success' => false,
                'message' => 'Ein Import läuft bereits. Bitte warten Sie, bis dieser abgeschlossen ist.',
                'debug' => [
                    'current_status' => $progress['status'] ?? 'unknown',
                    'processed' => $progress['processed'] ?? 0,
                    'total' => $progress['total'] ?? 0,
                    'start_time' => $progress['start_time'] ?? 0
                ]
            ];
        }
        
        if ( $config === null ) {
            $config = csv_import_get_config();
        }
        
        csv_import_set_import_lock();
        
        $session_id = 'import_' . time() . '_' . uniqid();
        
        csv_import_log( 'info', "Import gestartet - Quelle: {$source}, Session: {$session_id}" );
        
        csv_import_create_backup( $session_id );
        
        $csv_data = csv_import_load_csv_data( $source, $config );
        
        if ( empty( $csv_data['data'] ) ) {
            throw new Exception( 'Keine Daten in CSV-Datei gefunden' );
        }
        
        csv_import_update_progress( 0, count( $csv_data['data'] ), 'starting' );
        
        $result = csv_import_process_data( $csv_data, $config, $session_id );
        
        csv_import_update_import_stats( $result, $source );
        
        csv_import_update_progress( 
            $result['processed'], 
            $result['total'], 
            $result['errors'] > 0 ? 'completed_with_errors' : 'completed' 
        );
        
        csv_import_remove_import_lock();
        
        csv_import_log( 'info', "Import abgeschlossen - {$result['processed']} Einträge verarbeitet, {$result['errors']} Fehler" );
        
        return $result;
        
    } catch ( Exception $e ) {
        csv_import_remove_import_lock();
        csv_import_update_progress( 0, 0, 'failed' );
        
        csv_import_log( 'error', 'Import fehlgeschlagen: ' . $e->getMessage(), [
            'trace' => $e->getTraceAsString(),
            'source' => $source
        ] );
        
        return [
            'success' => false,
            'message' => $e->getMessage(),
            'processed' => 0,
            'total' => 0,
            'errors' => 1,
            'debug' => [
                'exception_class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'source' => $source
            ]
        ];
    }
}

/**
 * Verarbeitet die CSV-Daten und erstellt Posts
 */
function csv_import_process_data( array $csv_data, array $config, string $session_id ): array {
    $processed = 0;
    $errors = 0;
    $created_posts = [];
    $error_messages = [];
    
    $data = $csv_data['data'];
    $total = count( $data );
    
    $required_columns = is_array( $config['required_columns'] ) 
        ? $config['required_columns'] 
        : explode( "\n", $config['required_columns'] );
    
    $column_validation = csv_import_validate_required_columns( $csv_data['headers'], $required_columns );
    if ( ! $column_validation['valid'] ) {
        throw new Exception( 'Erforderliche Spalten fehlen: ' . implode( ', ', $column_validation['missing'] ) );
    }
    
    foreach ( $data as $index => $row ) {
        try {
            if ( $index % 5 === 0 ) {
                $current_progress = get_option('csv_import_progress', []);
                if ( empty($current_progress['running']) ) {
                    csv_import_log( 'info', 'Import wurde vom Benutzer abgebrochen' );
                    break;
                }
            }
            
            csv_import_update_progress( $processed, $total, 'processing' );
            
            $post_result = csv_import_create_post_from_row( $row, $config, $session_id );
            
            if ( $post_result === 'created' ) {
                $created_posts[] = $post_result;
                $processed++;
            } elseif ( $post_result === 'skipped' ) {
                // Übersprungen
            }
            
            $processed++;
            
            if ( $processed % 10 === 0 ) {
                usleep( 100000 );
            }
            
        } catch ( Exception $e ) {
            $errors++;
            $error_msg = "Zeile " . ($index + 2) . ": " . $e->getMessage();
            $error_messages[] = $error_msg;
            
            csv_import_log( 'warning', $error_msg, [
                'row_data' => $row,
                'session_id' => $session_id
            ] );
            
            if ( $errors > 50 ) {
                csv_import_log( 'error', 'Import abgebrochen - zu viele Fehler (>50)' );
                break;
            }
        }
    }
    
    return [
        'success' => $processed > 0,
        'processed' => $processed,
        'total' => $total,
        'errors' => $errors,
        'created_posts' => $created_posts,
        'error_messages' => array_slice( $error_messages, 0, 10 ),
        'session_id' => $session_id
    ];
}

/**
 * Erstellt einen WordPress-Post aus einer CSV-Zeile
 */
function csv_import_create_post_from_row( array $row, array $config, string $session_id ) {
    $post_title = csv_import_sanitize_title( $row['post_title'] ?? $row['title'] ?? 'Untitled' );
    $post_content = $row['post_content'] ?? $row['content'] ?? '';
    $post_excerpt = $row['post_excerpt'] ?? $row['excerpt'] ?? '';
    $post_slug = $row['post_name'] ?? csv_import_generate_unique_slug( $post_title, $config['post_type'] );
    
    if ( empty( $post_title ) ) {
        throw new Exception( 'Post-Titel ist erforderlich' );
    }
    
    if ( $config['skip_duplicates'] ) {
        $existing_post = get_page_by_title( $post_title, OBJECT, $config['post_type'] );
        if ( $existing_post ) {
            return 'skipped';
        }
    }
    
    $post_data = [
        'post_title'   => $post_title,
        'post_content' => $post_content,
        'post_excerpt' => $post_excerpt,
        'post_name'    => $post_slug,
        'post_status'  => $config['post_status'],
        'post_type'    => $config['post_type'],
        'meta_input'   => [
            '_csv_import_session' => $session_id,
            '_csv_import_date' => current_time( 'mysql' ),
        ]
    ];
    
    if ( $config['page_builder'] !== 'none' && ! empty( $config['template_id'] ) ) {
        $post_data['post_content'] = csv_import_apply_template( $config['template_id'], $row, $config );
    }
    
    $post_id = wp_insert_post( $post_data );
    
    if ( is_wp_error( $post_id ) ) {
        throw new Exception( 'WordPress Fehler: ' . $post_id->get_error_message() );
    }
    
    csv_import_add_meta_fields( $post_id, $row, $config );
    
    if ( $config['image_source'] !== 'none' ) {
        csv_import_process_images( $post_id, $row, $config );
    }
    
    if ( $config['seo_plugin'] !== 'none' ) {
        csv_import_add_seo_data( $post_id, $row, $config );
    }
    
    return 'created';
}

/**
 * Wendet ein Template auf Post-Content an
 * NEUE VERSION: Erkennt Page-Builder-JSON (z.B. Breakdance) und wendet Platzhalter sicher an.
 */

function csv_import_apply_template( int $template_id, array $row, array $config ): string {
    $template_post = get_post( $template_id );
    if ( ! $template_post ) {
        throw new Exception( "Template mit ID {$template_id} nicht gefunden" );
    }

    $content = $template_post->post_content;
    $page_builder = $config['page_builder'] ?? 'gutenberg';

    // Spezielle Logik für JSON-basierte Page Builder wie Breakdance
    if ($page_builder === 'breakdance') {
        $json_data = json_decode($content, true);

        // Prüfen, ob der Inhalt valides JSON ist
        if (json_last_error() === JSON_ERROR_NONE && is_array($json_data)) {

            // Rekursive Funktion, um Platzhalter in verschachtelten Arrays/Objekten zu ersetzen
            $json_replacer = function(&$item) use ($row, &$json_replacer) {
                if (is_string($item)) {
                    foreach ($row as $key => $value) {
                        $placeholder = '{{' . trim($key) . '}}';
                        $item = str_replace($placeholder, $value, $item);
                    }
                } elseif (is_array($item)) {
                    foreach ($item as &$value) {
                        $json_replacer($value);
                    }
                }
            };

            $json_replacer($json_data);

            // Die Daten zurück in einen JSON-String konvertieren
            return wp_json_encode($json_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }

    // Fallback / Standard-Verhalten für Gutenberg, Enfold etc.
    foreach ( $row as $key => $value ) {
        $placeholder = '{{' . $key . '}}';
        $content = str_replace( $placeholder, $value, $content );
    }

    // Legacy-Platzhalter
    $content = str_replace( '{{title}}', $row['post_title'] ?? '', $content );
    $content = str_replace( '{{content}}', $row['post_content'] ?? '', $content );

    return $content;
}

/**
 * Fügt Meta-Felder zum Post hinzu
 */
function csv_import_add_meta_fields( int $post_id, array $row, array $config ): void {
    $skip_fields = ['post_title', 'post_content', 'post_excerpt', 'post_name', 'title', 'content', 'excerpt'];
    
    foreach ( $row as $key => $value ) {
        if ( ! in_array( $key, $skip_fields ) && ! empty( $value ) ) {
            $meta_key = sanitize_key( $key );
            if ( strpos( $meta_key, '_' ) !== 0 ) {
                $meta_key = '_' . $meta_key;
            }
            
            update_post_meta( $post_id, $meta_key, sanitize_text_field( $value ) );
        }
    }
}

/**
 * Verarbeitet Bilder für einen Post
 */
function csv_import_process_images( int $post_id, array $row, array $config ): void {
    $image_fields = ['image', 'featured_image', 'thumbnail', 'post_image'];
    $image_url = '';
    
    foreach ( $image_fields as $field ) {
        if ( ! empty( $row[ $field ] ) ) {
            $image_url = $row[ $field ];
            break;
        }
    }
    
    if ( empty( $image_url ) ) {
        return;
    }
    
    try {
        $attachment_id = csv_import_download_and_attach_image( $image_url, $post_id );
        
        if ( $attachment_id ) {
            set_post_thumbnail( $post_id, $attachment_id );
            update_post_meta( $post_id, '_csv_import_image_attached', true );
        }
        
    } catch ( Exception $e ) {
        csv_import_log( 'warning', "Fehler beim Verarbeiten des Bildes für Post {$post_id}: " . $e->getMessage() );
    }
}

/**
 * Lädt ein Bild herunter und fügt es zur Media Library hinzu
 */
function csv_import_download_and_attach_image( string $image_url, int $post_id ): int {
    if ( ! filter_var( $image_url, FILTER_VALIDATE_URL ) ) {
        throw new Exception( 'Ungültige Bild-URL: ' . $image_url );
    }
    
    $image_url = esc_url_raw( $image_url );
    $image_name = basename( $image_url );
    
    $response = wp_remote_get( $image_url, [
        'timeout' => 30
    ] );
    
    if ( is_wp_error( $response ) ) {
        throw new Exception( 'Konnte Bild nicht herunterladen: ' . $response->get_error_message() );
    }
    
    $image_data = wp_remote_retrieve_body( $response );
    $http_code = wp_remote_retrieve_response_code( $response );
    
    if ( $http_code !== 200 || empty( $image_data ) ) {
        throw new Exception( "Bild-Download fehlgeschlagen (HTTP {$http_code})" );
    }
    
    $upload_dir = wp_upload_dir();
    $temp_file = $upload_dir['basedir'] . '/csv-import-temp/' . $image_name;
    
    wp_mkdir_p( dirname( $temp_file ) );
    
    if ( file_put_contents( $temp_file, $image_data ) === false ) {
        throw new Exception( 'Konnte temporäre Datei nicht erstellen' );
    }
    
    $attachment_data = [
        'post_title' => sanitize_text_field( pathinfo( $image_name, PATHINFO_FILENAME ) ),
        'post_content' => '',
        'post_status' => 'inherit',
        'post_parent' => $post_id
    ];
    
    $attachment_id = wp_insert_attachment( $attachment_data, $temp_file, $post_id );
    
    if ( is_wp_error( $attachment_id ) ) {
        @unlink( $temp_file );
        throw new Exception( 'Konnte Attachment nicht erstellen: ' . $attachment_id->get_error_message() );
    }
    
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $attachment_metadata = wp_generate_attachment_metadata( $attachment_id, $temp_file );
    wp_update_attachment_metadata( $attachment_id, $attachment_metadata );
    
    @unlink( $temp_file );
    
    return $attachment_id;
}



function csv_import_add_seo_data( int $post_id, array $row, array $config ): void {
    $seo_plugin = $config['seo_plugin'];
    $set_noindex = get_option('csv_import_noindex_posts', false);

    // ===================================================================
    // ALL IN ONE SEO (AIOSEO) - KORRIGIERT FÜR IHRE CSV-STRUKTUR
    // ===================================================================
    if ( $seo_plugin === 'aioseo' ) {
        // Prüfen ob AIOSEO installiert und aktiv ist
        if ( ! class_exists( 'AIOSEO\\Plugin\\AIOSEO' ) && ! function_exists( 'aioseo' ) ) {
            csv_import_log( 'warning', "All in One SEO Plugin nicht gefunden - überspringe SEO-Daten für Post {$post_id}" );
            return;
        }

        // SEO Title aus meta_title Spalte setzen
        if ( ! empty( $row['meta_title'] ) ) {
            update_post_meta( 
                $post_id, 
                '_aioseo_title', 
                sanitize_text_field( $row['meta_title'] ) 
            );
        }
        
        // SEO Description aus meta_description Spalte setzen
        if ( ! empty( $row['meta_description'] ) ) {
            update_post_meta( 
                $post_id, 
                '_aioseo_description', 
                sanitize_textarea_field( $row['meta_description'] ) 
            );
        }
        
        // Fallback: Wenn meta_title leer ist, nutze post_title
        if ( empty( $row['meta_title'] ) && ! empty( $row['post_title'] ) ) {
            update_post_meta( 
                $post_id, 
                '_aioseo_title', 
                sanitize_text_field( $row['post_title'] ) 
            );
        }
        
        // Noindex setzen wenn aktiviert
        if ( $set_noindex ) {
            // AIOSEO verwendet eine JSON-Struktur für robots Meta
            $robots_meta = [
                'default' => false,
                'noindex' => true,
                'nofollow' => false,
                'noarchive' => false,
                'noimageindex' => false,
                'nosnippet' => false
            ];
            
            update_post_meta( 
                $post_id, 
                '_aioseo_robots_default', 
                0 
            );
            
            update_post_meta( 
                $post_id, 
                '_aioseo_robots_noindex', 
                1 
            );
        }
        
        // Logging für Debug-Zwecke
        csv_import_log( 'debug', "AIOSEO-Daten für Post {$post_id} gesetzt", [
            'post_id' => $post_id,
            'post_title' => $row['post_title'] ?? '',
            'post_name' => $row['post_name'] ?? '',
            'meta_title' => $row['meta_title'] ?? '',
            'meta_description' => $row['meta_description'] ?? '',
            'has_meta_title' => ! empty( $row['meta_title'] ),
            'has_meta_description' => ! empty( $row['meta_description'] ),
            'noindex_set' => $set_noindex
        ]);
    }
    
    // ===================================================================
    // YOAST SEO - Angepasst für meta_title und meta_description
    // ===================================================================
    elseif ( $seo_plugin === 'yoast' && class_exists( 'WPSEO_Options' ) ) {
        if ( ! empty( $row['meta_title'] ) ) {
            update_post_meta( 
                $post_id, 
                '_yoast_wpseo_title', 
                sanitize_text_field( $row['meta_title'] ) 
            );
        }
        
        if ( ! empty( $row['meta_description'] ) ) {
            update_post_meta( 
                $post_id, 
                '_yoast_wpseo_metadesc', 
                sanitize_textarea_field( $row['meta_description'] ) 
            );
        }
        
        if ( $set_noindex ) {
            update_post_meta( 
                $post_id, 
                '_yoast_wpseo_meta-robots-noindex', 
                '1' 
            );
        }
        
        csv_import_log( 'debug', "Yoast SEO-Daten für Post {$post_id} gesetzt" );
    }
    
    // ===================================================================
    // RANK MATH - Angepasst für meta_title und meta_description
    // ===================================================================
    elseif ( $seo_plugin === 'rankmath' && class_exists( 'RankMath' ) ) {
        if ( ! empty( $row['meta_title'] ) ) {
            update_post_meta( 
                $post_id, 
                'rank_math_title', 
                sanitize_text_field( $row['meta_title'] ) 
            );
        }
        
        if ( ! empty( $row['meta_description'] ) ) {
            update_post_meta( 
                $post_id, 
                'rank_math_description', 
                sanitize_textarea_field( $row['meta_description'] ) 
            );
        }
        
        if ( $set_noindex ) {
            update_post_meta( 
                $post_id, 
                'rank_math_robots', 
                ['noindex'] 
            );
        }
        
        csv_import_log( 'debug', "Rank Math SEO-Daten für Post {$post_id} gesetzt" );
    }
    
    // ===================================================================
    // FALLBACK: Kein SEO-Plugin oder Standard-Meta
    // ===================================================================
    else {
        // Setze generische Meta-Tags als Fallback
        if ( ! empty( $row['meta_title'] ) ) {
            update_post_meta( 
                $post_id, 
                '_seo_title', 
                sanitize_text_field( $row['meta_title'] ) 
            );
        }
        
        if ( ! empty( $row['meta_description'] ) ) {
            update_post_meta( 
                $post_id, 
                '_seo_description', 
                sanitize_textarea_field( $row['meta_description'] ) 
            );
        }
        
        if ( $set_noindex ) {
            update_post_meta( $post_id, '_noindex', '1' );
        }
        
        csv_import_log( 'debug', "Standard-SEO-Daten für Post {$post_id} gesetzt (kein SEO-Plugin aktiv)" );
    }
}

// ===================================================================
// SYSTEM & HEALTH FUNKTIONEN
// ===================================================================

/**
 * Überprüft den Systemzustand
 */
function csv_import_system_health_check(): array {
    $health = [
        'memory_ok'          => true,
        'time_ok'            => true,
        'disk_space_ok'      => true,
        'permissions_ok'     => true,
        'php_version_ok'     => true,
        'curl_ok'            => true,
        'wp_version_ok'      => true,
        'import_locks_ok'    => true,
        'no_stuck_processes' => true,
    ];

    $memory_limit = ini_get( 'memory_limit' );
    if ( $memory_limit && $memory_limit !== '-1' ) {
        $memory_bytes = csv_import_convert_to_bytes( $memory_limit );
        $health['memory_ok'] = $memory_bytes >= 128 * 1024 * 1024;
    }

    $time_limit = ini_get( 'max_execution_time' );
    $health['time_ok'] = ( $time_limit == 0 || $time_limit >= 60 );

    $health['php_version_ok'] = version_compare( PHP_VERSION, '7.4', '>=' );

    global $wp_version;
    $health['wp_version_ok'] = version_compare( $wp_version, '5.0', '>=' );

    $health['curl_ok'] = function_exists( 'curl_init' );

    $free_space = @disk_free_space( ABSPATH );
    if ( $free_space ) {
        $health['disk_space_ok'] = $free_space >= 100 * 1024 * 1024;
    }

    $upload_dir = wp_upload_dir();
    $health['permissions_ok'] = is_writable( $upload_dir['basedir'] );

    global $wpdb;

    $lock_value = $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'csv_import_running_lock' LIMIT 1" );
    $lock_data = maybe_unserialize($lock_value);
    $health['import_locks_ok'] = empty($lock_data);

    $progress_value = $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'csv_import_progress' LIMIT 1" );
    if ( ! empty($progress_value) ) {
        $progress = maybe_unserialize($progress_value);
        if ( is_array($progress) && !empty($progress['running']) && !empty($progress['start_time']) ) {
            $runtime = time() - $progress['start_time'];
            $health['no_stuck_processes'] = $runtime <= 600;
        } else {
            $health['no_stuck_processes'] = true;
        }
    } else {
        $health['no_stuck_processes'] = true;
    }

    return $health;
}

/**
 * Konvertiert Grössenangaben wie '256M' in Bytes
 */
function csv_import_convert_to_bytes( string $size_str ): int {
    $size_str = trim( $size_str );
    if ( empty( $size_str ) || $size_str === '-1' ) {
        return PHP_INT_MAX;
    }
    
    $last = strtolower( $size_str[ strlen( $size_str ) - 1 ] );
    $size = (int) $size_str;

    switch ( $last ) {
        case 'g':
            $size *= 1024;
        case 'm':
            $size *= 1024;
        case 'k':
            $size *= 1024;
    }

    return $size;
}

// ===================================================================
// FORTSCHRITT & STATISTIKEN
// ===================================================================

/**
 * Holt den aktuellen Import-Fortschritt
 */
function csv_import_get_progress(): array {
    $progress = get_option( 'csv_import_progress', [] );
    $default_progress = [
        'running'    => false,
        'processed'  => 0,
        'total'      => 0,
        'percent'    => 0,
        'status'     => 'idle',
        'message'    => 'Kein aktiver Import',
        'timestamp'  => 0,
        'start_time' => 0,
        'errors'     => 0
    ];
    
    $progress = wp_parse_args( $progress, $default_progress );
    
    if ( $progress['running'] && $progress['start_time'] > 0 ) {
        $runtime = time() - $progress['start_time'];
        if ( $runtime > 900 ) {
            $progress['running'] = false;
            $progress['status'] = 'timeout';
            $progress['message'] = 'Import-Timeout nach ' . human_time_diff($progress['start_time']) . ' - automatisch zurückgesetzt';
            update_option( 'csv_import_progress', $progress );
        }
    }
    
    return $progress;
}

/**
 * Holt allgemeine Import-Statistiken
 */
function csv_import_get_stats(): array {
    return [
        'total_imported' => get_option( 'csv_import_total_imported', 0 ),
        'last_run'       => get_option( 'csv_import_last_run', 'Nie' ),
        'last_count'     => get_option( 'csv_import_last_count', 0 ),
        'last_source'    => get_option( 'csv_import_last_source', 'Keine' ),
        'success_rate'   => get_option( 'csv_import_last_success_rate', 0 ),
        'avg_processing_time' => get_option( 'csv_import_avg_processing_time', 0 )
    ];
}

/**
 * Holt Fehlerstatistiken
 */
function csv_import_get_error_stats(): array {
    return get_option( 'csv_import_error_stats', [
        'total_errors'        => 0,
        'total_real_errors'   => 0,
        'errors_by_level'     => [],
        'recent_errors'       => [],
        'error_trends'        => [],
        'critical_errors_24h' => 0,
        'warning_errors_24h'  => 0
    ] );
}

/**
 * Aktualisiert den Fortschritt eines laufenden Imports
 */
function csv_import_update_progress( int $processed, int $total, string $status = 'processing' ): void {
    $current_progress = get_option( 'csv_import_progress', [] );
    
    $progress = [
        'running'    => ( $status !== 'completed' && $status !== 'failed' && $status !== 'timeout' ),
        'processed'  => $processed,
        'total'      => $total,
        'percent'    => $total > 0 ? round( ( $processed / $total ) * 100, 1 ) : 0,
        'status'     => $status,
        'message'    => csv_import_get_status_message( $status, $processed, $total ),
        'timestamp'  => current_time( 'timestamp' ),
        'start_time' => $current_progress['start_time'] ?? current_time( 'timestamp' ),
        'errors'     => $current_progress['errors'] ?? 0
    ];
    
    if ( $processed > 5 && $total > $processed ) {
        $elapsed = time() - $progress['start_time'];
        $rate = $processed / $elapsed;
        $remaining = $total - $processed;
        $eta = $remaining / $rate;
        $progress['eta_seconds'] = (int) $eta;
        $progress['eta_human'] = human_time_diff( time(), time() + $eta );
    }
    
    update_option( 'csv_import_progress', $progress );
    
    if ( $status === 'starting' ) {
        update_option( 'csv_import_start_time', current_time( 'timestamp' ) );
    }
}

/**
 * Gibt eine lesbare Status-Nachricht zurück
 */
function csv_import_get_status_message( string $status, int $processed, int $total ): string {
    switch ( $status ) {
        case 'starting':
            return 'Import wird vorbereitet...';
        case 'processing':
            $percent = $total > 0 ? round(($processed / $total) * 100, 1) : 0;
            return "Verarbeite Eintrag {$processed} von {$total} ({$percent}%)...";
        case 'completed':
            return "Import erfolgreich abgeschlossen. {$processed} Einträge verarbeitet.";
        case 'completed_with_errors':
            return "Import abgeschlossen mit Fehlern. {$processed} von {$total} Einträgen verarbeitet.";
        case 'failed':
            return 'Import fehlgeschlagen.';
        case 'timeout':
            return 'Import-Timeout - Prozess wurde automatisch zurückgesetzt.';
        case 'idle':
        default:
            return 'Kein aktiver Import';
    }
}

/**
 * Löscht den Import-Fortschritt
 */
function csv_import_clear_progress(): void {
    delete_option( 'csv_import_progress' );
    delete_option( 'csv_import_start_time' );
    delete_option( 'csv_import_running_lock' );
}

/**
 * Aktualisiert Import-Statistiken
 */
function csv_import_update_import_stats( array $result, string $source ): void {
    $total_imported = get_option( 'csv_import_total_imported', 0 );
    $total_imported += $result['processed'];
    
    update_option( 'csv_import_total_imported', $total_imported );
    update_option( 'csv_import_last_run', current_time( 'mysql' ) );
    update_option( 'csv_import_last_count', $result['processed'] );
    update_option( 'csv_import_last_source', ucfirst( $source ) );
    
    if ( $result['total'] > 0 ) {
        $success_rate = round( ( $result['processed'] / $result['total'] ) * 100, 1 );
        update_option( 'csv_import_last_success_rate', $success_rate );
    }
    
    $start_time = get_option( 'csv_import_start_time', time() );
    $processing_time = time() - $start_time;
    if ( $result['processed'] > 0 ) {
        $time_per_item = $processing_time / $result['processed'];
        update_option( 'csv_import_avg_processing_time', round( $time_per_item, 2 ) );
    }
}

// ===================================================================
// ADMIN UI FUNKTIONEN
// ===================================================================

/**
 * Holt Informationen zum Template-Post
 */
function csv_import_get_template_info(): string {
    $id = get_option( 'csv_import_template_id' );
    if ( ! $id || $id == 0 ) {
        return '<span style="color:red;">Nicht gesetzt</span>';
    }
    
    $post = get_post( $id );
    if ( ! $post ) {
        return '<span style="color:red;">Template mit ID ' . $id . ' nicht gefunden</span>';
    }
    
    $edit_link = get_edit_post_link( $id );
    $view_link = get_permalink( $id );
    
    return sprintf(
        '<strong>"%s"</strong> (ID: %d)<br>' .
        '<small>Status: %s | Typ: %s</small><br>' .
        '<a href="%s" target="_blank" class="button button-small">Bearbeiten</a> ' .
        '<a href="%s" target="_blank" class="button button-small">Ansehen</a>',
        esc_html( $post->post_title ),
        $id,
        esc_html( $post->post_status ),
        esc_html( $post->post_type ),
        esc_url( $edit_link ),
        esc_url( $view_link )
    );
}

/**
 * Prüft den Status einer Datei oder eines Verzeichnisses
 */
function csv_import_get_file_status( string $path, bool $is_dir = false ): string {
    $full_path = $path;
    
    if ( ! csv_import_path_is_absolute( $path ) ) {
        $full_path = ABSPATH . ltrim( $path, '/' );
    }
    
    if ( $is_dir ) {
        if ( is_dir( $full_path ) && is_readable( $full_path ) ) {
            $file_count = count( glob( $full_path . '/*' ) );
            return '<span style="color:green;">Ordner existiert (' . $file_count . ' Dateien)</span>';
        }
        return '<span style="color:red;">Ordner nicht gefunden oder nicht lesbar</span>';
    } else {
        if ( file_exists( $full_path ) && is_readable( $full_path ) ) {
            $size = filesize( $full_path );
            $modified = date( 'Y-m-d H:i:s', filemtime( $full_path ) );
            return sprintf(
                '<span style="color:green;">Datei gefunden (%s, geändert: %s)</span>',
                size_format( $size ),
                $modified
            );
        }
        return '<span style="color:red;">Datei nicht gefunden: ' . esc_html( basename( $path ) ) . '</span>';
    }
}

// ===================================================================
// HILFSFUNKTIONEN
// ===================================================================

/**
 * Normalisiert Zeilenumbrüche
 */
function csv_import_normalize_line_endings( string $content ): string {
    return str_replace( [ "\r\n", "\r" ], "\n", $content );
}

/**
 * Prüft ob ein Pfad absolut ist
 */
function csv_import_path_is_absolute( string $path ): bool {
    return isset( $path[0] ) && $path[0] === '/' ||
           isset( $path[1] ) && $path[1] === ':';
}

/**
 * Validiert erforderliche CSV-Spalten
 */
function csv_import_validate_required_columns( array $csv_headers, array $required_columns ): array {
    $missing = [];
    
    foreach ( $required_columns as $required ) {
        if ( ! in_array( $required, $csv_headers ) ) {
            $missing[] = $required;
        }
    }
    
    return [
        'valid' => empty( $missing ),
        'missing' => $missing,
        'message' => empty( $missing ) 
            ? 'Alle erforderlichen Spalten vorhanden' 
            : 'Fehlende Spalten: ' . implode( ', ', $missing )
    ];
}

/**Sanitized einen Post-Titel
 */
function csv_import_sanitize_title( string $title ): string {
    $title = trim( $title );
    $title = wp_strip_all_tags( $title );
    $title = html_entity_decode( $title, ENT_QUOTES, 'UTF-8' );
    return $title;
}

/**
 * Generiert einen eindeutigen Post-Slug
 */
function csv_import_generate_unique_slug( string $title, string $post_type = 'post' ): string {
    $slug = sanitize_title( $title );
    
    if ( empty( $slug ) ) {
        $slug = 'csv-import-post-' . uniqid();
    }
    
    $original_slug = $slug;
    $counter = 1;
    
    while ( get_page_by_path( $slug, OBJECT, $post_type ) ) {
        $slug = $original_slug . '-' . $counter;
        $counter++;
    }
    
    return $slug;
}

/**
 * Human-readable time diff
 */
function csv_import_human_time_diff( $from, $to = null ) {
    if ( $to === null ) {
        $to = time();
    }
    
    $diff = abs( $to - $from );
    
    if ( $diff < 60 ) {
        return $diff . ' Sekunden';
    } elseif ( $diff < 3600 ) {
        return round( $diff / 60 ) . ' Minuten';
    } elseif ( $diff < 86400 ) {
        return round( $diff / 3600 ) . ' Stunden';
    } else {
        return round( $diff / 86400 ) . ' Tage';
    }
}

// ===================================================================
// BACKUP & CLEANUP FUNKTIONEN
// ===================================================================

/**
 * Erstellt ein Backup vor dem Import
 */
function csv_import_create_backup( string $session_id ): bool {
    if ( class_exists( 'CSV_Import_Backup_Manager' ) && method_exists( 'CSV_Import_Backup_Manager', 'create_backup' ) ) {
        return CSV_Import_Backup_Manager::create_backup( $session_id );
    }
    
    global $wpdb;
    
    $backup_info = [
        'session_id' => $session_id,
        'timestamp' => current_time( 'mysql' ),
        'pre_import_post_count' => wp_count_posts()->publish,
        'user_id' => get_current_user_id(),
        'memory_usage' => memory_get_usage( true ),
        'mysql_version' => $wpdb->db_version(),
        'wp_version' => get_bloginfo( 'version' ),
        'php_version' => PHP_VERSION,
        'server_info' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown'
    ];
    
    update_option( 'csv_import_backup_' . $session_id, $backup_info );
    csv_import_log( 'info', "Backup für Session {$session_id} erstellt" );
    
    return true;
}

/**
 * Bereinigt temporäre Dateien
 */
function csv_import_cleanup_temp_files( int $older_than_hours = 24 ): void {
    $upload_dir = wp_upload_dir();
    $temp_dir = $upload_dir['basedir'] . '/csv-import-temp/';
    $cutoff_time = time() - ( $older_than_hours * 3600 );
    $deleted_files = 0;
    
    if ( is_dir( $temp_dir ) ) {
        $files = glob( $temp_dir . '*' );
        foreach ( $files as $file ) {
            if ( is_file( $file ) && filemtime( $file ) < $cutoff_time ) {
                if ( @unlink( $file ) ) {
                    $deleted_files++;
                }
            }
        }
        
        if ( is_dir_empty( $temp_dir ) ) {
            @rmdir( $temp_dir );
        }
    }
    
    if ( $deleted_files > 0 ) {
        csv_import_log( 'info', "Bereinigung: {$deleted_files} temporäre Dateien gelöscht" );
    }
}

/**
 * Prüft ob ein Verzeichnis leer ist
 */
function is_dir_empty( string $dir ): bool {
    if ( ! is_readable( $dir ) ) {
        return false;
    }
    
    $handle = opendir( $dir );
    while ( false !== ( $entry = readdir( $handle ) ) ) {
        if ( $entry != '.' && $entry != '..' ) {
            closedir( $handle );
            return false;
        }
    }
    closedir( $handle );
    return true;
}

// ===================================================================
// LOGGING & FEHLERBEHANDLUNG
// ===================================================================

/**
 * Verfolgt Fehlerstatistiken
 */
function csv_import_track_error_stats( string $level, string $message ): void {
    $stats = get_option( 'csv_import_error_stats', [
        'total_errors'        => 0,
        'total_real_errors'   => 0,
        'errors_by_level'     => [],
        'recent_errors'       => [],
        'error_trends'        => [],
        'critical_errors_24h' => 0,
        'warning_errors_24h'  => 0
    ] );

    $stats['total_errors']++;

    if ( in_array( $level, ['critical', 'error', 'warning'] ) ) {
        $stats['total_real_errors'] = ( $stats['total_real_errors'] ?? 0 ) + 1;
        
        if ( $level === 'critical' || $level === 'error' ) {
            $stats['critical_errors_24h'] = ( $stats['critical_errors_24h'] ?? 0 ) + 1;
        } elseif ( $level === 'warning' ) {
            $stats['warning_errors_24h'] = ( $stats['warning_errors_24h'] ?? 0 ) + 1;
        }
    }

    $stats['errors_by_level'][ $level ] = ( $stats['errors_by_level'][ $level ] ?? 0 ) + 1;

    $stats['recent_errors'][] = [
        'level'   => $level,
        'message' => mb_substr( $message, 0, 200 ),
        'time'    => current_time( 'mysql' ),
        'user_id' => get_current_user_id(),
        'ip'      => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ];
    $stats['recent_errors'] = array_slice( $stats['recent_errors'], -50 );

    if ( in_array( $level, ['critical', 'error', 'warning'] ) ) {
        $today = current_time( 'Y-m-d' );
        $stats['error_trends'][ $today ] = ( $stats['error_trends'][ $today ] ?? 0 ) + 1;

        if ( count( $stats['error_trends'] ) > 30 ) {
            $stats['error_trends'] = array_slice( $stats['error_trends'], -30, null, true );
        }
    }

    update_option( 'csv_import_error_stats', $stats );
}

/**
 * Loggt Import-Aktivitäten
 */
function csv_import_log( string $level, string $message, array $context = [] ): void {
    $context = array_merge( $context, [
        'timestamp' => current_time( 'mysql' ),
        'user_id' => get_current_user_id(),
        'memory_usage' => memory_get_usage( true ),
        'peak_memory' => memory_get_peak_usage( true ),
        'php_version' => PHP_VERSION,
        'plugin_version' => defined('CSV_IMPORT_PRO_VERSION') ? CSV_IMPORT_PRO_VERSION : 'unknown'
    ] );
    
    if ( class_exists( 'CSV_Import_Error_Handler' ) ) {
        CSV_Import_Error_Handler::handle( $level, $message, $context );
    } else {
        error_log( sprintf( '[CSV Import Pro %s] %s', strtoupper( $level ), $message ) );
    }
    
    csv_import_track_error_stats( $level, $message );
    
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        csv_import_debug_log( $message, $context );
    }
    
    if ( $level === 'critical' ) {
        csv_import_send_critical_error_notification( $message, $context );
    }
}

/**
 * Debug-Logging in separate Datei
 */
function csv_import_debug_log( string $message, array $context = [] ): void {
    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/csv-import-debug.log';
    
    if ( file_exists( $log_file ) && filesize( $log_file ) > 10485760 ) {
        @rename( $log_file, $upload_dir['basedir'] . '/csv-import-debug.log.old' );
    }
    
    $log_entry = sprintf(
        "[%s] [%s] %s %s\n",
        current_time( 'Y-m-d H:i:s' ),
        strtoupper( WP_DEBUG ? 'DEBUG' : 'INFO' ),
        $message,
        ! empty( $context ) ? wp_json_encode( $context, JSON_PRETTY_PRINT ) : ''
    );
    
    @file_put_contents( $log_file, $log_entry, FILE_APPEND | LOCK_EX );
}

/**
 * Sendet kritische Fehler-Benachrichtigungen
 */
function csv_import_send_critical_error_notification( string $message, array $context = [] ): void {
    $last_critical = get_transient( 'csv_import_last_critical_notification' );
    if ( $last_critical ) {
        return;
    }
    
    set_transient( 'csv_import_last_critical_notification', time(), 3600 );
    
    $admin_email = get_option( 'admin_email' );
    $site_name = get_bloginfo( 'name' );
    
    $subject = "[$site_name] CSV Import Pro - Kritischer Fehler";
    $body = "Ein kritischer Fehler ist im CSV Import Pro Plugin aufgetreten:\n\n";
    $body .= "Fehler: $message\n\n";
    $body .= "Zeit: " . current_time( 'Y-m-d H:i:s' ) . "\n";
    $body .= "Benutzer: " . ( $context['user_id'] ?? 'unbekannt' ) . "\n";
    $body .= "Speicherverbrauch: " . size_format( $context['memory_usage'] ?? 0 ) . "\n\n";
    $body .= "Weitere Details finden Sie in den Plugin-Logs.\n\n";
    $body .= "-- CSV Import Pro System";
    
    wp_mail( $admin_email, $subject, $body );
}

// ===================================================================
// MAINTENANCE
// ===================================================================

/**
 * Tägliche Wartungsaufgaben
 */
function csv_import_daily_maintenance(): void {
    csv_import_log( 'debug', 'Starte tägliche Wartung' );
    
    $stats = csv_import_get_error_stats();
    
    $cutoff_date = date( 'Y-m-d', strtotime( '-30 days' ) );
    
    if ( isset( $stats['error_trends'] ) ) {
        $cleaned = 0;
        foreach ( $stats['error_trends'] as $date => $count ) {
            if ( $date < $cutoff_date ) {
                unset( $stats['error_trends'][ $date ] );
                $cleaned++;
            }
        }
        csv_import_log( 'debug', "Bereinigt: {$cleaned} alte Fehler-Trend-Einträge" );
    }
    
    if ( isset( $stats['recent_errors'] ) && count( $stats['recent_errors'] ) > 50 ) {
        $stats['recent_errors'] = array_slice( $stats['recent_errors'], -50 );
    }
    
    $stats['critical_errors_24h'] = 0;
    $stats['warning_errors_24h'] = 0;
    
    update_option( 'csv_import_error_stats', $stats );
    
    csv_import_cleanup_temp_files( 24 );
    
    global $wpdb;
    $old_backups = $wpdb->get_results(
        "SELECT option_name FROM {$wpdb->options} 
         WHERE option_name LIKE 'csv_import_backup_%' 
         AND option_value LIKE '%\"timestamp\":\"%' 
         LIMIT 100"
    );
    
    $deleted_backups = 0;
    foreach ( $old_backups as $backup ) {
        $backup_data = get_option( $backup->option_name );
        if ( isset( $backup_data['timestamp'] ) ) {
            $backup_time = strtotime( $backup_data['timestamp'] );
            if ( $backup_time < strtotime( '-7 days' ) ) {
                delete_option( $backup->option_name );
                $deleted_backups++;
            }
        }
    }
    
    if ( $deleted_backups > 0 ) {
        csv_import_log( 'debug', "Bereinigt: {$deleted_backups} alte Backup-Einträge" );
    }
    
    csv_import_cleanup_dead_processes();
    
    delete_transient( 'csv_import_progress' );
    
    $memory_limit = csv_import_convert_to_bytes( ini_get( 'memory_limit' ) );
    $disk_free = disk_free_space( ABSPATH );
    
    update_option( 'csv_import_system_metrics', [
        'memory_limit' => $memory_limit,
        'disk_free' => $disk_free,
        'last_maintenance' => current_time( 'mysql' ),
        'php_version' => PHP_VERSION,
        'wp_version' => get_bloginfo( 'version' )
    ] );
    
    csv_import_log( 'debug', 'Tägliche Wartung abgeschlossen', [
        'deleted_backups' => $deleted_backups,
        'memory_limit' => size_format( $memory_limit ),
        'disk_free' => size_format( $disk_free )
    ] );
}

/**
 * Wöchentliche Wartungsaufgaben
 */
function csv_import_weekly_maintenance(): void {
    csv_import_log( 'debug', 'Starte wöchentliche Wartung' );
    
    csv_import_cleanup_temp_files( 168 );
    
    $health = csv_import_system_health_check();
    $health_issues = array_filter( $health, function( $value, $key ) {
        return $value === false && $key !== 'import_locks' && $key !== 'stuck_processes';
    }, ARRAY_FILTER_USE_BOTH );
    
    if ( ! empty( $health_issues ) ) {
        csv_import_log( 'warning', 'System-Health-Probleme erkannt', [
            'issues' => array_keys( $health_issues )
        ] );
    }
    
    csv_import_log( 'debug', 'Wöchentliche Wartung abgeschlossen' );
}

if ( ! wp_next_scheduled( 'csv_import_daily_maintenance' ) ) {
    wp_schedule_event( time(), 'daily', 'csv_import_daily_maintenance' );
}

if ( ! wp_next_scheduled( 'csv_import_weekly_maintenance' ) ) {
    wp_schedule_event( time(), 'weekly', 'csv_import_weekly_maintenance' );
}

add_action( 'csv_import_daily_maintenance', 'csv_import_daily_maintenance' );
add_action( 'csv_import_weekly_maintenance', 'csv_import_weekly_maintenance' );

// ===================================================================
// SCHEDULER-AKTIVIERUNGSSYSTEM
// ===================================================================

/**
 * Prüft ob der Scheduler aktiviert ist
 */
function csv_import_is_scheduler_enabled(): bool {
    return get_option('csv_import_scheduler_enabled', false);
}

/**
 * Aktiviert den Scheduler
 */
function csv_import_enable_scheduler(): array {
    if (!current_user_can('manage_options')) {
        return [
            'success' => false,
            'message' => 'Keine Berechtigung für Scheduler-Aktivierung'
        ];
    }

    $health_check = csv_import_system_health_check();
    $critical_issues = array_filter($health_check, function($status) {
        return $status === false;
    });

    if (!empty($critical_issues)) {
        $issue_names = array_map(function($key) {
            $names = [
                'memory_ok' => 'Memory Limit',
                'time_ok' => 'Execution Time', 
                'disk_space_ok' => 'Disk Space',
                'permissions_ok' => 'File Permissions',
                'php_version_ok' => 'PHP Version',
                'curl_ok' => 'cURL Extension',
                'wp_version_ok' => 'WordPress Version',
                'import_locks_ok' => 'Import Locks',
                'no_stuck_processes' => 'Hängende Prozesse'
            ];
            return $names[$key] ?? $key;
        }, array_keys($critical_issues));
        
        return [
            'success' => false,
            'message' => 'System-Probleme gefunden: ' . implode(', ', $issue_names) . '. Bitte beheben Sie diese Probleme vor der Aktivierung.'
        ];
    }

    if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
        return [
            'success' => false,
            'message' => 'WordPress Cron ist deaktiviert (DISABLE_WP_CRON = true). Für automatische Imports ist ein funktionierendes Cron-System erforderlich.'
        ];
    }

    $required_functions = [
        'csv_import_get_config',
        'csv_import_validate_config',
        'csv_import_start_import'
    ];
    
    $missing_functions = array_filter($required_functions, function($func) {
        return !function_exists($func);
    });
    
    if (!empty($missing_functions)) {
        return [
            'success' => false,
            'message' => 'Erforderliche Plugin-Funktionen nicht verfügbar: ' . implode(', ', $missing_functions) . '. Plugin möglicherweise beschädigt.'
        ];
    }

    if (!class_exists('CSV_Import_Scheduler')) {
        return [
            'success' => false,
            'message' => 'Scheduler-Klasse nicht verfügbar. Plugin möglicherweise unvollständig installiert.'
        ];
    }

    update_option('csv_import_scheduler_enabled', true);
    update_option('csv_import_scheduler_activated_at', current_time('mysql'));
    update_option('csv_import_scheduler_activated_by', get_current_user_id());

    csv_import_log('info', 'Scheduler wurde aktiviert', [
        'user_id' => get_current_user_id(),
        'user_login' => wp_get_current_user()->user_login,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'timestamp' => current_time('mysql')
    ]);

    csv_import_log_scheduler_audit('scheduler_enabled', get_current_user_id(), true, [
        'method' => 'manual_activation',
        'health_check_passed' => true
    ]);

    return [
        'success' => true,
        'message' => 'Scheduler erfolgreich aktiviert! Automatische CSV-Imports sind jetzt verfügbar.'
    ];
}

/**
 * Deaktiviert den Scheduler
 */
function csv_import_disable_scheduler(): array {
    if (!current_user_can('manage_options')) {
        return [
            'success' => false,
            'message' => 'Keine Berechtigung für Scheduler-Deaktivierung'
        ];
    }

    $stopped_imports = 0;
    if (class_exists('CSV_Import_Scheduler')) {
        if (method_exists('CSV_Import_Scheduler', 'is_scheduled') && CSV_Import_Scheduler::is_scheduled()) {
            $stopped_imports = 1;
        }
        
        CSV_Import_Scheduler::unschedule_all();
    }

    update_option('csv_import_scheduler_enabled', false);
    delete_option('csv_import_scheduler_activated_at');
    delete_option('csv_import_scheduler_activated_by');
    delete_option('csv_import_scheduled_frequency');
    delete_option('csv_import_scheduled_source');
    delete_option('csv_import_scheduled_options');
    delete_option('csv_import_scheduled_start');
    delete_option('csv_import_scheduled_created');

    csv_import_log('info', 'Scheduler wurde deaktiviert', [
        'user_id' => get_current_user_id(),
        'stopped_imports' => $stopped_imports,
        'timestamp' => current_time('mysql')
    ]);

    csv_import_log_scheduler_audit('scheduler_disabled', get_current_user_id(), true, [
        'method' => 'manual_deactivation',
        'stopped_imports' => $stopped_imports
    ]);

    $message = 'Scheduler deaktiviert';
    if ($stopped_imports > 0) {
        $message .= " und {$stopped_imports} geplante Imports gestoppt";
    }
    $message .= '. Automatische Imports sind nicht mehr verfügbar.';

    return [
        'success' => true,
        'message' => $message
    ];
}

/**
 * Audit-Log für Scheduler-Aktivitäten
 */
function csv_import_log_scheduler_audit(string $action, int $user_id, bool $success = true, array $details = []): void {
    $user = get_user_by('id', $user_id);
    
    $audit_entry = [
        'timestamp' => current_time('mysql'),
        'action' => $action,
        'user_id' => $user_id,
        'user_login' => $user ? $user->user_login : 'unknown',
        'user_roles' => $user ? $user->roles : [],
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'success' => $success,
        'details' => $details
    ];
    
    $audit_log = get_option('csv_import_scheduler_audit_log', []);
    $audit_log[] = $audit_entry;
    
    if (count($audit_log) > 100) {
        $audit_log = array_slice($audit_log, -100);
    }
    
    update_option('csv_import_scheduler_audit_log', $audit_log);
}

/**
 * Prüft Scheduler-Berechtigungen
 */
function csv_import_can_manage_scheduler(int $user_id = 0): bool {
    if ($user_id === 0) {
        $user_id = get_current_user_id();
    }
    
    if (!user_can($user_id, 'manage_options')) {
        return false;
    }
    
    $user = get_user_by('id', $user_id);
    if (!$user) {
        return false;
    }
    
    if (is_multisite() && !is_super_admin($user_id)) {
        return false;
    }
    
    return true;
}

// ===================================================================
// BREAKDANCE REPARATUR-TOOL
// ===================================================================

/**
 * Repariert Breakdance-Seiten
 */
function csv_import_repair_breakdance_posts( int $post_id = 0 ): array {
    $repaired = 0;
    $errors = 0;
    $post_ids = [];
    
    if ( $post_id > 0 ) {
        $post_ids = [ $post_id ];
    } else {
        global $wpdb;
        $post_ids = $wpdb->get_col(
            "SELECT post_id FROM {$wpdb->postmeta} 
             WHERE meta_key = '_csv_import_session' 
             GROUP BY post_id"
        );
    }
    
    if ( empty( $post_ids ) ) {
        return [
            'success' => false,
            'message' => 'Keine importierten Posts gefunden',
            'repaired' => 0,
            'errors' => 0
        ];
    }
    
    $config = csv_import_get_config();
    if ( empty( $config['template_id'] ) || $config['page_builder'] !== 'breakdance' ) {
        return [
            'success' => false,
            'message' => 'Breakdance nicht als Page Builder konfiguriert',
            'repaired' => 0,
            'errors' => 0
        ];
    }
    
    $template_post = get_post( $config['template_id'] );
    if ( ! $template_post ) {
        return [
            'success' => false,
            'message' => 'Template-Post nicht gefunden (ID: ' . $config['template_id'] . ')',
            'repaired' => 0,
            'errors' => 0
        ];
    }
    
    $template_meta = get_post_meta( $template_post->ID );
    
    foreach ( $post_ids as $current_post_id ) {
        try {
            $post = get_post( $current_post_id );
            if ( ! $post ) {
                $errors++;
                continue;
            }
            
            $needs_repair = false;
            $has_breakdance_data = get_post_meta( $current_post_id, '_breakdance_data', true );
            $has_breakdance_editable = get_post_meta( $current_post_id, '_breakdance_is_editable', true );
            
            if ( empty( $has_breakdance_data ) && empty( $has_breakdance_editable ) ) {
                $needs_repair = true;
            }
            
            if ( empty( $post->post_content ) || json_decode( $post->post_content, true ) === null ) {
                $needs_repair = true;
            }
            
            if ( ! $needs_repair ) {
                continue;
            }
            
            update_post_meta( $current_post_id, '_breakdance_data', '1' );
            update_post_meta( $current_post_id, '_breakdance_is_editable', '1' );
            update_post_meta( $current_post_id, 'breakdance_data', '1' );
            
            $breakdance_meta_keys = [
                '_breakdance_tree_id',
                '_breakdance_revision_id',
                '_breakdance_settings',
                '_breakdance_custom_css',
                '_breakdance_custom_js'
            ];
            
            foreach ( $breakdance_meta_keys as $meta_key ) {
                if ( isset( $template_meta[$meta_key][0] ) ) {
                    $meta_value = maybe_unserialize( $template_meta[$meta_key][0] );
                    update_post_meta( $current_post_id, $meta_key, $meta_value );
                }
            }
            
            if ( empty( $post->post_content ) || json_decode( $post->post_content, true ) === null ) {
                wp_update_post( [
                    'ID' => $current_post_id,
                    'post_content' => $template_post->post_content
                ] );
            }
            
            $repaired++;
            
            csv_import_log( 'info', "Breakdance-Post repariert: {$post->post_title} (ID: {$current_post_id})" );
            
        } catch ( Exception $e ) {
            $errors++;
            csv_import_log( 'error', "Fehler beim Reparieren von Post {$current_post_id}: " . $e->getMessage() );
        }
    }
    
    return [
        'success' => $errors === 0,
        'message' => "{$repaired} Posts repariert, {$errors} Fehler",
        'repaired' => $repaired,
        'errors' => $errors,
        'total_checked' => count( $post_ids )
    ];
}

/**
 * Admin-Notice für Breakdance-Reparatur
 */
function csv_import_breakdance_repair_notice() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    
    if ( ! isset( $_GET['page'] ) || strpos( $_GET['page'], 'csv-import' ) === false ) {
        return;
    }
    
    $config = csv_import_get_config();
    if ( $config['page_builder'] !== 'breakdance' ) {
        return;
    }
    
    global $wpdb;
    $posts_needing_repair = $wpdb->get_var(
        "SELECT COUNT(DISTINCT p.ID) 
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
         LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_breakdance_data'
         WHERE pm.meta_key = '_csv_import_session'
         AND pm2.meta_value IS NULL"
    );
    
    if ( $posts_needing_repair > 0 ) {
        ?>
        <div class="notice notice-warning">
            <p>
                <strong>Breakdance-Reparatur verfügbar:</strong> 
                Es wurden <?php echo esc_html( $posts_needing_repair ); ?> importierte Posts gefunden, 
                die möglicherweise nicht korrekt als Breakdance-Seiten konfiguriert sind.
            </p>
            <p>
                <button type="button" class="button button-primary" id="csv-repair-breakdance-btn">
                    Jetzt alle reparieren
                </button>
                <span id="csv-repair-result" style="margin-left: 15px;"></span>
            </p>
        </div>
        
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#csv-repair-breakdance-btn').on('click', function() {
                var button = $(this);
                var resultSpan = $('#csv-repair-result');
                
                button.prop('disabled', true);
                button.text('Repariere...');
                resultSpan.html('');
                
                $.post(ajaxurl, {
                    action: 'csv_repair_breakdance',
                    nonce: '<?php echo wp_create_nonce( 'csv_import_ajax' ); ?>',
                    post_id: 0
                }, function(response) {
                    if (response.success) {
                        resultSpan.html('<span style="color: green;">✅ ' + response.data.message + '</span>');
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        var errorMsg = response.data && response.data.message ? response.data.message : 'Fehler bei der Reparatur';
                        resultSpan.html('<span style="color: red;">❌ ' + errorMsg + '</span>');
                        button.prop('disabled', false);
                        button.text('Erneut versuchen');
                    }
                }).fail(function(jqXHR, textStatus, errorThrown) {
                    console.error('AJAX Fehler:', textStatus, errorThrown);
                    resultSpan.html('<span style="color: red;">❌ Serverfehler: ' + textStatus + '</span>');
                    button.prop('disabled', false);
                    button.text('Erneut versuchen');
                });
            });
        });
        </script>
        <?php
    }
}
add_action( 'admin_notices', 'csv_import_breakdance_repair_notice' );

// ===================================================================
// FINAL INITIALIZATION
// ===================================================================

csv_import_log( 'debug', 'CSV Import Pro Core Functions geladen - Version 5.3-final (Parse-Error behoben)' );
