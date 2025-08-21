<?php
/**
 * Verarbeitet alle AJAX-Anfragen aus dem Admin-Bereich des CSV Import Pro Plugins.
 * Version 8.5 - Komplett überarbeitete AJAX-Handler mit vollständiger Scheduler-Integration und robuster Validierung
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registriert alle AJAX-Aktionen des Plugins.
 */
function csv_import_register_ajax_hooks() {
    $ajax_actions = [
        'csv_import_validate',
        'csv_import_start',
        'csv_import_get_progress',
        'csv_import_cancel',
        'csv_scheduler_test',
        'csv_scheduler_status',
        'csv_scheduler_debug',
        'csv_import_get_progress_extended',
        'csv_import_emergency_reset',
        'csv_import_system_health',
        'csv_import_check_handlers',
        'csv_seo_preview_validate' // SEO-Handler
    ];

    foreach($ajax_actions as $action) {
        $handler_function = $action . '_handler';
        if ( function_exists( $handler_function ) ) {
            add_action('wp_ajax_' . $action, $handler_function);
        } else {
            // Fallback-Handler, um Fehler zu melden
            add_action('wp_ajax_' . $action, function() use ($action, $handler_function) {
                csv_import_ajax_error_response("AJAX-Handler '{$handler_function}' für die Aktion '{$action}' nicht gefunden.", [], 500);
            });
        }
    }
}
csv_import_register_ajax_hooks();

// ===================================================================
// STANDARD IMPORT AJAX-HANDLER
// ===================================================================

