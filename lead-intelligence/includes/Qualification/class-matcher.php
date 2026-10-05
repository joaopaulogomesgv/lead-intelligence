<?php
namespace LeadIntelligence\Qualification;

use LeadIntelligence\Database\DbSchema;
use LeadIntelligence\Database\LeadRepository;
use LeadIntelligence\PhoneNormalizer;
use LeadIntelligence\Logger;
use LeadIntelligence\MetaCapi\CapiQueue;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Motor de Cruzamento e Vinculação Automática de Leads com Planilhas
 */
class Matcher {

    /**
     * Processa uma única linha da planilha e realiza a vinculação
     *
     * @param array $row_data Dados da linha associativa ['Coluna' => 'Valor']
     * @param array $mapping Mapeamento de colunas selecionado pelo usuário
     * @param string $filename Nome do arquivo da planilha
     * @param int $import_id ID do lote de importação
     * @return array ['status' => 'matched'|'created'|'skipped', 'lead_id' => int, 'reason' => string]
     */
    public static function process_row($row_data, $mapping, $filename, $import_id = 0) {
        global $wpdb;
        $leads_table = DbSchema::get_leads_table();
        $history_table = DbSchema::get_history_table();

        // 1. Extração dos campos mapeados
        $raw_phone = self::get_mapped_value($row_data, $mapping, 'telefone');
        $raw_email = self::get_mapped_value($row_data, $mapping, 'email');
        $raw_name  = self::get_mapped_value($row_data, $mapping, 'nome');
        $raw_date  = self::get_mapped_value($row_data, $mapping, 'data');
        $raw_course= self::get_mapped_value($row_data, $mapping, 'curso');
        $raw_area  = self::get_mapped_value($row_data, $mapping, 'area');
        $raw_status= self::get_mapped_value($row_data, $mapping, 'status');

        $phone_norm = !empty($raw_phone) ? PhoneNormalizer::normalize($raw_phone) : '';
        $email_norm = !empty($raw_email) ? sanitize_email($raw_email) : '';

        // Se a linha não tiver nem telefone nem e-mail, não há como cruzar
        if (empty($phone_norm) && empty($email_norm)) {
            return [
                'status'  => 'skipped',
                'lead_id' => 0,
                'reason'  => 'Linha sem telefone ou e-mail válido para identificação.',
            ];
        }

        // Determina o status da qualificação (valor mapeado ou default da planilha)
        $default_status = !empty($mapping['default_status']) ? $mapping['default_status'] : 'qualificado';
        $qualificacao_status = !empty($raw_status) ? self::normalize_status($raw_status) : $default_status;

        $qualificacao_data = !empty($raw_date) ? self::parse_date($raw_date) : current_time('mysql');
        $json_dados = wp_json_encode($row_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // 2. Busca de correspondência: Prioridade 1 = Telefone Normalizado
        $existing = null;
        if (!empty($phone_norm)) {
            $existing = LeadRepository::find_by_phone($phone_norm);
        }

        // Prioridade 2 = E-mail
        if (!$existing && !empty($email_norm)) {
            $existing = LeadRepository::find_by_email($email_norm);
        }

        $current_user_id = get_current_user_id();

        // CASO A: LEAD ENCONTRADO NO BANCO (Elementor prévio ou cadastro anterior)
        if ($existing) {
            $lead_id = (int) $existing->id;
            $old_status = (string) $existing->qualificacao_status;

            $update_data = [
                'qualificacao_status' => $qualificacao_status,
                'qualificacao_data'   => $qualificacao_data,
                'qualificacao_origem' => 'planilha: ' . sanitize_text_field($filename),
                'qualificacao_dados'  => $json_dados,
                'motivo_qualificacao' => 'Vinculado com sucesso via planilha ' . sanitize_text_field($filename),
                'updated_at'          => current_time('mysql'),
            ];

            // Atualiza campos cadastrais faltantes sem sobrescrever dados preenchidos
            if (empty($existing->nome) && !empty($raw_name)) {
                $update_data['nome'] = sanitize_text_field($raw_name);
            }
            if (empty($existing->email) && !empty($email_norm)) {
                $update_data['email'] = $email_norm;
            }
            if (empty($existing->tipo_curso) && !empty($raw_course)) {
                $update_data['tipo_curso'] = sanitize_text_field($raw_course);
            }
            if (empty($existing->area_interesse) && !empty($raw_area)) {
                $update_data['area_interesse'] = sanitize_text_field($raw_area);
            }

            // Incrementa score de qualidade
            $new_score = ((int) $existing->score) + 10;
            $update_data['score'] = $new_score;

            $wpdb->update($leads_table, $update_data, ['id' => $lead_id]);

            // Auditoria no histórico
            $wpdb->insert($history_table, [
                'lead_id'        => $lead_id,
                'campo'          => 'qualificacao_status',
                'valor_anterior' => $old_status,
                'valor_novo'     => $qualificacao_status . " (via {$filename})",
                'origem'         => 'planilha',
                'usuario_id'     => $current_user_id,
                'created_at'     => current_time('mysql'),
            ]);

            Logger::debug('Planilha', "Lead #{$lead_id} cruzado com sucesso via planilha {$filename}.", [
                'telefone' => $phone_norm,
                'email'    => $email_norm,
                'status'   => $qualificacao_status,
            ]);

            // Dispara evento LeadQualified para a Meta CAPI se o status for qualificado
            if ($qualificacao_status === 'qualificado') {
                CapiQueue::enqueue_qualified($lead_id);
            }

            return [
                'status'  => 'matched',
                'lead_id' => $lead_id,
                'reason'  => "Lead #{$lead_id} cruzado e qualificado.",
            ];
        }

        // CASO B: LEAD NÃO EXISTIA NO BANCO (Registro novo originado da planilha)
        $new_lead_data = [
            'uuid'                 => wp_generate_uuid4(),
            'nome'                 => sanitize_text_field($raw_name),
            'email'                => $email_norm,
            'telefone'             => sanitize_text_field($raw_phone),
            'telefone_normalizado' => $phone_norm,
            'tipo_curso'           => sanitize_text_field($raw_course),
            'area_interesse'       => sanitize_text_field($raw_area),
            'formulario_nome'      => 'Importação Planilha',
            'pagina_origem'        => 'Arquivo: ' . sanitize_text_field($filename),
            'qualificacao_status'  => $qualificacao_status,
            'qualificacao_data'    => $qualificacao_data,
            'qualificacao_origem'  => 'planilha: ' . sanitize_text_field($filename),
            'qualificacao_dados'   => $json_dados,
            'score'                => 10,
            'motivo_qualificacao'  => 'Importado via planilha (sem formulário Elementor prévio)',
            'data_cadastro'        => $qualificacao_data,
            'created_at'           => current_time('mysql'),
            'updated_at'           => current_time('mysql'),
        ];

        $wpdb->insert($leads_table, $new_lead_data);
        $new_id = $wpdb->insert_id;

        $wpdb->insert($history_table, [
            'lead_id'        => $new_id,
            'campo'          => 'criacao',
            'valor_anterior' => '',
            'valor_novo'     => "Lead cadastrado via importação de planilha ({$filename})",
            'origem'         => 'planilha',
            'usuario_id'     => $current_user_id,
            'created_at'     => current_time('mysql'),
        ]);

        // Dispara evento LeadQualified para a Meta CAPI se o status for qualificado
        if ($qualificacao_status === 'qualificado') {
            CapiQueue::enqueue_qualified($new_id);
        }

        return [
            'status'  => 'created',
            'lead_id' => $new_id,
            'reason'  => "Novo lead #{$new_id} criado a partir da planilha.",
        ];
    }

    /**
     * Extrai valor da coluna mapeada
     */
    private static function get_mapped_value($row_data, $mapping, $key) {
        if (empty($mapping[$key])) {
            return '';
        }
        $col_name = $mapping[$key];
        return isset($row_data[$col_name]) ? trim((string) $row_data[$col_name]) : '';
    }

    /**
     * Normaliza termos variados de status para os padrões do sistema
     */
    public static function normalize_status($status_str) {
        $clean = strtolower(trim((string) $status_str));

        if (preg_match('/(qualificad|matriculad|aprovad|pago|sim|ganho|fechado|aluno)/i', $clean)) {
            return 'qualificado';
        }
        if (preg_match('/(desqualificad|nao|no|cancelad|perdido|recusad|invalido|reprovad)/i', $clean)) {
            return 'nao_qualificado';
        }
        if (preg_match('/(pendente|aguardando|em analise|negociacao)/i', $clean)) {
            return 'pendente';
        }

        return 'qualificado';
    }

    /**
     * Normaliza datas nos formatos mais comuns (BR d/m/Y, ISO Y-m-d, timestamps)
     */
    private static function parse_date($date_str) {
        $clean = trim((string) $date_str);
        if (empty($clean)) {
            return current_time('mysql');
        }

        // Padrão Brasileiro: 15/04/2026 ou 15/04/2026 14:30:00
        if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})(.*)$#', $clean, $matches)) {
            $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
            $year = $matches[3];
            $time = trim($matches[4]);
            if (empty($time)) {
                $time = '12:00:00';
            }
            return "{$year}-{$month}-{$day} {$time}";
        }

        $timestamp = strtotime($clean);
        if ($timestamp !== false) {
            return gmdate('Y-m-d H:i:s', $timestamp);
        }

        return current_time('mysql');
    }
}
