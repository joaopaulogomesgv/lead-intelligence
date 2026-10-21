<?php
namespace LeadIntelligence\Admin\Controllers;

use LeadIntelligence\Database\DbSchema;
use LeadIntelligence\Database\LeadRepository;
use LeadIntelligence\PhoneNormalizer;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Controller Administrativo dedicado ao Google Ads (Design Padrão WordPress)
 */
class GoogleAdsController {

    public static function render() {
        global $wpdb;
        $table = DbSchema::get_leads_table();

        // 1. Tratamento de Período (Padrão: Hoje)
        $custom_from = isset($_GET['from']) ? sanitize_text_field(wp_unslash($_GET['from'])) : '';
        $custom_to   = isset($_GET['to']) ? sanitize_text_field(wp_unslash($_GET['to'])) : '';
        $periodo     = isset($_GET['periodo']) ? sanitize_text_field(wp_unslash($_GET['periodo'])) : 'today';

        if ($periodo !== 'custom') {
            $custom_from = '';
            $custom_to   = '';
        } elseif (empty($custom_from) && empty($custom_to)) {
            $periodo = 'today';
        }

        $search_term = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $filter_status = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';

        // Cálculo de datas
        $now = current_time('mysql');
        $date_start = '';
        $date_end   = $now;

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
            case 'custom':
                if (!empty($custom_from) && !empty($custom_to)) {
                    $date_start = $custom_from . ' 00:00:00';
                    $date_end   = $custom_to . ' 23:59:59';
                } elseif (!empty($custom_from)) {
                    $date_start = $custom_from . ' 00:00:00';
                    $date_end   = $custom_from . ' 23:59:59';
                } elseif (!empty($custom_to)) {
                    $date_end   = $custom_to . ' 23:59:59';
                }
                break;
            case 'all':
            default:
                $date_start = '';
                $date_end   = '';
                break;
        }

        // Construção da Query Segura
        $google_condition = LeadRepository::get_channel_sql_condition('google_ads');
        $where_conditions = ["1=1", $google_condition];
        $params = [];

        if (!empty($date_start)) {
            $where_conditions[] = "data_cadastro >= %s";
            $params[] = $date_start;
        }
        if (!empty($date_end)) {
            $where_conditions[] = "data_cadastro <= %s";
            $params[] = $date_end;
        }

        // Métricas Globais do Canal no Período
        $where_metrics_sql = implode(' AND ', $where_conditions);
        $metrics_query = "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN (qualificacao_status = 'qualificado' OR etapa_funil LIKE '%venda%' OR etapa_funil LIKE '%matr%') THEN 1 ELSE 0 END) as qualificados,
            SUM(CASE WHEN qualificacao_status = 'nao_qualificado' THEN 1 ELSE 0 END) as desqualificados,
            SUM(CASE WHEN qualificacao_status = 'pendente' THEN 1 ELSE 0 END) as pendentes
            FROM {$table} WHERE {$where_metrics_sql}";

        $metrics = !empty($params) ? $wpdb->get_row($wpdb->prepare($metrics_query, $params)) : $wpdb->get_row($metrics_query);

        $total_leads = $metrics ? (int) $metrics->total : 0;
        $vendas      = $metrics ? (int) $metrics->qualificados : 0;
        $desq        = $metrics ? (int) $metrics->desqualificados : 0;
        $pendentes   = max(0, $total_leads - $vendas - $desq);
        $taxa        = $total_leads > 0 ? round(($vendas / $total_leads) * 100, 1) : 0;

        // Filtros Adicionais para Listagem de Leads (Busca e Status)
        $list_where = $where_conditions;
        $list_params = $params;

        if (!empty($search_term)) {
            $like = '%' . $wpdb->esc_like(trim($search_term)) . '%';
            $list_where[] = "(nome LIKE %s OR email LIKE %s OR telefone LIKE %s OR telefone_normalizado LIKE %s OR utm_source LIKE %s OR utm_campaign LIKE %s OR utm_term LIKE %s)";
            $list_params = array_merge($list_params, [$like, $like, $like, $like, $like, $like, $like]);
        }

