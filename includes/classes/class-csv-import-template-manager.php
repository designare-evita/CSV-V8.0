<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Direkten Zugriff verhindern
}

/**
 * Verwaltet alle Operationen im Zusammenhang mit Import-Templates.
 * Version 3.0 - Komplett korrigierte Version für Template-Generierung
 */
class CSV_Import_Template_Manager {

    /**
     * Erstellt ein neues Template, indem es einen Basis-Post dupliziert
     * und alle Platzhalter aus der konfigurierten CSV-Datei anhängt.
     *
     * @param int    $base_post_id      Die ID des Posts, der als Design-Grundlage dient.
     * @param string $new_template_name Der Name des neuen Template-Posts.
     * @return int|WP_Error Die ID des neuen Posts oder ein WP_Error-Objekt bei einem Fehler.
     */
    public static function create_template_from_csv_headers(int $base_post_id, string $new_template_name) {
        // 1. Prüfen, ob die benötigten Core-Funktionen existieren
        if (!function_exists('csv_import_get_config')) {
            return new WP_Error(
                'missing_core_functions',
                'csv_import_get_config Funktion ist nicht verfügbar. Bitte stellen Sie sicher, dass das Plugin korrekt installiert ist.'
            );
        }

        if (!function_exists('csv_import_validate_config')) {
            return new WP_Error(
                'missing_validation_function',
                'csv_import_validate_config Funktion ist nicht verfügbar.'
            );
        }

        if (!function_exists('csv_import_validate_csv_source')) {
            return new WP_Error(
                'missing_csv_validation_function',
                'csv_import_validate_csv_source Funktion ist nicht verfügbar.'
            );
        }

        // 2. Basis-Post abrufen und validieren
        $base_post = get_post($base_post_id);
        if (!$base_post) {
            return new WP_Error(
                'base_post_not_found',
                'Der Basis-Post mit der ID ' . esc_html($base_post_id) . ' wurde nicht gefunden.'
            );
        }

        // 3. CSV-Header direkt über Konfiguration und Validierung auslesen
        try {
            $config = csv_import_get_config();
            
            // Plugin-Konfiguration validieren
            $validation = csv_import_validate_config($config);
            
            if (!$validation['valid']) {
                $error_details = !empty($validation['errors']) ? implode(', ', $validation['errors']) : 'Unbekannte Konfigurationsfehler';
                throw new Exception('Plugin-Konfiguration ungültig: ' . $error_details);
            }
            
            // Bestimme die beste verfügbare CSV-Quelle
            $source = null;
            $source_name = '';
            
            if ($validation['dropbox_ready']) {
                $source = 'dropbox';
                $source_name = 'Dropbox';
            } elseif ($validation['local_ready']) {
                $source = 'local';
                $source_name = 'Lokale Datei';
            } else {
                throw new Exception('Keine gültige CSV-Quelle konfiguriert oder verfügbar. Bitte konfigurieren Sie eine Dropbox-URL oder einen lokalen CSV-Pfad in den Plugin-Einstellungen.');
            }
            
            // CSV-Quelle validieren um Header zu bekommen
            $csv_validation = csv_import_validate_csv_source($source, $config);
            
            if (!$csv_validation['valid']) {
                $error_msg = $csv_validation['message'] ?? 'Unbekannter CSV-Validierungsfehler';
                throw new Exception("CSV-Validierung für {$source_name} fehlgeschlagen: " . $error_msg);
            }
            
            if (empty($csv_validation['columns'])) {
                throw new Exception("Keine Spalten-Header in der {$source_name} CSV-Datei gefunden.");
            }
            
            $headers = $csv_validation['columns'];
            
        } catch (Exception $e) {
            return new WP_Error(
                'csv_processing_error',
                'Fehler beim Verarbeiten der CSV-Datei: ' . $e->getMessage()
            );
        }

        // 4. Platzhalter-Block für den Editor generieren
        $placeholder_block = self::generate_placeholder_block($headers, $source_name);

        // 5. Neuen Post-Datensatz vorbereiten
        $new_post_data = [
            'post_title'   => sanitize_text_field($new_template_name),
            'post_content' => $base_post->post_content . $placeholder_block,
            'post_status'  => 'draft',
            'post_type'    => $base_post->post_type,
            'post_author'  => get_current_user_id(),
            'comment_status' => $base_post->comment_status,
            'ping_status'    => $base_post->ping_status,
        ];

        // 6. Neuen Post in die Datenbank einfügen
        $new_post_id = wp_insert_post($new_post_data, true);
        if (is_wp_error($new_post_id)) {
            return new WP_Error(
                'post_creation_failed',
                'WordPress konnte den neuen Post nicht erstellen: ' . $new_post_id->get_error_message()
            );
        }

        // 7. Alle Metadaten vom Basis-Post zum neuen Post kopieren
        self::copy_post_metadata($base_post_id, $new_post_id);
        
        // 8. Template-spezifische Meta-Daten hinzufügen
        self::add_template_metadata($new_post_id, $base_post_id, $headers, $source);
        
        // 9. Erfolg loggen
        self::log_template_creation($new_post_id, $new_template_name, $base_post_id, $source, count($headers));

        return $new_post_id;
    }

