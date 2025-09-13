<?php

if ( ! defined( 'ABSPATH' ) ) {

	exit; // Direkten Zugriff verhindern
}

/**
 * Verwaltet alle Operationen im Zusammenhang mit Import-Templates.
 * Version 5.0 - GUTENBERG/BLOCK EDITOR KOMPATIBLE VERSION
 */
class CSV_Import_Template_Manager {

    /**
     * Erstellt ein neues Template mit Block Editor kompatiblen Platzhaltern
     */
    public static function create_template_from_csv_headers(int $base_post_id, string $new_template_name) {
        // Basis-Validierung bleibt gleich
        if (!function_exists('csv_import_get_config')) {
            return new WP_Error(
                'missing_core_functions',
                'csv_import_get_config Funktion ist nicht verfügbar. Bitte stellen Sie sicher, dass das Plugin korrekt installiert ist.'
            );
        }

        $base_post = get_post($base_post_id);
        if (!$base_post) {
            return new WP_Error(
                'base_post_not_found',
                'Der Basis-Post mit der ID ' . esc_html($base_post_id) . ' wurde nicht gefunden.'
            );
        }

        // CSV-Header laden (bestehende Logik)
        try {
            $config = csv_import_get_config();
            $validation = csv_import_validate_config($config);
            
            if (!$validation['valid']) {
                $error_details = !empty($validation['errors']) ? implode(', ', $validation['errors']) : 'Unbekannte Konfigurationsfehler';
                throw new Exception('Plugin-Konfiguration ungültig: ' . $error_details);
            }
            
            $source = null;
            $source_name = '';
            
            if ($validation['dropbox_ready']) {
                $source = 'dropbox';
                $source_name = 'Dropbox';
            } elseif ($validation['local_ready']) {
                $source = 'local';
                $source_name = 'Lokale Datei';
            } else {
                throw new Exception('Keine gültige CSV-Quelle konfiguriert.');
            }
            
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

        // KORRIGIERT: Block Editor kompatible Platzhalter generieren
        $placeholder_content = self::generate_gutenberg_compatible_placeholders($headers, $source_name);

        // Neuen Post-Datensatz vorbereiten
        $new_post_data = [
            'post_title'   => sanitize_text_field($new_template_name),
            'post_content' => $base_post->post_content . $placeholder_content,
            'post_status'  => 'draft',
            'post_type'    => $base_post->post_type,
            'post_author'  => get_current_user_id(),
            'comment_status' => $base_post->comment_status,
            'ping_status'    => $base_post->ping_status,
        ];

        // Post erstellen
        $new_post_id = wp_insert_post($new_post_data, true);
        if (is_wp_error($new_post_id)) {
            return new WP_Error(
                'post_creation_failed',
                'WordPress konnte den neuen Post nicht erstellen: ' . $new_post_id->get_error_message()
            );
        }

        // Metadaten kopieren und hinzufügen
        self::copy_post_metadata($base_post_id, $new_post_id);
        self::add_template_metadata($new_post_id, $base_post_id, $headers, $source);
        
        // Erfolg loggen
        self::log_template_creation($new_post_id, $new_template_name, $base_post_id, $source, count($headers));

        return $new_post_id;
    }

    /**
     * 🔥 KORRIGIERTE FUNKTION: Generiert Gutenberg/Block Editor kompatible Platzhalter
     * Diese Version überlebt den Block Editor!
     */
    private static function generate_gutenberg_compatible_placeholders(array $headers, string $source_name): string {
        $current_time = current_time('Y-m-d H:i:s');
        
        // LÖSUNG 1: Verwende HTML-Block mit korrekter Gutenberg-Syntax
        $block_content = "\n\n<!-- wp:html -->\n";
        $block_content .= '<div id="csv-import-placeholders-' . uniqid() . '" class="csv-import-placeholders-widget" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; margin: 30px 0; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif;">' . "\n";
        
        // Header-Bereich mit modernem Design
        $block_content .= '<div style="text-align: center; margin-bottom: 30px;">' . "\n";
        $block_content .= '<h2 style="color: white; margin: 0 0 10px 0; font-size: 32px; font-weight: 700; text-shadow: 0 2px 4px rgba(0,0,0,0.3);"><span style="margin-right: 10px;">🚀</span>CSV Import Platzhalter</h2>' . "\n";
        $block_content .= '<p style="margin: 0; opacity: 0.9; font-size: 18px;">Quelle: ' . esc_html($source_name) . ' | Generiert: ' . $current_time . '</p>' . "\n";
        $block_content .= '</div>' . "\n";
        
        // Info-Box mit Anweisungen
        $block_content .= '<div style="background: rgba(255,255,255,0.15); border-radius: 10px; padding: 20px; margin-bottom: 30px; backdrop-filter: blur(10px);">' . "\n";
        $block_content .= '<h3 style="color: white; margin: 0 0 15px 0; font-size: 20px;">💡 Verwendung:</h3>' . "\n";
        $block_content .= '<ol style="margin: 0; padding-left: 20px; color: white; line-height: 1.8;">' . "\n";
        $block_content .= '<li><strong>Markieren</strong> Sie den gewünschten Platzhalter mit der Maus</li>' . "\n";
        $block_content .= '<li><strong>Kopieren</strong> Sie ihn (Strg+C / Cmd+C)</li>' . "\n";
        $block_content .= '<li><strong>Fügen Sie ihn</strong> in Ihr Template ein (Strg+V / Cmd+V)</li>' . "\n";
        $block_content .= '<li>Beim Import werden alle Platzhalter automatisch durch CSV-Werte ersetzt</li>' . "\n";
        $block_content .= '</ol>' . "\n";
        $block_content .= '</div>' . "\n";
        
        // Kopier-Button für alle Platzhalter
        $all_placeholders = array_map(function($header) {
            return '{{' . trim($header) . '}}';
        }, array_filter($headers, 'trim'));
        
        $all_placeholders_text = implode(' ', $all_placeholders);
        
        $block_content .= '<div style="text-align: center; margin-bottom: 30px;">' . "\n";
        $block_content .= '<button type="button" onclick="navigator.clipboard.writeText(\'' . esc_js($all_placeholders_text) . '\').then(function(){alert(\'✅ Alle ' . count($all_placeholders) . ' Platzhalter kopiert!\');}).catch(function(){prompt(\'Kopieren Sie diesen Text:\', \'' . esc_js($all_placeholders_text) . '\');});" style="background: linear-gradient(45deg, #ff6b6b, #feca57); color: white; border: none; padding: 15px 30px; border-radius: 50px; font-size: 16px; font-weight: 600; cursor: pointer; box-shadow: 0 4px 15px rgba(0,0,0,0.2); transition: transform 0.2s;" onmouseover="this.style.transform=\'translateY(-2px)\'" onmouseout="this.style.transform=\'translateY(0)\'">📋 ALLE PLATZHALTER KOPIEREN</button>' . "\n";
        $block_content .= '</div>' . "\n";
        
        // Platzhalter-Grid
        $block_content .= '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 30px;">' . "\n";
        
        foreach ($headers as $index => $header) {
            if (!empty(trim($header))) {
                $clean_header = trim($header);
                $placeholder = '{{' . $clean_header . '}}';
                $usage_hint = self::get_usage_hint($clean_header);
                
                $block_content .= '<div style="background: rgba(255,255,255,0.1); border-radius: 10px; padding: 20px; backdrop-filter: blur(5px); border: 1px solid rgba(255,255,255,0.2);">' . "\n";
                
                // Header mit Nummer
                $block_content .= '<div style="display: flex; align-items: center; margin-bottom: 15px;">' . "\n";
                $block_content .= '<div style="background: #4ecdc4; color: white; border-radius: 50%; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; font-weight: bold; margin-right: 15px; flex-shrink: 0;">' . ($index + 1) . '</div>' . "\n";
                $block_content .= '<h4 style="color: white; margin: 0; font-size: 16px; font-weight: 600;">' . esc_html($clean_header) . '</h4>' . "\n";
                $block_content .= '</div>' . "\n";
                
                // Platzhalter-Code (selektierbar)
                $block_content .= '<div style="background: rgba(0,0,0,0.3); border: 1px dashed rgba(255,255,255,0.3); border-radius: 8px; padding: 15px; margin-bottom: 10px; position: relative;">' . "\n";
                $block_content .= '<code style="color: #feca57; font-size: 14px; font-weight: 600; word-break: break-all; user-select: all; background: transparent; padding: 0;">' . esc_html($placeholder) . '</code>' . "\n";
                
                // Kopier-Button für einzelnen Platzhalter
                $block_content .= '<button type="button" onclick="navigator.clipboard.writeText(\'' . esc_js($placeholder) . '\').then(function(){this.textContent=\'✅ KOPIERT!\'; setTimeout(() => this.textContent=\'📋 KOPIEREN\', 2000);}.bind(this)).catch(function(){prompt(\'Kopieren Sie diesen Text:\', \'' . esc_js($placeholder) . '\');});" style="position: absolute; top: 5px; right: 5px; background: #4ecdc4; color: white; border: none; padding: 5px 10px; border-radius: 5px; font-size: 11px; font-weight: 600; cursor: pointer;">📋 KOPIEREN</button>' . "\n";
                $block_content .= '</div>' . "\n";
                
                // Verwendungshinweis
                if ($usage_hint) {
                    $block_content .= '<p style="margin: 0; font-size: 13px; color: rgba(255,255,255,0.8); line-height: 1.4;"><strong>💡 Verwendung:</strong> ' . esc_html($usage_hint) . '</p>' . "\n";
                }
                
                $block_content .= '</div>' . "\n";
            }
        }
        
        $block_content .= '</div>' . "\n";
        
        // Häufige Platzhalter (Quick Access)
        $common_placeholders = self::get_common_placeholders($headers);
        if (!empty($common_placeholders)) {
            $block_content .= '<div style="background: rgba(255,255,255,0.1); border-radius: 10px; padding: 20px; margin-bottom: 20px; backdrop-filter: blur(5px);">' . "\n";
            $block_content .= '<h4 style="color: white; margin: 0 0 15px 0; font-size: 18px;">⚡ Häufig verwendete Platzhalter:</h4>' . "\n";
            $block_content .= '<div style="display: flex; flex-wrap: wrap; gap: 10px;">' . "\n";
            
            foreach ($common_placeholders as $placeholder) {
                $block_content .= '<button type="button" onclick="navigator.clipboard.writeText(\'' . esc_js($placeholder) . '\').then(function(){this.style.background=\'#00a32a\'; this.textContent=\'✅ KOPIERT\'; setTimeout(() => {this.style.background=\'#4ecdc4\'; this.textContent=\'' . esc_js($placeholder) . '\';}, 2000);}).catch(function(){prompt(\'Kopieren Sie diesen Text:\', \'' . esc_js($placeholder) . '\');});" style="background: #4ecdc4; color: white; border: none; padding: 8px 15px; border-radius: 20px; font-size: 13px; font-weight: 500; cursor: pointer; transition: all 0.2s;">' . esc_html($placeholder) . '</button>' . "\n";
            }
            
            $block_content .= '</div>' . "\n";
            $block_content .= '</div>' . "\n";
        }
        
        // Lösch-Hinweis
        $block_content .= '<div style="text-align: center; opacity: 0.8;">' . "\n";
        $block_content .= '<p style="margin: 0; font-size: 14px;">🗑️ Dieser Platzhalter-Block kann nach dem Kopieren der gewünschten Platzhalter gelöscht werden.</p>' . "\n";
        $block_content .= '</div>' . "\n";
        
        $block_content .= '</div>' . "\n";
        $block_content .= "<!-- /wp:html -->\n\n";
        
        // LÖSUNG 2: Zusätzlich ein einfacher Text-Block mit allen Platzhaltern
        $block_content .= "<!-- wp:heading -->\n";
        $block_content .= "<h2 class=\"wp-block-heading\">📝 Verfügbare CSV-Platzhalter (Backup-Liste)</h2>\n";
        $block_content .= "<!-- /wp:heading -->\n\n";
        
        $block_content .= "<!-- wp:paragraph -->\n";
        $block_content .= "<p><strong>Alle " . count($headers) . " verfügbaren Platzhalter:</strong></p>\n";
        $block_content .= "<!-- /wp:paragraph -->\n\n";
        
        // Platzhalter als normaler Text (falls HTML-Block nicht funktioniert)
        $block_content .= "<!-- wp:code -->\n";
        $block_content .= "<pre class=\"wp-block-code\"><code>";
        foreach ($headers as $header) {
            if (!empty(trim($header))) {
                $block_content .= '{{' . esc_html(trim($header)) . '}}, ';
            }
        }
        $block_content = rtrim($block_content, ', ');
        $block_content .= "</code></pre>\n";
        $block_content .= "<!-- /wp:code -->\n\n";

        return $block_content;
    }

    /**
     * Gibt Verwendungshinweise für spezielle Platzhalter
     */
    private static function get_usage_hint(string $header): string {
        $hints = [
            'post_title' => 'Wird als Seitentitel verwendet',
            'title' => 'Wird als Seitentitel verwendet',
            'post_content' => 'Hauptinhalt der Seite',
            'content' => 'Hauptinhalt der Seite',
            'post_excerpt' => 'Kurzbeschreibung/Zusammenfassung',
            'excerpt' => 'Kurzbeschreibung/Zusammenfassung',
            'featured_image' => 'URL zum Beitragsbild',
            'image' => 'URL zu einem Bild',
            'seo_title' => 'SEO-Titel für Suchmaschinen',
            'seo_description' => 'Meta-Description für SEO',
            'price' => 'Ideal für Preisangaben',
            'button_text' => 'Text für Call-to-Action Buttons',
            'link' => 'URL für Links',
            'url' => 'URL für Links',
            'phone' => 'Telefonnummer',
            'email' => 'E-Mail-Adresse',
            'address' => 'Adressangaben',
            'date' => 'Datumsangaben'
        ];
        
        $lower_header = strtolower($header);
        
        // Exakte Übereinstimmung
        if (isset($hints[$lower_header])) {
            return $hints[$lower_header];
        }
        
        // Teilweise Übereinstimmung
        foreach ($hints as $key => $hint) {
            if (strpos($lower_header, $key) !== false) {
                return $hint;
            }
        }
        
        return '';
    }

    /**
     * Ermittelt häufig verwendete Platzhalter
     */
    private static function get_common_placeholders(array $headers): array {
        $common_fields = ['post_title', 'title', 'post_content', 'content', 'post_excerpt', 'excerpt', 'featured_image', 'image', 'button_text', 'price', 'link', 'url'];
        $found_common = [];
        
        foreach ($headers as $header) {
            $lower_header = strtolower(trim($header));
            if (in_array($lower_header, $common_fields)) {
                $found_common[] = '{{' . trim($header) . '}}';
            }
        }
        
        return array_slice($found_common, 0, 8); // Maximal 8 häufige Platzhalter
    }

    /**
     * Kopiert Metadaten vom Basis-Post zum neuen Template
     */
    private static function copy_post_metadata(int $source_post_id, int $target_post_id): void {
        $meta_data = get_post_meta($source_post_id);
        
        if (!empty($meta_data) && is_array($meta_data)) {
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
        update_post_meta($post_id, '_csv_import_template_version', '5.0-gutenberg-compatible');
        update_post_meta($post_id, '_csv_import_template_header_count', count($headers));
    }

    /**
     * Loggt die Template-Erstellung
     */
    private static function log_template_creation(int $template_id, string $template_name, int $base_id, string $source, int $header_count): void {
        if (function_exists('csv_import_log')) {
            csv_import_log('info', 'Block Editor kompatibles Template erfolgreich generiert', [
                'template_id' => $template_id,
                'template_name' => $template_name,
                'base_post_id' => $base_id,
                'csv_source' => $source,
                'headers_count' => $header_count,
                'user_id' => get_current_user_id(),
                'user_login' => wp_get_current_user()->user_login ?? 'unknown',
                'version' => '5.0-gutenberg-compatible'
            ]);
        }
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

    // ===================================================================
    // 🔥 NEUE FEATURES: Template-Verwaltung und Hilfsfunktionen
    // ===================================================================

    /**
     * 🔥 NEUE FUNKTION: Erstellt einen Platzhalter-Cheatsheet als Download
     */
    public static function generate_placeholder_cheatsheet(array $headers): string {
        $cheatsheet = "# CSV Import Platzhalter Cheatsheet\n";
        $cheatsheet .= "Erstellt am: " . current_time('d.m.Y H:i:s') . "\n";
        $cheatsheet .= "Anzahl Platzhalter: " . count($headers) . "\n\n";
        
        $cheatsheet .= "## Alle verfügbaren Platzhalter:\n\n";
        
        foreach ($headers as $index => $header) {
            if (!empty(trim($header))) {
                $clean_header = trim($header);
                $placeholder = '{{' . $clean_header . '}}';
                $usage_hint = self::get_usage_hint($clean_header);
                
                $cheatsheet .= sprintf(
                    "%d. %s\n   Platzhalter: %s\n   %s\n\n",
                    $index + 1,
                    $clean_header,
                    $placeholder,
                    $usage_hint ? "Verwendung: " . $usage_hint : "Allgemeiner Platzhalter"
                );
            }
        }
        
        $cheatsheet .= "\n## Verwendung:\n";
        $cheatsheet .= "1. Kopieren Sie die gewünschten Platzhalter\n";
        $cheatsheet .= "2. Fügen Sie sie in Ihr Template ein\n";
        $cheatsheet .= "3. Beim Import werden sie automatisch ersetzt\n";
        
        return $cheatsheet;
    }

    /**
     * 🔥 NEUE FUNKTION: Prüft ob ein Template CSV-Platzhalter enthält
     */
    public static function template_has_placeholders(int $template_id): array {
        $template_post = get_post($template_id);
        if (!$template_post) {
            return ['error' => 'Template nicht gefunden'];
        }
        
        $content = $template_post->post_content;
        
        // Suche nach Platzhaltern im Format {{...}}
        preg_match_all('/\{\{([^}]+)\}\}/', $content, $matches);
        
        $found_placeholders = array_unique($matches[1]);
        $placeholder_count = count($found_placeholders);
        
        return [
            'has_placeholders' => $placeholder_count > 0,
            'placeholder_count' => $placeholder_count,
            'placeholders' => $found_placeholders,
            'template_length' => strlen($content),
            'analysis' => [
                'common_fields' => array_intersect($found_placeholders, ['post_title', 'post_content', 'post_excerpt']),
                'image_fields' => array_filter($found_placeholders, function($p) {
                    return stripos($p, 'image') !== false || stripos($p, 'photo') !== false;
                }),
                'seo_fields' => array_filter($found_placeholders, function($p) {
                    return stripos($p, 'seo') !== false || stripos($p, 'meta') !== false;
                })
            ]
        ];
    }

    /**
     * 🔥 NEUE FUNKTION: Vorschau eines Templates mit Beispieldaten
     */
    public static function preview_template_with_sample_data(int $template_id, array $sample_data): array {
        $template_post = get_post($template_id);
        if (!$template_post) {
            return ['error' => 'Template nicht gefunden'];
        }
        
        $original_content = $template_post->post_content;
        $preview_content = $original_content;
        
        // Platzhalter durch Beispieldaten ersetzen
        foreach ($sample_data as $key => $value) {
            $placeholder = '{{' . trim($key) . '}}';
            $preview_content = str_replace($placeholder, $value, $preview_content);
        }
        
        // Analyse der Ersetzungen
        preg_match_all('/\{\{([^}]+)\}\}/', $original_content, $original_matches);
        preg_match_all('/\{\{([^}]+)\}\}/', $preview_content, $remaining_matches);
        
        $replaced_count = count($original_matches[1]) - count($remaining_matches[1]);
        
        return [
            'success' => true,
            'original_content' => $original_content,
            'preview_content' => $preview_content,
            'original_placeholders' => array_unique($original_matches[1]),
            'remaining_placeholders' => array_unique($remaining_matches[1]),
            'replaced_count' => $replaced_count,
            'replacement_stats' => [
                'total_placeholders' => count($original_matches[1]),
                'replaced_placeholders' => $replaced_count,
                'remaining_placeholders' => count($remaining_matches[1]),
                'replacement_percentage' => count($original_matches[1]) > 0 
                    ? round(($replaced_count / count($original_matches[1])) * 100, 1) 
                    : 0
            ]
        ];
    }

    /**
     * 🔥 NEUE FUNKTION: Validiert Template-Platzhalter gegen CSV-Header
     */
    public static function validate_template_placeholders(int $template_id, array $csv_headers): array {
        $analysis = self::template_has_placeholders($template_id);
        if (isset($analysis['error'])) {
            return $analysis;
        }
        
        $template_placeholders = $analysis['placeholders'];
        
        // Vergleiche Template-Platzhalter mit verfügbaren CSV-Headern
        $matching_placeholders = array_intersect($template_placeholders, $csv_headers);
        $missing_placeholders = array_diff($template_placeholders, $csv_headers);
        $unused_csv_headers = array_diff($csv_headers, $template_placeholders);
        
        return [
            'validation_result' => empty($missing_placeholders),
            'template_placeholders' => $template_placeholders,
            'csv_headers' => $csv_headers,
            'matching_placeholders' => $matching_placeholders,
            'missing_placeholders' => $missing_placeholders,
            'unused_csv_headers' => $unused_csv_headers,
            'statistics' => [
                'total_template_placeholders' => count($template_placeholders),
                'total_csv_headers' => count($csv_headers),
                'matching_count' => count($matching_placeholders),
                'missing_count' => count($missing_placeholders),
                'unused_count' => count($unused_csv_headers),
                'compatibility_percentage' => count($template_placeholders) > 0 
                    ? round((count($matching_placeholders) / count($template_placeholders)) * 100, 1)
                    : 100
            ],
            'recommendations' => self::generate_template_recommendations($missing_placeholders, $unused_csv_headers)
        ];
    }

    /**
     * 🔥 NEUE FUNKTION: Generiert Empfehlungen für Template-Optimierung
     */
    private static function generate_template_recommendations(array $missing_placeholders, array $unused_csv_headers): array {
        $recommendations = [];
        
        if (!empty($missing_placeholders)) {
            $recommendations[] = [
                'type' => 'warning',
                'title' => 'Fehlende CSV-Spalten',
                'message' => 'Diese Platzhalter im Template haben keine entsprechende CSV-Spalte: ' . implode(', ', array_map(function($p) { return '{{' . $p . '}}'; }, $missing_placeholders)),
                'action' => 'Fügen Sie diese Spalten zu Ihrer CSV-Datei hinzu oder entfernen Sie die Platzhalter aus dem Template.'
            ];
        }
        
        if (!empty($unused_csv_headers)) {
            $top_unused = array_slice($unused_csv_headers, 0, 5);
            $recommendations[] = [
                'type' => 'info',
                'title' => 'Ungenutzte CSV-Daten',
                'message' => 'Diese CSV-Spalten werden nicht im Template verwendet: ' . implode(', ', $top_unused) . (count($unused_csv_headers) > 5 ? ' (und ' . (count($unused_csv_headers) - 5) . ' weitere)' : ''),
                'action' => 'Sie können diese Platzhalter zu Ihrem Template hinzufügen: ' . implode(' ', array_map(function($h) { return '{{' . $h . '}}'; }, $top_unused))
            ];
        }
        
        if (empty($missing_placeholders) && !empty($unused_csv_headers)) {
            $recommendations[] = [
                'type' => 'success',
                'title' => 'Template kompatibel',
                'message' => 'Alle Template-Platzhalter haben entsprechende CSV-Spalten.',
                'action' => 'Template ist bereit für den Import!'
            ];
        }
        
        return $recommendations;
    }

    // ===================================================================
    // Legacy-Funktionen (bleiben für Rückwärtskompatibilität)
    // ===================================================================

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
}
