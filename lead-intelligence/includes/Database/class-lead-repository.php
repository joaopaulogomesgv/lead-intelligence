<?php
namespace LeadIntelligence\Database;

use LeadIntelligence\PhoneNormalizer;
use LeadIntelligence\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Repositório de Acesso a Dados e Operações de Leads
 */
class LeadRepository {

    /**
     * Localiza lead por telefone considerando variações de 9º dígito e DDI
     *
     * @param string $phone
     * @return object|null
     */
    public static function find_by_phone($phone) {
        global $wpdb;
        $variations = PhoneNormalizer::get_lookup_variations($phone);
        if (empty($variations)) {
            return null;
        }

        $table = DbSchema::get_leads_table();
        $placeholders = implode(',', array_fill(0, count($variations), '%s'));

        // Query ultrarrápida: aproveita os índices de telefone_normalizado e telefone (tempo < 0.1ms)
        $query = $wpdb->prepare(
            "SELECT * FROM {$table} 
             WHERE telefone_normalizado IN ({$placeholders}) 
                OR telefone IN ({$placeholders}) 
             ORDER BY id DESC LIMIT 1",
            array_merge($variations, $variations)
        );

        $row = $wpdb->get_row($query);

        // Auto-cura em memória: garante telefone_normalizado disponível
        if ($row && empty($row->telefone_normalizado)) {
            $row->telefone_normalizado = PhoneNormalizer::normalize($row->telefone ?: $phone);
        }

        return $row;
    }