        if (!empty($filter_status)) {
            if ($filter_status === 'venda') {
                $like_v = '%' . $wpdb->esc_like('venda') . '%';
                $like_m = '%' . $wpdb->esc_like('matr') . '%';
                $list_where[] = "(qualificacao_status = 'qualificado' OR etapa_funil LIKE %s OR etapa_funil LIKE %s)";
                $list_params = array_merge($list_params, [$like_v, $like_m]);
            } elseif ($filter_status === 'desqualificado') {
                $like_d = '%' . $wpdb->esc_like('desq') . '%';
                $list_where[] = "(qualificacao_status = 'nao_qualificado' OR etapa_funil LIKE %s)";
                $list_params[] = $like_d;
            } elseif ($filter_status === 'pendente') {
                $like_v = '%' . $wpdb->esc_like('venda') . '%';
                $like_m = '%' . $wpdb->esc_like('matr') . '%';
                $list_where[] = "(qualificacao_status = 'pendente' AND (etapa_funil IS NULL OR (etapa_funil NOT LIKE %s AND etapa_funil NOT LIKE %s)))";
                $list_params = array_merge($list_params, [$like_v, $like_m]);
            }
        }

        $list_sql = implode(' AND ', $list_where);
        $query_leads = "SELECT * FROM {$table} WHERE {$list_sql} ORDER BY id DESC LIMIT 150";
        $leads = !empty($list_params) ? $wpdb->get_results($wpdb->prepare($query_leads, $list_params)) : $wpdb->get_results($query_leads);
        $leads = is_array($leads) ? $leads : [];

        $sync_feedback = null;
        if (isset($_POST['li_sync_gads_channel']) && check_admin_referer('li_gads_channel_sync', 'li_nonce')) {
            $sync_feedback = \LeadIntelligence\GoogleAds\GoogleAdsClient::sync_campaign_costs();
        }

        if (isset($_POST['li_run_google_sync_now']) && check_admin_referer('li_gads_sync_now_verify', 'li_nonce')) {
            $res = \LeadIntelligence\Qualification\GoogleSheetsSync::run_sync('google_ads', true);
            if (is_wp_error($res)) {
                $sync_feedback = ['success' => false, 'message' => $res->get_error_message()];
            } else {
                $sync_feedback = ['success' => true, 'message' => $res['message']];
            }
        }

        if (isset($_POST['li_unify_all_leads']) && check_admin_referer('li_gads_unify_verify', 'li_nonce')) {
            $merged = \LeadIntelligence\Database\DbSchema::unify_and_enrich_leads_by_phone();
            $sync_feedback = ['success' => true, 'message' => "Unificação concluída! {$merged} registros foram atualizados e enriquecidos com status da planilha e formulários."];
        }

        $settings = \LeadIntelligence\Admin\Settings::get_settings();
        $is_gads_connected = !empty($settings['google_ads_connected']) && !empty($settings['google_ads_refresh_token']);
        $is_gsheets_connected = \LeadIntelligence\Qualification\GoogleSheetsClient::is_connected();
        $gsheet_settings = \LeadIntelligence\Qualification\GoogleSheetsSync::get_source_settings('google_ads');
        $has_gsheet_configured = $is_gsheets_connected && !empty($gsheet_settings['spreadsheet_id']);

        // Investimento e Custos do Período
        $investimento = LeadRepository::get_total_cost('google_ads', $date_start, $date_end);
        $cpl          = ($total_leads > 0 && $investimento > 0) ? round($investimento / $total_leads, 2) : 0.00;
        $cpv          = ($vendas > 0 && $investimento > 0) ? round($investimento / $vendas, 2) : 0.00;
        $campanhas_custos = LeadRepository::get_campaign_costs_summary('google_ads', $date_start, $date_end);

