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

    /**
     * Endpoint AJAX para atualização de qualificação/venda e envio para a Meta CAPI
     */
    public static function ajax_update_lead_status() {
        check_ajax_referer('li_meta_ads_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permissão negada.']);
        }

        $lead_id = isset($_POST['lead_id']) ? (int) $_POST['lead_id'] : 0;
        $action  = isset($_POST['action_type']) ? sanitize_key($_POST['action_type']) : '';
        $valor   = isset($_POST['valor']) ? (float) $_POST['valor'] : null;

        if ($lead_id <= 0 || empty($action)) {
            wp_send_json_error(['message' => 'Parâmetros de requisição inválidos.']);
        }

        $result = LeadRepository::update_lead_status_and_capi($lead_id, $action, $valor);
        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message']]);
        }

        $lead = $result['lead'];
        $capi_res = $result['capi_result'] ?? null;
        $capi_feedback = '';

        if ($capi_res) {
            if (!empty($capi_res['success'])) {
                $capi_feedback = ' e enviado para o Meta com sucesso!';
            } else {
                $capi_feedback = ' (Salvo no sistema. Aviso Meta CAPI: ' . ($capi_res['message'] ?? 'não enviado') . ')';
            }
        }

        $action_labels = [
            'qualificar'    => 'Lead marcado como Qualificado',
            'desqualificar' => 'Lead marcado como Não Qualificado',
            'pendente'      => 'Lead revertido para Pendente',
            'venda'         => 'Venda registrada com sucesso',
            'remover_venda' => 'Marcação de venda removida',
        ];

        $friendly_msg = ($action_labels[$action] ?? 'Status atualizado com sucesso') . $capi_feedback;

        wp_send_json_success([
            'lead_id'            => $lead_id,
            'message'            => $friendly_msg,
            'status'             => $lead->qualificacao_status,
            'etapa'              => $lead->etapa_funil,
            'status_badge_html'  => self::render_status_badge_html($lead),
            'etapa_badge_html'   => self::render_etapa_badge_html($lead),
            'actions_html'       => self::render_meta_actions_html($lead),
            'is_sale'            => !empty($lead->capi_purchase_sent) || stripos($lead->etapa_funil, 'venda') !== false || stripos($lead->etapa_funil, 'matr') !== false,
            'capi_purchase_sent' => !empty($lead->capi_purchase_sent),
            'capi_qualified_sent'=> !empty($lead->capi_qualified_sent),
        ]);
    }

    public static function render_status_badge_html($lead) {
        $status = $lead->qualificacao_status ?? 'pendente';
        $is_sale = !empty($lead->capi_purchase_sent) || stripos($lead->etapa_funil ?? '', 'venda') !== false || stripos($lead->etapa_funil ?? '', 'matr') !== false;

        if ($is_sale) {
            return '<span class="li-badge li-status-qualificado" style="background: rgba(16, 185, 129, 0.15); color: #059669; font-weight: 700; border: 1px solid rgba(16, 185, 129, 0.3);">💰 Venda</span>';
        } elseif ($status === 'qualificado') {
            return '<span class="li-badge li-status-qualificado" style="font-weight: 600;">Qualificado</span>';
        } elseif ($status === 'nao_qualificado') {
            return '<span class="li-badge li-status-desqualificado" style="font-weight: 600;">Não Qualificado</span>';
        } else {
            return '<span class="li-badge li-status-cinza" style="font-weight: 600;">Pendente</span>';
        }
    }

    public static function render_etapa_badge_html($lead) {
        $etapa = !empty($lead->etapa_funil) ? $lead->etapa_funil : ($lead->qualificacao_status === 'qualificado' ? 'Qualificado' : ($lead->qualificacao_status === 'nao_qualificado' ? 'Desqualificado' : 'Formulário Enviado'));
        $status = $lead->qualificacao_status ?? 'pendente';
        $class = ($status === 'qualificado') ? 'li-status-qualificado' : (($status === 'nao_qualificado') ? 'li-status-desqualificado' : 'li-status-cinza');
        return '<span class="li-badge ' . esc_attr($class) . '" style="font-size: 11px;">' . esc_html($etapa) . '</span>';
    }

    public static function render_meta_actions_html($lead) {
        $is_qual = ($lead->qualificacao_status === 'qualificado');
        $is_desq = ($lead->qualificacao_status === 'nao_qualificado');
        $is_sale = !empty($lead->capi_purchase_sent) || stripos($lead->etapa_funil ?? '', 'venda') !== false || stripos($lead->etapa_funil ?? '', 'matr') !== false;

        $has_capi_purchase = !empty($lead->capi_purchase_sent);
        $has_capi_qual     = !empty($lead->capi_qualified_sent);
        $has_capi_lead     = !empty($lead->capi_lead_sent);

        ob_start();
        ?>
        <div class="li-meta-row-actions" data-lead-id="<?php echo esc_attr($lead->id); ?>">
            <!-- BOTOES DE CONTROLE RÁPIDO -->
            <div class="li-btn-group-meta" style="display: inline-flex; gap: 4px; align-items: center; justify-content: center; margin-bottom: 4px;">
                <!-- BOTÃO QUALIFICADO -->
                <button type="button" 
                        class="button button-small li-action-btn li-btn-qualificar <?php echo $is_qual ? 'active is-active-qual' : ''; ?>" 
                        data-action="<?php echo $is_qual && !$is_sale ? 'pendente' : 'qualificar'; ?>"
                        title="<?php echo $is_qual ? 'Clique para desmarcar (voltar para pendente)' : 'Marcar como Qualificado e enviar à Meta CAPI'; ?>"
                        style="<?php echo $is_qual ? 'background: #10b981; color: #fff; border-color: #059669; font-weight: 700;' : 'background: #f8fafc; color: #334155; border-color: #cbd5e1;'; ?> padding: 0 8px; height: 26px; line-height: 24px; font-size: 11px; cursor: pointer;">
                    <?php echo $is_qual ? '✔ Qualificado' : 'Qualificar'; ?>
                </button>

                <!-- BOTÃO NÃO QUALIFICADO -->
                <button type="button" 
                        class="button button-small li-action-btn li-btn-desqualificar <?php echo $is_desq ? 'active is-active-desq' : ''; ?>" 
                        data-action="<?php echo $is_desq ? 'pendente' : 'desqualificar'; ?>"
                        title="<?php echo $is_desq ? 'Clique para desmarcar (voltar para pendente)' : 'Marcar como Não Qualificado'; ?>"
                        style="<?php echo $is_desq ? 'background: #ef4444; color: #fff; border-color: #dc2626; font-weight: 700;' : 'background: #f8fafc; color: #64748b; border-color: #cbd5e1;'; ?> padding: 0 6px; height: 26px; line-height: 24px; font-size: 11px; cursor: pointer;">
                    <?php echo $is_desq ? '✖ Não Qualif.' : '✖'; ?>
                </button>

                <!-- BOTÃO VENDA -->
                <button type="button" 
                        class="button button-small li-action-btn li-btn-venda <?php echo $is_sale ? 'active is-active-venda' : ''; ?>" 
                        data-action="<?php echo $is_sale ? 'remover_venda' : 'venda'; ?>"
                        title="<?php echo $is_sale ? 'Clique para desmarcar venda' : 'Marcar como Venda e despachar evento Purchase para a Meta CAPI'; ?>"
                        style="<?php echo $is_sale ? 'background: #d97706; color: #fff; border-color: #b45309; font-weight: 700; box-shadow: 0 0 6px rgba(217,119,6,0.35);' : 'background: #fff; color: #047857; border-color: #10b981; font-weight: 600;'; ?> padding: 0 8px; height: 26px; line-height: 24px; font-size: 11px; cursor: pointer;">
                    <?php echo $is_sale ? '💰 Venda ✔' : '💰 Venda'; ?>
                </button>
            </div>

            <!-- STATUS META E OPENAI CAPI PILLS -->
            <div class="li-meta-sync-status" style="font-size: 10px; line-height: 1.3; display: flex; flex-direction: column; gap: 2px; align-items: center;">
                <?php if ($has_capi_purchase): ?>
                    <span class="li-capi-pill" style="display: inline-block; background: #dcfce7; color: #15803d; border: 1px solid #86efac; border-radius: 10px; padding: 1px 7px; font-weight: 600;" title="Evento Purchase confirmado pela Meta">
                        🔵 Meta: Venda Enviada
                    </span>
                <?php elseif ($has_capi_qual): ?>
                    <span class="li-capi-pill" style="display: inline-block; background: #e0f2fe; color: #0369a1; border: 1px solid #7dd3fc; border-radius: 10px; padding: 1px 7px; font-weight: 600;" title="Evento LeadQualified confirmado pela Meta">
                        🔵 Meta: Qualificado
                    </span>
                <?php elseif ($has_capi_lead): ?>
                    <span class="li-capi-pill" style="display: inline-block; background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; border-radius: 10px; padding: 1px 7px;" title="Evento Lead recebido da captação">
                        ⚪ Meta: Lead
                    </span>
                <?php endif; ?>

                <?php 
                $has_oai_purchase = !empty($lead->openai_capi_purchase_sent);
                $has_oai_qual     = !empty($lead->openai_capi_qualified_sent);
                $has_oai_lead     = !empty($lead->openai_capi_lead_sent);
                ?>
                <?php if ($has_oai_purchase): ?>
                    <span class="li-capi-pill" style="display: inline-block; background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; border-radius: 10px; padding: 1px 7px; font-weight: 600;" title="Evento order_created confirmado pela OpenAI">
                        🤖 OpenAI: Venda Enviada
                    </span>
                <?php elseif ($has_oai_qual): ?>
                    <span class="li-capi-pill" style="display: inline-block; background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; border-radius: 10px; padding: 1px 7px; font-weight: 600;" title="Evento lead_qualified confirmado pela OpenAI">
                        🤖 OpenAI: Qualificado
                    </span>
                <?php elseif ($has_oai_lead): ?>
                    <span class="li-capi-pill" style="display: inline-block; background: #f8fafc; color: #475569; border: 1px solid #cbd5e1; border-radius: 10px; padding: 1px 7px;" title="Evento lead_created recebido pela OpenAI">
                        🤖 OpenAI: Lead
                    </span>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function render() {
        global $wpdb;
        $table = DbSchema::get_leads_table();

        // 1. Tratamento de Período (Padrão: Hoje, ou Todo se houver pesquisa ativa)
        $custom_from      = isset($_GET['from']) ? sanitize_text_field(wp_unslash($_GET['from'])) : '';
        $custom_to        = isset($_GET['to']) ? sanitize_text_field(wp_unslash($_GET['to'])) : '';
        $search_term      = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $filter_status    = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
        $utm_source       = isset($_GET['utm_source']) ? sanitize_text_field(wp_unslash($_GET['utm_source'])) : '';
        $has_custom_dates = (!empty($custom_from) || !empty($custom_to));
        $is_period_fixed  = !empty($_GET['li_explicit_period']);

        if ($has_custom_dates) {
            $periodo = 'custom';
        } elseif (isset($_GET['periodo']) && $_GET['periodo'] !== '') {
            $periodo = sanitize_text_field(wp_unslash($_GET['periodo']));
            if (!empty($search_term) && $periodo === 'today' && !$is_period_fixed) {
                $periodo = 'all';
            }
        } else {
            $periodo = !empty($search_term) ? 'all' : 'today';
        }
        $available_utm_sources = LeadRepository::get_distinct_utm_sources();

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

        // Construção da Query Segura: Meta Ads + WhatsApp (Ecossistema Meta)
        $meta_type = isset($_GET['meta_type']) ? sanitize_key($_GET['meta_type']) : 'all';

        $meta_ads_cond = LeadRepository::get_channel_sql_condition('meta_ads');
        $whatsapp_cond = LeadRepository::get_channel_sql_condition('whatsapp');

        if ($meta_type === 'ads_only') {
            $meta_condition = $meta_ads_cond;
        } elseif ($meta_type === 'whatsapp_only') {
            $meta_condition = $whatsapp_cond;
        } else {
            // Padrão: Todos da Meta juntos (Anúncios + WhatsApp)
            $meta_condition = "({$meta_ads_cond} OR {$whatsapp_cond})";
        }

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
            $list_where[] = "(nome LIKE %s OR email LIKE %s OR telefone LIKE %s OR telefone_normalizado LIKE %s OR utm_source LIKE %s OR utm_campaign LIKE %s OR ad_name LIKE %s OR campaign_name LIKE %s OR leadid LIKE %s OR instancia LIKE %s OR polo LIKE %s)";
            $list_params = array_merge($list_params, [$like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like]);
        }

        if (!empty($utm_source)) {
            $src_val = trim($utm_source);
            $src_like = '%' . $wpdb->esc_like($src_val) . '%';
            $list_where[] = "(utm_source = %s OR utm_source_first = %s OR utm_source LIKE %s OR pagina_origem LIKE %s)";
            $list_params[] = $src_val;
            $list_params[] = $src_val;
            $list_params[] = $src_like;
            $list_params[] = '%' . $wpdb->esc_like('utm_source=' . $src_val) . '%';
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
                    Lead Intelligence &bull; Meta Ads &amp; WhatsApp
                </h2>
                <p class="li-subtitle">Performance integrada de campanhas, formulários e contatos do ecossistema Meta (Facebook, Instagram e WhatsApp).</p>
            </div>

            <!-- CARDS DE MÉTRICAS (GRID PADRÃO DO PLUGIN / MODELO WHATSAPP) -->
            <div class="li-metric-grid" style="margin-bottom: 20px;">
                <div class="li-metric-card" style="border-left: 4px solid #0081fb;">
                    <span class="li-metric-label">Leads da Meta</span>
                    <span class="li-metric-value" id="li-metric-total" style="color: #0081fb;"><?php echo number_format_i18n($total_leads); ?></span>
                    <span class="li-metric-sub">Ads + WhatsApp Oficial</span>
                </div>
                <div class="li-metric-card li-card-success" style="border-left: 4px solid #10b981;">
                    <span class="li-metric-label">Vendas / Matrículas</span>
                    <span class="li-metric-value" id="li-metric-vendas" style="color: #10b981;"><?php echo number_format_i18n($vendas); ?></span>
                    <span class="li-metric-sub">Alunos confirmados</span>
                </div>
                <div class="li-metric-card" style="border-left: 4px solid #f59e0b;">
                    <span class="li-metric-label">Pendentes Comercial</span>
                    <span class="li-metric-value" id="li-metric-pendentes" style="color: #f59e0b;"><?php echo number_format_i18n($pendentes); ?></span>
                    <span class="li-metric-sub">Aguardando atendimento</span>
                </div>
                <div class="li-metric-card" style="border-left: 4px solid #ef4444;">
                    <span class="li-metric-label">Não Qualificados</span>
                    <span class="li-metric-value" id="li-metric-desq" style="color: #ef4444;"><?php echo number_format_i18n($desq); ?></span>
                    <span class="li-metric-sub">Descartes / Sem perfil</span>
                </div>
                <div class="li-metric-card" style="border-left: 4px solid #14b8a6;">
                    <span class="li-metric-label">Taxa de Conversão</span>
                    <span class="li-metric-value" id="li-metric-taxa" style="color: #0d9488;"><?php echo esc_html($taxa); ?>%</span>
                    <span class="li-metric-sub">Eficiência de fechamento</span>
                </div>
            </div>

            <!-- CARD DE FILTRO LIMPO (ESTILO WORDPRESS) -->
            <div class="li-card" style="margin-bottom: 20px; padding: 16px 20px;">
                <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-end;">
                    <input type="hidden" name="page" value="lead-intelligence-meta">
                    <input type="hidden" name="view" value="leads">
                    <input type="hidden" name="periodo" value="<?php echo esc_attr($periodo); ?>">
                    <?php if ($is_period_fixed): ?>
                        <input type="hidden" name="li_explicit_period" value="1">
                    <?php endif; ?>

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
                                <a href="<?php echo esc_url(add_query_arg(['periodo' => $k, 'view' => 'leads', 's' => $search_term, 'status' => $filter_status, 'utm_source' => $utm_source, 'li_explicit_period' => 1], remove_query_arg(['from', 'to']))); ?>" 
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

                    <!-- Tipo de Origem Meta -->
                    <div>
                        <label style="display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 6px;">Canal Meta</label>
                        <select name="meta_type" style="height: 32px; font-size: 13px;">
                            <option value="all" <?php selected($meta_type, 'all'); ?>>🔵💬 Todos da Meta (Ads + WhatsApp)</option>
                            <option value="ads_only" <?php selected($meta_type, 'ads_only'); ?>>🔵 Apenas Meta Ads (Formulários/Site)</option>
                            <option value="whatsapp_only" <?php selected($meta_type, 'whatsapp_only'); ?>>💬 Apenas WhatsApp (Meta Cloud)</option>
                        </select>
                    </div>

                    <!-- Campo de Busca -->
                    <div style="flex: 1 1 180px;">
                        <label style="display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 6px;">Pesquisar</label>
                        <input type="text" name="s" value="<?php echo esc_attr($search_term); ?>" placeholder="Filtrar por nome, telefone..." style="width: 100%; height: 32px; font-size: 13px;">
                    </div>

                    <!-- Filtro por utm_source -->
                    <div style="min-width: 160px;">
                        <label style="display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 6px;">🏷️ utm_source</label>
                        <input type="text" name="utm_source" list="li_meta_utm_sources_list" value="<?php echo esc_attr($utm_source); ?>" placeholder="Ex: generica-20-10-26" style="width: 100%; height: 32px; font-size: 13px;">
                        <datalist id="li_meta_utm_sources_list">
                            <?php foreach ($available_utm_sources as $src_opt): ?>
                                <option value="<?php echo esc_attr($src_opt); ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
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

            <!-- CARD DE DESTAQUE: FILTRO ATIVO POR UTM SOURCE -->
            <?php if (!empty($utm_source)): ?>
                <div style="margin-bottom: 15px; padding: 12px 18px; background: #f0f9ff; border: 1px solid #7dd3fc; border-left: 4px solid #0284c7; border-radius: 8px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                        <span style="font-size: 18px;">🏷️</span>
                        <div style="font-size: 13px; color: #0369a1;">
                            Filtro ativo: <strong>utm_source = </strong>
                            <code style="background: #ffffff; border: 1px solid #bae6fd; padding: 3px 8px; border-radius: 4px; color: #0369a1; font-weight: 700; font-size: 13px;"><?php echo esc_html($utm_source); ?></code>
                            <span style="margin-left: 12px; color: #0f172a; font-weight: 600;">
                                📊 Encontrados: <strong><?php echo count($leads); ?> leads</strong> exibidos nesta listagem
                            </span>
                        </div>
                    </div>
                    <div>
                        <a href="<?php echo esc_url(remove_query_arg('utm_source')); ?>" class="button button-secondary button-small" style="color: #b91c1c; border-color: #fca5a5;">
                            ✕ Limpar Filtro UTM
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <!-- TABELA DE LEADS META ADS (ESTILO NATIVO WORDPRESS) -->
            <div class="li-card" style="padding: 0; overflow-x: auto;">
                <div style="padding: 16px 20px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <div>
                        <h3 class="li-card-title" style="margin: 0; font-size: 16px;">Leads do Ecossistema Meta (Ads &amp; WhatsApp)</h3>
                        <p class="li-card-desc" style="margin: 2px 0 0; font-size: 12px;">Contatos identificados com origem de Facebook Ads, Instagram Ads e conversas do WhatsApp Cloud API.</p>
                    </div>
                    <span class="li-badge" style="background: rgba(0, 129, 251, 0.15); color: #0081fb; font-weight: 700;">
                        <?php echo count($leads); ?> leads exibidos
                    </span>
                </div>

                <table class="wp-list-table widefat fixed striped li-table">
                    <thead>
                        <tr>
                            <th style="width: 55px;">ID</th>
                            <th style="width: 95px;">Lead ID</th>
                            <th style="width: 105px;">Data</th>
                            <th>Nome / Lead</th>
                            <th style="width: 140px;">Telefone / WhatsApp</th>
                            <th style="width: 130px;">Instância</th>
                            <th style="width: 120px; text-align: center;">Origem Meta</th>
                            <th>Curso / Interesse</th>
                            <th style="width: 110px;">UTM</th>
                            <th style="width: 120px; text-align: center;">Etapa do Funil</th>
                            <th style="width: 100px; text-align: center;">Status</th>
                            <th style="width: 240px; text-align: center;">Qualificação &amp; Meta CAPI</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($leads)): ?>
                            <tr>
                                <td colspan="12" style="text-align: center; padding: 35px; color: #64748b;">
                                    Nenhum lead Meta encontrado no período selecionado.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($leads as $lead): 
                                $lead_phone_clean = preg_replace('/\D/', '', $lead->telefone_normalizado ?: ($lead->telefone ?? ''));
                                
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

                                // Identifica se é WhatsApp ou Anúncio tradicional
                                $is_wa = (!empty($lead->whatsapp_status) || !empty($lead->conversation_id) || stripos($lead->formulario_nome ?? '', 'whatsapp') !== false || !empty($lead->phone_number_id));
                            ?>
                                <tr id="li-lead-row-<?php echo esc_attr($lead->id); ?>">
                                    <td><strong>#<?php echo esc_html($lead->id); ?></strong></td>
                                    <td>
                                        <?php if (!empty($lead->leadid)): ?>
                                            <code style="background: #f8fafc; color: #334155; border: 1px solid #cbd5e1; font-weight: 700; font-size: 11px; padding: 2px 6px; border-radius: 4px; display: inline-block; white-space: nowrap;" title="Lead ID da Planilha: <?php echo esc_attr($lead->leadid); ?>">
                                                #<?php echo esc_html($lead->leadid); ?>
                                            </code>
                                        <?php else: ?>
                                            <span style="color: #cbd5e1; font-size: 12px;">—</span>
                                        <?php endif; ?>
                                    </td>
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
                                        <?php 
                                        $inst_val = !empty($lead->instancia) ? $lead->instancia : (!empty($lead->polo) ? $lead->polo : '');
                                        ?>
                                        <?php if (!empty($inst_val)): ?>
                                            <span class="li-badge" style="background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; font-weight: 600; font-size: 11px; padding: 2px 7px; border-radius: 4px; display: inline-flex; align-items: center; gap: 4px; text-transform: capitalize;" title="Instância: <?php echo esc_attr($inst_val); ?>">
                                                <span>🏛️</span> <?php echo esc_html($inst_val); ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color: #cbd5e1; font-size: 12px;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: center;">
                                        <?php if ($is_wa): ?>
                                            <span class="li-badge" style="background: rgba(21, 128, 61, 0.12); color: #15803d; font-weight: 700; border: 1px solid rgba(21, 128, 61, 0.25);" title="Contato recebido via WhatsApp Cloud API / Click-to-WhatsApp">
                                                💬 WhatsApp
                                            </span>
                                        <?php else: ?>
                                            <span class="li-badge" style="background: rgba(8, 102, 255, 0.12); color: #0866ff; font-weight: 700; border: 1px solid rgba(8, 102, 255, 0.25);" title="Lead gerado via Anúncio / Formulário Meta">
                                                🔵 Anúncio / Form
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span style="font-size: 12px; color: #334155;"><?php echo esc_html($lead->tipo_curso ?: ($lead->area_interesse ?: '-')); ?></span>
                                    </td>
                                    <td>
                                        <?php if (!empty($source_display)): ?>
                                            <a href="<?php echo esc_url(add_query_arg(['page' => 'lead-intelligence-meta', 'view' => 'leads', 'utm_source' => $source_display, 'periodo' => $periodo], admin_url('admin.php'))); ?>" style="text-decoration: none;" title="Filtrar por utm_source = '<?php echo esc_attr($source_display); ?>'">
                                                <code style="background: #e0f2fe; color: #0369a1; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; cursor: pointer; transition: all 0.2s;" onmouseover="this.style.background='#bae6fd'; this.style.color='#0284c7';" onmouseout="this.style.background='#e0f2fe'; this.style.color='#0369a1';">
                                                    <span>🏷️</span> <?php echo esc_html($source_display); ?>
                                                </code>
                                            </a>
                                        <?php else: ?>
                                            <span style="color: #94a3b8; font-size: 11px; font-style: italic;">Sem UTM</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="li-col-etapa" style="text-align: center;">
                                        <?php echo self::render_etapa_badge_html($lead); ?>
                                    </td>
                                    <td class="li-col-status" style="text-align: center;">
                                        <?php echo self::render_status_badge_html($lead); ?>
                                    </td>
                                    <td class="li-col-actions" style="text-align: center;">
                                        <?php echo self::render_meta_actions_html($lead); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- SCRIPT AJAX DE QUALIFICAÇÃO & DESPACHO META CAPI -->
        <script>
        jQuery(document).ready(function($) {
            var metaNonce = '<?php echo esc_js(wp_create_nonce('li_meta_ads_nonce')); ?>';

            $(document).on('click', '.li-action-btn', function(e) {
                e.preventDefault();
                var $btn = $(this);
                if ($btn.prop('disabled')) return;

                var $container = $btn.closest('.li-meta-row-actions');
                var leadId = $container.data('lead-id');
                var actionType = $btn.data('action');

                if (!leadId || !actionType) return;

                var originalHtml = $btn.html();
                $container.find('.li-action-btn').prop('disabled', true).css('opacity', '0.6');
                $btn.html('⏳ ...');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'li_meta_ads_update_lead',
                        lead_id: leadId,
                        action_type: actionType,
                        nonce: metaNonce
                    },
                    success: function(res) {
                        if (res.success && res.data) {
                            var d = res.data;
                            var $row = $('#li-lead-row-' + leadId);

                            // Atualiza a coluna de ações e o status da Meta
                            $container.replaceWith(d.actions_html);

                            // Atualiza badges de Etapa e Status
                            $row.find('.li-col-etapa').html(d.etapa_badge_html);
                            $row.find('.li-col-status').html(d.status_badge_html);

                            showMetaNotification(d.message, 'success');
                        } else {
                            $btn.html(originalHtml);
                            $container.find('.li-action-btn').prop('disabled', false).css('opacity', '1');
                            var msg = (res.data && res.data.message) ? res.data.message : 'Falha ao atualizar o lead.';
                            showMetaNotification(msg, 'error');
                        }
                    },
                    error: function(xhr, status, err) {
                        $btn.html(originalHtml);
                        $container.find('.li-action-btn').prop('disabled', false).css('opacity', '1');
                        showMetaNotification('Erro na requisição com o servidor: ' + err, 'error');
                    }
                });
            });

            function showMetaNotification(msg, type) {
                var $toast = $('#li-meta-live-toast');
                if (!$toast.length) {
                    $toast = $('<div id="li-meta-live-toast" style="position: fixed; top: 40px; right: 25px; z-index: 999999; max-width: 480px; padding: 13px 20px; border-radius: 8px; font-size: 13px; font-weight: 600; box-shadow: 0 10px 25px rgba(0,0,0,0.2); display: flex; align-items: center; gap: 10px; transition: all 0.3s ease;"></div>');
                    $('body').append($toast);
                }

                var isOk = (type === 'success');
                $toast.css({
                    background: isOk ? '#059669' : '#dc2626',
                    color: '#ffffff',
                    border: isOk ? '1px solid #10b981' : '1px solid #ef4444',
                    opacity: 0,
                    transform: 'translateY(-15px)'
                }).html((isOk ? '✔ ' : '✖ ') + msg).show();

                $toast.animate({ opacity: 1, top: '50px' }, 200);

                setTimeout(function() {
                    $toast.fadeOut(300);
                }, 4500);
            }
        });
        </script>
        <?php
    }
}
