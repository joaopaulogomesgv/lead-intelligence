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
        $theme                = 'light';
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
        $logo            = !empty($options['logo']) ? esc_url_raw($options['logo']) : '';
        if (empty($logo)) {
            $logo = !empty($options['logo_light']) ? esc_url_raw($options['logo_light']) : '';
        }
        if (empty($logo)) {
            $logo = !empty($plugin_settings['logo_light']) ? esc_url_raw($plugin_settings['logo_light']) : '';
        }
        if (empty($logo)) {
            $logo = !empty($options['logo_dark']) ? esc_url_raw($options['logo_dark']) : ($plugin_settings['logo_dark'] ?? '');
        }
        $logo_compact    = !empty($options['logo_compact']) ? esc_url_raw($options['logo_compact']) : ($plugin_settings['logo_compact'] ?? '');

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

        // Construção da cláusula WHERE base (filtros universais: datas, campanhas, cursos, áreas)
        $where_base = ['1=1'];
        $params_base = [];

        if (!empty($date_start)) {
            $where_base[] = "data_cadastro >= %s";
            $params_base[] = $date_start;
        }
        if (!empty($date_end)) {
            $where_base[] = "data_cadastro <= %s";
            $params_base[] = $date_end;
        }
        if (!empty($filter_campaign)) {
            $where_base[] = "utm_campaign = %s";
            $params_base[] = $filter_campaign;
        }
        if (!empty($filter_curso)) {
            $where_base[] = "tipo_curso = %s";
            $params_base[] = $filter_curso;
        }
        if (!empty($filter_area)) {
            $where_base[] = "area_interesse = %s";
            $params_base[] = $filter_area;
        }

        // Construção do WHERE geral para o Dashboard (inclui filtro manual de canal se selecionado)
        $where = $where_base;
        $params = $params_base;

        if (!empty($filter_channel)) {
            $chan_sql = LeadRepository::get_channel_sql_condition($filter_channel);
            if (!empty($chan_sql)) {
                $where[] = $chan_sql;
            }
        }

        $where_sql = implode(' AND ', $where);

        // Resumo Comparativo por Canal (Google Ads vs Meta Ads)
        $channel_summary = LeadRepository::get_channel_comparison_summary($date_start, $date_end);

        // 2. Métricas dos Cards Gerais
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

        // 3. Desempenho Geral por Campanha
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

        // 4. Desempenho Geral por Anúncio / Criativo (ad_name ou utm_content)
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

        // 5. Evolução Diária Geral
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

        // =========================================================================
        // DADOS SEGMENTADOS POR CANAL (META ADS & GOOGLE ADS)
        // =========================================================================
        $meta_condition   = LeadRepository::get_channel_sql_condition('meta_ads');
        $google_condition = LeadRepository::get_channel_sql_condition('google_ads');

        // --- META ADS: CONSTRUÇÃO DE QUERIES E MÉTRICAS ---
        $where_meta = $where_base;
        $where_meta[] = $meta_condition;
        $where_meta_sql = implode(' AND ', $where_meta);

        $m_metrics_query = "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN qualificacao_status = 'qualificado' THEN 1 ELSE 0 END) as qualificados,
            SUM(CASE WHEN qualificacao_status = 'nao_qualificado' THEN 1 ELSE 0 END) as desqualificados,
            SUM(CASE WHEN qualificacao_status = 'pendente' THEN 1 ELSE 0 END) as pendentes
            FROM {$table} WHERE {$where_meta_sql}";
        $m_metrics = !empty($params_base) ? $wpdb->get_row($wpdb->prepare($m_metrics_query, $params_base)) : $wpdb->get_row($m_metrics_query);

        $m_total = $m_metrics ? (int) $m_metrics->total : 0;
        $m_qual  = $m_metrics ? (int) $m_metrics->qualificados : 0;
        $m_desq  = $m_metrics ? (int) $m_metrics->desqualificados : 0;
        $m_pend  = $m_metrics ? (int) $m_metrics->pendentes : 0;
        $m_taxa  = $m_total > 0 ? round(($m_qual / $m_total) * 100, 1) : 0;

        $m_data = (object) [
            'canal'            => 'meta_ads',
            'total_leads'      => $m_total,
            'qualificados'     => $m_qual,
            'desqualificados'  => $m_desq,
            'pendentes'        => $m_pend,
            'taxa'             => $m_taxa,
        ];

        $camp_meta_query = "SELECT 
            COALESCE(NULLIF(utm_campaign, ''), 'Direto / Sem Campanha') as campanha,
            COUNT(*) as leads,
            SUM(CASE WHEN qualificacao_status = 'qualificado' THEN 1 ELSE 0 END) as qualificados,
            SUM(CASE WHEN qualificacao_status = 'nao_qualificado' THEN 1 ELSE 0 END) as desqualificados,
            SUM(CASE WHEN qualificacao_status = 'pendente' THEN 1 ELSE 0 END) as pendentes
            FROM {$table} 
            WHERE {$where_meta_sql} 
            GROUP BY campanha 
            ORDER BY qualificados DESC, leads DESC 
            LIMIT 15";
        $campaigns_meta = !empty($params_base) ? $wpdb->get_results($wpdb->prepare($camp_meta_query, $params_base)) : $wpdb->get_results($camp_meta_query);

        $ad_meta_query = "SELECT 
            COALESCE(NULLIF(ad_name, ''), NULLIF(utm_content, ''), 'Sem Anúncio Identificado') as anuncio,
            COALESCE(NULLIF(utm_campaign, ''), '-') as campanha,
            COUNT(*) as leads,
            SUM(CASE WHEN qualificacao_status = 'qualificado' THEN 1 ELSE 0 END) as qualificados
            FROM {$table} 
            WHERE {$where_meta_sql} 
            GROUP BY anuncio, campanha 
            ORDER BY qualificados DESC, leads DESC 
            LIMIT 15";
        $ads_meta = !empty($params_base) ? $wpdb->get_results($wpdb->prepare($ad_meta_query, $params_base)) : $wpdb->get_results($ad_meta_query);

        $daily_meta_query = "SELECT 
            DATE(data_cadastro) as dia,
            COUNT(*) as total,
            SUM(CASE WHEN qualificacao_status = 'qualificado' THEN 1 ELSE 0 END) as qualificados
            FROM {$table} 
            WHERE {$where_meta_sql} 
            GROUP BY dia 
            ORDER BY dia ASC 
            LIMIT 31";
        $daily_evolution_meta = !empty($params_base) ? $wpdb->get_results($wpdb->prepare($daily_meta_query, $params_base)) : $wpdb->get_results($daily_meta_query);

        $max_daily_meta = 1;
        if (!empty($daily_evolution_meta)) {
            foreach ($daily_evolution_meta as $d) {
                if ($d->total > $max_daily_meta) {
                    $max_daily_meta = $d->total;
                }
            }
        }

        // --- GOOGLE ADS: CONSTRUÇÃO DE QUERIES E MÉTRICAS ---
        $where_google = $where_base;
        $where_google[] = $google_condition;
        $where_google_sql = implode(' AND ', $where_google);

        $g_metrics_query = "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN qualificacao_status = 'qualificado' THEN 1 ELSE 0 END) as qualificados,
            SUM(CASE WHEN qualificacao_status = 'nao_qualificado' THEN 1 ELSE 0 END) as desqualificados,
            SUM(CASE WHEN qualificacao_status = 'pendente' THEN 1 ELSE 0 END) as pendentes
            FROM {$table} WHERE {$where_google_sql}";
        $g_metrics = !empty($params_base) ? $wpdb->get_row($wpdb->prepare($g_metrics_query, $params_base)) : $wpdb->get_row($g_metrics_query);

        $g_total = $g_metrics ? (int) $g_metrics->total : 0;
        $g_qual  = $g_metrics ? (int) $g_metrics->qualificados : 0;
        $g_desq  = $g_metrics ? (int) $g_metrics->desqualificados : 0;
        $g_pend  = $g_metrics ? (int) $g_metrics->pendentes : 0;
        $g_taxa  = $g_total > 0 ? round(($g_qual / $g_total) * 100, 1) : 0;

        $g_data = (object) [
            'canal'            => 'google_ads',
            'total_leads'      => $g_total,
            'qualificados'     => $g_qual,
            'desqualificados'  => $g_desq,
            'pendentes'        => $g_pend,
            'taxa'             => $g_taxa,
        ];

        $camp_google_query = "SELECT 
            COALESCE(NULLIF(utm_campaign, ''), 'Direto / Sem Campanha') as campanha,
            COUNT(*) as leads,
            SUM(CASE WHEN qualificacao_status = 'qualificado' THEN 1 ELSE 0 END) as qualificados,
            SUM(CASE WHEN qualificacao_status = 'nao_qualificado' THEN 1 ELSE 0 END) as desqualificados,
            SUM(CASE WHEN qualificacao_status = 'pendente' THEN 1 ELSE 0 END) as pendentes
            FROM {$table} 
            WHERE {$where_google_sql} 
            GROUP BY campanha 
            ORDER BY qualificados DESC, leads DESC 
            LIMIT 15";
        $campaigns_google = !empty($params_base) ? $wpdb->get_results($wpdb->prepare($camp_google_query, $params_base)) : $wpdb->get_results($camp_google_query);

        $ad_google_query = "SELECT 
            COALESCE(NULLIF(utm_term, ''), NULLIF(utm_content, ''), 'Palavra-chave Geral') as anuncio,
            COALESCE(NULLIF(utm_campaign, ''), '-') as campanha,
            COUNT(*) as leads,
            SUM(CASE WHEN qualificacao_status = 'qualificado' THEN 1 ELSE 0 END) as qualificados
            FROM {$table} 
            WHERE {$where_google_sql} 
            GROUP BY anuncio, campanha 
            ORDER BY qualificados DESC, leads DESC 
            LIMIT 15";
        $ads_google = !empty($params_base) ? $wpdb->get_results($wpdb->prepare($ad_google_query, $params_base)) : $wpdb->get_results($ad_google_query);

        $daily_google_query = "SELECT 
            DATE(data_cadastro) as dia,
            COUNT(*) as total,
            SUM(CASE WHEN qualificacao_status = 'qualificado' THEN 1 ELSE 0 END) as qualificados
            FROM {$table} 
            WHERE {$where_google_sql} 
            GROUP BY dia 
            ORDER BY dia ASC 
            LIMIT 31";
        $daily_evolution_google = !empty($params_base) ? $wpdb->get_results($wpdb->prepare($daily_google_query, $params_base)) : $wpdb->get_results($daily_google_query);

        $max_daily_google = 1;
        if (!empty($daily_evolution_google)) {
            foreach ($daily_evolution_google as $d) {
                if ($d->total > $max_daily_google) {
                    $max_daily_google = $d->total;
                }
            }
        }

        $active_tab = isset($_GET['tab']) && in_array($_GET['tab'], ['dashboard', 'leads-meta', 'leads-google'], true) ? sanitize_text_field($_GET['tab']) : 'dashboard';
        ?>
        <div class="wrap li-wrap li-wrap-dashboard <?php echo $is_frontend ? 'li-frontend-wrap' : ''; ?> <?php echo $is_full_width ? 'li-full-width' : ''; ?>" data-theme="light">
            <div class="li-app-layout">
                <!-- ========================================================
                     1. MENU LATERAL À ESQUERDA (SIDEBAR HUD)
                     ======================================================== -->
                <aside class="li-sidebar" id="liSidebar">
                    <div class="li-sidebar-header">
                        <div class="li-sidebar-header-top">
                            <div class="li-brand-logos li-brand-toggle" onclick="liToggleSidebarCollapse()" role="button" tabindex="0" title="Clique para recolher ou expandir o menu">
                                <?php if (!empty($logo)): ?>
                                    <img src="<?php echo esc_url($logo); ?>" alt="Logo Faveni" class="li-logo-img li-logo-expanded" />
                                <?php else: ?>
                                    <div class="li-sidebar-brand-text li-logo-expanded">FAVENI</div>
                                <?php endif; ?>

                                <?php if (!empty($logo_compact)): ?>
                                    <img src="<?php echo esc_url($logo_compact); ?>" alt="Logo Faveni" class="li-logo-img li-logo-compact" />
                                <?php else: ?>
                                    <div class="li-logo-compact-fallback li-logo-compact" title="FAVENI">
                                        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="var(--li-gold)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M22 10v6M2 10l10-5 10 5-10 5z"></path>
                                            <path d="M6 12v5c3 3 9 3 12 0v-5"></path>
                                        </svg>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <nav class="li-sidebar-nav">
                        <div class="li-nav-group-title">MÓDULOS</div>
                        <a href="#dashboard" class="li-nav-item is-active" data-tab="dashboard" onclick="liSwitchTab('dashboard'); return false;" title="Dashboard Geral (<?php echo (int) $total_leads; ?> leads)">
                            <span class="li-nav-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect width="7" height="9" x="3" y="3" rx="1.5"></rect>
                                    <rect width="7" height="5" x="14" y="3" rx="1.5"></rect>
                                    <rect width="7" height="9" x="14" y="12" rx="1.5"></rect>
                                    <rect width="7" height="5" x="3" y="16" rx="1.5"></rect>
                                </svg>
                            </span>
                            <span class="li-nav-text">Dashboard</span>
                            <span class="li-nav-badge"><?php echo (int) $total_leads; ?></span>
                        </a>
                        <a href="#leads-meta" class="li-nav-item" data-tab="leads-meta" onclick="liSwitchTab('leads-meta'); return false;" title="Leads Meta Ads (<?php echo (int) $m_data->total_leads; ?> leads)">
                            <span class="li-nav-icon">
                                <svg class="li-brand-icon li-brand-meta" viewBox="0 0 24 24" width="18" height="18" fill="none">
                                    <path fill="#0081FB" d="M6.915 4.03c-1.968 0-3.683 1.28-4.871 3.113C.704 9.208 0 11.883 0 14.449c0 .706.07 1.369.21 1.973a6.624 6.624 0 0 0 .265.86 5.297 5.297 0 0 0 .371.761c.696 1.159 1.818 1.927 3.593 1.927 1.497 0 2.633-.671 3.965-2.444.76-1.012 1.144-1.626 2.663-4.32l.756-1.339.186-.325c.061.1.121.196.183.3l2.152 3.595c.724 1.21 1.665 2.556 2.47 3.314 1.046.987 1.992 1.22 3.06 1.22 1.075 0 1.876-.355 2.455-.843a3.743 3.743 0 0 0 .81-.973c.542-.939.861-2.127.861-3.745 0-2.72-.681-5.357-2.084-7.45-1.282-1.912-2.957-2.93-4.716-2.93-1.047 0-2.088.467-3.053 1.308-.652.57-1.257 1.29-1.82 2.05-.69-.875-1.335-1.547-1.958-2.056-1.182-.966-2.315-1.303-3.454-1.303zm10.16 2.053c1.147 0 2.188.758 2.992 1.999 1.132 1.748 1.647 4.195 1.647 6.4 0 1.548-.368 2.9-1.839 2.9-.58 0-1.027-.23-1.664-1.004-.496-.601-1.343-1.878-2.832-4.358l-.617-1.028a44.908 44.908 0 0 0-1.255-1.98c.07-.109.141-.224.211-.327 1.12-1.667 2.118-2.602 3.358-2.602zm-10.201.553c1.265 0 2.058.791 2.675 1.446.307.327.737.871 1.234 1.579l-1.02 1.566c-.757 1.163-1.882 3.017-2.837 4.338-1.191 1.649-1.81 1.817-2.486 1.817-.524 0-1.038-.237-1.383-.794-.263-.426-.464-1.13-.464-2.046 0-2.221.63-4.535 1.66-6.088.454-.687.964-1.226 1.533-1.533a2.264 2.264 0 0 1 1.088-.285z"/>
                                </svg>
                            </span>
                            <span class="li-nav-text">Leads Meta</span>
                            <span class="li-nav-badge"><?php echo (int) $m_data->total_leads; ?></span>
                        </a>
                        <a href="#leads-google" class="li-nav-item" data-tab="leads-google" onclick="liSwitchTab('leads-google'); return false;" title="Leads Google Ads (<?php echo (int) $g_data->total_leads; ?> leads)">
                            <span class="li-nav-icon">
                                <svg class="li-brand-icon li-brand-google" viewBox="0 0 24 24" width="18" height="18" fill="none">
                                    <path fill="#4285F4" d="M23.745 12.27c0-.7-.06-1.4-.19-2.07H12v4.51h6.6c-.29 1.52-1.14 2.82-2.4 3.68v3.05h3.88c2.27-2.09 3.665-5.17 3.665-9.17Z"/>
                                    <path fill="#34A853" d="M12 24c3.24 0 5.95-1.08 7.93-2.91l-3.88-3.05c-1.08.72-2.45 1.16-4.05 1.16-3.12 0-5.77-2.1-6.72-4.93H1.25v3.15C3.26 21.36 7.33 24 12 24Z"/>
                                    <path fill="#FBBC05" d="M5.28 14.27c-.25-.72-.38-1.49-.38-2.27s.13-1.55.38-2.27V6.58H1.25C.45 8.18 0 10.03 0 12s.45 3.82 1.25 5.42l4.03-3.15Z"/>
                                    <path fill="#EA4335" d="M12 4.75c1.77 0 3.35.61 4.6 1.8l3.42-3.42C17.95 1.19 15.24 0 12 0 7.33 0 3.26 2.64 1.25 6.58l4.03 3.15c.95-2.83 3.6-4.98 6.72-4.98Z"/>
                                </svg>
                            </span>
                            <span class="li-nav-text">Leads Google</span>
                            <span class="li-nav-badge"><?php echo (int) $g_data->total_leads; ?></span>
                        </a>

                        <?php if (current_user_can('manage_options')): ?>
                            <div class="li-nav-group-title" style="margin-top: 18px;">ADMINISTRAÇÃO</div>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-leads')); ?>" class="li-nav-item" target="_blank" title="Lista de Leads">
                                <span class="li-nav-icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
                                        <circle cx="9" cy="7" r="4"></circle>
                                        <path d="M22 21v-2a4 4 0 0 0-3-3.87"></path>
                                        <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                                    </svg>
                                </span>
                                <span class="li-nav-text">Lista de Leads</span>
                                <span class="li-nav-ext"><svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17l9.2-9.2M17 17V7H7"></path></svg></span>
                            </a>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-import')); ?>" class="li-nav-item" target="_blank" title="Importar Planilha">
                                <span class="li-nav-icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"></path>
                                        <path d="M14 2v4a2 2 0 0 0 2 2h4"></path>
                                        <path d="M12 12v6"></path>
                                        <path d="m9 15 3-3 3 3"></path>
                                    </svg>
                                </span>
                                <span class="li-nav-text">Importar Planilha</span>
                                <span class="li-nav-ext"><svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17l9.2-9.2M17 17V7H7"></path></svg></span>
                            </a>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-settings')); ?>" class="li-nav-item" target="_blank" title="Configurações">
                                <span class="li-nav-icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="12" cy="12" r="3"></circle>
                                        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
                                    </svg>
                                </span>
                                <span class="li-nav-text">Configurações</span>
                                <span class="li-nav-ext"><svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17l9.2-9.2M17 17V7H7"></path></svg></span>
                            </a>
                        <?php endif; ?>
                    </nav>

                    <div class="li-sidebar-footer">
                        <div class="li-sidebar-active-status">
                            <span class="li-status-pulse"></span>
                            <span class="li-status-text">Monitoramento Ativo</span>
                        </div>
                        <span class="li-sidebar-ver">Lead Intelligence v<?php echo LEAD_INTELLIGENCE_VERSION; ?></span>
                    </div>
                </aside>

                <!-- ========================================================
                     2. CONTEÚDO PRINCIPAL (DASHBOARD EXECUTIVO)
                     ======================================================== -->
                <div class="li-main-wrapper">
                    <div class="li-content-scroll">

                        <!-- CABEÇALHO DINÂMICO DA ABA (TÍTULO E SUBTÍTULO NO TOPO) -->
                        <div class="li-tab-header li-top-tab-header" id="liTopHeader-dashboard" style="<?php echo $active_tab === 'dashboard' ? '' : 'display: none;'; ?>">
                            <h1 class="li-tab-title">Dashboard</h1>
                            <p class="li-tab-subtitle">Visão executiva em tempo real de captação, qualidade e conversão de leads</p>
                        </div>

                        <div class="li-tab-header li-top-tab-header" id="liTopHeader-leads-meta" style="<?php echo $active_tab === 'leads-meta' ? '' : 'display: none;'; ?>">
                            <h1 class="li-tab-title" style="display: flex; align-items: center; gap: 10px;">
                                <svg class="li-brand-icon li-brand-meta" viewBox="0 0 24 24" width="28" height="28" fill="none" style="flex-shrink: 0;"><path fill="#0081FB" d="M6.915 4.03c-1.968 0-3.683 1.28-4.871 3.113C.704 9.208 0 11.883 0 14.449c0 .706.07 1.369.21 1.973a6.624 6.624 0 0 0 .265.86 5.297 5.297 0 0 0 .371.761c.696 1.159 1.818 1.927 3.593 1.927 1.497 0 2.633-.671 3.965-2.444.76-1.012 1.144-1.626 2.663-4.32l.756-1.339.186-.325c.061.1.121.196.183.3l2.152 3.595c.724 1.21 1.665 2.556 2.47 3.314 1.046.987 1.992 1.22 3.06 1.22 1.075 0 1.876-.355 2.455-.843a3.743 3.743 0 0 0 .81-.973c.542-.939.861-2.127.861-3.745 0-2.72-.681-5.357-2.084-7.45-1.282-1.912-2.957-2.93-4.716-2.93-1.047 0-2.088.467-3.053 1.308-.652.57-1.257 1.29-1.82 2.05-.69-.875-1.335-1.547-1.958-2.056-1.182-.966-2.315-1.303-3.454-1.303zm10.16 2.053c1.147 0 2.188.758 2.992 1.999 1.132 1.748 1.647 4.195 1.647 6.4 0 1.548-.368 2.9-1.839 2.9-.58 0-1.027-.23-1.664-1.004-.496-.601-1.343-1.878-2.832-4.358l-.617-1.028a44.908 44.908 0 0 0-1.255-1.98c.07-.109.141-.224.211-.327 1.12-1.667 2.118-2.602 3.358-2.602zm-10.201.553c1.265 0 2.058.791 2.675 1.446.307.327.737.871 1.234 1.579l-1.02 1.566c-.757 1.163-1.882 3.017-2.837 4.338-1.191 1.649-1.81 1.817-2.486 1.817-.524 0-1.038-.237-1.383-.794-.263-.426-.464-1.13-.464-2.046 0-2.221.63-4.535 1.66-6.088.454-.687.964-1.226 1.533-1.533a2.264 2.264 0 0 1 1.088-.285z"/></svg>
                                Leads Meta Ads
                            </h1>
                            <p class="li-tab-subtitle">Performance e qualidade dos contatos gerados via campanhas do Facebook e Instagram Ads</p>
                        </div>

                        <div class="li-tab-header li-top-tab-header" id="liTopHeader-leads-google" style="<?php echo $active_tab === 'leads-google' ? '' : 'display: none;'; ?>">
                            <h1 class="li-tab-title" style="display: flex; align-items: center; gap: 10px;">
                                <svg class="li-brand-icon li-brand-google" viewBox="0 0 24 24" width="26" height="26" fill="none" style="flex-shrink: 0;"><path fill="#4285F4" d="M23.745 12.27c0-.7-.06-1.4-.19-2.07H12v4.51h6.6c-.29 1.52-1.14 2.82-2.4 3.68v3.05h3.88c2.27-2.09 3.665-5.17 3.665-9.17Z"/><path fill="#34A853" d="M12 24c3.24 0 5.95-1.08 7.93-2.91l-3.88-3.05c-1.08.72-2.45 1.16-4.05 1.16-3.12 0-5.77-2.1-6.72-4.93H1.25v3.15C3.26 21.36 7.33 24 12 24Z"/><path fill="#FBBC05" d="M5.28 14.27c-.25-.72-.38-1.49-.38-2.27s.13-1.55.38-2.27V6.58H1.25C.45 8.18 0 10.03 0 12s.45 3.82 1.25 5.42l4.03-3.15Z"/><path fill="#EA4335" d="M12 4.75c1.77 0 3.35.61 4.6 1.8l3.42-3.42C17.95 1.19 15.24 0 12 0 7.33 0 3.26 2.64 1.25 6.58l4.03 3.15c.95-2.83 3.6-4.98 6.72-4.98Z"/></svg>
                                Leads Google Ads
                            </h1>
                            <p class="li-tab-subtitle">Performance e qualidade dos contatos gerados via Rede de Pesquisa e Display Google</p>
                        </div>

                        <?php if ($show_filters): ?>
                            <!-- BARRA DE FILTROS DO DASHBOARD (HUD TOOLBAR - TOPO) -->
                            <div id="li-sec-filters" class="li-card li-filter-bar">
                                <form method="get" action="<?php echo esc_url($form_action); ?>" class="li-filter-form">
                                    <?php if (!$is_frontend): ?>
                                        <input type="hidden" name="page" value="lead-intelligence">
                                    <?php endif; ?>
                                    <input type="hidden" name="tab" id="liActiveTabInput" value="<?php echo esc_attr($active_tab); ?>">

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
                                                <option value="custom" <?php selected($periodo, 'custom'); ?>>Personalizado...</option>
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
                                                <button type="submit" class="button button-primary li-btn li-btn-faveni" style="display: inline-flex; align-items: center; gap: 6px;">
                                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                                                    Filtrar
                                                </button>
                                                <a href="<?php echo esc_url($reset_url); ?>" class="button li-btn li-btn-ghost" title="Limpar todos os filtros" style="display: inline-flex; align-items: center; gap: 6px;">
                                                    <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path><path d="M3 3v5h5"></path></svg>
                                                    Resetar
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        <?php endif; ?>

                        <!-- =========================================================================
                             ABA 1: DASHBOARD GERAL CONSOLIDADO
                             ========================================================================= -->
                        <div id="li-panel-dashboard" class="li-tab-panel <?php echo $active_tab === 'dashboard' ? 'is-active' : ''; ?>">
                            <!-- HERO HIGHLIGHTS GERAIS -->
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
                                            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M22 10v6M2 10l10-5 10 5-10 5z"></path>
                                                <path d="M6 12v5c3 3 9 3 12 0v-5"></path>
                                            </svg>
                                        </div>
                                    </div>
                                    <div class="li-hero-card-footer">
                                        <span class="li-hero-stat-highlight"><?php echo $taxa_qualificacao; ?>%</span>
                                        <span class="li-hero-stat-sub">de conversão qualificada</span>
                                    </div>
                                </div>

                                <!-- CARD 2: VERDE FLORESTA (TOTAL GERAL DE LEADS) -->
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
                                            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
                                                <circle cx="9" cy="7" r="4"></circle>
                                                <path d="M22 21v-2a4 4 0 0 0-3-3.87"></path>
                                                <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                                            </svg>
                                        </div>
                                    </div>
                                    <div class="li-hero-card-footer">
                                        <span class="li-hero-stat-highlight">Elementor • Meta • Google</span>
                                        <span class="li-hero-stat-sub">Base ativa centralizada</span>
                                    </div>
                                </div>

                                <!-- CARD 3: STATUS DA OPERAÇÃO -->
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
                                            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
                                            </svg>
                                        </div>
                                    </div>
                                    <div class="li-hero-card-footer">
                                        <span class="li-hero-stat-highlight"><?php echo number_format_i18n($total_desqualif); ?> não qualificados</span>
                                        <span class="li-hero-stat-sub">triagem contínua de alunos</span>
                                    </div>
                                </div>
                            </div>

                            <?php if ($show_channel_compare): ?>
                                <!-- COMPARATIVO EXECUTIVO: GOOGLE ADS VS META ADS -->
                                <?php
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
                                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--li-brand); flex-shrink: 0;"><path d="m16 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"></path><path d="m2 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"></path><path d="M7 21h10"></path><path d="M12 3v18"></path><path d="M3 7h2c2 0 5-1 7-2 2 1 5 2 7 2h2"></path></svg>
                                                Comparativo de Performance: Google Ads vs Meta Ads
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
                                            <h3 class="li-calc-title" style="display: flex; align-items: center; gap: 8px;">
                                                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--li-gold); flex-shrink: 0;"><rect width="16" height="20" x="4" y="2" rx="2"></rect><line x1="8" x2="16" y1="6" y2="6"></line><line x1="16" x2="16" y1="14" y2="18"></line><path d="M16 10h.01"></path><path d="M12 10h.01"></path><path d="M8 10h.01"></path><path d="M12 14h.01"></path><path d="M8 14h.01"></path><path d="M12 18h.01"></path><path d="M8 18h.01"></path></svg>
                                                Calculadora HUD de CPL e Custo por Aluno
                                            </h3>
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
                                <!-- GRÁFICO DE EVOLUÇÃO DIÁRIA GERAL -->
                                <div id="li-sec-chart" class="li-card">
                                    <h3 class="li-card-title" style="display: flex; align-items: center; gap: 8px;">
                                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--li-brand); flex-shrink: 0;"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"></polyline><polyline points="16 7 22 7 22 13"></polyline></svg>
                                        Evolução Diária Consolidada (Captação vs. Qualificação)
                                    </h3>
                                    <p class="li-card-desc">Volume diário consolidado de leads captados e matrículas confirmadas.</p>

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
                        </div>

                        <!-- =========================================================================
                             ABA 2: LEADS META ADS
                             ========================================================================= -->
                        <div id="li-panel-leads-meta" class="li-tab-panel <?php echo $active_tab === 'leads-meta' ? 'is-active' : ''; ?>">
                            <!-- HERO HIGHLIGHTS META -->
                            <div class="li-hero-highlights">
                                <!-- CARD 1: DOURADO META -->
                                <div class="li-hero-card li-hero-card-gold">
                                    <div class="li-hero-card-top">
                                        <span class="li-hero-tag">META • CONVERSÃO REAL</span>
                                        <span class="li-hero-pill-badge">FACEBOOK &amp; INSTA</span>
                                    </div>
                                    <div class="li-hero-card-body">
                                        <div class="li-hero-metric-wrap">
                                            <div class="li-hero-metric"><?php echo number_format_i18n($m_data->qualificados); ?></div>
                                            <div class="li-hero-metric-lbl">Matrículas Meta Confirmadas</div>
                                        </div>
                                        <div class="li-hero-icon-box">
                                            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M22 10v6M2 10l10-5 10 5-10 5z"></path>
                                                <path d="M6 12v5c3 3 9 3 12 0v-5"></path>
                                            </svg>
                                        </div>
                                    </div>
                                    <div class="li-hero-card-footer">
                                        <span class="li-hero-stat-highlight"><?php echo $m_data->taxa; ?>%</span>
                                        <span class="li-hero-stat-sub">de qualificação no Meta</span>
                                    </div>
                                </div>

                                <!-- CARD 2: VERDE FLORESTA META -->
                                <div class="li-hero-card li-hero-card-forest">
                                    <div class="li-hero-card-top">
                                        <span class="li-hero-tag">META ADS • CAPTAÇÃO</span>
                                        <span class="li-hero-pill-badge" style="background: rgba(24, 119, 242, 0.2); color: #60a5fa;">● META PIXEL / CAPI</span>
                                    </div>
                                    <div class="li-hero-card-body">
                                        <div class="li-hero-metric-wrap">
                                            <div class="li-hero-metric"><?php echo number_format_i18n($m_data->total_leads); ?></div>
                                            <div class="li-hero-metric-lbl">Total de Leads Meta</div>
                                        </div>
                                        <div class="li-hero-icon-box">
                                            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M12 12c-2-2.67-4-4-6-4a4 4 0 1 0 0 8c2 0 4-1.33 6-4Zm0 0c2 2.67 4 4 6 4a4 4 0 1 0 0-8c-2 0-4 1.33-6 4Z"></path>
                                            </svg>
                                        </div>
                                    </div>
                                    <div class="li-hero-card-footer">
                                        <span class="li-hero-stat-highlight">Facebook &amp; Instagram</span>
                                        <span class="li-hero-stat-sub">Origem rastreada via fbclid/utm</span>
                                    </div>
                                </div>

                                <!-- CARD 3: STATUS OPERAÇÃO META -->
                                <div class="li-hero-card li-hero-card-forest">
                                    <div class="li-hero-card-top">
                                        <span class="li-hero-tag">STATUS META</span>
                                        <span class="li-hero-pill-badge li-badge-live">● EM QUALIFICAÇÃO</span>
                                    </div>
                                    <div class="li-hero-card-body">
                                        <div class="li-hero-metric-wrap">
                                            <div class="li-hero-metric"><?php echo number_format_i18n($m_data->pendentes); ?></div>
                                            <div class="li-hero-metric-lbl">Aguardando Retorno Comercial</div>
                                        </div>
                                        <div class="li-hero-icon-box">
                                            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
                                            </svg>
                                        </div>
                                    </div>
                                    <div class="li-hero-card-footer">
                                        <span class="li-hero-stat-highlight"><?php echo number_format_i18n($m_data->desqualificados); ?> não qualificados</span>
                                        <span class="li-hero-stat-sub">triados pela equipe Faveni</span>
                                    </div>
                                </div>
                            </div>

                            <!-- CARDS DE KPIS META -->
                            <div class="li-metric-grid">
                                <div class="li-metric-card li-metric-total">
                                    <span class="li-metric-label">Leads Meta Ads</span>
                                    <span class="li-metric-value"><?php echo number_format_i18n($m_data->total_leads); ?></span>
                                    <span class="li-metric-sub">Total no período</span>
                                </div>
                                <div class="li-metric-card li-card-success li-metric-qual">
                                    <span class="li-metric-label">Matrículas Confirmadas</span>
                                    <span class="li-metric-value"><?php echo number_format_i18n($m_data->qualificados); ?></span>
                                    <span class="li-metric-sub">Alunos convertidos</span>
                                </div>
                                <div class="li-metric-card li-card-warning li-metric-pend">
                                    <span class="li-metric-label">Pendentes Meta</span>
                                    <span class="li-metric-value"><?php echo number_format_i18n($m_data->pendentes); ?></span>
                                    <span class="li-metric-sub">Em atendimento</span>
                                </div>
                                <div class="li-metric-card li-card-danger li-metric-desq">
                                    <span class="li-metric-label">Não Qualificados</span>
                                    <span class="li-metric-value"><?php echo number_format_i18n($m_data->desqualificados); ?></span>
                                    <span class="li-metric-sub">Descartes / Sem perfil</span>
                                </div>
                                <div class="li-metric-card li-card-info li-metric-rate">
                                    <span class="li-metric-label">Taxa Meta Ads</span>
                                    <span class="li-metric-value"><?php echo $m_data->taxa; ?>%</span>
                                    <span class="li-metric-sub">Eficiência de conversão</span>
                                </div>
                            </div>

                            <!-- TABELA 1: CAMPANHAS META ADS -->
                            <div class="li-card" style="padding: 0; overflow-x: auto;">
                                <div style="padding: 20px 24px 12px; border-bottom: 1px solid #e2e8f0;">
                                    <h3 class="li-card-title" style="display: flex; align-items: center; gap: 8px;">
                                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--li-brand); flex-shrink: 0;"><circle cx="12" cy="12" r="10"></circle><circle cx="12" cy="12" r="6"></circle><circle cx="12" cy="12" r="2"></circle></svg>
                                        Qualidade de Leads por Campanha (Meta Ads)
                                    </h3>
                                    <p class="li-card-desc" style="margin-bottom: 0;">Campanhas do Facebook e Instagram classificadas por taxa de qualificação e matrículas geradas.</p>
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
                                        <?php if (empty($campaigns_meta)): ?>
                                            <tr>
                                                <td colspan="6" style="text-align: center; padding: 35px; color: #64748b;">
                                                    Nenhuma campanha Meta identificada no período selecionado.
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($campaigns_meta as $camp): ?>
                                                <?php
                                                $camp_taxa = $camp->leads > 0 ? round(($camp->qualificados / $camp->leads) * 100, 1) : 0;
                                                $is_high_quality = ($camp_taxa >= 25 && $camp->leads >= 5);
                                                ?>
                                                <tr>
                                                    <td><strong><?php echo esc_html($camp->campanha); ?></strong></td>
                                                    <td style="text-align: center; font-weight: 600;"><?php echo number_format_i18n($camp->leads); ?></td>
                                                    <td style="text-align: center;"><span style="font-weight: 700; color: #15803d;"><?php echo number_format_i18n($camp->qualificados); ?></span></td>
                                                    <td style="text-align: center; color: #b91c1c;"><?php echo number_format_i18n($camp->desqualificados); ?></td>
                                                    <td>
                                                        <div style="display: flex; align-items: center; gap: 8px;">
                                                            <div style="flex: 1; height: 8px; background: #e2e8f0; border-radius: 4px; overflow: hidden;">
                                                                <div style="width: <?php echo min(100, $camp_taxa); ?>%; height: 100%; background: <?php echo $camp_taxa >= 20 ? '#10b981' : ($camp_taxa >= 10 ? '#f59e0b' : '#ef4444'); ?>; border-radius: 4px;"></div>
                                                            </div>
                                                            <span style="font-size: 13px; font-weight: 700; min-width: 45px; text-align: right;"><?php echo $camp_taxa; ?>%</span>
                                                        </div>
                                                    </td>
                                                    <td style="text-align: center;">
                                                        <?php if ($is_high_quality): ?>
                                                            <span class="li-badge li-status-qualificado">⭐ Alta Qualidade</span>
                                                        <?php elseif ($camp_taxa < 10 && $camp->leads >= 10): ?>
                                                            <span class="li-badge li-status-desqualificado">⚠️ Baixa Qualidade</span>
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

                            <!-- TABELA 2: ANÚNCIOS / CRIATIVOS META ADS -->
                            <div class="li-card" style="padding: 0; overflow-x: auto;">
                                <div style="padding: 20px 24px 12px; border-bottom: 1px solid #e2e8f0;">
                                    <h3 class="li-card-title" style="display: flex; align-items: center; gap: 8px;">
                                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--li-brand); flex-shrink: 0;"><path d="m3 11 18-5v12L3 14v-3z"></path><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"></path></svg>
                                        Qualidade por Anúncio / Criativo (Meta Ads)
                                    </h3>
                                    <p class="li-card-desc" style="margin-bottom: 0;">Criativos do Facebook e Instagram que mais trazem alunos matriculados.</p>
                                </div>

                                <table class="wp-list-table widefat fixed striped li-table">
                                    <thead>
                                        <tr>
                                            <th>Criativo / Anúncio (ad_name / utm_content)</th>
                                            <th>Campanha</th>
                                            <th style="width: 110px; text-align: center;">Leads</th>
                                            <th style="width: 120px; text-align: center;">Qualificados</th>
                                            <th style="width: 150px; text-align: center;">Taxa de Qualificação</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($ads_meta)): ?>
                                            <tr>
                                                <td colspan="5" style="text-align: center; padding: 35px; color: #64748b;">
                                                    Nenhum anúncio Meta identificado no período.
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($ads_meta as $ad): ?>
                                                <?php $ad_taxa = $ad->leads > 0 ? round(($ad->qualificados / $ad->leads) * 100, 1) : 0; ?>
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

                            <?php if (!empty($daily_evolution_meta)): ?>
                                <!-- GRÁFICO DIÁRIO META ADS -->
                                <div class="li-card">
                                    <h3 class="li-card-title" style="display: flex; align-items: center; gap: 8px;">
                                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--li-brand); flex-shrink: 0;"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"></polyline><polyline points="16 7 22 7 22 13"></polyline></svg>
                                        Evolução Diária - Meta Ads (Captação vs. Matrículas)
                                    </h3>
                                    <p class="li-card-desc">Volume diário exclusivo do Facebook e Instagram Ads.</p>

                                    <div style="display: flex; align-items: flex-end; gap: 8px; height: 180px; padding-top: 20px; overflow-x: auto;">
                                        <?php foreach ($daily_evolution_meta as $day): ?>
                                            <?php
                                            $height_total = round(($day->total / $max_daily_meta) * 140);
                                            $height_qual  = $day->total > 0 ? round(($day->qualificados / $day->total) * $height_total) : 0;
                                            $day_label    = date_i18n('d/m', strtotime($day->dia));
                                            ?>
                                            <div style="display: flex; flex-direction: column; align-items: center; flex: 1; min-width: 32px;" title="<?php echo esc_attr("{$day->dia}\nTotal Meta: {$day->total}\nQualificados: {$day->qualificados}"); ?>">
                                                <div style="font-size: 10px; color: #64748b; margin-bottom: 4px;"><?php echo $day->total; ?></div>
                                                <div style="width: 22px; height: <?php echo max(4, $height_total); ?>px; background: #e2e8f0; border-radius: 4px; position: relative; overflow: hidden; display: flex; align-items: flex-end;">
                                                    <div style="width: 100%; height: <?php echo $height_qual; ?>px; background: #1877f2; border-radius: 0 0 4px 4px;"></div>
                                                </div>
                                                <div style="font-size: 10px; color: #94a3b8; margin-top: 6px;"><?php echo esc_html($day_label); ?></div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div style="display: flex; gap: 20px; justify-content: center; margin-top: 15px; font-size: 12px; color: #64748b;">
                                        <span style="display: flex; align-items: center; gap: 6px;">
                                            <span style="width: 12px; height: 12px; background: #e2e8f0; border-radius: 3px; display: inline-block;"></span>
                                            Total Meta Ads
                                        </span>
                                        <span style="display: flex; align-items: center; gap: 6px;">
                                            <span style="width: 12px; height: 12px; background: #1877f2; border-radius: 3px; display: inline-block;"></span>
                                            Matrículas Confirmadas
                                        </span>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- =========================================================================
                             ABA 3: LEADS GOOGLE ADS
                             ========================================================================= -->
                        <div id="li-panel-leads-google" class="li-tab-panel <?php echo $active_tab === 'leads-google' ? 'is-active' : ''; ?>">
                            <!-- HERO HIGHLIGHTS GOOGLE -->
                            <div class="li-hero-highlights">
                                <!-- CARD 1: DOURADO GOOGLE -->
                                <div class="li-hero-card li-hero-card-gold">
                                    <div class="li-hero-card-top">
                                        <span class="li-hero-tag">GOOGLE • CONVERSÃO REAL</span>
                                        <span class="li-hero-pill-badge">SEARCH &amp; DISPLAY</span>
                                    </div>
                                    <div class="li-hero-card-body">
                                        <div class="li-hero-metric-wrap">
                                            <div class="li-hero-metric"><?php echo number_format_i18n($g_data->qualificados); ?></div>
                                            <div class="li-hero-metric-lbl">Matrículas Google Confirmadas</div>
                                        </div>
                                        <div class="li-hero-icon-box">
                                            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M22 10v6M2 10l10-5 10 5-10 5z"></path>
                                                <path d="M6 12v5c3 3 9 3 12 0v-5"></path>
                                            </svg>
                                        </div>
                                    </div>
                                    <div class="li-hero-card-footer">
                                        <span class="li-hero-stat-highlight"><?php echo $g_data->taxa; ?>%</span>
                                        <span class="li-hero-stat-sub">de qualificação no Google</span>
                                    </div>
                                </div>

                                <!-- CARD 2: VERDE FLORESTA GOOGLE -->
                                <div class="li-hero-card li-hero-card-forest">
                                    <div class="li-hero-card-top">
                                        <span class="li-hero-tag">GOOGLE ADS • CAPTAÇÃO</span>
                                        <span class="li-hero-pill-badge" style="background: rgba(16, 185, 129, 0.2); color: #34d399;">● REDE DE PESQUISA</span>
                                    </div>
                                    <div class="li-hero-card-body">
                                        <div class="li-hero-metric-wrap">
                                            <div class="li-hero-metric"><?php echo number_format_i18n($g_data->total_leads); ?></div>
                                            <div class="li-hero-metric-lbl">Total de Leads Google</div>
                                        </div>
                                        <div class="li-hero-icon-box">
                                            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <circle cx="11" cy="11" r="8"></circle>
                                                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                                            </svg>
                                        </div>
                                    </div>
                                    <div class="li-hero-card-footer">
                                        <span class="li-hero-stat-highlight">Pesquisa • Display • PMax</span>
                                        <span class="li-hero-stat-sub">Origem rastreada via gclid/utm</span>
                                    </div>
                                </div>

                                <!-- CARD 3: STATUS OPERAÇÃO GOOGLE -->
                                <div class="li-hero-card li-hero-card-forest">
                                    <div class="li-hero-card-top">
                                        <span class="li-hero-tag">STATUS GOOGLE</span>
                                        <span class="li-hero-pill-badge li-badge-live">● EM QUALIFICAÇÃO</span>
                                    </div>
                                    <div class="li-hero-card-body">
                                        <div class="li-hero-metric-wrap">
                                            <div class="li-hero-metric"><?php echo number_format_i18n($g_data->pendentes); ?></div>
                                            <div class="li-hero-metric-lbl">Aguardando Retorno Comercial</div>
                                        </div>
                                        <div class="li-hero-icon-box">
                                            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
                                            </svg>
                                        </div>
                                    </div>
                                    <div class="li-hero-card-footer">
                                        <span class="li-hero-stat-highlight"><?php echo number_format_i18n($g_data->desqualificados); ?> não qualificados</span>
                                        <span class="li-hero-stat-sub">triados pela equipe Faveni</span>
                                    </div>
                                </div>
                            </div>

                            <!-- CARDS DE KPIS GOOGLE -->
                            <div class="li-metric-grid">
                                <div class="li-metric-card li-metric-total">
                                    <span class="li-metric-label">Leads Google Ads</span>
                                    <span class="li-metric-value"><?php echo number_format_i18n($g_data->total_leads); ?></span>
                                    <span class="li-metric-sub">Total no período</span>
                                </div>
                                <div class="li-metric-card li-card-success li-metric-qual">
                                    <span class="li-metric-label">Matrículas Confirmadas</span>
                                    <span class="li-metric-value"><?php echo number_format_i18n($g_data->qualificados); ?></span>
                                    <span class="li-metric-sub">Alunos convertidos</span>
                                </div>
                                <div class="li-metric-card li-card-warning li-metric-pend">
                                    <span class="li-metric-label">Pendentes Google</span>
                                    <span class="li-metric-value"><?php echo number_format_i18n($g_data->pendentes); ?></span>
                                    <span class="li-metric-sub">Em atendimento</span>
                                </div>
                                <div class="li-metric-card li-card-danger li-metric-desq">
                                    <span class="li-metric-label">Não Qualificados</span>
                                    <span class="li-metric-value"><?php echo number_format_i18n($g_data->desqualificados); ?></span>
                                    <span class="li-metric-sub">Descartes / Sem perfil</span>
                                </div>
                                <div class="li-metric-card li-card-info li-metric-rate">
                                    <span class="li-metric-label">Taxa Google Ads</span>
                                    <span class="li-metric-value"><?php echo $g_data->taxa; ?>%</span>
                                    <span class="li-metric-sub">Eficiência de conversão</span>
                                </div>
                            </div>

                            <!-- TABELA 1: CAMPANHAS GOOGLE ADS -->
                            <div class="li-card" style="padding: 0; overflow-x: auto;">
                                <div style="padding: 20px 24px 12px; border-bottom: 1px solid #e2e8f0;">
                                    <h3 class="li-card-title" style="display: flex; align-items: center; gap: 8px;">
                                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--li-brand); flex-shrink: 0;"><circle cx="12" cy="12" r="10"></circle><circle cx="12" cy="12" r="6"></circle><circle cx="12" cy="12" r="2"></circle></svg>
                                        Qualidade de Leads por Campanha (Google Ads)
                                    </h3>
                                    <p class="li-card-desc" style="margin-bottom: 0;">Campanhas do Google Ads classificadas por taxa de qualificação e matrículas geradas.</p>
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
                                        <?php if (empty($campaigns_google)): ?>
                                            <tr>
                                                <td colspan="6" style="text-align: center; padding: 35px; color: #64748b;">
                                                    Nenhuma campanha Google identificada no período selecionado.
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($campaigns_google as $camp): ?>
                                                <?php
                                                $camp_taxa = $camp->leads > 0 ? round(($camp->qualificados / $camp->leads) * 100, 1) : 0;
                                                $is_high_quality = ($camp_taxa >= 25 && $camp->leads >= 5);
                                                ?>
                                                <tr>
                                                    <td><strong><?php echo esc_html($camp->campanha); ?></strong></td>
                                                    <td style="text-align: center; font-weight: 600;"><?php echo number_format_i18n($camp->leads); ?></td>
                                                    <td style="text-align: center;"><span style="font-weight: 700; color: #15803d;"><?php echo number_format_i18n($camp->qualificados); ?></span></td>
                                                    <td style="text-align: center; color: #b91c1c;"><?php echo number_format_i18n($camp->desqualificados); ?></td>
                                                    <td>
                                                        <div style="display: flex; align-items: center; gap: 8px;">
                                                            <div style="flex: 1; height: 8px; background: #e2e8f0; border-radius: 4px; overflow: hidden;">
                                                                <div style="width: <?php echo min(100, $camp_taxa); ?>%; height: 100%; background: <?php echo $camp_taxa >= 20 ? '#10b981' : ($camp_taxa >= 10 ? '#f59e0b' : '#ef4444'); ?>; border-radius: 4px;"></div>
                                                            </div>
                                                            <span style="font-size: 13px; font-weight: 700; min-width: 45px; text-align: right;"><?php echo $camp_taxa; ?>%</span>
                                                        </div>
                                                    </td>
                                                    <td style="text-align: center;">
                                                        <?php if ($is_high_quality): ?>
                                                            <span class="li-badge li-status-qualificado">⭐ Alta Qualidade</span>
                                                        <?php elseif ($camp_taxa < 10 && $camp->leads >= 10): ?>
                                                            <span class="li-badge li-status-desqualificado">⚠️ Baixa Qualidade</span>
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

                            <!-- TABELA 2: PALAVRAS-CHAVE / ANÚNCIOS GOOGLE ADS -->
                            <div class="li-card" style="padding: 0; overflow-x: auto;">
                                <div style="padding: 20px 24px 12px; border-bottom: 1px solid #e2e8f0;">
                                    <h3 class="li-card-title" style="display: flex; align-items: center; gap: 8px;">
                                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--li-brand); flex-shrink: 0;"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                                        Qualidade por Palavra-Chave / Termo (Google Ads)
                                    </h3>
                                    <p class="li-card-desc" style="margin-bottom: 0;">Termos de pesquisa e anúncios que mais convertem leads em alunos matriculados.</p>
                                </div>

                                <table class="wp-list-table widefat fixed striped li-table">
                                    <thead>
                                        <tr>
                                            <th>Palavra-Chave / Termo (utm_term / utm_content)</th>
                                            <th>Campanha</th>
                                            <th style="width: 110px; text-align: center;">Leads</th>
                                            <th style="width: 120px; text-align: center;">Qualificados</th>
                                            <th style="width: 150px; text-align: center;">Taxa de Qualificação</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($ads_google)): ?>
                                            <tr>
                                                <td colspan="5" style="text-align: center; padding: 35px; color: #64748b;">
                                                    Nenhuma palavra-chave/termo Google identificado no período.
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($ads_google as $ad): ?>
                                                <?php $ad_taxa = $ad->leads > 0 ? round(($ad->qualificados / $ad->leads) * 100, 1) : 0; ?>
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

                            <?php if (!empty($daily_evolution_google)): ?>
                                <!-- GRÁFICO DIÁRIO GOOGLE ADS -->
                                <div class="li-card">
                                    <h3 class="li-card-title" style="display: flex; align-items: center; gap: 8px;">
                                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--li-brand); flex-shrink: 0;"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"></polyline><polyline points="16 7 22 7 22 13"></polyline></svg>
                                        Evolução Diária - Google Ads (Captação vs. Matrículas)
                                    </h3>
                                    <p class="li-card-desc">Volume diário exclusivo do Google Ads.</p>

                                    <div style="display: flex; align-items: flex-end; gap: 8px; height: 180px; padding-top: 20px; overflow-x: auto;">
                                        <?php foreach ($daily_evolution_google as $day): ?>
                                            <?php
                                            $height_total = round(($day->total / $max_daily_google) * 140);
                                            $height_qual  = $day->total > 0 ? round(($day->qualificados / $day->total) * $height_total) : 0;
                                            $day_label    = date_i18n('d/m', strtotime($day->dia));
                                            ?>
                                            <div style="display: flex; flex-direction: column; align-items: center; flex: 1; min-width: 32px;" title="<?php echo esc_attr("{$day->dia}\nTotal Google: {$day->total}\nQualificados: {$day->qualificados}"); ?>">
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
                                            Total Google Ads
                                        </span>
                                        <span style="display: flex; align-items: center; gap: 6px;">
                                            <span style="width: 12px; height: 12px; background: #10b981; border-radius: 3px; display: inline-block;"></span>
                                            Matrículas Confirmadas
                                        </span>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

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

            // Alternância instantânea de abas com sincronização de URL e estado de filtro
            function liSwitchTab(tabName) {
                if (!tabName) tabName = 'dashboard';

                // 1. Alterna classe is-active nos itens do menu lateral
                document.querySelectorAll('.li-sidebar-nav .li-nav-item[data-tab]').forEach(function(item) {
                    if (item.getAttribute('data-tab') === tabName) {
                        item.classList.add('is-active');
                    } else {
                        item.classList.remove('is-active');
                    }
                });

                // 2. Alterna visibilidade dos painéis
                document.querySelectorAll('.li-tab-panel').forEach(function(panel) {
                    if (panel.id === 'li-panel-' + tabName) {
                        panel.classList.add('is-active');
                    } else {
                        panel.classList.remove('is-active');
                    }
                });

                // 2.1. Alterna cabeçalho dinâmico no topo
                document.querySelectorAll('.li-top-tab-header').forEach(function(header) {
                    if (header.id === 'liTopHeader-' + tabName) {
                        header.style.display = 'block';
                    } else {
                        header.style.display = 'none';
                    }
                });

                // 3. Atualiza o input hidden no formulário de filtros
                var activeInput = document.getElementById('liActiveTabInput');
                if (activeInput) {
                    activeInput.value = tabName;
                }

                // 4. Atualiza hash na URL sem scroll brusco
                if (window.history && window.history.replaceState) {
                    window.history.replaceState(null, null, '#' + tabName);
                }

                // 5. Scroll suave para o topo do conteúdo
                var scrollContainer = document.querySelector('.li-content-scroll');
                if (scrollContainer) {
                    scrollContainer.scrollTop = 0;
                }

                // 6. Fecha sidebar em dispositivos móveis
                var sidebar = document.getElementById('liSidebar');
                if (sidebar && window.innerWidth <= 980) {
                    sidebar.classList.remove('is-open');
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

            // Remove qualquer resquício de chave de tema escuro salva anteriormente
            try {
                localStorage.removeItem('li_dashboard_theme');
            } catch (e) {}

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
                var sidebar = document.getElementById('liSidebar');
                if (sidebar) {
                    sidebar.classList.toggle('is-collapsed', isCollapsed);
                }
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
                    var sidebar = document.getElementById('liSidebar');
                    if (sidebar) {
                        sidebar.classList.add('is-collapsed');
                    }
                }
                liUpdateCollapseUI(isCollapsed);
            }

            function liInitActiveTab() {
                var hashTab = window.location.hash ? window.location.hash.replace('#', '') : '';
                var initialTab = '<?php echo esc_js($active_tab); ?>';
                if (hashTab && ['dashboard', 'leads-meta', 'leads-google'].indexOf(hashTab) !== -1) {
                    initialTab = hashTab;
                }
                liSwitchTab(initialTab);
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function() {
                    liInitSidebarCollapse();
                    liInitActiveTab();
                    recalcularCPL();
                });
            } else {
                liInitSidebarCollapse();
                liInitActiveTab();
                recalcularCPL();
            }
            </script>
        </div>
        <?php
    }
}
