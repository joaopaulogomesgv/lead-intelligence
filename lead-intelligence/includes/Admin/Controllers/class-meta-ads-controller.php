<?php
namespace LeadIntelligence\Admin\Controllers;

use LeadIntelligence\Database\DbSchema;
use LeadIntelligence\Database\LeadRepository;
use LeadIntelligence\PhoneNormalizer;
use LeadIntelligence\Admin\Controllers\MetaController;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Controller Administrativo dedicado ao Meta Ads (Design Padrão WordPress)
 */
class MetaAdsController {

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

        $search_term   = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
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
        $meta_condition   = LeadRepository::get_channel_sql_condition('meta_ads');
        $where_conditions = ["1=1", $meta_condition];
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
            $list_where[] = "(nome LIKE %s OR email LIKE %s OR telefone LIKE %s OR telefone_normalizado LIKE %s OR utm_source LIKE %s OR utm_campaign LIKE %s OR ad_name LIKE %s OR campaign_name LIKE %s)";
            $list_params = array_merge($list_params, [$like, $like, $like, $like, $like, $like, $like, $like]);
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

        $input_from = !empty($custom_from) ? $custom_from : (!empty($date_start) ? substr($date_start, 0, 10) : '');
        $input_to   = !empty($custom_to) ? $custom_to : (!empty($date_end) ? substr($date_end, 0, 10) : '');
        ?>
        <div class="wrap li-wrap">
            <!-- CABEÇALHO PADRÃO WORDPRESS -->
            <div class="li-header" style="margin-bottom: 20px;">
                <h2 style="display: flex; align-items: center; gap: 10px;">
                    <svg viewBox="0 0 24 24" width="28" height="28" fill="none">
                        <path fill="#0081FB" d="M6.915 4.03c-1.968 0-3.683 1.28-4.871 3.113C.704 9.208 0 11.883 0 14.449c0 .706.07 1.369.21 1.973a6.624 6.624 0 0 0 .265.86 5.297 5.297 0 0 0 .371.761c.696 1.159 1.818 1.927 3.593 1.927 1.497 0 2.633-.671 3.965-2.444.76-1.012 1.144-1.626 2.663-4.32l.756-1.339.186-.325c.061.1.121.196.183.3l2.152 3.595c.724 1.21 1.665 2.556 2.47 3.314 1.046.987 1.992 1.22 3.06 1.22 1.075 0 1.876-.355 2.455-.843a3.743 3.743 0 0 0 .81-.973c.542-.939.861-2.127.861-3.745 0-2.72-.681-5.357-2.084-7.45-1.282-1.912-2.957-2.93-4.716-2.93-1.047 0-2.088.467-3.053 1.308-.652.57-1.257 1.29-1.82 2.05-.69-.875-1.335-1.547-1.958-2.056-1.182-.966-2.315-1.303-3.454-1.303zm10.16 2.053c1.147 0 2.188.758 2.992 1.999 1.132 1.748 1.647 4.195 1.647 6.4 0 1.548-.368 2.9-1.839 2.9-.58 0-1.027-.23-1.664-1.004-.496-.601-1.343-1.878-2.832-4.358l-.617-1.028a44.908 44.908 0 0 0-1.255-1.98c.07-.109.141-.224.211-.327 1.12-1.667 2.118-2.602 3.358-2.602zm-10.201.553c1.265 0 2.058.791 2.675 1.446.307.327.737.871 1.234 1.579l-1.02 1.566c-.757 1.163-1.882 3.017-2.837 4.338-1.191 1.649-1.81 1.817-2.486 1.817-.524 0-1.038-.237-1.383-.794-.263-.426-.464-1.13-.464-2.046 0-2.221.63-4.535 1.66-6.088.454-.687.964-1.226 1.533-1.533a2.264 2.264 0 0 1 1.088-.285z"/>
                    </svg>
                    Lead Intelligence &bull; Meta Ads
                </h2>
                <p class="li-subtitle">Performance de campanhas, criativos e contatos exclusivos da Meta Ads (Facebook e Instagram).</p>
            </div>

