<?php
namespace LeadIntelligence\Admin\Controllers;

use LeadIntelligence\Qualification\SpreadsheetParser;
use LeadIntelligence\Qualification\Matcher;
use LeadIntelligence\Qualification\ElementorCrossAnalyzer;
use LeadIntelligence\Qualification\GoogleSheetsClient;
use LeadIntelligence\Qualification\GoogleSheetsSync;
use LeadIntelligence\Database\DbSchema;
use LeadIntelligence\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Controller de Importação e Mapeamento de Planilhas de Qualificação
 * Suporta Upload Manual (CSV/XLSX), Sincronização Automática via Google Sheets API v4
 * e Cruzamento de Submissões do Elementor com Leads Qualificados
 */
class ImportController {

    /**
     * Inicializa ações de envio de formulários no hook admin_init
     * Executa antes de qualquer cabeçalho ou HTML ser enviado, evitando tela branca em redirecionamentos.
     */
    public static function init() {
        add_action('admin_init', [__CLASS__, 'handle_form_actions']);
    }

    /**
     * Processa formulários POST antes da renderização da página
     */
    public static function handle_form_actions() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // 0. Salvamento e Validação da Conta de Serviço (Service Account JSON)
        if (isset($_POST['li_save_service_account'])) {
            check_admin_referer('li_google_sa_verify', 'li_nonce');

            $json_raw = '';
            if (!empty($_FILES['sa_json_file']['tmp_name'])) {
                $json_raw = file_get_contents($_FILES['sa_json_file']['tmp_name']);
            } elseif (!empty($_POST['sa_json_content'])) {
                $json_raw = wp_unslash($_POST['sa_json_content']);
            }

            if (empty(trim($json_raw))) {
                wp_safe_redirect(admin_url('admin.php?page=lead-intelligence-import&tab=google_sheets&google_error=' . urlencode('Envie o arquivo JSON da Conta de Serviço ou cole o conteúdo no campo de texto.')));
                exit;
            }

            $result = GoogleSheetsClient::save_service_account_credentials($json_raw);
            if (is_wp_error($result)) {
                wp_safe_redirect(admin_url('admin.php?page=lead-intelligence-import&tab=google_sheets&google_error=' . urlencode($result->get_error_message())));
                exit;
            }

            wp_safe_redirect(admin_url('admin.php?page=lead-intelligence-import&tab=google_sheets&sa_connected=1'));
            exit;
        }

        // 1. Conexão e Salvamento de Credenciais Google OAuth
        if (isset($_POST['li_save_google_creds']) || isset($_POST['li_connect_google_btn'])) {
            check_admin_referer('li_google_creds_verify', 'li_nonce');

            $client_id     = sanitize_text_field(wp_unslash($_POST['google_client_id'] ?? ''));
            $client_secret = sanitize_text_field(wp_unslash($_POST['google_client_secret'] ?? ''));

            $current_auth = GoogleSheetsClient::get_auth_data();
            if (strpos($client_secret, '••••') !== false || empty($client_secret)) {
                $client_secret = $current_auth['client_secret'];
            }

            $redirect_mode = isset($_POST['google_redirect_mode']) && $_POST['google_redirect_mode'] === 'admin' ? 'admin' : 'rest';
            update_option('lead_intelligence_google_redirect_mode', $redirect_mode);

            GoogleSheetsClient::save_auth_data([
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
            ]);

            if (isset($_POST['li_connect_google_btn'])) {
                if (empty($client_id) || empty($client_secret)) {
                    wp_safe_redirect(admin_url('admin.php?page=lead-intelligence-import&tab=google_sheets&google_error=' . urlencode('Informe o Client ID e o Client Secret para conectar ao Google.')));
                    exit;
                }

                $auth_url = GoogleSheetsClient::get_auth_url($client_id);
                if (!empty($auth_url)) {
                    wp_redirect($auth_url);
                    exit;
                } else {
                    wp_safe_redirect(admin_url('admin.php?page=lead-intelligence-import&tab=google_sheets&google_error=' . urlencode('Não foi possível gerar a URL de autorização do Google.')));
                    exit;
                }
            } else {
                wp_safe_redirect(admin_url('admin.php?page=lead-intelligence-import&tab=google_sheets&saved_creds=1'));
                exit;
            }
        }

        // 2. Desconectar Conta Google
        if (isset($_POST['li_disconnect_google'])) {
            check_admin_referer('li_google_disconnect_verify', 'li_nonce');

            GoogleSheetsClient::disconnect();
            wp_safe_redirect(admin_url('admin.php?page=lead-intelligence-import&tab=google_sheets&disconnected=1'));
            exit;
        }

        // 3. Salvar Configurações da Planilha ou Sincronizar Agora
        if (isset($_POST['li_save_google_sync']) || isset($_POST['li_run_google_sync_now'])) {
            check_admin_referer('li_google_sync_verify', 'li_nonce');

            $source_channel = isset($_POST['source_channel']) && $_POST['source_channel'] === 'meta_ads' ? 'meta_ads' : 'google_ads';
            $raw_url   = sanitize_text_field(wp_unslash($_POST['spreadsheet_url'] ?? ''));
            $sheet_id  = GoogleSheetsClient::extract_spreadsheet_id($raw_url);
            $sheet_tab = sanitize_text_field(wp_unslash($_POST['sheet_tab'] ?? ''));
            $frequency   = sanitize_text_field(wp_unslash($_POST['cron_frequency'] ?? 'disabled'));
            $sync_window = sanitize_text_field(wp_unslash($_POST['sync_window'] ?? 'tail_2000'));

            $mapping = [
                'telefone'       => sanitize_text_field(wp_unslash($_POST['map_telefone'] ?? '')),
                'email'          => sanitize_text_field(wp_unslash($_POST['map_email'] ?? '')),
                'nome'           => sanitize_text_field(wp_unslash($_POST['map_nome'] ?? '')),
                'status'         => sanitize_text_field(wp_unslash($_POST['map_status'] ?? '')),
                'data'           => sanitize_text_field(wp_unslash($_POST['map_data'] ?? '')),
                'campanha'       => sanitize_text_field(wp_unslash($_POST['map_campanha'] ?? '')),
                'curso'          => sanitize_text_field(wp_unslash($_POST['map_curso'] ?? '')),
                'area'           => sanitize_text_field(wp_unslash($_POST['map_area'] ?? '')),
                'default_status' => sanitize_text_field(wp_unslash($_POST['default_status'] ?? 'qualificado')),
            ];

            if ($source_channel === 'meta_ads') {
                $mapping['fbclid'] = sanitize_text_field(wp_unslash($_POST['map_fbclid'] ?? ''));
                $mapping['ad_id']  = sanitize_text_field(wp_unslash($_POST['map_ad_id'] ?? ''));
            } else {
                $mapping['gclid']  = sanitize_text_field(wp_unslash($_POST['map_gclid'] ?? ''));
            }

            GoogleSheetsSync::save_source_settings($source_channel, [
                'spreadsheet_url' => $raw_url,
                'spreadsheet_id'  => $sheet_id,
                'sheet_tab'       => $sheet_tab,
                'cron_frequency'  => $frequency,
                'sync_window'     => $sync_window,
                'mapping'         => $mapping,
            ]);

            if (isset($_POST['li_run_google_sync_now'])) {
                $sync_result = GoogleSheetsSync::run_sync($source_channel, true);

                if (is_wp_error($sync_result)) {
                    wp_safe_redirect(admin_url("admin.php?page=lead-intelligence-import&tab=google_sheets&channel={$source_channel}&sync_error=" . urlencode($sync_result->get_error_message())));
                    exit;
                } else {
                    wp_safe_redirect(admin_url("admin.php?page=lead-intelligence-import&tab=google_sheets&channel={$source_channel}&sync_success=" . urlencode($sync_result['message'])));
                    exit;
                }
            } else {
                wp_safe_redirect(admin_url("admin.php?page=lead-intelligence-import&tab=google_sheets&channel={$source_channel}&saved_sync=1"));
                exit;
            }
        }

        // 4. Execução do Cruzamento e Análise Elementor vs Qualificados
        if (isset($_POST['li_run_cross_analysis'])) {
            check_admin_referer('li_cross_verify', 'li_nonce');

            $upload_dir = wp_upload_dir();
            $target_dir = $upload_dir['basedir'] . '/li_imports';
            if (!file_exists($target_dir)) {
                wp_mkdir_p($target_dir);
            }

            $elementor_paths = [];
            $qualified_path = '';

            // Se o usuário optou por utilizar arquivos locais já existentes no servidor
            if (!empty($_POST['use_local_sample_files'])) {
                $base_proj = dirname(LEAD_INTELLIGENCE_PLUGIN_DIR);
                $cand1 = $base_proj . '/elementor-submissions-export-Popup Principal (2575f51)-2026-10-07.csv';
                $cand2 = $base_proj . '/elementor-submissions-export-Popup Principal (c4c7c0f)-2026-10-07.csv';
                $candQ = $base_proj . '/Leads GoogleADS - todas unidades - Leads.csv';

                if (file_exists($cand1)) $elementor_paths[] = $cand1;
                if (file_exists($cand2)) $elementor_paths[] = $cand2;
                if (file_exists($candQ)) $qualified_path = $candQ;
            }

            // Arquivos de Elementor enviados no formulário
            if (empty($elementor_paths) && !empty($_FILES['elementor_files']['name'])) {
                $files = $_FILES['elementor_files'];
                $names = is_array($files['name']) ? $files['name'] : [$files['name']];
                $tmp_names = is_array($files['tmp_name']) ? $files['tmp_name'] : [$files['tmp_name']];

                foreach ($names as $idx => $fname) {
                    if (empty($fname) || empty($tmp_names[$idx])) continue;
                    $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
                    if (!in_array($ext, ['csv', 'txt', 'xlsx'])) continue;

                    $dest = $target_dir . '/' . time() . '_' . sanitize_file_name($fname);
                    if (move_uploaded_file($tmp_names[$idx], $dest)) {
                        $elementor_paths[] = $dest;
                    }
                }
            }

            // Arquivo de Leads Qualificados enviado no formulário
            if (empty($qualified_path) && !empty($_FILES['qualified_file']['tmp_name'])) {
                $q_file = $_FILES['qualified_file'];
                $q_ext = strtolower(pathinfo($q_file['name'], PATHINFO_EXTENSION));
                if (in_array($q_ext, ['csv', 'txt', 'xlsx'])) {
                    $q_dest = $target_dir . '/' . time() . '_qual_' . sanitize_file_name($q_file['name']);
                    if (move_uploaded_file($q_file['tmp_name'], $q_dest)) {
                        $qualified_path = $q_dest;
                    }
                }
            }

            if (empty($elementor_paths) || empty($qualified_path)) {
                wp_safe_redirect(admin_url('admin.php?page=lead-intelligence-import&tab=elementor_cross&cross_error=' . urlencode('Envie ao menos um arquivo de submissões do Elementor e o arquivo de Leads Qualificados.')));
                exit;
            }

            try {
                $analysis_result = ElementorCrossAnalyzer::analyze_spreadsheets($elementor_paths, $qualified_path);
                set_transient('li_cross_result_' . get_current_user_id(), $analysis_result, 7200);

                // Limpa arquivos temporários criados em uploads
                foreach ($elementor_paths as $ep) {
                    if (strpos($ep, $target_dir) !== false) @unlink($ep);
                }
                if (strpos($qualified_path, $target_dir) !== false) @unlink($qualified_path);

                wp_safe_redirect(admin_url('admin.php?page=lead-intelligence-import&tab=elementor_cross&analyzed=1'));
                exit;
            } catch (\Throwable $e) {
                wp_safe_redirect(admin_url('admin.php?page=lead-intelligence-import&tab=elementor_cross&cross_error=' . urlencode('Erro durante a análise: ' . $e->getMessage())));
                exit;
            }
        }

