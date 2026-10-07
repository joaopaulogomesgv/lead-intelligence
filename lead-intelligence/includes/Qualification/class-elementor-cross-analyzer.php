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
 * Motor de Análise, Desduplicação e Cruzamento de Planilhas do Elementor com Leads Qualificados
 */
class ElementorCrossAnalyzer {

    /**
     * Executa a análise completa cruzando as planilhas do Elementor com a de Qualificados
     *
     * @param array $elementor_file_paths Lista de caminhos de arquivos do Elementor (CSV ou XLSX)
     * @param string $qualified_file_path Caminho do arquivo de Leads Qualificados
     * @return array Resultado da análise com resumo, breakdown e lista de leads únicos
     */
    public static function analyze_spreadsheets($elementor_file_paths, $qualified_file_path) {
        @ini_set('memory_limit', '512M');
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        $start_time = microtime(true);

        if (!is_array($elementor_file_paths)) {
            $elementor_file_paths = [$elementor_file_paths];
        }

        // 1. Processar e Indexar a Planilha de Leads Qualificados
        $qualified_index = self::index_qualified_spreadsheet($qualified_file_path);

        // 2. Processar e Desduplicar as Planilhas do Elementor
        $elementor_data = self::process_elementor_spreadsheets($elementor_file_paths);

        $unique_leads = $elementor_data['unique_leads'];
        $total_submissions = $elementor_data['total_submissions'];
        $duplicate_leads_count = $elementor_data['duplicate_leads_count'];

        // 3. Cruzar Leads Únicos com a Base de Qualificados
        $total_qualified = 0;
        $total_not_qualified = 0;

        $by_campaign = [];
        $by_course   = [];
        $by_area     = [];
        $by_polo     = [];

        foreach ($unique_leads as $key => &$lead) {
            $match = null;
            $match_type = '';

            // Prioridade 1: Telefone Normalizado (+55 DDD 9 dígitos e variações)
            if (!empty($lead['telefone_normalizado'])) {
                $variations = PhoneNormalizer::get_lookup_variations($lead['telefone_normalizado']);
                foreach ($variations as $var) {
                    if (isset($qualified_index['by_phone'][$var])) {
                        $match = $qualified_index['by_phone'][$var];
                        $match_type = 'telefone';
                        break;
                    }
                }
            }

            // Prioridade 2: E-mail (minúsculo e sem espaços)
            if (!$match && !empty($lead['email'])) {
                $clean_email = strtolower(trim($lead['email']));
                if (isset($qualified_index['by_email'][$clean_email])) {
                    $match = $qualified_index['by_email'][$clean_email];
                    $match_type = 'email';
                }
            }

            // Prioridade 3: GCLID (se presente)
            if (!$match && !empty($lead['gclid'])) {
                $clean_gclid = trim($lead['gclid']);
                if (isset($qualified_index['by_gclid'][$clean_gclid])) {
                    $match = $qualified_index['by_gclid'][$clean_gclid];
                    $match_type = 'gclid';
                }
            }

            // Atualiza status e metadados da qualificação
            if ($match) {
                $total_qualified++;
                $lead['qualificado'] = true;
                $lead['qualificacao_status'] = 'qualificado';
                $lead['match_tipo'] = $match_type;
                $lead['match_motivo'] = 'Reconhecido por ' . ucfirst($match_type);
                $lead['polo_qualificado'] = !empty($match['instancia']) ? $match['instancia'] : (!empty($match['polo']) ? $match['polo'] : '');
                $lead['data_qualificacao'] = !empty($match['data']) ? $match['data'] : (!empty($match['data_criacao']) ? $match['data_criacao'] : '');
                $lead['crm_lead_id'] = !empty($match['lead_id']) ? $match['lead_id'] : '';

                // Agrupamento por Polo
                $polo_name = !empty($lead['polo_qualificado']) ? $lead['polo_qualificado'] : 'Não especificado';
                if (!isset($by_polo[$polo_name])) {
                    $by_polo[$polo_name] = 0;
                }
                $by_polo[$polo_name]++;
            } else {
                $total_not_qualified++;
                $lead['qualificado'] = false;
                $lead['qualificacao_status'] = 'pendente';
                $lead['match_tipo'] = '';
                $lead['match_motivo'] = 'Pendente / Não localizado na planilha de qualificados';
                $lead['polo_qualificado'] = '';
                $lead['data_qualificacao'] = '';
                $lead['crm_lead_id'] = '';
            }

            // Agrupamento por Campanha (utm_source / utm_campaign)
            $camp_key = !empty($lead['utm_source']) ? $lead['utm_source'] : (!empty($lead['utm_campaign']) ? $lead['utm_campaign'] : 'Direto / Sem UTM');
            if (!isset($by_campaign[$camp_key])) {
                $by_campaign[$camp_key] = [
                    'nome'         => $camp_key,
                    'total'        => 0,
                    'qualificados' => 0,
                    'duplicados'   => 0,
                ];
            }
            $by_campaign[$camp_key]['total']++;
            if ($lead['qualificado']) {
                $by_campaign[$camp_key]['qualificados']++;
            }
            if ($lead['submissoes_count'] > 1) {
                $by_campaign[$camp_key]['duplicados']++;
            }

            // Agrupamento por Tipo de Curso
            $course_key = !empty($lead['tipo_curso']) ? $lead['tipo_curso'] : 'Não informado';
            if (!isset($by_course[$course_key])) {
                $by_course[$course_key] = [
                    'nome'         => $course_key,
                    'total'        => 0,
                    'qualificados' => 0,
                ];
            }
            $by_course[$course_key]['total']++;
            if ($lead['qualificado']) {
                $by_course[$course_key]['qualificados']++;
            }

            // Agrupamento por Área de Interesse
            $area_key = !empty($lead['area_interesse']) ? $lead['area_interesse'] : 'Não informada';
            if (!isset($by_area[$area_key])) {
                $by_area[$area_key] = [
                    'nome'         => $area_key,
                    'total'        => 0,
                    'qualificados' => 0,
                ];
            }
            $by_area[$area_key]['total']++;
            if ($lead['qualificado']) {
                $by_area[$area_key]['qualificados']++;
            }
        }
        unset($lead); // Desfaz referência

        // Calcula taxas percentuais nos agrupamentos
        foreach ($by_campaign as &$camp) {
            $camp['taxa'] = $camp['total'] > 0 ? round(($camp['qualificados'] / $camp['total']) * 100, 2) : 0;
        }
        unset($camp);
        uasort($by_campaign, function ($a, $b) {
            return $b['total'] <=> $a['total'];
        });

        foreach ($by_course as &$crs) {
            $crs['taxa'] = $crs['total'] > 0 ? round(($crs['qualificados'] / $crs['total']) * 100, 2) : 0;
        }
        unset($crs);
        uasort($by_course, function ($a, $b) {
            return $b['total'] <=> $a['total'];
        });

        foreach ($by_area as &$ar) {
            $ar['taxa'] = $ar['total'] > 0 ? round(($ar['qualificados'] / $ar['total']) * 100, 2) : 0;
        }
        unset($ar);
        uasort($by_area, function ($a, $b) {
            return $b['total'] <=> $a['total'];
        });

        arsort($by_polo);

        $total_unique = count($unique_leads);
        $taxa_qualificacao_global = $total_unique > 0 ? round(($total_qualified / $total_unique) * 100, 2) : 0;
        $execution_time = round(microtime(true) - $start_time, 2);

        return [
            'summary' => [
                'total_submissoes_brutas'    => $total_submissions,
                'total_leads_unicos'         => $total_unique,
                'total_duplicados_leads'     => $duplicate_leads_count,
                'total_submissoes_extras'    => max(0, $total_submissions - $total_unique),
                'total_qualificados'         => $total_qualified,
                'total_nao_qualificados'     => $total_not_qualified,
                'taxa_qualificacao_global'   => $taxa_qualificacao_global,
                'total_base_qualificados'    => $qualified_index['total_rows'],
                'tempo_execucao_segundos'    => $execution_time,
            ],
            'by_campaign' => $by_campaign,
            'by_course'   => $by_course,
            'by_area'     => $by_area,
            'by_polo'     => $by_polo,
            'leads'       => array_values($unique_leads),
        ];
    }

