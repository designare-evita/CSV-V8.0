/**
 * CSV Import Pro Admin JavaScript
 * Version 10.0 - Mit Live-Template-Vorschau
 * Löst alle Syntaxfehler und begrenzt die Spaltenanzeige.
 */

(function($) {
    'use strict';

    // Globale Funktionen für die `onclick`-Attribute im HTML verfügbar machen.
    window.csvImportTestConfig = () => { if (window.CSVImportAdmin) window.CSVImportAdmin.testConfiguration(); };
    window.csvImportValidateCSV = (type) => { if (window.CSVImportAdmin) window.CSVImportAdmin.validateCSV(type); };
    window.csvImportSystemHealth = () => { alert("System-Health-Check wird ausgeführt. Bitte prüfen Sie die Konsole (F12) für Details."); console.log("System Health:", csvImportAjax.health); };

    const CSVImportAdmin = {
        version: '10.0-preview',
        elements: {},
        status: {
            importRunning: false,
            validationInProgress: false,
            progressInterval: null,
            firstRowData: null // Hält die Daten der ersten CSV-Zeile für die Vorschau
        },

        init: function() {
            if (typeof csvImportAjax === 'undefined') {
                console.error('❌ CSV Import: AJAX-Konfiguration fehlt.');
                return;
            }
            this.cacheElements();
            this.bindEvents();
            this.initializeStatus();
            console.log(`🔧 CSV Import Admin v${this.version} initialisiert.`);
        },

        cacheElements: function() {
            this.elements = {
                resultsContainer: $('#csv-test-results'),
                sampleDataContainer: $('#csv-sample-data-container'),
                importButtons: $('.csv-import-btn'),
                progressBar: $('.progress-bar-fill, .csv-import-progress-fill'),
                mappingContainer: $('#csv-column-mapping-container'),
                // NEU: Elemente für die Live-Vorschau
                livePreviewContainer: $('#csv-live-preview-container'),
                generatePreviewBtn: $('#csv-generate-preview-btn'),
                previewContent: $('#csv-preview-content')
            };
        },

        bindEvents: function() {
            const self = this;
            this.elements.importButtons.on('click', function(e) {
                e.preventDefault();
                self.handleImportClick($(this));
            });

            // NEU: Event-Handler für den Vorschau-Button
            this.elements.generatePreviewBtn.on('click', function() {
                self.generatePreview();
            });
        },

        initializeStatus: function() {
            this.status.importRunning = csvImportAjax.import_running || false;
            this.updateUIState();
            if (this.status.importRunning) {
                this.startProgressUpdates();
            }
        },

        performAjaxRequest: function(data) {
            return $.ajax({
                url: csvImportAjax.ajaxurl,
                type: 'POST',
                data: $.extend({ nonce: csvImportAjax.nonce }, data),
            }).fail(() => {
                console.error("❌ CSV Import: AJAX-Anfrage fehlgeschlagen.", data.action);
            });
        },

        testConfiguration: function() {
            if (this.status.validationInProgress) return;
            this.status.validationInProgress = true;
            this.showTestProgress('Konfiguration wird geprüft...');
            this.performAjaxRequest({ action: 'csv_import_validate', type: 'config' })
                .done(response => this.handleValidationResult(response, 'config'))
                .always(() => { this.status.validationInProgress = false; });
        },

        validateCSV: function(type) {
            if (this.status.validationInProgress) return;
            this.status.validationInProgress = true;
            const typeLabel = type.charAt(0).toUpperCase() + type.slice(1);
            this.showTestProgress(`${typeLabel} CSV wird validiert...`);
            this.performAjaxRequest({ action: 'csv_import_validate', type: type })
                .done(response => this.handleValidationResult(response, type))
                .always(() => { this.status.validationInProgress = false; });
        },

        handleValidationResult: function(response, type) {
            const data = response.data || {};
            this.showTestResult(data.message || 'Ein unbekannter Fehler ist aufgetreten.', response.success);

            if (response.success && data.columns && data.sample_data && data.sample_data.length > 0) {
                this.showSampleData(data.columns, data.sample_data);
                this.showColumnMappingUI(data.columns);

                // NEU: Logik zum Anzeigen der Vorschau-Box
                // Speichere die erste Zeile als Objekt für die Vorschau
                this.status.firstRowData = this.mapRowToHeaders(data.columns, data.sample_data[0]);
                this.elements.livePreviewContainer.slideDown();


                if (window.CSVSEOPreview) {
                    const sample_row = data.sample_data[0];
                    const columns = data.columns;
                    const preview_data = {
                        seo_title: this.getDataFromRow(sample_row, columns, ['post_title', 'title']),
                        seo_description: this.getDataFromRow(sample_row, columns, ['post_excerpt', 'excerpt', 'description'])
                    };
                    window.CSVSEOPreview.updatePreview(preview_data);
                }
            } else {
                this.clearSampleData();
                this.elements.mappingContainer.hide().empty();
                // NEU: Verstecke die Vorschau-Box bei einem Fehler
                this.elements.livePreviewContainer.slideUp();
            }
        },

        // NEU: Hilfsfunktion, um eine Datenzeile (Array) mit den Headern zu einem Objekt zu machen
        mapRowToHeaders: function(headers, row) {
            const obj = {};
            headers.forEach((header, index) => {
                obj[header] = row[index] || '';
            });
            return obj;
        },

        // NEU: Funktion zum Generieren der Live-Vorschau
        // In assets/js/admin-script.js

generatePreview: function() {
    if (!this.status.firstRowData) {
        alert('Bitte validieren Sie zuerst eine CSV-Datei.');
        return;
    }

    const templateId = $('#csv_import_template_id').val();
    if (!templateId || templateId == 0) {
        alert('Bitte geben Sie eine gültige Template-ID an.');
        return;
    }

    this.elements.previewContent.html('<div class="test-result test-progress">🔄 Vorschau wird geladen...</div>');

    // NEU: SEO-Vorschau mit den Daten aus der CSV-Zeile aktualisieren
    if (window.CSVSEOPreview && this.status.firstRowData) {
        const previewData = {
            seo_title: this.status.firstRowData.post_title || this.status.firstRowData.title || '',
            seo_description: this.status.firstRowData.post_excerpt || this.status.firstRowData.excerpt || this.status.firstRowData.description || ''
        };
        // Die updatePreview-Funktion aus seo-preview.js aufrufen
        window.CSVSEOPreview.updatePreview(previewData);
    }

    this.performAjaxRequest({
        action: 'csv_import_generate_preview',
        template_id: templateId,
        row_data: this.status.firstRowData
    }).done(response => {
        if (response.success) {
            this.elements.previewContent.html(response.data.preview_html);
        } else {
            this.elements.previewContent.html(`<div class="test-result test-error">❌ ${response.data.message}</div>`);
        }
    }).fail(() => {
        this.elements.previewContent.html('<div class="test-result test-error">❌ Ein Serverfehler ist aufgetreten.</div>');
    });
},


        getDataFromRow: function(row, columns, possibleKeys) {
            for (const key of possibleKeys) {
                const index = columns.indexOf(key);
                if (index !== -1 && row[index]) {
                    return row[index];
                }
            }
            return '';
        },

        handleImportClick: function($button) {
            const source = $button.data('source');
            const sourceName = source.charAt(0).toUpperCase() + source.slice(1);
            if (!confirm(`Den Import von der Quelle "${sourceName}" wirklich starten?`)) return;
            this.startImport(source);
        },

        startImport: function(source) {
            this.status.importRunning = true;
            this.updateUIState();
            const mappingData = {};
            this.elements.mappingContainer.find('select').each(function() {
                const columnName = $(this).attr('name').replace(/csv_mapping\[|\]/g, '');
                if($(this).val()) {
                    mappingData[columnName] = $(this).val();
                }
            });

            this.performAjaxRequest({ action: 'csv_import_start', source: source, mapping: mappingData })
                .done(response => {
                    if (response.success && response.data) {
                        $('#success-count').text(response.data.processed || 0);
                        $('#success-source').text(source.charAt(0).toUpperCase() + source.slice(1));
                        $('#csv-import-success-message').slideDown();
                        $('html, body').animate({ scrollTop: 0 }, 'slow');
                    } else {
                        alert('Import fehlgeschlagen: ' + (response.data.message || 'Unbekannter Fehler.'));
                    }
                })
                .fail(() => alert('Ein schwerwiegender Serverfehler ist beim Import aufgetreten.'))
                .always(() => {
                    this.status.importRunning = false;
                    this.updateUIState();
                });
            this.startProgressUpdates();
        },

        updateUIState: function() {
            this.elements.importButtons.prop('disabled', this.status.importRunning);
        },

        startProgressUpdates: function() {
            if (this.status.progressInterval) clearInterval(this.status.progressInterval);
            this.status.progressInterval = setInterval(() => this.updateProgress(), 5000);
        },

        updateProgress: function() {
            this.performAjaxRequest({ action: 'csv_import_get_progress' })
                .done(response => {
                    if (response.success && response.data) {
                        const progress = response.data;
                        this.elements.progressBar.css('width', progress.percent + '%');
                        if (!progress.running && this.status.progressInterval) {
                            clearInterval(this.status.progressInterval);
                        }
                    }
                });
        },

        showTestProgress: function(message) {
            this.elements.resultsContainer.html(`<div class="test-result test-progress">🔄 ${message}</div>`);
        },

        showTestResult: function(message, success) {
            const resultClass = success ? 'test-success' : 'test-error';
            const icon = success ? '✅' : '❌';
            this.elements.resultsContainer.html(`<div class="test-result ${resultClass}">${icon} ${message}</div>`);
        },

        showSampleData: function(columns, sampleData) {
            const maxCols = 6;
            const displayColumns = columns.slice(0, maxCols);
            const hasMoreCols = columns.length > maxCols;

            let tableHtml = `<div class="sample-data-header"><h4>📊 Beispieldaten</h4><span class="sample-info">${sampleData.length} Zeilen, ${columns.length} Spalten</span></div><table class="wp-list-table widefat striped"><thead><tr>`;
            displayColumns.forEach(col => tableHtml += `<th>${col}</th>`);
            if (hasMoreCols) tableHtml += `<th class="more-cols">...</th>`;
            tableHtml += '</tr></thead><tbody>';
            sampleData.forEach(row => {
                tableHtml += '<tr>';
                const displayRow = row.slice(0, maxCols);
                displayRow.forEach(cell => {
                     tableHtml += `<td>${cell || ''}</td>`;
                });
                if (hasMoreCols) tableHtml += `<td class="more-cols">...</td>`;
                tableHtml += '</tr>';
            });
            tableHtml += '</tbody></table>';
            if (hasMoreCols) {
                tableHtml += `<p class="description">Zeige ${maxCols} von ${columns.length} Spalten zur Vorschau.</p>`;
            }
            this.elements.sampleDataContainer.html(tableHtml);
        },

        clearSampleData: function() {
            this.elements.sampleDataContainer.empty();
        },
// HILFSFUNKTION ZUM "ENTSCHÄRFEN" VON SONDERZEICHEN
escapeAttr: function(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
},

showColumnMappingUI: function(columns) {
    const self = this; // 'this' für die forEach-Schleife verfügbar machen
    const targetFields = ['post_title', 'post_content', 'post_excerpt', 'post_name', 'featured_image'];
    let tableHtml = '<h4>Spalten zuordnen</h4><table class="wp-list-table widefat striped"><thead><tr><th>CSV-Spalte</th><th>WordPress-Feld</th></tr></thead><tbody>';

    columns.forEach(column => {
        // Die Spaltenüberschrift vor der Verwendung "entschärfen"
        const escapedColumn = self.escapeAttr(column);
        let optionsHtml = '<option value="">-- Ignorieren --</option>';

        targetFields.forEach(field => {
            const isSelected = column.toLowerCase().replace(/[ -]/g, '_') === field;
            optionsHtml += `<option value="${field}" ${isSelected ? 'selected' : ''}>${field}</option>`;
        });

        // Die entschärfte Variable im 'name'-Attribut verwenden
        tableHtml += `<tr><td><strong>${column}</strong></td><td><select name="csv_mapping[${escapedColumn}]">${optionsHtml}</select></td></tr>`;
    });

    tableHtml += '</tbody></table>';
    this.elements.mappingContainer.find('#mapping-table-target').html(tableHtml);
    $('#csv-column-mapping-container').show();
    $('#csv-seo-preview-container').show();
    $('#csv-column-mapping-container').css('grid-column', 'auto');
},

    $(document).ready(function() {
        if (typeof CSVImportAdmin !== 'undefined') {
            CSVImportAdmin.init();
            window.CSVImportAdmin = CSVImportAdmin;
        }
    });

})(jQuery);