        // 5. Exportação do Relatório CSV Consolidado
        if (isset($_POST['li_export_cross_csv'])) {
            check_admin_referer('li_cross_export_verify', 'li_nonce');

            $data = get_transient('li_cross_result_' . get_current_user_id());
            if (empty($data['leads'])) {
                wp_safe_redirect(admin_url('admin.php?page=lead-intelligence-import&tab=elementor_cross&cross_error=' . urlencode('Nenhum dado de análise disponível para exportação.')));
                exit;
            }

            $csv_content = ElementorCrossAnalyzer::generate_export_csv($data['leads']);
            $filename = 'analise-leads-elementor-vs-qualificados-' . date('Y-m-d_H-i') . '.csv';

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Pragma: no-cache');
            header('Expires: 0');
            echo $csv_content;
            exit;
        }

        // 6. Gravação/Sincronização dos Leads no Banco de Dados
        if (isset($_POST['li_save_cross_to_db'])) {
            check_admin_referer('li_cross_save_verify', 'li_nonce');

            $data = get_transient('li_cross_result_' . get_current_user_id());
            if (empty($data['leads'])) {
                wp_safe_redirect(admin_url('admin.php?page=lead-intelligence-import&tab=elementor_cross&cross_error=' . urlencode('Nenhum dado disponível para gravação.')));
                exit;
            }

            $res = ElementorCrossAnalyzer::sync_to_database($data['leads']);
            wp_safe_redirect(admin_url('admin.php?page=lead-intelligence-import&tab=elementor_cross&cross_saved=1&novos=' . $res['novos'] . '&atualizados=' . $res['atualizados'] . '&qualificados=' . $res['qualificados']));
            exit;
        }