        $input_from = !empty($custom_from) ? $custom_from : (!empty($date_start) ? substr($date_start, 0, 10) : '');
        $input_to   = !empty($custom_to) ? $custom_to : (!empty($date_end) ? substr($date_end, 0, 10) : '');
        ?>
        <div class="wrap li-wrap">
            <!-- CABEÇALHO PADRÃO WORDPRESS -->
            <div class="li-header" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 15px; margin-bottom: 20px;">
                <div>
                    <h2 style="display: flex; align-items: center; gap: 10px;">
                        <svg viewBox="0 0 24 24" width="28" height="28" fill="none">
                            <path fill="#4285F4" d="M23.745 12.27c0-.7-.06-1.4-.19-2.07H12v4.51h6.6c-.29 1.52-1.14 2.82-2.4 3.68v3.05h3.88c2.27-2.09 3.665-5.17 3.665-9.17Z"/>
                            <path fill="#34A853" d="M12 24c3.24 0 5.95-1.08 7.93-2.91l-3.88-3.05c-1.08.72-2.45 1.16-4.05 1.16-3.12 0-5.77-2.1-6.72-4.93H1.25v3.15C3.26 21.36 7.33 24 12 24Z"/>
                            <path fill="#FBBC05" d="M5.28 14.27c-.25-.72-.38-1.49-.38-2.27s.13-1.55.38-2.27V6.58H1.25C.45 8.18 0 10.03 0 12s.45 3.82 1.25 5.42l4.03-3.15Z"/>
                            <path fill="#EA4335" d="M12 4.75c1.77 0 3.35.61 4.6 1.8l3.42-3.42C17.95 1.19 15.24 0 12 0 7.33 0 3.26 2.64 1.25 6.58l4.03 3.15c.95-2.83 3.6-4.98 6.72-4.98Z"/>
                        </svg>
                        Lead Intelligence &bull; Google Ads
                    </h2>
                    <p class="li-subtitle">Performance, investimento e qualidade dos contatos gerados via Rede de Pesquisa, Display e campanhas Google.</p>
                </div>

                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <?php if ($has_gsheet_configured): ?>
                        <form method="post" action="" style="display: inline;">
                            <?php wp_nonce_field('li_gads_sync_now_verify', 'li_nonce'); ?>
                            <button type="submit" name="li_run_google_sync_now" value="1" class="button button-primary" style="display: inline-flex; align-items: center; gap: 6px; background: #059669; border-color: #047857;" title="Puxa os status atualizados diretamente da sua planilha Google Sheets">
                                <span class="dashicons dashicons-update"></span> Sincronizar Google Sheets
                            </button>
                        </form>
                    <?php else: ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-import&tab=google_sheets')); ?>" class="button button-primary" style="display: inline-flex; align-items: center; gap: 6px; background: #059669; border-color: #047857;" title="Conecte ou informe a URL da sua planilha do Google Sheets">
                            <span class="dashicons dashicons-media-spreadsheet"></span> Conectar Planilha Google
                        </a>
                    <?php endif; ?>

                    <form method="post" action="" style="display: inline;">
                        <?php wp_nonce_field('li_gads_unify_verify', 'li_nonce'); ?>
                        <button type="submit" name="li_unify_all_leads" value="1" class="button button-secondary" style="display: inline-flex; align-items: center; gap: 6px;" title="Unifica dados cruzados por telefone e e-mail e recalibra status">
                            <span class="dashicons dashicons-randomize"></span> Unificar com Planilha
                        </button>
                    </form>

