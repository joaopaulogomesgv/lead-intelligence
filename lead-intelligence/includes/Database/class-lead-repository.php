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
     * Retorna contadores agregados para dashboard e badges
     */
    public static function get_status_counts() {
        global $wpdb;
        $table = DbSchema::get_leads_table();

        $results = $wpdb->get_results(
            "SELECT qualificacao_status, COUNT(*) as total FROM {$table} GROUP BY qualificacao_status",
            OBJECT_K
        );

        return [
            'total'              => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"),
            'qualificado'        => isset($results['qualificado']) ? (int) $results['qualificado']->total : 0,
            'nao_qualificado'    => isset($results['nao_qualificado']) ? (int) $results['nao_qualificado']->total : 0,
            'pendente'           => isset($results['pendente']) ? (int) $results['pendente']->total : 0,
            'sem_correspondencia'=> isset($results['sem_correspondencia']) ? (int) $results['sem_correspondencia']->total : 0,
        ];
    }
}
