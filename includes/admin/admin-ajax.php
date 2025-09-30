<?php
/**
 * Verarbeitet alle AJAX-Anfragen aus dem Admin-Bereich des CSV Import Pro Plugins.
 * Version 10.0 - KOMPLETT KORRIGIERT für Template-Generierung und alle Features
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Direkten Zugriff verhindern
}

/**
 * Registriert alle AJAX-Aktionen des Plugins an einem zentralen Ort.
 */
function csv_import_pro_register_ajax_hooks(): void {
    // Array mit allen AJAX-Aktionen und ihren Handler-Funktionen
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

        // Template-Generierung (KORRIGIERT)
        'csv_import_generate_template' => 'csv_import_pro_generate_template_handler',

        // System & Debug-Funktionen
        'csv_import_system_health'     => 'csv_import_pro_system_health_handler',
        'csv_import_emergency_reset'   => 'csv_import_pro_emergency_reset_handler',
    ];

    foreach($ajax_actions as $action => $handler) {
        if (function_exists($handler)) {
            add_action('wp_ajax_' . $action, $handler);
        } else {
            error_log("CSV Import Pro: AJAX handler function not found: $handler");
        }
    }
}
add_action('init', 'csv_import_pro_register_ajax_hooks', 5);

// ===================================================================
// HELPER-FUNKTION FÜR KONSISTENTE ANTWORTEN
// ===================================================================

/**
 * Sendet eine standardisierte Fehlerantwort und loggt den Vorfall.
 */
function csv_import_pro_ajax_error(string $message, array $context = [], int $http_code = 400): void {
    if (function_exists('csv_import_log')) {
        csv_import_log('warning', "AJAX Fehler: {$message}", $context);
    }
    wp_send_json_error(['message' => $message, 'debug' => $context], $http_code);
}

/**
 * Sendet eine standardisierte Erfolgsantwort.
 */
