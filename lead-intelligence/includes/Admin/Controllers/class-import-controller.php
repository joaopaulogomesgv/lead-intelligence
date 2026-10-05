<?php
namespace LeadIntelligence\Admin\Controllers;

use LeadIntelligence\Qualification\SpreadsheetParser;
use LeadIntelligence\Qualification\Matcher;
use LeadIntelligence\Database\DbSchema;
use LeadIntelligence\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Controller de Importação e Mapeamento de Planilhas de Qualificação
 */
class ImportController {

    public static function render() {
        $step = 1;
        $error = '';
        $success_message = '';
        $preview_data = null;
        $temp_file = '';
        $original_filename = '';

        // PASSO 2: Upload Realizado -> Exibir Mapeamento
        if (isset($_POST['li_upload_spreadsheet'])) {
            check_admin_referer('li_import_upload_verify', 'li_nonce');

            if (!current_user_can('manage_options')) {
                wp_die('Permissão insuficiente.');
            }

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
                            $step = 2; // Avança para o assistente de mapeamento
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

        // PASSO 3: Mapeamento Confirmado -> Processar e Vincular
        if (isset($_POST['li_confirm_mapping'])) {
            check_admin_referer('li_mapping_verify', 'li_nonce');

            if (!current_user_can('manage_options')) {
                wp_die('Permissão insuficiente.');
            }

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
                    'default_status' => sanitize_text_field(wp_unslash($_POST['default_status'] ?? 'qualificado')),
                ];

                global $wpdb;
                $imports_table = DbSchema::get_imports_table();

                // Registra lote de importação
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

                    // Atualiza lote de importação com totais
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

                    $success_message = "Importação concluída com sucesso! <strong>{$total_processed}</strong> linhas processadas. <strong>{$total_matched}</strong> leads do Elementor foram cruzados e qualificados, e <strong>{$total_created}</strong> novos leads cadastrados.";
                    $step = 1;
                } catch (\Throwable $e) {
                    $error = 'Erro durante o processamento da planilha: ' . $e->getMessage();
                    $wpdb->update($imports_table, ['status' => 'erro'], ['id' => $import_id]);
                }

                @unlink($temp_file);
            }
        }

        // Histórico de Importações
        global $wpdb;
        $imports_table = DbSchema::get_imports_table();
        $history = $wpdb->get_results("SELECT * FROM {$imports_table} ORDER BY id DESC LIMIT 10");
        ?>
        <div class="wrap li-wrap">
            <div class="li-header">
                <h2>Lead Intelligence &bull; Importar Qualificações de Leads</h2>
                <p class="li-subtitle">Faça upload de planilhas de matrículas/vendas (CSV ou XLSX) para cruzar automaticamente com os leads capturados do Elementor Pro.</p>
            </div>

            <?php if (!empty($error)): ?>
                <div class="notice notice-error is-dismissible"><p><?php echo esc_html($error); ?></p></div>
            <?php endif; ?>

            <?php if (!empty($success_message)): ?>
                <div class="notice notice-success is-dismissible"><p><?php echo wp_kses_post($success_message); ?></p></div>
            <?php endif; ?>

            <!-- ETAPA 1: SELEÇÃO E UPLOAD DO ARQUIVO -->
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

            <!-- ETAPA 2: ASSISTENTE DE MAPEAMENTO DE COLUNAS -->
            <?php elseif ($step === 2 && $preview_data): ?>
                <div class="li-card">
                    <h3 class="li-card-title">2. Mapeamento de Colunas da Planilha</h3>
                    <p class="li-card-desc">Arquivo: <strong><?php echo esc_html($original_filename); ?></strong> &bull; Total aproximado: <strong><?php echo esc_html($preview_data['total_rows']); ?> linhas</strong>.</p>

                    <!-- PREVIEW DAS PRIMEIRAS LINHAS -->
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

                    <!-- FORMULÁRIO DE-PARA DE COLUNAS -->
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
                                <th scope="row">
                                    <label for="map_nome">Nome do Aluno / Lead</label>
                                </th>
                                <td>
                                    <?php echo self::render_select('map_nome', $preview_data['headers'], ['nome', 'name', 'firstname', 'cliente', 'aluno']); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="map_status">Coluna de Status (Opcional)</label>
                                </th>
                                <td>
                                    <?php echo self::render_select('map_status', $preview_data['headers'], ['status', 'situacao', 'qualificacao', 'matriculado', 'pago']); ?>
                                    <p class="description">Identifica se o lead é "Qualificado", "Desqualificado", etc. Se não houver, utilize o status padrão abaixo.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="default_status">Status Padrão se não informado</label>
                                </th>
                                <td>
                                    <select name="default_status" id="default_status">
                                        <option value="qualificado" selected>Qualificado (Ex: Lista de Alunos Matriculados)</option>
                                        <option value="pendente">Pendente</option>
                                        <option value="nao_qualificado">Não Qualificado</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="map_data">Data da Matrícula / Qualificação</label>
                                </th>
                                <td>
                                    <?php echo self::render_select('map_data', $preview_data['headers'], ['data', 'date', 'data_matricula', 'data_cadastro']); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="map_curso">Tipo de Curso / Modalidade</label>
                                </th>
                                <td>
                                    <?php echo self::render_select('map_curso', $preview_data['headers'], ['curso', 'tipo_curso', 'modalidade']); ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="map_area">Área de Interesse / Polo</label>
                                </th>
                                <td>
                                    <?php echo self::render_select('map_area', $preview_data['headers'], ['area', 'area_interesse', 'polo']); ?>
                                </td>
                            </tr>
                        </table>

                        <div style="margin-top: 24px;">
                            <button type="submit" name="li_confirm_mapping" class="button button-primary button-large">
                                Iniciar Cruzamento e Vinculação Automática &rarr;
                            </button>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-import')); ?>" class="button button-secondary button-large" style="margin-left: 10px;">
                                Cancelar
                            </a>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

            <!-- HISTÓRICO DE IMPORTAÇÕES -->
            <div class="li-card" style="padding: 0; overflow-x: auto;">
                <div style="padding: 18px 24px 10px; border-bottom: 1px solid #e2e8f0;">
                    <h3 class="li-card-title">Histórico de Importações Recentes</h3>
                </div>
                <table class="wp-list-table widefat fixed striped li-table">
                    <thead>
                        <tr>
                            <th style="width: 70px;">ID</th>
                            <th style="width: 140px;">Data</th>
                            <th>Arquivo</th>
                            <th style="width: 100px;">Total Linhas</th>
                            <th style="width: 140px;">Cruzados (Elementor)</th>
                            <th style="width: 130px;">Novos Cadastrados</th>
                            <th style="width: 110px;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($history)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 30px; color: #64748b;">
                                    Nenhuma planilha foi importada ainda.
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
    private static function render_select($name, $headers, $match_keywords = []) {
        $html = '<select name="' . esc_attr($name) . '" id="' . esc_attr($name) . '" style="min-width: 250px;">';
        $html .= '<option value="">-- Não mapear este campo --</option>';

        $selected_found = false;
        foreach ($headers as $header) {
            $is_selected = false;
            if (!$selected_found && !empty($match_keywords)) {
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
