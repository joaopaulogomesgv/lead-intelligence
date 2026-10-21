<?php
namespace LeadIntelligence\Admin;

use LeadIntelligence\Admin\Controllers\DashboardController;
use LeadIntelligence\Admin\Controllers\LeadsController;
use LeadIntelligence\Admin\Controllers\LogsController;
use LeadIntelligence\Admin\Controllers\ImportController;
use LeadIntelligence\Admin\Controllers\WhatsAppController;
use LeadIntelligence\Admin\Controllers\PolosController;
use LeadIntelligence\Admin\Controllers\MetaController;
use LeadIntelligence\Admin\Controllers\MetaAdsController;
use LeadIntelligence\Admin\Controllers\GoogleAdsController;
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
        add_action('wp_ajax_li_meta_ads_update_lead', [MetaAdsController::class, 'ajax_update_lead_status']);

        // Inicializa listeners de formulários do controller de importação
        ImportController::init();
    }

    public static function register_menus() {
        $capability = 'manage_options';

        // 1. Menu Principal (Abre diretamente Todos os Leads)
        add_menu_page(
            'Lead Intelligence',
            'Lead Intelligence',
            $capability,
            'lead-intelligence',
            [LeadsController::class, 'render'],
            'dashicons-groups',
            30
        );

        // 2. Submenu 1: Todos os Leads
        add_submenu_page(
            'lead-intelligence',
            'Todos os Leads - Lead Intelligence',
            '👥 Todos os Leads',
            $capability,
            'lead-intelligence',
            [LeadsController::class, 'render']
        );

        // 3. Submenu: Meta Ads
        add_submenu_page(
            'lead-intelligence',
            'Meta Ads - Lead Intelligence',
            '🔵 Meta Ads',
            $capability,
            'lead-intelligence-meta',
            [__CLASS__, 'render_meta_page']
        );

        // 4. Submenu: Google Ads
        add_submenu_page(
            'lead-intelligence',
            'Google Ads - Lead Intelligence',
            '🟢 Google Ads',
            $capability,
            'lead-intelligence-google',
            [__CLASS__, 'render_google_page']
        );

        // 5. Submenu: Importar Planilha
        add_submenu_page(
            'lead-intelligence',
            'Importações - Lead Intelligence',
            '📥 Importar Planilha',
            $capability,
            'lead-intelligence-import',
            [ImportController::class, 'render']
        );

        // 7. Submenu: Configurações
        add_submenu_page(
            'lead-intelligence',
            'Configurações - Lead Intelligence',
            '⚙️ Configurações',
            $capability,
            'lead-intelligence-settings',
            [Settings::class, 'render']
        );

        // 8. Submenu: Logs
        add_submenu_page(
            'lead-intelligence',
            'Logs do Sistema - Lead Intelligence',
            '📋 Logs',
            $capability,
            'lead-intelligence-logs',
            [LogsController::class, 'render']
        );

        // ========================================================
        // ROTAS LEGADAS INVISÍVEIS (GARANTE QUE NENHUM LINK QUEBRE)
        // ========================================================
        add_submenu_page(
            null,
            'WhatsApp Cloud - Lead Intelligence',
            'WhatsApp Cloud',
            $capability,
            'lead-intelligence-whatsapp',
            [__CLASS__, 'render_whatsapp_page']
        );

        add_submenu_page(
            null,
            'Polos WhatsApp',
            'Polos WhatsApp',
            $capability,
            'lead-intelligence-polos',
            [PolosController::class, 'render']
        );

        add_submenu_page(
            null,
            'Qualificações',
            'Qualificações',
            $capability,
            'lead-intelligence-qualificacoes',
            [LeadsController::class, 'render']
        );

        add_submenu_page(
            null,
            'Relatórios',
            'Relatórios',
            $capability,
            'lead-intelligence-relatorios',
            [__CLASS__, 'render_dashboard_page']
        );

        add_submenu_page(
            null,
            'Dashboard Geral',
            'Dashboard Geral',
            $capability,
            'lead-intelligence-dashboard',
            [__CLASS__, 'render_dashboard_page']
        );

        add_submenu_page(
            null,
            'Todos os Leads',
            'Todos os Leads',
            $capability,
            'lead-intelligence-leads',
            [LeadsController::class, 'render']
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

    public static function render_dashboard_page() {
        DashboardController::render(['tab' => 'dashboard']);
    }

    public static function render_meta_page() {
        MetaAdsController::render();
    }

    public static function render_google_page() {
        GoogleAdsController::render();
    }

    public static function render_whatsapp_page() {
        $view = isset($_GET['view']) && in_array($_GET['view'], ['chat', 'polos'], true) ? sanitize_key($_GET['view']) : (isset($_GET['action']) ? 'polos' : 'chat');
        ?>
        <div class="wrap li-wrap" style="margin-bottom: 0; padding-bottom: 0;">
            <div class="li-channel-subnav" style="display: flex; gap: 8px; margin: 12px 0 14px 0; border-bottom: 2px solid #e2e8f0; padding-bottom: 10px;">
                <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-whatsapp&view=chat')); ?>" 
                   class="button <?php echo $view === 'chat' ? 'button-primary' : 'button-secondary'; ?>" 
                   style="display: inline-flex; align-items: center; gap: 6px; font-weight: 600; padding: 5px 14px; height: 32px; border-radius: 6px; <?php echo $view === 'chat' ? 'background: #25d366; border-color: #25d366; color: #fff;' : ''; ?>">
                    💬 Central &amp; Webhook WhatsApp
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-whatsapp&view=polos')); ?>" 
                   class="button <?php echo $view === 'polos' ? 'button-primary' : 'button-secondary'; ?>" 
                   style="display: inline-flex; align-items: center; gap: 6px; font-weight: 600; padding: 5px 14px; height: 32px; border-radius: 6px; <?php echo $view === 'polos' ? 'background: #25d366; border-color: #25d366; color: #fff;' : ''; ?>">
                    🏢 Polos &amp; Números WABA
                </a>
            </div>
        </div>
        <?php
        if ($view === 'polos') {
            PolosController::render();
        } else {
            WhatsAppController::render();
        }
    }

    public static function render_dashboard_or_leads() {
        self::render_dashboard_page();
    }
}
