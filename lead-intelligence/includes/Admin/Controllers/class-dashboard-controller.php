<?php
namespace LeadIntelligence\Admin\Controllers;

use LeadIntelligence\Database\DbSchema;
use LeadIntelligence\Database\LeadRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Controller do Dashboard de Inteligência e Qualidade de Leads
 */
class DashboardController {

    public static function render(array $options = []) {
        global $wpdb;
        $table = DbSchema::get_leads_table();

        $is_frontend          = !empty($options['is_frontend']) || !is_admin();
        $theme                = !empty($options['theme']) ? sanitize_key($options['theme']) : 'dark';
        if (!in_array($theme, ['dark', 'light'], true)) {
            $theme = 'dark';
        }
        $default_period       = !empty($options['periodo']) ? sanitize_text_field($options['periodo']) : '30d';
        $default_channel      = !empty($options['canal']) ? sanitize_key($options['canal']) : '';
        $show_header          = array_key_exists('show_header', $options) ? (bool) $options['show_header'] : true;
        $show_import          = array_key_exists('show_import', $options) ? (bool) $options['show_import'] : (!$is_frontend && current_user_can('manage_options'));
        $show_filters         = array_key_exists('show_filters', $options) ? (bool) $options['show_filters'] : true;
        $show_channel_compare = array_key_exists('show_channel_compare', $options) ? (bool) $options['show_channel_compare'] : true;
        $show_kpis            = array_key_exists('show_kpis', $options) ? (bool) $options['show_kpis'] : true;
        $show_calculator      = array_key_exists('show_calculator', $options) ? (bool) $options['show_calculator'] : true;
        $show_chart           = array_key_exists('show_chart', $options) ? (bool) $options['show_chart'] : true;
        $show_campaigns       = array_key_exists('show_campaigns', $options) ? (bool) $options['show_campaigns'] : true;
        $show_creatives       = array_key_exists('show_creatives', $options) ? (bool) $options['show_creatives'] : true;
        $custom_title         = !empty($options['title']) ? sanitize_text_field($options['title']) : 'Lead Intelligence • Dashboard de Qualidade';
        $custom_subtitle      = !empty($options['subtitle']) ? sanitize_text_field($options['subtitle']) : 'Análise avançada da qualidade dos leads gerados pelas campanhas da Meta Ads.';
        $is_full_width        = !empty($options['full_width']);

        $plugin_settings = \LeadIntelligence\Admin\Settings::get_settings();
        $logo_dark       = !empty($options['logo_dark']) ? esc_url_raw($options['logo_dark']) : ($plugin_settings['logo_dark'] ?? '');
        $logo_light      = !empty($options['logo_light']) ? esc_url_raw($options['logo_light']) : ($plugin_settings['logo_light'] ?? '');

        if (empty($logo_light) && !empty($logo_dark)) {
            $logo_light = $logo_dark;
        }
        if (empty($logo_dark) && !empty($logo_light)) {
            $logo_dark = $logo_light;
        }

        // 1. Filtros
        $custom_from     = isset($_GET['from']) ? sanitize_text_field(wp_unslash($_GET['from'])) : '';
        $custom_to       = isset($_GET['to']) ? sanitize_text_field(wp_unslash($_GET['to'])) : '';

        // Se datas personalizadas foram enviadas via GET, define o período como 'custom' automaticamente
        if (!empty($custom_from) || !empty($custom_to)) {
            $periodo = 'custom';
        } else {
            $periodo = isset($_GET['periodo']) ? sanitize_text_field(wp_unslash($_GET['periodo'])) : $default_period;
        }

        $filter_channel  = isset($_GET['canal']) ? sanitize_key(wp_unslash($_GET['canal'])) : $default_channel;
        $filter_campaign = isset($_GET['campanha']) ? sanitize_text_field(wp_unslash($_GET['campanha'])) : '';
        $filter_curso    = isset($_GET['curso']) ? sanitize_text_field(wp_unslash($_GET['curso'])) : '';
        $filter_area     = isset($_GET['area']) ? sanitize_text_field(wp_unslash($_GET['area'])) : '';

        // URLs de formulário e reset
        if ($is_frontend) {
            $form_action = remove_query_arg(['periodo', 'canal', 'campanha', 'curso', 'area', 'from', 'to']);
            $reset_url   = $form_action;
        } else {
            $form_action = '';
            $reset_url   = admin_url('admin.php?page=lead-intelligence');
        }

        // Cálculo de datas
        $now = current_time('mysql');
        $date_start = '';
        $date_end = $now;

        switch ($periodo) {
            case 'today':
                $date_start = wp_date('Y-m-d 00:00:00');
                $date_end   = wp_date('Y-m-d 23:59:59');
                break;
            case 'yesterday':
                $date_start = wp_date('Y-m-d 00:00:00', strtotime('-1 day'));
                $date_end   = wp_date('Y-m-d 23:59:59', strtotime('-1 day'));
                break;
            case '7d':
                $date_start = wp_date('Y-m-d 00:00:00', strtotime('-7 days'));
                break;
            case '30d':
                $date_start = wp_date('Y-m-d 00:00:00', strtotime('-30 days'));
                break;
            case 'month':
                $date_start = wp_date('Y-m-01 00:00:00');
                break;
            case 'last_month':
                $date_start = wp_date('Y-m-01 00:00:00', strtotime('first day of last month'));
                $date_end   = wp_date('Y-m-t 23:59:59', strtotime('last day of last month'));
                break;
            case 'custom':
                if (!empty($custom_from) && !empty($custom_to)) {
                    $date_start = $custom_from . ' 00:00:00';
                    $date_end   = $custom_to . ' 23:59:59';
                } elseif (!empty($custom_from)) {
                    // Preencheu apenas uma data aleatória: filtra exatamente este dia completo
                    $date_start = $custom_from . ' 00:00:00';
                    $date_end   = $custom_from . ' 23:59:59';
                } elseif (!empty($custom_to)) {
                    $date_start = '';
                    $date_end   = $custom_to . ' 23:59:59';
                }
                break;
            case 'all':
            default:
                $date_start = '';
                break;
        }

        // Datas pré-calculadas para exibir sempre no input
        $input_from = !empty($custom_from) ? $custom_from : (!empty($date_start) ? substr($date_start, 0, 10) : '');
        $input_to   = !empty($custom_to) ? $custom_to : (!empty($date_end) ? substr($date_end, 0, 10) : '');

        // Construção da cláusula WHERE
        $where = ['1=1'];
        $params = [];

        if (!empty($date_start)) {
            $where[] = "data_cadastro >= %s";
            $params[] = $date_start;
        }
        if (!empty($date_end)) {
            $where[] = "data_cadastro <= %s";
            $params[] = $date_end;
        }
        if (!empty($filter_channel)) {
            if ($filter_channel === 'google_ads') {
                $where[] = "(gclid != '' OR utm_source = 'google' OR formulario_nome LIKE '%Google%' OR pagina_origem LIKE '%Google%')";
            } elseif ($filter_channel === 'meta_ads') {
                $where[] = "(fbclid != '' OR ad_id != '' OR utm_source IN ('meta', 'facebook', 'instagram', 'fb') OR formulario_nome LIKE '%Meta%' OR formulario_nome LIKE '%Facebook%' OR pagina_origem LIKE '%Meta%')";
            } elseif ($filter_channel === 'whatsapp') {
                $where[] = "(formulario_nome LIKE '%WhatsApp%' OR whatsapp_status != '' OR conversation_id != '')";
            }
        }
        if (!empty($filter_campaign)) {
            $where[] = "utm_campaign = %s";
            $params[] = $filter_campaign;
        }
        if (!empty($filter_curso)) {
            $where[] = "tipo_curso = %s";
            $params[] = $filter_curso;
        }
        if (!empty($filter_area)) {
            $where[] = "area_interesse = %s";
            $params[] = $filter_area;
        }

        $where_sql = implode(' AND ', $where);

        // Resumo Comparativo por Canal (Google Ads vs Meta Ads)
        $channel_summary = LeadRepository::get_channel_comparison_summary($date_start, $date_end);

        // 2. Métricas dos Cards
        $metrics_query = "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN qualificacao_status = 'qualificado' THEN 1 ELSE 0 END) as qualificados,
            SUM(CASE WHEN qualificacao_status = 'nao_qualificado' THEN 1 ELSE 0 END) as nao_qualificados,
            SUM(CASE WHEN qualificacao_status = 'pendente' THEN 1 ELSE 0 END) as pendentes
            FROM {$table} WHERE {$where_sql}";

        if (!empty($params)) {
            $metrics_query = $wpdb->prepare($metrics_query, $params);
        }
        $metrics = $wpdb->get_row($metrics_query);

        $total_leads        = $metrics ? (int) $metrics->total : 0;
        $total_qualificados = $metrics ? (int) $metrics->qualificados : 0;
        $total_desqualif    = $metrics ? (int) $metrics->nao_qualificados : 0;
        $total_pendentes    = $metrics ? (int) $metrics->pendentes : 0;

        $taxa_qualificacao = $total_leads > 0 ? round(($total_qualificados / $total_leads) * 100, 1) : 0;

        // 3. Desempenho por Campanha
        $camp_query = "SELECT 
            COALESCE(NULLIF(utm_campaign, ''), 'Direto / Sem Campanha') as campanha,
            COUNT(*) as leads,
            SUM(CASE WHEN qualificacao_status = 'qualificado' THEN 1 ELSE 0 END) as qualificados,
            SUM(CASE WHEN qualificacao_status = 'nao_qualificado' THEN 1 ELSE 0 END) as desqualificados,
            SUM(CASE WHEN qualificacao_status = 'pendente' THEN 1 ELSE 0 END) as pendentes
            FROM {$table} 
            WHERE {$where_sql} 
            GROUP BY campanha 
            ORDER BY qualificados DESC, leads DESC 
            LIMIT 15";

        if (!empty($params)) {
            $camp_query = $wpdb->prepare($camp_query, $params);
        }
        $campaigns = $wpdb->get_results($camp_query);

        // 4. Desempenho por Anúncio / Criativo (ad_name ou utm_content)
        $ad_query = "SELECT 
            COALESCE(NULLIF(ad_name, ''), NULLIF(utm_content, ''), 'Sem Anúncio Identificado') as anuncio,
            COALESCE(NULLIF(utm_campaign, ''), '-') as campanha,
            COUNT(*) as leads,
            SUM(CASE WHEN qualificacao_status = 'qualificado' THEN 1 ELSE 0 END) as qualificados
            FROM {$table} 
            WHERE {$where_sql} 
            GROUP BY anuncio, campanha 
            ORDER BY qualificados DESC, leads DESC 
            LIMIT 10";

        if (!empty($params)) {
            $ad_query = $wpdb->prepare($ad_query, $params);
        }
        $ads = $wpdb->get_results($ad_query);

        // 5. Evolução Diária (Últimos dias)
        $daily_query = "SELECT 
            DATE(data_cadastro) as dia,
            COUNT(*) as total,
            SUM(CASE WHEN qualificacao_status = 'qualificado' THEN 1 ELSE 0 END) as qualificados
            FROM {$table} 
            WHERE {$where_sql} 
            GROUP BY dia 
            ORDER BY dia ASC 
            LIMIT 31";

        if (!empty($params)) {
            $daily_query = $wpdb->prepare($daily_query, $params);
        }
        $daily_evolution = $wpdb->get_results($daily_query);

        // Listas para os filtros dropdown
        $all_campaigns = $wpdb->get_col("SELECT DISTINCT utm_campaign FROM {$table} WHERE utm_campaign != '' ORDER BY utm_campaign ASC");
        $all_cursos    = $wpdb->get_col("SELECT DISTINCT tipo_curso FROM {$table} WHERE tipo_curso != '' ORDER BY tipo_curso ASC");
        $all_areas     = $wpdb->get_col("SELECT DISTINCT area_interesse FROM {$table} WHERE area_interesse != '' ORDER BY area_interesse ASC");

        $max_daily = 1;
        foreach ($daily_evolution as $d) {
            if ($d->total > $max_daily) {
                $max_daily = $d->total;
            }
        }
        ?>
        <div class="wrap li-wrap li-wrap-dashboard <?php echo $is_frontend ? 'li-frontend-wrap' : ''; ?> <?php echo $is_full_width ? 'li-full-width' : ''; ?>" data-theme="<?php echo esc_attr($theme); ?>">
            <div class="li-app-layout">
                <!-- ========================================================
                     1. MENU LATERAL À ESQUERDA (SIDEBAR HUD)
                     ======================================================== -->
                <aside class="li-sidebar" id="liSidebar">
                    <div class="li-sidebar-header">
                        <div class="li-sidebar-header-top">
                            <?php if (!empty($logo_dark) || !empty($logo_light)): ?>
                                <div class="li-brand-logos">
                                    <?php if (!empty($logo_dark)): ?>
                                        <img src="<?php echo esc_url($logo_dark); ?>" alt="Logo Faveni" class="li-logo-img li-logo-dark" />
                                    <?php endif; ?>
                                    <?php if (!empty($logo_light)): ?>
                                        <img src="<?php echo esc_url($logo_light); ?>" alt="Logo Faveni" class="li-logo-img li-logo-light" />
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="li-sidebar-brand-text">FAVENI</div>
                            <?php endif; ?>

                            <!-- BOTÃO DE RECOLHER MENU LATERAL -->
                            <button type="button" class="li-sidebar-toggle-btn" onclick="liToggleSidebarCollapse()" title="Recolher menu lateral">
                                <span>⇤</span>
                            </button>
                        </div>

                        <!-- LINHA DE STATUS LIVE (MESMA LARGURA) -->
                        <div class="li-brand-pill">
                            <span class="li-brand-dot"></span>
                            <span class="li-brand-title">FAVENI &bull; HUD</span>
                            <span class="li-brand-live">LIVE</span>
                        </div>
                    </div>

                    <div class="li-sidebar-profile">
                        <div class="li-profile-avatar" title="Grupo Educacional Faveni">
                            <span class="li-avatar-emblem">🏛️</span>
                        </div>
                        <div class="li-profile-info">
                            <span class="li-profile-name">GRUPO FAVENI</span>
                            <span class="li-profile-role">Inteligência de Leads</span>
                        </div>
                    </div>

                    <nav class="li-sidebar-nav">
                        <div class="li-nav-group-title">MÓDULOS</div>
                        <a href="#li-sec-filters" class="li-nav-item is-active" data-target="li-sec-filters" title="Dashboard Geral (<?php echo (int) $total_leads; ?> leads)">
                            <span class="li-nav-icon">📊</span>
                            <span class="li-nav-text">Dashboard Geral</span>
                            <span class="li-nav-badge"><?php echo (int) $total_leads; ?></span>
                        </a>
                        <a href="#li-sec-compare" class="li-nav-item" data-target="li-sec-compare" title="Comparativo: Google Ads vs Meta Ads">
                            <span class="li-nav-icon">⚖️</span>
                            <span class="li-nav-text">Google vs Meta</span>
                        </a>
                        <a href="#li-sec-kpis" class="li-nav-item" data-target="li-sec-kpis" title="Métricas &amp; KPIs">
                            <span class="li-nav-icon">💎</span>
                            <span class="li-nav-text">Métricas &amp; KPIs</span>
                        </a>
                        <a href="#li-sec-calc" class="li-nav-item" data-target="li-sec-calc" title="Calculadora HUD de CPL">
                            <span class="li-nav-icon">⚡</span>
                            <span class="li-nav-text">Calculadora CPL</span>
                        </a>
                        <?php if ($show_chart && !empty($daily_evolution)): ?>
                            <a href="#li-sec-chart" class="li-nav-item" data-target="li-sec-chart" title="Evolução Diária">
                                <span class="li-nav-icon">📈</span>
                                <span class="li-nav-text">Evolução Diária</span>
                            </a>
                        <?php endif; ?>
                        <?php if ($show_campaigns): ?>
                            <a href="#li-sec-campaigns" class="li-nav-item" data-target="li-sec-campaigns" title="Campanhas Meta Ads">
                                <span class="li-nav-icon">🎯</span>
                                <span class="li-nav-text">Campanhas Meta</span>
                            </a>
                        <?php endif; ?>
                        <?php if ($show_creatives): ?>
                            <a href="#li-sec-creatives" class="li-nav-item" data-target="li-sec-creatives" title="Anúncios &amp; Criativos">
                                <span class="li-nav-icon">📢</span>
                                <span class="li-nav-text">Anúncios &amp; Criativos</span>
                            </a>
                        <?php endif; ?>

                        <?php if (current_user_can('manage_options')): ?>
                            <div class="li-nav-group-title" style="margin-top: 18px;">ADMINISTRAÇÃO</div>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-leads')); ?>" class="li-nav-item" target="_blank" title="Lista de Leads">
                                <span class="li-nav-icon">👥</span>
                                <span class="li-nav-text">Lista de Leads</span>
                                <span class="li-nav-ext">↗</span>
                            </a>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-import')); ?>" class="li-nav-item" target="_blank" title="Importar Planilha">
                                <span class="li-nav-icon">📥</span>
                                <span class="li-nav-text">Importar Planilha</span>
                                <span class="li-nav-ext">↗</span>
                            </a>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-settings')); ?>" class="li-nav-item" target="_blank" title="Configurações">
                                <span class="li-nav-icon">⚙️</span>
                                <span class="li-nav-text">Configurações</span>
                                <span class="li-nav-ext">↗</span>
                            </a>
                        <?php endif; ?>
                    </nav>

                    <div class="li-sidebar-footer">
                        <div class="li-sidebar-active-status">
                            <span class="li-status-pulse"></span>
                            <span class="li-status-text">Monitoramento Ativo</span>
                        </div>
                        <span class="li-sidebar-ver">Lead Intelligence v1.6.4</span>
                    </div>
                </aside>

                <!-- ========================================================
                     2. CONTEÚDO PRINCIPAL (COM MENU SUPERIOR TOPBAR)
                     ======================================================== -->
                <div class="li-main-wrapper">
                    <?php if ($show_header): ?>
                        <!-- MENU SUPERIOR (TOPBAR HUD) -->
                        <header class="li-topbar">
                            <div class="li-topbar-left">
                                <div class="li-topbar-heading">
                                    <h2 class="li-dashboard-title"><?php echo esc_html($custom_title); ?></h2>
                                    <p class="li-subtitle"><?php echo esc_html($custom_subtitle); ?></p>
                                </div>
                            </div>

                            <!-- CENTRO DO TOPBAR: INFORMAÇÕES CENTRALIZADAS -->
                            <div class="li-topbar-center">
                                <div class="li-topbar-pill" title="Total de Leads captados">
                                    <span class="li-pill-lbl">Total Leads</span>
                                    <span class="li-pill-val"><?php echo number_format_i18n($total_leads); ?></span>
                                </div>
                                <div class="li-topbar-pill li-pill-success" title="Total de Matrículas confirmadas">
                                    <span class="li-pill-lbl">Confirmados</span>
                                    <span class="li-pill-val"><?php echo number_format_i18n($total_qualificados); ?></span>
                                </div>
                                <div class="li-topbar-pill li-pill-info" title="Taxa de conversão do período">
                                    <span class="li-pill-lbl">Taxa Geral</span>
                                    <span class="li-pill-val"><?php echo $taxa_qualificacao; ?>%</span>
                                </div>
                            </div>

                            <!-- DIREITA DO TOPBAR: CONTROLES E AÇÕES -->
                            <div class="li-topbar-right">
                                <button type="button" class="li-theme-toggle-btn" onclick="liToggleTheme()" title="Alternar Modo Claro / Escuro">
                                    <span class="li-theme-toggle-icon"><?php echo ($theme === 'dark') ? '🌙' : '☀️'; ?></span>
                                    <span class="li-theme-toggle-text"><?php echo ($theme === 'dark') ? 'Modo Escuro' : 'Modo Claro'; ?></span>
                                </button>

                                <?php if (!$is_frontend): ?>
                                    <button type="button" class="button li-btn li-btn-ghost" onclick="navigator.clipboard.writeText('[lead_intelligence_dashboard]').then(function(){alert('Shortcode copiado:\n[lead_intelligence_dashboard]');});" title="Copiar shortcode">
                                        📋 Shortcode
                                    </button>
                                <?php endif; ?>

                                <?php if ($show_import): ?>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-import')); ?>" class="button button-primary li-btn li-btn-faveni">
                                        + Importar
                                    </a>
                                <?php endif; ?>
                            </div>
                        </header>
                    <?php endif; ?>

                    <div class="li-content-scroll">

                        <!-- ========================================================
                             HERO HIGHLIGHTS (DESIGN EXECUTIVO FAVENI)
                             ======================================================== -->
                        <div class="li-hero-highlights">
                            <!-- CARD 1: DOURADO FAVENI (MATRÍCULAS / CONVERSÕES) -->
                            <div class="li-hero-card li-hero-card-gold">
                                <div class="li-hero-card-top">
                                    <span class="li-hero-tag">FAVENI • QUALIFICAÇÃO</span>
                                    <span class="li-hero-pill-badge">ALTA PERFORMANCE</span>
                                </div>
                                <div class="li-hero-card-body">
                                    <div class="li-hero-metric-wrap">
                                        <div class="li-hero-metric"><?php echo number_format_i18n($total_qualificados); ?></div>
                                        <div class="li-hero-metric-lbl">Matrículas Confirmadas</div>
                                    </div>
                                    <div class="li-hero-icon-box">
                                        <span class="li-hero-icon">🎓</span>
                                    </div>
                                </div>
                                <div class="li-hero-card-footer">
                                    <span class="li-hero-stat-highlight"><?php echo $taxa_qualificacao; ?>%</span>
                                    <span class="li-hero-stat-sub">de conversão qualificada</span>
                                </div>
                            </div>

                            <!-- CARD 2: VERDE FLORESTA PROFUNDO (TOTAL DE LEADS) -->
                            <div class="li-hero-card li-hero-card-forest">
                                <div class="li-hero-card-top">
                                    <span class="li-hero-tag">VOLUME DE CAPTAÇÃO</span>
                                    <span class="li-hero-pill-badge">MULTI-CANAL</span>
                                </div>
                                <div class="li-hero-card-body">
                                    <div class="li-hero-metric-wrap">
                                        <div class="li-hero-metric"><?php echo number_format_i18n($total_leads); ?></div>
                                        <div class="li-hero-metric-lbl">Total Geral de Leads</div>
                                    </div>
                                    <div class="li-hero-icon-box">
                                        <span class="li-hero-icon">📊</span>
                                    </div>
                                </div>
                                <div class="li-hero-card-footer">
                                    <span class="li-hero-stat-highlight">Elementor • Meta • Google</span>
                                    <span class="li-hero-stat-sub">Base ativa centralizada</span>
                                </div>
                            </div>

                            <!-- CARD 3: VERDE FLORESTA COM STATUS & EFICIÊNCIA -->
                            <div class="li-hero-card li-hero-card-forest">
                                <div class="li-hero-card-top">
                                    <span class="li-hero-tag">STATUS DA OPERAÇÃO</span>
                                    <span class="li-hero-pill-badge li-badge-live">● AO VIVO</span>
                                </div>
                                <div class="li-hero-card-body">
                                    <div class="li-hero-metric-wrap">
                                        <div class="li-hero-metric"><?php echo number_format_i18n($total_pendentes); ?></div>
                                        <div class="li-hero-metric-lbl">Em Qualificação / Fila</div>
                                    </div>
                                    <div class="li-hero-icon-box">
                                        <span class="li-hero-icon">⚡</span>
                                    </div>
                                </div>
                                <div class="li-hero-card-footer">
                                    <span class="li-hero-stat-highlight"><?php echo number_format_i18n($total_desqualif); ?> não qualificados</span>
                                    <span class="li-hero-stat-sub">triagem contínua de alunos</span>
                                </div>
                            </div>
                        </div>

            <?php if ($show_filters): ?>
                <!-- BARRA DE FILTROS DO DASHBOARD (HUD TOOLBAR) -->
                <div id="li-sec-filters" class="li-card li-filter-bar">
                    <form method="get" action="<?php echo esc_url($form_action); ?>" class="li-filter-form">
                        <?php if (!$is_frontend): ?>
                            <input type="hidden" name="page" value="lead-intelligence">
                        <?php endif; ?>

                        <div class="li-filter-row">
                            <!-- PERÍODO -->
                            <div class="li-filter-col">
                                <label class="li-filter-lbl" for="liPeriodoSelect">Período</label>
                                <select name="periodo" id="liPeriodoSelect" class="li-select" onchange="liOnPeriodoChange(this.value)">
                                    <option value="today" <?php selected($periodo, 'today'); ?>>Hoje</option>
                                    <option value="yesterday" <?php selected($periodo, 'yesterday'); ?>>Ontem</option>
                                    <option value="7d" <?php selected($periodo, '7d'); ?>>Últimos 7 dias</option>
                                    <option value="30d" <?php selected($periodo, '30d'); ?>>Últimos 30 dias</option>
                                    <option value="month" <?php selected($periodo, 'month'); ?>>Este Mês</option>
                                    <option value="last_month" <?php selected($periodo, 'last_month'); ?>>Mês Passado</option>
                                    <option value="all" <?php selected($periodo, 'all'); ?>>Todo o Período</option>
                                    <option value="custom" <?php selected($periodo, 'custom'); ?>>📅 Personalizado...</option>
                                </select>
                            </div>

                            <!-- INTERVALO DE DATAS (SEMPRE VISÍVEL) -->
                            <div id="liCustomDateBox" class="li-filter-col li-filter-dates-col">
                                <label class="li-filter-lbl">Intervalo de Datas</label>
                                <div class="li-dates-capsule">
                                    <span class="li-date-tag">De</span>
                                    <input type="date" name="from" id="liDateInputFrom" value="<?php echo esc_attr($input_from); ?>" class="li-date-input" title="Data inicial" onchange="liOnDateInputChange()">
                                    <span class="li-date-sep">➔</span>
                                    <span class="li-date-tag">Até</span>
                                    <input type="date" name="to" id="liDateInputTo" value="<?php echo esc_attr($input_to); ?>" class="li-date-input" title="Data final" onchange="liOnDateInputChange()">
                                </div>
                            </div>

                            <!-- CANAL -->
                            <div class="li-filter-col">
                                <label class="li-filter-lbl">Canal de Origem</label>
                                <select name="canal" class="li-select">
                                    <option value="">Todos os Canais</option>
                                    <option value="google_ads" <?php selected($filter_channel, 'google_ads'); ?>>🟢 Google Ads</option>
                                    <option value="meta_ads" <?php selected($filter_channel, 'meta_ads'); ?>>🔵 Meta Ads</option>
                                    <option value="whatsapp" <?php selected($filter_channel, 'whatsapp'); ?>>💬 WhatsApp Direto</option>
                                </select>
                            </div>

                            <!-- CAMPANHA -->
                            <?php if (!empty($all_campaigns)): ?>
                                <div class="li-filter-col">
                                    <label class="li-filter-lbl">Campanha</label>
                                    <select name="campanha" class="li-select">
                                        <option value="">Todas as Campanhas</option>
                                        <?php foreach ($all_campaigns as $camp): ?>
                                            <option value="<?php echo esc_attr($camp); ?>" <?php selected($filter_campaign, $camp); ?>>
                                                <?php echo esc_html($camp); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif; ?>

                            <!-- CURSO -->
                            <?php if (!empty($all_cursos)): ?>
                                <div class="li-filter-col">
                                    <label class="li-filter-lbl">Curso</label>
                                    <select name="curso" class="li-select">
                                        <option value="">Todos os Cursos</option>
                                        <?php foreach ($all_cursos as $c): ?>
                                            <option value="<?php echo esc_attr($c); ?>" <?php selected($filter_curso, $c); ?>>
                                                <?php echo esc_html($c); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif; ?>

                            <!-- AÇÕES -->
                            <div class="li-filter-col li-filter-actions-col">
                                <label class="li-filter-lbl">&nbsp;</label>
                                <div class="li-filter-btns">
                                    <button type="submit" class="button button-primary li-btn li-btn-faveni">
                                        <span>🔍</span> Filtrar
                                    </button>
                                    <a href="<?php echo esc_url($reset_url); ?>" class="button li-btn li-btn-ghost" title="Limpar todos os filtros">
                                        ↺ Resetar
                                    </a>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

            <?php if ($show_channel_compare): ?>
                <!-- COMPARATIVO EXECUTIVO: GOOGLE ADS VS META ADS -->
                <?php
                $g_data = $channel_summary['google_ads'];
                $m_data = $channel_summary['meta_ads'];
                $melhor_taxa = '';
                if ($g_data->taxa > $m_data->taxa && $g_data->total_leads > 0) {
                    $melhor_taxa = 'google';
                } elseif ($m_data->taxa > $g_data->taxa && $m_data->total_leads > 0) {
                    $melhor_taxa = 'meta';
                }
                ?>
                <div id="li-sec-compare" class="li-card li-card-channel-compare">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 18px;">
                        <div>
                            <h3 class="li-card-title" style="margin: 0; font-size: 16px; display: flex; align-items: center; gap: 8px;">
                                <span>⚖️</span> Comparativo de Performance: Google Ads vs Meta Ads
                            </h3>
                            <p class="li-card-desc" style="margin: 4px 0 0 0;">Análise comparativa limpa e em tempo real do retorno em matrículas de cada canal.</p>
                        </div>
                        <?php if (!empty($filter_channel)): ?>
                            <span class="li-badge li-badge-active-filter">Filtro ativo: <?php echo esc_html($filter_channel === 'google_ads' ? 'Google Ads' : ($filter_channel === 'meta_ads' ? 'Meta Ads' : 'WhatsApp')); ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="li-channel-compare-grid">
                        <!-- COLUNA GOOGLE ADS -->
                        <div class="li-channel-box li-channel-google">
                            <div class="li-channel-box-header">
                                <span class="li-channel-tag-google">🟢 Google Ads</span>
                                <?php if ($melhor_taxa === 'google'): ?>
                                    <span class="li-badge-winner" title="Maior percentual de conversão de alunos">🏆 Maior Taxa de Qualificação</span>
                                <?php endif; ?>
                            </div>
                            <div class="li-channel-stats-row">
                                <div class="li-channel-stat">
                                    <span class="li-channel-stat-num"><?php echo number_format_i18n($g_data->total_leads); ?></span>
                                    <span class="li-channel-stat-lbl">Leads Gerados</span>
                                </div>
                                <div class="li-channel-stat">
                                    <span class="li-channel-stat-num li-color-green"><?php echo number_format_i18n($g_data->qualificados); ?></span>
                                    <span class="li-channel-stat-lbl">Matrículas Confirmadas</span>
                                </div>
                                <div class="li-channel-stat">
                                    <span class="li-channel-stat-num li-color-blue"><?php echo $g_data->taxa; ?>%</span>
                                    <span class="li-channel-stat-lbl">Taxa de Conversão</span>
                                </div>
                            </div>
                            <div class="li-channel-progress-bg">
                                <div class="li-channel-progress-bar-google" style="width: <?php echo min(100, $g_data->taxa); ?>%;"></div>
                            </div>
                        </div>

                        <!-- DIVISOR VS -->
                        <div class="li-channel-vs">
                            <span>VS</span>
                        </div>

                        <!-- COLUNA META ADS -->
                        <div class="li-channel-box li-channel-meta">
                            <div class="li-channel-box-header">
                                <span class="li-channel-tag-meta">🔵 Meta Ads</span>
                                <?php if ($melhor_taxa === 'meta'): ?>
                                    <span class="li-badge-winner" title="Maior percentual de conversão de alunos">🏆 Maior Taxa de Qualificação</span>
                                <?php endif; ?>
                            </div>
                            <div class="li-channel-stats-row">
                                <div class="li-channel-stat">
                                    <span class="li-channel-stat-num"><?php echo number_format_i18n($m_data->total_leads); ?></span>
                                    <span class="li-channel-stat-lbl">Leads Gerados</span>
                                </div>
                                <div class="li-channel-stat">
                                    <span class="li-channel-stat-num li-color-green"><?php echo number_format_i18n($m_data->qualificados); ?></span>
                                    <span class="li-channel-stat-lbl">Matrículas Confirmadas</span>
                                </div>
                                <div class="li-channel-stat">
                                    <span class="li-channel-stat-num li-color-meta"><?php echo $m_data->taxa; ?>%</span>
                                    <span class="li-channel-stat-lbl">Taxa de Conversão</span>
                                </div>
                            </div>
                            <div class="li-channel-progress-bg">
                                <div class="li-channel-progress-bar-meta" style="width: <?php echo min(100, $m_data->taxa); ?>%;"></div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($show_kpis): ?>
                <!-- CARDS DE KPIs PRINCIPAIS -->
                <div id="li-sec-kpis" class="li-metric-grid">
                    <div class="li-metric-card li-metric-total">
                        <span class="li-metric-label">Total de Leads</span>
                        <span class="li-metric-value"><?php echo number_format_i18n($total_leads); ?></span>
                        <span class="li-metric-sub">Elementor &amp; Campanhas</span>
                    </div>
                    <div class="li-metric-card li-card-success li-metric-qual">
                        <span class="li-metric-label">Leads Qualificados</span>
                        <span class="li-metric-value"><?php echo number_format_i18n($total_qualificados); ?></span>
                        <span class="li-metric-sub">Matrículas confirmadas</span>
                    </div>
                    <div class="li-metric-card li-card-warning li-metric-pend">
                        <span class="li-metric-label">Pendentes</span>
                        <span class="li-metric-value"><?php echo number_format_i18n($total_pendentes); ?></span>
                        <span class="li-metric-sub">Aguardando planilha</span>
                    </div>
                    <div class="li-metric-card li-card-danger li-metric-desq">
                        <span class="li-metric-label">Não Qualificados</span>
                        <span class="li-metric-value"><?php echo number_format_i18n($total_desqualif); ?></span>
                        <span class="li-metric-sub">Desqualificados/Recusados</span>
                    </div>
                    <div class="li-metric-card li-card-info li-metric-rate">
                        <span class="li-metric-label">Taxa de Qualificação</span>
                        <span class="li-metric-value"><?php echo $taxa_qualificacao; ?>%</span>
                        <span class="li-metric-sub">Média do período</span>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($show_calculator): ?>
                <!-- CALCULADORA DE CPL E INVESTIMENTO -->
                <div id="li-sec-calc" class="li-card li-card-calculator">
                    <div class="li-calc-header">
                        <div>
                            <h3 class="li-calc-title">⚡ Calculadora HUD de CPL e Custo por Aluno</h3>
                            <p class="li-calc-desc">Simulação em tempo real da eficiência do investimento em tráfego da Faveni:</p>
                        </div>
                        <div class="li-calc-input-wrap">
                            <span class="li-calc-input-lbl">Investimento:</span>
                            <div class="li-calc-field">
                                <span class="li-calc-cur">R$</span>
                                <input type="number" id="liInvestimentoInput" value="5000" min="0" step="100">
                            </div>
                            <button type="button" class="button button-primary li-btn li-btn-faveni" onclick="recalcularCPL()">Calcular</button>
                        </div>
                    </div>

                    <div class="li-calc-grid">
                        <div class="li-calc-box">
                            <span class="li-calc-kpi-lbl">Custo por Lead Geral (CPL)</span>
                            <div id="liCPLGeral" class="li-calc-kpi-val li-val-cyan">R$ 0,00</div>
                        </div>
                        <div class="li-calc-box">
                            <span class="li-calc-kpi-lbl">Custo por Aluno Qualificado (CPQ)</span>
                            <div id="liCPLQualificado" class="li-calc-kpi-val li-val-green">R$ 0,00</div>
                        </div>
                        <div class="li-calc-box">
                            <span class="li-calc-kpi-lbl">Eficiência da Conversão</span>
                            <div id="liEficiencia" class="li-calc-kpi-val li-val-amber"><?php echo $taxa_qualificacao; ?>%</div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($show_chart && !empty($daily_evolution)): ?>
                <!-- GRÁFICO DE EVOLUÇÃO DIÁRIA -->
                <div id="li-sec-chart" class="li-card">
                    <h3 class="li-card-title">📈 Evolução Diária de Captação vs. Qualificação</h3>
                    <p class="li-card-desc">Volume diário de leads gerados e leads confirmados na qualificação.</p>

                    <div style="display: flex; align-items: flex-end; gap: 8px; height: 180px; padding-top: 20px; overflow-x: auto;">
                        <?php foreach ($daily_evolution as $day): ?>
                            <?php
                            $height_total = round(($day->total / $max_daily) * 140);
                            $height_qual  = $day->total > 0 ? round(($day->qualificados / $day->total) * $height_total) : 0;
                            $day_label    = date_i18n('d/m', strtotime($day->dia));
                            ?>
                            <div style="display: flex; flex-direction: column; align-items: center; flex: 1; min-width: 32px;" title="<?php echo esc_attr("{$day->dia}\nTotal: {$day->total}\nQualificados: {$day->qualificados}"); ?>">
                                <div style="font-size: 10px; color: #64748b; margin-bottom: 4px;"><?php echo $day->total; ?></div>
                                <div style="width: 22px; height: <?php echo max(4, $height_total); ?>px; background: #e2e8f0; border-radius: 4px; position: relative; overflow: hidden; display: flex; align-items: flex-end;">
                                    <div style="width: 100%; height: <?php echo $height_qual; ?>px; background: #10b981; border-radius: 0 0 4px 4px;"></div>
                                </div>
                                <div style="font-size: 10px; color: #94a3b8; margin-top: 6px;"><?php echo esc_html($day_label); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div style="display: flex; gap: 20px; justify-content: center; margin-top: 15px; font-size: 12px; color: #64748b;">
                        <span style="display: flex; align-items: center; gap: 6px;">
                            <span style="width: 12px; height: 12px; background: #e2e8f0; border-radius: 3px; display: inline-block;"></span>
                            Total de Leads
                        </span>
                        <span style="display: flex; align-items: center; gap: 6px;">
                            <span style="width: 12px; height: 12px; background: #10b981; border-radius: 3px; display: inline-block;"></span>
                            Leads Qualificados
                        </span>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($show_campaigns): ?>
                <!-- RELATÓRIO 1: DESEMPENHO POR CAMPANHA -->
                <div id="li-sec-campaigns" class="li-card" style="padding: 0; overflow-x: auto;">
                    <div style="padding: 20px 24px 12px; border-bottom: 1px solid #e2e8f0;">
                        <h3 class="li-card-title">🎯 Qualidade de Leads por Campanha (Meta Ads)</h3>
                        <p class="li-card-desc" style="margin-bottom: 0;">Descubra quais campanhas geram alunos reais com a maior taxa de qualificação e menor desperdício de verba.</p>
                    </div>

                    <table class="wp-list-table widefat fixed striped li-table">
                        <thead>
                            <tr>
                                <th>Campanha (UTM Campaign)</th>
                                <th style="width: 110px; text-align: center;">Total Leads</th>
                                <th style="width: 120px; text-align: center;">Qualificados</th>
                                <th style="width: 130px; text-align: center;">Não Qualificados</th>
                                <th style="width: 180px;">Taxa de Qualificação</th>
                                <th style="width: 120px; text-align: center;">Classificação</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($campaigns)): ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; padding: 35px; color: #64748b;">
                                        Nenhuma campanha identificada no período selecionado.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($campaigns as $camp): ?>
                                    <?php
                                    $camp_taxa = $camp->leads > 0 ? round(($camp->qualificados / $camp->leads) * 100, 1) : 0;
                                    $is_high_quality = ($camp_taxa >= 25 && $camp->leads >= 5);
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo esc_html($camp->campanha); ?></strong>
                                        </td>
                                        <td style="text-align: center; font-weight: 600;">
                                            <?php echo number_format_i18n($camp->leads); ?>
                                        </td>
                                        <td style="text-align: center;">
                                            <span style="font-weight: 700; color: #15803d;">
                                                <?php echo number_format_i18n($camp->qualificados); ?>
                                            </span>
                                        </td>
                                        <td style="text-align: center; color: #b91c1c;">
                                            <?php echo number_format_i18n($camp->desqualificados); ?>
                                        </td>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 8px;">
                                                <div style="flex: 1; height: 8px; background: #e2e8f0; border-radius: 4px; overflow: hidden;">
                                                    <div style="width: <?php echo min(100, $camp_taxa); ?>%; height: 100%; background: <?php echo $camp_taxa >= 20 ? '#10b981' : ($camp_taxa >= 10 ? '#f59e0b' : '#ef4444'); ?>; border-radius: 4px;"></div>
                                                </div>
                                                <span style="font-size: 13px; font-weight: 700; min-width: 45px; text-align: right;">
                                                    <?php echo $camp_taxa; ?>%
                                                </span>
                                            </div>
                                        </td>
                                        <td style="text-align: center;">
                                            <?php if ($is_high_quality): ?>
                                                <span class="li-badge li-status-qualificado" title="Campanha com alto retorno de qualificação">⭐ Alta Qualidade</span>
                                            <?php elseif ($camp_taxa < 10 && $camp->leads >= 10): ?>
                                                <span class="li-badge li-status-desqualificado" title="Alto custo por aluno real">⚠️ Baixa Qualidade</span>
                                            <?php else: ?>
                                                <span class="li-badge li-status-cinza">Normal</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if ($show_creatives): ?>
                <!-- RELATÓRIO 2: QUALIDADE POR ANÚNCIO / CRIATIVO -->
                <div id="li-sec-creatives" class="li-card" style="padding: 0; overflow-x: auto;">
                    <div style="padding: 20px 24px 12px; border-bottom: 1px solid #e2e8f0;">
                        <h3 class="li-card-title">📢 Qualidade por Anúncio / Criativo</h3>
                        <p class="li-card-desc" style="margin-bottom: 0;">Identifique quais criativos atraem o público mais qualificado para a equipe de vendas.</p>
                    </div>