    /**
     * Indexa a planilha de leads qualificados em estruturas hash rápidas O(1)
     */
    private static function index_qualified_spreadsheet($file_path) {
        $index = [
            'by_phone'   => [],
            'by_email'   => [],
            'by_gclid'   => [],
            'total_rows' => 0,
        ];

        if (!file_exists($file_path)) {
            return $index;
        }

        try {
            SpreadsheetParser::parse_all($file_path, function ($row_data) use (&$index) {
                $index['total_rows']++;

                // Identifica colunas dinamicamente
                $email = '';
                $phone = '';
                $gclid = '';
                $name  = '';
                $instancia = '';
                $date  = '';
                $lead_id = '';

                foreach ($row_data as $col_name => $val) {
                    $raw_col   = preg_replace('/^\xEF\xBB\xBF/', '', (string) $col_name);
                    $clean_col = strtolower(trim($raw_col, " \t\n\r\0\x0B\"'“”«»"));
                    $val_clean = trim((string) $val);

                    if (empty($val_clean)) {
                        continue;
                    }

                    if (strpos($clean_col, 'email') !== false || strpos($clean_col, 'e-mail') !== false) {
                        $email = strtolower($val_clean);
                    } elseif (strpos($clean_col, 'telef') !== false || strpos($clean_col, 'phone') !== false || strpos($clean_col, 'whats') !== false || strpos($clean_col, 'celular') !== false) {
                        $phone = $val_clean;
                    } elseif (strpos($clean_col, 'gclid') !== false) {
                        $gclid = $val_clean;
                    } elseif (strpos($clean_col, 'nome') !== false || strpos($clean_col, 'name') !== false) {
                        $name = $val_clean;
                    } elseif (strpos($clean_col, 'instan') !== false || strpos($clean_col, 'polo') !== false || strpos($clean_col, 'unidade') !== false) {
                        $instancia = $val_clean;
                    } elseif (strpos($clean_col, 'data') !== false || strpos($clean_col, 'date') !== false) {
                        $date = $val_clean;
                    } elseif (strpos($clean_col, 'lead') !== false || strpos($clean_col, 'leaad') !== false || strpos($clean_col, 'id') !== false) {
                        // Limpa colunas como '["331442"]'
                        $lead_id = trim($val_clean, "[]\"' ");
                    }
                }

                $record = [
                    'email'        => $email,
                    'phone'        => $phone,
                    'nome'         => $name,
                    'instancia'    => $instancia,
                    'data'         => $date,
                    'lead_id'      => $lead_id,
                    'gclid'        => $gclid,
                ];

                // Indexa por e-mail
                if (!empty($email) && is_email($email)) {
                    $index['by_email'][$email] = $record;
                }

                // Indexa por variações de telefone
                if (!empty($phone)) {
                    $phone_norm = PhoneNormalizer::normalize($phone);
                    if (!empty($phone_norm)) {
                        $variations = PhoneNormalizer::get_lookup_variations($phone_norm);
                        foreach ($variations as $var) {
                            $index['by_phone'][$var] = $record;
                        }
                    }
                }

                // Indexa por GCLID
                if (!empty($gclid)) {
                    $index['by_gclid'][$gclid] = $record;
                }
            });
        } catch (\Throwable $e) {
            Logger::error('Planilha', 'Erro ao indexar planilha de qualificados: ' . $e->getMessage());
        }

        return $index;
    }