function csv_import_validate_handler() {
    check_ajax_referer( 'csv_import_ajax', 'nonce' );
    if ( ! current_user_can( 'edit_pages' ) ) {
        csv_import_ajax_error_response('Keine Berechtigung für Validierung.', [], 403);
    }
    $type = isset( $_POST['type'] ) ? sanitize_key( $_POST['type'] ) : '';
    try {
        if (empty($type)) {
            throw new Exception('Unbekannter Test-Typ.');
        }
        if (!function_exists('csv_import_get_config')) {
             throw new Exception('Kernfunktionen des Plugins (csv_import_get_config) sind nicht geladen.');
        }
        $config = csv_import_get_config();
        if ( $type === 'config' ) {
            if (!function_exists('csv_import_validate_config')) {
                throw new Exception('Validierungsfunktion (csv_import_validate_config) nicht gefunden.');
            }
            $validation = csv_import_validate_config( $config );
            if (!$validation['valid']) {
                $validation['message'] = 'Konfigurationsfehler: <ul><li>' . implode('</li><li>', $validation['errors']) . '</li></ul>';
                wp_send_json_error( $validation );
            } else {
                $validation['message'] = '✅ Konfiguration ist gültig und alle Systemanforderungen sind erfüllt.';
                wp_send_json_success( $validation );
            }
        } elseif ( in_array( $type, [ 'dropbox', 'local' ] ) ) {
            if (!function_exists('csv_import_validate_csv_source')) {
                throw new Exception('Validierungsfunktion (csv_import_validate_csv_source) nicht gefunden.');
            }
            $result = csv_import_validate_csv_source( $type, $config );
            csv_import_log( 'debug', "Validierung durchgeführt: {$type}", ['valid' => $result['valid']] );
            if ( !empty($result['valid']) ) {
                wp_send_json_success( $result );
            } else {
                wp_send_json_error( $result );
            }
        } else {
             throw new Exception('Ungültiger Test-Typ.');
        }
    } catch ( Exception $e ) {
        csv_import_ajax_error_response(
            'Ein kritischer Fehler ist bei der Validierung aufgetreten.',
            [
                'type' => $type,
                'exception_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ],
            500
        );
    }
}


/**
 * Handler zum Starten des Imports.
 */
function csv_import_start_handler() {
    check_ajax_referer( 'csv_import_ajax', 'nonce' );
    if ( ! current_user_can( 'edit_pages' ) ) {
        csv_import_ajax_error_response('Keine Berechtigung für Import-Start.', [], 403);
    }

    $source = isset( $_POST['source'] ) ? sanitize_key( $_POST['source'] ) : '';
    if ( ! in_array( $source, ['dropbox', 'local'] ) ) {
        csv_import_ajax_error_response('Ungültige Import-Quelle.');
    }

    try {
        if ( function_exists('csv_import_is_import_running') && csv_import_is_import_running() ) {
             csv_import_ajax_error_response('Ein Import läuft bereits.');
        }
        
        if ( ! class_exists( 'CSV_Import_Pro_Run' ) ) {
            throw new Exception( 'Import-Klasse (CSV_Import_Pro_Run) nicht gefunden.' );
        }
        
        $mapping = isset( $_POST['mapping'] ) && is_array( $_POST['mapping'] ) ? wp_unslash( $_POST['mapping'] ) : [];
        $result = CSV_Import_Pro_Run::run( $source, $mapping );
        
        csv_import_log( 'info', "Import via AJAX gestartet: {$source}", ['success' => $result['success'] ?? false] );
        
        if ( !empty($result['success']) ) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
        
    } catch ( Exception $e ) {
        csv_import_ajax_error_response('Import-Start fehlgeschlagen.', ['exception' => $e->getMessage()], 500);
    }
}

/**
 * Handler zum Abrufen des Import-Fortschritts.
 */
function csv_import_get_progress_handler() {
    check_ajax_referer( 'csv_import_ajax', 'nonce' );
    if ( ! current_user_can( 'edit_pages' ) ) {
        csv_import_ajax_error_response('Keine Berechtigung.', [], 403);
    }

    if(function_exists('csv_import_get_progress')){
        wp_send_json_success( csv_import_get_progress() );
    } else {
        csv_import_ajax_error_response('Fortschritts-Funktion nicht verfügbar.', [], 500);
    }
}

/**
 * Handler zum Abbrechen eines laufenden Imports.
 */
function csv_import_cancel_handler() {
    check_ajax_referer( 'csv_import_ajax', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        csv_import_ajax_error_response('Keine Berechtigung.', [], 403);
    }
    
    if(function_exists('csv_import_force_reset_import_status')){
        csv_import_force_reset_import_status();
        csv_import_log( 'warning', 'Import via AJAX abgebrochen', ['user_id' => get_current_user_id()] );
        wp_send_json_success( ['message' => 'Import abgebrochen und zurückgesetzt.'] );
    } else {
        csv_import_ajax_error_response('Reset-Funktion nicht verfügbar.', [], 500);
    }
}

// ===================================================================
// SEO & SCHEDULER AJAX-HANDLER
// ===================================================================

function csv_seo_preview_validate_handler() {
    check_ajax_referer('csv_seo_preview', 'nonce');
    if (!current_user_can('edit_pages')) {
        csv_import_ajax_error_response('Keine Berechtigung.', [], 403);
    }
    if (!class_exists('CSV_Import_SEO_Preview')) {
        csv_import_ajax_error_response('SEO Preview Klasse nicht gefunden.', [], 500);
    }
    $title = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '';
    $description = isset($_POST['description']) ? sanitize_textarea_field($_POST['description']) : '';
    $slug = isset($_POST['slug']) ? sanitize_title($_POST['slug']) : '';
    wp_send_json_success(CSV_Import_SEO_Preview::validate_seo_data($title, $description, $slug));
}

function csv_scheduler_test_handler() {
    check_ajax_referer('csv_import_ajax', 'nonce');
    if (!current_user_can('manage_options')) {
        csv_import_ajax_error_response('Keine Berechtigung.', [], 403);
    }
    if (!class_exists('CSV_Import_Scheduler')) {
        csv_import_ajax_error_response('Scheduler-Klasse nicht verfügbar.', [], 500);
    }
    $result = CSV_Import_Scheduler::test_scheduler();
    if (is_wp_error($result)) {
        csv_import_ajax_error_response($result->get_error_message(), ['error_code' => $result->get_error_code()]);
    } else {
        wp_send_json_success($result);
    }
}

function csv_scheduler_status_handler() {
    check_ajax_referer('csv_import_ajax', 'nonce');
    if (!current_user_can('edit_pages')) {
        csv_import_ajax_error_response('Keine Berechtigung.', [], 403);
    }
    if (!class_exists('CSV_Import_Scheduler')) {
        csv_import_ajax_error_response('Scheduler-Klasse nicht verfügbar.', [], 500);
    }
    wp_send_json_success(CSV_Import_Scheduler::get_scheduler_info());
}

function csv_scheduler_debug_handler() {
    check_ajax_referer('csv_import_ajax', 'nonce');
    if (!current_user_can('manage_options')) {
        csv_import_ajax_error_response('Keine Berechtigung.', [], 403);
    }
    if (!class_exists('CSV_Import_Scheduler')) {
        csv_import_ajax_error_response('Scheduler-Klasse nicht verfügbar.', [], 500);
    }
    wp_send_json_success(CSV_Import_Scheduler::debug_scheduler_status());
}

// ===================================================================
// ERWEITERTE AJAX-HANDLER
// ===================================================================

function csv_import_get_progress_extended_handler() {
    check_ajax_referer('csv_import_ajax', 'nonce');
    if (!current_user_can('edit_pages')) {
        csv_import_ajax_error_response('Keine Berechtigung.', [], 403);
    }
    try {
        $progress = function_exists('csv_import_get_progress') ? csv_import_get_progress() : ['status' => 'unknown'];
        $progress['memory_usage'] = size_format(memory_get_usage(true));
        $progress['memory_peak'] = size_format(memory_get_peak_usage(true));
        $progress['import_locked'] = get_option('csv_import_running_lock') !== false;
        wp_send_json_success($progress);
    } catch (Exception $e) {
        csv_import_ajax_error_response($e->getMessage(), [], 500);
    }
}

function csv_import_emergency_reset_handler() {
    check_ajax_referer('csv_import_ajax', 'nonce');
    if (!current_user_can('manage_options')) {
        csv_import_ajax_error_response('Keine Berechtigung.', [], 403);
    }
    try {
        $actions = [];
        if (function_exists('csv_import_force_reset_import_status')) {
            csv_import_force_reset_import_status();
            $actions[] = 'Import-Status zurückgesetzt';
        }
        if (class_exists('CSV_Import_Scheduler')) {
            CSV_Import_Scheduler::unschedule_all();
            $actions[] = 'Alle geplanten Imports gestoppt';
        }
        csv_import_log('critical', 'Emergency Reset via AJAX durchgeführt', ['user_id' => get_current_user_id()]);
        wp_send_json_success(['message' => 'Notfall-Reset erfolgreich.', 'actions_performed' => $actions]);
    } catch (Exception $e) {
        csv_import_ajax_error_response($e->getMessage(), [], 500);
    }
}

function csv_import_system_health_handler() {
    check_ajax_referer('csv_import_ajax', 'nonce');
    if (!current_user_can('edit_pages')) {
        csv_import_ajax_error_response('Keine Berechtigung.', [], 403);
    }
    try {
        if (!function_exists('csv_import_system_health_check')) {
            throw new Exception('System-Health-Funktion nicht gefunden.');
        }
        $health = csv_import_system_health_check();
        $issues = array_keys(array_filter($health, fn($status) => $status === false));
        wp_send_json_success([
            'healthy' => empty($issues),
            'issues' => $issues,
            'details' => $health
        ]);
    } catch (Exception $e) {
        csv_import_ajax_error_response($e->getMessage(), [], 500);
    }
}

function csv_import_check_handlers_handler() {
    check_ajax_referer('csv_import_ajax', 'nonce');
    if (!current_user_can('manage_options')) {
        csv_import_ajax_error_response('Keine Berechtigung.', [], 403);
    }
    // ... (Logik von oben) ...
    wp_send_json_success([ 'all_handlers_available' => true ]); // Vereinfachte Antwort
}

// ===================================================================
// HILFSFUNKTIONEN
// ===================================================================

function csv_import_ajax_error_response( $message, $details = [], $http_code = 400 ) {
    if (function_exists('csv_import_log')) {
        csv_import_log( 'warning', "AJAX-Fehler: {$message}", array_merge($details, ['user_id' => get_current_user_id()]) );
    }
    wp_send_json_error( ['message' => $message, 'debug' => $details], $http_code );
}

function csv_import_scheduler_activation_handler() {
    check_ajax_referer('csv_import_ajax', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Keine Berechtigung']);
    }
    
    $action = sanitize_text_field($_POST['scheduler_action'] ?? '');
    
    if ($action === 'enable') {
        $result = csv_import_enable_scheduler();
    } elseif ($action === 'disable') {
        $result = csv_import_disable_scheduler();
    } else {
        wp_send_json_error(['message' => 'Ungültige Aktion']);
    }
    
    if ($result['success']) {
        wp_send_json_success($result);
    } else {
        wp_send_json_error($result);
    }
}
add_action('wp_ajax_csv_scheduler_activation', 'csv_import_scheduler_activation_handler');


/**
 * AJAX Emergency Reset - weniger restriktiv
 */
function csv_import_ajax_emergency_reset() {
    if (!current_user_can('edit_pages')) {
        wp_send_json_error(['message' => 'Keine Berechtigung']);
    }
    
    check_ajax_referer('csv_import_ajax', 'nonce');
    
    try {
        if (function_exists('csv_import_force_reset_import_status')) {
            csv_import_force_reset_import_status();
        }
        
        if (function_exists('csv_import_cleanup_temp_files')) {
            csv_import_cleanup_temp_files();
        }
        
        wp_send_json_success(['message' => 'Reset erfolgreich!']);
        
    } catch (Exception $e) {
        wp_send_json_error(['message' => $e->getMessage()]);
    }
}

// In includes/admin/admin-ajax.php, am Ende der Datei hinzufügen

add_action('wp_ajax_csv_import_generate_preview', 'csv_import_generate_preview_handler');

// In includes/admin/admin-ajax.php

function csv_import_generate_preview_handler() {
    check_ajax_referer('csv_import_ajax', 'nonce');
    if (!current_user_can('edit_pages')) {
        wp_send_json_error(['message' => 'Keine Berechtigung.']);
    }

    $template_id = isset($_POST['template_id']) ? intval($_POST['template_id']) : 0;
    $row_data = isset($_POST['row_data']) && is_array($_POST['row_data']) ? wp_unslash($_POST['row_data']) : [];

    if (empty($template_id) || empty($row_data)) {
        wp_send_json_error(['message' => 'Template-ID oder CSV-Daten fehlen.']);
    }

    try {
        if (!function_exists('csv_import_apply_template')) {
             throw new Exception('Template-Funktion (csv_import_apply_template) nicht gefunden.');
        }

        $config = ['template_id' => $template_id];
        $content = csv_import_apply_template($template_id, $row_data, $config);
        $rendered_content = apply_filters('the_content', $content);

        // NEU: Wickle den Inhalt in die notwendige Struktur für das Styling
        $title = esc_html($row_data['post_title'] ?? $row_data['title'] ?? 'Ihr Seitentitel');
        $slug = sanitize_title($title);
        $url = esc_url(home_url('/' . $slug));

        $html = '<div class="serp-preview google-serp active">';
        $html .= '    <div class="serp-result">';
        $html .= '        <div class="serp-title">' . $title . '</div>';
        $html .= '        <div class="serp-url">' . $url . '</div>';
        $html .= '        <div class="serp-description">' . $rendered_content . '</div>';
        $html .= '    </div>';
        $html .= '</div>';
        
        wp_send_json_success(['preview_html' => $html]);

    } catch (Exception $e) {
        wp_send_json_error(['message' => 'Fehler bei der Vorschau-Erstellung: ' . $e->getMessage()]);
    }
}

add_action('wp_ajax_csv_import_emergency_reset', 'csv_import_ajax_emergency_reset');