    /**
     * Localiza lead por e-mail (tolerante a maiúsculas e espaços com índice)
     *
     * @param string $email
     * @return object|null
     */
    public static function find_by_email($email) {
        global $wpdb;
        $clean_email = strtolower(trim((string) $email));
        if (empty($clean_email)) {
            return null;
        }

        $table = DbSchema::get_leads_table();
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE email = %s OR email = %s ORDER BY id DESC LIMIT 1",
            $clean_email,
            (string) $email
        ));
    }

    /**
     * Localiza lead por ID
     *
     * @param int $id
     * @return object|null
     */
    public static function find_by_id($id) {
        global $wpdb;
        $table = DbSchema::get_leads_table();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));
    }

    /**
     * Cria ou atualiza lead preservando dados anteriores e gravando histórico de auditoria
     *
     * @param array $data
     * @param string $origem 'elementor', 'spreadsheet', 'whatsapp', 'manual'
     * @return int ID do lead
     */
    public static function save_lead($data, $origem = 'elementor') {
        global $wpdb;
        $table = DbSchema::get_leads_table();
        $history_table = DbSchema::get_history_table();

        $telefone = isset($data['telefone']) ? sanitize_text_field($data['telefone']) : '';
        $tel_normalizado = !empty($telefone) ? PhoneNormalizer::normalize($telefone) : '';
        $email = isset($data['email']) ? sanitize_email($data['email']) : '';

        // Tenta encontrar lead existente: prioridade 1 = telefone, prioridade 2 = email
        $existing = null;
        if (!empty($tel_normalizado)) {
            $existing = self::find_by_phone($tel_normalizado);
        }
        if (!$existing && !empty($email)) {
            $existing = self::find_by_email($email);
        }

        $current_user_id = get_current_user_id();

        if ($existing) {
            $lead_id = (int) $existing->id;
            $update_fields = [];
            $history_entries = [];

            // Compara campos e atualiza apenas os pertinentes
            foreach ($data as $key => $val) {
                // Não altera id, uuid ou created_at
                if (in_array($key, ['id', 'uuid', 'created_at', 'data_cadastro'])) {
                    continue;
                }

                $old_val = isset($existing->$key) ? (string) $existing->$key : '';
                $new_val = is_array($val) ? wp_json_encode($val) : (string) $val;

                // Se o valor novo não for vazio e for diferente do anterior
                if ($new_val !== '' && $new_val !== $old_val) {
                    $update_fields[$key] = $val;

                    // Registra no histórico se o campo antigo já continha valor
                    if ($old_val !== '') {
                        $history_entries[] = [
                            'lead_id'        => $lead_id,
                            'campo'          => $key,
                            'valor_anterior' => $old_val,
                            'valor_novo'     => $new_val,
                            'origem'         => $origem,
                            'usuario_id'     => $current_user_id,
                            'created_at'     => current_time('mysql'),
                        ];
                    }
                }
            }

            // Sempre garante telefone normalizado se fornecido
            if (!empty($tel_normalizado) && empty($existing->telefone_normalizado)) {
                $update_fields['telefone_normalizado'] = $tel_normalizado;
            }

            // Preservação de ciclo longo (First Touch & Lead Time)
            if ($origem === 'elementor') {
                if (empty($existing->utm_source_first)) {
                    $update_fields['utm_source_first'] = !empty($data['utm_source']) ? $data['utm_source'] : (!empty($existing->utm_source) ? $existing->utm_source : '');
                }
                if (empty($existing->data_primeira_captura)) {
                    $update_fields['data_primeira_captura'] = !empty($existing->data_cadastro) ? $existing->data_cadastro : current_time('mysql');
                }
                if (!empty($existing->qualificacao_data)) {
                    $t_first = strtotime($update_fields['data_primeira_captura'] ?? $existing->data_cadastro);
                    $t_qual  = strtotime($existing->qualificacao_data);
                    if ($t_qual >= $t_first) {
                        $update_fields['dias_para_conversao'] = (int) floor(($t_qual - $t_first) / 86400);
                    }
                }
            }

            if (!empty($update_fields)) {
                $update_fields['updated_at'] = current_time('mysql');
                $wpdb->update($table, $update_fields, ['id' => $lead_id]);

                // Salva histórico de auditoria
                foreach ($history_entries as $entry) {
                    $wpdb->insert($history_table, $entry);
                }
            }

            Logger::debug('Leads', "Lead existente #{$lead_id} atualizado via {$origem}.", [
                'telefone' => $tel_normalizado,
                'email'    => $email,
                'campos'   => array_keys($update_fields),
            ]);

            return $lead_id;
        }

        // Novo Lead
        $uuid = wp_generate_uuid4();
        $insert_data = array_merge([
            'uuid'                 => $uuid,
            'telefone_normalizado' => $tel_normalizado,
            'qualificacao_status'  => 'pendente',
            'data_cadastro'        => current_time('mysql'),
            'created_at'           => current_time('mysql'),
            'updated_at'           => current_time('mysql'),
        ], $data);

        // Se telefone normalizado foi calculado, assegura consistência
        if (!empty($tel_normalizado)) {
            $insert_data['telefone_normalizado'] = $tel_normalizado;
        }

        $wpdb->insert($table, $insert_data);
        $lead_id = $wpdb->insert_id;

        // Histórico de criação inicial
        $wpdb->insert($history_table, [
            'lead_id'        => $lead_id,
            'campo'          => 'criacao',
            'valor_anterior' => '',
            'valor_novo'     => "Lead criado via {$origem}",
            'origem'         => $origem,
            'usuario_id'     => $current_user_id,
            'created_at'     => current_time('mysql'),
        ]);

        Logger::info('Leads', "Novo lead #{$lead_id} cadastrado com sucesso via {$origem}.", [
            'nome'     => isset($data['nome']) ? $data['nome'] : '',
            'telefone' => $tel_normalizado,
            'email'    => $email,
        ]);

        return $lead_id;
    }

    /**
     * Busca leads com paginação e filtros
     */
    public static function get_leads($args = []) {
        global $wpdb;
        $table = DbSchema::get_leads_table();

        $defaults = [
            'page'         => 1,
            'per_page'     => 20,
            'search'       => '',
            'status'       => '',
            'campaign'     => '',
            'utm_source'   => '',
            'orderby'      => 'id',
            'order'        => 'DESC',
            'date_from'    => '',
            'date_to'      => '',
        ];

        $args = wp_parse_args($args, $defaults);
        $where = ['1=1'];
        $params = [];

        if (!empty($args['search'])) {
            $search = '%' . $wpdb->esc_like(trim($args['search'])) . '%';
            $where[] = "(nome LIKE %s OR email LIKE %s OR telefone LIKE %s OR telefone_normalizado LIKE %s OR area_interesse LIKE %s OR utm_source LIKE %s OR utm_campaign LIKE %s OR pagina_origem LIKE %s)";
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        if (!empty($args['utm_source'])) {
            $src_val = trim($args['utm_source']);
            $src_like = '%' . $wpdb->esc_like($src_val) . '%';
            $where[] = "(utm_source = %s OR utm_source_first = %s OR utm_source LIKE %s OR pagina_origem LIKE %s)";
            $params[] = $src_val;
            $params[] = $src_val;
            $params[] = $src_like;
            $params[] = '%' . $wpdb->esc_like('utm_source=' . $src_val) . '%';
        }

        if (!empty($args['status'])) {
            $where[] = "qualificacao_status = %s";
            $params[] = $args['status'];
        }

        if (!empty($args['campaign'])) {
            $where[] = "utm_campaign = %s";
            $params[] = $args['campaign'];
        }

        if (!empty($args['date_from'])) {
            $where[] = "data_cadastro >= %s";
            $params[] = $args['date_from'] . ' 00:00:00';
        }

        if (!empty($args['date_to'])) {
            $where[] = "data_cadastro <= %s";
            $params[] = $args['date_to'] . ' 23:59:59';
        }

        if (!empty($args['channel'])) {
            $chan = sanitize_key($args['channel']);
            $chan_sql = self::get_channel_sql_condition($chan);
            if (!empty($chan_sql)) {
                $where[] = $chan_sql;
            }
        }

        $where_sql = implode(' AND ', $where);

        $allowed_orderby = ['id', 'nome', 'email', 'data_cadastro', 'qualificacao_status', 'score'];
        $orderby = in_array($args['orderby'], $allowed_orderby) ? $args['orderby'] : 'id';
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

        $offset = ($args['page'] - 1) * $args['per_page'];

        // Total de registros
        $count_query = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        if (!empty($params)) {
            $count_query = $wpdb->prepare($count_query, $params);
        }
        $total = (int) $wpdb->get_var($count_query);

        // Dados
        $data_query = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $data_params = array_merge($params, [$args['per_page'], $offset]);
        $items = $wpdb->get_results($wpdb->prepare($data_query, $data_params));

        return [
            'items'       => $items,
            'total'       => $total,
            'pages'       => ceil($total / $args['per_page']),
            'page'        => $args['page'],
            'per_page'    => $args['per_page'],
        ];
    }

    /**
     * Retorna a expressão SQL unificada e segura para classificar leads por canal
     *
     * @param string $channel 'google_ads' | 'meta_ads' | 'whatsapp' | 'organico'
     * @return string Cláusula SQL para WHERE ou CASE WHEN
     */
    public static function get_channel_sql_condition($channel) {
        $chan = sanitize_key($channel);

        // Condição para leads cuja origem cadastral é estritamente uma planilha
        $planilha_condition = "(
            (formulario_nome IS NOT NULL AND INSTR(LOWER(formulario_nome), 'planilha') > 0) OR
            (pagina_origem IS NOT NULL AND INSTR(LOWER(pagina_origem), 'planilha:') > 0) OR
            (utm_source IS NOT NULL AND (LOWER(utm_source) IN ('planilha', 'planilha_google', 'planilha_meta', 'planilha_upload', 'planilha_sheets') OR INSTR(LOWER(utm_source), 'planilha') > 0)) OR
            (qualificacao_origem IS NOT NULL AND INSTR(LOWER(qualificacao_origem), 'planilha:') > 0 AND (formulario_id IS NULL OR formulario_id = '' OR LOWER(formulario_id) LIKE '%planilha%')) OR
            (motivo_qualificacao IS NOT NULL AND INSTR(LOWER(motivo_qualificacao), 'planilha') > 0 AND (formulario_id IS NULL OR formulario_id = ''))
        )";

        if ($chan === 'planilha') {
            return $planilha_condition;
        }

        if ($chan === 'planilha_google') {
            return "({$planilha_condition} AND (INSTR(LOWER(COALESCE(formulario_nome, '')), 'google') > 0 OR INSTR(LOWER(COALESCE(utm_source, '')), 'google') > 0))";
        }

        // Condição para leads vindos da Extensão Nativa de Formulário do Google Ads ou Campanhas de Formulário Google (Google Lead Form)
        $google_lead_form_condition = "(
            LOWER(COALESCE(utm_source, '')) IN ('google_lead_form', 'google-lead-form', 'lead_form') OR
            INSTR(LOWER(COALESCE(utm_source, '')), 'lead_form') > 0 OR
            LOWER(COALESCE(utm_medium, '')) IN ('lead_form', 'leadform', 'lead-form') OR
            INSTR(LOWER(COALESCE(formulario_nome, '')), 'lead form') > 0 OR
            INSTR(LOWER(COALESCE(formulario_nome, '')), 'leadform') > 0 OR
            INSTR(LOWER(COALESCE(formulario_nome, '')), 'formulario google') > 0 OR
            INSTR(LOWER(COALESCE(formulario_nome, '')), 'formulário google') > 0 OR
            INSTR(LOWER(COALESCE(formulario_nome, '')), 'formulario do google') > 0 OR
            INSTR(LOWER(COALESCE(formulario_nome, '')), 'formulário do google') > 0 OR
            INSTR(LOWER(COALESCE(formulario_nome, '')), 'google lead form') > 0 OR
            INSTR(LOWER(COALESCE(formulario_nome, '')), 'google form') > 0 OR
            INSTR(LOWER(COALESCE(pagina_origem, '')), 'lead form') > 0 OR
            INSTR(LOWER(COALESCE(pagina_origem, '')), 'formulario google') > 0 OR
            INSTR(LOWER(COALESCE(pagina_origem, '')), 'formulário google') > 0 OR
            INSTR(LOWER(COALESCE(pagina_origem, '')), 'google_lead_form') > 0 OR
            INSTR(LOWER(COALESCE(pagina_origem, '')), 'google form') > 0 OR
            INSTR(LOWER(COALESCE(utm_campaign, '')), 'formulario google') > 0 OR
            INSTR(LOWER(COALESCE(utm_campaign, '')), 'formulário google') > 0 OR
            INSTR(LOWER(COALESCE(utm_campaign, '')), 'formulario_google') > 0 OR
            INSTR(LOWER(COALESCE(utm_campaign, '')), 'formulario-google') > 0 OR
            INSTR(LOWER(COALESCE(utm_campaign, '')), 'formulario do google') > 0 OR
            INSTR(LOWER(COALESCE(utm_campaign, '')), 'formulário do google') > 0 OR
            INSTR(LOWER(COALESCE(utm_campaign, '')), 'google_lead_form') > 0 OR
            INSTR(LOWER(COALESCE(utm_campaign, '')), 'google-lead-form') > 0 OR
            INSTR(LOWER(COALESCE(utm_campaign, '')), 'lead_form') > 0 OR
            INSTR(LOWER(COALESCE(utm_campaign, '')), 'lead form') > 0 OR
            INSTR(LOWER(COALESCE(utm_campaign, '')), 'leadform') > 0 OR
            INSTR(LOWER(COALESCE(utm_campaign, '')), 'form google') > 0 OR
            INSTR(LOWER(COALESCE(utm_campaign, '')), 'form_google') > 0 OR
            INSTR(LOWER(COALESCE(utm_campaign, '')), 'google form') > 0 OR
            INSTR(LOWER(COALESCE(campaign_name, '')), 'formulario google') > 0 OR
            INSTR(LOWER(COALESCE(campaign_name, '')), 'formulário google') > 0 OR
            INSTR(LOWER(COALESCE(campaign_name, '')), 'formulario_google') > 0 OR
            INSTR(LOWER(COALESCE(campaign_name, '')), 'formulario-google') > 0 OR
            INSTR(LOWER(COALESCE(campaign_name, '')), 'formulario do google') > 0 OR
            INSTR(LOWER(COALESCE(campaign_name, '')), 'formulário do google') > 0 OR
            INSTR(LOWER(COALESCE(campaign_name, '')), 'google_lead_form') > 0 OR
            INSTR(LOWER(COALESCE(campaign_name, '')), 'google-lead-form') > 0 OR
            INSTR(LOWER(COALESCE(campaign_name, '')), 'lead_form') > 0 OR
            INSTR(LOWER(COALESCE(campaign_name, '')), 'lead form') > 0 OR
            INSTR(LOWER(COALESCE(campaign_name, '')), 'leadform') > 0 OR
            INSTR(LOWER(COALESCE(campaign_name, '')), 'form google') > 0 OR
            INSTR(LOWER(COALESCE(campaign_name, '')), 'form_google') > 0 OR
            INSTR(LOWER(COALESCE(campaign_name, '')), 'google form') > 0
        )";

        if ($chan === 'google_lead_form' || $chan === 'lead_form') {
            return $google_lead_form_condition;
        }

        $google_raw = "(
            (gclid IS NOT NULL AND TRIM(gclid) != '') OR
            (gad_source IS NOT NULL AND TRIM(gad_source) != '') OR
            (gbraid IS NOT NULL AND TRIM(gbraid) != '') OR
            (wbraid IS NOT NULL AND TRIM(wbraid) != '') OR
            LOWER(COALESCE(utm_source, '')) IN ('google', 'google_ads', 'google-ads', 'gads', 'adwords', 'googleads', 'google_cpc', 'google_search') OR
            INSTR(LOWER(COALESCE(utm_source, '')), 'google') > 0 OR
            INSTR(LOWER(COALESCE(utm_source, '')), 'gads') > 0 OR
            INSTR(LOWER(COALESCE(utm_source, '')), 'clique') > 0 OR
            INSTR(LOWER(COALESCE(utm_source, '')), 'aba-') > 0 OR
            INSTR(LOWER(COALESCE(utm_source, '')), 'search') > 0 OR
            INSTR(LOWER(COALESCE(utm_source, '')), 'pesquisa') > 0 OR
            INSTR(LOWER(COALESCE(utm_source, '')), 'pmax') > 0 OR
            INSTR(LOWER(COALESCE(utm_medium, '')), 'google') > 0 OR
            (LOWER(COALESCE(utm_medium, '')) IN ('cpc', 'ppc', 'search', 'pmax', 'display') AND INSTR(LOWER(COALESCE(utm_source, '')), 'meta') = 0 AND INSTR(LOWER(COALESCE(utm_source, '')), 'face') = 0 AND INSTR(LOWER(COALESCE(utm_source, '')), 'insta') = 0) OR
            INSTR(LOWER(COALESCE(utm_campaign, '')), 'google') > 0 OR
            INSTR(LOWER(COALESCE(utm_campaign, '')), 'gads') > 0 OR
            INSTR(LOWER(COALESCE(formulario_nome, '')), 'google') > 0 OR
            INSTR(LOWER(COALESCE(pagina_origem, '')), 'gclid=') > 0 OR
            INSTR(LOWER(COALESCE(pagina_origem, '')), 'gbraid=') > 0 OR
            INSTR(LOWER(COALESCE(pagina_origem, '')), 'wbraid=') > 0 OR
            INSTR(LOWER(COALESCE(pagina_origem, '')), 'gad_source=') > 0 OR
            INSTR(LOWER(COALESCE(pagina_origem, '')), 'gad_campaignid=') > 0 OR
            INSTR(LOWER(COALESCE(pagina_origem, '')), 'utm_source=google') > 0 OR
            INSTR(LOWER(COALESCE(pagina_origem, '')), 'utm_source=institucional-clique') > 0 OR
            INSTR(LOWER(COALESCE(referrer, '')), 'google.') > 0
        )";

        $google_condition = "({$google_raw} AND NOT {$planilha_condition} AND NOT {$google_lead_form_condition})";

        if ($chan === 'google_ads') {
            return $google_condition;
        }

        if ($chan === 'meta_ads') {
            $meta_raw = "(
                (fbclid IS NOT NULL AND TRIM(fbclid) != '') OR
                (fbc IS NOT NULL AND TRIM(fbc) != '' AND fbc LIKE 'fb.%') OR
                (ad_id IS NOT NULL AND TRIM(ad_id) != '') OR
                (adset_id IS NOT NULL AND TRIM(adset_id) != '') OR
                LOWER(COALESCE(utm_source, '')) IN ('meta', 'facebook', 'instagram', 'fb', 'ig', 'face', 'insta', 'meta_ads', 'facebook_ads', 'instagram_feed', 'stories', 'reels') OR
                INSTR(LOWER(COALESCE(utm_source, '')), 'meta') > 0 OR
                INSTR(LOWER(COALESCE(utm_source, '')), 'facebook') > 0 OR
                INSTR(LOWER(COALESCE(utm_source, '')), 'instagram') > 0 OR
                INSTR(LOWER(COALESCE(utm_campaign, '')), 'meta') > 0 OR
                INSTR(LOWER(COALESCE(utm_campaign, '')), 'facebook') > 0 OR
                INSTR(LOWER(COALESCE(utm_campaign, '')), 'instagram') > 0 OR
                INSTR(LOWER(COALESCE(utm_campaign, '')), 'fb') > 0 OR
                INSTR(LOWER(COALESCE(formulario_nome, '')), 'meta') > 0 OR
                INSTR(LOWER(COALESCE(formulario_nome, '')), 'facebook') > 0 OR
                INSTR(LOWER(COALESCE(formulario_nome, '')), 'instagram') > 0 OR
                INSTR(LOWER(COALESCE(pagina_origem, '')), 'fbclid=') > 0 OR
                INSTR(LOWER(COALESCE(pagina_origem, '')), 'utm_source=meta') > 0 OR
                INSTR(LOWER(COALESCE(pagina_origem, '')), 'utm_source=facebook') > 0 OR
                INSTR(LOWER(COALESCE(pagina_origem, '')), 'utm_source=instagram') > 0 OR
                INSTR(LOWER(COALESCE(referrer, '')), 'facebook.com') > 0 OR
                INSTR(LOWER(COALESCE(referrer, '')), 'instagram.com') > 0
            )";
            // Exclusão mútua rigorosa: leads de Google Ads, Lead Form ou Planilha nunca caem em Meta Ads web
            return "({$meta_raw} AND NOT {$google_condition} AND NOT {$planilha_condition} AND NOT {$google_lead_form_condition})";
        }

        if ($chan === 'chatgpt' || $chan === 'chatgpt_ads') {
            return "(
                LOWER(COALESCE(utm_source, '')) IN ('chat-ads', 'chatgpt', 'chatgpt_ads', 'chat-gpt', 'openai', 'chat_ads') OR
                INSTR(LOWER(COALESCE(utm_source, '')), 'chat-ads') > 0 OR
                INSTR(LOWER(COALESCE(utm_source, '')), 'chatgpt') > 0 OR
                INSTR(LOWER(COALESCE(utm_source, '')), 'chat-gpt') > 0 OR
                INSTR(LOWER(COALESCE(utm_campaign, '')), 'chatgpt') > 0 OR
                INSTR(LOWER(COALESCE(utm_campaign, '')), 'chat-gpt') > 0 OR
                INSTR(LOWER(COALESCE(formulario_nome, '')), 'chat ads') > 0 OR
                INSTR(LOWER(COALESCE(formulario_nome, '')), 'chat-ads') > 0 OR
                INSTR(LOWER(COALESCE(formulario_nome, '')), 'chatgpt') > 0 OR
                INSTR(LOWER(COALESCE(pagina_origem, '')), 'chat-gpt') > 0 OR
                INSTR(LOWER(COALESCE(pagina_origem, '')), 'chatgpt') > 0 OR
                INSTR(LOWER(COALESCE(pagina_origem, '')), 'utm_source=chat-ads') > 0 OR
                INSTR(LOWER(COALESCE(referrer, '')), 'chatgpt.com') > 0 OR
                INSTR(LOWER(COALESCE(referrer, '')), 'openai.com') > 0
            )";
        }

        if ($chan === 'whatsapp') {
            return "(
                INSTR(LOWER(COALESCE(formulario_nome, '')), 'whatsapp') > 0 OR
                (whatsapp_status IS NOT NULL AND TRIM(whatsapp_status) != '') OR
                (conversation_id IS NOT NULL AND TRIM(conversation_id) != '')
            )";
        }

        if ($chan === 'organico') {
            $google    = self::get_channel_sql_condition('google_ads');
            $meta      = self::get_channel_sql_condition('meta_ads');
            $wa        = self::get_channel_sql_condition('whatsapp');
            $chatgpt   = self::get_channel_sql_condition('chatgpt');
            $lead_form = self::get_channel_sql_condition('google_lead_form');
            return "(NOT ({$google}) AND NOT ({$meta}) AND NOT ({$wa}) AND NOT ({$chatgpt}) AND NOT ({$planilha_condition}) AND NOT ({$lead_form}))";
        }

        return '';
    }

    /**
     * Identifica o canal de tráfego/origem de um lead
     *
     * @param object $lead Objeto com colunas do lead
     * @return string 'google_ads' | 'google_lead_form' | 'meta_ads' | 'whatsapp' | 'chatgpt' | 'planilha' | 'planilha_google' | 'planilha_meta' | 'organico'
     */
    public static function get_channel($lead) {
        if (!$lead) {
            return 'organico';
        }

        $utm_src  = strtolower(trim((string) ($lead->utm_source ?? '')));
        $utm_med  = strtolower(trim((string) ($lead->utm_medium ?? '')));
        $utm_camp = strtolower(trim((string) ($lead->utm_campaign ?? '')));
        $gclid    = trim((string) ($lead->gclid ?? ''));
        $gad_src  = trim((string) ($lead->gad_source ?? ''));
        $gbraid   = trim((string) ($lead->gbraid ?? ''));
        $wbraid   = trim((string) ($lead->wbraid ?? ''));
        $fbclid   = trim((string) ($lead->fbclid ?? ''));
        $fbc      = trim((string) ($lead->fbc ?? ''));
        $ad_id    = trim((string) ($lead->ad_id ?? ''));
        $adset_id = trim((string) ($lead->adset_id ?? ''));
        $form     = strtolower(trim((string) ($lead->formulario_nome ?? '')));
        $origem   = strtolower(trim((string) ($lead->pagina_origem ?? '')));
        $motivo   = strtolower(trim((string) ($lead->motivo_qualificacao ?? '')));
        $referrer = strtolower(trim((string) ($lead->referrer ?? '')));
        $wa_stat  = trim((string) ($lead->whatsapp_status ?? ''));
        $conv_id   = trim((string) ($lead->conversation_id ?? ''));
        $form_id   = trim((string) ($lead->formulario_id ?? ''));
        $camp_name = strtolower(trim((string) ($lead->campaign_name ?? '')));
        $lead_orig = strtolower(trim((string) ($lead->origem ?? '')));

        // 1. Google Ads Lead Form (Formulário Nativo / Extensão de Anúncio / Campanha Formulário Google)
        $is_lead_form = (
            $utm_src === 'google_lead_form' ||
            $utm_src === 'google-lead-form' ||
            strpos($utm_src, 'lead_form') !== false ||
            $utm_med === 'lead_form' ||
            $utm_med === 'leadform' ||
            $utm_med === 'lead-form' ||
            strpos($form, 'lead form') !== false ||
            strpos($form, 'leadform') !== false ||
            strpos($form, 'formulario google') !== false ||
            strpos($form, 'formulário google') !== false ||
            strpos($form, 'formulario do google') !== false ||
            strpos($form, 'formulário do google') !== false ||
            strpos($form, 'google lead form') !== false ||
            strpos($form, 'google form') !== false ||
            strpos($origem, 'lead form') !== false ||
            strpos($origem, 'formulario google') !== false ||
            strpos($origem, 'formulário google') !== false ||
            strpos($origem, 'google_lead_form') !== false ||
            strpos($origem, 'google form') !== false ||
            strpos($lead_orig, 'lead form') !== false ||
            strpos($lead_orig, 'google ads lead form') !== false ||
            strpos($lead_orig, 'formulario google') !== false ||
            strpos($lead_orig, 'formulário google') !== false ||
            strpos($utm_camp, 'formulario google') !== false ||
            strpos($utm_camp, 'formulário google') !== false ||
            strpos($utm_camp, 'formulario_google') !== false ||
            strpos($utm_camp, 'formulario-google') !== false ||
            strpos($utm_camp, 'formulario do google') !== false ||
            strpos($utm_camp, 'formulário do google') !== false ||
            strpos($utm_camp, 'google_lead_form') !== false ||
            strpos($utm_camp, 'google-lead-form') !== false ||
            strpos($utm_camp, 'lead_form') !== false ||
            strpos($utm_camp, 'lead form') !== false ||
            strpos($utm_camp, 'leadform') !== false ||
            strpos($utm_camp, 'form google') !== false ||
            strpos($utm_camp, 'form_google') !== false ||
            strpos($utm_camp, 'google form') !== false ||
            strpos($camp_name, 'formulario google') !== false ||
            strpos($camp_name, 'formulário google') !== false ||
            strpos($camp_name, 'formulario_google') !== false ||
            strpos($camp_name, 'formulario-google') !== false ||
            strpos($camp_name, 'formulario do google') !== false ||
            strpos($camp_name, 'formulário do google') !== false ||
            strpos($camp_name, 'google_lead_form') !== false ||
            strpos($camp_name, 'google-lead-form') !== false ||
            strpos($camp_name, 'lead_form') !== false ||
            strpos($camp_name, 'lead form') !== false ||
            strpos($camp_name, 'leadform') !== false ||
            strpos($camp_name, 'form google') !== false ||
            strpos($camp_name, 'form_google') !== false ||
            strpos($camp_name, 'google form') !== false
        );

        if ($is_lead_form) {
            return 'google_lead_form';
        }

        // 2. ChatGPT Ads / OpenAI
        if (
            in_array($utm_src, ['chat-ads', 'chatgpt', 'chatgpt_ads', 'chat-gpt', 'openai', 'chat_ads']) ||
            strpos($utm_src, 'chat-ads') !== false ||
            strpos($utm_src, 'chatgpt') !== false ||
            strpos($utm_src, 'chat-gpt') !== false ||
            strpos($utm_camp, 'chatgpt') !== false ||
            strpos($utm_camp, 'chat-gpt') !== false ||
            strpos($form, 'chat ads') !== false ||
            strpos($form, 'chat-ads') !== false ||
            strpos($form, 'chatgpt') !== false ||
            strpos($origem, 'chat-gpt') !== false ||
            strpos($origem, 'chatgpt') !== false ||
            strpos($origem, 'utm_source=chat-ads') !== false ||
            strpos($referrer, 'chatgpt.com') !== false ||
            strpos($referrer, 'openai.com') !== false
        ) {
            return 'chatgpt';
        }

        // 3. Leads gerados diretamente a partir de Planilhas (sem formulário Elementor prévio do site)
        $is_planilha = (
            strpos($form, 'planilha') !== false ||
            strpos($origem, 'planilha:') !== false ||
            strpos($utm_src, 'planilha') !== false ||
            in_array($utm_src, ['planilha', 'planilha_google', 'planilha_meta', 'planilha_upload', 'planilha_sheets']) ||
            strpos($motivo, 'cadastrado via planilha') !== false ||
            strpos($motivo, 'importado via') !== false ||
            (!empty($lead->qualificacao_origem) && strpos(strtolower($lead->qualificacao_origem), 'planilha:') !== false && (empty($form_id) || strpos(strtolower($form_id), 'planilha') !== false))
        );

        if ($is_planilha && (empty($form_id) || strpos(strtolower($form_id), 'planilha') !== false)) {
            if (strpos($form, 'meta') !== false || strpos($utm_src, 'meta') !== false || !empty($fbclid) || !empty($ad_id)) {
                return 'planilha_meta';
            }
            if (strpos($form, 'google') !== false || strpos($utm_src, 'google') !== false || !empty($gclid) || !empty($gad_src)) {
                return 'planilha_google';
            }
            return 'planilha';
        }

        // 4. Google Ads (Site / Landing Page): Tem gclid, gad_source, gbraid, wbraid, utm_source google/clique/aba/search ou indicativos de mídia de busca/pmax
        if (
            !empty($gclid) ||
            !empty($gad_src) ||
            !empty($gbraid) ||
            !empty($wbraid) ||
            in_array($utm_src, ['google', 'google_ads', 'google-ads', 'gads', 'adwords', 'googleads', 'google_cpc', 'google_search']) ||
            strpos($utm_src, 'google') !== false ||
            strpos($utm_src, 'gads') !== false ||
            strpos($utm_src, 'clique') !== false ||
            strpos($utm_src, 'aba-') !== false ||
            strpos($utm_src, 'search') !== false ||
            strpos($utm_src, 'pesquisa') !== false ||
            strpos($utm_src, 'pmax') !== false ||
            (in_array($utm_med, ['cpc', 'ppc', 'search', 'pmax', 'display']) && strpos($utm_src, 'face') === false && strpos($utm_src, 'meta') === false && strpos($utm_src, 'insta') === false) ||
            strpos($utm_camp, 'google') !== false ||
            strpos($utm_camp, 'gads') !== false ||
            strpos($form, 'google') !== false ||
            strpos($origem, 'gclid=') !== false ||
            strpos($origem, 'gbraid=') !== false ||
            strpos($origem, 'wbraid=') !== false ||
            strpos($origem, 'gad_source=') !== false ||
            strpos($origem, 'gad_campaignid=') !== false ||
            strpos($origem, 'utm_source=google') !== false ||
            strpos($origem, 'utm_source=institucional-clique') !== false ||
            strpos($referrer, 'google.') !== false
        ) {
            return 'google_ads';
        }

        // 5. Meta Ads: Tem fbclid, fbc (com 'fb.'), ad_id, adset_id ou indicativos explícitos de Facebook/Instagram
        if (
            !empty($fbclid) ||
            (!empty($fbc) && strpos($fbc, 'fb.') !== false) ||
            !empty($ad_id) ||
            !empty($adset_id) ||
            in_array($utm_src, ['meta', 'facebook', 'instagram', 'fb', 'ig', 'face', 'insta', 'meta_ads', 'facebook_ads', 'instagram_feed', 'stories', 'reels']) ||
            strpos($utm_src, 'meta') !== false ||
            strpos($utm_src, 'facebook') !== false ||
            strpos($utm_src, 'instagram') !== false ||
            strpos($utm_camp, 'meta') !== false ||
            strpos($utm_camp, 'facebook') !== false ||
            strpos($utm_camp, 'instagram') !== false ||
            strpos($utm_camp, 'fb') !== false ||
            strpos($form, 'meta') !== false ||
            strpos($form, 'facebook') !== false ||
            strpos($form, 'instagram') !== false ||
            strpos($origem, 'fbclid=') !== false ||
            strpos($origem, 'utm_source=meta') !== false ||
            strpos($origem, 'utm_source=facebook') !== false ||
            strpos($origem, 'utm_source=instagram') !== false ||
            strpos($referrer, 'facebook.com') !== false ||
            strpos($referrer, 'instagram.com') !== false
        ) {
            return 'meta_ads';
        }

        // 6. WhatsApp Direto
        if (strpos($form, 'whatsapp') !== false || !empty($wa_stat) || !empty($conv_id)) {
            return 'whatsapp';
        }

        return 'organico';
    }

    /**
     * Retorna informações de rótulo e estilo do canal
     *
     * @param string $channel
     * @return array ['label' => string, 'badge_class' => string, 'icon' => string, 'color' => string, 'bg' => string]
     */
    public static function get_channel_info($channel) {
        switch ($channel) {
            case 'google_lead_form':
                return [
                    'label'       => 'Google Lead Form',
                    'badge_class' => 'li-badge-google-form',
                    'icon'        => 'dashicons-feedback',
                    'dot'         => '📋',
                    'color'       => '#b45309',
                    'bg'          => '#fef3c7',
                ];
            case 'chatgpt':
            case 'chatgpt_ads':
                return [
                    'label'       => 'ChatGPT Ads',
                    'badge_class' => 'li-badge-chatgpt',
                    'icon'        => 'dashicons-format-chat',
                    'dot'         => '🤖',
                    'color'       => '#10a37f',
                    'bg'          => '#e6f7f2',
                ];
            case 'planilha_google':
                return [
                    'label'       => 'Planilha (Google Ads)',
                    'badge_class' => 'li-badge-planilha',
                    'icon'        => 'dashicons-media-spreadsheet',
                    'dot'         => '📊',
                    'color'       => '#7c3aed',
                    'bg'          => '#f5f3ff',
                ];
            case 'planilha_meta':
                return [
                    'label'       => 'Planilha (Meta Ads)',
                    'badge_class' => 'li-badge-planilha',
                    'icon'        => 'dashicons-media-spreadsheet',
                    'dot'         => '📊',
                    'color'       => '#8b5cf6',
                    'bg'          => '#f5f3ff',
                ];
            case 'planilha':
                return [
                    'label'       => 'Planilha (Importado)',
                    'badge_class' => 'li-badge-planilha',
                    'icon'        => 'dashicons-media-spreadsheet',
                    'dot'         => '📊',
                    'color'       => '#6d28d9',
                    'bg'          => '#ede9fe',
                ];
            case 'google_ads':
                return [
                    'label'       => 'Google Ads',
                    'badge_class' => 'li-badge-google',
                    'icon'        => 'dashicons-google',
                    'dot'         => '🟢',
                    'color'       => '#1a73e8',
                    'bg'          => '#e8f0fe',
                ];
            case 'meta_ads':
                return [
                    'label'       => 'Meta Ads',
                    'badge_class' => 'li-badge-meta',
                    'icon'        => 'dashicons-facebook',
                    'dot'         => '🔵',
                    'color'       => '#0866ff',
                    'bg'          => '#e7f3ff',
                ];
            case 'whatsapp':
                return [
                    'label'       => 'WhatsApp Direto',
                    'badge_class' => 'li-badge-whatsapp',
                    'icon'        => 'dashicons-format-chat',
                    'dot'         => '💬',
                    'color'       => '#15803d',
                    'bg'          => '#dcfce7',
                ];
            case 'organico':
            default:
                return [
                    'label'       => 'Direto / Site',
                    'badge_class' => 'li-badge-direct',
                    'icon'        => 'dashicons-admin-site',
                    'dot'         => '⚪',
                    'color'       => '#475569',
                    'bg'          => '#f1f5f9',
                ];
        }
    }

    /**
     * Retorna contadores agregados para dashboard e badges (com filtro opcional por canal, período de data e utm_source)
     */
    public static function get_status_counts($channel = '', $date_start = '', $date_end = '', $utm_source = '') {
        global $wpdb;
        $table = DbSchema::get_leads_table();
        $where = ['1=1'];
        $params = [];

        if (!empty($channel)) {
            $chan_sql = self::get_channel_sql_condition($channel);
            if (!empty($chan_sql)) {
                $where[] = $chan_sql;
            }
        }

        if (!empty($utm_source)) {
            $src_val = trim($utm_source);
            $src_like = '%' . $wpdb->esc_like($src_val) . '%';
            $where[] = "(utm_source = %s OR utm_source_first = %s OR utm_source LIKE %s OR pagina_origem LIKE %s)";
            $params[] = $src_val;
            $params[] = $src_val;
            $params[] = $src_like;
            $params[] = '%' . $wpdb->esc_like('utm_source=' . $src_val) . '%';
        }

        if (!empty($date_start)) {
            $where[] = "data_cadastro >= %s";
            $params[] = $date_start;
        }

        if (!empty($date_end)) {
            $where[] = "data_cadastro <= %s";
            $params[] = $date_end;
        }

        $where_sql = implode(' AND ', $where);

        $query = "SELECT qualificacao_status, COUNT(*) as total FROM {$table} WHERE {$where_sql} GROUP BY qualificacao_status";
        $results = !empty($params) 
            ? $wpdb->get_results($wpdb->prepare($query, $params), OBJECT_K)
            : $wpdb->get_results($query, OBJECT_K);

        $total_query = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        $total = !empty($params) 
            ? (int) $wpdb->get_var($wpdb->prepare($total_query, $params))
            : (int) $wpdb->get_var($total_query);

        return [
            'total'               => $total,
            'qualificado'         => isset($results['qualificado']) ? (int) $results['qualificado']->total : 0,
            'nao_qualificado'     => isset($results['nao_qualificado']) ? (int) $results['nao_qualificado']->total : 0,
            'pendente'            => isset($results['pendente']) ? (int) $results['pendente']->total : 0,
            'sem_correspondencia' => isset($results['sem_correspondencia']) ? (int) $results['sem_correspondencia']->total : 0,
        ];
    }

    /**
     * Retorna todas as utm_sources distintas cadastradas no banco de leads
     *
     * @return array
     */
    public static function get_distinct_utm_sources() {
        global $wpdb;
        $table = DbSchema::get_leads_table();
        $results = $wpdb->get_col("
            SELECT DISTINCT utm_source 
            FROM {$table} 
            WHERE utm_source IS NOT NULL AND TRIM(utm_source) != '' 
            ORDER BY utm_source ASC
        ");

        if (!is_array($results)) {
            $results = [];
        }

        // Também busca utm_source passadas dentro da URL da pagina_origem
        $url_sources = $wpdb->get_col("
            SELECT DISTINCT pagina_origem 
            FROM {$table} 
            WHERE pagina_origem LIKE '%utm_source=%' 
            LIMIT 50
        ");

        if (is_array($url_sources)) {
            foreach ($url_sources as $u) {
                $q = parse_url($u, PHP_URL_QUERY);
                if (!empty($q)) {
                    parse_str($q, $qp);
                    if (!empty($qp['utm_source'])) {
                        $results[] = sanitize_text_field($qp['utm_source']);
                    }
                }
            }
        }

        $clean = array_unique(array_filter(array_map('trim', $results)));
        sort($clean);
        return array_values($clean);
    }

    /**
     * Retorna resumo comparativo direto entre Google Ads e Meta Ads
     */
    public static function get_channel_comparison_summary($date_start = '', $date_end = '') {
        global $wpdb;
        $table = DbSchema::get_leads_table();

        $where_date = '1=1';
        $params = [];
        if (!empty($date_start)) {
            $where_date .= " AND data_cadastro >= %s";
            $params[] = $date_start;
        }
        if (!empty($date_end)) {
            $where_date .= " AND data_cadastro <= %s";
            $params[] = $date_end;
        }

        $chatgpt_cond   = self::get_channel_sql_condition('chatgpt');
        $planilha_cond  = self::get_channel_sql_condition('planilha');
        $lead_form_cond = self::get_channel_sql_condition('google_lead_form');
        $google_cond    = self::get_channel_sql_condition('google_ads');
        $meta_cond      = self::get_channel_sql_condition('meta_ads');
        $wa_cond        = self::get_channel_sql_condition('whatsapp');

        $sql = "SELECT 
            CASE 
                WHEN {$chatgpt_cond} THEN 'chatgpt'
                WHEN {$planilha_cond} THEN 'planilha'
                WHEN {$lead_form_cond} THEN 'google_lead_form'
                WHEN {$google_cond} THEN 'google_ads'
                WHEN {$meta_cond} THEN 'meta_ads'
                WHEN {$wa_cond} THEN 'whatsapp'
                ELSE 'organico'
            END as canal,
            COUNT(*) as total_leads,
            SUM(CASE WHEN qualificacao_status = 'qualificado' THEN 1 ELSE 0 END) as qualificados,
            SUM(CASE WHEN qualificacao_status = 'nao_qualificado' THEN 1 ELSE 0 END) as nao_qualificados,
            SUM(CASE WHEN qualificacao_status = 'pendente' THEN 1 ELSE 0 END) as pendentes
        FROM {$table}
        WHERE {$where_date}
        GROUP BY canal";

        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, $params);
        }

        $results = $wpdb->get_results($sql, OBJECT_K);

        $default_item = function($canal) {
            return (object) [
                'canal'            => $canal,
                'total_leads'      => 0,
                'qualificados'     => 0,
                'nao_qualificados' => 0,
                'pendentes'        => 0,
                'taxa'             => 0,
            ];
        };

        $channels = ['google_ads', 'google_lead_form', 'meta_ads', 'whatsapp', 'planilha', 'organico'];
        $formatted = [];

        foreach ($channels as $c) {
            $row = isset($results[$c]) ? $results[$c] : $default_item($c);
            $total = (int) $row->total_leads;
            $qual  = (int) $row->qualificados;
            $row->taxa = $total > 0 ? round(($qual / $total) * 100, 1) : 0;
            $formatted[$c] = $row;
        }

        return $formatted;
    }

    /**
     * Exclui leads em massa por lista de IDs
     *
     * @param array $ids
     * @return int Quantidade de leads excluídos
     */
    public static function delete_leads_by_ids($ids) {
        global $wpdb;
        if (empty($ids) || !is_array($ids)) {
            return 0;
        }

        $clean_ids = array_filter(array_map('intval', $ids));
        if (empty($clean_ids)) {
            return 0;
        }

        $leads_table   = DbSchema::get_leads_table();
        $history_table = DbSchema::get_history_table();

        $placeholders = implode(',', array_fill(0, count($clean_ids), '%d'));

        // Remove histórico de auditoria correspondente
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$history_table} WHERE lead_id IN ({$placeholders})",
            $clean_ids
        ));

        // Remove os leads da tabela principal
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$leads_table} WHERE id IN ({$placeholders})",
            $clean_ids
        ));

        Logger::info('Leads', "Exclusão em massa: {$deleted} lead(s) removido(s) pelo usuário #" . get_current_user_id(), [
            'ids_amostra' => array_slice($clean_ids, 0, 10),
            'total'       => $deleted,
        ]);

        return (int) $deleted;
    }

    /**
     * Exclui todos os leads que correspondem aos filtros atuais
     *
     * @param array $args Filtros (search, status, channel, campaign, etc.)
     * @return int Quantidade de leads excluídos
     */
    public static function delete_all_by_filters($args = []) {
        global $wpdb;
        $leads_table = DbSchema::get_leads_table();

        $where = ['1=1'];
        $params = [];

        if (!empty($args['search'])) {
            $search = '%' . $wpdb->esc_like(trim($args['search'])) . '%';
            $where[] = "(nome LIKE %s OR email LIKE %s OR telefone LIKE %s OR telefone_normalizado LIKE %s OR area_interesse LIKE %s)";
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        if (!empty($args['status'])) {
            $where[] = "qualificacao_status = %s";
            $params[] = $args['status'];
        }

        if (!empty($args['campaign'])) {
            $where[] = "utm_campaign = %s";
            $params[] = $args['campaign'];
        }

        if (!empty($args['channel'])) {
            $chan = sanitize_key($args['channel']);
            $chan_sql = self::get_channel_sql_condition($chan);
            if (!empty($chan_sql)) {
                $where[] = $chan_sql;
            }
        }

        if (!empty($args['date_from'])) {
            $where[] = "data_cadastro >= %s";
            $params[] = $args['date_from'] . (strlen($args['date_from']) === 10 ? ' 00:00:00' : '');
        }

        if (!empty($args['date_to'])) {
            $where[] = "data_cadastro <= %s";
            $params[] = $args['date_to'] . (strlen($args['date_to']) === 10 ? ' 23:59:59' : '');
        }

        $where_sql = implode(' AND ', $where);

        $sql_ids = "SELECT id FROM {$leads_table} WHERE {$where_sql}";
        if (!empty($params)) {
            $sql_ids = $wpdb->prepare($sql_ids, $params);
        }
        $target_ids = $wpdb->get_col($sql_ids);

        if (empty($target_ids)) {
            return 0;
        }

        return self::delete_leads_by_ids($target_ids);
    }

    /**
     * Repara leads cadastrados com "Sem nome" a partir de arquivos CSV do Elementor
     *
     * @return array ['repaired' => int, 'total_missing' => int, 'files_scanned' => int]
     */
    public static function repair_missing_names_from_elementor_files() {
        global $wpdb;
        $leads_table   = DbSchema::get_leads_table();
        $history_table = DbSchema::get_lead_history_table();

        // 1. Localiza arquivos CSV do Elementor em caminhos estratégicos
        $search_dirs = [
            dirname(LEAD_INTELLIGENCE_PLUGIN_DIR), // Raiz do projeto
            LEAD_INTELLIGENCE_PLUGIN_DIR,          // Diretório do plugin
            sys_get_temp_dir(),                    // Diretório temporário
        ];

        $upload_dir = wp_upload_dir();
        if (!empty($upload_dir['basedir'])) {
            $search_dirs[] = $upload_dir['basedir'];
            $search_dirs[] = $upload_dir['basedir'] . '/elementor';
        }

        $files = [];
        foreach (array_unique($search_dirs) as $dir) {
            if (!is_dir($dir)) continue;
            $found = glob($dir . '/*elementor*.csv');
            if ($found) {
                $files = array_merge($files, $found);
            }
            $found_generic = glob($dir . '/*.csv');
            if ($found_generic) {
                foreach ($found_generic as $gf) {
                    if (strpos(basename($gf), 'elementor') !== false || strpos(basename($gf), 'submission') !== false) {
                        $files[] = $gf;
                    }
                }
            }
        }
        $files = array_unique($files);

        if (empty($files)) {
            return ['repaired' => 0, 'total_missing' => 0, 'files_scanned' => 0];
        }

        // 2. Extrai pares [Telefone/Email -> Nome] dos arquivos
        $names_by_phone = [];
        $names_by_email = [];

        foreach ($files as $file_path) {
            try {
                \LeadIntelligence\Qualification\SpreadsheetParser::parse_all($file_path, function ($row) use (&$names_by_phone, &$names_by_email) {
                    $nome  = '';
                    $tel   = '';
                    $email = '';

                    foreach ($row as $k => $v) {
                        $raw_k   = preg_replace('/^\xEF\xBB\xBF/', '', (string) $k);
                        $clean_k = strtolower(trim($raw_k, " \t\n\r\0\x0B\"'“”«»"));
                        $clean_k = str_replace(['á', 'à', 'ã', 'â', 'é', 'ê', 'í', 'ó', 'ô', 'õ', 'ú', 'ç'], ['a', 'a', 'a', 'a', 'e', 'e', 'i', 'o', 'o', 'o', 'u', 'c'], $clean_k);
                        $val     = trim(trim((string) $v), "\"'“”«»");

                        if (empty($val)) continue;

                        if (($clean_k === 'nome' || $clean_k === 'name' || $clean_k === 'nome completo') ||
                            (strpos($clean_k, 'nome') !== false && strpos($clean_k, 'form') === false && strpos($clean_k, 'campan') === false)) {
                            if (empty($nome)) $nome = $val;
                        } elseif ($clean_k === 'email' || strpos($clean_k, 'email') !== false) {
                            if (empty($email)) $email = strtolower($val);
                        } elseif ($clean_k === 'telefone' || strpos($clean_k, 'telef') !== false || strpos($clean_k, 'celular') !== false || strpos($clean_k, 'whats') !== false) {
                            if (empty($tel)) $tel = $val;
                        }
                    }

                    if (!empty($nome)) {
                        if (!empty($tel)) {
                            $phone_norm = \LeadIntelligence\PhoneNormalizer::normalize($tel);
                            if (!empty($phone_norm)) {
                                $variations = \LeadIntelligence\PhoneNormalizer::get_lookup_variations($phone_norm);
                                foreach ($variations as $var) {
                                    $names_by_phone[$var] = $nome;
                                }
                            }
                        }
                        if (!empty($email) && is_email($email)) {
                            $names_by_email[$email] = $nome;
                        }
                    }
                });
            } catch (\Throwable $e) {
                // Ignora arquivo individual com erro
            }
        }

        // 3. Busca no banco todos os leads que estão sem nome
        $missing_leads = $wpdb->get_results(
            "SELECT id, telefone_normalizado, telefone, email, nome 
             FROM {$leads_table} 
             WHERE nome IS NULL OR nome = '' OR nome = 'Sem nome' OR nome = 'Sem Nome' OR nome = 'sem nome'"
        );

        $repaired = 0;
        $current_user_id = get_current_user_id();

        foreach ($missing_leads as $lead) {
            $matched_name = '';

            // Match por telefone normalizado
            if (!empty($lead->telefone_normalizado) && isset($names_by_phone[$lead->telefone_normalizado])) {
                $matched_name = $names_by_phone[$lead->telefone_normalizado];
            } elseif (!empty($lead->telefone)) {
                $tel_norm = \LeadIntelligence\PhoneNormalizer::normalize($lead->telefone);
                if (!empty($tel_norm) && isset($names_by_phone[$tel_norm])) {
                    $matched_name = $names_by_phone[$tel_norm];
                }
            }

            // Match por e-mail
            if (empty($matched_name) && !empty($lead->email)) {
                $clean_em = strtolower(trim($lead->email));
                if (isset($names_by_email[$clean_em])) {
                    $matched_name = $names_by_email[$clean_em];
                }
            }

            if (!empty($matched_name)) {
                $wpdb->update(
                    $leads_table,
                    [
                        'nome'       => sanitize_text_field($matched_name),
                        'updated_at' => current_time('mysql'),
                    ],
                    ['id' => (int) $lead->id]
                );

                $wpdb->insert($history_table, [
                    'lead_id'        => (int) $lead->id,
                    'campo'          => 'nome',
                    'valor_anterior' => $lead->nome,
                    'valor_novo'     => sanitize_text_field($matched_name),
                    'origem'         => 'reparo_planilha_elementor',
                    'usuario_id'     => $current_user_id,
                    'created_at'     => current_time('mysql'),
                ]);

                $repaired++;
            }
        }

        Logger::info('Leads', "Reparo de nomes concluído: {$repaired} leads atualizados de " . count($missing_leads) . " pendentes.", [
            'files_scanned' => count($files),
            'repaired'      => $repaired,
        ]);

        return [
            'repaired'      => $repaired,
            'total_missing' => count($missing_leads),
            'files_scanned' => count($files),
        ];
    }

    /**
     * Registra ou atualiza o gasto diário de uma campanha (Upsert seguro)
     */
    public static function record_campaign_cost($data) {
        global $wpdb;
        $table = DbSchema::get_costs_table();

        $canal         = sanitize_key($data['canal'] ?? 'google_ads');
        $campaign_id   = sanitize_text_field($data['campaign_id'] ?? '');
        $campaign_name = sanitize_text_field($data['campaign_name'] ?? '');
        $date          = sanitize_text_field($data['date'] ?? current_time('Y-m-d'));
        $cost          = (float) ($data['cost'] ?? 0.00);
        $clicks        = (int) ($data['clicks'] ?? 0);
        $impressions   = (int) ($data['impressions'] ?? 0);
        $cpc           = (float) ($data['cpc'] ?? ($clicks > 0 ? round($cost / $clicks, 2) : 0.00));
        $ctr           = (float) ($data['ctr'] ?? ($impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0.00));
        $conversions   = (float) ($data['conversions'] ?? 0.00);

        if (empty($campaign_id) && empty($campaign_name)) {
            return false;
        }

        if (empty($campaign_id)) {
            $campaign_id = md5($campaign_name);
        }

        $sql = "INSERT INTO {$table} 
            (canal, campaign_id, campaign_name, date, cost, clicks, impressions, cpc, ctr, conversions, synced_at)
            VALUES (%s, %s, %s, %s, %f, %d, %d, %f, %f, %f, %s)
            ON DUPLICATE KEY UPDATE 
                campaign_name = VALUES(campaign_name),
                cost = VALUES(cost),
                clicks = VALUES(clicks),
                impressions = VALUES(impressions),
                cpc = VALUES(cpc),
                ctr = VALUES(ctr),
                conversions = VALUES(conversions),
                synced_at = VALUES(synced_at)";

        return $wpdb->query($wpdb->prepare(
            $sql,
            $canal,
            $campaign_id,
            $campaign_name,
            $date,
            $cost,
            $clicks,
            $impressions,
            $cpc,
            $ctr,
            $conversions,
            current_time('mysql')
        ));
    }

    /**
     * Retorna o investimento total no canal para um determinado período
     */
    public static function get_total_cost($canal = 'google_ads', $date_start = '', $date_end = '') {
        global $wpdb;
        $table = DbSchema::get_costs_table();
        $where = ['canal = %s'];
        $params = [$canal];

        if (!empty($date_start)) {
            $where[] = "date >= %s";
            $params[] = substr($date_start, 0, 10);
        }
        if (!empty($date_end)) {
            $where[] = "date <= %s";
            $params[] = substr($date_end, 0, 10);
        }

        $where_sql = implode(' AND ', $where);
        $sql = "SELECT COALESCE(SUM(cost), 0) FROM {$table} WHERE {$where_sql}";
        return (float) $wpdb->get_var($wpdb->prepare($sql, $params));
    }

    /**
     * Retorna o consolidado de gastos agrupado por campanha
     */
    public static function get_campaign_costs_summary($canal = 'google_ads', $date_start = '', $date_end = '') {
        global $wpdb;
        $table = DbSchema::get_costs_table();
        $where = ['canal = %s'];
        $params = [$canal];

        if (!empty($date_start)) {
            $where[] = "date >= %s";
            $params[] = substr($date_start, 0, 10);
        }
        if (!empty($date_end)) {
            $where[] = "date <= %s";
            $params[] = substr($date_end, 0, 10);
        }

        $where_sql = implode(' AND ', $where);
        $sql = "SELECT 
                    campaign_name,
                    campaign_id,
                    SUM(cost) as total_cost,
                    SUM(clicks) as total_clicks,
                    SUM(impressions) as total_impressions,
                    MAX(synced_at) as last_sync
                FROM {$table} 
                WHERE {$where_sql} 
                GROUP BY campaign_name, campaign_id 
                ORDER BY total_cost DESC";

        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }

    /**
     * Atualiza qualificação ou venda de um lead e despacha na hora para a Meta Conversions API (CAPI)
     *
     * @param int $lead_id
     * @param string $action 'qualificar' | 'desqualificar' | 'pendente' | 'venda' | 'remover_venda'
     * @param float|null $valor_venda
     * @return array
     */
    public static function update_lead_status_and_capi($lead_id, $action, $valor_venda = null) {
        global $wpdb;
        $table = DbSchema::get_leads_table();
        $history_table = DbSchema::get_history_table();

        $lead = self::find_by_id($lead_id);
        if (!$lead) {
            return [
                'success' => false,
                'message' => 'Lead não localizado no banco de dados.',
            ];
        }

        $now = current_time('mysql');
        $user_id = get_current_user_id();
        $updates = ['updated_at' => $now];
        $history = [];
        $meta_event_to_send = null;

        $old_status = (string) ($lead->qualificacao_status ?? 'pendente');
        $old_etapa  = (string) ($lead->etapa_funil ?? '');
        $old_is_sale = !empty($lead->capi_purchase_sent) || stripos($old_etapa, 'venda') !== false || stripos($old_etapa, 'matr') !== false;

        switch ($action) {
            case 'qualificar':
                $new_status = 'qualificado';
                $new_etapa  = $old_is_sale ? $old_etapa : 'Qualificado';
                $updates['qualificacao_status'] = $new_status;
                $updates['etapa_funil']         = $new_etapa;
                $updates['qualificacao_data']   = $now;
                $updates['qualificacao_origem'] = 'manual_meta_ads';
                $meta_event_to_send = 'LeadQualified';
                break;

            case 'desqualificar':
                $new_status = 'nao_qualificado';
                $new_etapa  = 'Desqualificado';
                $updates['qualificacao_status'] = $new_status;
                $updates['etapa_funil']         = $new_etapa;
                $updates['qualificacao_data']   = $now;
                $updates['qualificacao_origem'] = 'manual_meta_ads';
                $updates['capi_purchase_sent']  = 0;
                break;

            case 'pendente':
                $new_status = 'pendente';
                $new_etapa  = 'Formulário Enviado';
                $updates['qualificacao_status'] = $new_status;
                $updates['etapa_funil']         = $new_etapa;
                $updates['capi_purchase_sent']  = 0;
                break;

            case 'venda':
                $new_status = 'qualificado';
                $new_etapa  = 'Matrícula Realizada';
                $updates['qualificacao_status'] = $new_status;
                $updates['etapa_funil']         = $new_etapa;
                $updates['qualificacao_data']   = $now;
                $updates['qualificacao_origem'] = 'manual_meta_ads';
                if ($valor_venda !== null && (float) $valor_venda > 0) {
                    $updates['valor_venda'] = (float) $valor_venda;
                }
                $meta_event_to_send = 'Purchase';
                break;

            case 'remover_venda':
                $new_status = ($old_status === 'nao_qualificado') ? 'nao_qualificado' : 'qualificado';
                $new_etapa  = ($new_status === 'nao_qualificado') ? 'Desqualificado' : 'Qualificado';
                $updates['etapa_funil']        = $new_etapa;
                $updates['capi_purchase_sent'] = 0;
                break;

            default:
                return [
                    'success' => false,
                    'message' => 'Ação de qualificação inválida.',
                ];
        }

        // Registro de Auditoria
        if (isset($updates['qualificacao_status']) && $updates['qualificacao_status'] !== $old_status) {
            $history[] = [
                'lead_id'        => $lead_id,
                'campo'          => 'qualificacao_status',
                'valor_anterior' => $old_status,
                'valor_novo'     => $updates['qualificacao_status'],
                'origem'         => 'manual_meta_ads',
                'usuario_id'     => $user_id,
                'created_at'     => $now,
            ];
        }

        if (isset($updates['etapa_funil']) && $updates['etapa_funil'] !== $old_etapa) {
            $history[] = [
                'lead_id'        => $lead_id,
                'campo'          => 'etapa_funil',
                'valor_anterior' => $old_etapa,
                'valor_novo'     => $updates['etapa_funil'],
                'origem'         => 'manual_meta_ads',
                'usuario_id'     => $user_id,
                'created_at'     => $now,
            ];
        }

        $wpdb->update($table, $updates, ['id' => $lead_id]);

        foreach ($history as $h) {
            $wpdb->insert($history_table, $h);
        }

        $updated_lead = self::find_by_id($lead_id);

        // Despacho imediato para a Meta CAPI
        $capi_result = null;
        if ($meta_event_to_send === 'LeadQualified') {
            $capi_result = \LeadIntelligence\MetaCapi\CapiService::send_lead_qualified_event($updated_lead);
            if (!empty($capi_result['success'])) {
                $wpdb->update($table, [
                    'capi_qualified_sent' => 1,
                    'capi_qualified_time' => $now,
                    'capi_event_id'       => $capi_result['event_id'] ?? '',
                ], ['id' => $lead_id]);
                $updated_lead->capi_qualified_sent = 1;
                $updated_lead->capi_qualified_time = $now;
            }
        } elseif ($meta_event_to_send === 'Purchase') {
            if (empty($updated_lead->capi_qualified_sent)) {
                $q_res = \LeadIntelligence\MetaCapi\CapiService::send_lead_qualified_event($updated_lead);
                if (!empty($q_res['success'])) {
                    $wpdb->update($table, [
                        'capi_qualified_sent' => 1,
                        'capi_qualified_time' => $now,
                    ], ['id' => $lead_id]);
                    $updated_lead->capi_qualified_sent = 1;
                }
            }

            $capi_result = \LeadIntelligence\MetaCapi\CapiService::send_purchase_event($updated_lead, $valor_venda);
            if (!empty($capi_result['success'])) {
                $wpdb->update($table, [
                    'capi_purchase_sent' => 1,
                    'capi_purchase_time' => $now,
                    'capi_event_id'      => $capi_result['event_id'] ?? '',
                ], ['id' => $lead_id]);
                $updated_lead->capi_purchase_sent = 1;
                $updated_lead->capi_purchase_time = $now;
            }
        }

        Logger::info('Leads', "Lead #{$lead_id} teve status atualizado para '{$action}' via Painel Meta Ads.", [
            'status'      => $updated_lead->qualificacao_status,
            'etapa'       => $updated_lead->etapa_funil,
            'capi_event'  => $meta_event_to_send,
            'capi_result' => $capi_result ? ($capi_result['success'] ? 'sucesso' : 'falha') : 'nao_aplicavel',
        ]);

        return [
            'success'     => true,
            'action'      => $action,
            'lead'        => $updated_lead,
            'capi_result' => $capi_result,
        ];
    }
}
