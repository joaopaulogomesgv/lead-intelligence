<?php
namespace LeadIntelligence\Admin\Controllers;

use LeadIntelligence\Qualification\SpreadsheetParser;
use LeadIntelligence\Qualification\Matcher;
use LeadIntelligence\Qualification\GoogleSheetsClient;
use LeadIntelligence\Qualification\GoogleSheetsSync;
use LeadIntelligence\Database\DbSchema;
use LeadIntelligence\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Controller de Importação e Mapeamento de Planilhas de Qualificação
 * Suporta Upload Manual (CSV/XLSX) e Sincronização Automática via Google Sheets API v4
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

        // 1. Conexão e Salvamento de Credenciais Google OAuth
        if (isset($_POST['li_save_google_creds']) || isset($_POST['li_connect_google_btn'])) {
            check_admin_referer('li_google_creds_verify', 'li_nonce');

            $client_id     = sanitize_text_field(wp_unslash($_POST['google_client_id'] ?? ''));
            $client_secret = sanitize_text_field(wp_unslash($_POST['google_client_secret'] ?? ''));

            $current_auth = GoogleSheetsClient::get_auth_data();
            if (strpos($client_secret, '••••') !== false || empty($client_secret)) {
                $client_secret = $current_auth['client_secret'];
            }

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
    }

    public static function render() {
        $active_tab = sanitize_key($_GET['tab'] ?? (isset($_GET['google_connected']) || isset($_GET['google_error']) || isset($_GET['saved_creds']) || isset($_GET['sync_success']) ? 'google_sheets' : 'google_sheets'));
        $error = '';
        $success_message = '';
        $step = 1;
        $preview_data = null;
        $temp_file = '';
        $original_filename = '';

        // Alertas vindos via URL
        if (isset($_GET['google_connected'])) {
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

                    Logger::info('Planilha', "Importação de '{$original_filename}' finalizada.", [
                        'processadas' => $total_processed,
                        'cruzados'    => $total_matched,
                        'criados'     => $total_created,
                        'ignorados'   => $total_skipped,
                    ]);

                    $success_message = "Importação concluída com sucesso! <strong>{$total_processed}</strong> linhas processadas: <strong>{$total_matched}</strong> leads cruzados/qualificados e <strong>{$total_created}</strong> novos cadastrados.";
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
        $redirect_uri = GoogleSheetsClient::get_redirect_uri();
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
        $history = $wpdb->get_results("SELECT * FROM {$imports_table} ORDER BY id DESC LIMIT 10");
        ?>
        <div class="wrap li-wrap">
            <div class="li-header">
                <h2>Lead Intelligence &bull; Qualificação & Planilhas</h2>
                <p class="li-subtitle">Conecte suas planilhas de Google Ads e Meta Ads ou envie arquivos para cruzar vendas e matrículas com os leads.</p>
            </div>

            <!-- NAVEGAÇÃO POR ABAS -->
            <nav class="nav-tab-wrapper" style="margin-bottom: 24px;">
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
                    Upload Manual de Arquivo (CSV / XLSX)
                </a>
            </nav>

            <?php if (!empty($error)): ?>
                <div class="notice notice-error is-dismissible"><p><?php echo esc_html($error); ?></p></div>
            <?php endif; ?>

            <?php if (!empty($success_message)): ?>
                <div class="notice notice-success is-dismissible"><p><?php echo wp_kses_post($success_message); ?></p></div>
            <?php endif; ?>

            <?php if ($active_tab === 'google_sheets'): ?>
                <!-- ============================================================== -->
                <!-- ABA: GOOGLE SHEETS AUTOMÁTICO (API V4 VIA OAUTH 2.0) -->
                <!-- ============================================================== -->

                <!-- CARD 1: STATUS DE AUTENTICAÇÃO OAUTH -->
                <div class="li-card">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 15px;">
                        <div>
                            <h3 class="li-card-title" style="display: flex; align-items: center; gap: 8px;">
                                <span class="dashicons dashicons-admin-network" style="color: #4285f4;"></span>
                                1. Conexão com sua Conta Google
                            </h3>
                            <p class="li-card-desc" style="margin-bottom: 0;">
                                Permite ao plugin ler e sincronizar planilhas privadas do Google onde seu e-mail possui permissão de <strong>"Somente ver"</strong>.
                            </p>
                        </div>

                        <?php if ($is_google_connected): ?>
                            <span class="li-badge li-status-qualificado" style="font-size: 13px; padding: 6px 12px; display: inline-flex; align-items: center; gap: 6px;">
                                <span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:#10b981;"></span>
                                Conectado: <?php echo esc_html($google_auth['connected_email'] ?: 'OAuth Ativo'); ?>
                            </span>
                        <?php else: ?>
                            <span class="li-badge li-status-cinza" style="font-size: 13px; padding: 6px 12px;">
                                Desconectado
                            </span>
                        <?php endif; ?>
                    </div>

                    <?php if (!$is_google_connected): ?>
                        <!-- GUIA RÁPIDO PARA OAUTH -->
                        <div style="margin-top: 20px; padding: 16px 20px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 13px; color: #475569;">
                            <strong style="color: #0f172a; display: block; margin-bottom: 6px;">
                                <span class="dashicons dashicons-info" style="color: #3b82f6;"></span> Como configurar o acesso no Google Cloud:
                            </strong>
                            <ol style="margin: 0 0 0 20px; padding: 0; line-height: 1.6;">
                                <li>Acesse o <a href="https://console.cloud.google.com/" target="_blank" rel="noopener noreferrer"><strong>Google Cloud Console</strong></a> com a conta que possui acesso à planilha.</li>
                                <li>Ative a <strong>Google Sheets API</strong> em <em>APIs e Serviços &rarr; Biblioteca</em>.</li>
                                <li>Em <em>APIs e Serviços &rarr; Credenciais &rarr; Criar Credenciais &rarr; <strong>ID do cliente OAuth</strong></em> (Tipo: <strong>Aplicativo da Web</strong>).</li>
                                <li>No campo <strong>URIs de redirecionamento autorizados</strong>, adicione a URL abaixo:</li>
                            </ol>
                            <div style="margin: 10px 0 6px 20px; display: flex; align-items: center; gap: 8px;">
                                <input type="text" id="li_redirect_uri" readonly value="<?php echo esc_attr($redirect_uri); ?>" style="width: 100%; max-width: 480px; font-family: monospace; font-size: 12px; background: #ffffff;">
                                <button type="button" class="button button-secondary button-small" onclick="navigator.clipboard.writeText(document.getElementById('li_redirect_uri').value); alert('URL copiada!');">
                                    Copiar URI
                                </button>
                            </div>
                        </div>

                        <!-- FORMULÁRIO DE CREDENCIAIS OAUTH -->
                        <form method="post" action="" style="margin-top: 20px;">
                            <?php wp_nonce_field('li_google_creds_verify', 'li_nonce'); ?>

                            <table class="form-table" style="margin-top: 0;">
                                <tr>
                                    <th scope="row"><label for="google_client_id">Client ID do Google</label></th>
                                    <td>
                                        <input type="text" name="google_client_id" id="google_client_id"
                                               value="<?php echo esc_attr($google_auth['client_id']); ?>"
                                               class="regular-text" style="width: 100%; max-width: 480px;"
                                               placeholder="ex: 123456789-xxxxxxxx.apps.googleusercontent.com" required>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="google_client_secret">Client Secret do Google</label></th>
                                    <td>
                                        <input type="password" name="google_client_secret" id="google_client_secret"
                                               value="<?php echo !empty($google_auth['client_secret']) ? '••••••••••••••••' : ''; ?>"
                                               class="regular-text" style="width: 100%; max-width: 480px;"
                                               placeholder="GOCSPX-xxxxxxxxxxxxxx" required>
                                    </td>
                                </tr>
                            </table>

                            <div style="margin-top: 15px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                                <?php if (!empty($auth_url)): ?>
                                    <a href="<?php echo esc_url($auth_url); ?>" class="button button-primary button-large" style="display: inline-flex; align-items: center; gap: 6px;">
                                        <span class="dashicons dashicons-google" style="margin-top:2px;"></span>
                                        Conectar Conta Google (Somente Leitura) &rarr;
                                    </a>
                                <?php else: ?>
                                    <button type="submit" name="li_connect_google_btn" class="button button-primary button-large" style="display: inline-flex; align-items: center; gap: 6px;">
                                        <span class="dashicons dashicons-google" style="margin-top:2px;"></span>
                                        Conectar Conta Google (Somente Leitura) &rarr;
                                    </button>
                                <?php endif; ?>

                                <button type="submit" name="li_save_google_creds" class="button button-secondary">
                                    Salvar Credenciais
                                </button>
                            </div>
                        </form>

                    <?php else: ?>
                        <!-- CONECTADO: AÇÕES DE CONEXÃO -->
                        <div style="margin-top: 16px; padding: 14px 18px; background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 8px; font-size: 13px; color: #065f46; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                            <div>
                                <strong>✔ Autenticação Ativa:</strong> O plugin possui autorização permanente de leitura para acessar as planilhas da sua conta (renovação automática em segundo plano).
                            </div>
                            <form method="post" action="" onsubmit="return confirm('Deseja realmente desconectar sua conta Google deste plugin?');">
                                <?php wp_nonce_field('li_google_disconnect_verify', 'li_nonce'); ?>
                                <button type="submit" name="li_disconnect_google" class="button button-link-delete" style="color: #b91c1c; font-size: 13px;">
                                    Desconectar Conta Google
                                </button>
                            </form>
                        </div>
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
}