    /**
     * Processa arquivos do Elementor, extrai campos e unifica duplicidades
     */
    private static function process_elementor_spreadsheets($file_paths) {
        $unique_leads = [];
        $total_submissions = 0;
        $duplicate_leads_count = 0;

        foreach ($file_paths as $file_path) {
            if (!file_exists($file_path)) {
                continue;
            }

            try {
                SpreadsheetParser::parse_all($file_path, function ($row_data) use (&$unique_leads, &$total_submissions, &$duplicate_leads_count) {
                    $total_submissions++;

                    // Mapeamento semântico das colunas padrão do Elementor
                    $extracted = self::extract_elementor_fields($row_data);

                    $phone_raw  = $extracted['telefone'];
                    $phone_norm = !empty($phone_raw) ? PhoneNormalizer::normalize($phone_raw) : '';
                    $email_raw  = $extracted['email'];
                    $email_norm = !empty($email_raw) ? strtolower(trim($email_raw)) : '';

                    // Chave primária de desduplicação
                    if (!empty($phone_norm)) {
                        $dedup_key = 'tel:' . $phone_norm;
                    } elseif (!empty($email_norm) && is_email($email_norm)) {
                        $dedup_key = 'email:' . $email_norm;
                    } else {
                        $dedup_key = 'row:' . $total_submissions;
                    }

                    $submission_info = [
                        'data'       => $extracted['criado_em'],
                        'formulario' => $extracted['formulario_nome'],
                        'envio_id'   => $extracted['envio_id'],
                        'curso'      => $extracted['tipo_curso'],
                        'area'       => $extracted['area_interesse'],
                        'utm_source' => $extracted['utm_source'],
                        'ip'         => $extracted['ip_address'],
                    ];

                    // Se o lead já existe na coleção: UNIFICA DUPLICIDADE
                    if (isset($unique_leads[$dedup_key])) {
                        $existing = &$unique_leads[$dedup_key];

                        if ($existing['submissoes_count'] === 1) {
                            $duplicate_leads_count++;
                        }

                        $existing['submissoes_count']++;
                        $existing['submissoes'][] = $submission_info;
                        $existing['data_ultimo_envio'] = !empty($extracted['criado_em']) ? $extracted['criado_em'] : $existing['data_ultimo_envio'];

                        // Enriquece campos em branco do registro principal
                        foreach (['nome', 'email', 'telefone', 'tipo_curso', 'area_interesse', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'gclid', 'fbclid', 'page_url', 'referrer'] as $fld) {
                            if (empty($existing[$fld]) && !empty($extracted[$fld])) {
                                $existing[$fld] = $extracted[$fld];
                            }
                        }

                        unset($existing);
                    } else {
                        // Novo Lead Único
                        $unique_leads[$dedup_key] = [
                            'dedup_key'             => $dedup_key,
                            'nome'                  => $extracted['nome'],
                            'email'                 => $email_norm,
                            'telefone'              => $phone_raw,
                            'telefone_normalizado'  => $phone_norm,
                            'tipo_curso'            => $extracted['tipo_curso'],
                            'area_interesse'        => $extracted['area_interesse'],
                            'utm_source'            => $extracted['utm_source'],
                            'utm_medium'            => $extracted['utm_medium'],
                            'utm_campaign'          => $extracted['utm_campaign'],
                            'utm_content'           => $extracted['utm_content'],
                            'utm_term'              => $extracted['utm_term'],
                            'gclid'                 => $extracted['gclid'],
                            'fbclid'                => $extracted['fbclid'],
                            'page_url'              => $extracted['page_url'],
                            'referrer'              => $extracted['referrer'],
                            'formulario_nome'       => $extracted['formulario_nome'],
                            'formulario_id'         => $extracted['formulario_id'],
                            'envio_id'              => $extracted['envio_id'],
                            'ip_address'            => $extracted['ip_address'],
                            'data_primeiro_envio'   => $extracted['criado_em'],
                            'data_ultimo_envio'     => $extracted['criado_em'],
                            'submissoes_count'      => 1,
                            'submissoes'            => [$submission_info],
                        ];
                    }
                });
            } catch (\Throwable $e) {
                Logger::error('Planilha', 'Erro ao processar arquivo Elementor: ' . $e->getMessage());
            }
        }

        return [
            'unique_leads'          => $unique_leads,
            'total_submissions'     => $total_submissions,
            'duplicate_leads_count' => $duplicate_leads_count,
        ];
    }

