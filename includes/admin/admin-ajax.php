<?php
/**
 * Verarbeitet alle AJAX-Anfragen aus dem Admin-Bereich des CSV Import Pro Plugins.
 * Version 9.0 - Refactored für Klarheit, Sicherheit und vollständige Funktionalität.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Direkten Zugriff verhindern
}

/**
 * Registriert alle AJAX-Aktionen des Plugins an einem zentralen Ort.
 */
function csv_import_pro_register_ajax_hooks(): void {
    // Ein Array, das alle AJAX-Aktionen und ihre Handler-Funktionen definiert.
    $ajax_actions = [
        // Standard-Import-Prozesse
        'csv_import_validate'          => 'csv_import_pro_validate_handler',
        'csv_import_start'             => 'csv_import_pro_start_handler',
        'csv_import_get_progress'      => 'csv_import_pro_get_progress_handler',
        'csv_import_cancel'            => 'csv_import_pro_cancel_handler',

        // Scheduler-Funktionen
        'csv_scheduler_activation'     => 'csv_import_pro_scheduler_activation_handler',
        'csv_scheduler_test'           => 'csv_import_pro_scheduler_test_handler',
        'csv_scheduler_status'         => 'csv_import_pro_scheduler_status_handler',

        // SEO & Vorschau-Funktionen
        'csv_seo_preview_validate'     => 'csv_import_pro_seo_validate_handler',
        'csv_update_seo_preview'       => 'csv_import_pro_update_seo_preview_handler',
        'csv_import_generate_preview'  => 'csv_import_pro_generate_template_preview_handler',

        // System & Debug-Funktionen
        'csv_import_system_health'     => 'csv_import_pro_system_health_handler',
        'csv_import_emergency_reset'   => 'csv_import_pro_emergency_reset_handler',
    ];

    foreach($ajax_actions as $action => $handler) {
        if (function_exists($handler)) {
            add_action('wp_ajax_' . $action, $handler);
        }
    }
}
add_action('init', 'csv_import_pro_register_ajax_hooks');


// ===================================================================
// HELPER-FUNKTION FÜR KONSISTENTE ANTWORTEN
// ===================================================================

/**
 * Sendet eine standardisierte Fehlerantwort und loggt den Vorfall.
 *
 * @param string $message Die Fehlermeldung für den Benutzer.
 * @param array  $context Zusätzliche Debug-Informationen.
 * @param int    $http_code Der HTTP-Statuscode.
 */
function csv_import_pro_ajax_error(string $message, array $context = [], int $http_code = 400): void {
    if (function_exists('csv_import_log')) {
        csv_import_log('warning', "AJAX Fehler: {$message}", $context);
    }
    wp_send_json_error(['message' => $message, 'debug' => $context], $http_code);
}


// ===================================================================
// STANDARD IMPORT AJAX-HANDLER
// ===================================================================

/**
 * Handler zum Validieren der Konfiguration oder einer CSV-Quelle.
 */
function csv_import_pro_validate_handler(): void {
    check_ajax_referer('csv_import_ajax', 'nonce');
    if (!current_user_can('edit_pages')) {
        csv_import_pro_ajax_error('Keine Berechtigung für die Validierung.', [], 403);
    }

    $type = isset($_POST['type']) ? sanitize_key($_POST['type']) : '';
    if (empty($type)) {
        csv_import_pro_ajax_error('Kein Validierungstyp angegeben.');
    }

    try {
        $config = csv_import_get_config();
        if ($type === 'config') {
            $validation = csv_import_validate_config($config);
            if (!$validation['valid']) {
                $validation['message'] = 'Konfigurationsfehler: <ul><li>' . implode('</li><li>', $validation['errors']) . '</li></ul>';
                wp_send_json_error($validation);
            }
            $validation['message'] = '✅ Konfiguration ist gültig.';
            wp_send_json_success($validation);

        } elseif (in_array($type, ['dropbox', 'local'])) {
            $result = csv_import_validate_csv_source($type, $config);
            if (!empty($result['valid'])) {
                wp_send_json_success($result);
            }
            wp_send_json_error($result);
        } else {
            csv_import_pro_ajax_error('Ungültiger Validierungstyp.');
        }
    } catch (Exception $e) {
        csv_import_pro_ajax_error('Validierung fehlgeschlagen: ' . $e->getMessage(), [], 500);
    }
}