            <!-- CARDS DE MÉTRICAS (GRID PADRÃO DO PLUGIN / MODELO WHATSAPP) -->
            <div class="li-metric-grid" style="margin-bottom: 20px;">
                <div class="li-metric-card" style="border-left: 4px solid #0081fb;">
                    <span class="li-metric-label">Leads Meta Ads</span>
                    <span class="li-metric-value" style="color: #0081fb;"><?php echo number_format_i18n($total_leads); ?></span>
                    <span class="li-metric-sub">Facebook &amp; Instagram</span>
                </div>
                <div class="li-metric-card li-card-success" style="border-left: 4px solid #10b981;">
                    <span class="li-metric-label">Vendas / Matrículas</span>
                    <span class="li-metric-value" style="color: #10b981;"><?php echo number_format_i18n($vendas); ?></span>
                    <span class="li-metric-sub">Alunos confirmados</span>
                </div>
                <div class="li-metric-card" style="border-left: 4px solid #f59e0b;">
                    <span class="li-metric-label">Pendentes Comercial</span>
                    <span class="li-metric-value" style="color: #f59e0b;"><?php echo number_format_i18n($pendentes); ?></span>
                    <span class="li-metric-sub">Aguardando atendimento</span>
                </div>
                <div class="li-metric-card" style="border-left: 4px solid #ef4444;">
                    <span class="li-metric-label">Não Qualificados</span>
                    <span class="li-metric-value" style="color: #ef4444;"><?php echo number_format_i18n($desq); ?></span>
                    <span class="li-metric-sub">Descartes / Sem perfil</span>
                </div>
                <div class="li-metric-card" style="border-left: 4px solid #14b8a6;">
                    <span class="li-metric-label">Taxa de Conversão</span>
                    <span class="li-metric-value" style="color: #0d9488;"><?php echo esc_html($taxa); ?>%</span>
                    <span class="li-metric-sub">Eficiência de fechamento</span>
                </div>
            </div>

            <!-- CARD DE FILTRO LIMPO (ESTILO WORDPRESS) -->
            <div class="li-card" style="margin-bottom: 20px; padding: 16px 20px;">
                <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-end;">
                    <input type="hidden" name="page" value="lead-intelligence-meta">
                    <input type="hidden" name="view" value="leads">

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
                                <a href="<?php echo esc_url(add_query_arg(['periodo' => $k, 'view' => 'leads'], remove_query_arg(['from', 'to']))); ?>" 
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
                        <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-meta&view=leads')); ?>" class="button button-secondary" style="height: 32px; line-height: 30px;">
                            Limpar
                        </a>
                    </div>
                </form>
            </div>

            <!-- TABELA DE LEADS META ADS (ESTILO NATIVO WORDPRESS) -->
            <div class="li-card" style="padding: 0; overflow-x: auto;">
                <div style="padding: 16px 20px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <div>
                        <h3 class="li-card-title" style="margin: 0; font-size: 16px;">Leads Capturados via Meta Ads</h3>
                        <p class="li-card-desc" style="margin: 2px 0 0; font-size: 12px;">Contatos identificados com origem de Facebook Ads, Instagram Ads e formulários vinculados.</p>
                    </div>
                    <span class="li-badge" style="background: rgba(0, 129, 251, 0.15); color: #0081fb; font-weight: 700;">
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
                                    Nenhum lead Meta Ads encontrado no período selecionado.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($leads as $lead): 
                                $lead_phone_clean = preg_replace('/\D/', '', $lead->telefone_normalizado ?: ($lead->telefone ?? ''));
                                $etapa_raw = !empty($lead->etapa_funil) ? $lead->etapa_funil : ($lead->qualificacao_status === 'qualificado' ? 'Matrícula Realizada' : ($lead->qualificacao_status === 'nao_qualificado' ? 'Desqualificado' : 'Formulário Enviado'));
                                
                                // Extrai apenas utm_source limpa
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
