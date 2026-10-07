<?php
namespace LeadIntelligence\Database;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gerenciador de Esquema do Banco de Dados
 */
class DbSchema {

    public static function get_leads_table() {
        global $wpdb;
        return $wpdb->prefix . 'li_leads';
    }

    public static function get_history_table() {
        global $wpdb;
        return $wpdb->prefix . 'li_lead_history';
    }

    public static function get_whatsapp_table() {
        global $wpdb;
        return $wpdb->prefix . 'li_whatsapp_messages';
    }

    public static function get_logs_table() {
        global $wpdb;
        return $wpdb->prefix . 'li_logs';
    }

    public static function get_imports_table() {
        global $wpdb;
        return $wpdb->prefix . 'li_qualification_imports';
    }

    /**
     * Cria ou atualiza as tabelas do banco de dados
     */
    public static function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        $leads_table = self::get_leads_table();
        $history_table = self::get_history_table();
        $whatsapp_table = self::get_whatsapp_table();
        $logs_table = self::get_logs_table();
        $imports_table = self::get_imports_table();

        // Tabela Principal de Leads
        $sql_leads = "CREATE TABLE {$leads_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            uuid varchar(64) NOT NULL DEFAULT '',
            nome varchar(255) DEFAULT '',
            email varchar(255) DEFAULT '',
            telefone varchar(50) DEFAULT '',
            telefone_normalizado varchar(50) DEFAULT '',
            tipo_curso varchar(100) DEFAULT '',
            area_interesse varchar(100) DEFAULT '',
            data_cadastro datetime DEFAULT CURRENT_TIMESTAMP,
            formulario_id varchar(100) DEFAULT '',
            formulario_nome varchar(255) DEFAULT '',
            pagina_origem text DEFAULT NULL,
            ip_address varchar(45) DEFAULT '',
            user_agent text DEFAULT NULL,
            utm_source varchar(255) DEFAULT '',
            utm_medium varchar(255) DEFAULT '',
            utm_campaign varchar(255) DEFAULT '',
            utm_content varchar(255) DEFAULT '',
            utm_term varchar(255) DEFAULT '',
            fbclid text DEFAULT NULL,
            gclid varchar(255) DEFAULT '',
            gbraid varchar(255) DEFAULT '',
            wbraid varchar(255) DEFAULT '',
            gad_source varchar(50) DEFAULT '',
            referrer text DEFAULT NULL,
            fbc varchar(255) DEFAULT '',
            fbp varchar(255) DEFAULT '',
            campaign_id varchar(100) DEFAULT '',
            adset_id varchar(100) DEFAULT '',
            ad_id varchar(100) DEFAULT '',
            campaign_name varchar(255) DEFAULT '',
            adset_name varchar(255) DEFAULT '',
            ad_name varchar(255) DEFAULT '',
            qualificacao_status varchar(50) DEFAULT 'pendente',
            qualificacao_data datetime DEFAULT NULL,
            qualificacao_origem varchar(100) DEFAULT '',
            qualificacao_dados longtext DEFAULT NULL,
            score int(11) DEFAULT 0,
            motivo_qualificacao text DEFAULT NULL,
            whatsapp_status varchar(50) DEFAULT '',
            primeira_mensagem datetime DEFAULT NULL,
            ultima_mensagem datetime DEFAULT NULL,
            mensagens_recebidas int(11) DEFAULT 0,
            mensagens_enviadas int(11) DEFAULT 0,
            conversation_id varchar(100) DEFAULT '',
            capi_lead_sent tinyint(1) DEFAULT 0,
            capi_lead_time datetime DEFAULT NULL,
            capi_qualified_sent tinyint(1) DEFAULT 0,
            capi_qualified_time datetime DEFAULT NULL,
            capi_event_id varchar(100) DEFAULT '',
            utm_source_first varchar(255) DEFAULT '',
            data_primeira_captura datetime DEFAULT NULL,
            dias_para_conversao int(11) DEFAULT NULL,
            polo varchar(150) DEFAULT '',
            phone_number_id varchar(100) DEFAULT '',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY telefone_normalizado (telefone_normalizado),
            KEY email (email(191)),
            KEY qualificacao_status (qualificacao_status),
            KEY utm_campaign (utm_campaign(191)),
            KEY polo (polo(100)),
            KEY created_at (created_at)
        ) {$charset_collate};";

        // Tabela de Histórico de Auditoria do Lead
        $sql_history = "CREATE TABLE {$history_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            lead_id bigint(20) unsigned NOT NULL,
            campo varchar(100) NOT NULL,
            valor_anterior longtext DEFAULT NULL,
            valor_novo longtext DEFAULT NULL,
            origem varchar(100) DEFAULT 'system',
            usuario_id bigint(20) unsigned DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY lead_id (lead_id),
            KEY created_at (created_at)
        ) {$charset_collate};";

        // Tabela de Mensagens do WhatsApp
        $sql_whatsapp = "CREATE TABLE {$whatsapp_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            lead_id bigint(20) unsigned DEFAULT 0,
            telefone_normalizado varchar(50) NOT NULL,
            message_id varchar(100) DEFAULT '',
            conversation_id varchar(100) DEFAULT '',
            direcao varchar(20) DEFAULT 'inbound',
            tipo_mensagem varchar(50) DEFAULT 'text',
            conteudo text DEFAULT NULL,
            status_entrega varchar(50) DEFAULT '',
            polo varchar(150) DEFAULT '',
            phone_number_id varchar(100) DEFAULT '',
            payload_bruto longtext DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY telefone_normalizado (telefone_normalizado),
            KEY lead_id (lead_id),
            KEY message_id (message_id),
            KEY phone_number_id (phone_number_id)
        ) {$charset_collate};";

        // Tabela de Logs do Sistema
        $sql_logs = "CREATE TABLE {$logs_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            categoria varchar(50) NOT NULL,
            nivel varchar(20) DEFAULT 'info',
            mensagem text NOT NULL,
            contexto longtext DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY categoria (categoria),
            KEY nivel (nivel),
            KEY created_at (created_at)
        ) {$charset_collate};";

        // Tabela de Importações de Planilha
        $sql_imports = "CREATE TABLE {$imports_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            nome_arquivo varchar(255) NOT NULL,
            total_linhas int(11) DEFAULT 0,
            linhas_processadas int(11) DEFAULT 0,
            leads_qualificados int(11) DEFAULT 0,
            leads_nao_encontrados int(11) DEFAULT 0,
            mapeamento_colunas text DEFAULT NULL,
            status varchar(50) DEFAULT 'concluido',
            usuario_id bigint(20) unsigned DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) {$charset_collate};";

        dbDelta($sql_leads);
        dbDelta($sql_history);
        dbDelta($sql_whatsapp);
        dbDelta($sql_logs);
        dbDelta($sql_imports);

        update_option('lead_intelligence_db_version', LEAD_INTELLIGENCE_DB_VERSION);
    }

    /**
     * Verifica se o banco precisa ser atualizado
     */
    public static function maybe_update() {
        $installed_ver = get_option('lead_intelligence_db_version', '0.0.0');
        if (version_compare($installed_ver, LEAD_INTELLIGENCE_DB_VERSION, '<')) {
            self::create_tables();
            \LeadIntelligence\WhatsApp\MessageHandler::retro_enrich_leads();
            self::retro_enrich_google_leads();
            self::unify_and_enrich_leads_by_phone();
        }
    }

    /**
     * Unifica leads duplicados por telefone normalizado, mesclando dados da Planilha com UTMs do Elementor
     * e calculando o ciclo de venda (lead time em dias) com preservação de histórico.
     *
     * @return int Quantidade de registros secundários mesclados
     */
    public static function unify_and_enrich_leads_by_phone() {
        global $wpdb;
        $leads_table   = self::get_leads_table();
        $history_table = self::get_history_table();

        // 1. Busca telefones normalizados com registros duplicados
        $duplicates = $wpdb->get_results(
            "SELECT telefone_normalizado, COUNT(*) as qtd 
             FROM {$leads_table} 
             WHERE telefone_normalizado != '' 
             GROUP BY telefone_normalizado 
             HAVING qtd > 1"
        );

        $merged_count = 0;

        if (!empty($duplicates)) {
            foreach ($duplicates as $dup) {
                $tel = $dup->telefone_normalizado;
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM {$leads_table} WHERE telefone_normalizado = %s ORDER BY id ASC",
                    $tel
                ));

                if (count($rows) < 2) {
                    continue;
                }

                // Identifica o melhor registro mestre:
                // Prioridade: Aquele com status 'qualificado' (veio da planilha de matrículas)
                $master = null;
                foreach ($rows as $r) {
                    if ($r->qualificacao_status === 'qualificado') {
                        $master = $r;
                        break;
                    }
                }
                // Se nenhum for qualificado, prioriza o que tem utm_source rica
                if (!$master) {
                    foreach ($rows as $r) {
                        if (!empty($r->utm_source) && !in_array($r->utm_source, ['google', 'meta', 'planilha'])) {
                            $master = $r;
                            break;
                        }
                    }
                }
                // Fallback para o primeiro registro
                if (!$master) {
                    $master = $rows[0];
                }

                $other_rows = [];
                foreach ($rows as $r) {
                    if ($r->id != $master->id) {
                        $other_rows[] = $r;
                    }
                }

                $updates = [];
                $earliest_date = $master->data_cadastro;
                $first_utm_source = $master->utm_source;

                foreach ($other_rows as $other) {
                    // Se o outro foi cadastrado antes, registra como primeira captura
                    if (!empty($other->data_cadastro) && (empty($earliest_date) || strtotime($other->data_cadastro) < strtotime($earliest_date))) {
                        $earliest_date = $other->data_cadastro;
                        if (!empty($other->utm_source)) {
                            $first_utm_source = $other->utm_source;
                        }
                    }

                    // Herda qualificação se o outro tiver
                    if ($master->qualificacao_status !== 'qualificado' && $other->qualificacao_status === 'qualificado') {
                        $updates['qualificacao_status'] = 'qualificado';
                        $updates['qualificacao_data']   = $other->qualificacao_data;
                        $updates['qualificacao_origem'] = $other->qualificacao_origem;
                        $updates['qualificacao_dados']  = $other->qualificacao_dados;
                        $master->qualificacao_status    = 'qualificado';
                        $master->qualificacao_data      = $other->qualificacao_data;
                    }

                    // Herda UTMs ricas se o master estiver sem ou com genérico ('google', 'meta', 'planilha')
                    $master_has_generic = empty($master->utm_source) || in_array($master->utm_source, ['google', 'meta', 'planilha']);
                    if ($master_has_generic && !empty($other->utm_source) && !in_array($other->utm_source, ['google', 'meta', 'planilha'])) {
                        $updates['utm_source']    = $other->utm_source;
                        $updates['utm_campaign']  = $other->utm_campaign ?: $master->utm_campaign;
                        $updates['utm_medium']    = $other->utm_medium ?: $master->utm_medium;
                        $updates['utm_term']      = $other->utm_term ?: $master->utm_term;
                        $updates['utm_content']   = $other->utm_content ?: $master->utm_content;
                        $updates['campaign_name'] = $other->campaign_name ?: $master->campaign_name;
                        $updates['ad_name']       = $other->ad_name ?: $master->ad_name;
                        $updates['pagina_origem'] = $other->pagina_origem ?: $master->pagina_origem;
                    }

                    // Herda identificadores de clique
                    if (empty($master->gclid) && !empty($other->gclid)) {
                        $updates['gclid'] = $other->gclid;
                    }
                    if (empty($master->fbclid) && !empty($other->fbclid)) {
                        $updates['fbclid'] = $other->fbclid;
                    }

                    // Herda campos de contato
                    if (empty($master->nome) && !empty($other->nome)) {
                        $updates['nome'] = $other->nome;
                    }
                    if (empty($master->email) && !empty($other->email)) {
                        $updates['email'] = $other->email;
                    }
                    if (empty($master->tipo_curso) && !empty($other->tipo_curso)) {
                        $updates['tipo_curso'] = $other->tipo_curso;
                    }
                    if (empty($master->area_interesse) && !empty($other->area_interesse)) {
                        $updates['area_interesse'] = $other->area_interesse;
                    }
                }

                // Preserva o primeiro toque e calcula tempo de fechamento
                $updates['data_primeira_captura'] = $earliest_date;
                $updates['utm_source_first'] = $first_utm_source ?: ($updates['utm_source'] ?? $master->utm_source);

                $qual_date = $updates['qualificacao_data'] ?? $master->qualificacao_data;
                if (!empty($earliest_date) && !empty($qual_date) && ($master->qualificacao_status === 'qualificado' || ($updates['qualificacao_status'] ?? '') === 'qualificado')) {
                    $t_cad  = strtotime($earliest_date);
                    $t_qual = strtotime($qual_date);
                    if ($t_qual >= $t_cad) {
                        $updates['dias_para_conversao'] = (int) floor(($t_qual - $t_cad) / 86400);
                    }
                }

                $updates['updated_at'] = current_time('mysql');
                $wpdb->update($leads_table, $updates, ['id' => $master->id]);

                // Transfere histórico de auditoria e remove os registros secundários
                foreach ($other_rows as $other) {
                    $wpdb->query($wpdb->prepare("UPDATE {$history_table} SET lead_id = %d WHERE lead_id = %d", $master->id, $other->id));
                    $wpdb->delete($leads_table, ['id' => $other->id]);
                }

                $merged_count += count($other_rows);
            }
        }

        return $merged_count;
    }

    /**
     * Recupera parâmetros do Google Ads (gclid, campaign_id, etc.) retroativamente da pagina_origem
     */
    public static function retro_enrich_google_leads() {
        global $wpdb;
        $leads_table = self::get_leads_table();

        $leads = $wpdb->get_results("SELECT id, pagina_origem, gclid, campaign_id, utm_source, utm_campaign FROM {$leads_table} WHERE INSTR(pagina_origem, 'gclid=') > 0 OR INSTR(pagina_origem, 'utm_') > 0");
        if (empty($leads)) {
            return;
        }

        foreach ($leads as $l) {
            $query = parse_url($l->pagina_origem, PHP_URL_QUERY);
            if (empty($query)) {
                continue;
            }

            $params = [];
            parse_str($query, $params);

            $updates = [];
            if (empty($l->gclid) && !empty($params['gclid'])) {
                $updates['gclid'] = sanitize_text_field($params['gclid']);
            }
            if (!empty($params['gad_campaignid']) && empty($l->campaign_id)) {
                $updates['campaign_id'] = sanitize_text_field($params['gad_campaignid']);
            }
            if (!empty($params['gad_source'])) {
                $updates['gad_source'] = sanitize_text_field($params['gad_source']);
            }
            if (empty($l->utm_source) && !empty($params['utm_source'])) {
                $updates['utm_source'] = sanitize_text_field($params['utm_source']);
            }
            if (empty($l->utm_campaign) && !empty($params['utm_campaign'])) {
                $updates['utm_campaign'] = sanitize_text_field($params['utm_campaign']);
            } elseif (empty($l->utm_campaign) && !empty($params['gad_campaignid'])) {
                $updates['utm_campaign'] = sanitize_text_field($params['gad_campaignid']);
            }

            if (!empty($updates)) {
                $updates['updated_at'] = current_time('mysql');
                $wpdb->update($leads_table, $updates, ['id' => $l->id]);
            }
        }
    }
}
