<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Direkten Zugriff verhindern
}

class CSV_Import_Template_Manager {

    /**
     * Erstellt ein neues Template, indem es einen Basis-Post dupliziert
     * und alle Platzhalter aus der konfigurierten CSV-Datei anhängt.
     *
     * @param int    $base_post_id      Die ID des Posts, der als Design-Grundlage dient.
     * @param string $new_template_name Der Name des neuen Template-Posts.
     * @return int|WP_Error Die ID des neuen Posts oder ein WP_Error-Objekt bei einem Fehler.
     */
    public static function create_template_from_csv_headers(int $base_post_id, string $new_template_name): int|WP_Error {
        // 1. Prüfen, ob die benötigten Core-Funktionen existieren
        if (!function_exists('csv_import_get_config') || !function_exists('csv_import_load_csv_data')) {
            return new WP_Error(
                'missing_core_functions',
                'Benötigte Core-Funktionen sind nicht verfügbar. Bitte stellen Sie sicher, dass das Plugin korrekt installiert ist.'
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

        // 3. CSV-Header auslesen
        try {
            $config = csv_import_get_config();
            // Priorität für die Quellenauswahl: Dropbox, dann lokal.
            $source = !empty($config['dropbox_url']) && filter_var($config['dropbox_url'], FILTER_VALIDATE_URL) ? 'dropbox' : 'local';
            $csv_data = csv_import_load_csv_data($source, $config);

            if (empty($csv_data['headers'])) {
                return new WP_Error(
                    'no_csv_headers',
                    'Es konnten keine Spaltenüberschriften aus der CSV-Datei gelesen werden. Bitte prüfen Sie die Konfiguration und die CSV-Datei.'
                );
            }
            $headers = $csv_data['headers'];
        } catch (Exception $e) {
            return new WP_Error(
                'csv_read_error',
                'Fehler beim Lesen der CSV-Datei: ' . $e->getMessage()
            );
        }

        // 4. Platzhalter-Block für den Editor generieren
        $placeholder_block = "\n\n\n";
        $placeholder_block .= "\n\n";
        foreach ($headers as $header) {
            if (!empty(trim($header))) {
                 $placeholder_block .= '{{' . esc_html(trim($header)) . "}}\n";
            }
        }
        $placeholder_block .= "\n\n";

        // 5. Neuen Post-Datensatz vorbereiten
        $new_post_data = [
            'post_title'   => sanitize_text_field($new_template_name),
            'post_content' => $base_post->post_content . $placeholder_block,
            'post_status'  => 'draft', // Immer als Entwurf speichern, um versehentliches Veröffentlichen zu verhindern
            'post_type'    => $base_post->post_type,
            'post_author'  => get_current_user_id(),
        ];

        // 6. Neuen Post in die Datenbank einfügen
        $new_post_id = wp_insert_post($new_post_data, true);
        if (is_wp_error($new_post_id)) {
            return $new_post_id; // Gibt das WP_Error-Objekt direkt zurück
        }

        // 7. Alle Metadaten vom Basis-Post zum neuen Post kopieren
        $meta_data = get_post_meta($base_post_id);
        if (!empty($meta_data) && is_array($meta_data)) {
            foreach ($meta_data as $meta_key => $meta_values) {
                // Spezielle Metadaten überspringen, die von WordPress verwaltet werden und eindeutig sein müssen
                if (in_array($meta_key, ['_wp_old_slug', '_edit_lock', '_edit_last', '_thumbnail_id'])) {
                    continue;
                }
                foreach ($meta_values as $meta_value) {
                    add_post_meta($new_post_id, $meta_key, maybe_unserialize($meta_value));
                }
            }
        }
        
        // Featured Image vom Basis-Post kopieren, falls vorhanden
        $thumbnail_id = get_post_thumbnail_id($base_post_id);
        if ($thumbnail_id) {
            set_post_thumbnail($new_post_id, $thumbnail_id);
        }

        return $new_post_id;
    }

    /**
     * Wendet Platzhalter auf den Inhalt eines Templates an.
     * (Bestehende Funktionalität, beibehalten für Kompatibilität)
     *
     * @param int   $template_id Die ID des Template-Posts.
     * @param array $data        Die Datenzeile aus der CSV.
     * @return string|WP_Error Der verarbeitete Inhalt oder ein Fehler.
     */
    public static function apply_placeholders_to_content(int $template_id, array $data): string|WP_Error {
        $template_post = get_post($template_id);
        if (!$template_post) {
            return new WP_Error('template_not_found', 'Template mit ID ' . $template_id . ' nicht gefunden.');
        }

        $content = $template_post->post_content;
        foreach ($data as $key => $value) {
            $placeholder = '{{' . trim($key) . '}}';
            $content = str_replace($placeholder, wp_kses_post($value), $content);
        }
        
        return $content;
    }
// ===================================================================
// TEMPLATE MANAGEMENT SYSTEM
// ===================================================================

class CSV_Import_Template_Manager {
    
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
    
    public static function get_templates() {
        return get_option('csv_import_templates', []);
    }
    
    public static function get_template($template_id) {
        $templates = self::get_templates();
        return $templates[$template_id] ?? null;
    }
    
    public static function delete_template($template_id) {
        $templates = self::get_templates();
        if (isset($templates[$template_id])) {
            unset($templates[$template_id]);
            update_option('csv_import_templates', $templates);
            return true;
        }
        return false;
    }
    
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