        // 7. Limpeza da Análise Atual
        if (isset($_GET['clear_cross']) && $_GET['clear_cross'] === '1') {
            delete_transient('li_cross_result_' . get_current_user_id());
            wp_safe_redirect(admin_url('admin.php?page=lead-intelligence-import&tab=elementor_cross&cross_cleared=1'));
            exit;
        }
    }

    public static function render() {
        $active_tab = sanitize_key($_GET['tab'] ?? 'elementor_cross');
        if (isset($_GET['google_connected']) || isset($_GET['google_error']) || isset($_GET['saved_creds']) || isset($_GET['sync_success']) || isset($_GET['sa_connected']) || isset($_GET['disconnected']) || isset($_GET['saved_sync'])) {
            $active_tab = 'google_sheets';
        }

        $error = '';
        $success_message = '';
        $step = 1;
        $preview_data = null;
        $temp_file = '';
        $original_filename = '';

        // Alertas vindos via URL
        if (isset($_GET['cross_saved'])) {
            $novos = intval($_GET['novos'] ?? 0);
            $atualizados = intval($_GET['atualizados'] ?? 0);
            $qualificados = intval($_GET['qualificados'] ?? 0);
            $success_message = "Cruzamento gravado com sucesso no banco de dados! <strong>{$novos}</strong> novos leads cadastrados, <strong>{$atualizados}</strong> existentes enriquecidos e <strong>{$qualificados}</strong> qualificados com histórico de auditoria.";
            $active_tab = 'elementor_cross';
        } elseif (isset($_GET['cross_cleared'])) {
            $success_message = 'Análise anterior limpa com sucesso. Você pode enviar novas planilhas agora.';
            $active_tab = 'elementor_cross';
        } elseif (isset($_GET['analyzed'])) {
            $success_message = 'Análise e cruzamento de planilhas concluídos com sucesso! Veja o diagnóstico detalhado abaixo.';
            $active_tab = 'elementor_cross';
        }
        if (!empty($_GET['cross_error'])) {
            $error = sanitize_text_field(wp_unslash($_GET['cross_error']));
            $active_tab = 'elementor_cross';
        }

        if (isset($_GET['sa_connected'])) {
            $success_message = 'Conta de Serviço do Google conectada e validada com sucesso! Lembre-se de compartilhar sua planilha com o e-mail da Conta de Serviço dando acesso de Leitor.';
            $active_tab = 'google_sheets';
        } elseif (isset($_GET['google_connected'])) {
            $success_message = 'Conta Google conectada com sucesso via OAuth 2.0! O plugin agora pode ler e sincronizar sua planilha.';
            $active_tab = 'google_sheets';
        }
        if (!empty($_GET['google_error'])) {
            $error = sanitize_text_field(wp_unslash($_GET['google_error']));
            $active_tab = 'google_sheets';
        }
        if (isset($_GET['saved_creds'])) {
            $success_message = 'Credenciais salvas com sucesso! Agora clique em <strong>Conectar Conta Google</strong> para autorizar o acesso.';
            $active_tab = 'google_sheets';
        }
        if (isset($_GET['disconnected'])) {
            $success_message = 'Conta Google desconectada com sucesso.';
            $active_tab = 'google_sheets';
        }
        if (isset($_GET['saved_sync'])) {
            $success_message = 'Configurações de sincronização da planilha salvas com sucesso!';
            $active_tab = 'google_sheets';
        }
        if (!empty($_GET['sync_success'])) {
            $success_message = sanitize_text_field(wp_unslash($_GET['sync_success']));
            $active_tab = 'google_sheets';
        }
        if (!empty($_GET['sync_error'])) {
            $error = 'Erro na sincronização: ' . sanitize_text_field(wp_unslash($_GET['sync_error']));
            $active_tab = 'google_sheets';
        }

        // ==========================================
        // AÇÕES DA ABA: UPLOAD MANUAL (CSV/XLSX)
        // ==========================================
        if (isset($_POST['li_upload_spreadsheet'])) {
            check_admin_referer('li_import_upload_verify', 'li_nonce');

            if (empty($_FILES['spreadsheet']['tmp_name'])) {
                $error = 'Por favor, selecione um arquivo CSV ou XLSX válido.';
            } else {
                $uploaded = $_FILES['spreadsheet'];
                $ext = strtolower(pathinfo($uploaded['name'], PATHINFO_EXTENSION));

                if (!in_array($ext, ['csv', 'txt', 'xlsx'])) {
                    $error = 'Formato inválido. Apenas arquivos .CSV e .XLSX são aceitos.';
                } else {
                    $upload_dir = wp_upload_dir();
                    $target_dir = $upload_dir['basedir'] . '/li_imports';
                    if (!file_exists($target_dir)) {
                        wp_mkdir_p($target_dir);
                    }

                    $safe_name = sanitize_file_name($uploaded['name']);
                    $temp_file = $target_dir . '/' . time() . '_' . $safe_name;

                    if (move_uploaded_file($uploaded['tmp_name'], $temp_file)) {
                        try {
                            $preview_data = SpreadsheetParser::preview($temp_file, 5);
                            $original_filename = $uploaded['name'];
                            $step = 2;
                            $active_tab = 'upload';
                        } catch (\Throwable $e) {
                            $error = 'Erro ao processar arquivo: ' . $e->getMessage();
                            @unlink($temp_file);
                        }
                    } else {
                        $error = 'Falha ao salvar arquivo temporário no servidor.';
                    }
                }
            }
        }

        if (isset($_POST['li_confirm_mapping'])) {
            check_admin_referer('li_mapping_verify', 'li_nonce');

            $temp_file = sanitize_text_field(wp_unslash($_POST['temp_file'] ?? ''));
            $original_filename = sanitize_text_field(wp_unslash($_POST['original_filename'] ?? 'planilha.csv'));

            if (!file_exists($temp_file)) {
                $error = 'Arquivo temporário expirou ou foi excluído. Por favor, envie novamente.';
                $step = 1;
            } else {
                $mapping = [
                    'telefone'       => sanitize_text_field(wp_unslash($_POST['map_telefone'] ?? '')),
                    'email'          => sanitize_text_field(wp_unslash($_POST['map_email'] ?? '')),
                    'nome'           => sanitize_text_field(wp_unslash($_POST['map_nome'] ?? '')),
                    'status'         => sanitize_text_field(wp_unslash($_POST['map_status'] ?? '')),
                    'data'           => sanitize_text_field(wp_unslash($_POST['map_data'] ?? '')),
                    'curso'          => sanitize_text_field(wp_unslash($_POST['map_curso'] ?? '')),
                    'area'           => sanitize_text_field(wp_unslash($_POST['map_area'] ?? '')),
                    'gclid'          => sanitize_text_field(wp_unslash($_POST['map_gclid'] ?? '')),
                    'default_status' => sanitize_text_field(wp_unslash($_POST['default_status'] ?? 'qualificado')),
                ];

                global $wpdb;
                $imports_table = DbSchema::get_imports_table();

                $wpdb->insert($imports_table, [
                    'nome_arquivo'          => $original_filename,
                    'total_linhas'          => 0,
                    'linhas_processadas'    => 0,
                    'leads_qualificados'    => 0,
                    'leads_nao_encontrados' => 0,
                    'mapeamento_colunas'    => wp_json_encode($mapping, JSON_UNESCAPED_UNICODE),
                    'status'                => 'processando',
                    'usuario_id'            => get_current_user_id(),
                    'created_at'            => current_time('mysql'),
                ]);
                $import_id = $wpdb->insert_id;

                $total_processed = 0;
                $total_matched = 0;
                $total_created = 0;
                $total_skipped = 0;

                try {
                    SpreadsheetParser::parse_all($temp_file, function ($row_data) use ($mapping, $original_filename, $import_id, &$total_processed, &$total_matched, &$total_created, &$total_skipped) {
                        $result = Matcher::process_row($row_data, $mapping, $original_filename, $import_id);

                        $total_processed++;
                        if ($result['status'] === 'matched') {
                            $total_matched++;
                        } elseif ($result['status'] === 'created') {
                            $total_created++;
                        } else {
                            $total_skipped++;
                        }
                    });

                    $wpdb->update($imports_table, [
                        'total_linhas'          => $total_processed,
                        'linhas_processadas'    => $total_processed,
                        'leads_qualificados'    => $total_matched,
                        'leads_nao_encontrados' => $total_created,
                        'status'                => 'concluido',
                    ], ['id' => $import_id]);

                    // Unificação e enriquecimento inteligente por telefone pós-importação
                    $unified_count = DbSchema::unify_and_enrich_leads_by_phone();
                    $unify_info = $unified_count > 0 ? " ({$unified_count} registros unificados com formulários Elementor)" : "";

                    Logger::info('Planilha', "Importação de '{$original_filename}' finalizada.", [
                        'processadas' => $total_processed,
                        'cruzados'    => $total_matched,
                        'criados'     => $total_created,
                        'ignorados'   => $total_skipped,
                        'unificados'  => $unified_count,
                    ]);

                    $success_message = "Importação concluída com sucesso! <strong>{$total_processed}</strong> linhas processadas: <strong>{$total_matched}</strong> leads cruzados/qualificados e <strong>{$total_created}</strong> novos cadastrados.{$unify_info}";
                    $step = 1;
                    $active_tab = 'upload';
                } catch (\Throwable $e) {
                    $error = 'Erro durante o processamento da planilha: ' . $e->getMessage();
                    $wpdb->update($imports_table, ['status' => 'erro'], ['id' => $import_id]);
                }

                @unlink($temp_file);
            }
        }

        // Dados para renderização
        $google_auth = GoogleSheetsClient::get_auth_data();
        $is_google_connected = GoogleSheetsClient::is_connected();
        $current_source_channel = (isset($_GET['channel']) && $_GET['channel'] === 'meta_ads') ? 'meta_ads' : 'google_ads';
        $google_sync_settings = GoogleSheetsSync::get_source_settings($current_source_channel);
        $redirect_mode = get_option('lead_intelligence_google_redirect_mode', 'rest');
        $redirect_uri_rest  = GoogleSheetsClient::get_redirect_uri('rest');
        $redirect_uri_admin = GoogleSheetsClient::get_redirect_uri('admin');
        $redirect_uri       = GoogleSheetsClient::get_redirect_uri($redirect_mode);
        $auth_url = !empty($google_auth['client_id']) ? GoogleSheetsClient::get_auth_url() : '';

        // Se estiver conectado e tiver planilha informada, busca abas e cabeçalhos dinamicamente
        $detected_tabs = [];
        $sheet_headers = [];
        if ($is_google_connected && !empty($google_sync_settings['spreadsheet_id'])) {
            $tabs_result = GoogleSheetsClient::get_spreadsheet_tabs($google_sync_settings['spreadsheet_id']);
            if (!is_wp_error($tabs_result)) {
                $detected_tabs = $tabs_result;
            }

            $sample = GoogleSheetsClient::fetch_sheet_data(
                $google_sync_settings['spreadsheet_id'],
                $google_sync_settings['sheet_tab'],
                2
            );
            if (!is_wp_error($sample) && !empty($sample['headers'])) {
                $sheet_headers = $sample['headers'];
            }
        }

        // Histórico de Importações
        global $wpdb;
        $imports_table = DbSchema::get_imports_table();
        // Dados de Cruzamento Elementor vs Qualificados
        $cross_result = get_transient('li_cross_result_' . get_current_user_id());
        $has_cross_data = !empty($cross_result) && !empty($cross_result['summary']);
        ?>
        <div class="wrap li-wrap">
            <div class="li-header">
                <h2>Lead Intelligence &bull; Qualificação & Planilhas</h2>
                <p class="li-subtitle">Conecte suas planilhas de Google Ads e Meta Ads ou envie arquivos para cruzar vendas e matrículas com os leads.</p>
            </div>

            <!-- NAVEGAÇÃO POR ABAS -->
            <nav class="nav-tab-wrapper" style="margin-bottom: 24px;">
                <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-import&tab=elementor_cross')); ?>"
                   class="nav-tab <?php echo $active_tab === 'elementor_cross' ? 'nav-tab-active' : ''; ?>"
                   style="display: inline-flex; align-items: center; gap: 6px; font-weight: 600;">
                    <span class="dashicons dashicons-analytics" style="color: #10824C;"></span>
                    ⚡ Cruzamento Elementor vs Qualificados
                    <?php if ($has_cross_data): ?>
                        <span class="li-badge li-status-qualificado" style="font-size: 11px; padding: 2px 7px; margin-left: 4px;">Ativo (<?php echo esc_html($cross_result['summary']['total_leads_unicos'] ?? 0); ?> leads)</span>
                    <?php endif; ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-import&tab=google_sheets')); ?>"
                   class="nav-tab <?php echo $active_tab === 'google_sheets' ? 'nav-tab-active' : ''; ?>"
                   style="display: inline-flex; align-items: center; gap: 6px; font-weight: 600;">
                    <span class="dashicons dashicons-google" style="color: #4285f4;"></span>
                    Google Sheets Automático (API v4)
                    <?php if ($is_google_connected): ?>
                        <span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:#10b981; margin-left:4px;" title="Conectado"></span>
                    <?php endif; ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-import&tab=upload')); ?>"
                   class="nav-tab <?php echo $active_tab === 'upload' ? 'nav-tab-active' : ''; ?>"
                   style="display: inline-flex; align-items: center; gap: 6px; font-weight: 600;">
                    <span class="dashicons dashicons-upload" style="color: #0284c7;"></span>
                    Upload Manual Simples (CSV / XLSX)
                </a>
            </nav>

            <?php if (!empty($error)): ?>
                <div class="notice notice-error is-dismissible"><p><?php echo esc_html($error); ?></p></div>
            <?php endif; ?>

            <?php if (!empty($success_message)): ?>
                <div class="notice notice-success is-dismissible"><p><?php echo wp_kses_post($success_message); ?></p></div>
            <?php endif; ?>

            <?php if ($active_tab === 'elementor_cross'): ?>
                <!-- ============================================================== -->
                <!-- ABA: CRUZAMENTO ELEMENTOR VS QUALIFICADOS -->
                <!-- ============================================================== -->
                <?php self::render_cross_analysis_tab($cross_result, $has_cross_data); ?>

            <?php elseif ($active_tab === 'google_sheets'): ?>
                <!-- ============================================================== -->
                <!-- ABA: GOOGLE SHEETS AUTOMÁTICO (API V4 VIA OAUTH 2.0) -->
                <!-- ============================================================== -->

                <!-- CARD 1: STATUS DE AUTENTICAÇÃO -->
                <div class="li-card">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 15px;">
                        <div>
                            <h3 class="li-card-title" style="display: flex; align-items: center; gap: 8px;">
                                <span class="dashicons dashicons-admin-network" style="color: #4285f4;"></span>
                                1. Conexão com o Google Sheets
                            </h3>
                            <p class="li-card-desc" style="margin-bottom: 0;">
                                Permite ao plugin ler e sincronizar planilhas privadas de matrículas e vendas sem depender de intervenção manual.
                            </p>
                        </div>

                        <?php 
                        $auth_type = GoogleSheetsClient::get_auth_type();
                        if ($is_google_connected): ?>
                            <span class="li-badge li-status-qualificado" style="font-size: 13px; padding: 6px 12px; display: inline-flex; align-items: center; gap: 6px;">
                                <span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:#10b981;"></span>
                                <?php if ($auth_type === 'service_account'): ?>
                                    Conta de Serviço: <?php echo esc_html($google_auth['connected_email'] ?: $google_auth['sa_client_email']); ?>
                                <?php else: ?>
                                    OAuth: <?php echo esc_html($google_auth['connected_email'] ?: 'Ativo'); ?>
                                <?php endif; ?>
                            </span>
                        <?php else: ?>
                            <span class="li-badge li-status-cinza" style="font-size: 13px; padding: 6px 12px;">
                                Desconectado
                            </span>
                        <?php endif; ?>
                    </div>

                    <?php if (!$is_google_connected): ?>
                        <!-- MÉTODO 1: CONTA DE SERVIÇO (RECOMENDADO) -->
                        <div style="margin-top: 24px; padding: 22px; background: #ffffff; border: 2px solid #10b981; border-radius: 10px; box-shadow: 0 2px 4px rgba(16, 185, 129, 0.05);">
                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; flex-wrap: wrap; gap: 8px;">
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <span style="background: #10b981; color: #fff; font-weight: 700; font-size: 12px; padding: 3px 8px; border-radius: 4px; text-transform: uppercase;">Recomendado</span>
                                    <h4 style="margin: 0; font-size: 16px; color: #0f172a; font-weight: 700;">
                                        Método 1: Conta de Serviço (100% Automático & Sem Bloqueios de Servidor)
                                    </h4>
                                </div>
                                <span style="color: #059669; font-size: 12px; font-weight: 600;">✔ Imune ao Mod_Security & Zero Redirecionamentos</span>
                            </div>

                            <p style="font-size: 13px; color: #475569; margin: 0 0 16px; line-height: 1.6;">
                                Com a Conta de Serviço (Service Account), o plugin se comunica diretamente com a API do Google nos bastidores, sem depender de telas de login ou firewalls da sua hospedagem.
                            </p>

                            <!-- PASSO A PASSO -->
                            <div style="padding: 14px 18px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; font-size: 13px; color: #166534; line-height: 1.7; margin-bottom: 18px;">
                                <strong>Passo a passo no Google Cloud Console:</strong>
                                <ol style="margin: 6px 0 0 18px; padding: 0;">
                                    <li>Acesse o <a href="https://console.cloud.google.com/iam-admin/serviceaccounts" target="_blank" rel="noopener noreferrer" style="color: #15803d; font-weight: 600; text-decoration: underline;"><strong>Google Cloud &rarr; Contas de Serviço</strong></a> no seu projeto.</li>
                                    <li>Clique em <strong>+ CRIAR CONTA DE SERVIÇO</strong>, dê um nome (ex: <code>robofaveni</code>) e clique em <em>Concluir</em>.</li>
                                    <li>Clique na conta de serviço criada na lista, acesse a aba <strong>Chaves</strong> &rarr; <strong>Adicionar Chave &rarr; Criar nova chave</strong>, escolha o formato <strong>JSON</strong> e clique em <em>Criar</em>. O download do arquivo <code>.json</code> iniciará no seu PC.</li>
                                    <li>Envie o arquivo baixado ou cole o conteúdo dele abaixo e clique no botão verde.</li>
                                </ol>
                            </div>

                            <!-- FORMULÁRIO CONTA DE SERVIÇO -->
                            <form method="post" action="" enctype="multipart/form-data">
                                <?php wp_nonce_field('li_google_sa_verify', 'li_nonce'); ?>

                                <div style="margin-bottom: 14px;">
                                    <label for="sa_json_file" style="display: block; font-weight: 600; color: #1e293b; margin-bottom: 6px; font-size: 13px;">
                                        Opção A: Enviar arquivo JSON da chave:
                                    </label>
                                    <input type="file" name="sa_json_file" id="sa_json_file" accept=".json" style="padding: 6px 0;">
                                </div>

                                <div style="margin-bottom: 18px;">
                                    <label for="sa_json_content" style="display: block; font-weight: 600; color: #1e293b; margin-bottom: 6px; font-size: 13px;">
                                        Opção B: Ou cole o conteúdo do JSON aqui:
                                    </label>
                                    <textarea name="sa_json_content" id="sa_json_content" rows="4" style="width: 100%; font-family: monospace; font-size: 12px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 12px;" placeholder='{ "type": "service_account", "project_id": "...", "private_key": "-----BEGIN PRIVATE KEY-----..." }'></textarea>
                                </div>

                                <button type="submit" name="li_save_service_account" class="button button-primary button-large" style="background: #10b981; border-color: #059669; font-weight: 600; display: inline-flex; align-items: center; gap: 6px;">
                                    <span class="dashicons dashicons-saved" style="margin-top: 1px;"></span>
                                    Conectar Conta de Serviço (Salvar e Validar)
                                </button>
                            </form>
                        </div>

                        <!-- MÉTODO 2: OAUTH 2.0 (EXPANSÍVEL) -->
                        <details style="margin-top: 20px; padding: 14px 18px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;">
                            <summary style="font-weight: 600; color: #475569; cursor: pointer; font-size: 13px;">
                                Método 2: OAuth 2.0 Clássico (Login via Navegador) - <em>Clique para expandir caso prefira</em>
                            </summary>
                            
                            <form method="post" action="" style="margin-top: 15px;">
                                <?php wp_nonce_field('li_google_creds_verify', 'li_nonce'); ?>
                                <table class="form-table" style="margin-top: 0;">
                                    <tr>
                                        <th scope="row"><label for="google_client_id">Client ID do Google</label></th>
                                        <td>
                                            <input type="text" name="google_client_id" id="google_client_id"
                                                   value="<?php echo esc_attr($google_auth['client_id']); ?>"
                                                   class="regular-text" style="width: 100%; max-width: 480px;"
                                                   placeholder="ex: 123456789-xxxxxxxx.apps.googleusercontent.com">
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label for="google_client_secret">Client Secret do Google</label></th>
                                        <td>
                                            <input type="password" name="google_client_secret" id="google_client_secret"
                                                   value="<?php echo !empty($google_auth['client_secret']) ? '••••••••••••••••' : ''; ?>"
                                                   class="regular-text" style="width: 100%; max-width: 480px;"
                                                   placeholder="GOCSPX-xxxxxxxxxxxxxx">
                                        </td>
                                    </tr>
                                </table>
                                <div style="margin-top: 10px; display: flex; gap: 10px;">
                                    <button type="submit" name="li_connect_google_btn" class="button button-secondary">
                                        Conectar via OAuth
                                    </button>
                                </div>
                            </form>
                        </details>

                    <?php else: ?>
                        <!-- CONECTADO: AÇÕES DE CONEXÃO -->
                        <?php if ($auth_type === 'service_account'): ?>
                            <div style="margin-top: 18px; padding: 18px 22px; background: #f0fdf4; border: 1px solid #86efac; border-radius: 8px; font-size: 13px; color: #166534;">
                                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 12px;">
                                    <div style="font-weight: 700; font-size: 14px; color: #15803d; display: flex; align-items: center; gap: 6px;">
                                        <span class="dashicons dashicons-yes-alt" style="color: #10b981;"></span>
                                        Conexão Ativa via Conta de Serviço (Server-to-Server)
                                    </div>
                                    <form method="post" action="" onsubmit="return confirm('Deseja realmente desconectar esta Conta de Serviço?');" style="margin: 0;">
                                        <?php wp_nonce_field('li_google_disconnect_verify', 'li_nonce'); ?>
                                        <button type="submit" name="li_disconnect_google" class="button button-link-delete" style="color: #b91c1c; font-size: 12px;">
                                            Desconectar Conta de Serviço
                                        </button>
                                    </form>
                                </div>

                                <div style="padding: 12px 16px; background: #ffffff; border: 1px solid #bbf7d0; border-radius: 6px; margin-bottom: 10px;">
                                    <div style="font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 4px;">
                                        👉 E-mail do robô para compartilhar sua planilha:
                                    </div>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <input type="text" id="li_sa_email_display" readonly value="<?php echo esc_attr($google_auth['connected_email'] ?: $google_auth['sa_client_email']); ?>" style="width: 100%; max-width: 520px; font-family: monospace; font-size: 13px; font-weight: 700; color: #0f172a; background: #f8fafc; border: 1px solid #cbd5e1; padding: 6px 10px; border-radius: 4px;">
                                        <button type="button" class="button button-secondary" onclick="navigator.clipboard.writeText(document.getElementById('li_sa_email_display').value); alert('E-mail copiado! Abra sua planilha no Google Sheets e adicione este e-mail como Leitor.');">
                                            <span class="dashicons dashicons-admin-page" style="vertical-align: text-bottom; margin-right: 2px;"></span> Copiar E-mail
                                        </button>
                                    </div>
                                </div>

                                <p style="margin: 0; font-size: 12px; color: #15803d; line-height: 1.5;">
                                    <strong>Instrução Obrigatória:</strong> Abra a sua planilha do Google Sheets, clique no botão azul <strong>Compartilhar</strong> no canto superior direito e adicione o e-mail acima com permissão de <strong>Leitor</strong>.
                                </p>
                            </div>
                        <?php else: ?>
                            <div style="margin-top: 16px; padding: 14px 18px; background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 8px; font-size: 13px; color: #065f46; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                                <div>
                                    <strong>✔ Autenticação Ativa via OAuth:</strong> Conectado com <?php echo esc_html($google_auth['connected_email']); ?>.
                                </div>
                                <form method="post" action="" onsubmit="return confirm('Deseja realmente desconectar sua conta Google deste plugin?');">
                                    <?php wp_nonce_field('li_google_disconnect_verify', 'li_nonce'); ?>
                                    <button type="submit" name="li_disconnect_google" class="button button-link-delete" style="color: #b91c1c; font-size: 13px;">
                                        Desconectar Conta Google
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <!-- CARD 2: CONFIGURAÇÃO DA PLANILHA E MAPEAMENTO (ATIVO SE CONECTADO) -->
                <?php if ($is_google_connected): ?>
                    <div class="li-card">
                        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 16px;">
                            <div>
                                <h3 class="li-card-title" style="margin-bottom: 4px;">2. Configuração e Mapeamento de Planilhas por Canal</h3>
                                <p class="li-card-desc">Gerencie as planilhas de origem do Google Ads e Meta Ads separadamente.</p>
                            </div>
                        </div>

                        <!-- SELETOR DE CANAL (SUB-ABAS / PILLS) -->
                        <div style="display: flex; gap: 10px; margin-bottom: 24px; border-bottom: 2px solid #e2e8f0; padding-bottom: 12px;">
                            <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-import&tab=google_sheets&channel=google_ads')); ?>"
                               class="button <?php echo $current_source_channel === 'google_ads' ? 'button-primary' : 'button-secondary'; ?>"
                               style="display: inline-flex; align-items: center; gap: 6px; font-weight: 600; padding: 6px 14px; border-radius: 6px;">
                                <span>🟢</span> Planilha Google Ads
                            </a>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-import&tab=google_sheets&channel=meta_ads')); ?>"
                               class="button <?php echo $current_source_channel === 'meta_ads' ? 'button-primary' : 'button-secondary'; ?>"
                               style="display: inline-flex; align-items: center; gap: 6px; font-weight: 600; padding: 6px 14px; border-radius: 6px;">
                                <span>🔵</span> Planilha Meta Ads
                            </a>
                        </div>

                        <?php if ($current_source_channel === 'google_ads'): ?>
                            <div class="notice notice-info inline" style="margin: 0 0 20px 0; border-left-color: #1a73e8;">
                                <p><strong>Fonte Ativa: Google Ads.</strong> Os novos contatos lidos desta planilha serão atribuídos ao canal <strong>Google Ads</strong> (com suporte a <code>gclid</code> e parâmetros UTM do Google).</p>
                            </div>
                        <?php else: ?>
                            <div class="notice notice-info inline" style="margin: 0 0 20px 0; border-left-color: #0866ff;">
                                <p><strong>Fonte Ativa: Meta Ads.</strong> Os novos contatos lidos desta planilha serão atribuídos ao canal <strong>Meta Ads</strong> (com suporte a <code>fbclid</code>, <code>ad_id</code> e criativos da Meta).</p>
                            </div>
                        <?php endif; ?>

                        <form method="post" action="">
                            <?php wp_nonce_field('li_google_sync_verify', 'li_nonce'); ?>
                            <input type="hidden" name="source_channel" value="<?php echo esc_attr($current_source_channel); ?>">

                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label for="spreadsheet_url"><strong>Link ou ID da Planilha</strong> <span style="color:#ef4444;">*</span></label>
                                    </th>
                                    <td>
                                        <input type="text" name="spreadsheet_url" id="spreadsheet_url"
                                               value="<?php echo esc_attr($google_sync_settings['spreadsheet_url']); ?>"
                                               class="regular-text" style="width: 100%; max-width: 600px;"
                                               placeholder="https://docs.google.com/spreadsheets/d/..." required>
                                        <p class="description">Cole o link completo da planilha do <?php echo $current_source_channel === 'meta_ads' ? 'Meta Ads' : 'Google Ads'; ?> aberta no seu navegador.</p>
                                    </td>
                                </tr>

                                <tr>
                                    <th scope="row">
                                        <label for="sheet_tab"><strong>Aba da Planilha</strong></label>
                                    </th>
                                    <td>
                                        <?php if (!empty($detected_tabs)): ?>
                                            <select name="sheet_tab" id="sheet_tab" style="min-width: 250px;">
                                                <?php foreach ($detected_tabs as $tab_item): ?>
                                                    <?php $is_sel = ($google_sync_settings['sheet_tab'] === $tab_item['title']) || (empty($google_sync_settings['sheet_tab']) && $tab_item['index'] === 0); ?>
                                                    <option value="<?php echo esc_attr($tab_item['title']); ?>" <?php echo $is_sel ? 'selected' : ''; ?>>
                                                        <?php echo esc_html($tab_item['title']); ?> (gid: <?php echo esc_html($tab_item['id']); ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php else: ?>
                                            <input type="text" name="sheet_tab" id="sheet_tab"
                                                   value="<?php echo esc_attr($google_sync_settings['sheet_tab'] ?: ($current_source_channel === 'meta_ads' ? 'Meta' : 'Leads V2')); ?>"
                                                   placeholder="ex: Leads ou Base" style="min-width: 250px;">
                                        <?php endif; ?>
                                        <p class="description">Nome da aba na parte inferior da planilha.</p>
                                    </td>
                                </tr>

                                <tr>
                                    <th scope="row">
                                        <label for="cron_frequency"><strong>Frequência de Sincronização Automática</strong></label>
                                    </th>
                                    <td>
                                        <select name="cron_frequency" id="cron_frequency" style="min-width: 250px;">
                                            <option value="disabled" <?php selected($google_sync_settings['cron_frequency'], 'disabled'); ?>>Apenas Manual (Sem agendamento)</option>
                                            <option value="hourly" <?php selected($google_sync_settings['cron_frequency'], 'hourly'); ?>>A cada 1 Hora (Recomendado)</option>
                                            <option value="six_hours" <?php selected($google_sync_settings['cron_frequency'], 'six_hours'); ?>>A cada 6 Horas</option>
                                            <option value="twicedaily" <?php selected($google_sync_settings['cron_frequency'], 'twicedaily'); ?>>2x ao Dia (A cada 12 horas)</option>
                                            <option value="daily" <?php selected($google_sync_settings['cron_frequency'], 'daily'); ?>>Diariamente (1x ao dia)</option>
                                        </select>
                                    </td>
                                </tr>

                                <tr>
                                    <th scope="row">
                                        <label for="sync_window"><strong>Janela Deslizante de Leitura</strong></label>
                                    </th>
                                    <td>
                                        <?php $cur_window = $google_sync_settings['sync_window'] ?? 'tail_2000'; ?>
                                        <select name="sync_window" id="sync_window" style="min-width: 320px;">
                                            <option value="tail_1000" <?php selected($cur_window, 'tail_1000'); ?>>Últimas 1.000 Linhas (~2 dias de leads - Ultra Leve)</option>
                                            <option value="tail_2000" <?php selected($cur_window, 'tail_2000'); ?>>Últimas 2.000 Linhas (~4 dias de leads - Recomendado)</option>
                                            <option value="tail_3000" <?php selected($cur_window, 'tail_3000'); ?>>Últimas 3.000 Linhas (~6 dias de leads - Semanal)</option>
                                            <option value="tail_5000" <?php selected($cur_window, 'tail_5000'); ?>>Últimas 5.000 Linhas (~10 dias de leads)</option>
                                            <option value="all" <?php selected($cur_window, 'all'); ?>>Todas as Linhas (Carga Completa - Mais pesado)</option>
                                        </select>
                                        <p class="description">Busca apenas as linhas recentes do final da planilha via <code>batchGet</code>, economizando <strong>95% de memória e processamento</strong>.</p>
                                    </td>
                                </tr>
                            </table>

                            <h4 style="margin: 28px 0 12px 0; font-size: 15px; color: #0f172a; border-top: 1px solid #e2e8f0; padding-top: 20px;">
                                Mapeamento De-Para das Colunas:
                            </h4>

                            <?php
                            $headers_list = !empty($sheet_headers) ? $sheet_headers : ['Nome', 'Email', 'telefone', 'Instancia', 'leadId', 'gclid', 'fbclid', 'ad_id', 'campanha', 'dataCriação', 'status'];
                            $mapping = $google_sync_settings['mapping'];
                            ?>

                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label for="map_telefone"><strong>Telefone / WhatsApp</strong> <span style="color:#ef4444;">*</span></label>
                                    </th>
                                    <td>
                                        <?php echo self::render_select('map_telefone', $headers_list, ['telefone', 'phone', 'whatsapp', 'tel', 'celular'], $mapping['telefone'] ?? 'telefone'); ?>
                                        <p class="description"><strong>Prioridade 1 de Cruzamento:</strong> Normalizado automaticamente (+55 DDD 9 dígitos).</p>
                                    </td>
                                </tr>

                                <tr>
                                    <th scope="row">
                                        <label for="map_email"><strong>E-mail</strong></label>
                                    </th>
                                    <td>
                                        <?php echo self::render_select('map_email', $headers_list, ['email', 'e-mail'], $mapping['email'] ?? 'Email'); ?>
                                        <p class="description"><strong>Prioridade 2 de Cruzamento:</strong> Usado caso o telefone não seja encontrado.</p>
                                    </td>
                                </tr>

                                <tr>
                                    <th scope="row"><label for="map_nome">Nome do Aluno / Lead</label></th>
                                    <td>
                                        <?php echo self::render_select('map_nome', $headers_list, ['nome', 'name', 'cliente', 'aluno'], $mapping['nome'] ?? 'Nome'); ?>
                                    </td>
                                </tr>

                                <tr>
                                    <th scope="row">
                                        <label for="map_status"><strong>Coluna de Status de Venda / Matrícula</strong></label>
                                    </th>
                                    <td>
                                        <?php echo self::render_select('map_status', $headers_list, ['status', 'situacao', 'qualificacao', 'etapa'], $mapping['status'] ?? 'status'); ?>
                                        <p class="description">Mapeia automaticamente: <code>Vendido/Matriculado</code> &rarr; Qualificado, <code>Perdido</code> &rarr; Não Qualificado, <code>Ativo</code> &rarr; Pendente.</p>
                                    </td>
                                </tr>

                                <tr>
                                    <th scope="row"><label for="default_status">Status Padrão (se vazio na linha)</label></th>
                                    <td>
                                        <select name="default_status" id="default_status">
                                            <option value="qualificado" <?php selected($mapping['default_status'] ?? 'qualificado', 'qualificado'); ?>>Qualificado</option>
                                            <option value="pendente" <?php selected($mapping['default_status'] ?? 'qualificado', 'pendente'); ?>>Pendente</option>
                                            <option value="nao_qualificado" <?php selected($mapping['default_status'] ?? 'qualificado', 'nao_qualificado'); ?>>Não Qualificado</option>
                                        </select>
                                    </td>
                                </tr>

                                <?php if ($current_source_channel === 'google_ads'): ?>
                                    <tr>
                                        <th scope="row"><label for="map_gclid">GCLID (Google Click ID)</label></th>
                                        <td>
                                            <?php echo self::render_select('map_gclid', $headers_list, ['gclid', 'google_click'], $mapping['gclid'] ?? 'gclid'); ?>
                                            <p class="description">Identificador de clique direto das campanhas de Google Ads.</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <tr>
                                        <th scope="row"><label for="map_fbclid">FBCLID (Facebook Click ID)</label></th>
                                        <td>
                                            <?php echo self::render_select('map_fbclid', $headers_list, ['fbclid', 'meta_click', 'fb_click'], $mapping['fbclid'] ?? ''); ?>
                                            <p class="description">Identificador de clique de anúncios Meta Ads.</p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label for="map_ad_id">ID do Anúncio (ad_id)</label></th>
                                        <td>
                                            <?php echo self::render_select('map_ad_id', $headers_list, ['ad_id', 'adid', 'anuncio_id', 'id_anuncio'], $mapping['ad_id'] ?? ''); ?>
                                            <p class="description">ID do anúncio da Meta para sincronização com criativos e conjuntos.</p>
                                        </td>
                                    </tr>
                                <?php endif; ?>

                                <tr>
                                    <th scope="row"><label for="map_campanha">Nome da Campanha (UTM Campaign)</label></th>
                                    <td>
                                        <?php echo self::render_select('map_campanha', $headers_list, ['campanha', 'campaign', 'utm_campaign', 'nome_campanha'], $mapping['campanha'] ?? ''); ?>
                                    </td>
                                </tr>

                                <tr>
                                    <th scope="row"><label for="map_data">Data da Criação / Venda</label></th>
                                    <td>
                                        <?php echo self::render_select('map_data', $headers_list, ['datacriação', 'data', 'date', 'criacao'], $mapping['data'] ?? 'dataCriação'); ?>
                                    </td>
                                </tr>

                                <tr>
                                    <th scope="row"><label for="map_area">Instância / Polo / Unidade</label></th>
                                    <td>
                                        <?php echo self::render_select('map_area', $headers_list, ['instancia', 'polo', 'unidade', 'area'], $mapping['area'] ?? 'Instancia'); ?>
                                    </td>
                                </tr>

                                <tr>
                                    <th scope="row"><label for="map_curso">Tipo de Curso / Modalidade</label></th>
                                    <td>
                                        <?php echo self::render_select('map_curso', $headers_list, ['curso', 'modalidade'], $mapping['curso'] ?? ''); ?>
                                    </td>
                                </tr>
                            </table>

                            <div style="margin-top: 24px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                                <button type="submit" name="li_save_google_sync" class="button button-secondary button-large">
                                    Salvar Configurações (<?php echo $current_source_channel === 'meta_ads' ? 'Meta Ads' : 'Google Ads'; ?>)
                                </button>
                                <button type="submit" name="li_run_google_sync_now" class="button button-primary button-large" style="display: inline-flex; align-items: center; gap: 6px;">
                                    <span class="dashicons dashicons-update" style="margin-top: 2px;"></span>
                                    Sincronizar <?php echo $current_source_channel === 'meta_ads' ? 'Meta Ads' : 'Google Ads'; ?> Agora &rarr;
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- CARD 3: RESUMO DA ÚLTIMA SINCRONIZAÇÃO -->
                    <?php if (!empty($google_sync_settings['last_sync'])): ?>
                        <?php $ls = $google_sync_settings['last_sync']; ?>
                        <div class="li-card">
                            <h3 class="li-card-title">Resumo da Última Sincronização &bull; <?php echo $current_source_channel === 'meta_ads' ? 'Meta Ads' : 'Google Ads'; ?></h3>
                            <p class="li-card-desc">
                                Executada em <strong><?php echo esc_html(date_i18n('d/m/Y \à\s H:i:s', strtotime($ls['timestamp'] ?? current_time('mysql')))); ?></strong>
                                &bull; Status: <span class="li-badge <?php echo ($ls['status'] ?? '') === 'concluido' ? 'li-status-qualificado' : 'li-status-desqualificado'; ?>"><?php echo esc_html(ucfirst($ls['status'] ?? '')); ?></span>
                            </p>

                            <div class="li-metric-grid" style="margin-bottom: 0;">
                                <div class="li-metric-card li-card-info">
                                    <span class="li-metric-label">Linhas Lidas</span>
                                    <span class="li-metric-value"><?php echo number_format_i18n($ls['total'] ?? 0); ?></span>
                                </div>
                                <div class="li-metric-card li-card-success">
                                    <span class="li-metric-label">Leads Cruzados</span>
                                    <span class="li-metric-value"><?php echo number_format_i18n($ls['matched'] ?? 0); ?></span>
                                </div>
                                <div class="li-metric-card li-card-warning">
                                    <span class="li-metric-label">Novos Leads Cadastrados</span>
                                    <span class="li-metric-value"><?php echo number_format_i18n($ls['created'] ?? 0); ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

            <?php else: ?>
                <!-- ============================================================== -->
                <!-- ABA: UPLOAD MANUAL DE ARQUIVO (CSV / XLSX) -->
                <!-- ============================================================== -->

                <?php if ($step === 1): ?>
                    <div class="li-card">
                        <h3 class="li-card-title">1. Selecione o Arquivo de Qualificação</h3>
                        <p class="li-card-desc">Formatos suportados: <strong>.CSV</strong> ou <strong>.XLSX</strong>. O sistema permite mapear qualquer formato de coluna na etapa seguinte.</p>

                        <form method="post" action="" enctype="multipart/form-data">
                            <?php wp_nonce_field('li_import_upload_verify', 'li_nonce'); ?>

                            <div style="padding: 30px; border: 2px dashed #cbd5e1; border-radius: 10px; text-align: center; background: #f8fafc; margin-bottom: 20px;">
                                <span class="dashicons dashicons-upload" style="font-size: 40px; width: 40px; height: 40px; color: #0284c7; margin-bottom: 10px;"></span>
                                <div style="margin-bottom: 12px;">
                                    <input type="file" name="spreadsheet" accept=".csv, .xlsx, .txt" required style="font-size: 14px;">
                                </div>
                                <span style="font-size: 12px; color: #64748b;">Planilhas com telefone, e-mail e status/matrícula de alunos</span>
                            </div>

                            <button type="submit" name="li_upload_spreadsheet" class="button button-primary button-large">
                                Avançar para Mapeamento de Colunas &rarr;
                            </button>
                        </form>
                    </div>

                <?php elseif ($step === 2 && $preview_data): ?>
                    <div class="li-card">
                        <h3 class="li-card-title">2. Mapeamento de Colunas da Planilha</h3>
                        <p class="li-card-desc">Arquivo: <strong><?php echo esc_html($original_filename); ?></strong> &bull; Total aproximado: <strong><?php echo esc_html($preview_data['total_rows']); ?> linhas</strong>.</p>

                        <h4 style="margin: 15px 0 8px 0; font-size: 13px; color: #475569;">Amostra das Primeiras Linhas da Planilha:</h4>
                        <div style="overflow-x: auto; margin-bottom: 24px; border: 1px solid #e2e8f0; border-radius: 8px;">
                            <table class="wp-list-table widefat fixed striped" style="margin: 0;">
                                <thead>
                                    <tr>
                                        <?php foreach ($preview_data['headers'] as $header): ?>
                                            <th style="background:#f1f5f9; font-weight: 600;"><?php echo esc_html($header); ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($preview_data['rows'] as $r): ?>
                                        <tr>
                                            <?php foreach ($r as $val): ?>
                                                <td><?php echo esc_html($val); ?></td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <form method="post" action="">
                            <?php wp_nonce_field('li_mapping_verify', 'li_nonce'); ?>
                            <input type="hidden" name="temp_file" value="<?php echo esc_attr($temp_file); ?>">
                            <input type="hidden" name="original_filename" value="<?php echo esc_attr($original_filename); ?>">

                            <h4 style="margin: 20px 0 10px 0; font-size: 15px; color: #0f172a;">Associe as Colunas da sua Planilha aos Campos do Sistema:</h4>

                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label for="map_telefone"><strong>Telefone / WhatsApp</strong> <span style="color:#ef4444;">*</span></label>
                                    </th>
                                    <td>
                                        <?php echo self::render_select('map_telefone', $preview_data['headers'], ['telefone', 'phone', 'whatsapp', 'tel', 'celular', 'contato']); ?>
                                        <p class="description"><strong>Prioridade 1 de Cruzamento:</strong> O sistema normaliza automaticamente com ou sem DDI 55 e com/sem 9º dígito.</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="map_email"><strong>E-mail</strong></label>
                                    </th>
                                    <td>
                                        <?php echo self::render_select('map_email', $preview_data['headers'], ['email', 'e-mail']); ?>
                                        <p class="description"><strong>Prioridade 2 de Cruzamento:</strong> Utilizado caso o telefone não coincida.</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="map_nome">Nome do Aluno / Lead</label></th>
                                    <td><?php echo self::render_select('map_nome', $preview_data['headers'], ['nome', 'name', 'firstname', 'cliente', 'aluno']); ?></td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="map_status">Coluna de Status (Opcional)</label></th>
                                    <td>
                                        <?php echo self::render_select('map_status', $preview_data['headers'], ['status', 'situacao', 'qualificacao', 'matriculado', 'pago']); ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="default_status">Status Padrão se não informado</label></th>
                                    <td>
                                        <select name="default_status" id="default_status">
                                            <option value="qualificado" selected>Qualificado</option>
                                            <option value="pendente">Pendente</option>
                                            <option value="nao_qualificado">Não Qualificado</option>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="map_gclid">GCLID (Google Click ID)</label></th>
                                    <td><?php echo self::render_select('map_gclid', $preview_data['headers'], ['gclid', 'google_click']); ?></td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="map_data">Data da Matrícula / Qualificação</label></th>
                                    <td><?php echo self::render_select('map_data', $preview_data['headers'], ['datacriação', 'data', 'date', 'data_matricula']); ?></td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="map_area">Instância / Polo / Área</label></th>
                                    <td><?php echo self::render_select('map_area', $preview_data['headers'], ['instancia', 'area', 'area_interesse', 'polo']); ?></td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="map_curso">Tipo de Curso / Modalidade</label></th>
                                    <td><?php echo self::render_select('map_curso', $preview_data['headers'], ['curso', 'tipo_curso', 'modalidade']); ?></td>
                                </tr>
                            </table>

                            <div style="margin-top: 24px;">
                                <button type="submit" name="li_confirm_mapping" class="button button-primary button-large">
                                    Iniciar Cruzamento e Vinculação Automática &rarr;
                                </button>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-import&tab=upload')); ?>" class="button button-secondary button-large" style="margin-left: 10px;">
                                    Cancelar
                                </a>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <!-- HISTÓRICO DE IMPORTAÇÕES -->
            <div class="li-card" style="padding: 0; overflow-x: auto; margin-top: 24px;">
                <div style="padding: 18px 24px 10px; border-bottom: 1px solid #e2e8f0;">
                    <h3 class="li-card-title">Histórico de Importações Recentes</h3>
                </div>
                <table class="wp-list-table widefat fixed striped li-table">
                    <thead>
                        <tr>
                            <th style="width: 70px;">ID</th>
                            <th style="width: 140px;">Data</th>
                            <th>Origem / Arquivo</th>
                            <th style="width: 100px;">Total Linhas</th>
                            <th style="width: 140px;">Cruzados</th>
                            <th style="width: 130px;">Novos Cadastrados</th>
                            <th style="width: 110px;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($history)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 30px; color: #64748b;">
                                    Nenhuma importação ou sincronização realizada ainda.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($history as $h): ?>
                                <tr>
                                    <td>#<?php echo esc_html($h->id); ?></td>
                                    <td><?php echo esc_html(date_i18n('d/m/Y H:i', strtotime($h->created_at))); ?></td>
                                    <td><strong><?php echo esc_html($h->nome_arquivo); ?></strong></td>
                                    <td><?php echo number_format_i18n($h->total_linhas); ?></td>
                                    <td>
                                        <span class="li-badge li-status-qualificado">
                                            ✔ <?php echo number_format_i18n($h->leads_qualificados); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="li-badge li-status-cinza">
                                            + <?php echo number_format_i18n($h->leads_nao_encontrados); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="li-badge <?php echo $h->status === 'concluido' ? 'li-status-qualificado' : 'li-status-desqualificado'; ?>">
                                            <?php echo esc_html(ucfirst($h->status)); ?>
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

    /**
     * Auxiliar para renderizar <select> com pré-seleção heurística
     */
    private static function render_select($name, $headers, $match_keywords = [], $saved_val = '') {
        $html = '<select name="' . esc_attr($name) . '" id="' . esc_attr($name) . '" style="min-width: 250px;">';
        $html .= '<option value="">-- Não mapear este campo --</option>';

        $selected_found = false;
        foreach ($headers as $header) {
            $is_selected = false;

            if (!empty($saved_val) && strcasecmp($header, $saved_val) === 0) {
                $is_selected = true;
                $selected_found = true;
            } elseif (!$selected_found && !empty($match_keywords)) {
                $clean_h = strtolower(trim($header));
                foreach ($match_keywords as $kw) {
                    if (strpos($clean_h, $kw) !== false) {
                        $is_selected = true;
                        $selected_found = true;
                        break;
                    }
                }
            }

            $html .= '<option value="' . esc_attr($header) . '" ' . ($is_selected ? 'selected' : '') . '>' . esc_html($header) . '</option>';
        }

        $html .= '</select>';
        return $html;
    }

    /**
     * Renderiza a aba de Cruzamento e Análise de Planilhas (Elementor vs Qualificados)
     *
     * @param array $cross_result Dados da análise armazenados em transient
     * @param bool $has_cross_data Indica se há análise ativa
     */
    private static function render_cross_analysis_tab($cross_result, $has_cross_data) {
        $base_proj = dirname(LEAD_INTELLIGENCE_PLUGIN_DIR);
        $cand1 = $base_proj . '/elementor-submissions-export-Popup Principal (2575f51)-2026-10-07.csv';
        $cand2 = $base_proj . '/elementor-submissions-export-Popup Principal (c4c7c0f)-2026-10-07.csv';
        $candQ = $base_proj . '/Leads GoogleADS - todas unidades - Leads.csv';
        $has_sample_files = file_exists($cand1) && file_exists($cand2) && file_exists($candQ);

        if (!$has_cross_data): ?>
            <!-- CARD INICIAL: FORMULÁRIO DE ENVIO DAS PLANILHAS -->
            <div class="li-card" style="border-top: 4px solid #10824C;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 15px; margin-bottom: 16px;">
                    <div>
                        <h3 class="li-card-title" style="display: flex; align-items: center; gap: 8px; color: #163930; font-size: 18px;">
                            <span class="dashicons dashicons-analytics" style="color: #10824C; font-size: 22px; width: 22px; height: 22px;"></span>
                            Cruzamento Inteligente: Formulários Elementor &bull; Leads Qualificados
                        </h3>
                        <p class="li-card-desc" style="max-width: 820px; line-height: 1.6; margin-top: 4px;">
                            Envie as planilhas baixadas das submissões do Elementor e a planilha de leads qualificados (matrículas/vendas).
                            O sistema fará o <strong>reconhecimento automático de contatos</strong>, <strong>unificará leads duplicados</strong> (agrupando envios repetidos)
                            e gerará um <strong>diagnóstico completo da qualidade dos leads por campanha, tipo de curso e área</strong>.
                        </p>
                    </div>
                </div>

                <?php if ($has_sample_files): ?>
                    <!-- ATALHO: ARQUIVOS LOCAIS DETECTADOS -->
                    <div style="padding: 16px 20px; background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 8px; margin-bottom: 24px;">
                        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
                            <div>
                                <span style="background: #10b981; color: #fff; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 4px; text-transform: uppercase;">Arquivos Detectados</span>
                                <strong style="color: #065f46; margin-left: 8px; font-size: 14px;">Planilhas padrão encontradas na pasta do projeto:</strong>
                                <ul style="margin: 6px 0 0 20px; padding: 0; color: #047857; font-size: 12px; line-height: 1.5;">
                                    <li>2x Planilhas Elementor (Popups 2575f51 e c4c7c0f)</li>
                                    <li>1x Planilha de Leads Qualificados (GoogleADS - todas unidades - 22.630 linhas)</li>
                                </ul>
                            </div>
                            <form method="post" action="" style="margin: 0;">
                                <?php wp_nonce_field('li_cross_verify', 'li_nonce'); ?>
                                <input type="hidden" name="use_local_sample_files" value="1">
                                <button type="submit" name="li_run_cross_analysis" class="button button-primary button-large" style="background: #10824C; border-color: #0c673c; font-weight: 600; display: inline-flex; align-items: center; gap: 6px;">
                                    <span class="dashicons dashicons-controls-play" style="margin-top: 1px;"></span>
                                    Analisar Planilhas Locais Agora
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- FORMULÁRIO DE UPLOAD MANUAL -->
                <form method="post" action="" enctype="multipart/form-data">
                    <?php wp_nonce_field('li_cross_verify', 'li_nonce'); ?>

                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; margin-bottom: 24px;">
                        <!-- CAMPO 1: ELEMENTOR -->
                        <div style="padding: 24px; border: 2px dashed #cbd5e1; border-radius: 10px; background: #f8fafc;">
                            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 12px;">
                                <span class="dashicons dashicons-feedback" style="font-size: 28px; width: 28px; height: 28px; color: #0284c7;"></span>
                                <div>
                                    <h4 style="margin: 0; font-size: 15px; color: #0f172a;">1. Planilhas do Elementor (Submissões)</h4>
                                    <span style="font-size: 12px; color: #64748b;">Suporta 1 ou múltiplos arquivos (.CSV ou .XLSX)</span>
                                </div>
                            </div>
                            <p style="font-size: 12px; color: #475569; margin: 0 0 14px; line-height: 1.5;">
                                Baixadas em <em>Elementor &rarr; Submissions</em>. O sistema detecta Nome, E-mail, Telefone, Curso, Área, UTMs e GCLID/FBCLID.
                            </p>
                            <input type="file" name="elementor_files[]" multiple accept=".csv, .xlsx, .txt" style="font-size: 13px; width: 100%;">
                        </div>

                        <!-- CAMPO 2: QUALIFICADOS -->
                        <div style="padding: 24px; border: 2px dashed #cbd5e1; border-radius: 10px; background: #f8fafc;">
                            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 12px;">
                                <span class="dashicons dashicons-yes-alt" style="font-size: 28px; width: 28px; height: 28px; color: #10824C;"></span>
                                <div>
                                    <h4 style="margin: 0; font-size: 15px; color: #0f172a;">2. Planilha de Leads Qualificados</h4>
                                    <span style="font-size: 12px; color: #64748b;">Base de matrículas / alunos convertidos (.CSV ou .XLSX)</span>
                                </div>
                            </div>
                            <p style="font-size: 12px; color: #475569; margin: 0 0 14px; line-height: 1.5;">
                                Contém os contatos que fecharam/matricularam (ex: <em>Leads GoogleADS - todas unidades - Leads.csv</em>).
                            </p>
                            <input type="file" name="qualified_file" accept=".csv, .xlsx, .txt" style="font-size: 13px; width: 100%;">
                        </div>
                    </div>

                    <div style="display: flex; align-items: center; gap: 14px; flex-wrap: wrap;">
                        <button type="submit" name="li_run_cross_analysis" class="button button-primary button-large" style="background: #10824C; border-color: #0c673c; font-weight: 700; padding: 6px 20px; font-size: 14px; display: inline-flex; align-items: center; gap: 6px;">
                            <span class="dashicons dashicons-update" style="margin-top: 2px;"></span>
                            🚀 Processar Análise, Desduplicação e Cruzamento
                        </button>
                    </div>
                </form>
            </div>

        <?php else:
            $summary     = $cross_result['summary'];
            $by_campaign = $cross_result['by_campaign'];
            $by_course   = $cross_result['by_course'];
            $by_area     = $cross_result['by_area'];
            $by_polo     = $cross_result['by_polo'];
            $leads       = $cross_result['leads'];
        ?>
            <!-- PAINEL DE RESULTADOS DA ANÁLISE -->

            <!-- BARRA DE AÇÕES DO RESULTADO -->
            <div class="li-card" style="padding: 16px 22px; margin-bottom: 20px; background: #ffffff; border-left: 4px solid #C5A059; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                <div>
                    <h3 style="margin: 0 0 4px; font-size: 17px; color: #163930; font-weight: 700;">
                        Diagnóstico de Qualidade Concluído
                    </h3>
                    <span style="font-size: 13px; color: #5B786F;">
                        Análise de <strong><?php echo number_format_i18n($summary['total_submissoes_brutas']); ?></strong> envios do Elementor contra base de <strong><?php echo number_format_i18n($summary['total_base_qualificados']); ?></strong> qualificados (processado em <?php echo esc_html($summary['tempo_execucao_segundos']); ?>s).
                    </span>
                </div>

                <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                    <!-- EXPORTAR CSV -->
                    <form method="post" action="" style="margin: 0;">
                        <?php wp_nonce_field('li_cross_export_verify', 'li_nonce'); ?>
                        <button type="submit" name="li_export_cross_csv" class="button button-secondary" style="font-weight: 600; display: inline-flex; align-items: center; gap: 6px;">
                            <span class="dashicons dashicons-download" style="color: #0284c7;"></span>
                            Baixar CSV Consolidado
                        </button>
                    </form>

                    <!-- SALVAR NO BANCO -->
                    <form method="post" action="" style="margin: 0;" onsubmit="return confirm('Deseja salvar e atualizar estes <?php echo count($leads); ?> leads no banco de dados do plugin (wp_li_leads)?');">
                        <?php wp_nonce_field('li_cross_save_verify', 'li_nonce'); ?>
                        <button type="submit" name="li_save_cross_to_db" class="button button-primary" style="background: #10824C; border-color: #0c673c; font-weight: 600; display: inline-flex; align-items: center; gap: 6px;">
                            <span class="dashicons dashicons-database-add"></span>
                            Salvar Leads no Banco (wp_li_leads)
                        </button>
                    </form>

                    <!-- NOVA ANÁLISE / LIMPAR -->
                    <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-import&tab=elementor_cross&clear_cross=1')); ?>" class="button button-link-delete" style="color: #64748b; font-size: 12px; margin-left: 6px;">
                        Nova Análise
                    </a>
                </div>
            </div>

            <!-- GRID DE METRICAS PRINCIPAIS (6 CARDS EXECUTIVOS) -->
            <div class="li-metric-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 24px;">
                <div class="li-metric-card li-card-info" style="border-top: 3px solid #0284c7;">
                    <span class="li-metric-label">Envios Brutos Elementor</span>
                    <span class="li-metric-value" style="color: #0284c7;"><?php echo number_format_i18n($summary['total_submissoes_brutas']); ?></span>
                    <span style="font-size: 11px; color: #64748b;">Submissões capturadas</span>
                </div>

                <div class="li-metric-card" style="border-top: 3px solid #10824C; background: #fff;">
                    <span class="li-metric-label">Leads Únicos (Desduplicados)</span>
                    <span class="li-metric-value" style="color: #10824C;"><?php echo number_format_i18n($summary['total_leads_unicos']); ?></span>
                    <span style="font-size: 11px; color: #64748b;">Contatos únicos</span>
                </div>

                <div class="li-metric-card" style="border-top: 3px solid #f59e0b; background: #fff;">
                    <span class="li-metric-label">Duplicidades Agrupadas</span>
                    <span class="li-metric-value" style="color: #d97706;"><?php echo number_format_i18n($summary['total_duplicados_leads']); ?></span>
                    <span style="font-size: 11px; color: #64748b;">+<?php echo esc_html($summary['total_submissoes_extras']); ?> envios repetidos</span>
                </div>

                <div class="li-metric-card li-card-success" style="border-top: 3px solid #10b981; background: #f0fdf4;">
                    <span class="li-metric-label">Leads Qualificados</span>
                    <span class="li-metric-value" style="color: #059669;"><?php echo number_format_i18n($summary['total_qualificados']); ?></span>
                    <span class="li-badge li-status-qualificado" style="font-size: 11px; margin-top: 4px; display: inline-block;">
                        Taxa: <?php echo esc_html($summary['taxa_qualificacao_global']); ?>%
                    </span>
                </div>

                <div class="li-metric-card" style="border-top: 3px solid #94a3b8; background: #fff;">
                    <span class="li-metric-label">Não Qualificados / Pendentes</span>
                    <span class="li-metric-value" style="color: #64748b;"><?php echo number_format_i18n($summary['total_nao_qualificados']); ?></span>
                    <span style="font-size: 11px; color: #64748b;">Aguardando conversão</span>
                </div>

                <div class="li-metric-card" style="border-top: 3px solid #C5A059; background: #fff;">
                    <span class="li-metric-label">Base de Qualificados</span>
                    <span class="li-metric-value" style="color: #A37E36;"><?php echo number_format_i18n($summary['total_base_qualificados']); ?></span>
                    <span style="font-size: 11px; color: #64748b;">Matrículas na planilha</span>
                </div>
            </div>

            <!-- GRID DE DIAGNÓSTICO E QUALIDADE POR DIMENSÕES -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(420px, 1fr)); gap: 20px; margin-bottom: 24px;">
                <!-- COLUNA 1: QUALIDADE POR CAMPANHA / UTM -->
                <div class="li-card" style="margin-bottom: 0;">
                    <h3 class="li-card-title" style="display: flex; align-items: center; gap: 8px; font-size: 15px; margin-bottom: 12px;">
                        <span class="dashicons dashicons-tag" style="color: #10824C;"></span>
                        Qualidade por Campanha (UTM Source / Campaign)
                    </h3>
                    <p class="li-card-desc" style="font-size: 12px; margin-bottom: 12px;">Taxa de conversão e volume de contatos por origem.</p>

                    <table class="wp-list-table widefat fixed striped" style="font-size: 12px;">
                        <thead>
                            <tr>
                                <th>Campanha / Origem</th>
                                <th style="width: 70px; text-align: center;">Leads</th>
                                <th style="width: 70px; text-align: center;">Qualif.</th>
                                <th style="width: 70px; text-align: center;">Duplic.</th>
                                <th style="width: 130px;">Conversão</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($by_campaign as $camp): ?>
                                <tr>
                                    <td><strong><?php echo esc_html($camp['nome']); ?></strong></td>
                                    <td style="text-align: center;"><?php echo esc_html($camp['total']); ?></td>
                                    <td style="text-align: center;">
                                        <?php if ($camp['qualificados'] > 0): ?>
                                            <span style="color: #10b981; font-weight: 700;"><?php echo esc_html($camp['qualificados']); ?></span>
                                        <?php else: ?>
                                            <span style="color: #94a3b8;">0</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: center;">
                                        <?php if ($camp['duplicados'] > 0): ?>
                                            <span style="color: #d97706;"><?php echo esc_html($camp['duplicados']); ?></span>
                                        <?php else: ?>
                                            <span style="color: #94a3b8;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 6px;">
                                            <div style="flex-grow: 1; background: #e2e8f0; height: 7px; border-radius: 4px; overflow: hidden;">
                                                <div style="width: <?php echo min(100, $camp['taxa'] * 4); ?>%; background: <?php echo $camp['taxa'] > 0 ? '#10824C' : '#cbd5e1'; ?>; height: 100%;"></div>
                                            </div>
                                            <span style="font-size: 11px; font-weight: 600; min-width: 38px;"><?php echo esc_html($camp['taxa']); ?>%</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- COLUNA 2: CURSO & ÁREA DE INTERESSE -->
                <div style="display: flex; flex-direction: column; gap: 20px;">
                    <!-- CURSO -->
                    <div class="li-card" style="margin-bottom: 0;">
                        <h3 class="li-card-title" style="display: flex; align-items: center; gap: 8px; font-size: 15px; margin-bottom: 12px;">
                            <span class="dashicons dashicons-welcome-learn-more" style="color: #C5A059;"></span>
                            Qualidade por Tipo de Curso
                        </h3>
                        <table class="wp-list-table widefat fixed striped" style="font-size: 12px;">
                            <thead>
                                <tr>
                                    <th>Modalidade</th>
                                    <th style="width: 80px; text-align: center;">Leads</th>
                                    <th style="width: 80px; text-align: center;">Qualificados</th>
                                    <th style="width: 100px; text-align: center;">Taxa (%)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($by_course as $crs): ?>
                                    <tr>
                                        <td><strong><?php echo esc_html($crs['nome']); ?></strong></td>
                                        <td style="text-align: center;"><?php echo esc_html($crs['total']); ?></td>
                                        <td style="text-align: center; color: #10b981; font-weight: 700;"><?php echo esc_html($crs['qualificados']); ?></td>
                                        <td style="text-align: center; font-weight: 600;"><?php echo esc_html($crs['taxa']); ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- ÁREA DE INTERESSE -->
                    <div class="li-card" style="margin-bottom: 0;">
                        <h3 class="li-card-title" style="display: flex; align-items: center; gap: 8px; font-size: 15px; margin-bottom: 12px;">
                            <span class="dashicons dashicons-category" style="color: #0284c7;"></span>
                            Top Áreas de Interesse
                        </h3>
                        <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                            <?php foreach (array_slice($by_area, 0, 8) as $ar): ?>
                                <div style="padding: 8px 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 12px; display: inline-flex; align-items: center; gap: 8px;">
                                    <span style="font-weight: 600; color: #1e293b;"><?php echo esc_html(ucfirst($ar['nome'])); ?></span>
                                    <span style="background: #e2e8f0; color: #475569; font-size: 11px; padding: 2px 6px; border-radius: 10px; font-weight: 600;"><?php echo esc_html($ar['total']); ?></span>
                                    <?php if ($ar['qualificados'] > 0): ?>
                                        <span style="background: #dcfce7; color: #15803d; font-size: 11px; padding: 2px 6px; border-radius: 10px; font-weight: 700;">✔ <?php echo esc_html($ar['qualificados']); ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TABELA INTERATIVA DE LEADS CRUZADOS -->
            <div class="li-card" style="padding: 0; overflow: hidden;">
                <div style="padding: 18px 24px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px; background: #fafafa;">
                    <div>
                        <h3 class="li-card-title" style="margin: 0 0 4px;">Relação de Leads &bull; Cruzamento & Qualificação</h3>
                        <span id="li_table_counter" style="font-size: 12px; color: #64748b;">
                            Exibindo todos os <strong><?php echo count($leads); ?></strong> leads únicos.
                        </span>
                    </div>

                    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                        <!-- BUSCA RÁPIDA -->
                        <div style="position: relative;">
                            <input type="text" id="li_search_leads" placeholder="🔍 Buscar nome, telefone, e-mail, campanha..." style="width: 280px; font-size: 13px; padding: 6px 12px; border-radius: 6px; border: 1px solid #cbd5e1;">
                        </div>

                        <!-- FILTROS POR BOTÃO -->
                        <div class="button-group" style="display: inline-flex;">
                            <button type="button" class="button li-filter-btn button-primary" data-filter="all">Todos (<?php echo count($leads); ?>)</button>
                            <button type="button" class="button li-filter-btn" data-filter="qualificado">✔ Qualificados (<?php echo esc_html($summary['total_qualificados']); ?>)</button>
                            <button type="button" class="button li-filter-btn" data-filter="duplicado">🔥 Múltiplos Envios (<?php echo esc_html($summary['total_duplicados_leads']); ?>)</button>
                            <button type="button" class="button li-filter-btn" data-filter="pendente">Pendentes (<?php echo esc_html($summary['total_nao_qualificados']); ?>)</button>
                        </div>
                    </div>
                </div>

                <div style="overflow-x: auto;">
                    <table class="wp-list-table widefat fixed striped li-table" id="li_leads_table">
                        <thead>
                            <tr>
                                <th style="width: 220px;">Lead / Contato</th>
                                <th style="width: 140px;">Origem / Campanha</th>
                                <th style="width: 160px;">Interesse</th>
                                <th style="width: 120px; text-align: center;">Envios Elementor</th>
                                <th style="width: 160px;">Status Qualificação</th>
                                <th style="width: 130px;">Data de Envio</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($leads as $l):
                                $is_qual = !empty($l['qualificado']);
                                $is_dup  = !empty($l['submissoes_count']) && $l['submissoes_count'] > 1;
                                $status_class = $is_qual ? 'row-qualificado' : 'row-pendente';
                                $dup_class = $is_dup ? 'row-duplicado' : '';
                            ?>
                                <tr class="li-lead-row <?php echo esc_attr($status_class . ' ' . $dup_class); ?>"
                                    data-status="<?php echo $is_qual ? 'qualificado' : 'pendente'; ?>"
                                    data-dup="<?php echo $is_dup ? '1' : '0'; ?>">
                                    <td>
                                        <div style="font-weight: 700; color: #1e293b; font-size: 13px;">
                                            <?php echo esc_html($l['nome'] ?: 'Sem Nome'); ?>
                                        </div>
                                        <?php if (!empty($l['telefone'])): ?>
                                            <div style="font-size: 12px; color: #0284c7; margin-top: 2px;">
                                                <a href="https://wa.me/<?php echo esc_attr(preg_replace('/\D/', '', $l['telefone_normalizado'] ?: $l['telefone'])); ?>" target="_blank" style="text-decoration: none; color: inherit;">
                                                    📱 <?php echo esc_html($l['telefone']); ?>
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($l['email'])): ?>
                                            <div style="font-size: 11px; color: #64748b; margin-top: 1px;">
                                                ✉ <?php echo esc_html($l['email']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <span class="li-badge li-status-cinza" style="font-size: 11px; max-width: 130px; overflow: hidden; text-overflow: ellipsis; display: inline-block;">
                                            <?php echo esc_html($l['utm_source'] ?: ($l['utm_campaign'] ?: 'Direto')); ?>
                                        </span>
                                        <?php if (!empty($l['utm_campaign']) && $l['utm_campaign'] !== $l['utm_source']): ?>
                                            <div style="font-size: 10px; color: #64748b; margin-top: 2px;">
                                                <?php echo esc_html($l['utm_campaign']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <div style="font-weight: 600; font-size: 12px; color: #334155;">
                                            <?php echo esc_html($l['tipo_curso'] ?: '-'); ?>
                                        </div>
                                        <?php if (!empty($l['area_interesse'])): ?>
                                            <div style="font-size: 11px; color: #64748b;">
                                                Área: <?php echo esc_html($l['area_interesse']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <td style="text-align: center;">
                                        <?php if ($is_dup): ?>
                                            <span class="li-badge" style="background: #fef3c7; color: #92400e; border: 1px solid #f59e0b; font-weight: 700; cursor: help;"
                                                  title="<?php echo esc_attr(count($l['submissoes']) . ' envios detectados.'); ?>">
                                                🔥 <?php echo esc_html($l['submissoes_count']); ?> envios
                                            </span>
                                        <?php else: ?>
                                            <span class="li-badge li-status-cinza" style="font-size: 11px;">
                                                1 envio
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?php if ($is_qual): ?>
                                            <span class="li-badge li-status-qualificado" style="font-size: 11px; font-weight: 700;">
                                                ✔ QUALIFICADO
                                            </span>
                                            <div style="font-size: 10px; color: #059669; margin-top: 3px;">
                                                <?php echo esc_html($l['match_motivo']); ?>
                                            </div>
                                            <?php if (!empty($l['polo_qualificado'])): ?>
                                                <div style="font-size: 10px; color: #475569; font-weight: 600;">
                                                    Polo: <?php echo esc_html($l['polo_qualificado']); ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="li-badge li-status-cinza" style="font-size: 11px;">
                                                PENDENTE
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td style="font-size: 11px; color: #64748b;">
                                        <?php if (!empty($l['data_primeiro_envio'])): ?>
                                            <?php echo esc_html(date_i18n('d/m/Y H:i', strtotime($l['data_primeiro_envio']))); ?>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- JAVASCRIPT NATIVO DE FILTRAGEM INSTANTÂNEA -->
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                var searchInput = document.getElementById('li_search_leads');
                var filterBtns  = document.querySelectorAll('.li-filter-btn');
                var rows        = document.querySelectorAll('.li-lead-row');
                var counterEl   = document.getElementById('li_table_counter');
                var currentFilter = 'all';

                function updateTable() {
                    var term = (searchInput ? searchInput.value.toLowerCase().trim() : '');
                    var visibleCount = 0;

                    rows.forEach(function(row) {
                        var status = row.getAttribute('data-status');
                        var isDup  = row.getAttribute('data-dup') === '1';
                        var text   = row.textContent.toLowerCase();

                        var matchesFilter = true;
                        if (currentFilter === 'qualificado') {
                            matchesFilter = (status === 'qualificado');
                        } else if (currentFilter === 'duplicado') {
                            matchesFilter = isDup;
                        } else if (currentFilter === 'pendente') {
                            matchesFilter = (status === 'pendente');
                        }

                        var matchesSearch = (term === '' || text.indexOf(term) !== -1);

                        if (matchesFilter && matchesSearch) {
                            row.style.display = '';
                            visibleCount++;
                        } else {
                            row.style.display = 'none';
                        }
                    });

                    if (counterEl) {
                        counterEl.innerHTML = 'Exibindo <strong>' + visibleCount + '</strong> de ' + rows.length + ' leads.';
                    }
                }

                if (searchInput) {
                    searchInput.addEventListener('input', updateTable);
                }

                filterBtns.forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        filterBtns.forEach(function(b) { b.classList.remove('button-primary'); });
                        btn.classList.add('button-primary');
                        currentFilter = btn.getAttribute('data-filter');
                        updateTable();
                    });
                });
            });
            </script>
        <?php endif;
    }
}