    /**
     * Generiert den Platzhalter-Block für das Template
     */
    private static function generate_placeholder_block(array $headers, string $source_name): string {
        $placeholder_block = "\n\n<!-- =================================== -->\n";
        $placeholder_block .= "<!-- CSV Import Template Platzhalter -->\n";
        $placeholder_block .= "<!-- Quelle: {$source_name} -->\n";
        $placeholder_block .= "<!-- Generiert am: " . current_time('Y-m-d H:i:s') . " -->\n";
        $placeholder_block .= "<!-- =================================== -->\n\n";
        
        // Visueller Container für Gutenberg/Page Builder
        $placeholder_block .= '<div class="csv-import-placeholders" style="border: 3px dashed #0073aa; padding: 25px; margin: 30px 0; background: linear-gradient(135deg, #f0f6fc 0%, #e8f4fd 100%); border-radius: 8px; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif;">' . "\n";
        $placeholder_block .= '<h2 style="color: #0073aa; margin: 0 0 15px 0; font-size: 24px;">🔗 CSV Import Platzhalter</h2>' . "\n";
        $placeholder_block .= '<p style="margin: 0 0 20px 0; color: #646970; font-size: 16px;">Diese Platzhalter werden beim Import automatisch durch die entsprechenden CSV-Werte ersetzt:</p>' . "\n\n";
        
        $placeholder_block .= '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px;">' . "\n";
        
        foreach ($headers as $index => $header) {
            if (!empty(trim($header))) {
                $clean_header = trim($header);
                $placeholder_block .= '<div style="background: white; padding: 15px; border-radius: 6px; border-left: 4px solid #00a32a; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">' . "\n";
                $placeholder_block .= '<div style="display: flex; align-items: center; margin-bottom: 8px;">' . "\n";
                $placeholder_block .= '<span style="background: #00a32a; color: white; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: bold; margin-right: 10px;">' . ($index + 1) . '</span>' . "\n";
                $placeholder_block .= '<strong style="color: #1d2327; font-size: 14px;">' . esc_html($clean_header) . '</strong>' . "\n";
                $placeholder_block .= '</div>' . "\n";
                $placeholder_block .= '<code style="background: #f6f7f7; padding: 8px 12px; border-radius: 4px; font-size: 13px; color: #d63638; font-weight: 500; display: block; font-family: Consolas, Monaco, monospace;">{{' . $clean_header . '}}</code>' . "\n";
                $placeholder_block .= '</div>' . "\n";
            }
        }
        
        $placeholder_block .= '</div>' . "\n\n";
        
        // Anweisungen
        $placeholder_block .= '<div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 6px; margin-top: 20px;">' . "\n";
        $placeholder_block .= '<h4 style="color: #856404; margin: 0 0 10px 0; font-size: 16px;">📝 Verwendung:</h4>' . "\n";
        $placeholder_block .= '<ul style="margin: 0; padding-left: 20px; color: #856404;">' . "\n";
        $placeholder_block .= '<li>Kopieren Sie die gewünschten Platzhalter (z.B. <code>{{post_title}}</code>) in Ihr Template</li>' . "\n";
        $placeholder_block .= '<li>Beim Import werden sie automatisch durch die CSV-Werte ersetzt</li>' . "\n";
        $placeholder_block .= '<li>Sie können diesen Block nach dem Kopieren löschen</li>' . "\n";
        $placeholder_block .= '<li>Häufig verwendete Platzhalter: <code>{{post_title}}</code>, <code>{{post_content}}</code>, <code>{{post_excerpt}}</code></li>' . "\n";
        $placeholder_block .= '</ul>' . "\n";
        $placeholder_block .= '</div>' . "\n";
        
        $placeholder_block .= '</div>' . "\n\n";
        
        $placeholder_block .= "<!-- Ende CSV Import Platzhalter -->\n\n";

        return $placeholder_block;
    }

