<?php
/**
 * View-Datei für die Einstellungsseite.
 * FINALE VERSION: Flexibles Grid Layout, korrigierte Formular-Struktur und intelligenter Template-Generator.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Holen der Konfiguration für die Anzeige der ausgewählten Optionen
$current_pb = get_option('csv_import_page_builder', 'gutenberg');
$current_ptype = get_option( 'csv_import_post_type', 'page' );
$current_status = get_option( 'csv_import_post_status', 'draft' );
$current_img_src = get_option( 'csv_import_image_source', 'media_library' );
$current_seo_pl = get_option( 'csv_import_seo_plugin', 'none' );

?>
<div class="wrap">
	<div class="csv-dashboard-header">
		<h1>⚙️ CSV Import Einstellungen</h1>
		<p>Konfigurieren Sie alle Aspekte des CSV-Imports für optimale Ergebnisse</p>
	</div>

	<?php
    // Anzeige für Erfolgs- oder Fehlermeldungen (z.B. vom Template-Generator)
    if ( isset( $action_result ) && is_array( $action_result ) ) {
        $notice_class   = $action_result['success'] ? 'notice-success is-dismissible notice-large' : 'notice-error is-dismissible';
        $notice_message = $action_result['message'];
        echo '<div class="notice ' . esc_attr( $notice_class ) . '"><p>' . wp_kses_post( $notice_message ) . '</p></div>';
    } elseif ( isset( $_GET['settings-updated'] ) ) {
        // Standard-WordPress-Meldung nach dem Speichern der Einstellungen
        settings_errors();
    }
    ?>
	<div class="csv-settings-dashboard" style="margin-top: 2rem;">
        <div class="csv-import-box settings-box" style="grid-column: 1 / -1;">
            <h3>
                <span class="step-icon">✨</span>
                Automatischer Template-Generator
            </h3>
            <p>Erstellen Sie automatisch ein neues Import-Template. Das Plugin liest die Spalten Ihrer CSV-Datei aus und fügt alle verfügbaren Platzhalter in eine Kopie Ihres Basis-Designs ein.</p>
            
            <form method="post">
                <?php wp_nonce_field( 'csv_import_generate_template' ); ?>
                <input type="hidden" name="action" value="generate_template_from_csv">

                <table class="form-table compact-form" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="base_template_id">Basis-Post ID</label></th>
                            <td>
                                <input type="number" id="base_template_id" name="base_template_id" class="small-text" required placeholder="z.B. 123">
                                <p class="description">ID der Seite/des Beitrags, dessen Design als Grundlage dienen soll.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="new_template_name">Name des neuen Templates</label></th>
                            <td>
                                <input type="text" id="new_template_name" name="new_template_name" class="regular-text" required placeholder="z.B. Landingpage Vorlage V2">
                                <p class="description">Wie soll das neue Template (als Entwurf) heißen?</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div class="action-buttons" style="margin-top: 15px;">
                    <button type="submit" class="button button-primary">Template generieren</button>
                </div>
            </form>
        </div>
    </div>
	<form method="post" action="options.php">
		<?php
		// WordPress-Funktion zum Behandeln der Einstellungs-Speicherung
		settings_fields( 'csv_import_settings' );
		?>
		
		<div class="csv-settings-dashboard">

			<div class="csv-import-box settings-box">
				<h3>
					<span class="step-number completed">1</span>
					<span class="step-icon">📋</span>
					Basis-Konfiguration
				</h3>
				<table class="form-table compact-form" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="csv_import_template_id">Template-Post ID</label></th>
							<td>
								<input type="number" id="csv_import_template_id" name="csv_import_template_id"
									   value="<?php echo esc_attr( get_option( 'csv_import_template_id' ) ); ?>"
									   class="small-text">
								<p class="description">
									ID der Vorlage. Aktuell: <?php echo csv_import_get_template_info(); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="csv_import_page_builder">Page Builder</label></th>
							<td>
								<select id="csv_import_page_builder" name="csv_import_page_builder">
									<?php
									$pb_options = [
										'gutenberg' => 'Gutenberg (Standard)',
										'elementor' => 'Elementor',
										'wpbakery' => 'WPBakery',
										'breakdance' => 'Breakdance',
										'enfold' => 'Enfold'
									];
									foreach ( $pb_options as $val => $label ) {
										echo '<option value="' . esc_attr( $val ) . '" ' . selected( $current_pb, $val, false ) . '>' . esc_html( $label ) . '</option>';
									}
									?>
								</select>
								<p class="description">Editor für das Template</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="csv_import_post_type">Post-Typ</label></th>
							<td>
								<select id="csv_import_post_type" name="csv_import_post_type">
									<?php
									$post_types = get_post_types( [ 'public' => true ], 'objects' );
									foreach ( $post_types as $post_type ) {
										echo '<option value="' . esc_attr( $post_type->name ) . '" ' . selected( $current_ptype, $post_type->name, false ) . '>' . esc_html( $post_type->label ) . '</option>';
									}
									?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="csv_import_post_status">Post-Status</label></th>
							<td>
								<select id="csv_import_post_status" name="csv_import_post_status">
									<?php
									$status_options = [ 'draft' => 'Entwurf', 'publish' => 'Veröffentlicht', 'private' => 'Privat', 'pending' => 'Ausstehend' ];
									foreach ( $status_options as $val => $label ) {
										echo '<option value="' . esc_attr( $val ) . '" ' . selected( $current_status, $val, false ) . '>' . esc_html( $label ) . '</option>';
									}
									?>
								</select>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="csv-import-box settings-box">
				<h3>
					<span class="step-number completed">2</span>
					<span class="step-icon">🔗</span>
					CSV-Quellen
				</h3>
				<table class="form-table compact-form" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="csv_import_dropbox_url">Dropbox CSV-URL</label></th>
							<td>
								<input type="url" id="csv_import_dropbox_url" name="csv_import_dropbox_url"
									   value="<?php echo esc_attr( get_option( 'csv_import_dropbox_url' ) ); ?>"
									   class="regular-text" placeholder="https://www.dropbox.com/s/...?dl=1">
								<p class="description">Direkt-Download-Link. Muss mit `?dl=1` enden.</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="csv_import_local_path">Lokaler CSV-Pfad</label></th>
							<td>
								<input type="text" id="csv_import_local_path" name="csv_import_local_path"
									   value="<?php echo esc_attr( get_option( 'csv_import_local_path', 'data/landingpages.csv' ) ); ?>"
									   class="regular-text">
								<p class="description">
									Pfad: <code><?php echo esc_html( ABSPATH . get_option( 'csv_import_local_path', 'data/landingpages.csv' ) ); ?></code>
									<?php echo csv_import_get_file_status( ABSPATH . get_option( 'csv_import_local_path', 'data/landingpages.csv' ) ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="csv_import_delimiter">CSV-Trennzeichen</label></th>
							<td>
								<input type="text" id="csv_import_delimiter" name="csv_import_delimiter"
									   value="<?php echo esc_attr( get_option( 'csv_import_delimiter', 'auto' ) ); ?>"
									   class="small-text">
								<p class="description">Standard: code>auto</code> für automatische Erkennung.</p>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="csv-import-box settings-box">
				<h3>
					<span class="step-number completed">3</span>
					<span class="step-icon">🖼️</span>
					Medien-Einstellungen
				</h3>
				<table class="form-table compact-form" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="csv_import_image_source">Bildquelle</label></th>
							<td>
								<select id="csv_import_image_source" name="csv_import_image_source">
									<?php
									$img_src_options = [ 'media_library' => 'WordPress-Mediathek', 'local_folder' => 'Lokaler Ordner' ];
									foreach ( $img_src_options as $val => $label ) {
										echo '<option value="' . esc_attr( $val ) . '" ' . selected( $current_img_src, $val, false ) . '>' . esc_html( $label ) . '</option>';
									}
									?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="csv_import_image_folder">Lokaler Bild-Ordner</label></th>
							<td>
								<input type="text" id="csv_import_image_folder" name="csv_import_image_folder"
									   value="<?php echo esc_attr( get_option( 'csv_import_image_folder', 'wp-content/uploads/csv-import-images/' ) ); ?>"
									   class="regular-text">
								<p class="description">
									Pfad relativ zum WordPress-Root.
									<?php echo csv_import_get_file_status( ABSPATH . get_option( 'csv_import_image_folder' ), true ); ?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="csv-import-box settings-box">
				<h3>
					<span class="step-number completed">4</span>
					<span class="step-icon">🎯</span>
					SEO & Erweitert
				</h3>
				<table class="form-table compact-form" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="csv_import_seo_plugin">SEO-Plugin</label></th>
							<td>
								<select id="csv_import_seo_plugin" name="csv_import_seo_plugin">
									<?php
									$seo_options = [ 'none' => 'Keins / Manuell', 'aioseo' => 'All in One SEO', 'yoast' => 'Yoast SEO', 'rankmath' => 'Rank Math' ];
									foreach ( $seo_options as $val => $label ) {
										echo '<option value="' . esc_attr( $val ) . '" ' . selected( $current_seo_pl, $val, false ) . '>' . esc_html( $label ) . '</option>';
									}
									?>
								</select>
								<p class="description">Wähle dein aktives SEO-Plugin</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="csv_import_required_columns">Erforderliche Spalten</label></th>
							<td>
								<textarea id="csv_import_required_columns" name="csv_import_required_columns" rows="3" class="large-text"><?php echo esc_textarea( get_option( 'csv_import_required_columns', "post_title\npost_name" ) ); ?></textarea>
								<p class="description">Eine Spalte pro Zeile</p>
							</td>
						</tr>
						<tr>
							<th scope="row">Duplikate</th>
							<td>
								<label>
									<input type="checkbox" name="csv_import_skip_duplicates" value="1" <?php checked( get_option( 'csv_import_skip_duplicates' ), 1 ); ?> >
									Duplikate überspringen (basierend auf Post-Titel)
								</label>
							</td>
						</tr>
                        <tr>
							<th scope="row">Suchmaschinen</th>
							<td>
								<label>
									<input type="checkbox" name="csv_import_noindex_posts" value="1" <?php checked( get_option( 'csv_import_noindex_posts' ), 1 ); ?> >
									Alle importierten Posts auf "noindex" setzen.
								</label>
								<p class="description">Verhindert, dass Suchmaschinen diese Seiten indizieren.</p>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

		</div> <div class="csv-dashboard-footer">
			<?php submit_button( '💾 Einstellungen speichern', 'primary large', 'submit', false ); ?>
		</div>

	</form> 
	
	<div class="csv-settings-grid-half">
        <div class="csv-import-box settings-box">
            <h3>
                <span class="step-number active">5</span>
                <span class="step-icon">🧪</span>
                Konfiguration testen &amp; CSV validieren
            </h3>
            <p>Überprüfen Sie Ihre Einstellungen und die CSV-Dateien vor dem eigentlichen Import.</p>
            <div class="action-buttons">
                <button type="button" class="button button-secondary" onclick="csvImportTestConfig()">⚙️ Konfiguration prüfen</button>
                <button type="button" class="button button-secondary" onclick="csvImportValidateCSV('dropbox')">📊 Dropbox CSV validieren</button>
                <button type="button" class="button button-secondary" onclick="csvImportValidateCSV('local')">📁 Lokale CSV validieren</button>
            </div>
            <div id="csv-test-results" class="test-results-container"></div>
        </div>
        
        <div class="csv-import-box settings-box">
            <h3>
                <span class="step-number">6</span>
                <span class="step-icon">📊</span>
                CSV Beispieldaten
            </h3>
            <div id="csv-sample-data-container" class="sample-data-container">
                <div class="info-message">
                    <strong>Info:</strong> Führen Sie zuerst eine CSV-Validierung durch, um Beispieldaten zu sehen.
                </div>
            </div>
        </div>
	</div>
	<div class="csv-settings-grid-half">
        <div id="csv-column-mapping-container" class="csv-import-box settings-box" style="display:none;">
            <h3>
                <span class="step-number active">7</span>
                <span class="step-icon">🔄</span>
                Spalten zuordnen
            </h3>
            <div id="mapping-table-target"></div>
        </div>

        <div id="csv-live-preview-container" class="csv-import-box settings-box" style="display:none;">
            <h3>
                <span class="step-number active">8</span>
                <span class="step-icon">👀</span>
                Live-Vorschau
            </h3>
            <p>Vorschau, wie Ihr Template mit den Daten der ersten CSV-Zeile aussehen wird, inklusive SEO-Analyse.</p>
            <div class="action-buttons" style="margin-bottom: 20px;">
                <button type="button" id="csv-generate-preview-btn" class="button button-primary button-large">
                    Vorschau generieren / aktualisieren
                </button>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; align-items: start;">
                <div>
                    <h4>Template-Vorschau</h4>
                    <div id="csv-preview-content" class="seo-preview-container mobile-view" style="margin-top: 5px; min-height: 200px;">
                        <div class="info-message" style="margin: 0;">Klicken Sie auf "Vorschau generieren", um das Ergebnis zu sehen.</div>
                    </div>
                </div>
                <div>
                    <h4>SEO-Analyse</h4>
                    <?php if (class_exists('CSV_Import_SEO_Preview')) : ?>
                        <div class="seo-metrics" style="margin-top: 5px;">
                            <div class="metric-row"><span>Titel-Länge:</span><span class="metric-value" id="title-length-metric">0 Zeichen</span></div>
                            <div class="metric-row"><span>Description-Länge:</span><span class="metric-value" id="desc-length-metric">0 Zeichen</span></div>
                            <div class="metric-row"><span>SEO-Score:</span><span class="metric-value" id="seo-score-metric">...</span></div>
                        </div>
                        <div class="seo-recommendations" id="seo-recommendations" style="margin-top: 15px;"></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
	</div>
</div>