/**
 * Handler zum Starten des Imports.
 */
function csv_import_pro_start_handler(): void {
    check_ajax_referer('csv_import_ajax', 'nonce');
    if (!current_user_can('edit_pages')) {
        csv_import_pro_ajax_error('Keine Berechtigung für den Import-Start.', [], 403);
    }

    $source = isset($_POST['source']) ? sanitize_key($_POST['source']) : '';
    if (!in_array($source, ['dropbox', 'local'])) {
        csv_import_pro_ajax_error('Ungültige Import-Quelle.');
    }

    try {
        if (csv_import_is_import_running()) {
             csv_import_pro_ajax_error('Ein Import läuft bereits.');
        }

        if (!class_exists('CSV_Import_Pro_Run')) {
            throw new Exception('Import-Klasse (CSV_Import_Pro_Run) nicht gefunden.');
        }
        
        $mapping = isset($_POST['mapping']) && is_array($_POST['mapping']) ? wp_unslash($_POST['mapping']) : [];
        $result = CSV_Import_Pro_Run::run($source, $mapping);
        
        if (!empty($result['success'])) {
            wp_send_json_success($result);
        }
        wp_send_json_error($result);
        
    } catch (Exception $e) {
        csv_import_pro_ajax_error('Import-Start fehlgeschlagen: ' . $e->getMessage(), [], 500);
    }
}

/**
 * Handler zum Abrufen des Import-Fortschritts.
 */
function csv_import_pro_get_progress_handler(): void {
    check_ajax_referer('csv_import_ajax', 'nonce');
    if (!current_user_can('edit_pages')) {
        csv_import_pro_ajax_error('Keine Berechtigung.', [], 403);
    }
    wp_send_json_success(csv_import_get_progress());
}

/**
 * Handler zum Abbrechen eines laufenden Imports.
 */
function csv_import_pro_cancel_handler(): void {
    check_ajax_referer('csv_import_ajax', 'nonce');
    if (!current_user_can('manage_options')) {
        csv_import_pro_ajax_error('Keine Berechtigung zum Abbrechen.', [], 403);
    }
    
    csv_import_force_reset_import_status();
    csv_import_log('warning', 'Import wurde via AJAX manuell abgebrochen.', ['user_id' => get_current_user_id()]);
    wp_send_json_success(['message' => 'Import erfolgreich abgebrochen und zurückgesetzt.']);
}


// ===================================================================
// SCHEDULER AJAX-HANDLER
// ===================================================================

/**
 * Handler zum Aktivieren/Deaktivieren des Schedulers.
 */
function csv_import_pro_scheduler_activation_handler(): void {
    check_ajax_referer('csv_import_ajax', 'nonce');
    if (!current_user_can('manage_options')) {
        csv_import_pro_ajax_error('Keine Berechtigung.', [], 403);
    }
    
    $action = isset($_POST['scheduler_action']) ? sanitize_key($_POST['scheduler_action']) : '';
    $result = ($action === 'enable') ? csv_import_enable_scheduler() : csv_import_disable_scheduler();

    if ($result['success']) {
        wp_send_json_success($result);
    }
    wp_send_json_error($result);
}

/**
 * Handler zum Testen des Schedulers.
 */
function csv_import_pro_scheduler_test_handler(): void {
    check_ajax_referer('csv_import_ajax', 'nonce');
    if (!current_user_can('manage_options') || !class_exists('CSV_Import_Scheduler')) {
        csv_import_pro_ajax_error('Keine Berechtigung oder Scheduler nicht verfügbar.', [], 403);
    }
    
    $result = CSV_Import_Scheduler::test_scheduler();
    if (is_wp_error($result)) {
        csv_import_pro_ajax_error($result->get_error_message());
    }
    wp_send_json_success($result);
}

/**
 * Handler zum Abrufen des Scheduler-Status.
 */
function csv_import_pro_scheduler_status_handler(): void {
    check_ajax_referer('csv_import_ajax', 'nonce');
    if (!current_user_can('edit_pages') || !class_exists('CSV_Import_Scheduler')) {
        csv_import_pro_ajax_error('Keine Berechtigung oder Scheduler nicht verfügbar.', [], 403);
    }
    wp_send_json_success(CSV_Import_Scheduler::get_scheduler_info());
}