    /**
     * Kopiert Metadaten vom Basis-Post zum neuen Template
     */
    private static function copy_post_metadata(int $source_post_id, int $target_post_id): void {
        $meta_data = get_post_meta($source_post_id);
        
        if (!empty($meta_data) && is_array($meta_data)) {
            // Meta-Keys die NICHT kopiert werden sollen
            $skip_meta_keys = [
                '_wp_old_slug',
                '_edit_lock',
                '_edit_last',
                '_wp_desired_post_slug',
                '_edit_lock_time'
            ];
            
            foreach ($meta_data as $meta_key => $meta_values) {
                if (in_array($meta_key, $skip_meta_keys)) {
                    continue;
                }
                
                foreach ($meta_values as $meta_value) {
                    add_post_meta($target_post_id, $meta_key, maybe_unserialize($meta_value));
                }
            }
        }
        
        // Featured Image übertragen
        $thumbnail_id = get_post_thumbnail_id($source_post_id);
        if ($thumbnail_id) {
            set_post_thumbnail($target_post_id, $thumbnail_id);
        }
    }

    /**
     * Fügt Template-spezifische Metadaten hinzu
     */
    private static function add_template_metadata(int $post_id, int $source_id, array $headers, string $source_type): void {
        update_post_meta($post_id, '_csv_import_template', true);
        update_post_meta($post_id, '_csv_import_template_source_post', $source_id);
        update_post_meta($post_id, '_csv_import_template_csv_source', $source_type);
        update_post_meta($post_id, '_csv_import_template_headers', $headers);
        update_post_meta($post_id, '_csv_import_template_created_at', current_time('mysql'));
        update_post_meta($post_id, '_csv_import_template_created_by', get_current_user_id());
        update_post_meta($post_id, '_csv_import_template_version', '3.0');
        update_post_meta($post_id, '_csv_import_template_header_count', count($headers));
    }

    /**
     * Loggt die Template-Erstellung
     */
    private static function log_template_creation(int $template_id, string $template_name, int $base_id, string $source, int $header_count): void {
        if (function_exists('csv_import_log')) {
            csv_import_log('info', 'Template erfolgreich aus CSV-Headern generiert', [
                'template_id' => $template_id,
                'template_name' => $template_name,
                'base_post_id' => $base_id,
                'csv_source' => $source,
                'headers_count' => $header_count,
                'user_id' => get_current_user_id(),
                'user_login' => wp_get_current_user()->user_login ?? 'unknown'
            ]);
        }
    }

    /**
     * Wendet Platzhalter auf den Inhalt eines Templates an.
     *
     * @param int   $template_id Die ID des Template-Posts.
     * @param array $data        Die Datenzeile aus der CSV.
     * @return string|WP_Error Der verarbeitete Inhalt oder ein Fehler.
     */
    public static function apply_placeholders_to_content(int $template_id, array $data) {
        $template_post = get_post($template_id);
        if (!$template_post) {
            return new WP_Error('template_not_found', 'Template mit ID ' . $template_id . ' nicht gefunden.');
        }

        $content = $template_post->post_content;
        
        // Platzhalter ersetzen
        foreach ($data as $key => $value) {
            $placeholder = '{{' . trim($key) . '}}';
            $content = str_replace($placeholder, wp_kses_post($value), $content);
        }
        
        return $content;
    }
    