    /**
     * Extrai campos do array da linha com tolerância a maiúsculas e variações
     */
    private static function extract_elementor_fields($row) {
        $data = [
            'nome'            => '',
            'email'           => '',
            'telefone'        => '',
            'tipo_curso'      => '',
            'area_interesse'  => '',
            'utm_source'      => '',
            'utm_medium'      => '',
            'utm_campaign'    => '',
            'utm_content'     => '',
            'utm_term'        => '',
            'gclid'           => '',
            'fbclid'          => '',
            'page_url'        => '',
            'referrer'        => '',
            'formulario_nome' => '',
            'formulario_id'   => '',
            'envio_id'        => '',
            'criado_em'       => '',
            'ip_address'      => '',
        ];

        foreach ($row as $key => $val) {
            $raw_k = preg_replace('/^\xEF\xBB\xBF/', '', (string) $key);
            $k = strtolower(trim($raw_k, " \t\n\r\0\x0B\"'“”«»"));
            $v = trim(trim((string) $val), "\"'“”«»");

            if (empty($v)) {
                continue;
            }

            // Normalização sem acentos da chave para casamentos seguros
            $k_clean = str_replace(
                ['á', 'à', 'ã', 'â', 'é', 'ê', 'í', 'ó', 'ô', 'õ', 'ú', 'ç'],
                ['a', 'a', 'a', 'a', 'e', 'e', 'i', 'o', 'o', 'o', 'u', 'c'],
                $k
            );

            // Nome do Lead (ignora explicitamente 'nome do formulario' e 'nome da campanha')
            if (
                ($k === 'nome' || $k === 'name' || $k === 'nome completo' || $k_clean === 'nome' || $k_clean === 'nome completo') ||
                (strpos($k_clean, 'nome') !== false && strpos($k_clean, 'formul') === false && strpos($k_clean, 'form') === false && strpos($k_clean, 'campan') === false)
            ) {
                if (empty($data['nome'])) {
                    $data['nome'] = $v;
                }
            } elseif ($k === 'email' || $k === 'e-mail' || strpos($k_clean, 'email') !== false || strpos($k_clean, 'e-mail') !== false) {
                $data['email'] = $v;
            } elseif ($k === 'telefone' || $k === 'phone' || $k === 'whatsapp' || $k === 'celular' || strpos($k_clean, 'telef') !== false || strpos($k_clean, 'celular') !== false || strpos($k_clean, 'whats') !== false) {
                $data['telefone'] = $v;
            } elseif (strpos($k_clean, 'tipo de curso') !== false || $k_clean === 'curso' || $k_clean === 'modalidade') {
                $data['tipo_curso'] = $v;
            } elseif (strpos($k_clean, 'rea') !== false && strpos($k_clean, 'interesse') !== false) {
                $data['area_interesse'] = $v;
            } elseif ($k === 'utm_source') {
                $data['utm_source'] = $v;
            } elseif ($k === 'utm_medium') {
                $data['utm_medium'] = $v;
            } elseif ($k === 'utm_campaign') {
                $data['utm_campaign'] = $v;
            } elseif ($k === 'utm_content') {
                $data['utm_content'] = $v;
            } elseif ($k === 'utm_term') {
                $data['utm_term'] = $v;
            } elseif ($k === 'gclid') {
                $data['gclid'] = $v;
            } elseif ($k === 'fbclid') {
                $data['fbclid'] = $v;
            } elseif ($k === 'page_url' || strpos($k_clean, 'referencia') !== false) {
                $data['page_url'] = $v;
            } elseif ($k === 'referrer') {
                $data['referrer'] = $v;
            } elseif (strpos($k_clean, 'formulario') !== false || strpos($k_clean, 'form') !== false) {
                $data['formulario_nome'] = $v;
            } elseif (strpos($k_clean, 'id do envio') !== false || $k_clean === 'envio_id') {
                $data['envio_id'] = $v;
            } elseif (strpos($k_clean, 'criado em') !== false || $k_clean === 'data' || $k_clean === 'date') {
                $data['criado_em'] = $v;
            } elseif (strpos($k_clean, 'ip') !== false) {
                $data['ip_address'] = $v;
            }
        }

        return $data;
    }

