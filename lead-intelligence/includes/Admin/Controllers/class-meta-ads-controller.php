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
     * Endpoint AJAX para obter dados do criativo e anúncio Meta para o Modal
     */
    public static function ajax_get_ad_creative_preview() {
        check_ajax_referer('li_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permissão negada.']);
        }

        global $wpdb;
        $leads_table = DbSchema::get_leads_table();
        $whatsapp_table = DbSchema::get_whatsapp_table();

        $lead_id = isset($_POST['lead_id']) ? (int) $_POST['lead_id'] : 0;
        if ($lead_id <= 0) {
            wp_send_json_error(['message' => 'Lead inválido.']);
        }

        $lead = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$leads_table} WHERE id = %d", $lead_id));
        if (!$lead) {
            wp_send_json_error(['message' => 'Lead não encontrado.']);
        }

        // Tenta obter dados do referral em qualificacao_dados ou em wp_li_whatsapp_messages
        $referral = [];
        if (!empty($lead->qualificacao_dados)) {
            $qd = json_decode($lead->qualificacao_dados, true);
            if (!empty($qd['referral'])) {
                $referral = $qd['referral'];
            }
        }

        $raw_msg = $wpdb->get_row($wpdb->prepare(
            "SELECT conteudo, payload_bruto FROM {$whatsapp_table} WHERE (lead_id = %d OR (telefone_normalizado != '' AND telefone_normalizado = %s)) ORDER BY id DESC LIMIT 1",
            $lead_id,
            $lead->telefone_normalizado
        ));

        if (empty($referral) && $raw_msg && !empty($raw_msg->payload_bruto)) {
            $p_data = json_decode($raw_msg->payload_bruto, true);
            if (!empty($p_data['referral'])) {
                $referral = $p_data['referral'];
            }
        }

        $ad_id       = !empty($referral['source_id']) ? $referral['source_id'] : $lead->ad_id;
        $headline    = !empty($referral['headline']) ? $referral['headline'] : ($lead->ad_name ?: 'Converse Conosco');
        $body_text   = !empty($referral['body']) ? $referral['body'] : '';
        $media_type  = !empty($referral['media_type']) ? strtoupper($referral['media_type']) : '';
        $image_url   = !empty($referral['image_url']) ? $referral['image_url'] : '';
        $video_url   = !empty($referral['video_url']) ? $referral['video_url'] : '';
        $source_url  = !empty($referral['source_url']) ? $referral['source_url'] : $lead->pagina_origem;

        // Se tiver Ad ID numérico, gera o link direto do Gerenciador de Anúncios da Meta
        $ads_manager_url = '';
        $ad_meta = [];
        $page_name = '';
        $page_picture = '';

        try {
            if (!empty($ad_id) && preg_match('/^[0-9]{8,25}$/', $ad_id)) {
                $ads_manager_url = 'https://adsmanager.facebook.com/adsmanager/manage/ads?selected_ad_ids=' . $ad_id;
                $ad_meta = \LeadIntelligence\WhatsApp\WabaClient::get_ad_details($ad_id);
                if (!empty($ad_meta['page_name'])) {
                    $page_name = $ad_meta['page_name'];
                }
                if (!empty($ad_meta['page_picture'])) {
                    $page_picture = $ad_meta['page_picture'];
                }
            }

            // Fallback inteligente: se não encontrou a foto da Página, tenta a foto do Perfil Oficial da WABA
            if (empty($page_picture)) {
                $polo_info = null;
                $all_polos = \LeadIntelligence\WhatsApp\PoloRepository::get_all();
                $polo_target = strtolower(trim((string) (!empty($lead->instancia) ? $lead->instancia : $lead->polo)));
                
                if (!empty($all_polos) && is_array($all_polos)) {
                    foreach ($all_polos as $p) {
                        $p_name = strtolower($p['nome'] ?? '');
                        if ($polo_target !== '' && ($p_name === $polo_target || stripos($p_name, $polo_target) !== false || stripos($polo_target, $p_name) !== false)) {
                            $polo_info = $p;
                            break;
                        }
                    }
                }
                if (!$polo_info) {
                    $polo_info = \LeadIntelligence\WhatsApp\PoloRepository::get_default();
                }

                if ($polo_info && !empty($polo_info['phone_number_id']) && !empty($polo_info['access_token'])) {
                    $cache_prof_key = 'li_wa_prof_pic_' . $polo_info['phone_number_id'];
                    $cached_pic = get_transient($cache_prof_key);
                    if ($cached_pic !== false) {
                        $page_picture = $cached_pic;
                    } else {
                        $settings = \LeadIntelligence\Admin\Settings::get_settings();
                        $v = !empty($settings['meta_graph_version']) ? $settings['meta_graph_version'] : 'v26.0';
                        $w_url = "https://graph.facebook.com/{$v}/{$polo_info['phone_number_id']}/whatsapp_business_profile?fields=profile_picture_url,about";
                        $w_res = wp_remote_get($w_url, [
                            'headers' => ['Authorization' => 'Bearer ' . $polo_info['access_token']],
                            'timeout' => 4,
                        ]);
                        if (!is_wp_error($w_res) && wp_remote_retrieve_response_code($w_res) === 200) {
                            $w_body = json_decode(wp_remote_retrieve_body($w_res), true);
                            $page_picture = $w_body['data'][0]['profile_picture_url'] ?? '';
                            set_transient($cache_prof_key, $page_picture, 24 * HOUR_IN_SECONDS);
                        } else {
                            set_transient($cache_prof_key, '', 2 * HOUR_IN_SECONDS);
                        }
                    }
                }

                if (empty($page_name) && $polo_info && !empty($polo_info['nome'])) {
                    $page_name = $polo_info['nome'];
                }
            }
        } catch (\Throwable $e) {
            \LeadIntelligence\Logger::warning('WhatsApp', 'Erro ao obter dados da Página/WABA: ' . $e->getMessage());
        }

        if (empty($page_name)) {
            $page_name = !empty($lead->instancia) ? $lead->instancia : (!empty($lead->polo) ? $lead->polo : 'Faculdade FAVENI');
        }

        $clean_phone = preg_replace('/\D+/', '', $lead->telefone_normalizado ?: $lead->telefone);
        $formatted_date = !empty($lead->data_cadastro) ? wp_date('d/m/Y \à\s H:i', strtotime($lead->data_cadastro)) : '';

        wp_send_json_success([
            'lead_id'           => $lead->id,
            'lead_nome'         => $lead->nome ?: 'Lead #' . $lead->id,
            'lead_telefone'     => PhoneNormalizer::format_display($lead->telefone ?: $lead->telefone_normalizado),
            'lead_telefone_raw' => $clean_phone,
            'lead_data'         => $formatted_date,
            'lead_polo'         => !empty($lead->instancia) ? $lead->instancia : (!empty($lead->polo) ? $lead->polo : ''),
            'lead_curso'        => !empty($lead->tipo_curso) ? $lead->tipo_curso : (!empty($lead->area_interesse) ? $lead->area_interesse : ''),
            'page_name'         => $page_name,
            'page_picture'      => $page_picture,
            'ad_id'             => $ad_id,
            'headline'          => $headline,
            'body_text'         => $body_text,
            'media_type'        => $media_type,
            'image_url'         => $image_url,
            'video_url'         => $video_url,
            'source_url'        => $source_url,
            'ads_manager_url'   => $ads_manager_url,
            'campaign_name'     => !empty($lead->campaign_name) ? $lead->campaign_name : ($ad_meta['campaign_name'] ?? ''),
            'adset_name'        => !empty($lead->adset_name) ? $lead->adset_name : ($ad_meta['adset_name'] ?? ''),
            'ad_name'           => !empty($lead->ad_name) ? $lead->ad_name : ($ad_meta['ad_name'] ?? ''),
            'first_message'     => $raw_msg ? $raw_msg->conteudo : '',
        ]);
    }

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

        // 1. Reparação e blindagem de classificação: limpa conversation_id indevido em leads do Google/Elementor
        LeadRepository::repair_misclassified_leads();

        // 2. Auto-cura leve e segura: unifica duplicados causados por concorrência de webhooks ESTRITAMENTE do WhatsApp
        LeadRepository::deduplicate_whatsapp_leads();
        LeadRepository::enrich_placeholder_leads();

        // Sincronização manual em lote com a Meta Marketing API
        $sync_message = null;
        $sync_success = false;
        if (isset($_POST['li_sync_all_ads'])) {
            $nonce_valid = isset($_POST['li_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['li_nonce'])), 'li_sync_all_ads_verify');
            if ($nonce_valid || current_user_can('manage_options')) {
                $sync_res = \LeadIntelligence\WhatsApp\WabaClient::sync_all_pending_leads();
                $sync_message = $sync_res['message'];
                $sync_success = ($sync_res['updated'] > 0 || $sync_res['total'] === 0);
            }
        }

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

        // Blindagem Absoluta: A tela de Meta Ads NUNCA deve puxar leads do Google Ads ou de Planilhas Google
        $google_cond      = LeadRepository::get_channel_sql_condition('google_ads');
        $google_form_cond = LeadRepository::get_channel_sql_condition('google_lead_form');
        $planilha_cond    = LeadRepository::get_channel_sql_condition('planilha');

        $meta_condition .= " AND NOT ({$google_cond}) AND NOT ({$google_form_cond}) AND NOT ({$planilha_cond})";
        $meta_condition .= " AND (formulario_nome IS NULL OR INSTR(LOWER(formulario_nome), 'google') = 0)";
        $meta_condition .= " AND (utm_source IS NULL OR LOWER(utm_source) NOT IN ('google', 'google_ads', 'gads', 'demanda-mkt', 'online-19-10-26', 'google_lead_form'))";
        $meta_condition .= " AND (gclid IS NULL OR gclid = '')";

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
            $raw_st = trim($search_term);
            $like = '%' . $wpdb->esc_like($raw_st) . '%';
            $clean_id = (int) ltrim($raw_st, '#');
            $digits_only = preg_replace('/\D/', '', $raw_st);

            $st_conds = [
                "nome LIKE %s", "email LIKE %s", "telefone LIKE %s", "telefone_normalizado LIKE %s",
                "utm_source LIKE %s", "utm_campaign LIKE %s", "ad_name LIKE %s", "campaign_name LIKE %s",
                "leadid LIKE %s", "instancia LIKE %s", "polo LIKE %s"
            ];
            $st_params = array_fill(0, count($st_conds), $like);

            if ($clean_id > 0) {
                array_unshift($st_conds, "id = %d");
                array_unshift($st_params, $clean_id);
            }

            if (!empty($digits_only) && strlen($digits_only) >= 8) {
                $st_conds[] = "telefone_normalizado LIKE %s";
                $st_params[] = '%' . $wpdb->esc_like($digits_only) . '%';
            }

            $list_where[] = "(" . implode(' OR ', $st_conds) . ")";
            $list_params = array_merge($list_params, $st_params);
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

        $meta_url_params = 'utm_source=meta&utm_medium=cpc&utm_campaign={{campaign.name}}&utm_content={{adset.name}}&utm_term={{ad.name}}&campaign_id={{campaign.id}}&adset_id={{adset.id}}&ad_id={{ad.id}}&campaign_name={{campaign.name}}&adset_name={{adset.name}}&ad_name={{ad.name}}';
        ?>
        <div class="wrap li-wrap">
            <!-- NOTIFICAÇÃO DE SINCRONIZAÇÃO EM MASSA -->
            <?php if (!empty($sync_message)): ?>
                <div class="notice <?php echo $sync_success ? 'notice-success' : 'notice-warning'; ?> is-dismissible" style="margin-bottom: 20px; padding: 12px 16px;">
                    <strong><?php echo $sync_success ? '✔ Sucesso:' : 'ℹ️ Informação:'; ?></strong> <?php echo esc_html($sync_message); ?>
                </div>
            <?php endif; ?>

            <!-- CABEÇALHO PADRÃO WORDPRESS -->
            <div class="li-header" style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px;">
                <div>
                    <h2 style="display: flex; align-items: center; gap: 10px; margin: 0;">
                        <svg viewBox="0 0 24 24" width="28" height="28" fill="none">
                            <path fill="#0081FB" d="M6.915 4.03c-1.968 0-3.683 1.28-4.871 3.113C.704 9.208 0 11.883 0 14.449c0 .706.07 1.369.21 1.973a6.624 6.624 0 0 0 .265.86 5.297 5.297 0 0 0 .371.761c.696 1.159 1.818 1.927 3.593 1.927 1.497 0 2.633-.671 3.965-2.444.76-1.012 1.144-1.626 2.663-4.32l.756-1.339.186-.325c.061.1.121.196.183.3l2.152 3.595c.724 1.21 1.665 2.556 2.47 3.314 1.046.987 1.992 1.22 3.06 1.22 1.075 0 1.876-.355 2.455-.843a3.743 3.743 0 0 0 .81-.973c.542-.939.861-2.127.861-3.745 0-2.72-.681-5.357-2.084-7.45-1.282-1.912-2.957-2.93-4.716-2.93-1.047 0-2.088.467-3.053 1.308-.652.57-1.257 1.29-1.82 2.05-.69-.875-1.335-1.547-1.958-2.056-1.182-.966-2.315-1.303-3.454-1.303zm10.16 2.053c1.147 0 2.188.758 2.992 1.999 1.132 1.748 1.647 4.195 1.647 6.4 0 1.548-.368 2.9-1.839 2.9-.58 0-1.027-.23-1.664-1.004-.496-.601-1.343-1.878-2.832-4.358l-.617-1.028a44.908 44.908 0 0 0-1.255-1.98c.07-.109.141-.224.211-.327 1.12-1.667 2.118-2.602 3.358-2.602zm-10.201.553c1.265 0 2.058.791 2.675 1.446.307.327.737.871 1.234 1.579l-1.02 1.566c-.757 1.163-1.882 3.017-2.837 4.338-1.191 1.649-1.81 1.817-2.486 1.817-.524 0-1.038-.237-1.383-.794-.263-.426-.464-1.13-.464-2.046 0-2.221.63-4.535 1.66-6.088.454-.687.964-1.226 1.533-1.533a2.264 2.264 0 0 1 1.088-.285z"/>
                        </svg>
                        Lead Intelligence &bull; Meta Ads &amp; WhatsApp
                    </h2>
                    <p class="li-subtitle" style="margin: 4px 0 0;">Performance integrada de campanhas, formulários e contatos do ecossistema Meta (Facebook, Instagram e WhatsApp).</p>
                </div>
                <div>
                    <form method="post" action="" style="display: inline;">
                        <?php wp_nonce_field('li_sync_all_ads_verify', 'li_nonce'); ?>
                        <button type="submit" name="li_sync_all_ads" value="1" class="button button-secondary" style="display: inline-flex; align-items: center; gap: 6px; font-weight: 600; color: #0284c7; border-color: #7dd3fc; background: #f0f9ff;" title="Consulta a Meta Marketing API e preenche os nomes de anúncios e campanhas faltantes dos leads que possuem Ad ID">
                            <span class="dashicons dashicons-update" style="font-size: 16px; width: 16px; height: 16px; line-height: 16px;"></span>
                            Sincronizar Nomes de Anúncios Meta
                        </button>
                    </form>
                </div>
            </div>

            <!-- GUIA VISUAL: COMO MARCAR E RASTREAR NOME DE ANÚNCIO E CAMPANHA NO META ADS -->
            <div class="li-card" style="margin-bottom: 20px; border-left: 4px solid #0081fb; background: #ffffff;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 15px; flex-wrap: wrap;">
                    <div style="flex: 1; min-width: 300px;">
                        <h3 class="li-card-title" style="margin: 0 0 6px 0; font-size: 15px; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                            <span>🎯</span> Como Rastrear Nome de Anúncio e Campanha no Meta Ads (Facebook &amp; Instagram)
                        </h3>
                        <p class="li-card-desc" style="margin: 0 0 12px 0; font-size: 13px; color: #475569;">
                            Para que os leads cheguem com o <strong>Nome do Anúncio</strong>, <strong>Conjunto</strong> e <strong>Campanha</strong> identificados perfeitamente, você só precisa colar os parâmetros dinâmicos abaixo no seu anúncio:
                        </p>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 14px; margin-top: 6px;">
                    <!-- FLUXO 1: FORMULÁRIOS DO SITE / ELEMENTOR -->
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 16px;">
                        <div style="font-weight: 700; font-size: 12px; color: #1e3a8a; text-transform: uppercase; margin-bottom: 6px; display: flex; align-items: center; gap: 6px;">
                            <span>🌐</span> 1. Anúncios para o Site (Elementor / Landing Page)
                        </div>
                        <p style="margin: 0 0 8px 0; font-size: 12px; color: #475569; line-height: 1.5;">
                            No <strong>Gerenciador de Anúncios da Meta</strong>, vá no nível do <strong>Anúncio (Ad)</strong> &gt; Seção <strong>Rastreamento</strong> &gt; Campo <strong>"Parâmetros de URL"</strong> e cole:
                        </p>
                        <div style="display: flex; gap: 8px; align-items: center; margin-bottom: 8px;">
                            <code id="li_meta_params_code" style="font-size: 11px; background: #ffffff; color: #0369a1; padding: 7px 10px; border-radius: 4px; border: 1px solid #cbd5e1; flex: 1; overflow-x: auto; white-space: nowrap; font-family: monospace; font-weight: 600;">
                                <?php echo esc_html($meta_url_params); ?>
                            </code>
                            <button type="button" class="button button-secondary button-small" onclick="navigator.clipboard.writeText('<?php echo esc_js($meta_url_params); ?>'); this.innerText='Copiado!'; setTimeout(()=>{this.innerText='Copiar'}, 2000);" style="font-weight: 600; white-space: nowrap;">
                                📋 Copiar Parâmetros
                            </button>
                        </div>
                        <div style="font-size: 11px; color: #64748b; line-height: 1.4;">
                            ✔ A Meta substitui automaticamente <code>{{ad.name}}</code> e <code>{{campaign.name}}</code> pelos nomes reais.<br>
                            ✔ O plugin preserva as UTMs mesmo se o lead navegar por várias páginas antes de preencher o formulário.
                        </div>
                    </div>

                    <!-- FLUXO 2: ANÚNCIOS CLICK-TO-WHATSAPP (CTWA) -->
                    <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 14px 16px;">
                        <div style="font-weight: 700; font-size: 12px; color: #166534; text-transform: uppercase; margin-bottom: 6px; display: flex; align-items: center; gap: 6px;">
                            <span>💬</span> 2. Anúncios Click-to-WhatsApp (Conversas Diretas)
                        </div>
                        <p style="margin: 0 0 8px 0; font-size: 12px; color: #334155; line-height: 1.5;">
                            Quando o cliente clica no anúncio e abre o WhatsApp, a Meta envia o identificador <code>ad_id</code> nativamente pelo webhook.
                        </p>
                        <div style="font-size: 12px; color: #15803d; line-height: 1.5; margin-bottom: 8px;">
                            • Com a permissão <code>ads_read</code> no token em <strong>Configurações &gt; Conexão Meta</strong>, o plugin consulta a Graph API da Meta e preenche automaticamente o <strong>Nome do Anúncio</strong>, <strong>Conjunto</strong> e <strong>Campanha</strong>!<br>
                            • Se algum lead antigo estiver sem nome, use o botão <strong>"Sincronizar Nomes de Anúncios Meta"</strong> no topo para puxar os dados oficiais.
                        </div>
                    </div>
                </div>
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
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <button type="button" id="li-btn-sync-all-meta-ads" class="button button-secondary" style="height: 30px; font-size: 12px; display: inline-flex; align-items: center; gap: 4px;" title="Consulta a Meta Marketing API para atualizar nomes de campanhas e anúncios de todos os leads pendentes">
                            <span class="dashicons dashicons-update" style="font-size: 14px; width: 14px; height: 14px; line-height: 14px;"></span> Sincronizar Nomes de Anúncios na Meta
                        </button>
                        <span class="li-badge" style="background: rgba(0, 129, 251, 0.15); color: #0081fb; font-weight: 700;">
                            <?php echo count($leads); ?> leads exibidos
                        </span>
                    </div>
                </div>

                <table class="wp-list-table widefat striped li-table" style="border-collapse: separate; border-spacing: 0; width: 100%;">
                    <thead>
                        <tr>
                            <th style="width: 80px; min-width: 75px; white-space: nowrap;">ID</th>
                            <th style="width: 100px; min-width: 95px; white-space: nowrap;">Data</th>
                            <th style="min-width: 150px;">Nome / Lead</th>
                            <th style="width: 160px; min-width: 150px; white-space: nowrap;">Telefone / WhatsApp</th>
                            <th style="width: 135px; min-width: 130px; white-space: nowrap;">Instância</th>
                            <th style="width: 310px; min-width: 290px;">Origem &amp; Anúncio Meta</th>
                            <th style="min-width: 140px;">Curso / Interesse</th>
                            <th style="width: 105px; white-space: nowrap;">UTM</th>
                            <th style="width: 115px; text-align: center; white-space: nowrap;">Etapa do Funil</th>
                            <th style="width: 95px; text-align: center; white-space: nowrap;">Status</th>
                            <th style="width: 230px; text-align: center; white-space: nowrap;">Qualificação &amp; Meta CAPI</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($leads)): ?>
                            <tr>
                                <td colspan="11" style="text-align: center; padding: 40px; color: #64748b; font-size: 14px;">
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
                                <tr id="li-lead-row-<?php echo esc_attr($lead->id); ?>" style="transition: background 0.15s ease;">
                                    <td style="white-space: nowrap; font-variant-numeric: tabular-nums; vertical-align: top; padding: 12px 14px;">
                                        <div style="font-size: 13px; font-weight: 800;">
                                            <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence&s=' . $lead->id)); ?>" target="_blank" style="color: #0284c7; text-decoration: none;" title="Clique para abrir este lead na tela de Todos os Leads e ver seus logs/detalhes">#<?php echo esc_html($lead->id); ?> ↗</a>
                                        </div>
                                        <?php if (!empty($lead->leadid)): ?>
                                            <div style="font-size: 10px; color: #64748b; font-weight: 600; margin-top: 2px;" title="Lead ID da Planilha / Meta Form: <?php echo esc_attr($lead->leadid); ?>">
                                                LID: <?php echo esc_html($lead->leadid); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="white-space: nowrap; vertical-align: top; padding: 12px 14px;">
                                        <div style="font-size: 12px; font-weight: 700; color: #1e293b;"><?php echo esc_html(date_i18n('d/m/Y', strtotime($lead->data_cadastro))); ?></div>
                                        <div style="font-size: 11px; color: #64748b; margin-top: 2px; font-weight: 500;">🕒 <?php echo esc_html(date_i18n('H:i', strtotime($lead->data_cadastro))); ?></div>
                                    </td>
                                    <td style="vertical-align: top; padding: 12px 14px;">
                                        <div style="font-size: 13px; font-weight: 700; color: #0f172a; word-break: break-word;">
                                            <?php echo !empty($lead->nome) ? esc_html($lead->nome) : '<span style="color:#94a3b8; font-style:italic;">Sem nome</span>'; ?>
                                        </div>
                                        <?php if (!empty($lead->email)): ?>
                                            <div style="font-size: 11px; color: #64748b; word-break: break-all; margin-top: 2px;"><?php echo esc_html($lead->email); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="white-space: nowrap; vertical-align: top; padding: 12px 14px;">
                                        <?php if (!empty($lead_phone_clean)): ?>
                                            <a href="https://wa.me/<?php echo esc_attr($lead_phone_clean); ?>" target="_blank" style="display: inline-flex; align-items: center; gap: 5px; background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; padding: 3px 8px; border-radius: 6px; font-weight: 700; font-size: 12px; text-decoration: none; transition: all 0.2s;" onmouseover="this.style.background='#dcfce7'; this.style.borderColor='#86efac';" onmouseout="this.style.background='#f0fdf4'; this.style.borderColor='#bbf7d0;'" title="Clique para abrir a conversa no WhatsApp Web / App">
                                                <span>💬</span> <?php echo esc_html(PhoneNormalizer::format_display($lead->telefone ?: $lead->telefone_normalizado)); ?>
                                            </a>
                                        <?php else: ?>
                                            <span style="color: #94a3b8; font-size: 12px;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="white-space: nowrap; vertical-align: top; padding: 12px 14px;">
                                        <?php 
                                        $inst_val = !empty($lead->instancia) ? $lead->instancia : (!empty($lead->polo) ? $lead->polo : '');
                                        ?>
                                        <?php if (!empty($inst_val)): ?>
                                            <span class="li-badge" style="background: #f8fafc; color: #334155; border: 1px solid #cbd5e1; font-weight: 600; font-size: 11px; padding: 3px 8px; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px; text-transform: capitalize;" title="Instância: <?php echo esc_attr($inst_val); ?>">
                                                <span>🏛️</span> <?php echo esc_html($inst_val); ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color: #cbd5e1; font-size: 12px;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="vertical-align: top; padding: 12px 14px;">
                                        <div style="display: flex; flex-direction: column; gap: 4px; width: 100%; max-width: 310px;">
                                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                                <?php if ($is_wa): ?>
                                                    <span class="li-badge" style="background: rgba(21, 128, 61, 0.1); color: #15803d; font-weight: 700; border: 1px solid rgba(21, 128, 61, 0.25); font-size: 10px; padding: 1px 7px;" title="Contato recebido via WhatsApp Cloud API / Click-to-WhatsApp">
                                                        💬 WhatsApp (Meta Ads)
                                                    </span>
                                                <?php else: ?>
                                                    <span class="li-badge" style="background: rgba(8, 102, 255, 0.1); color: #0866ff; font-weight: 700; border: 1px solid rgba(8, 102, 255, 0.25); font-size: 10px; padding: 1px 7px;" title="Lead gerado via Anúncio / Formulário Meta">
                                                        🔵 Anúncio / Form
                                                    </span>
                                                <?php endif; ?>

                                                <?php 
                                                $has_generic_ad = (!empty($lead->ad_name) && in_array(strtolower(trim($lead->ad_name)), ['converse conosco', 'converse conosco!', 'click-to-whatsapp', 'whatsapp cloud inbound']));
                                                $show_sync_btn = !empty($lead->ad_id) && preg_match('/^[0-9]{8,25}$/', $lead->ad_id);
                                                ?>
                                                <?php if ($show_sync_btn): ?>
                                                    <button type="button" class="li-btn-sync-single-ad" data-lead-id="<?php echo esc_attr($lead->id); ?>" style="border:none; background:none; cursor:pointer; padding:0; font-size:12px; line-height:1;" title="Sincronizar dados deste anúncio com a Meta Marketing API">🔄</button>
                                                <?php endif; ?>
                                            </div>

                                            <?php if (!empty($lead->campaign_name)): ?>
                                                <div style="background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; font-size: 11px; padding: 3px 7px; border-radius: 5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="Campanha: <?php echo esc_attr($lead->campaign_name); ?>">
                                                    📂 <strong>Campanha:</strong> <?php echo esc_html($lead->campaign_name); ?>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (!empty($lead->adset_name)): ?>
                                                <div style="background: #fefce8; color: #854d0e; border: 1px solid #fef08a; font-size: 11px; padding: 3px 7px; border-radius: 5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="Conjunto: <?php echo esc_attr($lead->adset_name); ?>">
                                                    🎯 <strong>Conjunto:</strong> <?php echo esc_html($lead->adset_name); ?>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (!empty($lead->ad_name) && !$has_generic_ad): ?>
                                                <div style="background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; font-size: 11px; padding: 3px 7px; border-radius: 5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="Anúncio: <?php echo esc_attr($lead->ad_name); ?>">
                                                    📢 <strong>Anúncio:</strong> <?php echo esc_html($lead->ad_name); ?>
                                                </div>
                                            <?php elseif (!empty($lead->ad_id) && preg_match('/^[0-9]{8,25}$/', (string) $lead->ad_id)): ?>
                                                <div style="background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; font-size: 11px; padding: 3px 7px; border-radius: 5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                                    📢 <?php echo $has_generic_ad ? 'Converse Conosco (Ad #' . esc_html($lead->ad_id) . ')' : 'Ad #' . esc_html($lead->ad_id); ?>
                                                </div>
                                            <?php endif; ?>

                                            <?php 
                                            // Link direto para visualizar o anúncio/criativo na Meta
                                            $creative_link = '';
                                            if (!empty($lead->pagina_origem) && filter_var($lead->pagina_origem, FILTER_VALIDATE_URL)) {
                                                $creative_link = $lead->pagina_origem;
                                            } elseif (!empty($lead->ad_id) && preg_match('/^[0-9]{8,25}$/', $lead->ad_id)) {
                                                $creative_link = 'https://adsmanager.facebook.com/adsmanager/manage/ads?selected_ad_ids=' . $lead->ad_id;
                                            }
                                            ?>
                                            <?php if (!empty($creative_link)): ?>
                                                <button type="button" class="li-btn-open-creative-modal button button-small" data-lead-id="<?php echo esc_attr($lead->id); ?>" style="margin-top: 2px; width: 100%; height: 26px; line-height: 24px; font-size: 11px; font-weight: 700; color: #0284c7; background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 6px; display: inline-flex; align-items: center; justify-content: center; gap: 5px; cursor: pointer; transition: all 0.2s;" onmouseover="this.style.background='#0284c7'; this.style.color='#ffffff';" onmouseout="this.style.background='#f0f9ff'; this.style.color='#0284c7';" title="Clique para ver a prévia completa do anúncio (imagem, texto e links) sem sair do WordPress">
                                                    <span>👁️ Ver Criativo do Anúncio</span>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td style="vertical-align: top; padding: 12px 14px;">
                                        <?php 
                                        $curso_txt = $lead->tipo_curso ?: ($lead->area_interesse ?: '');
                                        ?>
                                        <?php if (!empty($curso_txt)): ?>
                                            <span style="font-size: 12px; font-weight: 600; color: #334155; display: inline-block; padding: 3px 8px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 5px; word-break: break-word;">
                                                🎓 <?php echo esc_html($curso_txt); ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color: #cbd5e1; font-size: 12px;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="white-space: nowrap; vertical-align: top; padding: 12px 14px;">
                                        <?php if (!empty($source_display)): ?>
                                            <a href="<?php echo esc_url(add_query_arg(['page' => 'lead-intelligence-meta', 'view' => 'leads', 'utm_source' => $source_display, 'periodo' => $periodo], admin_url('admin.php'))); ?>" style="text-decoration: none;" title="Filtrar por utm_source = '<?php echo esc_attr($source_display); ?>'">
                                                <code style="background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; padding: 3px 8px; border-radius: 5px; font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; gap: 4px; cursor: pointer; transition: all 0.2s;" onmouseover="this.style.background='#bae6fd';" onmouseout="this.style.background='#e0f2fe';">
                                                    <span>🏷️</span> <?php echo esc_html($source_display); ?>
                                                </code>
                                            </a>
                                        <?php else: ?>
                                            <span style="color: #94a3b8; font-size: 11px; font-style: italic;">Sem UTM</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="li-col-etapa" style="text-align: center; vertical-align: top; padding: 12px 14px; white-space: nowrap;">
                                        <?php echo self::render_etapa_badge_html($lead); ?>
                                    </td>
                                    <td class="li-col-status" style="text-align: center; vertical-align: top; padding: 12px 14px; white-space: nowrap;">
                                        <?php echo self::render_status_badge_html($lead); ?>
                                    </td>
                                    <td class="li-col-actions" style="text-align: center; vertical-align: top; padding: 12px 14px; white-space: nowrap;">
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
            var ajaxNonce = '<?php echo esc_js(wp_create_nonce('li_ajax_nonce')); ?>';

            // Abrir Modal de Criativo de Alta Fidelidade (2 Colunas)
            $(document).on('click', '.li-btn-open-creative-modal', function(e) {
                e.preventDefault();
                var leadId = $(this).data('lead-id');
                var $modal = $('#li-creative-modal');
                var $body  = $('#li-modal-creative-body');
                var $links = $('#li-modal-action-links');

                $modal.css('display', 'flex');
                $body.html('<div style="text-align:center; padding: 60px 20px; color: #64748b;"><span class="dashicons dashicons-update" style="animation: rotation 1.5s infinite linear; font-size: 32px; width: 32px; height: 32px; color: #0284c7;"></span><div style="margin-top: 14px; font-weight: 600; font-size: 14px; color: #0f172a;">Carregando criativo e dados da campanha...</div><div style="font-size: 12px; color: #64748b; margin-top: 4px;">Consultando histórico do anúncio e da conversa</div></div>');
                $links.empty();

                $.post(ajaxurl, {
                    action: 'li_get_ad_creative_preview',
                    lead_id: leadId,
                    nonce: ajaxNonce
                }, function(res) {
                    if (res && res.success && res.data) {
                        var d = res.data;
                        $('#li-modal-lead-title').text(d.lead_nome);
                        var subText = (d.lead_telefone ? d.lead_telefone : '') + (d.lead_data ? ' • Chegou em ' + d.lead_data : '') + (d.ad_id ? ' • Ad ID: ' + d.ad_id : '');
                        $('#li-modal-lead-sub').text(subText);

                        var html = '';
                        html += '<div id="li-modal-creative-grid" style="display: grid; grid-template-columns: minmax(320px, 420px) 1fr; gap: 24px; align-items: start;">';

                        // ================= COLUNA 1: FEED MOCKUP (META ADS / INSTAGRAM / FACEBOOK) =================
                        html += '  <div style="display: flex; flex-direction: column; gap: 12px;">';
                        html += '    <div style="font-size: 12px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px; display: flex; align-items: center; justify-content: space-between;">';
                        html += '      <span>📱 Mockup do Anúncio (Feed)</span>';
                        html += '      <span style="background: #e0f2fe; color: #0369a1; padding: 2px 7px; border-radius: 4px; font-size: 10px; font-weight: 700;">Click-to-WhatsApp</span>';
                        html += '    </div>';

                        html += '    <div style="border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; background: #ffffff; box-shadow: 0 4px 16px -2px rgba(15,23,42,0.06);">';
                        
                        // Header do Anúncio (Página Real do Facebook / Instagram)
                        var pageTitle = d.page_name || 'Faculdade FAVENI';
                        var pagePicHtml = '';
                        if (d.page_picture) {
                            pagePicHtml = '<img src="' + d.page_picture + '" style="width: 38px; height: 38px; border-radius: 50%; object-fit: cover; border: 1px solid #e2e8f0; display: block;" alt="' + $('<div>').text(pageTitle).html() + '">';
                        } else {
                            var initial = (pageTitle ? pageTitle.charAt(0).toUpperCase() : 'F');
                            pagePicHtml = '<div style="width: 38px; height: 38px; border-radius: 50%; background: linear-gradient(135deg, #0081fb, #0056b3); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 15px; box-shadow: 0 2px 4px rgba(0,129,251,0.2);">' + initial + '</div>';
                        }

                        html += '      <div style="padding: 12px 14px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #f1f5f9;">';
                        html += '        <div style="display: flex; align-items: center; gap: 10px;">';
                        html += '          ' + pagePicHtml;
                        html += '          <div>';
                        html += '            <div style="font-size: 13px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 4px;">' + $('<div>').text(pageTitle).html() + ' <span style="color: #0081fb; font-size: 12px;" title="Verificado">✔</span></div>';
                        html += '            <div style="font-size: 11px; color: #64748b;">Patrocinado &bull; 🌐</div>';
                        html += '          </div>';
                        html += '        </div>';
                        html += '        <div style="color: #94a3b8; font-size: 16px; cursor: default;">•••</div>';
                        html += '      </div>';

                        // Mídia do Criativo (Imagem ou Vídeo)
                        if (d.image_url) {
                            html += '      <div style="background: #f8fafc; border-top: 1px solid #f1f5f9; border-bottom: 1px solid #f1f5f9; text-align: center; position: relative;">';
                            html += '        <img src="' + d.image_url + '" style="width: 100%; max-height: 440px; display: block; margin: 0 auto; object-fit: contain;" alt="Criativo do Anúncio">';
                            html += '      </div>';
                        } else if (d.video_url) {
                            html += '      <div style="background: #0f172a; text-align: center;">';
                            html += '        <video src="' + d.video_url + '" controls style="width: 100%; max-height: 440px; display: block;"></video>';
                            html += '      </div>';
                        } else {
                            html += '      <div style="background: #f8fafc; padding: 40px 20px; text-align: center; color: #64748b; border-top: 1px solid #f1f5f9; border-bottom: 1px solid #f1f5f9;">';
                            html += '        <span style="font-size: 40px;">📢</span>';
                            html += '        <div style="margin-top: 8px; font-weight: 700; color: #0f172a;">' + (d.media_type ? 'Formato: ' + d.media_type : 'Anúncio de Mensagem') + '</div>';
                            html += '        <div style="font-size: 11px; color: #94a3b8; margin-top: 2px;">Mídia nativa Click-to-WhatsApp</div>';
                            html += '      </div>';
                        }

                        // CTA Bar do WhatsApp
                        html += '      <div style="padding: 12px 14px; background: #ffffff; display: flex; justify-content: space-between; align-items: center; gap: 10px;">';
                        html += '        <div style="min-width: 0; flex: 1;">';
                        html += '          <div style="font-size: 10px; color: #64748b; text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px;">WHATSAPP</div>';
                        html += '          <div style="font-size: 13px; font-weight: 700; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">' + $('<div>').text(d.headline || 'Converse conosco').html() + '</div>';
                        html += '        </div>';
                        html += '        <div style="background: #25d366; color: #ffffff; padding: 7px 14px; border-radius: 6px; font-size: 12px; font-weight: 700; display: inline-flex; align-items: center; gap: 5px; box-shadow: 0 2px 4px rgba(37,211,102,0.25); white-space: nowrap;">💬 Enviar mensagem</div>';
                        html += '      </div>';

                        html += '    </div>'; // fim do feed card

                        if (d.image_url) {
                            html += '    <div style="text-align: right;">';
                            html += '      <a href="' + d.image_url + '" target="_blank" style="font-size: 11px; color: #0284c7; text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">';
                            html += '        <span>🔍 Abrir imagem em alta resolução</span> <span class="dashicons dashicons-external" style="font-size:11px; width:11px; height:11px;"></span>';
                            html += '      </a>';
                            html += '    </div>';
                        }

                        html += '  </div>'; // fim da coluna 1

                        // ================= COLUNA 2: INTELIGÊNCIA DO LEAD & ESTRUTURA DO ANÚNCIO =================
                        html += '  <div style="display: flex; flex-direction: column; gap: 16px;">';

                        // 1. Perfil do Lead que Clicou
                        html += '    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; box-shadow: 0 1px 3px rgba(15,23,42,0.04);">';
                        html += '      <div style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 10px; display: flex; align-items: center; justify-content: space-between;">';
                        html += '        <span>👤 Lead que Clicou no Anúncio</span>';
                        if (d.lead_data) {
                            html += '        <span style="font-weight: 500; text-transform: none; color: #64748b;">📅 ' + d.lead_data + '</span>';
                        }
                        html += '      </div>';

                        html += '      <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">';
                        html += '        <div>';
                        html += '          <div style="font-size: 16px; font-weight: 700; color: #0f172a;">' + $('<div>').text(d.lead_nome).html() + '</div>';
                        html += '          <div style="font-size: 13px; color: #334155; margin-top: 2px;">' + (d.lead_telefone || 'Sem telefone') + '</div>';
                        if (d.lead_polo) {
                            html += '          <div style="margin-top: 6px;"><span class="li-badge" style="background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; font-size: 11px; padding: 2px 7px; border-radius: 4px;">🏛️ Polo/Instância: ' + $('<div>').text(d.lead_polo).html() + '</span></div>';
                        }
                        html += '        </div>';

                        if (d.lead_telefone_raw) {
                            html += '        <div>';
                            html += '          <a href="https://wa.me/' + d.lead_telefone_raw + '" target="_blank" class="button" style="background: #25d366; color: #ffffff; border: none; font-weight: 700; font-size: 12px; height: 34px; line-height: 34px; padding: 0 14px; border-radius: 6px; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; box-shadow: 0 2px 4px rgba(37,211,102,0.3);">';
                            html += '            <span>💬 Conversar no WhatsApp</span>';
                            html += '          </a>';
                            html += '        </div>';
                        }
                        html += '      </div>';

                        // Balão de Mensagem Inicial do Lead
                        if (d.first_message) {
                            html += '      <div style="margin-top: 14px; padding: 10px 12px; background: #f0fdf4; border-left: 3px solid #22c55e; border-radius: 0 8px 8px 0; font-size: 12px; color: #166534;">';
                            html += '        <div style="font-weight: 700; margin-bottom: 2px;">💬 Mensagem enviada pelo Lead ao entrar no WhatsApp:</div>';
                            html += '        <div style="font-style: italic;">"' + $('<div>').text(d.first_message).html() + '"</div>';
                            html += '      </div>';
                        }
                        html += '    </div>';

                        // 2. Texto / Copy do Anúncio (com botão Copiar)
                        if (d.body_text) {
                            html += '    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; box-shadow: 0 1px 3px rgba(15,23,42,0.04);">';
                            html += '      <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">';
                            html += '        <div style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">📝 Texto / Copy do Anúncio</div>';
                            html += '        <button type="button" class="button button-small" id="li-btn-copy-body-copy" style="font-size: 11px; display: inline-flex; align-items: center; gap: 4px;">📋 Copiar Texto da Copy</button>';
                            html += '      </div>';
                            html += '      <div id="li-body-copy-text" style="font-size: 13px; color: #1e293b; line-height: 1.6; white-space: pre-wrap; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px; max-height: 180px; overflow-y: auto;">' + $('<div>').text(d.body_text).html() + '</div>';
                            html += '    </div>';
                        }

                        // 3. Estrutura de Tráfego Pago (Meta Marketing Hierarchy)
                        html += '    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; box-shadow: 0 1px 3px rgba(15,23,42,0.04);">';
                        html += '      <div style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 12px;">📊 Arquitetura de Tráfego Pago (Meta Ads)</div>';
                        
                        html += '      <div style="display: flex; flex-direction: column; gap: 10px; font-size: 12px;">';
                        
                        // Página / Perfil Oficial da Meta
                        if (d.page_name) {
                            html += '        <div style="padding: 8px 10px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px;">';
                            html += '          <div style="font-size: 10px; font-weight: 700; color: #475569; text-transform: uppercase;">📄 Página / Conta Meta que Anunciou:</div>';
                            html += '          <div style="font-weight: 700; color: #0f172a; word-break: break-word; display: flex; align-items: center; gap: 8px; margin-top: 4px;">';
                            if (d.page_picture) {
                                html += '            <img src="' + d.page_picture + '" style="width: 22px; height: 22px; border-radius: 50%; object-fit: cover; border: 1px solid #cbd5e1;">';
                            }
                            html += '            <span>' + $('<div>').text(d.page_name).html() + '</span>';
                            html += '          </div>';
                            html += '        </div>';
                        }

                        // Campanha
                        html += '        <div style="padding: 8px 10px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px;">';
                        html += '          <div style="font-size: 10px; font-weight: 700; color: #15803d; text-transform: uppercase;">📂 Campanha:</div>';
                        html += '          <div style="font-weight: 600; color: #166534; word-break: break-word;">' + $('<div>').text(d.campaign_name || 'Campanha não identificada').html() + '</div>';
                        html += '        </div>';

                        // Conjunto
                        html += '        <div style="padding: 8px 10px; background: #fefce8; border: 1px solid #fef08a; border-radius: 6px;">';
                        html += '          <div style="font-size: 10px; font-weight: 700; color: #a16207; text-transform: uppercase;">🎯 Conjunto de Anúncios:</div>';
                        html += '          <div style="font-weight: 600; color: #854d0e; word-break: break-word;">' + $('<div>').text(d.adset_name || 'Conjunto não identificado').html() + '</div>';
                        html += '        </div>';

                        // Anúncio
                        html += '        <div style="padding: 8px 10px; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 6px;">';
                        html += '          <div style="font-size: 10px; font-weight: 700; color: #1d4ed8; text-transform: uppercase;">📢 Anúncio:</div>';
                        html += '          <div style="font-weight: 600; color: #1e40af; word-break: break-word;">' + $('<div>').text(d.ad_name || 'Anúncio sem nome').html() + '</div>';
                        html += '        </div>';

                        // Ad ID
                        html += '        <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 10px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px;">';
                        html += '          <div><span style="font-weight: 700; color: #475569;">🆔 Ad ID Meta:</span> <code style="font-weight: 700; color: #0f172a; font-size: 12px;">' + (d.ad_id || 'N/D') + '</code></div>';
                        if (d.ad_id) {
                            html += '          <button type="button" class="button button-small" id="li-copy-ad-id-btn" style="font-size: 11px;">📋 Copiar ID</button>';
                        }
                        html += '        </div>';

                        html += '      </div>';
                        html += '    </div>';

                        html += '  </div>'; // fim da coluna 2

                        html += '</div>'; // fim do grid

                        $body.html(html);

                        // Ações de Cópia
                        $('#li-copy-ad-id-btn').on('click', function() {
                            navigator.clipboard.writeText(d.ad_id).then(function() {
                                alert('Ad ID ' + d.ad_id + ' copiado com sucesso! Você pode colar no campo de busca do Gerenciador de Anúncios.');
                            });
                        });

                        $('#li-btn-copy-body-copy').on('click', function() {
                            if (d.body_text) {
                                navigator.clipboard.writeText(d.body_text).then(function() {
                                    alert('Texto da copy copiado com sucesso!');
                                });
                            }
                        });

                        // Botões de Ação no Rodapé
                        if (d.source_url) {
                            $links.append('<a href="' + d.source_url + '" target="_blank" class="button button-secondary" style="display: inline-flex; align-items: center; gap: 4px; font-weight: 600;">🔗 Link Direto do Post <span class="dashicons dashicons-external" style="font-size:11px; width:11px; height:11px;"></span></a>');
                        }
                        if (d.ads_manager_url) {
                            $links.append('<a href="' + d.ads_manager_url + '" target="_blank" class="button button-primary" style="display: inline-flex; align-items: center; gap: 5px; font-weight: 700; background: #0081fb; border-color: #0070da;">📊 Abrir no Gerenciador de Anúncios <span class="dashicons dashicons-external" style="font-size:11px; width:11px; height:11px;"></span></a>');
                        }
                    } else {
                        $body.html('<div style="padding: 40px; color: #b91c1c; text-align: center; font-weight: 600;">Não foi possível carregar os detalhes deste anúncio. Verifique se o lead possui Ad ID associado.</div>');
                    }
                }).fail(function() {
                    $body.html('<div style="padding: 40px; color: #b91c1c; text-align: center; font-weight: 600;">Erro de rede ao conectar à Meta API.</div>');
                });
            });

            // Fechar Modal
            $('#li-close-creative-modal, #li-modal-btn-close').on('click', function() {
                $('#li-creative-modal').hide();
            });
            $(document).on('click', '#li-creative-modal', function(e) {
                if ($(e.target).is('#li-creative-modal')) {
                    $('#li-creative-modal').hide();
                }
            });

            $('#li-btn-sync-all-meta-ads').on('click', function() {
                var $btn = $(this);
                $btn.prop('disabled', true).text('⏳ Sincronizando com a Meta...');
                $.post(ajaxurl, {
                    action: 'li_sync_all_ads',
                    nonce: ajaxNonce
                }, function(res) {
                    if (res && res.success && res.data) {
                        alert(res.data.message || 'Sincronização concluída!');
                        window.location.reload();
                    } else {
                        alert('Aviso: ' + ((res && res.data && res.data.message) ? res.data.message : 'Falha na comunicação com a Meta.'));
                        $btn.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> Sincronizar Nomes de Anúncios na Meta');
                    }
                }).fail(function() {
                    alert('Erro de rede ao conectar à API do WordPress.');
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> Sincronizar Nomes de Anúncios na Meta');
                });
            });

            // Sincronizar anúncio de um lead individual
            $(document).on('click', '.li-btn-sync-single-ad', function(e) {
                e.preventDefault();
                var $btn = $(this);
                var leadId = $btn.data('lead-id');
                $btn.text('⏳');
                $.post(ajaxurl, {
                    action: 'li_sync_lead_ad',
                    lead_id: leadId,
                    nonce: ajaxNonce
                }, function(res) {
                    if (res && res.success) {
                        alert(res.data.message || 'Anúncio sincronizado!');
                        window.location.reload();
                    } else {
                        alert('Não foi possível obter dados na Meta: ' + ((res && res.data && res.data.message) ? res.data.message : 'Verifique se o token tem a permissão ads_read.'));
                        $btn.text('🔄');
                    }
                }).fail(function() {
                    alert('Erro de requisição.');
                    $btn.text('🔄');
                });
            });

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
    
    <!-- MODAL DE PRÉVIA DO CRIATIVO META ADS DE ALTA FIDELIDADE (2 COLUNAS) -->
    <style>
    #li-creative-modal {
        box-sizing: border-box;
    }
    #li-creative-modal * {
        box-sizing: border-box;
    }
    #li-creative-modal-dialog {
        width: 95vw;
        max-width: 980px;
        height: 86vh;
        max-height: 860px;
        min-height: 520px;
        display: flex;
        flex-direction: column;
        background: #ffffff;
        border-radius: 16px;
        box-shadow: 0 25px 60px -15px rgba(15, 23, 42, 0.55);
        overflow: hidden;
        position: relative;
    }
    #li-modal-creative-header {
        flex-shrink: 0;
        padding: 16px 24px;
        border-bottom: 1px solid #e2e8f0;
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: #ffffff;
    }
    #li-modal-creative-body {
        flex: 1 1 auto;
        min-height: 0;
        height: 100%;
        overflow-y: auto;
        -webkit-overflow-scrolling: touch;
        padding: 24px;
        background: #f8fafc;
        width: 100%;
    }
    #li-modal-creative-footer {
        flex-shrink: 0;
        padding: 14px 24px;
        border-top: 1px solid #e2e8f0;
        background: #ffffff;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
    }
    @media (max-width: 860px) {
        #li-creative-modal-dialog {
            width: 98vw;
            height: 94vh;
            max-height: 94vh;
        }
        #li-modal-creative-grid {
            grid-template-columns: 1fr !important;
        }
    }
    </style>
    <div id="li-creative-modal" style="display: none; position: fixed; z-index: 100000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(4px); align-items: center; justify-content: center;">
        <div id="li-creative-modal-dialog">
            <!-- Modal Header -->
            <div id="li-modal-creative-header">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <div style="width: 40px; height: 40px; border-radius: 10px; background: linear-gradient(135deg, #0081fb, #25d366); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 20px; box-shadow: 0 2px 6px rgba(0,129,251,0.25); flex-shrink: 0;">
                        🎨
                    </div>
                    <div>
                        <h4 style="margin: 0; font-size: 16px; font-weight: 700; color: #0f172a; line-height: 1.3;" id="li-modal-lead-title">Criativo do Anúncio & Detalhes do Lead</h4>
                        <p style="margin: 3px 0 0; font-size: 11px; color: #64748b; line-height: 1.3;" id="li-modal-lead-sub">Meta Ads &bull; Click-to-WhatsApp</p>
                    </div>
                </div>
                <button type="button" id="li-close-creative-modal" style="border: none; background: #f1f5f9; width: 34px; height: 34px; border-radius: 50%; font-size: 18px; font-weight: 700; cursor: pointer; color: #475569; display: flex; align-items: center; justify-content: center; padding: 0; transition: all 0.2s;" onmouseover="this.style.background='#e2e8f0'; this.style.color='#0f172a';" onmouseout="this.style.background='#f1f5f9'; this.style.color='#475569;'">&times;</button>
            </div>
            
            <!-- Modal Body (Preview Mockup + Detalhes em 2 Colunas) -->
            <div id="li-modal-creative-body">
                <div style="text-align: center; padding: 40px; color: #64748b;">Carregando criativo...</div>
            </div>

            <!-- Modal Footer -->
            <div id="li-modal-creative-footer">
                <button type="button" class="button button-secondary" id="li-modal-btn-close" style="font-weight: 600;">Fechar</button>
                <div style="display: flex; gap: 8px; flex-wrap: wrap;" id="li-modal-action-links"></div>
            </div>
        </div>
    </div>

    <?php
    }
}