    /**
     * Debug-Funktion um verfügbare CSV-Header zu testen
     */
    public static function debug_get_csv_headers() {
        if (!function_exists('csv_import_get_config')) {
            return ['error' => 'csv_import_get_config Funktion nicht verfügbar'];
        }
        
        if (!function_exists('csv_import_validate_config')) {
            return ['error' => 'csv_import_validate_config Funktion nicht verfügbar'];
        }
        
        if (!function_exists('csv_import_validate_csv_source')) {
            return ['error' => 'csv_import_validate_csv_source Funktion nicht verfügbar'];
        }
        
        try {
            $config = csv_import_get_config();
            $validation = csv_import_validate_config($config);
            
            $debug_info = [
                'config_available' => true,
                'config_valid' => $validation['valid'],
                'dropbox_ready' => $validation['dropbox_ready'] ?? false,
                'local_ready' => $validation['local_ready'] ?? false,
                'dropbox_url' => !empty($config['dropbox_url']) ? 'Konfiguriert (' . strlen($config['dropbox_url']) . ' Zeichen)' : 'Nicht konfiguriert',
                'local_path' => !empty($config['local_path']) ? $config['local_path'] : 'Nicht konfiguriert',
                'validation_errors' => $validation['errors'] ?? []
            ];
            
            // Versuche Header von verfügbaren Quellen zu holen
            if ($validation['dropbox_ready']) {
                try {
                    $dropbox_result = csv_import_validate_csv_source('dropbox', $config);
                    $debug_info['dropbox_headers'] = $dropbox_result['columns'] ?? [];
                    $debug_info['dropbox_status'] = $dropbox_result['valid'] ? 'OK' : 'Fehler';
                    $debug_info['dropbox_message'] = $dropbox_result['message'] ?? '';
                    $debug_info['dropbox_rows'] = $dropbox_result['rows'] ?? 0;
                } catch (Exception $e) {
                    $debug_info['dropbox_status'] = 'Exception: ' . $e->getMessage();
                }
            }
            
            if ($validation['local_ready']) {
                try {
                    $local_result = csv_import_validate_csv_source('local', $config);
                    $debug_info['local_headers'] = $local_result['columns'] ?? [];
                    $debug_info['local_status'] = $local_result['valid'] ? 'OK' : 'Fehler';
                    $debug_info['local_message'] = $local_result['message'] ?? '';
                    $debug_info['local_rows'] = $local_result['rows'] ?? 0;
                } catch (Exception $e) {
                    $debug_info['local_status'] = 'Exception: ' . $e->getMessage();
                }
            }
            
            // Zusätzliche Systeminfo
            $debug_info['system_info'] = [
                'wordpress_version' => get_bloginfo('version'),
                'php_version' => PHP_VERSION,
                'plugin_version' => defined('CSV_IMPORT_PRO_VERSION') ? CSV_IMPORT_PRO_VERSION : 'unknown',
                'current_user' => get_current_user_id(),
                'memory_usage' => size_format(memory_get_usage(true)),
                'timestamp' => current_time('mysql')
            ];
            
            return $debug_info;
            
        } catch (Exception $e) {
            return [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ];
        }
    }

    /**
     * Holt Template-Informationen für Admin-Anzeige
     */
    public static function get_template_info(int $template_id): array {
        $post = get_post($template_id);
        if (!$post) {
            return ['error' => 'Template nicht gefunden'];
        }

        $info = [
            'id' => $template_id,
            'title' => $post->post_title,
            'status' => $post->post_status,
            'type' => $post->post_type,
            'created' => $post->post_date,
            'modified' => $post->post_modified,
            'is_csv_template' => (bool) get_post_meta($template_id, '_csv_import_template', true),
            'source_post_id' => get_post_meta($template_id, '_csv_import_template_source_post', true),
            'csv_source' => get_post_meta($template_id, '_csv_import_template_csv_source', true),
            'headers' => get_post_meta($template_id, '_csv_import_template_headers', true),
            'header_count' => get_post_meta($template_id, '_csv_import_template_header_count', true),
            'template_version' => get_post_meta($template_id, '_csv_import_template_version', true),
            'edit_link' => get_edit_post_link($template_id),
            'view_link' => get_permalink($template_id)
        ];

        return $info;
    }

    /**
     * Erstellt ein Template aus einem bestehenden Post (Legacy-Funktion)
     */
    public static function create_template_from_post($post_id, $template_name) {
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('post_not_found', 'Post nicht gefunden');
        }
        
        $template_data = [
            'name' => sanitize_text_field($template_name),
            'post_type' => $post->post_type,
            'post_content' => $post->post_content,
            'meta_data' => get_post_meta($post_id),
            'created_at' => current_time('mysql'),
            'created_by' => get_current_user_id()
        ];
        
        $templates = get_option('csv_import_templates', []);
        $template_id = uniqid('tpl_');
        $templates[$template_id] = $template_data;
        
        update_option('csv_import_templates', $templates);
        
        return $template_id;
    }
    
    /**
     * Holt alle gespeicherten Templates
     */
    public static function get_templates() {
        return get_option('csv_import_templates', []);
    }
    
    /**
     * Holt ein spezifisches Template
     */
    public static function get_template($template_id) {
        $templates = self::get_templates();
        return $templates[$template_id] ?? null;
    }
    
    /**
     * Löscht ein Template
     */
    public static function delete_template($template_id) {
        $templates = self::get_templates();
        if (isset($templates[$template_id])) {
            unset($templates[$template_id]);
            update_option('csv_import_templates', $templates);
            return true;
        }
        return false;
    }
    
    /**
     * Wendet ein Template auf Daten an
     */
    public static function apply_template($template_id, $data) {
        $template = self::get_template($template_id);
        if (!$template) {
            return new WP_Error('template_not_found', 'Template nicht gefunden');
        }
        
        // Template-Content mit Daten befüllen
        $content = $template['post_content'];
        foreach ($data as $key => $value) {
            $content = str_replace('{{' . $key . '}}', wp_kses_post($value), $content);
        }
        
        return [
            'post_type' => $template['post_type'],
            'post_content' => $content,
            'meta_data' => $template['meta_data']
        ];
    }
}