    /**
     * Gera CSV estruturado para download do relatório completo
     */
    public static function generate_export_csv($leads) {
        $headers = [
            'Status Qualificação',
            'Forma de Match',
            'Instância / Polo',
            'Data Qualificação',
            'Nome',
            'Telefone',
            'Telefone Normalizado',
            'E-mail',
            'Envios Elementor (Duplicidades)',
            'Tipo de Curso',
            'Área de Interesse',
            'UTM Source',
            'UTM Campaign',
            'UTM Medium',
            'GCLID',
            'FBCLID',
            'Primeiro Envio',
            'Último Envio',
            'Formulário Elementor',
        ];

        $output = fopen('php://temp', 'r+');
        // BOM UTF-8 para Excel abrir sem quebra de acentuação
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($output, $headers, ';');

        foreach ($leads as $lead) {
            $row = [
                !empty($lead['qualificado']) ? 'QUALIFICADO' : 'PENDENTE / NÃO QUALIFICADO',
                !empty($lead['match_motivo']) ? $lead['match_motivo'] : '',
                !empty($lead['polo_qualificado']) ? $lead['polo_qualificado'] : '',
                !empty($lead['data_qualificacao']) ? $lead['data_qualificacao'] : '',
                !empty($lead['nome']) ? $lead['nome'] : '',
                !empty($lead['telefone']) ? $lead['telefone'] : '',
                !empty($lead['telefone_normalizado']) ? $lead['telefone_normalizado'] : '',
                !empty($lead['email']) ? $lead['email'] : '',
                !empty($lead['submissoes_count']) ? $lead['submissoes_count'] : 1,
                !empty($lead['tipo_curso']) ? $lead['tipo_curso'] : '',
                !empty($lead['area_interesse']) ? $lead['area_interesse'] : '',
                !empty($lead['utm_source']) ? $lead['utm_source'] : '',
                !empty($lead['utm_campaign']) ? $lead['utm_campaign'] : '',
                !empty($lead['utm_medium']) ? $lead['utm_medium'] : '',
                !empty($lead['gclid']) ? $lead['gclid'] : '',
                !empty($lead['fbclid']) ? $lead['fbclid'] : '',
                !empty($lead['data_primeiro_envio']) ? $lead['data_primeiro_envio'] : '',
                !empty($lead['data_ultimo_envio']) ? $lead['data_ultimo_envio'] : '',
                !empty($lead['formulario_nome']) ? $lead['formulario_nome'] : '',
            ];
            fputcsv($output, $row, ';');
        }

        rewind($output);
        $csv_content = stream_get_contents($output);
        fclose($output);

        return $csv_content;
    }

