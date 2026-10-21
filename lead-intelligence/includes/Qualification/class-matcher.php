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
     * @param string $channel Canal de origem ('google_ads' | 'meta_ads' | 'planilha')
     * @return array ['status' => 'matched'|'created'|'skipped', 'lead_id' => int, 'reason' => string]
     */
    public static function process_row($row_data, $mapping, $filename, $import_id = 0, $channel = 'google_ads') {
        global $wpdb;
        $leads_table = DbSchema::get_leads_table();
        $history_table = DbSchema::get_history_table();

        // 1. Extração dos campos mapeados com auto-detecção inteligente
        $raw_phone   = self::get_mapped_value($row_data, $mapping, 'telefone');
        $raw_email   = self::get_mapped_value($row_data, $mapping, 'email');
        $raw_name    = self::get_mapped_value($row_data, $mapping, 'nome');
        $raw_date    = self::get_mapped_value($row_data, $mapping, 'data');
        $raw_course  = self::get_mapped_value($row_data, $mapping, 'curso');
        $raw_area    = self::get_mapped_value($row_data, $mapping, 'area');
        $raw_status  = self::get_mapped_value($row_data, $mapping, 'status');
        $raw_etapa   = self::get_mapped_value($row_data, $mapping, 'etapa');
        
        // Se a etapa estiver vazia mas status tiver sido preenchido
        if (empty($raw_etapa) && !empty($raw_status)) {
            $raw_etapa = $raw_status;
        }

        $raw_gclid   = self::get_mapped_value($row_data, $mapping, 'gclid');
        $raw_fbclid  = self::get_mapped_value($row_data, $mapping, 'fbclid');
        $raw_ad_id   = self::get_mapped_value($row_data, $mapping, 'ad_id');
        $raw_camp    = self::get_mapped_value($row_data, $mapping, 'campanha');

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

        // Determina o status da qualificação avaliando tanto a etapa do funil quanto a coluna de status
        $default_status     = !empty($mapping['default_status']) ? $mapping['default_status'] : 'qualificado';
        $status_from_etapa  = !empty($raw_etapa) ? self::normalize_status($raw_etapa) : '';
        $status_from_status = !empty($raw_status) ? self::normalize_status($raw_status) : '';

        // Regra de prioridade:
        // 1. Se qualquer campo indicar qualificado/venda confirmada -> qualificado
        // 2. Se qualquer campo indicar perda/desqualificado -> nao_qualificado
        // 3. Fallback para etapa informada, status informado ou padrão configurado
        if ($status_from_etapa === 'qualificado' || $status_from_status === 'qualificado') {
            $qualificacao_status = 'qualificado';
        } elseif ($status_from_etapa === 'nao_qualificado' || $status_from_status === 'nao_qualificado') {
            $qualificacao_status = 'nao_qualificado';
        } elseif (!empty($status_from_etapa)) {
            $qualificacao_status = $status_from_etapa;
        } elseif (!empty($status_from_status)) {
            $qualificacao_status = $status_from_status;
        } else {
            $qualificacao_status = $default_status;
        }

        // Define a etapa do funil preservando o termo real da planilha (ex: 'Desqualificado', 'Perda', 'Tentativa de Contato')
        $final_etapa = !empty($raw_etapa) ? sanitize_text_field($raw_etapa) : (!empty($raw_status) ? sanitize_text_field($raw_status) : '');
        if (empty($final_etapa)) {
            $final_etapa = ($qualificacao_status === 'qualificado') ? 'Matrícula Realizada' : (($qualificacao_status === 'nao_qualificado') ? 'Desqualificado' : 'Formulário Enviado');
        }

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
                'etapa_funil'         => $final_etapa,
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
            if (empty($existing->gclid) && !empty($raw_gclid)) {
                $update_data['gclid'] = sanitize_text_field($raw_gclid);
            }
            if (empty($existing->fbclid) && !empty($raw_fbclid)) {
                $update_data['fbclid'] = sanitize_text_field($raw_fbclid);
            }
            if (empty($existing->ad_id) && !empty($raw_ad_id)) {
                $update_data['ad_id'] = sanitize_text_field($raw_ad_id);
            }
            if (empty($existing->utm_campaign) && !empty($raw_camp)) {
                $update_data['utm_campaign'] = sanitize_text_field($raw_camp);
            }

            // Preserva e calcula dados de ciclo longo (Lead Time em dias)
            $date_first = !empty($existing->data_primeira_captura) ? $existing->data_primeira_captura : (!empty($existing->data_cadastro) ? $existing->data_cadastro : '');
            if (empty($existing->data_primeira_captura) && !empty($date_first)) {
                $update_data['data_primeira_captura'] = $date_first;
            }
            if (empty($existing->utm_source_first) && !empty($existing->utm_source)) {
                $update_data['utm_source_first'] = $existing->utm_source;
            }
            if (!empty($date_first) && !empty($qualificacao_data)) {
                $t_first = strtotime($date_first);
                $t_qual  = strtotime($qualificacao_data);
                if ($t_qual >= $t_first) {
                    $update_data['dias_para_conversao'] = (int) floor(($t_qual - $t_first) / 86400);
                }
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
                'canal'    => $channel,
            ]);

            // Dispara evento LeadQualified para a Meta CAPI se o status for qualificado (apenas canal Meta Ads)
            if ($qualificacao_status === 'qualificado' && $channel === 'meta_ads') {
                CapiQueue::enqueue_qualified($lead_id);
            }

            return [
                'status'  => 'matched',
                'lead_id' => $lead_id,
                'reason'  => "Lead #{$lead_id} cruzado e qualificado.",
            ];
        }

        // CASO B: LEAD NÃO EXISTIA NO BANCO (Registro novo originado da planilha)
        $form_label = 'Importação Planilha';
        $utm_source = 'planilha';
        if ($channel === 'google_ads') {
            $form_label = 'Google Ads (Planilha)';
            $utm_source = 'google';
        } elseif ($channel === 'meta_ads') {
            $form_label = 'Meta Ads (Planilha)';
            $utm_source = 'meta';
        }

        $new_lead_data = [
            'uuid'                 => wp_generate_uuid4(),
            'nome'                 => sanitize_text_field($raw_name),
            'email'                => $email_norm,
            'telefone'             => sanitize_text_field($raw_phone),
            'telefone_normalizado' => $phone_norm,
            'tipo_curso'           => sanitize_text_field($raw_course),
            'area_interesse'       => sanitize_text_field($raw_area),
            'gclid'                => sanitize_text_field($raw_gclid),
            'fbclid'               => sanitize_text_field($raw_fbclid),
            'ad_id'                => sanitize_text_field($raw_ad_id),
            'utm_source'           => $utm_source,
            'utm_medium'           => 'cpc',
            'utm_campaign'         => sanitize_text_field($raw_camp),
            'formulario_nome'      => $form_label,
            'pagina_origem'        => 'Planilha: ' . sanitize_text_field($filename),
            'qualificacao_status'  => $qualificacao_status,
            'etapa_funil'          => $final_etapa,
            'qualificacao_data'    => $qualificacao_data,
            'qualificacao_origem'  => 'planilha: ' . sanitize_text_field($filename),
            'qualificacao_dados'   => $json_dados,
            'score'                => 10,
            'motivo_qualificacao'  => "Importado via {$form_label} (sem formulário Elementor prévio)",
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

        // Dispara evento LeadQualified para a Meta CAPI se o status for qualificado (apenas canal Meta Ads)
        if ($qualificacao_status === 'qualificado' && $channel === 'meta_ads') {
            CapiQueue::enqueue_qualified($new_id);
        }

        return [
            'status'  => 'created',
            'lead_id' => $new_id,
            'reason'  => "Novo lead #{$new_id} criado a partir da planilha.",
        ];
    }

    /**
     * Extrai valor da coluna mapeada com auto-detecção resiliente
     */
    private static function get_mapped_value($row_data, $mapping, $key) {
        // 1. Tenta pelo mapeamento explícito com tolerância a maiúsculas/minúsculas, espaços e preposições (do/da/de)
        if (!empty($mapping[$key])) {
            $col_name = $mapping[$key];
            if (isset($row_data[$col_name]) && trim((string) $row_data[$col_name]) !== '') {
                return trim((string) $row_data[$col_name]);
            }
            $clean_target = str_replace(['-', ' ', '_'], '', strtolower($col_name));
            $target_simple = str_replace(['do', 'da', 'de', 'para', 'em'], '', $clean_target);

            foreach ($row_data as $col => $val) {
                $raw_c = preg_replace('/^\xEF\xBB\xBF/', '', (string) $col);
                $clean_col = str_replace(['-', ' ', '_'], '', strtolower(trim($raw_c)));
                $col_simple = str_replace(['do', 'da', 'de', 'para', 'em'], '', $clean_col);

                if (
                    ($clean_col === $clean_target || $col_simple === $target_simple || strpos($clean_col, $clean_target) !== false || strpos($clean_target, $clean_col) !== false)
                    && trim((string) $val) !== ''
                ) {
                    return trim((string) $val);
                }
            }
        }

        // 2. Auto-detecção inteligente de colunas comuns da planilha
        $alias_map = [
            'etapa'    => ['etapa_funil', 'etapadofunil', 'etapafunil', 'etapa', 'fase', 'funil', 'estagio', 'situacao_lead', 'situacao', 'motivo_perda', 'motivoperda', 'motivo_desqualificacao', 'motivodesqualificacao', 'motivo', 'desqualificacao', 'substatus', 'desfecho', 'resultado', 'motivo_da_perda', 'motivodaperda', 'fase_do_lead', 'fasedolead', 'tentativa_de_contato', 'tentativadecontato'],
            'telefone' => ['telefone', 'whatsapp', 'celular', 'phone', 'tel', 'contato', 'fone', 'numero'],
            'email'    => ['email', 'e-mail', 'e_mail', 'correio_eletronico'],
            'nome'     => ['nome', 'name', 'cliente', 'aluno', 'lead_nome', 'nome_completo', 'nomecompleto'],
            'status'   => ['status', 'situacao', 'estado', 'perda', 'qualificacao', 'fase', 'fasedolead', 'fase_do_lead', 'status_lead', 'statusdolead', 'desfecho', 'resultado'],
            'data'     => ['datacriação', 'datacriacao', 'data_criacao', 'data', 'date', 'data_cadastro', 'datamodificacao', 'data_modificacao', 'data_do_lead', 'datadolead'],
            'gclid'    => ['gclid', 'google_click_id', 'google_click'],
            'fbclid'   => ['fbclid', 'meta_click_id', 'fb_click'],
            'ad_id'    => ['adid', 'ad_id', 'id_anuncio', 'anuncio_id'],
            'campanha' => ['campanha', 'campaign', 'utm_campaign', 'nome_campanha', 'anuncio'],
            'curso'    => ['curso', 'tipo_curso', 'modalidade'],
            'area'     => ['instancia', 'polo', 'unidade', 'area', 'area_interesse'],
            'motivo'   => ['motivo_perda', 'motivoperda', 'motivo_desqualificacao', 'motivodesqualificacao', 'motivo', 'justificativa', 'motivo_da_perda', 'motivodaperda'],
        ];

        $candidates = $alias_map[$key] ?? [];
        if (!empty($candidates)) {
            foreach ($row_data as $col => $val) {
                $clean_col = strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $col)));
                $clean_col = str_replace(['-', ' ', '_'], '', $clean_col);
                $col_simple = str_replace(['do', 'da', 'de', 'para', 'em'], '', $clean_col);

                foreach ($candidates as $cand) {
                    $clean_cand = str_replace(['-', ' ', '_'], '', strtolower($cand));
                    $cand_simple = str_replace(['do', 'da', 'de', 'para', 'em'], '', $clean_cand);
                    if ($clean_col === $clean_cand || $col_simple === $cand_simple || strpos($clean_col, $clean_cand) !== false || strpos($clean_cand, $clean_col) !== false) {
                        $val_str = trim((string) $val);
                        if ($val_str !== '') {
                            return $val_str;
                        }
                    }
                }
            }
        }

        return '';
    }

    /**
     * Normaliza termos variados de status e etapas do funil para os padrões do sistema
     */
    public static function normalize_status($status_str) {
        $clean = mb_strtolower(trim((string) $status_str), 'UTF-8');
        $unaccented = function_exists('remove_accents') ? remove_accents($clean) : $clean;

        // 1. Desqualificados / Perdas / Não convertidos / Sem Interesse
        if (
            preg_match('/(desqualificad|nao[ _-]?convertid|não[ _-]?convertid|perda|perdido|cancelad|recusad|invalido|inválido|reprovad|sem[ _-]?perfil|sem[ _-]?interesse|descarte|desistiu)/iu', $clean) ||
            preg_match('/(desqualificad|nao[ _-]?convertid|perda|perdido|cancelad|recusad|invalido|reprovad|sem[ _-]?perfil|sem[ _-]?interesse|descarte|desistiu)/i', $unaccented)
        ) {
            return 'nao_qualificado';
        }

        // 2. Qualificados / Matrículas / Vendas Confirmadas
        if (
            preg_match('/(venda|vendid|matricul|aluno|fechado|ganho|pago|aprovad)/iu', $clean) ||
            preg_match('/(venda|vendid|matricul|aluno|fechado|ganho|pago|aprovad)/i', $unaccented)
        ) {
            return 'qualificado';
        }

        // 3. Qualquer outra etapa do pipeline (Lead Atribuído, Tentativa de Contato, Negociação, Remarketing, Ativo, etc.) é PENDENTE
        return 'pendente';
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
            return wp_date('Y-m-d H:i:s', $timestamp);
        }

        return current_time('mysql');
    }
}
