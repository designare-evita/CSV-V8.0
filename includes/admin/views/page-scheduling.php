<?php
/**
 * View-Datei für die Scheduling Seite.
 * KORRIGIERTE VERSION: Robuste Aktivierungs-Box und wiederhergestellte Formulare.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Scheduler-Status und alle notwendigen Daten abrufen
$scheduler_enabled = function_exists('csv_import_is_scheduler_enabled') ? csv_import_is_scheduler_enabled() : false;
$scheduler_info = (class_exists('CSV_Import_Scheduler') && method_exists('CSV_Import_Scheduler', 'get_scheduler_info')) ? CSV_Import_Scheduler::get_scheduler_info() : [];

$is_scheduled = $scheduler_info['is_scheduled'] ?? false;
$current_source = $scheduler_info['source'] ?? '';
$current_frequency = $scheduler_info['frequency'] ?? '';
$available_intervals = $scheduler_info['available_intervals'] ?? CSV_Import_Scheduler::INTERVALS;
$next_scheduled = $scheduler_info['next_run'] ?? null;
$notification_settings = get_option('csv_import_notification_settings', [
    'email_on_success' => false,
    'email_on_failure' => true,
    'recipients'       => [get_option('admin_email')]
]);
$config = function_exists('csv_import_get_config') ? csv_import_get_config() : [];
$validation = function_exists('csv_import_validate_config') ? csv_import_validate_config($config) : ['dropbox_ready' => false, 'local_ready' => false];


// Historie für die Anzeige sammeln
$scheduled_imports = [];
if (class_exists('CSV_Import_Error_Handler')) {
    $history = [];
    $all_logs = CSV_Import_Error_Handler::get_persistent_errors();
    if (is_array($all_logs)) {
        foreach ($all_logs as $log) {
            if (isset($log['message']) && (stripos($log['message'], 'geplant') !== false || stripos($log['message'], 'scheduled') !== false)) {
                 $history[] = [
                    'time' => $log['timestamp'] ?? date('Y-m-d H:i:s'),
                    'level' => $log['level'] ?? 'info',
                    'message' => $log['message']
                ];
            }
        }
    }
    if (!empty($history)) {
        usort($history, fn($a, $b) => strtotime($b['time']) - strtotime($a['time']));
        $scheduled_imports = array_slice($history, 0, 20);
    }
}
?>
<div class="wrap">
	<div class="csv-dashboard-header">
		<h1>⏰ CSV Import Automatisierung</h1>
		<p>Konfigurieren und überwachen Sie automatische, zeitgesteuerte CSV-Imports.</p>
	</div>

	<?php
	if ( isset( $action_result ) && is_array( $action_result ) ) {
		$notice_class   = $action_result['success'] ? 'notice-success' : 'notice-error';
		$notice_message = $action_result['message'];
		echo '<div class="notice ' . esc_attr( $notice_class ) . ' is-dismissible"><p>' . wp_kses_post( $notice_message ) . '</p></div>';
	}
	?>

	<div class="csv-import-dashboard">

        <div class="csv-import-box" style="grid-column: 1 / -1; border-left: 4px solid <?php echo $scheduler_enabled ? '#00a32a' : '#d63638'; ?>;">
            <h3>
                <span class="step-icon"><?php echo $scheduler_enabled ? '✅' : '🔒'; ?></span>
                Scheduler-Status: <?php echo $scheduler_enabled ? 'Aktiviert' : 'Deaktiviert'; ?>
            </h3>
            <p>
                <?php if ($scheduler_enabled): ?>
                    Die Automatisierung ist aktiv. Geplante Imports werden automatisch ausgeführt.
                <?php else: ?>
                    Der automatische CSV-Import-Scheduler ist derzeit nicht aktiv und muss zuerst aktiviert werden, bevor Sie Imports planen können.
                <?php endif; ?>
            </p>
            
            <div class="action-buttons">
                <?php if ($scheduler_enabled): ?>
                    <button type="button" class="button button-secondary" onclick="toggleScheduler('disable')">
                        Scheduler jetzt deaktivieren
                    </button>
                <?php else: ?>
                    <button type="button" class="button button-primary button-large" onclick="toggleScheduler('enable')" style="background: #00a32a; border-color: #00a32a;">
                        🚀 Scheduler jetzt aktivieren
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($scheduler_enabled): ?>

            <div class="csv-import-box">
                <?php if ( $is_scheduled ) : ?>
                    <h3>
                        <span class="step-number completed">1</span>
                        <span class="step-icon">✅</span>
                        Aktiver Zeitplan
                    </h3>
                    <span class="status-indicator status-success">Aktiv</span>
                    <ul class="status-list" style="margin: 15px 0;">
                        <li><strong>Quelle:</strong> <?php echo esc_html( ucfirst( $current_source ) ); ?></li>
                        <li><strong>Frequenz:</strong> <?php echo esc_html( $available_intervals[$current_frequency] ?? ucfirst( str_replace( '_', ' ', $current_frequency ) ) ); ?></li>
                    </ul>
                    <form method="post">
                        <?php wp_nonce_field( 'csv_import_scheduling' ); ?>
                        <input type="hidden" name="action" value="unschedule_import">
                        <div class="action-buttons">
                            <button type="submit" class="button button-secondary" onclick="return confirm('Geplante Imports wirklich deaktivieren?');">
                                ⏹️ Scheduling deaktivieren
                            </button>
                        </div>
                    </form>
                <?php else : ?>
                    <h3>
                        <span class="step-number active">1</span>
                        <span class="step-icon">📅</span>
                        Neuen Import planen
                    </h3>
                    <span class="status-indicator status-pending">Inaktiv</span>
                    <p>Planen Sie automatische CSV-Imports basierend auf den aktuellen <a href="<?php echo esc_url(admin_url('admin.php?page=csv-import-settings')); ?>">Einstellungen</a>.</p>
                    <form method="post">
                        <?php wp_nonce_field( 'csv_import_scheduling' ); ?>
                        <input type="hidden" name="action" value="schedule_import">
                        <table class="form-table compact-form">
                            <tbody>
                                <tr>
                                    <th scope="row"><label for="import_source">Import-Quelle</label></th>
                                    <td>
                                        <select id="import_source" name="import_source" required>
                                            <option value="">-- Quelle wählen --</option>
                                            <?php if ( $validation['dropbox_ready'] ) : ?><option value="dropbox">☁️ Dropbox</option><?php endif; ?>
                                            <?php if ( $validation['local_ready'] ) : ?><option value="local">📁 Lokale Datei</option><?php endif; ?>
                                        </select>
                                        <p class="description">Nur konfigurierte Quellen sind sichtbar.</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="frequency">Frequenz</label></th>
                                    <td>
                                        <select id="frequency" name="frequency" required>
                                            <option value="">-- Frequenz wählen --</option>
                                            <?php foreach($available_intervals as $key => $label): ?>
                                                <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <div class="action-buttons" style="margin-top: 20px;">
                            <?php submit_button( '🚀 Import planen', 'primary large', 'submit', false ); ?>
                        </div>
                    </form>
                <?php endif; ?>
            </div>

            <div class="csv-import-box">
                <h3>
                    <span class="step-number">2</span>
                    <span class="step-icon">⚙️</span>
                    Benachrichtigungen
                </h3>
                <p>Legen Sie fest, wer per E-Mail über automatische Imports informiert wird.</p>
                <form method="post">
                    <?php wp_nonce_field( 'csv_import_notification_settings' ); ?>
                    <input type="hidden" name="action" value="update_notifications">
                    <table class="form-table compact-form">
                        <tbody>
                            <tr>
                                <th scope="row">Bei Erfolg</th>
                                <td><label><input type="checkbox" name="email_on_success" value="1" <?php checked( $notification_settings['email_on_success'] ); ?>> E-Mail senden</label></td>
                            </tr>
                            <tr>
                                <th scope="row">Bei Fehlern</th>
                                <td><label><input type="checkbox" name="email_on_failure" value="1" <?php checked( $notification_settings['email_on_failure'] ); ?>> E-Mail senden</label></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="recipients">Empfänger</label></th>
                                <td>
                                    <textarea id="recipients" name="recipients" rows="2" class="large-text"><?php echo esc_textarea( implode( "\n", $notification_settings['recipients'] ) ); ?></textarea>
                                    <p class="description">Eine E-Mail-Adresse pro Zeile.</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div class="action-buttons" style="margin-top: 10px;">
                        <?php submit_button( 'Benachrichtigungen speichern', 'secondary', 'submit', false ); ?>
                    </div>
                </form>
            </div>

            <div style="grid-column: 1 / -1; display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">

                <div class="csv-import-box">
                    <h3>
                        <span class="step-number">3</span>
                        <span class="step-icon">📊</span>
                        Scheduling-Historie
                    </h3>
                    <p>Die letzten Aktionen des Schedulers.</p>
                    <div class="sample-data-container" style="max-height: 300px;">
                        <?php if ( empty( $scheduled_imports ) ) : ?>
                            <div class="info-message"><strong>Info:</strong> Noch keine automatischen Imports gefunden.</div>
                        <?php else : ?>
                            <table class="wp-list-table widefat fixed striped sample-data-table">
                                <thead>
                                    <tr>
                                        <th style="width: 160px;">Zeitpunkt</th>
                                        <th style="width: 100px;">Status</th>
                                        <th>Nachricht</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ( $scheduled_imports as $import ) : ?>
                                        <tr>
                                            <td><?php echo esc_html( mysql2date( 'd.m.Y H:i:s', $import['time'] ?? '' ) ); ?></td>
                                            <td>
                                                <?php 
                                                $log_level = $import['level'] ?? 'info';
                                                if ( in_array( $log_level, ['info', 'debug', 'success'] ) ) : ?>
                                                    <span class="status-indicator status-success" style="padding: 3px 6px;">Erfolg</span>
                                                <?php elseif ( $log_level === 'warning' ) : ?>
                                                    <span class="status-indicator status-pending" style="padding: 3px 6px;">Warnung</span>
                                                <?php else : ?>
                                                    <span class="status-indicator status-error" style="padding: 3px 6px;">Fehler</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo esc_html( $import['message'] ?? 'Keine Nachricht verfügbar' ); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="csv-import-box">
                    <h3>
                        <span class="step-number">4</span>
                        <span class="step-icon">🗓️</span>
                        Nächster geplanter Import
                    </h3>
                    <?php if ( $is_scheduled && $next_scheduled && $next_scheduled > time() ) : ?>
                        <span class="status-indicator status-success">✅ Geplant</span>
                        <p>Der nächste automatische Import wird ausgeführt in:</p>
                        <div class="next-import-timer">
                            <?php echo esc_html( human_time_diff( time(), $next_scheduled ) ); ?>
                        </div>
                        <p class="description" style="text-align: center;">
                            Genauer Zeitpunkt: <?php echo esc_html( date_i18n( 'd.m.Y H:i:s', $next_scheduled ) ); ?>
                        </p>
                    <?php else : ?>
                        <span class="status-indicator status-pending">Inaktiv</span>
                        <p style="margin-top: 15px;">Aktuell ist kein automatischer Import für die Zukunft geplant.</p>
                    <?php endif; ?>
                </div>
            </div>

        <?php else: ?>
            <div class="csv-import-box" style="grid-column: 1 / -1; text-align: center; padding: 40px; color: #8c8f94;">
                <p style="font-size: 1.2em;">Aktivieren Sie zuerst den Scheduler oben, um automatische Imports zu konfigurieren.</p>
            </div>
        <?php endif; ?>

	</div>
</div>

<script>
function toggleScheduler(action) {
    const messages = {
        enable: 'Scheduler wirklich aktivieren?',
        disable: 'Scheduler wirklich deaktivieren? Alle geplanten Imports werden gestoppt.'
    };
    if (!confirm(messages[action])) return;

    const button = event.target;
    button.disabled = true;
    button.textContent = '⏳ Verarbeite...';

    jQuery.post(csvImportAjax.ajaxurl, {
        action: 'csv_scheduler_activation',
        scheduler_action: action,
        nonce: csvImportAjax.nonce
    }, function(response) {
        if (response.success) {
            alert('✅ ' + response.data.message);
            location.reload();
        } else {
            alert('❌ Fehler: ' + response.data.message);
            button.disabled = false;
            button.textContent = action === 'enable' ? '🚀 Scheduler jetzt aktivieren' : 'Scheduler jetzt deaktivieren';
        }
    }).fail(function() {
        alert('❌ Ein schwerwiegender Serverfehler ist aufgetreten.');
        button.disabled = false;
        button.textContent = action === 'enable' ? '🚀 Scheduler jetzt aktivieren' : 'Scheduler jetzt deaktivieren';
    });
}
</script>