// ===================================================================
// SEO & VORSCHAU AJAX-HANDLER
// ===================================================================

/**
 * Handler zur Validierung von SEO-Daten in der Vorschau.
 */
function csv_import_pro_seo_validate_handler(): void {
    check_ajax_referer('csv_seo_preview', 'nonce'); // Beachten Sie die andere Nonce hier
    if (!current_user_can('edit_pages') || !class_exists('CSV_Import_SEO_Preview')) {
        csv_import_pro_ajax_error('Keine Berechtigung oder SEO-Modul nicht verfügbar.', [], 403);
    }

    $title = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '';
    $description = isset($_POST['description']) ? sanitize_textarea_field($_POST['description']) : '';
    
    wp_send_json_success(CSV_Import_SEO_Preview::validate_seo_data($title, $description));
}

/**
 * AJAX-Handler zum Aktualisieren der SEO-Vorschau. (Wiederhergestellt)
 */
function csv_import_pro_update_seo_preview_handler(): void {
    check_ajax_referer('csv_import_ajax', 'nonce');
    if (!current_user_can('edit_pages')) {
        csv_import_pro_ajax_error('Keine Berechtigung.');
    }

    $sample_data = isset($_POST['sample_data']) ? wp_unslash($_POST['sample_data']) : [];
    
    ob_start();
    CSV_Import_SEO_Preview::render_preview_widget($sample_data);
    $preview_html = ob_get_clean();

    wp_send_json_success(['preview_html' => $preview_html]);
}

/**
 * Handler zum Generieren der Live-Template-Vorschau mit CSV-Daten.
 */
function csv_import_pro_generate_template_preview_handler(): void {
    check_ajax_referer('csv_import_ajax', 'nonce');
    if (!current_user_can('edit_pages')) {
        csv_import_pro_ajax_error('Keine Berechtigung.');
    }

    $template_id = isset($_POST['template_id']) ? intval($_POST['template_id']) : 0;
    $row_data = isset($_POST['row_data']) && is_array($_POST['row_data']) ? wp_unslash($_POST['row_data']) : [];

    if (empty($template_id) || empty($row_data)) {
        csv_import_pro_ajax_error('Template-ID oder CSV-Daten fehlen.');
    }

    try {
        $content = csv_import_apply_template($template_id, $row_data, ['template_id' => $template_id]);
        $rendered_content = apply_filters('the_content', $content);
        
        wp_send_json_success(['preview_html' => $rendered_content]);
    } catch (Exception $e) {
        csv_import_pro_ajax_error('Fehler bei der Vorschau-Erstellung: ' . $e->getMessage());
    }
}


// ===================================================================
// SYSTEM & DEBUG AJAX-HANDLER
// ===================================================================

/**
 * Handler zum Abrufen des System-Health-Status.
 */
function csv_import_pro_system_health_handler(): void {
    check_ajax_referer('csv_import_ajax', 'nonce');
    if (!current_user_can('edit_pages')) {
        csv_import_pro_ajax_error('Keine Berechtigung.', [], 403);
    }
    
    $health = csv_import_system_health_check();
    $issues = array_keys(array_filter($health, fn($status) => $status === false));
    
    wp_send_json_success([
        'healthy' => empty($issues),
        'issues' => $issues,
        'details' => $health
    ]);
}

/**
 * Handler für den Notfall-Reset.
 */
function csv_import_pro_emergency_reset_handler(): void {
    check_ajax_referer('csv_import_ajax', 'nonce');
    if (!current_user_can('manage_options')) {
        csv_import_pro_ajax_error('Keine Berechtigung für den Notfall-Reset.', [], 403);
    }

    csv_import_force_reset_import_status();
    if (class_exists('CSV_Import_Scheduler')) {
        CSV_Import_Scheduler::unschedule_all();
    }
    csv_import_log('critical', 'Notfall-Reset wurde via AJAX durchgeführt.', ['user_id' => get_current_user_id()]);
    wp_send_json_success(['message' => 'Notfall-Reset erfolgreich. Alle Sperren und geplanten Aufgaben wurden entfernt.']);
}
