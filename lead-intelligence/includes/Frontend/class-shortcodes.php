<?php
namespace LeadIntelligence\Frontend;

use LeadIntelligence\Admin\Controllers\DashboardController;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registrador de Shortcodes do Lead Intelligence
 * Permite exibir o Dashboard e relatórios em qualquer página do WordPress / Elementor.
 */
class Shortcodes {

    public static function init() {
        // Registra o shortcode principal e seu alias curto
        add_shortcode('lead_intelligence_dashboard', [__CLASS__, 'render_dashboard']);
        add_shortcode('li_dashboard', [__CLASS__, 'render_dashboard']);

        // Registra assets para o frontend
        add_action('wp_enqueue_scripts', [__CLASS__, 'register_assets']);
    }

    /**
     * Registra os estilos para serem enfileirados sob demanda
     */
    public static function register_assets() {
        wp_register_style(
            'li-admin-css',
            LEAD_INTELLIGENCE_PLUGIN_URL . 'assets/css/admin-common.css',
            [],
            LEAD_INTELLIGENCE_VERSION
        );
    }

    /**
     * Renderiza o Dashboard via Shortcode
     *
     * Atributos suportados:
     * - public: 'false' (padrão) | 'true' (permite acesso público sem login)
     * - capability: 'manage_options' (padrão se public='false')
     * - periodo: '30d' (padrão) | '7d' | 'month' | 'last_month' | 'all'
     * - canal: '' (todos) | 'google_ads' | 'meta_ads' | 'whatsapp'
     * - show_header: 'true' (padrão) | 'false'
     * - show_import: 'false' (padrão no frontend) | 'true'
     * - show_filters: 'true' (padrão) | 'false'
     * - show_channel_compare: 'true' (padrão) | 'false'
     * - show_kpis: 'true' (padrão) | 'false'
     * - show_calculator: 'true' (padrão) | 'false'
     * - show_chart: 'true' (padrão) | 'false'
     * - show_campaigns: 'true' (padrão) | 'false'
     * - show_creatives: 'true' (padrão) | 'false'
     * - title: Título customizado
     * - subtitle: Subtítulo customizado
     */
    public static function render_dashboard($atts = []) {
        // Assegura que o CSS esteja enfileirado na página
        if (!wp_style_is('li-admin-css', 'enqueued')) {
            wp_enqueue_style(
                'li-admin-css',
                LEAD_INTELLIGENCE_PLUGIN_URL . 'assets/css/admin-common.css',
                [],
                LEAD_INTELLIGENCE_VERSION
            );
        }

        $atts = shortcode_atts([
            'public'               => 'false',
            'capability'           => 'manage_options',
            'theme'                => 'light',
            'full_width'           => 'false',
            'periodo'              => '30d',
            'canal'                => '',
            'show_header'          => 'true',
            'show_import'          => 'false',
            'show_filters'         => 'true',
            'show_channel_compare' => 'true',
            'show_kpis'            => 'true',
            'show_calculator'      => 'true',
            'show_chart'           => 'true',
            'show_campaigns'       => 'true',
            'show_creatives'       => 'true',
            'title'                => '',
            'subtitle'             => '',
            'logo'                 => '',
            'logo_dark'            => '',
            'logo_light'           => '',
            'logo_compact'         => '',
        ], $atts, 'lead_intelligence_dashboard');

        // Verificação de permissões
        $is_public = filter_var($atts['public'], FILTER_VALIDATE_BOOLEAN);
        $capability = sanitize_key($atts['capability'] ?: 'manage_options');

        if (!$is_public && !current_user_can($capability)) {
            return self::render_access_denied();
        }

        // Opções para o DashboardController
        $options = [
            'is_frontend'          => true,
            'theme'                => 'light',
            'full_width'           => filter_var($atts['full_width'], FILTER_VALIDATE_BOOLEAN),
            'periodo'              => sanitize_text_field($atts['periodo']),
            'canal'                => sanitize_key($atts['canal']),
            'show_header'          => filter_var($atts['show_header'], FILTER_VALIDATE_BOOLEAN),
            'show_import'          => filter_var($atts['show_import'], FILTER_VALIDATE_BOOLEAN),
            'show_filters'         => filter_var($atts['show_filters'], FILTER_VALIDATE_BOOLEAN),
            'show_channel_compare' => filter_var($atts['show_channel_compare'], FILTER_VALIDATE_BOOLEAN),
            'show_kpis'            => filter_var($atts['show_kpis'], FILTER_VALIDATE_BOOLEAN),
            'show_calculator'      => filter_var($atts['show_calculator'], FILTER_VALIDATE_BOOLEAN),
            'show_chart'           => filter_var($atts['show_chart'], FILTER_VALIDATE_BOOLEAN),
            'show_campaigns'       => filter_var($atts['show_campaigns'], FILTER_VALIDATE_BOOLEAN),
            'show_creatives'       => filter_var($atts['show_creatives'], FILTER_VALIDATE_BOOLEAN),
        ];

        if (!empty($atts['title'])) {
            $options['title'] = sanitize_text_field($atts['title']);
        }
        if (!empty($atts['subtitle'])) {
            $options['subtitle'] = sanitize_text_field($atts['subtitle']);
        }
        if (!empty($atts['logo_dark'])) {
            $options['logo_dark'] = esc_url_raw($atts['logo_dark']);
        } elseif (!empty($atts['logo'])) {
            $options['logo_dark'] = esc_url_raw($atts['logo']);
        }
        if (!empty($atts['logo_light'])) {
            $options['logo_light'] = esc_url_raw($atts['logo_light']);
        } elseif (!empty($atts['logo'])) {
            $options['logo_light'] = esc_url_raw($atts['logo']);
        }
        if (!empty($atts['logo_compact'])) {
            $options['logo_compact'] = esc_url_raw($atts['logo_compact']);
        }

        ob_start();
        DashboardController::render($options);
        return ob_get_clean();
    }

    /**
     * Card de Acesso Restrito quando usuário não tem permissão
     */
    private static function render_access_denied() {
        $login_url = wp_login_url(get_permalink());
        ob_start();
        ?>
        <div class="li-wrap li-frontend-wrap">
            <div class="li-card" style="text-align: center; padding: 48px 24px; max-width: 600px; margin: 30px auto; border-top: 4px solid #ef4444;">
                <div style="font-size: 42px; line-height: 1; margin-bottom: 16px;">🔒</div>
                <h3 style="margin: 0 0 10px 0; color: #0f172a; font-size: 20px;">Acesso Restrito ao Dashboard</h3>
                <p style="color: #64748b; font-size: 14px; line-height: 1.6; margin: 0 0 24px 0;">
                    <?php if (is_user_logged_in()): ?>
                        Sua conta não possui permissão administrativa para visualizar os dados de conversão e leads deste painel.
                    <?php else: ?>
                        Este painel contém métricas internas confidenciais da empresa. Faça login com uma conta autorizada para visualizar.
                    <?php endif; ?>
                </p>

                <?php if (!is_user_logged_in()): ?>
                    <a href="<?php echo esc_url($login_url); ?>" class="button button-primary li-btn li-btn-primary" style="height: 40px; padding: 0 24px; font-size: 14px;">
                        Fazer Login
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
