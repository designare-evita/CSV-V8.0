<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Direkten Zugriff verhindern
}

/**
 * Verwaltet alle Operationen im Zusammenhang mit Import-Templates.
 * Version 4.0 - MIT COPY/PASTE FUNKTIONALITÄT für CSV-Platzhalter
 */
class CSV_Import_Template_Manager {

    /**
     * Erstellt ein neues Template, indem es einen Basis-Post dupliziert
     * und alle Platzhalter aus der konfigurierten CSV-Datei anhängt.
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

        // 4. Platzhalter-Block für den Editor generieren (MIT COPY/PASTE)
        $placeholder_block = self::generate_copyable_placeholder_block($headers, $source_name);

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
     * 🔥 NEUE FUNKTION: Generiert interaktive Platzhalter mit Copy/Paste-Funktionalität
     */
    private static function generate_copyable_placeholder_block(array $headers, string $source_name): string {
        $placeholder_block = "\n\n<!-- =================================== -->\n";
        $placeholder_block .= "<!-- CSV Import Template Platzhalter -->\n";
        $placeholder_block .= "<!-- Quelle: {$source_name} -->\n";
        $placeholder_block .= "<!-- Generiert am: " . current_time('Y-m-d H:i:s') . " -->\n";
        $placeholder_block .= "<!-- =================================== -->\n\n";
        
        // 🎨 Moderner Container mit JavaScript für Copy-Funktionalität
        $placeholder_block .= '<div class="csv-import-placeholders" id="csv-placeholder-container" style="border: 3px dashed #0073aa; padding: 30px; margin: 30px 0; background: linear-gradient(135deg, #f0f6fc 0%, #e8f4fd 100%); border-radius: 12px; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; position: relative;">' . "\n";
        
        // Header mit Gesamt-Copy-Button
        $placeholder_block .= '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">' . "\n";
        $placeholder_block .= '<h2 style="color: #0073aa; margin: 0; font-size: 28px; display: flex; align-items: center;"><span style="margin-right: 10px;">🔗</span> CSV Import Platzhalter</h2>' . "\n";
        $placeholder_block .= '<div>' . "\n";
        $placeholder_block .= '<button type="button" onclick="copyAllPlaceholders()" style="background: #00a32a; color: white; border: none; padding: 10px 16px; border-radius: 6px; font-weight: 600; cursor: pointer; margin-right: 10px; font-size: 14px;">📋 Alle kopieren</button>' . "\n";
        $placeholder_block .= '<button type="button" onclick="togglePlaceholderContainer()" style="background: #f56e28; color: white; border: none; padding: 10px 16px; border-radius: 6px; font-weight: 600; cursor: pointer; font-size: 14px;">👁️ Ein/Ausblenden</button>' . "\n";
        $placeholder_block .= '</div>' . "\n";
        $placeholder_block .= '</div>' . "\n";
        
        $placeholder_block .= '<p style="margin: 0 0 25px 0; color: #646970; font-size: 16px; line-height: 1.6;">Diese Platzhalter werden beim Import automatisch durch die entsprechenden CSV-Werte ersetzt. <strong>Klicken Sie auf einen Platzhalter, um ihn zu kopieren!</strong></p>' . "\n\n";
        
        // 🎯 Suchfunktion für viele Platzhalter
        if (count($headers) > 10) {
            $placeholder_block .= '<div style="margin-bottom: 20px;">' . "\n";
            $placeholder_block .= '<input type="text" id="placeholder-search" onkeyup="filterPlaceholders()" placeholder="🔍 Platzhalter suchen..." style="width: 100%; padding: 12px; border: 2px solid #ddd; border-radius: 8px; font-size: 16px; background: white;">' . "\n";
            $placeholder_block .= '</div>' . "\n";
        }
        
        // Platzhalter-Grid mit Copy-Buttons
        $placeholder_block .= '<div id="placeholder-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px;">' . "\n";
        
        foreach ($headers as $index => $header) {
            if (!empty(trim($header))) {
                $clean_header = trim($header);
                $placeholder_text = '{{' . $clean_header . '}}';
                $card_id = 'placeholder-card-' . $index;
                
                $placeholder_block .= '<div class="placeholder-card" id="' . $card_id . '" style="background: white; padding: 20px; border-radius: 10px; border-left: 5px solid #00a32a; box-shadow: 0 2px 8px rgba(0,0,0,0.1); transition: all 0.3s ease; cursor: pointer; position: relative;" onclick="copyPlaceholder(\'' . esc_js($placeholder_text) . '\', \'' . $card_id . '\')" onmouseover="this.style.boxShadow=\'0 4px 16px rgba(0,0,0,0.15)\'; this.style.transform=\'translateY(-2px)\'" onmouseout="this.style.boxShadow=\'0 2px 8px rgba(0,0,0,0.1)\'; this.style.transform=\'translateY(0)\'">' . "\n";
                
                // Header-Info
                $placeholder_block .= '<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">' . "\n";
                $placeholder_block .= '<div style="display: flex; align-items: center;">' . "\n";
                $placeholder_block .= '<span style="background: #00a32a; color: white; padding: 6px 10px; border-radius: 50%; font-size: 12px; font-weight: bold; margin-right: 12px; min-width: 24px; text-align: center;">' . ($index + 1) . '</span>' . "\n";
                $placeholder_block .= '<strong style="color: #1d2327; font-size: 16px;">' . esc_html($clean_header) . '</strong>' . "\n";
                $placeholder_block .= '</div>' . "\n";
                $placeholder_block .= '<div class="copy-indicator" id="copy-indicator-' . $card_id . '" style="opacity: 0; color: #00a32a; font-weight: bold; font-size: 12px; transition: opacity 0.3s ease;">✅ KOPIERT!</div>' . "\n";
                $placeholder_block .= '</div>' . "\n";
                
                // Platzhalter-Code (selectable)
                $placeholder_block .= '<div style="background: #f8f9fa; border: 2px dashed #dee2e6; padding: 15px; border-radius: 8px; margin-bottom: 12px; position: relative;">' . "\n";
                $placeholder_block .= '<code id="placeholder-text-' . $card_id . '" style="background: transparent; padding: 0; font-size: 16px; color: #d63638; font-weight: 600; font-family: \'Consolas\', Monaco, monospace; word-break: break-all; user-select: all;">' . esc_html($placeholder_text) . '</code>' . "\n";
                $placeholder_block .= '<div style="position: absolute; top: 8px; right: 8px; background: #0073aa; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">KLICKEN = KOPIEREN</div>' . "\n";
                $placeholder_block .= '</div>' . "\n";
                
                // Verwendungshinweis
                $usage_hint = self::get_usage_hint($clean_header);
                if ($usage_hint) {
                    $placeholder_block .= '<div style="font-size: 13px; color: #6c757d; line-height: 1.4;"><strong>💡 Verwendung:</strong> ' . esc_html($usage_hint) . '</div>' . "\n";
                }
                
                $placeholder_block .= '</div>' . "\n";
            }
        }
        
        $placeholder_block .= '</div>' . "\n\n";
        
        // 📋 Schnell-Kopieren-Liste für häufige Platzhalter
        $common_placeholders = self::get_common_placeholders($headers);
        if (!empty($common_placeholders)) {
            $placeholder_block .= '<div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 20px; border-radius: 8px; margin-top: 25px;">' . "\n";
            $placeholder_block .= '<h4 style="color: #856404; margin: 0 0 15px 0; font-size: 18px; display: flex; align-items: center;"><span style="margin-right: 8px;">⚡</span> Häufig verwendete Platzhalter:</h4>' . "\n";
            $placeholder_block .= '<div style="display: flex; flex-wrap: wrap; gap: 10px;">' . "\n";
            
            foreach ($common_placeholders as $placeholder) {
                $placeholder_block .= '<button type="button" onclick="copyPlaceholder(\'' . esc_js($placeholder) . '\', \'quick-copy\')" style="background: #856404; color: white; border: none; padding: 8px 12px; border-radius: 6px; font-size: 13px; font-weight: 500; cursor: pointer;" onmouseover="this.style.background=\'#6f5016\'" onmouseout="this.style.background=\'#856404\'">' . esc_html($placeholder) . '</button>' . "\n";
            }
            
            $placeholder_block .= '</div>' . "\n";
            $placeholder_block .= '</div>' . "\n";
        }
        
        // 📝 Anweisungen und Tipps
        $placeholder_block .= '<div style="background: #e7f3ff; border: 1px solid #b3d9ff; padding: 20px; border-radius: 8px; margin-top: 20px;">' . "\n";
        $placeholder_block .= '<h4 style="color: #0066cc; margin: 0 0 15px 0; font-size: 18px; display: flex; align-items: center;"><span style="margin-right: 8px;">📝</span> Verwendungs-Tipps:</h4>' . "\n";
        $placeholder_block .= '<ul style="margin: 0; padding-left: 20px; color: #0066cc; line-height: 1.6;">' . "\n";
        $placeholder_block .= '<li><strong>Klicken Sie auf einen Platzhalter</strong>, um ihn automatisch in die Zwischenablage zu kopieren</li>' . "\n";
        $placeholder_block .= '<li><strong>Fügen Sie die Platzhalter</strong> in Ihr Template ein (Strg+V / Cmd+V)</li>' . "\n";
        $placeholder_block .= '<li><strong>Beim Import</strong> werden alle Platzhalter automatisch durch die CSV-Werte ersetzt</li>' . "\n";
        $placeholder_block .= '<li><strong>Mehrfach-Verwendung:</strong> Sie können jeden Platzhalter mehrmals verwenden</li>' . "\n";
        $placeholder_block .= '<li><strong>Dieser Block</strong> kann nach dem Kopieren der Platzhalter gelöscht werden</li>' . "\n";
        $placeholder_block .= '</ul>' . "\n";
        $placeholder_block .= '</div>' . "\n";
        
        // 🎯 JavaScript für Copy/Paste-Funktionalität
        $placeholder_block .= self::generate_copy_paste_javascript();
        
        $placeholder_block .= '</div>' . "\n\n";
        
        $placeholder_block .= "<!-- Ende CSV Import Platzhalter -->\n\n";

        return $placeholder_block;
    }

