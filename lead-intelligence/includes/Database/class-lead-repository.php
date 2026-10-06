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

        $query = $wpdb->prepare(
            "SELECT * FROM {$table} WHERE telefone_normalizado IN ({$placeholders}) OR telefone IN ({$placeholders}) ORDER BY id DESC LIMIT 1",
            array_merge($variations, $variations)
        );

        return $wpdb->get_row($query);
    }

    /**
     * Localiza lead por e-mail
     *
     * @param string $email
     * @return object|null
     */
    public static function find_by_email($email) {
        global $wpdb;
        $email = sanitize_email($email);
        if (empty($email)) {
            return null;
        }

        $table = DbSchema::get_leads_table();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE email = %s ORDER BY id DESC LIMIT 1", $email));
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

        if ($chan === 'google_ads') {
            return "(
                (gclid IS NOT NULL AND TRIM(gclid) != '') OR
                (gad_source IS NOT NULL AND TRIM(gad_source) != '') OR
                LOWER(COALESCE(utm_source, '')) IN ('google', 'google_ads', 'google-ads', 'gads', 'adwords', 'googleads') OR
                LOWER(COALESCE(utm_source, '')) LIKE '%google%' OR
                (LOWER(COALESCE(utm_medium, '')) IN ('cpc', 'ppc', 'search', 'pmax') AND LOWER(COALESCE(utm_source, '')) NOT LIKE '%meta%' AND LOWER(COALESCE(utm_source, '')) NOT LIKE '%face%' AND LOWER(COALESCE(utm_source, '')) NOT LIKE '%insta%') OR
                LOWER(COALESCE(formulario_nome, '')) LIKE '%google%' OR
                LOWER(COALESCE(pagina_origem, '')) LIKE '%gclid=%' OR
                LOWER(COALESCE(pagina_origem, '')) LIKE '%utm_source=google%' OR
                LOWER(COALESCE(referrer, '')) LIKE '%google.%'
            )";
        }

        if ($chan === 'meta_ads') {
            return "(
                (fbclid IS NOT NULL AND TRIM(fbclid) != '') OR
                (fbc IS NOT NULL AND TRIM(fbc) != '') OR
                (fbp IS NOT NULL AND TRIM(fbp) != '') OR
                (campaign_id IS NOT NULL AND TRIM(campaign_id) != '') OR
                (adset_id IS NOT NULL AND TRIM(adset_id) != '') OR
                (ad_id IS NOT NULL AND TRIM(ad_id) != '') OR
                LOWER(COALESCE(utm_source, '')) IN ('meta', 'facebook', 'instagram', 'fb', 'ig', 'face', 'insta', 'meta_ads', 'facebook_ads', 'instagram_feed', 'stories') OR
                LOWER(COALESCE(utm_source, '')) LIKE '%meta%' OR
                LOWER(COALESCE(utm_source, '')) LIKE '%facebook%' OR
                LOWER(COALESCE(utm_source, '')) LIKE '%instagram%' OR
                LOWER(COALESCE(utm_campaign, '')) LIKE '%meta%' OR
                LOWER(COALESCE(utm_campaign, '')) LIKE '%facebook%' OR
                LOWER(COALESCE(utm_campaign, '')) LIKE '%instagram%' OR
                LOWER(COALESCE(utm_campaign, '')) LIKE '%fb%' OR
                LOWER(COALESCE(formulario_nome, '')) LIKE '%meta%' OR
                LOWER(COALESCE(formulario_nome, '')) LIKE '%facebook%' OR
                LOWER(COALESCE(formulario_nome, '')) LIKE '%instagram%' OR
                LOWER(COALESCE(pagina_origem, '')) LIKE '%fbclid=%' OR
                LOWER(COALESCE(pagina_origem, '')) LIKE '%utm_source=meta%' OR
                LOWER(COALESCE(pagina_origem, '')) LIKE '%utm_source=facebook%' OR
                LOWER(COALESCE(pagina_origem, '')) LIKE '%utm_source=instagram%' OR
                LOWER(COALESCE(referrer, '')) LIKE '%facebook.com%' OR
                LOWER(COALESCE(referrer, '')) LIKE '%instagram.com%'
            )";
        }

        if ($chan === 'whatsapp') {
            return "(
                LOWER(COALESCE(formulario_nome, '')) LIKE '%whatsapp%' OR
                (whatsapp_status IS NOT NULL AND TRIM(whatsapp_status) != '') OR
                (conversation_id IS NOT NULL AND TRIM(conversation_id) != '')
            )";
        }

        if ($chan === 'organico') {
            $google = self::get_channel_sql_condition('google_ads');
            $meta   = self::get_channel_sql_condition('meta_ads');
            $wa     = self::get_channel_sql_condition('whatsapp');
            return "(NOT ({$google}) AND NOT ({$meta}) AND NOT ({$wa}))";
        }

        return '';
    }

    /**
     * Identifica o canal de tráfego/origem de um lead
     *
     * @param object $lead Objeto com colunas do lead
     * @return string 'google_ads' | 'meta_ads' | 'whatsapp' | 'organico'
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
        $fbclid   = trim((string) ($lead->fbclid ?? ''));
        $fbc      = trim((string) ($lead->fbc ?? ''));
        $fbp      = trim((string) ($lead->fbp ?? ''));
        $camp_id  = trim((string) ($lead->campaign_id ?? ''));
        $ad_id    = trim((string) ($lead->ad_id ?? ''));
        $form     = strtolower(trim((string) ($lead->formulario_nome ?? '')));
        $origem   = strtolower(trim((string) ($lead->pagina_origem ?? '')));
        $referrer = strtolower(trim((string) ($lead->referrer ?? '')));
        $wa_stat  = trim((string) ($lead->whatsapp_status ?? ''));
        $conv_id  = trim((string) ($lead->conversation_id ?? ''));

        // 1. Google Ads: Tem gclid, gad_source, utm_source google ou indicativos de mídia de busca/pmax
        if (
            !empty($gclid) ||
            !empty($gad_src) ||
            in_array($utm_src, ['google', 'google_ads', 'google-ads', 'gads', 'adwords', 'googleads']) ||
            strpos($utm_src, 'google') !== false ||
            (in_array($utm_med, ['cpc', 'ppc', 'search', 'pmax']) && strpos($utm_src, 'face') === false && strpos($utm_src, 'meta') === false && strpos($utm_src, 'insta') === false) ||
            strpos($form, 'google') !== false ||
            strpos($origem, 'gclid=') !== false ||
            strpos($origem, 'utm_source=google') !== false ||
            strpos($referrer, 'google.') !== false
        ) {
            return 'google_ads';
        }

        // 2. Meta Ads: Tem fbclid, fbc, fbp, campaign_id, ad_id ou indicativos de Facebook/Instagram
        if (
            !empty($fbclid) ||
            !empty($fbc) ||
            !empty($fbp) ||
            !empty($camp_id) ||
            !empty($ad_id) ||
            in_array($utm_src, ['meta', 'facebook', 'instagram', 'fb', 'ig', 'face', 'insta', 'meta_ads', 'facebook_ads', 'instagram_feed', 'stories']) ||
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

        // 3. WhatsApp Direto
        if (strpos($form, 'whatsapp') !== false || !empty($wa_stat) || !empty($conv_id)) {
            return 'whatsapp';
        }

        return 'organico';
    }

    /**
     * Retorna informações de rótulo e estilo do canal
     *
     * @param string $channel
     * @return array ['label' => string, 'badge_class' => string, 'icon' => string, 'color' => string]
     */
    public static function get_channel_info($channel) {
        switch ($channel) {
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
     * Retorna contadores agregados para dashboard e badges (com filtro opcional por canal)
     */
    public static function get_status_counts($channel = '') {
        global $wpdb;
        $table = DbSchema::get_leads_table();
        $where = ['1=1'];

        if (!empty($channel)) {
            $chan_sql = self::get_channel_sql_condition($channel);
            if (!empty($chan_sql)) {
                $where[] = $chan_sql;
            }
        }

        $where_sql = implode(' AND ', $where);

        $results = $wpdb->get_results(
            "SELECT qualificacao_status, COUNT(*) as total FROM {$table} WHERE {$where_sql} GROUP BY qualificacao_status",
            OBJECT_K
        );

        return [
            'total'              => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$where_sql}"),
            'qualificado'        => isset($results['qualificado']) ? (int) $results['qualificado']->total : 0,
            'nao_qualificado'    => isset($results['nao_qualificado']) ? (int) $results['nao_qualificado']->total : 0,
            'pendente'           => isset($results['pendente']) ? (int) $results['pendente']->total : 0,
            'sem_correspondencia'=> isset($results['sem_correspondencia']) ? (int) $results['sem_correspondencia']->total : 0,
        ];
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

        $google_cond = self::get_channel_sql_condition('google_ads');
        $meta_cond   = self::get_channel_sql_condition('meta_ads');
        $wa_cond     = self::get_channel_sql_condition('whatsapp');

        $sql = "SELECT 
            CASE 
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

        $channels = ['google_ads', 'meta_ads', 'whatsapp', 'organico'];
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
}
