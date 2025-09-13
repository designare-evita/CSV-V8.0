<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Direkten Zugriff verhindern
}

/**
 * Verwaltet alle Operationen im Zusammenhang mit Import-Templates.
 * Version 2.2 - KORRIGIERTE VERSION für Template-Generierung
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
            
            // Bestimme die beste verfügbare Quelle
            $source = null;
            $validation = csv_import_validate_config($config);
            
            if ($validation['dropbox_ready']) {
                $source = 'dropbox';
            } elseif ($validation['local_ready']) {
                $source = 'local';
            } else {
                throw new Exception('Keine gültige CSV-Quelle konfiguriert oder verfügbar');
            }
            
            // CSV-Quelle validieren um Header zu bekommen
            $csv_validation = csv_import_validate_csv_source($source, $config);
            
            if (!$csv_validation['valid'] || empty($csv_validation['columns'])) {
                throw new Exception('CSV-Validierung fehlgeschlagen: ' . ($csv_validation['message'] ?? 'Unbekannter Fehler'));
            }
            
            $headers = $csv_validation['columns'];
            
        } catch (Exception $e) {
            return new WP_Error(
                'csv_read_error',
                'Fehler beim Lesen der CSV-Datei: ' . $e->getMessage()
            );
        }

        // 4. Platzhalter-Block für den Editor generieren
        $placeholder_block = "\n\n<!-- CSV Import Platzhalter -->\n";
        $placeholder_block .= "<!-- Diese Platzhalter werden beim Import durch die tatsächlichen CSV-Werte ersetzt -->\n\n";
        
        // Platzhalter in einer übersichtlichen Struktur anlegen
        $placeholder_block .= "<div class=\"csv-placeholders\" style=\"border: 2px dashed #ccc; padding: 20px; margin: 20px 0; background: #f9f9f9;\">\n";
        $placeholder_block .= "<h3>CSV-Daten Platzhalter</h3>\n";
        $placeholder_block .= "<p>Die folgenden Platzhalter werden beim Import automatisch durch die entsprechenden CSV-Werte ersetzt:</p>\n\n";
        
        foreach ($headers as $header) {
            if (!empty(trim($header))) {
                $clean_header = trim($header);
                $placeholder_block .= "<div class=\"csv-placeholder-item\">\n";
                $placeholder_block .= "<strong>" . esc_html($clean_header) . ":</strong> {{" . $clean_header . "}}\n";
                $placeholder_block .= "</div>\n";
            }
        }
        
        $placeholder_block .= "</div>\n\n";
        $placeholder_block .= "<!-- Ende der CSV Import Platzhalter -->\n\n";

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
            return $new_post_id;
        }

        // 7. Alle Metadaten vom Basis-Post zum neuen Post kopieren
        $meta_data = get_post_meta($base_post_id);
        if (!empty($meta_data) && is_array($meta_data)) {
            foreach ($meta_data as $meta_key => $meta_values) {
                // Bestimmte Meta-Keys überspringen
                if (in_array($meta_key, ['_wp_old_slug', '_edit_lock', '_edit_last'])) {
                    continue;
                }
                
                foreach ($meta_values as $meta_value) {
                    add_post_meta($new_post_id, $meta_key, maybe_unserialize($meta_value));
                }
            }
        }
        
        // Featured Image übertragen
        $thumbnail_id = get_post_thumbnail_id($base_post_id);
        if ($thumbnail_id) {
            set_post_thumbnail($new_post_id, $thumbnail_id);
        }
        
        // Template-spezifische Meta-Daten hinzufügen
        update_post_meta($new_post_id, '_csv_import_template', true);
        update_post_meta($new_post_id, '_csv_import_template_source', $base_post_id);
        update_post_meta($new_post_id, '_csv_import_csv_headers', $headers);
        update_post_meta($new_post_id, '_csv_import_created_at', current_time('mysql'));

        // Erfolgsmeldung loggen
        if (function_exists('csv_import_log')) {
            csv_import_log('info', 'Template erfolgreich generiert', [
                'template_id' => $new_post_id,
                'template_name' => $new_template_name,
                'base_post_id' => $base_post_id,
                'csv_source' => $source,
                'headers_count' => count($headers)
            ]);
        }

        return $new_post_id;
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
    
    /**
     * Debug-Funktion um verfügbare CSV-Header zu testen
     */
    public static function debug_get_csv_headers() {
        if (!function_exists('csv_import_get_config')) {
            return ['error' => 'csv_import_get_config Funktion nicht verfügbar'];
        }
        
        try {
            $config = csv_import_get_config();
            $validation = csv_import_validate_config($config);
            
            $debug_info = [
                'config_available' => true,
                'dropbox_ready' => $validation['dropbox_ready'] ?? false,
                'local_ready' => $validation['local_ready'] ?? false,
                'dropbox_url' => !empty($config['dropbox_url']) ? 'Konfiguriert' : 'Nicht konfiguriert',
                'local_path' => !empty($config['local_path']) ? $config['local_path'] : 'Nicht konfiguriert'
            ];
            
            // Versuche Header von verfügbaren Quellen zu holen
            if ($validation['dropbox_ready']) {
                $dropbox_result = csv_import_validate_csv_source('dropbox', $config);
                $debug_info['dropbox_headers'] = $dropbox_result['columns'] ?? [];
                $debug_info['dropbox_status'] = $dropbox_result['valid'] ? 'OK' : 'Fehler';
            }
            
            if ($validation['local_ready']) {
                $local_result = csv_import_validate_csv_source('local', $config);
                $debug_info['local_headers'] = $local_result['columns'] ?? [];
                $debug_info['local_status'] = $local_result['valid'] ? 'OK' : 'Fehler';
            }
            
            return $debug_info;
            
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
}