    /**
     * 🔥 NEUE FUNKTION: Generiert JavaScript für Copy/Paste-Funktionalität
     */
    private static function generate_copy_paste_javascript(): string {
        return '
<script>
// 📋 CSV Import Platzhalter Copy/Paste JavaScript
(function() {
    // Copy-Funktion für einzelne Platzhalter
    window.copyPlaceholder = function(text, cardId) {
        // Moderne Clipboard API verwenden
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(function() {
                showCopyFeedback(cardId, text);
            }).catch(function(err) {
                fallbackCopy(text, cardId);
            });
        } else {
            fallbackCopy(text, cardId);
        }
    };
    
    // Fallback für ältere Browser
    function fallbackCopy(text, cardId) {
        const textArea = document.createElement("textarea");
        textArea.value = text;
        textArea.style.position = "fixed";
        textArea.style.left = "-999999px";
        textArea.style.top = "-999999px";
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        
        try {
            document.execCommand("copy");
            showCopyFeedback(cardId, text);
        } catch (err) {
            console.error("Copy fallback failed:", err);
            alert("Platzhalter kopiert: " + text);
        }
        
        document.body.removeChild(textArea);
    }
    
    // Visuelles Feedback beim Kopieren
    function showCopyFeedback(cardId, text) {
        // Copy-Indikator anzeigen
        const indicator = document.getElementById("copy-indicator-" + cardId);
        if (indicator) {
            indicator.style.opacity = "1";
            setTimeout(function() {
                indicator.style.opacity = "0";
            }, 2000);
        }
        
        // Card kurz hervorheben
        const card = document.getElementById(cardId);
        if (card) {
            const originalBorder = card.style.borderLeft;
            card.style.borderLeft = "5px solid #00a32a";
            card.style.boxShadow = "0 4px 20px rgba(0, 163, 42, 0.3)";
            
            setTimeout(function() {
                card.style.borderLeft = originalBorder;
                card.style.boxShadow = "0 2px 8px rgba(0,0,0,0.1)";
            }, 800);
        }
        
        console.log("✅ Platzhalter kopiert:", text);
    }
    
    // Alle Platzhalter kopieren
    window.copyAllPlaceholders = function() {
        const placeholderElements = document.querySelectorAll("[id^=\'placeholder-text-\']");
        const allPlaceholders = Array.from(placeholderElements).map(el => el.textContent);
        const allText = allPlaceholders.join(" ");
        
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(allText).then(function() {
                alert("✅ Alle " + allPlaceholders.length + " Platzhalter kopiert!\\n\\nJetzt können Sie diese in Ihr Template einfügen (Strg+V).");
            });
        } else {
            fallbackCopy(allText, "all");
            alert("✅ Alle " + allPlaceholders.length + " Platzhalter kopiert!");
        }
    };
    
    // Container ein/ausblenden
    window.togglePlaceholderContainer = function() {
        const grid = document.getElementById("placeholder-grid");
        const button = event.target;
        
        if (grid.style.display === "none") {
            grid.style.display = "grid";
            button.textContent = "👁️ Ausblenden";
            button.style.background = "#f56e28";
        } else {
            grid.style.display = "none";
            button.textContent = "👁️ Einblenden";
            button.style.background = "#00a32a";
        }
    };
    
    // Suchfunktion
    window.filterPlaceholders = function() {
        const searchTerm = document.getElementById("placeholder-search").value.toLowerCase();
        const cards = document.querySelectorAll(".placeholder-card");
        
        cards.forEach(function(card) {
            const headerText = card.querySelector("strong").textContent.toLowerCase();
            const placeholderText = card.querySelector("code").textContent.toLowerCase();
            
            if (headerText.includes(searchTerm) || placeholderText.includes(searchTerm)) {
                card.style.display = "block";
            } else {
                card.style.display = "none";
            }
        });
    };
    
    // Tastatur-Shortcuts
    document.addEventListener("keydown", function(e) {
        // Strg+Shift+C = Alle Platzhalter kopieren
        if (e.ctrlKey && e.shiftKey && e.key === "C") {
            e.preventDefault();
            copyAllPlaceholders();
        }
        
        // Strg+Shift+H = Container ein/ausblenden
        if (e.ctrlKey && e.shiftKey && e.key === "H") {
            e.preventDefault();
            togglePlaceholderContainer();
        }
    });
    
    console.log("🔗 CSV Import Platzhalter-System geladen!");
    console.log("💡 Tipps:");
    console.log("   • Klicken Sie auf Platzhalter zum Kopieren");
    console.log("   • Strg+Shift+C = Alle kopieren");
    console.log("   • Strg+Shift+H = Ein/Ausblenden");
    
})();
</script>';
    }

    /**
     * 🔥 NEUE FUNKTION: Gibt Verwendungshinweise für spezielle Platzhalter
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
     * 🔥 NEUE FUNKTION: Ermittelt häufig verwendete Platzhalter
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
        update_post_meta($post_id, '_csv_import_template_version', '4.0-copyable');
        update_post_meta($post_id, '_csv_import_template_header_count', count($headers));
    }

    /**
     * Loggt die Template-Erstellung
     */
    private static function log_template_creation(int $template_id, string $template_name, int $base_id, string $source, int $header_count): void {
        if (function_exists('csv_import_log')) {
            csv_import_log('info', 'Template mit Copy/Paste-Funktionalität erfolgreich generiert', [
                'template_id' => $template_id,
                'template_name' => $template_name,
                'base_post_id' => $base_id,
                'csv_source' => $source,
                'headers_count' => $header_count,
                'user_id' => get_current_user_id(),
                'user_login' => wp_get_current_user()->user_login ?? 'unknown',
                'version' => '4.0-copyable'
            ]);
        }
    }

    // ... Restliche Methoden bleiben unverändert ...

    /**
     * Wendet Platzhalter auf den Inhalt eines Templates an.
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