                    <table class="wp-list-table widefat fixed striped li-table">
                        <thead>
                            <tr>
                                <th>Anúncio / Criativo (ad_name / utm_content)</th>
                                <th>Campanha</th>
                                <th style="width: 110px; text-align: center;">Leads</th>
                                <th style="width: 120px; text-align: center;">Qualificados</th>
                                <th style="width: 150px; text-align: center;">Taxa de Qualificação</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($ads)): ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; padding: 35px; color: #64748b;">
                                        Nenhum anúncio identificado no período.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($ads as $ad): ?>
                                    <?php
                                    $ad_taxa = $ad->leads > 0 ? round(($ad->qualificados / $ad->leads) * 100, 1) : 0;
                                    ?>
                                    <tr>
                                        <td><strong><?php echo esc_html($ad->anuncio); ?></strong></td>
                                        <td><span style="color: #64748b;"><?php echo esc_html($ad->campanha); ?></span></td>
                                        <td style="text-align: center; font-weight: 600;"><?php echo number_format_i18n($ad->leads); ?></td>
                                        <td style="text-align: center; font-weight: 700; color: #15803d;"><?php echo number_format_i18n($ad->qualificados); ?></td>
                                        <td style="text-align: center;">
                                            <span class="li-badge <?php echo $ad_taxa >= 20 ? 'li-status-qualificado' : 'li-status-pendente'; ?>">
                                                <?php echo $ad_taxa; ?>%
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

                    </div> <!-- /li-content-scroll -->
                </div> <!-- /li-main-wrapper -->
            </div> <!-- /li-app-layout -->

            <script>
            function liToggleSidebar() {
                var sidebar = document.getElementById('liSidebar');
                if (sidebar) {
                    sidebar.classList.toggle('is-open');
                }
            }

            // Fechar sidebar ao clicar fora em telas mobile
            document.addEventListener('click', function(e) {
                var sidebar = document.getElementById('liSidebar');
                var toggle = document.querySelector('.li-sidebar-mobile-toggle');
                if (!sidebar || !toggle) return;
                if (sidebar.classList.contains('is-open')) {
                    if (!sidebar.contains(e.target) && !toggle.contains(e.target)) {
                        sidebar.classList.remove('is-open');
                    }
                }
            });

            // Rolagem suave e alternância imediata de abas ativas (Design 1)
            var liIsManualScrolling = false;
            var liScrollTimeout = null;

            document.querySelectorAll('.li-nav-item[data-target]').forEach(function(item) {
                item.addEventListener('click', function(e) {
                    var targetId = this.getAttribute('data-target');
                    var targetEl = document.getElementById(targetId);
                    if (targetEl) {
                        e.preventDefault();

                        // Alterna imediatamente a classe is-active sem concorrência
                        document.querySelectorAll('.li-nav-item').forEach(function(n) { 
                            n.classList.remove('is-active'); 
                        });
                        this.classList.add('is-active');

                        liIsManualScrolling = true;
                        clearTimeout(liScrollTimeout);
                        liScrollTimeout = setTimeout(function() {
                            liIsManualScrolling = false;
                        }, 800);

                        targetEl.scrollIntoView({ behavior: 'smooth', block: 'start' });

                        var sidebar = document.getElementById('liSidebar');
                        if (sidebar && window.innerWidth <= 980) {
                            sidebar.classList.remove('is-open');
                        }
                    }
                });
            });

            // ScrollSpy: Sincroniza a aba ativa quando o usuário rolar a página manualmente
            if ('IntersectionObserver' in window) {
                var liObserverSections = [];
                document.querySelectorAll('.li-nav-item[data-target]').forEach(function(item) {
                    var targetId = item.getAttribute('data-target');
                    var targetEl = document.getElementById(targetId);
                    if (targetEl && liObserverSections.indexOf(targetEl) === -1) {
                        liObserverSections.push(targetEl);
                    }
                });

                if (liObserverSections.length > 0) {
                    var liNavObserver = new IntersectionObserver(function(entries) {
                        if (liIsManualScrolling) return;
                        entries.forEach(function(entry) {
                            if (entry.isIntersecting) {
                                var id = entry.target.id;
                                var activeNav = document.querySelector('.li-nav-item[data-target="' + id + '"]');
                                if (activeNav && !activeNav.classList.contains('is-active')) {
                                    document.querySelectorAll('.li-nav-item').forEach(function(n) { 
                                        n.classList.remove('is-active'); 
                                    });
                                    activeNav.classList.add('is-active');
                                }
                            }
                        });
                    }, {
                        rootMargin: '-15% 0px -70% 0px',
                        threshold: 0
                    });

                    liObserverSections.forEach(function(sec) {
                        liNavObserver.observe(sec);
                    });
                }
            }

            function liInitTheme() {
                var wraps = document.querySelectorAll('.li-wrap');
                if (!wraps.length) return;
                var saved = localStorage.getItem('li_dashboard_theme');
                var theme = saved || wraps[0].getAttribute('data-theme') || 'dark';
                wraps.forEach(function(wrap) { wrap.setAttribute('data-theme', theme); });
                liUpdateThemeUI(theme);
            }

            function liToggleTheme() {
                var wraps = document.querySelectorAll('.li-wrap');
                if (!wraps.length) return;
                var current = wraps[0].getAttribute('data-theme') || 'dark';
                var next = (current === 'dark') ? 'light' : 'dark';
                wraps.forEach(function(wrap) { wrap.setAttribute('data-theme', next); });
                localStorage.setItem('li_dashboard_theme', next);
                liUpdateThemeUI(next);
            }

            function liUpdateThemeUI(theme) {
                var btns = document.querySelectorAll('.li-theme-toggle-btn');
                btns.forEach(function(btn) {
                    var icon = btn.querySelector('.li-theme-toggle-icon');
                    var text = btn.querySelector('.li-theme-toggle-text');
                    if (theme === 'dark') {
                        if (icon) icon.textContent = '🌙';
                        if (text) text.textContent = 'Modo Escuro';
                        btn.classList.add('is-dark');
                        btn.classList.remove('is-light');
                    } else {
                        if (icon) icon.textContent = '☀️';
                        if (text) text.textContent = 'Modo Claro';
                        btn.classList.add('is-light');
                        btn.classList.remove('is-dark');
                    }
                });
            }

            function liToggleFullscreen() {
                var wrap = document.querySelector('.li-wrap');
                if (!wrap) return;
                var isFull = !!(document.fullscreenElement || document.webkitFullscreenElement);
                if (!isFull) {
                    if (wrap.requestFullscreen) {
                        wrap.requestFullscreen();
                    } else if (wrap.webkitRequestFullscreen) {
                        wrap.webkitRequestFullscreen();
                    }
                } else {
                    if (document.exitFullscreen) {
                        document.exitFullscreen();
                    } else if (document.webkitExitFullscreen) {
                        document.webkitExitFullscreen();
                    }
                }
            }

            function liUpdateFullscreenUI() {
                var isFull = !!(document.fullscreenElement || document.webkitFullscreenElement);
                var btns = document.querySelectorAll('.li-fullscreen-btn');
                var wraps = document.querySelectorAll('.li-wrap');
                wraps.forEach(function(w) {
                    if (isFull) {
                        w.classList.add('is-fullscreen');
                    } else {
                        w.classList.remove('is-fullscreen');
                    }
                });
                btns.forEach(function(btn) {
                    var text = btn.querySelector('.li-fullscreen-text');
                    var icon = btn.querySelector('.li-fullscreen-icon');
                    if (isFull) {
                        if (text) text.textContent = 'Sair da Tela Cheia';
                        if (icon) icon.textContent = '✕';
                    } else {
                        if (text) text.textContent = 'Tela Cheia';
                        if (icon) icon.textContent = '⛶';
                    }
                });
            }

            document.addEventListener('fullscreenchange', liUpdateFullscreenUI);
            document.addEventListener('webkitfullscreenchange', liUpdateFullscreenUI);

            function liFormatDateYMD(d) {
                var year = d.getFullYear();
                var month = String(d.getMonth() + 1).padStart(2, '0');
                var day = String(d.getDate()).padStart(2, '0');
                return year + '-' + month + '-' + day;
            }

            function liOnPeriodoChange(val) {
                var inputFrom = document.getElementById('liDateInputFrom');
                var inputTo = document.getElementById('liDateInputTo');
                if (!inputFrom || !inputTo) return;

                var today = new Date();
                var from = '', to = '';

                if (val === 'today') {
                    from = liFormatDateYMD(today);
                    to = liFormatDateYMD(today);
                } else if (val === 'yesterday') {
                    var y = new Date(today);
                    y.setDate(today.getDate() - 1);
                    from = liFormatDateYMD(y);
                    to = liFormatDateYMD(y);
                } else if (val === '7d') {
                    var d7 = new Date(today);
                    d7.setDate(today.getDate() - 7);
                    from = liFormatDateYMD(d7);
                    to = liFormatDateYMD(today);
                } else if (val === '30d') {
                    var d30 = new Date(today);
                    d30.setDate(today.getDate() - 30);
                    from = liFormatDateYMD(d30);
                    to = liFormatDateYMD(today);
                } else if (val === 'month') {
                    var mStart = new Date(today.getFullYear(), today.getMonth(), 1);
                    from = liFormatDateYMD(mStart);
                    to = liFormatDateYMD(today);
                } else if (val === 'last_month') {
                    var lmStart = new Date(today.getFullYear(), today.getMonth() - 1, 1);
                    var lmEnd = new Date(today.getFullYear(), today.getMonth(), 0);
                    from = liFormatDateYMD(lmStart);
                    to = liFormatDateYMD(lmEnd);
                } else if (val === 'all') {
                    from = '';
                    to = '';
                } else if (val === 'custom') {
                    inputFrom.focus();
                    return;
                }

                inputFrom.value = from;
                inputTo.value = to;
            }

            function liOnDateInputChange() {
                var select = document.getElementById('liPeriodoSelect');
                if (select) {
                    select.value = 'custom';
                }
            }

            function toggleCustomDates(val) {
                liOnPeriodoChange(val);
            }

            function recalcularCPL() {
                var input = document.getElementById('liInvestimentoInput');
                var geral = document.getElementById('liCPLGeral');
                var qual = document.getElementById('liCPLQualificado');
                if (!input || !geral || !qual) return;

                var investimento = parseFloat(input.value) || 0;
                var totalLeads = <?php echo (int) $total_leads; ?>;
                var qualificados = <?php echo (int) $total_qualificados; ?>;

                var cplGeral = totalLeads > 0 ? (investimento / totalLeads) : 0;
                var cplQual = qualificados > 0 ? (investimento / qualificados) : 0;

                geral.innerText = 'R$ ' + cplGeral.toFixed(2).replace('.', ',');
                qual.innerText = 'R$ ' + cplQual.toFixed(2).replace('.', ',');
            }

            function liToggleSidebarCollapse() {
                var layout = document.querySelector('.li-app-layout');
                if (!layout) return;
                var isCollapsed = layout.classList.toggle('li-sidebar-collapsed');
                localStorage.setItem('li_sidebar_collapsed', isCollapsed ? '1' : '0');
                liUpdateCollapseUI(isCollapsed);
            }

            function liUpdateCollapseUI(isCollapsed) {
                var btn = document.querySelector('.li-sidebar-toggle-btn span');
                if (btn) {
                    btn.textContent = isCollapsed ? '⇥' : '⇤';
                }
                var topbarIcon = document.querySelector('.li-sidebar-toggle-topbar .li-toggle-icon');
                if (topbarIcon) {
                    topbarIcon.textContent = isCollapsed ? '⇥' : '☰';
                }
            }

            function liInitSidebarCollapse() {
                var layout = document.querySelector('.li-app-layout');
                if (!layout) return;
                var saved = localStorage.getItem('li_sidebar_collapsed');
                var isCollapsed = (saved === '1');
                if (isCollapsed) {
                    layout.classList.add('li-sidebar-collapsed');
                }
                liUpdateCollapseUI(isCollapsed);
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function() {
                    liInitTheme();
                    liInitSidebarCollapse();
                    recalcularCPL();
                });
            } else {
                liInitTheme();
                liInitSidebarCollapse();
                recalcularCPL();
            }
            </script>
        </div>
        <?php
    }
}
