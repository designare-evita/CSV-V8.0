jQuery(document).ready(function($) {
    'use strict';
    
    const SEOPreview = {
        init: function() {
            this.bindEvents();
            this.updatePreview(); // Initial preview with placeholder data
        },
        
        bindEvents: function() {
            // Tab-Wechsel für Suchmaschinen
            $(document).on('click', '.seo-tab', function() {
                $('.seo-tab').removeClass('active');
                $(this).addClass('active');
                
                const engine = $(this).data('engine');
                $('.serp-preview').removeClass('active');
                $('.' + engine + '-serp').addClass('active');
            });
            
            // Tab-Wechsel für Geräte
            $(document).on('click', '.device-tab', function() {
                $('.device-tab').removeClass('active');
                $(this).addClass('active');
                
                const device = $(this).data('device');
                if (device === 'mobile') {
                    $('.seo-preview-container').addClass('mobile-view');
                } else {
                    $('.seo-preview-container').removeClass('mobile-view');
                }
            });
            
            // Live-Update, wenn der Benutzer in die SEO-Felder tippt
            $(document).on('input change', '#seo_title, #seo_description', 
                this.debounce(this.updatePreview, 300)
            );
        },
        
        // KORRIGIERTE FUNKTION: Akzeptiert jetzt ein 'data' Objekt
        updatePreview: function(data) {
            const title = (data && data.seo_title) ? data.seo_title : SEOPreview.getCurrentTitle();
            const description = (data && data.seo_description) ? data.seo_description : SEOPreview.getCurrentDescription();
            const slug = SEOPreview.generateSlug(title);
            
            // UI aktualisieren
            SEOPreview.updateSERPDisplay(title, description, slug);
            
            // AJAX-Validierung
            SEOPreview.validateSEO(title, description, slug);
        },
        
        getCurrentTitle: function() {
            // Liest Daten direkt aus den Feldern auf der SEO-Seite
            return ($('#seo_title').val() || 'Beispiel Seitentitel').trim();
        },
        
        getCurrentDescription: function() {
            return ($('#seo_description').val() || 'Beispiel Meta-Description für bessere Suchergebnisse.').trim();
        },
        
        generateSlug: function(title) {
            if (!title) return 'beispiel-seite';
            return title.toLowerCase()
                       .replace(/[^a-z0-9\s-]/g, '')
                       .replace(/\s+/g, '-')
                       .replace(/-+/g, '-')
                       .replace(/^-|-$/g, '');
        },
        
        updateSERPDisplay: function(title, description, slug) {
            const domain = csvSeoPreview.domain || 'example.com';
            const displayUrl = domain + '/' + slug;
            
            // Google Preview Update
            $('#google-title-preview').text(title);
            $('#google-desc-preview').text(description);
            $('#google-url-preview').text(displayUrl);
            
            // Bing Preview Update
            $('#bing-title-preview').text(title);
            $('#bing-desc-preview').text(description);
            $('#bing-url-preview').text(displayUrl);
        },
        
        validateSEO: function(title, description, slug) {
            $.ajax({
                url: csvSeoPreview.ajaxurl,
                type: 'POST',
                data: {
                    action: 'csv_seo_preview_validate',
                    nonce: csvSeoPreview.nonce,
                    title: title,
                    description: description,
                    slug: slug
                },
                success: function(response) {
                    if (response.success) {
                        SEOPreview.updateMetrics(response.data);
                    }
                },
                error: function() {
                    console.warn('SEO Preview: Validation failed');
                }
            });
        },
        
        updateMetrics: function(data) {
    // Titel-Länge
    const titleLength = data.title.length;
    const titleStatus = data.title.status;
    $('#title-length-metric')
        .removeClass('good warning bad')
        .addClass(titleStatus)
        .text(titleLength + ' Zeichen' + SEOPreview.getStatusIcon(titleStatus));

    // Description-Länge
    const descLength = data.description.length;
    const descStatus = data.description.status;
    $('#desc-length-metric')
        .removeClass('good warning bad')
        .addClass(descStatus)
        .text(descLength + ' Zeichen' + SEOPreview.getStatusIcon(descStatus));

    // SEO-Score
    const score = data.seo_score;
    let scoreStatus = 'bad';
    let scoreText = 'Optimierung erforderlich';

    if (score >= 80) {
        scoreStatus = 'good';
        scoreText = 'Ausgezeichnet 🌟';
    } else if (score >= 60) {
        scoreStatus = 'good';
        scoreText = 'Gut 👍';
    } else if (score >= 40) {
        scoreStatus = 'warning';
        scoreText = 'Verbesserbar ⚠️';
    }

    $('#seo-score-metric')
        .removeClass('good warning bad')
        .addClass(scoreStatus)
        .text(scoreText + ' (' + score + '%)');

    // Empfehlungen
    SEOPreview.updateRecommendations(data.recommendations);
},
        
        updateRecommendations: function(recommendations) {
            const container = $('#seo-recommendations');
            container.empty();
            
            if (!recommendations || recommendations.length === 0) {
                container.html('<div class="seo-recommendation"><span class="recommendation-icon good">✅</span><span class="recommendation-text">Alle SEO-Kriterien erfüllt!</span></div>');
                return;
            }
            
            recommendations.forEach(function(rec) {
                const iconMap = {
                    'info': 'ℹ️',
                    'warning': '⚠️',
                    'error': '❌'
                };
                
                const html = '<div class="seo-recommendation">' +
                    '<span class="recommendation-icon ' + rec.type + '">' + iconMap[rec.type] + '</span>' +
                    '<span class="recommendation-text">' + rec.message + '</span>' +
                    '</div>';
                
                container.append(html);
            });
        },
        
        getStatusIcon: function(status) {
            const icons = {
                'good': ' ✓',
                'warning': ' ⚠️',
                'bad': ' ❌'
            };
            return icons[status] || '';
        },
        
        debounce: function(func, wait) {
            let timeout;
            return function() {
                const context = this;
                const args = arguments;
                clearTimeout(timeout);
                timeout = setTimeout(function() {
                    func.apply(context, args);
                }, wait);
            };
        }
    };
    
    // Initialisierung
    SEOPreview.init();
    
    // Global verfügbar machen, damit andere Skripte darauf zugreifen können
    window.CSVSEOPreview = SEOPreview;
});
