<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Direkten Zugriff auf die Datei verhindern
}

/**
 * Erstellt die Admin-Menüs und steuert die Anzeige der Plugin-Seiten.
 * Version 9.2 - Finale, vollständige Version mit korrekter POST-Verarbeitung.
 */
class CSV_Import_Pro_Admin {

	private array $admin_pages = [];

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        add_action( 'admin_init', [ $this, 'register_plugin_settings' ] );
        add_action( 'admin_notices', [ $this, 'show_plugin_notices' ] );
	}

    public function register_admin_menu(): void {
        add_menu_page(
            __( 'CSV Importer Pro', 'csv-import' ),
            'CSV Importer', // Kürzerer Titel für das Menü
            'edit_pages',
            'csv-import',
            [ $this, 'display_main_page' ],
            'dashicons-database-import',
            25
        );

        $submenus = [
            'main' => ['title' => 'Dashboard', 'slug' => 'csv-import', 'callback' => 'display_main_page', 'capability' => 'edit_pages'],
            'settings' => ['title' => 'Einstellungen', 'slug' => 'csv-import-settings', 'callback' => 'display_settings_page', 'capability' => 'edit_pages'],
            'profiles' => ['title' => 'Import-Profile', 'slug' => 'csv-import-profiles', 'callback' => 'display_profiles_page', 'capability' => 'edit_pages'],
            'scheduling' => ['title' => 'Automatisierung', 'slug' => 'csv-import-scheduling', 'callback' => 'display_scheduling_page', 'capability' => 'manage_options'],
            'backups' => ['title' => 'Backups & Rollback', 'slug' => 'csv-import-backups', 'callback' => 'display_backup_page', 'capability' => 'edit_pages'],
            'logs' => ['title' => 'Logs & Monitoring', 'slug' => 'csv-import-logs', 'callback' => 'display_logs_page', 'capability' => 'edit_pages'],
            'seo_preview' => ['title' => 'SEO-Vorschau', 'slug' => 'csv-import-seo-preview', 'callback' => 'display_seo_preview_page', 'capability' => 'edit_pages'],
            'debug' => ['title' => 'Debug', 'slug' => 'csv-import-debug', 'callback' => 'display_debug_page', 'capability' => 'manage_options'],
        ];

        foreach ($submenus as $key => $submenu) {
            $this->admin_pages[$key] = add_submenu_page(
                'csv-import',
                'CSV Import ' . $submenu['title'],
                $submenu['title'],
                $submenu['capability'],
                $submenu['slug'],
                [ $this, $submenu['callback'] ]
            );
        }
    }

    // --- Display-Methoden für alle Seiten ---
    public function display_main_page(): void { $this->render_page('page-main.php'); }
    public function display_settings_page(): void { $this->render_page('page-settings.php'); }
    public function display_backup_page(): void { $this->render_page('page-backups.php'); }
    public function display_profiles_page(): void { $this->render_page('page-profiles.php'); }
    public function display_scheduling_page(): void { $this->render_page('page-scheduling.php'); }
    public function display_logs_page(): void { $this->render_page('page-logs.php'); }
    public function display_seo_preview_page(): void { $this->render_page('page-seo-preview.php'); }
    public function display_debug_page(): void { $this->render_page('page-debug.php'); }

    /**
     * Zentraler Renderer für alle Admin-Seiten.
     */
    private function render_page(string $template_file): void {
        $data = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = array_merge($data, $this->handle_post_request($template_file));
        }

        $data = array_merge($data, $this->gather_view_data($template_file));
        
        extract($data);
        $template_path = CSV_IMPORT_PRO_PATH . 'includes/admin/views/' . $template_file;
        if ( file_exists( $template_path ) ) {
            include $template_path;
        } else {
            echo '<div class="wrap"><h2>Template-Datei nicht gefunden: ' . esc_html($template_file) . '</h2></div>';
        }
    }

    /**
     * Verarbeitet alle POST-Anfragen basierend auf dem 'action'-Parameter.
     */
    private function handle_post_request(string $template_file): array {
        $action = isset($_POST['action']) ? sanitize_key($_POST['action']) : '';
        if (empty($action)) return [];

        switch ($template_file) {
            case 'page-settings.php':
                if ($action === 'generate_template_from_csv') {
                    return $this->handle_template_generator_form();
                }
                break; // Wichtig: Hier abbrechen, damit nicht weitergemacht wird
            case 'page-scheduling.php':
                return $this->handle_scheduling_form();
            case 'page-backups.php':
                return $this->handle_backup_form();
            case 'page-profiles.php':
                return $this->handle_profile_form();
            case 'page-logs.php':
                if ($action === 'clear_logs') {
                    return $this->handle_logs_form();
                }
                break;
            case 'page-debug.php':
                if (isset($_POST['csv_import_cleanup'])) {
                     return $this->handle_debug_form();
                }
                break;
        }
        return [];
    }

    /**
     * Sammelt alle notwendigen Daten für die verschiedenen Admin-Seiten.
     */
    private function gather_view_data(string $template_file): array {
        $data = [];
        if (function_exists('csv_import_get_config')) $data['config'] = csv_import_get_config();
        if (function_exists('csv_import_system_health_check')) $data['health'] = csv_import_system_health_check();
        
        switch ($template_file) {
            case 'page-scheduling.php':
                if (class_exists('CSV_Import_Scheduler')) $data = array_merge($data, CSV_Import_Scheduler::get_scheduler_info());
                break;
            case 'page-backups.php':
                if (class_exists('CSV_Import_Backup_Manager')) $data['sessions'] = CSV_Import_Backup_Manager::get_import_sessions();
                break;
            case 'page-profiles.php':
                if (class_exists('CSV_Import_Profile_Manager')) $data['profiles'] = CSV_Import_Profile_Manager::get_profiles();
                break;
            case 'page-logs.php':
                if (class_exists('CSV_Import_Error_Handler')) {
                    $data = array_merge($data, $this->prepare_logs_data());
                    if (function_exists('csv_import_get_error_stats')) $data['error_stats'] = csv_import_get_error_stats();
                }
                break;
            case 'page-debug.php':
                $data = array_merge($data, $this->prepare_debug_data());
                break;
        }
        return $data;
    }

    // --- Formular-Handler ---

    private function handle_template_generator_form(): array {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'csv_import_generate_template')) {
            return ['action_result' => ['success' => false, 'message' => 'Sicherheitscheck fehlgeschlagen.']];
        }

        $base_post_id = isset($_POST['base_template_id']) ? intval($_POST['base_template_id']) : 0;
        $new_template_name = isset($_POST['new_template_name']) ? sanitize_text_field($_POST['new_template_name']) : '';

        if (empty($base_post_id) || empty($new_template_name)) {
            return ['action_result' => ['success' => false, 'message' => 'Bitte geben Sie eine Basis-ID und einen Namen an.']];
        }

        if (class_exists('CSV_Import_Template_Manager')) {
            $template_id_or_error = CSV_Import_Template_Manager::create_template_from_csv_headers($base_post_id, $new_template_name);

            if (is_wp_error($template_id_or_error)) {
                return ['action_result' => ['success' => false, 'message' => $template_id_or_error->get_error_message()]];
            }
            
            $edit_link = get_edit_post_link($template_id_or_error);
            $message = sprintf('Template "%s" erfolgreich erstellt. <a href="%s" class="button button-small">Jetzt bearbeiten</a>', esc_html($new_template_name), esc_url($edit_link));
            return ['action_result' => ['success' => true, 'message' => $message]];
        }
        
        return ['action_result' => ['success' => false, 'message' => 'Fehler: Template Manager Klasse nicht gefunden.']];
    }
    
    private function handle_scheduling_form(): array {
        $action = sanitize_key($_POST['action']);
        
        if ($action === 'schedule_import' && wp_verify_nonce($_POST['_wpnonce'] ?? '', 'csv_import_scheduling')) {
            $frequency = sanitize_key($_POST['frequency'] ?? '');
            $source = sanitize_key($_POST['import_source'] ?? '');
            $schedule_result = CSV_Import_Scheduler::schedule_import($frequency, $source);
            if(is_wp_error($schedule_result)) return ['action_result' => ['success' => false, 'message' => 'Scheduling fehlgeschlagen: ' . $schedule_result->get_error_message()]];
            return ['action_result' => ['success' => true, 'message' => 'Geplanter Import wurde erfolgreich aktiviert!']];
        }

        if ($action === 'unschedule_import' && wp_verify_nonce($_POST['_wpnonce'] ?? '', 'csv_import_scheduling')) {
            CSV_Import_Scheduler::unschedule_import();
            return ['action_result' => ['success' => true, 'message' => 'Geplanter Import wurde deaktiviert.']];
        }
        
        return [];
    }

    private function handle_backup_form(): array {
        if (isset($_POST['rollback_session']) && wp_verify_nonce($_POST['_wpnonce'] ?? '', 'csv_import_rollback')) {
            return ['rollback_result' => CSV_Import_Backup_Manager::rollback_import(sanitize_text_field($_POST['rollback_session']))];
        }
        if (isset($_POST['cleanup_backups']) && wp_verify_nonce($_POST['_wpnonce'] ?? '', 'csv_import_cleanup_backups')) {
            $retention_days = get_option('csv_import_advanced_settings', ['backup_retention_days' => 30])['backup_retention_days'] ?? 30;
            return ['deleted_count' => CSV_Import_Backup_Manager::cleanup_old_backups($retention_days)];
        }
        return [];
    }

    private function handle_profile_form(): array {
        $action = sanitize_key($_POST['action'] ?? '');
        if (empty($action) || !class_exists('CSV_Import_Profile_Manager')) return [];

        if ($action === 'save_profile' && wp_verify_nonce($_POST['_wpnonce'] ?? '', 'csv_import_save_profile')) {
             $profile_name = sanitize_text_field($_POST['profile_name'] ?? '');
             $config = function_exists('csv_import_get_config') ? csv_import_get_config() : [];
             CSV_Import_Profile_Manager::save_profile($profile_name, $config);
             return ['action_result' => ['success' => true, 'message' => "Profil '{$profile_name}' erfolgreich gespeichert."]];
        }
        return [];
    }
    
    private function handle_logs_form(): array {
        if (wp_verify_nonce($_POST['_wpnonce'] ?? '', 'csv_import_clear_logs')) {
            CSV_Import_Error_Handler::clear_error_log();
            wp_redirect(add_query_arg('logs_cleared', 'true', remove_query_arg(['_wpnonce', 'action'])));
            exit;
        }
        return [];
    }
    
    private function handle_debug_form(): array {
        if (wp_verify_nonce($_POST['_wpnonce'] ?? '', 'csv_import_debug')) {
            csv_import_force_reset_import_status();
            return ['cleanup_message' => 'Bereinigung erfolgreich durchgeführt.'];
        }
        return [];
    }

    // --- Daten-Vorbereitungs-Methoden ---

    private function prepare_logs_data(): array {
        $all_logs = array_reverse(CSV_Import_Error_Handler::get_persistent_errors());
        $filter_level = isset($_GET['level']) ? sanitize_key($_GET['level']) : 'all';
        $filtered_logs = ($filter_level !== 'all') ? array_filter($all_logs, fn($log) => ($log['level'] ?? '') === $filter_level) : $all_logs;

        $per_page = 50;
        $total_logs = count($filtered_logs);
        $total_pages = ceil($total_logs / $per_page);
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($page - 1) * $per_page;
        
        return ['logs' => array_slice($filtered_logs, $offset, $per_page), 'filter_level' => $filter_level, 'page' => $page, 'total_pages' => $total_pages];
    }
    
    private function prepare_debug_data(): array {
        global $wpdb;
        $data = ['locks' => [], 'stuck_jobs' => []];
        $data['locks'] = $wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE '%csv_import%lock%' OR option_name LIKE '%csv_import_running%'");
        return $data;
    }

    // --- Asset- und Einstellungs-Registrierung ---

    public function enqueue_admin_assets(string $hook_suffix): void {
        if (strpos($hook_suffix, 'csv-import') === false) return;
        
        wp_enqueue_style('csv-import-pro-admin-style', CSV_IMPORT_PRO_URL . "assets/css/admin-style.css", [], CSV_IMPORT_PRO_VERSION);
        wp_enqueue_script('csv-import-pro-admin-script', CSV_IMPORT_PRO_URL . "assets/js/admin-script.js", ['jquery'], CSV_IMPORT_PRO_VERSION, true);
        
        if ($hook_suffix === $this->admin_pages['seo_preview'] ?? '') {
             wp_enqueue_style('csv-seo-preview', CSV_IMPORT_PRO_URL . 'assets/css/seo-preview.css', [], CSV_IMPORT_PRO_VERSION);
             wp_enqueue_script('csv-seo-preview', CSV_IMPORT_PRO_URL . 'assets/js/seo-preview.js', ['jquery'], CSV_IMPORT_PRO_VERSION, true);
        }

        wp_localize_script('csv-import-pro-admin-script', 'csvImportAjax', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('csv_import_ajax'),
            'import_running' => function_exists('csv_import_is_import_running') ? csv_import_is_import_running() : false,
        ]);
    }
    
    public function register_plugin_settings(): void {
        $settings = [
            'template_id', 'post_type', 'post_status', 'page_builder', 'dropbox_url',
            'local_path', 'image_source', 'image_folder', 'seo_plugin', 'required_columns',
            'skip_duplicates', 'delimiter', 'noindex_posts'
        ];
        foreach ($settings as $setting) {
            register_setting('csv_import_settings', 'csv_import_' . $setting);
        }
    }
    
    public function show_plugin_notices(): void {
        if (get_transient('csv_import_stuck_reset_notice')) {
            echo '<div class="notice notice-warning is-dismissible"><p><strong>CSV Import:</strong> Ein hängender Import-Prozess wurde automatisch zurückgesetzt.</p></div>';
            delete_transient('csv_import_stuck_reset_notice');
        }
    }
}
