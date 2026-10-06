<?php
namespace LeadIntelligence\Admin;

use LeadIntelligence\Admin\Controllers\DashboardController;
use LeadIntelligence\Admin\Controllers\LeadsController;
use LeadIntelligence\Admin\Controllers\LogsController;
use LeadIntelligence\Admin\Controllers\ImportController;
use LeadIntelligence\Admin\Controllers\WhatsAppController;
use LeadIntelligence\Admin\Controllers\MetaController;
use LeadIntelligence\Admin\Settings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registrador do Menu Administrativo do WordPress
 */
class AdminMenu {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'register_menus']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('wp_ajax_li_sync_lead_ad', [LeadsController::class, 'ajax_sync_lead_ad']);

        // Inicializa listeners de formulários do controller de importação
        ImportController::init();
    }

    public static function register_menus() {
        $capability = 'manage_options';

        // Menu Principal
        add_menu_page(
            'Lead Intelligence',
            'Lead Intelligence',
            $capability,
            'lead-intelligence',
            [__CLASS__, 'render_dashboard_or_leads'],
            'dashicons-chart-pie',
            30
        );

        // Submenus
        add_submenu_page(
            'lead-intelligence',
            'Dashboard - Lead Intelligence',
            'Dashboard',
            $capability,
            'lead-intelligence',
            [__CLASS__, 'render_dashboard_or_leads']
        );

        add_submenu_page(
            'lead-intelligence',
            'Leads - Lead Intelligence',
            'Leads',
            $capability,
            'lead-intelligence-leads',
            [LeadsController::class, 'render']
        );

        add_submenu_page(
            'lead-intelligence',
            'Qualificações - Lead Intelligence',
            'Qualificações',
            $capability,
            'lead-intelligence-qualificacoes',
            [LeadsController::class, 'render']
        );

        add_submenu_page(
            'lead-intelligence',
            'WhatsApp Cloud - Lead Intelligence',
            'WhatsApp',
            $capability,
            'lead-intelligence-whatsapp',
            [WhatsAppController::class, 'render']
        );

        add_submenu_page(
            'lead-intelligence',
            'Meta Ads / CAPI - Lead Intelligence',
            'Meta',
            $capability,
            'lead-intelligence-meta',
            [MetaController::class, 'render']
        );

        add_submenu_page(
            'lead-intelligence',
            'Relatórios - Lead Intelligence',
            'Relatórios',
            $capability,
            'lead-intelligence-relatorios',
            [DashboardController::class, 'render']
        );

        add_submenu_page(
            'lead-intelligence',
            'Importar Planilha - Lead Intelligence',
            'Importar Planilha',
            $capability,
            'lead-intelligence-import',
            [ImportController::class, 'render']
        );

        add_submenu_page(
            'lead-intelligence',
            'Logs do Sistema - Lead Intelligence',
            'Logs',
            $capability,
            'lead-intelligence-logs',
            [LogsController::class, 'render']
        );

        add_submenu_page(
            'lead-intelligence',
            'Configurações - Lead Intelligence',
            'Configurações',
            $capability,
            'lead-intelligence-settings',
            [Settings::class, 'render']
        );
    }

    public static function enqueue_assets($hook) {
        if (strpos($hook, 'lead-intelligence') === false) {
            return;
        }

        wp_enqueue_style(
            'li-admin-css',
            LEAD_INTELLIGENCE_PLUGIN_URL . 'assets/css/admin-common.css',
            [],
            LEAD_INTELLIGENCE_VERSION
        );
    }

    public static function render_dashboard_or_leads() {
        DashboardController::render();
    }

    public static function render_placeholder() {
        $screen = get_current_screen();
        $title = 'Módulo em Preparação';
        ?>
        <div class="wrap li-wrap">
            <div class="li-header">
                <h2>Lead Intelligence &bull; <?php echo esc_html($title); ?></h2>
            </div>
            <div class="li-card" style="text-align: center; padding: 50px 20px;">
                <span class="dashicons dashicons-admin-generic" style="font-size: 48px; width: 48px; height: 48px; color: #0073aa; margin-bottom: 15px;"></span>
                <h3>Este módulo faz parte das próximas fases do cronograma</h3>
                <p style="color: #64748b; max-width: 600px; margin: 0 auto 20px;">
                    O plugin está estruturado conforme a arquitetura modular aprovada. Conforme concluirmos as fases (Fase 1: Elementor, Fase 2: UTMs, Fase 3: Planilhas, Fase 4: Dashboard, Fase 5/6: WhatsApp, Fase 7: Meta CAPI), cada tela será ativada.
                </p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-leads')); ?>" class="button button-primary">
                    Ver Leads Capturados do Elementor
                </a>
            </div>
        </div>
        <?php
    }
}
