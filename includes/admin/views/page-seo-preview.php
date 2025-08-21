<?php
/**
 * View-Datei für die SEO-Vorschau-Seite.
 * KORRIGIERTE VERSION: Zweispaltiges Layout für bessere Übersicht.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Direkten Zugriff verhindern
}
?>
<div class="wrap">
    <div class="csv-dashboard-header">
        <h1>🔍 CSV Import SEO-Vorschau</h1>
        <p>Analysieren und optimieren Sie die Darstellung Ihrer importierten Seiten in Suchmaschinen.</p>
    </div>

    <div class="csv-import-dashboard">
        
        <div class="csv-import-box">
             <h3>
                <span class="step-icon">🔄</span>
                SEO-Felder bearbeiten
            </h3>
            <p>Geben Sie hier Titel und Beschreibung ein. Die Vorschau rechts und die Metriken unten werden live aktualisiert.</p>
            
            <form method="post" onsubmit="return false;">
                 <table class="form-table compact-form">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="seo_title">SEO-Titel</label></th>
                            <td>
                                <input type="text" id="seo_title" name="seo_title" class="regular-text" placeholder="Beispiel-Seitentitel">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="seo_description">Meta-Description</label></th>
                            <td>
                                <textarea id="seo_description" name="seo_description" rows="4" class="large-text" placeholder="Geben Sie hier Ihre Meta-Beschreibung ein..."></textarea>
                            </td>
                        </tr>
                    </tbody>
                 </table>
            </form>

            <?php
            // Die Metriken und Empfehlungen aus dem Widget hier direkt einbinden
            if ( class_exists('CSV_Import_SEO_Preview') ) {
                ?>
                <div class="seo-metrics" style="margin-top: 20px;">
                    <h4>📊 SEO Metriken</h4>
                    <div class="metric-row">
                        <span>Titel-Länge:</span>
                        <span class="metric-value" id="title-length-metric">0 Zeichen</span>
                    </div>
                    <div class="metric-row">
                        <span>Description-Länge:</span>
                        <span class="metric-value" id="desc-length-metric">0 Zeichen</span>
                    </div>
                    <div class="metric-row">
                        <span>SEO-Score:</span>
                        <span class="metric-value" id="seo-score-metric">Berechnung...</span>
                    </div>
                </div>

                <div class="seo-recommendations" id="seo-recommendations" style="margin-top: 15px;">
                </div>
                <?php
            }
            ?>
        </div>

        <div class="csv-import-box">
            <h3>
                <span class="step-icon">📊</span>
                Live-Vorschau
            </h3>
            <p>So könnte Ihre Seite in den Google-Suchergebnissen aussehen.</p>
            
            <?php
            // Hier nur den visuellen Teil des Widgets rendern
            if ( class_exists('CSV_Import_SEO_Preview') ) {
                ?>
                <div class="csv-seo-preview-widget">
                    <div class="seo-preview-container mobile-view">
                        <div class="serp-preview google-serp active">
                            <div class="serp-result">
                                <div class="serp-title" id="google-title-preview">Ihr Seitentitel</div>
                                <div class="serp-url" id="google-url-preview"><?php echo esc_url(home_url('/beispiel-seite')); ?></div>
                                <div class="serp-description" id="google-desc-preview">Ihre Meta-Description erscheint hier...</div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php
            } else {
                echo '<div class="notice notice-error"><p>Fehler: Die SEO-Vorschau-Komponente konnte nicht geladen werden.</p></div>';
            }
            ?>
        </div>
    </div>
</div>