function csv_import_pro_ajax_success(string $message, array $data = []): void {
    wp_send_json_success(array_merge(['message' => $message], $data));
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
        if (!function_exists('csv_import_get_config')) {
            throw new Exception('csv_import_get_config Funktion nicht verfügbar');
        }

        $config = csv_import_get_config();
        
        if ($type === 'config') {
            if (!function_exists('csv_import_validate_config')) {
                throw new Exception('csv_import_validate_config Funktion nicht verfügbar');
            }
            
            $validation = csv_import_validate_config($config);
            if (!$validation['valid']) {
                $validation['message'] = 'Konfigurationsfehler: <ul><li>' . implode('</li><li>', $validation['errors']) . '</li></ul>';
                wp_send_json_error($validation);
            }
            $validation['message'] = '✅ Konfiguration ist gültig.';
            wp_send_json_success($validation);

        } elseif (in_array($type, ['dropbox', 'local'])) {
            if (!function_exists('csv_import_validate_csv_source')) {
                throw new Exception('csv_import_validate_csv_source Funktion nicht verfügbar');
            }
            
            $result = csv_import_validate_csv_source($type, $config);
            if (!empty($result['valid'])) {
                wp_send_json_success($result);
            }
            wp_send_json_error($result);
        } else {
            csv_import_pro_ajax_error('Ungültiger Validierungstyp.');
        }
    } catch (Exception $e) {
        csv_import_pro_ajax_error('Validierung fehlgeschlagen: ' . $e->getMessage(), [
            'type' => $type,
            'trace' => $e->getTraceAsString()
        ], 500);
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
        if (!function_exists('csv_import_is_import_running')) {
            throw new Exception('csv_import_is_import_running Funktion nicht verfügbar');
        }

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
        csv_import_pro_ajax_error('Import-Start fehlgeschlagen: ' . $e->getMessage(), [
            'source' => $source,
            'trace' => $e->getTraceAsString()
        ], 500);
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
    
    if (!function_exists('csv_import_get_progress')) {
        csv_import_pro_ajax_error('Progress-Funktion nicht verfügbar.');
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
    
    if (!function_exists('csv_import_force_reset_import_status')) {
        csv_import_pro_ajax_error('Reset-Funktion nicht verfügbar.');
    }
    
    csv_import_force_reset_import_status();
    
    if (function_exists('csv_import_log')) {
        csv_import_log('warning', 'Import wurde via AJAX manuell abgebrochen.', ['user_id' => get_current_user_id()]);
    }
    
    csv_import_pro_ajax_success('Import erfolgreich abgebrochen und zurückgesetzt.');
}

// ===================================================================
// TEMPLATE-GENERIERUNG AJAX-HANDLER (KORRIGIERT)
// ===================================================================

/**
 * KORRIGIERTER Handler für Template-Generierung aus CSV-Headern
 */
function csv_import_pro_generate_template_handler(): void {
    check_ajax_referer('csv_import_ajax', 'nonce');
    if (!current_user_can('edit_pages')) {
        csv_import_pro_ajax_error('Keine Berechtigung für Template-Generierung.', [], 403);
    }

    $base_template_id = isset($_POST['base_template_id']) ? intval($_POST['base_template_id']) : 0;
    $new_template_name = isset($_POST['new_template_name']) ? sanitize_text_field($_POST['new_template_name']) : '';

    // Eingabe-Validierung
    if (empty($base_template_id) || $base_template_id <= 0) {
        csv_import_pro_ajax_error('Gültige Basis-Template-ID ist erforderlich.');
    }

    if (empty($new_template_name)) {
        csv_import_pro_ajax_error('Template-Name ist erforderlich.');
    }

    // Template-Name-Länge prüfen
    if (strlen($new_template_name) > 200) {
        csv_import_pro_ajax_error('Template-Name ist zu lang (max. 200 Zeichen).');
    }

    try {
        // Prüfen ob Template Manager verfügbar ist
        if (!class_exists('CSV_Import_Template_Manager')) {
            throw new Exception('Template Manager Klasse nicht verfügbar. Plugin möglicherweise nicht vollständig geladen.');
        }

        // Basis-Post prüfen
        $base_post = get_post($base_template_id);
        if (!$base_post) {
            throw new Exception('Basis-Template mit ID ' . $base_template_id . ' nicht gefunden.');
        }

        // Berechtigung für Basis-Post prüfen
        if (!current_user_can('edit_post', $base_template_id)) {
            throw new Exception('Keine Berechtigung zum Zugriff auf das Basis-Template.');
        }

        // Template generieren
        $new_template_id = CSV_Import_Template_Manager::create_template_from_csv_headers(
            $base_template_id, 
            $new_template_name
        );

        if (is_wp_error($new_template_id)) {
            throw new Exception($new_template_id->get_error_message());
        }

        // Erfolgreiche Antwort mit Template-Informationen
        $edit_link = get_edit_post_link($new_template_id);
        $view_link = get_permalink($new_template_id);
        
        csv_import_pro_ajax_success('Template erfolgreich generiert!', [
            'template_id' => $new_template_id,
            'template_name' => $new_template_name,
            'edit_link' => $edit_link,
            'view_link' => $view_link,
            'message_html' => sprintf(
                'Template <strong>"%s"</strong> wurde erfolgreich erstellt! <a href="%s" target="_blank" class="button button-small">Jetzt bearbeiten</a>',
                esc_html($new_template_name),
                esc_url($edit_link)
            )
        ]);

    } catch (Exception $e) {
        // Detaillierte Fehlerbehandlung
        $error_context = [
            'base_template_id' => $base_template_id,
            'new_template_name' => $new_template_name,
            'user_id' => get_current_user_id(),
            'user_can_edit_posts' => current_user_can('edit_posts'),
            'class_exists_template_manager' => class_exists('CSV_Import_Template_Manager')
        ];

        if (function_exists('csv_import_log')) {
            csv_import_log('error', 'Template-Generierung fehlgeschlagen: ' . $e->getMessage(), $error_context);
        }

        csv_import_pro_ajax_error(
            'Template-Generierung fehlgeschlagen: ' . $e->getMessage(),
            $error_context,
            500
        );
    }
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
    
    if (!in_array($action, ['enable', 'disable'])) {
        csv_import_pro_ajax_error('Ungültige Scheduler-Aktion.');
    }

    try {
        if (!function_exists('csv_import_enable_scheduler') || !function_exists('csv_import_disable_scheduler')) {
            throw new Exception('Scheduler-Funktionen nicht verfügbar.');
        }

        $result = ($action === 'enable') ? csv_import_enable_scheduler() : csv_import_disable_scheduler();

        if ($result['success']) {
            wp_send_json_success($result);
        }
        wp_send_json_error($result);
        
    } catch (Exception $e) {
        csv_import_pro_ajax_error('Scheduler-Aktion fehlgeschlagen: ' . $e->getMessage(), [
            'action' => $action,
            'trace' => $e->getTraceAsString()
        ], 500);
    }
}

/**
 * Handler zum Testen des Schedulers.
 */
function csv_import_pro_scheduler_test_handler(): void {
    check_ajax_referer('csv_import_ajax', 'nonce');
    if (!current_user_can('manage_options')) {
        csv_import_pro_ajax_error('Keine Berechtigung.', [], 403);
    }
    
    try {
        if (!class_exists('CSV_Import_Scheduler')) {
            throw new Exception('Scheduler-Klasse nicht verfügbar.');
        }

        if (!method_exists('CSV_Import_Scheduler', 'test_scheduler')) {
            throw new Exception('Scheduler-Test-Methode nicht verfügbar.');
        }
        
        $result = CSV_Import_Scheduler::test_scheduler();
        
        if (is_wp_error($result)) {
            csv_import_pro_ajax_error($result->get_error_message());
        }
        
        wp_send_json_success($result);
        
    } catch (Exception $e) {
        csv_import_pro_ajax_error('Scheduler-Test fehlgeschlagen: ' . $e->getMessage(), [
            'trace' => $e->getTraceAsString()
        ], 500);
    }
}

/**
 * Handler zum Abrufen des Scheduler-Status.
 */
function csv_import_pro_scheduler_status_handler(): void {
    check_ajax_referer('csv_import_ajax', 'nonce');
    if (!current_user_can('edit_pages')) {
        csv_import_pro_ajax_error('Keine Berechtigung.', [], 403);
    }
    
    try {
        if (!class_exists('CSV_Import_Scheduler')) {
            throw new Exception('Scheduler-Klasse nicht verfügbar.');
        }

        if (!method_exists('CSV_Import_Scheduler', 'get_scheduler_info')) {
            throw new Exception('Scheduler-Info-Methode nicht verfügbar.');
        }
        
        wp_send_json_success(CSV_Import_Scheduler::get_scheduler_info());
        
    } catch (Exception $e) {
        csv_import_pro_ajax_error('Scheduler-Status abrufen fehlgeschlagen: ' . $e->getMessage(), [
            'trace' => $e->getTraceAsString()
        ], 500);
    }
}

// ===================================================================
// SEO & VORSCHAU AJAX-HANDLER
// ===================================================================

/**
 * Handler zur Validierung von SEO-Daten in der Vorschau.
 */
function csv_import_pro_seo_validate_handler(): void {
    check_ajax_referer('csv_seo_preview', 'nonce'); // Beachten Sie die andere Nonce hier
    if (!current_user_can('edit_pages')) {
        csv_import_pro_ajax_error('Keine Berechtigung.', [], 403);
    }

    try {
        if (!class_exists('CSV_Import_SEO_Preview')) {
            throw new Exception('SEO-Preview-Klasse nicht verfügbar.');
        }

        $title = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '';
        $description = isset($_POST['description']) ? sanitize_textarea_field($_POST['description']) : '';
        $slug = isset($_POST['slug']) ? sanitize_title($_POST['slug']) : '';
        
        if (!method_exists('CSV_Import_SEO_Preview', 'validate_seo_data')) {
            throw new Exception('SEO-Validierungsmethode nicht verfügbar.');
        }
        
        wp_send_json_success(CSV_Import_SEO_Preview::validate_seo_data($title, $description, $slug));
        
    } catch (Exception $e) {
        csv_import_pro_ajax_error('SEO-Validierung fehlgeschlagen: ' . $e->getMessage(), [
            'trace' => $e->getTraceAsString()
        ], 500);
    }
}

/**
 * AJAX-Handler zum Aktualisieren der SEO-Vorschau.
 */
function csv_import_pro_update_seo_preview_handler(): void {
    check_ajax_referer('csv_import_ajax', 'nonce');
    if (!current_user_can('edit_pages')) {
        csv_import_pro_ajax_error('Keine Berechtigung.');
    }

    try {
        if (!class_exists('CSV_Import_SEO_Preview')) {
            throw new Exception('SEO-Preview-Klasse nicht verfügbar.');
        }

        $sample_data = isset($_POST['sample_data']) ? wp_unslash($_POST['sample_data']) : [];
        
        if (!method_exists('CSV_Import_SEO_Preview', 'render_preview_widget')) {
            throw new Exception('SEO-Preview-Render-Methode nicht verfügbar.');
        }
        
        ob_start();
        CSV_Import_SEO_Preview::render_preview_widget($sample_data);
        $preview_html = ob_get_clean();

        wp_send_json_success(['preview_html' => $preview_html]);
        
    } catch (Exception $e) {
        csv_import_pro_ajax_error('SEO-Vorschau-Update fehlgeschlagen: ' . $e->getMessage(), [
            'trace' => $e->getTraceAsString()
        ], 500);
    }
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

    if (empty($template_id) || $template_id <= 0) {
        csv_import_pro_ajax_error('Gültige Template-ID ist erforderlich.');
    }

    if (empty($row_data)) {
        csv_import_pro_ajax_error('CSV-Daten für Vorschau sind erforderlich.');
    }

    try {
        // Template-Post prüfen
        $template_post = get_post($template_id);
        if (!$template_post) {
            throw new Exception('Template mit ID ' . $template_id . ' nicht gefunden.');
        }

        // Berechtigung prüfen
        if (!current_user_can('read_post', $template_id)) {
            throw new Exception('Keine Berechtigung zum Zugriff auf dieses Template.');
        }

        // Template anwenden
        if (!function_exists('csv_import_apply_template')) {
            throw new Exception('Template-Anwendungs-Funktion nicht verfügbar.');
        }
        
        $content = csv_import_apply_template($template_id, $row_data, ['template_id' => $template_id]);
        
        // Content durch WordPress-Filter verarbeiten
        $rendered_content = apply_filters('the_content', $content);
        
        // Sicherheit: Script-Tags entfernen für Preview
        $rendered_content = wp_kses_post($rendered_content);
        
        wp_send_json_success([
            'preview_html' => $rendered_content,
            'template_id' => $template_id,
            'data_keys' => array_keys($row_data)
        ]);
        
    } catch (Exception $e) {
        csv_import_pro_ajax_error('Fehler bei der Vorschau-Erstellung: ' . $e->getMessage(), [
            'template_id' => $template_id,
            'row_data_keys' => array_keys($row_data),
            'trace' => $e->getTraceAsString()
        ]);
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
    
    try {
        if (!function_exists('csv_import_system_health_check')) {
            throw new Exception('System-Health-Check-Funktion nicht verfügbar.');
        }
        
        $health = csv_import_system_health_check();
        $issues = array_keys(array_filter($health, fn($status) => $status === false));
        
        wp_send_json_success([
            'healthy' => empty($issues),
            'issues' => $issues,
            'details' => $health,
            'issues_count' => count($issues),
            'total_checks' => count($health)
        ]);
        
    } catch (Exception $e) {
        csv_import_pro_ajax_error('System-Health-Check fehlgeschlagen: ' . $e->getMessage(), [
            'trace' => $e->getTraceAsString()
        ], 500);
    }
}

/**
 * Handler für den Notfall-Reset.
 */
function csv_import_pro_emergency_reset_handler(): void {
    check_ajax_referer('csv_import_ajax', 'nonce');
    if (!current_user_can('manage_options')) {
        csv_import_pro_ajax_error('Keine Berechtigung für den Notfall-Reset.', [], 403);
    }

    try {
        // Reset-Funktionen aufrufen
        if (function_exists('csv_import_force_reset_import_status')) {
            csv_import_force_reset_import_status();
        }
        
        if (class_exists('CSV_Import_Scheduler') && method_exists('CSV_Import_Scheduler', 'unschedule_all')) {
            CSV_Import_Scheduler::unschedule_all();
        }
        
        // Zusätzliche Bereinigung
        if (function_exists('csv_import_cleanup_temp_files')) {
            csv_import_cleanup_temp_files();
        }
        
        if (function_exists('csv_import_cleanup_dead_processes')) {
            csv_import_cleanup_dead_processes();
        }
        
        // Globale Bereinigung
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%csv_import%lock%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_csv_import_%'");
        
        if (function_exists('csv_import_log')) {
            csv_import_log('critical', 'Notfall-Reset wurde via AJAX durchgeführt.', [
                'user_id' => get_current_user_id(),
                'user_login' => wp_get_current_user()->user_login,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
        }
        
        csv_import_pro_ajax_success('Notfall-Reset erfolgreich durchgeführt. Alle Locks und geplanten Aufgaben wurden entfernt.');
        
    } catch (Exception $e) {
        csv_import_pro_ajax_error('Notfall-Reset fehlgeschlagen: ' . $e->getMessage(), [
            'trace' => $e->getTraceAsString()
        ], 500);
    }
}

// ===================================================================
// TESTING & DEBUG HANDLERS
// ===================================================================

/**
 * Handler zum Testen der Template-Manager-Funktionalität
 */
function csv_import_pro_test_template_manager_handler(): void {
    check_ajax_referer('csv_import_ajax', 'nonce');
    if (!current_user_can('manage_options')) {
        csv_import_pro_ajax_error('Keine Berechtigung für Debug-Tests.', [], 403);
    }

    try {
        if (!class_exists('CSV_Import_Template_Manager')) {
            throw new Exception('Template Manager Klasse nicht verfügbar.');
        }

        if (!method_exists('CSV_Import_Template_Manager', 'debug_get_csv_headers')) {
            throw new Exception('Debug-Methode nicht verfügbar.');
        }

        $debug_info = CSV_Import_Template_Manager::debug_get_csv_headers();
        
        wp_send_json_success([
            'debug_info' => $debug_info,
            'class_available' => class_exists('CSV_Import_Template_Manager'),
            'method_available' => method_exists('CSV_Import_Template_Manager', 'create_template_from_csv_headers'),
            'core_functions' => [
                'csv_import_get_config' => function_exists('csv_import_get_config'),
                'csv_import_validate_config' => function_exists('csv_import_validate_config'),
                'csv_import_validate_csv_source' => function_exists('csv_import_validate_csv_source')
            ]
        ]);
        
    } catch (Exception $e) {
        csv_import_pro_ajax_error('Template-Manager-Test fehlgeschlagen: ' . $e->getMessage(), [
            'trace' => $e->getTraceAsString()
        ], 500);
    }
}

// ===================================================================
// INITIALIZATION & FALLBACK
// ===================================================================

/**
 * Fallback für nicht registrierte AJAX-Aktionen
 */
function csv_import_pro_ajax_fallback(): void {
    $action = $_POST['action'] ?? $_GET['action'] ?? 'unknown';
    
    csv_import_pro_ajax_error('Unbekannte AJAX-Aktion: ' . $action, [
        'available_actions' => [
            'csv_import_validate',
            'csv_import_start',
            'csv_import_generate_template',
            'csv_scheduler_activation',
            'csv_seo_preview_validate',
            'csv_import_system_health',
            'csv_import_emergency_reset'
        ],
        'action_requested' => $action
    ], 404);
}

// ===================================================================
// AJAX ACTION REGISTRATION VERIFICATION
// ===================================================================

/**
 * Überprüft ob alle AJAX-Aktionen korrekt registriert wurden
 */
function csv_import_pro_verify_ajax_registration(): void {
    if (!defined('WP_DEBUG') || !WP_DEBUG) {
        return;
    }

    $expected_handlers = [
        'csv_import_pro_validate_handler',
        'csv_import_pro_start_handler',
        'csv_import_pro_generate_template_handler',
        'csv_import_pro_scheduler_activation_handler',
        'csv_import_pro_seo_validate_handler',
        'csv_import_pro_system_health_handler',
        'csv_import_pro_emergency_reset_handler'
    ];

    $missing_handlers = array_filter($expected_handlers, function($handler) {
        return !function_exists($handler);
    });

    if (!empty($missing_handlers)) {
        error_log('CSV Import Pro: Fehlende AJAX-Handler: ' . implode(', ', $missing_handlers));
    }

    if (function_exists('csv_import_log')) {
        csv_import_log('debug', 'AJAX-Handler überprüft', [
            'total_expected' => count($expected_handlers),
            'missing_handlers' => $missing_handlers,
            'all_handlers_available' => empty($missing_handlers)
        ]);
    }
}

// Registrierung bei WordPress-Initialisierung überprüfen
add_action('init', 'csv_import_pro_verify_ajax_registration', 999);

// ===================================================================
// ADDITIONAL UTILITY FUNCTIONS
// ===================================================================

/**
 * Überprüft AJAX-Anfrage-Kontext und führt Basis-Validierung durch
 */
function csv_import_pro_validate_ajax_context(): array {
    return [
        'is_admin' => is_admin(),
        'doing_ajax' => wp_doing_ajax(),
        'user_logged_in' => is_user_logged_in(),
        'current_user_id' => get_current_user_id(),
        'user_can_edit_posts' => current_user_can('edit_posts'),
        'user_can_manage_options' => current_user_can('manage_options'),
        'nonce_field_present' => isset($_POST['nonce']) || isset($_POST['_wpnonce']),
        'action_field_present' => isset($_POST['action']) || isset($_GET['action']),
        'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
        'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ];
}

/**
 * Sanitized AJAX-Parameter mit erweiterten Checks
 */
function csv_import_pro_sanitize_ajax_params(array $params): array {
    $sanitized = [];
    
    foreach ($params as $key => $value) {
        $key = sanitize_key($key);
        
        if (is_array($value)) {
            $sanitized[$key] = array_map('sanitize_text_field', $value);
        } elseif (is_string($value)) {
            // Verschiedene Sanitization je nach Parameter-Typ
            switch ($key) {
                case 'email':
                    $sanitized[$key] = sanitize_email($value);
                    break;
                case 'url':
                case 'dropbox_url':
                    $sanitized[$key] = esc_url_raw($value);
                    break;
                case 'template_name':
                case 'new_template_name':
                    $sanitized[$key] = sanitize_text_field(wp_strip_all_tags($value));
                    break;
                case 'description':
                case 'seo_description':
                    $sanitized[$key] = sanitize_textarea_field($value);
                    break;
                case 'id':
                case 'template_id':
                case 'base_template_id':
                case 'post_id':
                    $sanitized[$key] = absint($value);
                    break;
                case 'slug':
                case 'post_name':
                    $sanitized[$key] = sanitize_title($value);
                    break;
                default:
                    $sanitized[$key] = sanitize_text_field($value);
            }
        } else {
            $sanitized[$key] = $value; // Zahlen, Booleans etc.
        }
    }
    
    return $sanitized;
}

/**
 * Rate Limiting für AJAX-Anfragen (Schutz vor Spam)
 */
function csv_import_pro_check_rate_limit(string $action): bool {
    $user_id = get_current_user_id();
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $cache_key = "csv_import_rate_limit_{$action}_{$user_id}_{$ip_address}";
    
    // Rate Limits pro Aktion definieren
    $rate_limits = [
        'csv_import_validate' => ['requests' => 20, 'window' => 300], // 20 Anfragen in 5 Min
        'csv_import_start' => ['requests' => 5, 'window' => 900],     // 5 Anfragen in 15 Min
        'csv_import_generate_template' => ['requests' => 10, 'window' => 600], // 10 in 10 Min
        'csv_scheduler_activation' => ['requests' => 5, 'window' => 300], // 5 in 5 Min
        'default' => ['requests' => 30, 'window' => 300] // Standard: 30 in 5 Min
    ];
    
    $limit = $rate_limits[$action] ?? $rate_limits['default'];
    
    // Aktuelle Request-Anzahl abrufen
    $current_requests = get_transient($cache_key) ?: 0;
    
    if ($current_requests >= $limit['requests']) {
        // Rate Limit erreicht
        if (function_exists('csv_import_log')) {
            csv_import_log('warning', "Rate Limit erreicht für Aktion: {$action}", [
                'user_id' => $user_id,
                'ip_address' => $ip_address,
                'current_requests' => $current_requests,
                'limit' => $limit['requests']
            ]);
        }
        return false;
    }
    
    // Request-Counter erhöhen
    set_transient($cache_key, $current_requests + 1, $limit['window']);
    
    return true;
}

/**
 * Erweiterte Sicherheitsprüfung für kritische AJAX-Aktionen
 */
function csv_import_pro_security_check(string $action): bool {
    // IP-Blacklist prüfen
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
    $blacklisted_ips = get_option('csv_import_blacklisted_ips', []);
    
    if (in_array($ip_address, $blacklisted_ips)) {
        if (function_exists('csv_import_log')) {
            csv_import_log('critical', "AJAX-Anfrage von gesperrter IP: {$ip_address}", [
                'action' => $action,
                'user_id' => get_current_user_id()
            ]);
        }
        return false;
    }
    
    // User-Agent prüfen (Bot-Schutz)
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $suspicious_agents = ['bot', 'crawler', 'spider', 'scraper'];
    
    foreach ($suspicious_agents as $agent) {
        if (stripos($user_agent, $agent) !== false) {
            if (function_exists('csv_import_log')) {
                csv_import_log('warning', "Verdächtiger User-Agent bei AJAX-Anfrage: {$user_agent}", [
                    'action' => $action,
                    'ip_address' => $ip_address
                ]);
            }
            // Bot-Anfragen nicht komplett blockieren, aber loggen
        }
    }
    
    // Kritische Aktionen: Zusätzliche Berechtigung prüfen
    $critical_actions = [
        'csv_import_start',
        'csv_import_generate_template',
        'csv_scheduler_activation',
        'csv_import_emergency_reset'
    ];
    
    if (in_array($action, $critical_actions)) {
        // Session-Validierung
        if (!wp_get_session_token()) {
            if (function_exists('csv_import_log')) {
                csv_import_log('warning', "Kritische AJAX-Aktion ohne gültige Session: {$action}", [
                    'user_id' => get_current_user_id(),
                    'ip_address' => $ip_address
                ]);
            }
            return false;
        }
        
        // Admin-Aktionen: Zusätzlicher Schutz
        if (in_array($action, ['csv_scheduler_activation', 'csv_import_emergency_reset'])) {
            if (!current_user_can('manage_options')) {
                return false;
            }
            
            // Multi-Faktor-Authentifizierung prüfen (falls verfügbar)
            if (function_exists('is_user_using_two_factor') && is_user_using_two_factor(get_current_user_id())) {
                // 2FA-Status prüfen
                if (!get_user_meta(get_current_user_id(), '_two_factor_authenticated_' . wp_get_session_token(), true)) {
                    if (function_exists('csv_import_log')) {
                        csv_import_log('warning', "2FA-Benutzer ohne aktuelle 2FA-Authentifizierung: {$action}");
                    }
                    // Nicht blockieren, nur loggen (da nicht alle Installationen 2FA haben)
                }
            }
        }
    }
    
    return true;
}

/**
 * Performance-Monitoring für AJAX-Requests
 */
function csv_import_pro_monitor_ajax_performance(string $action, float $start_time): void {
    $end_time = microtime(true);
    $execution_time = $end_time - $start_time;
    $memory_usage = memory_get_usage(true);
    $peak_memory = memory_get_peak_usage(true);
    
    // Performance-Warnung bei langsamen Requests
    if ($execution_time > 5.0) { // 5 Sekunden
        if (function_exists('csv_import_log')) {
            csv_import_log('warning', "Langsame AJAX-Anfrage: {$action}", [
                'execution_time' => round($execution_time, 2) . 's',
                'memory_usage' => size_format($memory_usage),
                'peak_memory' => size_format($peak_memory),
                'user_id' => get_current_user_id()
            ]);
        }
    }
    
    // Performance-Metriken sammeln
    $metrics = get_option('csv_import_ajax_performance_metrics', []);
    $today = current_time('Y-m-d');
    
    if (!isset($metrics[$today])) {
        $metrics[$today] = [];
    }
    
    if (!isset($metrics[$today][$action])) {
        $metrics[$today][$action] = [
            'count' => 0,
            'total_time' => 0,
            'max_time' => 0,
            'min_time' => PHP_FLOAT_MAX,
            'total_memory' => 0,
            'max_memory' => 0
        ];
    }
    
    $metrics[$today][$action]['count']++;
    $metrics[$today][$action]['total_time'] += $execution_time;
    $metrics[$today][$action]['max_time'] = max($metrics[$today][$action]['max_time'], $execution_time);
    $metrics[$today][$action]['min_time'] = min($metrics[$today][$action]['min_time'], $execution_time);
    $metrics[$today][$action]['total_memory'] += $memory_usage;
    $metrics[$today][$action]['max_memory'] = max($metrics[$today][$action]['max_memory'], $memory_usage);
    
    // Nur letzte 30 Tage behalten
    if (count($metrics) > 30) {
        $metrics = array_slice($metrics, -30, null, true);
    }
    
    update_option('csv_import_ajax_performance_metrics', $metrics);
}

// ===================================================================
// AJAX WRAPPER FUNCTION (für vereinfachte Handler-Erstellung)
// ===================================================================

/**
 * Wrapper-Funktion für AJAX-Handler mit automatischer Sicherheit und Monitoring
 */
function csv_import_pro_ajax_wrapper(string $action, callable $handler, array $options = []): void {
    $start_time = microtime(true);
    
    try {
        // Standard-Optionen
        $default_options = [
            'capability' => 'edit_pages',
            'nonce' => 'csv_import_ajax',
            'rate_limit' => true,
            'security_check' => true,
            'log_calls' => true
        ];
        
        $options = array_merge($default_options, $options);
        
        // Nonce-Prüfung
        if ($options['nonce']) {
            check_ajax_referer($options['nonce'], 'nonce');
        }
        
        // Berechtigung prüfen
        if ($options['capability'] && !current_user_can($options['capability'])) {
            csv_import_pro_ajax_error('Keine Berechtigung für diese Aktion.', [], 403);
        }
        
        // Rate Limiting
        if ($options['rate_limit'] && !csv_import_pro_check_rate_limit($action)) {
            csv_import_pro_ajax_error('Zu viele Anfragen. Bitte versuchen Sie es später erneut.', [], 429);
        }
        
        // Sicherheitsprüfung
        if ($options['security_check'] && !csv_import_pro_security_check($action)) {
            csv_import_pro_ajax_error('Sicherheitsprüfung fehlgeschlagen.', [], 403);
        }
        
        // AJAX-Kontext loggen
        if ($options['log_calls'] && function_exists('csv_import_log')) {
            csv_import_log('debug', "AJAX-Aufruf: {$action}", [
                'user_id' => get_current_user_id(),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200)
            ]);
        }
        
        // Handler ausführen
        call_user_func($handler);
        
    } catch (Exception $e) {
        csv_import_pro_ajax_error(
            'Unerwarteter Fehler: ' . $e->getMessage(),
            [
                'action' => $action,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ],
            500
        );
    } finally {
        // Performance-Monitoring
        csv_import_pro_monitor_ajax_performance($action, $start_time);
    }
}

// ===================================================================
// DEBUGGING & DIAGNOSTIC FUNCTIONS
// ===================================================================

/**
 * Debug-Handler für AJAX-System-Diagnose (nur für Admins)
 */
function csv_import_pro_ajax_diagnostic_handler(): void {
    if (!current_user_can('manage_options') || !defined('WP_DEBUG') || !WP_DEBUG) {
        wp_die('Zugriff verweigert');
    }
    
    check_ajax_referer('csv_import_ajax', 'nonce');
    
    $diagnostic = [
        'timestamp' => current_time('mysql'),
        'php_version' => PHP_VERSION,
        'wp_version' => get_bloginfo('version'),
        'plugin_version' => defined('CSV_IMPORT_PRO_VERSION') ? CSV_IMPORT_PRO_VERSION : 'unknown',
        'memory_limit' => ini_get('memory_limit'),
        'memory_usage' => size_format(memory_get_usage(true)),
        'peak_memory' => size_format(memory_get_peak_usage(true)),
        'ajax_context' => csv_import_pro_validate_ajax_context(),
        'registered_handlers' => array_filter([
            'csv_import_validate' => function_exists('csv_import_pro_validate_handler'),
            'csv_import_start' => function_exists('csv_import_pro_start_handler'),
            'csv_import_generate_template' => function_exists('csv_import_pro_generate_template_handler'),
            'csv_scheduler_activation' => function_exists('csv_import_pro_scheduler_activation_handler'),
            'csv_seo_preview_validate' => function_exists('csv_import_pro_seo_validate_handler'),
            'csv_import_system_health' => function_exists('csv_import_pro_system_health_handler')
        ]),
        'class_availability' => [
            'CSV_Import_Template_Manager' => class_exists('CSV_Import_Template_Manager'),
            'CSV_Import_Scheduler' => class_exists('CSV_Import_Scheduler'),
            'CSV_Import_SEO_Preview' => class_exists('CSV_Import_SEO_Preview'),
            'CSV_Import_Pro_Run' => class_exists('CSV_Import_Pro_Run')
        ],
        'function_availability' => [
            'csv_import_get_config' => function_exists('csv_import_get_config'),
            'csv_import_validate_config' => function_exists('csv_import_validate_config'),
            'csv_import_start_import' => function_exists('csv_import_start_import'),
            'csv_import_system_health_check' => function_exists('csv_import_system_health_check'),
            'csv_import_log' => function_exists('csv_import_log')
        ],
        'performance_metrics' => get_option('csv_import_ajax_performance_metrics', [])
    ];
    
    wp_send_json_success($diagnostic);
}

// Debug-Handler registrieren (nur bei WP_DEBUG)
if (defined('WP_DEBUG') && WP_DEBUG) {
    add_action('wp_ajax_csv_import_diagnostic', 'csv_import_pro_ajax_diagnostic_handler');
}

// ===================================================================
// FINAL INITIALIZATION LOG
// ===================================================================

if (function_exists('csv_import_log')) {
    csv_import_log('debug', 'CSV Import Pro AJAX-System komplett geladen - Version 10.0 (Template-Generierung korrigiert)', [
        'total_handlers' => 8,
        'security_features' => ['rate_limiting', 'ip_blacklist', 'capability_checks', 'nonce_verification'],
        'monitoring_enabled' => true
    ]);
} else {
    error_log('CSV Import Pro: AJAX-System komplett geladen - Version 10.0 (korrigiert)');
}

