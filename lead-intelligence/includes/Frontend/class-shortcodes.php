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

        // Registra papel de usuário limitado e permissões
        add_action('init', [__CLASS__, 'register_roles']);

        // Registra assets para o frontend
        add_action('wp_enqueue_scripts', [__CLASS__, 'register_assets']);
    }

    /**
     * Registra a Role 'lead_viewer' e a capability 'view_lead_intelligence'
     * Permite ao administrador criar contas de usuários limitados apenas para ver métricas.
     */
    public static function register_roles() {
        if (!get_role('lead_viewer')) {
            add_role('lead_viewer', 'Visualizador de Leads (Lead Intelligence)', [
                'read'                   => true,
                'view_lead_intelligence' => true,
            ]);
        }
        $admin = get_role('administrator');
        if ($admin && !$admin->has_cap('view_lead_intelligence')) {
            $admin->add_cap('view_lead_intelligence');
        }
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

        // Se for a página /dashboard/ ou contiver o shortcode, enfileira logo no <head>
        global $post;
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        if (
            (is_a($post, 'WP_Post') && (has_shortcode($post->post_content, 'lead_intelligence_dashboard') || has_shortcode($post->post_content, 'li_dashboard'))) ||
            strpos($uri, 'dashboard') !== false
        ) {
            wp_enqueue_style('li-admin-css');
        }
    }

    /**
     * Valida se o usuário atual possui permissão para visualizar o dashboard
     */
    public static function can_view_dashboard($atts) {
        // 1. Acesso via Chave Secreta/Token na URL (ex: ?key=segredo ou ?token=segredo)
        if (!empty($atts['access_token'])) {
            $req_token = sanitize_text_field($_GET['token'] ?? ($_GET['key'] ?? ''));
            if (!empty($req_token) && hash_equals((string) $atts['access_token'], $req_token)) {
                return true;
            }
        }

        // 2. Por padrão é PRIVADO: Se o visitante não estiver logado, BLOQUEIA (imune a quem descobrir a URL)
        $is_public = filter_var($atts['public'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($is_public) {
            return true;
        }

        if (!is_user_logged_in()) {
            return false;
        }

        // 3. Administradores sempre têm acesso total
        if (current_user_can('manage_options')) {
            return true;
        }

        // 4. Usuários com a permissão específica 'view_lead_intelligence'
        if (current_user_can('view_lead_intelligence')) {
            return true;
        }

        // 5. Verificação de papéis (roles) autorizados (admin, lead_viewer, editor, author)
        $allowed_roles_str = $atts['allowed_roles'] ?? 'administrator,lead_viewer,editor,author';
        $allowed_roles = array_filter(array_map('trim', explode(',', strtolower($allowed_roles_str))));

        $user = wp_get_current_user();
        if (!empty($user->roles) && is_array($user->roles)) {
            foreach ($user->roles as $role) {
                if (in_array(strtolower($role), $allowed_roles, true)) {
                    return true;
                }
            }
        }

        // 6. Capability genérica adicional passada no shortcode
        if (!empty($atts['capability']) && current_user_can(sanitize_key($atts['capability']))) {
            return true;
        }

        return false;
    }

    /**
     * Renderiza o Dashboard via Shortcode
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
            'public'               => 'false', // SEMPRE falso por padrão (segurança total)
            'allowed_roles'        => 'administrator,lead_viewer,editor,author',
            'capability'           => 'view_lead_intelligence',
            'access_token'         => '', // Token opcional para compartilhar link restrito
            'theme'                => 'light',
            'full_width'           => 'false',
            'periodo'              => 'today',
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

        // Verificação de permissões: apenas Administradores ou Usuários Limitados autorizados
        if (!self::can_view_dashboard($atts)) {
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
        try {
            DashboardController::render($options);
        } catch (\Throwable $e) {
            \LeadIntelligence\Logger::error('Falha ao renderizar DashboardController via shortcode: ' . $e->getMessage(), 'dashboard');
            echo '<div class="li-wrap" style="padding: 20px; background: #fff1f2; border: 1px solid #fecdd3; border-radius: 8px; color: #9f1239; margin: 20px;"><strong>Erro ao carregar Dashboard:</strong> ' . esc_html($e->getMessage()) . '</div>';
        }
        return ob_get_clean();
    }

    /**
     * Card de Acesso Restrito com Formulário de Login Seguro
     */
    private static function render_access_denied() {
        $login_url = wp_login_url(get_permalink());
        ob_start();
        ?>
        <div class="li-wrap li-frontend-wrap" style="padding: 20px 0;">
            <div class="li-card" style="text-align: center; padding: 40px 24px; max-width: 480px; margin: 40px auto; border-top: 4px solid #0081fb; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1); border-radius: 12px; background: #ffffff;">
                <div style="font-size: 40px; margin-bottom: 12px;">🔒</div>
                <h3 style="margin: 0 0 8px 0; color: #0f172a; font-size: 20px; font-weight: 700;">Painel de Métricas Restrito</h3>
                <p style="color: #64748b; font-size: 13.5px; line-height: 1.5; margin: 0 0 24px 0;">
                    <?php if (is_user_logged_in()): ?>
                        Sua conta atual não possui permissão para visualizar este painel de métricas. Solicite acesso ao administrador.
                    <?php else: ?>
                        Este painel é confidencial. Faça login com uma conta autorizada (Administrador ou Visualizador) para acessar os dados.
                    <?php endif; ?>
                </p>

                <?php if (!is_user_logged_in()): ?>
                    <div style="text-align: left; background: #f8fafc; padding: 20px; border-radius: 8px; border: 1px solid #e2e8f0; margin-bottom: 15px;">
                        <?php
                        wp_login_form([
                            'redirect'       => esc_url(get_permalink()),
                            'form_id'        => 'li-frontend-loginform',
                            'label_username' => 'Usuário ou E-mail',
                            'label_password' => 'Senha',
                            'label_remember' => 'Lembrar-me',
                            'label_log_in'   => 'Acessar Painel',
                            'remember'       => true,
                            'value_remember' => true,
                        ]);
                        ?>
                    </div>
                    <div style="font-size: 12px; color: #94a3b8;">
                        <a href="<?php echo esc_url(wp_lostpassword_url(get_permalink())); ?>" style="color: #64748b; text-decoration: none;">Esqueceu sua senha?</a>
                    </div>
                <?php else: ?>
                    <div style="margin-top: 15px;">
                        <a href="<?php echo esc_url(wp_logout_url(get_permalink())); ?>" class="button button-secondary" style="font-size: 12px;">
                            Sair da conta atual
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