    /**
     * Salva ou sincroniza todos os leads analisados no banco de dados do plugin (wp_li_leads)
     *
     * @param array $leads
     * @return array Contagem de salvos, atualizados e qualificados
     */
    public static function sync_to_database($leads) {
        global $wpdb;
        $leads_table   = DbSchema::get_leads_table();
        $history_table = DbSchema::get_history_table();
        $imports_table = DbSchema::get_imports_table();

        $saved_new = 0;
        $updated_existing = 0;
        $qualified_count = 0;
        $current_user_id = get_current_user_id();

        foreach ($leads as $lead) {
            $phone_norm = !empty($lead['telefone_normalizado']) ? $lead['telefone_normalizado'] : '';
            $email_norm = !empty($lead['email']) ? $lead['email'] : '';

            $existing = null;
            if (!empty($phone_norm)) {
                $existing = LeadRepository::find_by_phone($phone_norm);
            }
            if (!$existing && !empty($email_norm)) {
                $existing = LeadRepository::find_by_email($email_norm);
            }

            $is_qual = !empty($lead['qualificado']);
            if ($is_qual) {
                $qualified_count++;
            }

            if ($existing) {
                // Atualiza lead existente
                $lead_id = (int) $existing->id;
                $update_data = [
                    'updated_at' => current_time('mysql'),
                ];

                if ($is_qual && $existing->qualificacao_status !== 'qualificado') {
                    $update_data['qualificacao_status'] = 'qualificado';
                    $update_data['qualificacao_data']   = !empty($lead['data_qualificacao']) ? $lead['data_qualificacao'] : current_time('mysql');
                    $update_data['qualificacao_origem'] = 'Cruzamento Planilha Elementor';
                    $update_data['motivo_qualificacao'] = $lead['match_motivo'];
                    $update_data['score']               = ((int) $existing->score) + 15;

                    if (!empty($lead['polo_qualificado'])) {
                        $update_data['polo'] = sanitize_text_field($lead['polo_qualificado']);
                    }

                    // Auditoria no histórico
                    $wpdb->insert($history_table, [
                        'lead_id'        => $lead_id,
                        'campo'          => 'qualificacao_status',
                        'valor_anterior' => $existing->qualificacao_status,
                        'valor_novo'     => 'qualificado (cruzamento manual)',
                        'origem'         => 'cruzamento_planilha',
                        'usuario_id'     => $current_user_id,
                        'created_at'     => current_time('mysql'),
                    ]);

                    CapiQueue::enqueue_qualified($lead_id);
                }

                // Enriquece dados ausentes (incluindo se estiver como 'Sem nome')
                $is_name_empty = empty($existing->nome) || in_array(trim((string) $existing->nome), ['Sem nome', 'Sem Nome', 'sem nome'], true);
                if ($is_name_empty && !empty($lead['nome'])) {
                    $update_data['nome'] = sanitize_text_field($lead['nome']);
                }
                if (empty($existing->tipo_curso) && !empty($lead['tipo_curso'])) {
                    $update_data['tipo_curso'] = sanitize_text_field($lead['tipo_curso']);
                }
                if (empty($existing->area_interesse) && !empty($lead['area_interesse'])) {
                    $update_data['area_interesse'] = sanitize_text_field($lead['area_interesse']);
                }
                if (empty($existing->utm_source) && !empty($lead['utm_source'])) {
                    $update_data['utm_source'] = sanitize_text_field($lead['utm_source']);
                }
                if (empty($existing->utm_campaign) && !empty($lead['utm_campaign'])) {
                    $update_data['utm_campaign'] = sanitize_text_field($lead['utm_campaign']);
                }
                if (empty($existing->gclid) && !empty($lead['gclid'])) {
                    $update_data['gclid'] = sanitize_text_field($lead['gclid']);
                }
                if (empty($existing->fbclid) && !empty($lead['fbclid'])) {
                    $update_data['fbclid'] = sanitize_text_field($lead['fbclid']);
                }

                $wpdb->update($leads_table, $update_data, ['id' => $lead_id]);
                $updated_existing++;
            } else {
                // Cadastra novo lead
                $qual_status = $is_qual ? 'qualificado' : 'pendente';
                $qual_data   = $is_qual ? (!empty($lead['data_qualificacao']) ? $lead['data_qualificacao'] : current_time('mysql')) : null;

                $new_lead = [
                    'uuid'                 => wp_generate_uuid4(),
                    'nome'                 => sanitize_text_field($lead['nome']),
                    'email'                => $email_norm,
                    'telefone'             => sanitize_text_field($lead['telefone']),
                    'telefone_normalizado' => $phone_norm,
                    'tipo_curso'           => sanitize_text_field($lead['tipo_curso']),
                    'area_interesse'       => sanitize_text_field($lead['area_interesse']),
                    'data_cadastro'        => !empty($lead['data_primeiro_envio']) ? $lead['data_primeiro_envio'] : current_time('mysql'),
                    'formulario_nome'      => !empty($lead['formulario_nome']) ? sanitize_text_field($lead['formulario_nome']) : 'Elementor Form',
                    'pagina_origem'        => !empty($lead['page_url']) ? esc_url_raw($lead['page_url']) : '',
                    'ip_address'           => !empty($lead['ip_address']) ? sanitize_text_field($lead['ip_address']) : '',
                    'utm_source'           => sanitize_text_field($lead['utm_source']),
                    'utm_medium'           => sanitize_text_field($lead['utm_medium']),
                    'utm_campaign'         => sanitize_text_field($lead['utm_campaign']),
                    'utm_content'          => sanitize_text_field($lead['utm_content']),
                    'utm_term'             => sanitize_text_field($lead['utm_term']),
                    'gclid'                => sanitize_text_field($lead['gclid']),
                    'fbclid'               => sanitize_text_field($lead['fbclid']),
                    'referrer'             => sanitize_text_field($lead['referrer']),
                    'qualificacao_status'  => $qual_status,
                    'qualificacao_data'    => $qual_data,
                    'qualificacao_origem'  => 'Cruzamento Planilha Elementor',
                    'motivo_qualificacao'  => $lead['match_motivo'],
                    'polo'                 => !empty($lead['polo_qualificado']) ? sanitize_text_field($lead['polo_qualificado']) : '',
                    'score'                => $is_qual ? 15 : 5,
                    'data_primeira_captura'=> !empty($lead['data_primeiro_envio']) ? $lead['data_primeiro_envio'] : current_time('mysql'),
                    'created_at'           => current_time('mysql'),
                    'updated_at'           => current_time('mysql'),
                ];

                $wpdb->insert($leads_table, $new_lead);
                $new_id = $wpdb->insert_id;

                $wpdb->insert($history_table, [
                    'lead_id'        => $new_id,
                    'campo'          => 'criacao',
                    'valor_anterior' => '',
                    'valor_novo'     => 'Importado via Cruzamento Elementor',
                    'origem'         => 'cruzamento_planilha',
                    'usuario_id'     => $current_user_id,
                    'created_at'     => current_time('mysql'),
                ]);

                if ($is_qual) {
                    CapiQueue::enqueue_qualified($new_id);
                }

                $saved_new++;
            }
        }

        // Registra o lote no histórico geral de importações
        $wpdb->insert($imports_table, [
            'nome_arquivo'          => 'Cruzamento Elementor vs Qualificados (' . count($leads) . ' leads)',
            'total_linhas'          => count($leads),
            'linhas_processadas'    => count($leads),
            'leads_qualificados'    => $qualified_count,
            'leads_nao_encontrados' => max(0, count($leads) - $qualified_count),
            'mapeamento_colunas'    => 'Automático (Cruzamento Elementor)',
            'status'                => 'concluido',
            'usuario_id'            => $current_user_id,
            'created_at'            => current_time('mysql'),
        ]);

        return [
            'novos'        => $saved_new,
            'atualizados'  => $updated_existing,
            'qualificados' => $qualified_count,
            'total'        => count($leads),
        ];
    }
}