                    <?php if ($is_gads_connected): ?>
                        <form method="post" action="" style="display: inline;">
                            <?php wp_nonce_field('li_gads_channel_sync', 'li_nonce'); ?>
                            <button type="submit" name="li_sync_gads_channel" value="1" class="button button-secondary" style="display: inline-flex; align-items: center; gap: 6px;">
                                <span class="dashicons dashicons-chart-line"></span> Gastos API
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($sync_feedback): ?>
                <div class="notice <?php echo $sync_feedback['success'] ? 'notice-success' : 'notice-error'; ?> is-dismissible" style="margin-bottom: 20px;">
                    <p><strong><?php echo $sync_feedback['success'] ? '✔' : '❌'; ?></strong> <?php echo esc_html($sync_feedback['message']); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($pendentes > 0 && !$has_gsheet_configured): ?>
                <div class="notice notice-info" style="margin-bottom: 20px; border-left-color: #0284c7; padding: 12px 18px;">
                    <p style="margin: 0; font-size: 13px; color: #0369a1; line-height: 1.5;">
                        ℹ️ <strong>Identificação de Qualificação de Leads:</strong> Os leads são capturados em tempo real pelos formulários do Elementor como <code>Formulário Enviado (Pendente)</code>. Para trazer os status comerciais exatos da sua planilha (ex: <em>Perda</em>, <em>Desqualificado</em>, <em>Tentativa de Contato</em>, <em>Venda</em>), conecte o link da sua planilha em <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-import&tab=google_sheets')); ?>" style="font-weight: 700; color: #0284c7;">Importar Planilha &rarr; Google Sheets</a> ou faça o cruzamento em <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-import&tab=elementor_cross')); ?>" style="font-weight: 700; color: #0284c7;">Cruzamento Elementor vs Qualificados</a>.
                    </p>
                </div>
            <?php endif; ?>

            <!-- CARDS DE MÉTRICAS (GRID PADRÃO DO PLUGIN) -->
            <div class="li-metric-grid" style="margin-bottom: 20px;">
                <div class="li-metric-card" style="border-left: 4px solid #4285F4;">
                    <span class="li-metric-label">Leads Google Ads</span>
                    <span class="li-metric-value" style="color: #4285F4;"><?php echo number_format_i18n($total_leads); ?></span>
                    <span class="li-metric-sub">Captação no período</span>
                </div>
                <div class="li-metric-card li-card-success" style="border-left: 4px solid #10b981;">
                    <span class="li-metric-label">Vendas / Matrículas</span>
                    <span class="li-metric-value" style="color: #10b981;"><?php echo number_format_i18n($vendas); ?></span>
                    <span class="li-metric-sub">Alunos confirmados</span>
                </div>
                <div class="li-metric-card" style="border-left: 4px solid #14b8a6;">
                    <span class="li-metric-label">Taxa de Conversão</span>
                    <span class="li-metric-value" style="color: #0d9488;"><?php echo esc_html($taxa); ?>%</span>
                    <span class="li-metric-sub">Eficiência de fechamento</span>
                </div>
                <div class="li-metric-card" style="border-left: 4px solid #0284c7;">
                    <span class="li-metric-label">Investimento Total</span>
                    <span class="li-metric-value" style="color: #0369a1;">R$ <?php echo number_format($investimento, 2, ',', '.'); ?></span>
                    <span class="li-metric-sub"><?php echo $is_gads_connected ? 'Via Google Ads API' : 'Configure a API de Gastos'; ?></span>
                </div>
                <div class="li-metric-card" style="border-left: 4px solid #8b5cf6;">
                    <span class="li-metric-label">CPL Real</span>
                    <span class="li-metric-value" style="color: #7c3aed;">
                        <?php echo $cpl > 0 ? 'R$ ' . number_format($cpl, 2, ',', '.') : '<span style="color:#94a3b8; font-size:16px;">--</span>'; ?>
                    </span>
                    <span class="li-metric-sub">Custo Médio por Lead</span>
                </div>
                <div class="li-metric-card" style="border-left: 4px solid #059669;">
                    <span class="li-metric-label">Custo por Matrícula</span>
                    <span class="li-metric-value" style="color: #047857;">
                        <?php echo $cpv > 0 ? 'R$ ' . number_format($cpv, 2, ',', '.') : '<span style="color:#94a3b8; font-size:16px;">--</span>'; ?>
                    </span>
                    <span class="li-metric-sub">Custo de Aquisição (CAC)</span>
                </div>
            </div>

            <!-- CARD DE FILTRO LIMPO (ESTILO WORDPRESS) -->
            <div class="li-card" style="margin-bottom: 20px; padding: 16px 20px;">
                <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-end;">
                    <input type="hidden" name="page" value="lead-intelligence-google">

                    <!-- Seletor de Período -->
                    <div>
                        <label style="display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 6px;">Período</label>
                        <div style="display: inline-flex; background: #f1f5f9; padding: 3px; border-radius: 6px; border: 1px solid #cbd5e1;">
                            <?php 
                            $periods = [
                                'today'     => 'Hoje',
                                'yesterday' => 'Ontem',
                                '7d'        => '7 Dias',
                                '30d'       => '30 Dias',
                                'month'     => 'Este Mês',
                                'all'       => 'Todo',
                                'custom'    => 'Personalizado'
                            ];
                            foreach ($periods as $k => $label): ?>
                                <a href="<?php echo esc_url(add_query_arg(['periodo' => $k], remove_query_arg(['from', 'to']))); ?>" 
                                   class="button-link" 
                                   style="padding: 4px 10px; font-size: 12px; font-weight: 600; text-decoration: none; border-radius: 4px; <?php echo $periodo === $k ? 'background: #0073aa; color: #fff;' : 'color: #334155;'; ?>">
                                    <?php echo esc_html($label); ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Datas Customizadas -->
                    <?php if ($periodo === 'custom'): ?>
                        <div style="display: flex; gap: 8px; align-items: center;">
                            <div>
                                <label style="display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 6px;">De</label>
                                <input type="date" name="from" value="<?php echo esc_attr($input_from); ?>" style="height: 32px; font-size: 13px;">
                            </div>
                            <div>
                                <label style="display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 6px;">Até</label>
                                <input type="date" name="to" value="<?php echo esc_attr($input_to); ?>" style="height: 32px; font-size: 13px;">
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Campo de Busca -->
                    <div style="flex: 1 1 200px;">
                        <label style="display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 6px;">Pesquisar</label>
                        <input type="text" name="s" value="<?php echo esc_attr($search_term); ?>" placeholder="Filtrar por nome, telefone ou UTM..." style="width: 100%; height: 32px; font-size: 13px;">
                    </div>

                    <!-- Botões -->
                    <div style="display: flex; gap: 8px;">
                        <button type="submit" class="button button-primary" style="height: 32px;">
                            🔍 Filtrar
                        </button>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-google')); ?>" class="button button-secondary" style="height: 32px; line-height: 30px;">
                            Limpar
                        </a>
                    </div>
                </form>
            </div>

            <?php if (!empty($campanhas_custos)): ?>
                <!-- TABELA DE GASTOS POR CAMPANHA GOOGLE ADS API -->
                <div class="li-card" style="padding: 0; overflow-x: auto; margin-bottom: 20px;">
                    <div style="padding: 14px 20px; border-bottom: 1px solid #e2e8f0; background: #f8fafc; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                        <div>
                            <h3 style="margin: 0; font-size: 15px; font-weight: 700; color: #1e293b;">
                                📊 Investimento e Performance por Campanha (Google Ads API)
                            </h3>
                            <p style="margin: 2px 0 0; font-size: 12px; color: #64748b;">
                                Dados oficiais de gastos, cliques e impressões sincronizados diretamente da sua conta Google Ads.
                            </p>
                        </div>
                        <span class="li-badge" style="background: #e0f2fe; color: #0369a1; font-weight: 700;">
                            <?php echo count($campanhas_custos); ?> campanhas ativas
                        </span>
                    </div>

                    <table class="wp-list-table widefat fixed striped li-table">
                        <thead>
                            <tr>
                                <th>Campanha Google Ads</th>
                                <th style="width: 140px; text-align: right;">Investimento (R$)</th>
                                <th style="width: 110px; text-align: center;">Cliques</th>
                                <th style="width: 110px; text-align: center;">Impressões</th>
                                <th style="width: 100px; text-align: center;">CTR</th>
                                <th style="width: 110px; text-align: center;">CPC Médio</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($campanhas_custos as $c_cost): 
                                $c_ctr = $c_cost->total_impressions > 0 ? round(($c_cost->total_clicks / $c_cost->total_impressions) * 100, 2) : 0;
                                $c_cpc = $c_cost->total_clicks > 0 ? round($c_cost->total_cost / $c_cost->total_clicks, 2) : 0;
                            ?>
                                <tr>
                                    <td>
                                        <strong><?php echo esc_html($c_cost->campaign_name ?: 'ID: ' . $c_cost->campaign_id); ?></strong>
                                    </td>
                                    <td style="text-align: right; font-weight: 700; color: #0369a1;">
                                        R$ <?php echo number_format($c_cost->total_cost, 2, ',', '.'); ?>
                                    </td>
                                    <td style="text-align: center;">
                                        <?php echo number_format_i18n($c_cost->total_clicks); ?>
                                    </td>
                                    <td style="text-align: center;">
                                        <?php echo number_format_i18n($c_cost->total_impressions); ?>
                                    </td>
                                    <td style="text-align: center;">
                                        <?php echo esc_html($c_ctr); ?>%
                                    </td>
                                    <td style="text-align: center;">
                                        R$ <?php echo number_format($c_cpc, 2, ',', '.'); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <!-- TABELA DE LEADS GOOGLE ADS (ESTILO NATIVO WORDPRESS) -->
            <div class="li-card" style="padding: 0; overflow-x: auto;">
                <div style="padding: 16px 20px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <div>
                        <h3 class="li-card-title" style="margin: 0; font-size: 16px;">Leads Capturados via Google Ads</h3>
                        <p class="li-card-desc" style="margin: 2px 0 0; font-size: 12px;">Contatos identificados com origem de Rede de Pesquisa, Display ou campanhas Google.</p>
                    </div>
                    <span class="li-badge" style="background: rgba(66, 133, 244, 0.15); color: #1a73e8; font-weight: 700;">
                        <?php echo count($leads); ?> leads exibidos
                    </span>
                </div>

                <table class="wp-list-table widefat fixed striped li-table">
                    <thead>
                        <tr>
                            <th style="width: 60px;">ID</th>
                            <th style="width: 120px;">Data</th>
                            <th>Nome / Lead</th>
                            <th style="width: 160px;">Telefone / WhatsApp</th>
                            <th>Curso / Interesse</th>
                            <th style="width: 190px;">UTM</th>
                            <th style="width: 150px; text-align: center;">Etapa do Funil</th>
                            <th style="width: 120px; text-align: center;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($leads)): ?>
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 35px; color: #64748b;">
                                    Nenhum lead Google Ads encontrado no período selecionado.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($leads as $lead): 
                                $lead_phone_clean = preg_replace('/\D/', '', $lead->telefone_normalizado ?: ($lead->telefone ?? ''));
                                $etapa_raw = !empty($lead->etapa_funil) ? $lead->etapa_funil : ($lead->qualificacao_status === 'qualificado' ? 'Matrícula Realizada' : ($lead->qualificacao_status === 'nao_qualificado' ? 'Desqualificado' : 'Formulário Enviado'));
                                
                                // Extrai apenas a utm_source de forma limpa
                                $source_display = $lead->utm_source;
                                if (empty($source_display) && !empty($lead->pagina_origem) && strpos($lead->pagina_origem, 'utm_source=') !== false) {
                                    $parsed_q = parse_url($lead->pagina_origem, PHP_URL_QUERY);
                                    if (!empty($parsed_q)) {
                                        parse_str($parsed_q, $q_params);
                                        if (!empty($q_params['utm_source'])) {
                                            $source_display = sanitize_text_field($q_params['utm_source']);
                                        }
                                    }
                                }
                            ?>
                                <tr>
                                    <td><strong>#<?php echo esc_html($lead->id); ?></strong></td>
                                    <td>
                                        <div style="font-size: 12px; font-weight: 600;"><?php echo esc_html(date_i18n('d/m/Y', strtotime($lead->data_cadastro))); ?></div>
                                        <div style="font-size: 11px; color: #64748b;"><?php echo esc_html(date_i18n('H:i', strtotime($lead->data_cadastro))); ?></div>
                                    </td>
                                    <td>
                                        <strong><?php echo !empty($lead->nome) ? esc_html($lead->nome) : '<span style="color:#94a3b8">Sem nome</span>'; ?></strong>
                                        <?php if (!empty($lead->email)): ?>
                                            <div style="font-size: 11px; color: #64748b;"><?php echo esc_html($lead->email); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($lead_phone_clean)): ?>
                                            <a href="https://wa.me/<?php echo esc_attr($lead_phone_clean); ?>" target="_blank" style="color: #059669; font-weight: 600; text-decoration: none;">
                                                💬 <?php echo esc_html(PhoneNormalizer::format_display($lead->telefone ?: $lead->telefone_normalizado)); ?>
                                            </a>
                                        <?php else: ?>
                                            <span style="color: #94a3b8;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span style="font-size: 12px; color: #334155;"><?php echo esc_html($lead->tipo_curso ?: ($lead->area_interesse ?: '-')); ?></span>
                                    </td>
                                    <td>
                                        <?php if (!empty($source_display)): ?>
                                            <code style="background: #e0f2fe; color: #0369a1; padding: 3px 7px; border-radius: 4px; font-size: 11px; font-weight: 600; display: inline-block;">
                                                <?php echo esc_html($source_display); ?>
                                            </code>
                                        <?php else: ?>
                                            <span style="color: #94a3b8; font-size: 11px; font-style: italic;">Sem UTM</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: center;">
                                        <span class="li-badge <?php echo $lead->qualificacao_status === 'qualificado' ? 'li-status-qualificado' : ($lead->qualificacao_status === 'nao_qualificado' ? 'li-status-desqualificado' : 'li-status-cinza'); ?>" style="font-size: 11px;">
                                            <?php echo esc_html($etapa_raw); ?>
                                        </span>
                                    </td>
                                    <td style="text-align: center;">
                                        <span class="li-badge <?php echo $lead->qualificacao_status === 'qualificado' ? 'li-status-qualificado' : ($lead->qualificacao_status === 'nao_qualificado' ? 'li-status-desqualificado' : 'li-status-cinza'); ?>" style="font-weight: 600;">
                                            <?php 
                                            if ($lead->qualificacao_status === 'qualificado') {
                                                echo 'Venda';
                                            } elseif ($lead->qualificacao_status === 'nao_qualificado') {
                                                echo 'Não Qualificado';
                                            } else {
                                                echo 'Pendente';
                                            }
                                            ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }
}
