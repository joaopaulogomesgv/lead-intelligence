<?php
namespace LeadIntelligence\Admin\Controllers;

use LeadIntelligence\Database\DbSchema;
use LeadIntelligence\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Controller de Visualização e Gestão de Logs do Sistema
 */
class LogsController {

    public static function render() {
        global $wpdb;

        // Limpeza manual de logs
        if (isset($_POST['li_clear_logs'])) {
            if (check_admin_referer('li_clear_logs_verify', 'li_nonce') && current_user_can('manage_options')) {
                $logs_table = DbSchema::get_logs_table();
                $wpdb->query("TRUNCATE TABLE {$logs_table}");
                Logger::info('Sistema', 'Logs do sistema foram limpos pelo administrador.');
                echo '<div class="notice notice-success is-dismissible"><p>Logs limpos com sucesso!</p></div>';
            }
        }

        $logs_table = DbSchema::get_logs_table();
        $categoria  = isset($_GET['cat']) ? sanitize_text_field(wp_unslash($_GET['cat'])) : '';
        $nivel      = isset($_GET['lvl']) ? sanitize_text_field(wp_unslash($_GET['lvl'])) : '';
        $search     = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $lead_id    = isset($_GET['lead_id']) ? max(0, (int) $_GET['lead_id']) : 0;
        $page       = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $per_page   = 50;
        $offset     = ($page - 1) * $per_page;

        $where = ['1=1'];
        $params = [];

        if (!empty($lead_id)) {
            $lid = (int) $lead_id;
            $where[] = "(
                mensagem LIKE %s OR 
                mensagem LIKE %s OR 
                contexto LIKE %s OR 
                contexto LIKE %s OR
                id = %d
            )";
            $params[] = '%#' . $lid . '%';
            $params[] = '%Lead ' . $lid . '%';
            $params[] = '%"lead_id":' . $lid . '%';
            $params[] = '%"lead_id":"' . $lid . '"%';
            $params[] = $lid;
        } elseif (!empty($search)) {
            $clean_s = trim($search);
            $numeric_id = preg_replace('/\D/', '', $clean_s);
            if (!empty($numeric_id) && strlen($numeric_id) >= 2 && (is_numeric($clean_s) || strpos($clean_s, '#') === 0)) {
                $lid = (int) $numeric_id;
                $where[] = "(
                    mensagem LIKE %s OR 
                    mensagem LIKE %s OR 
                    contexto LIKE %s OR 
                    contexto LIKE %s OR 
                    contexto LIKE %s OR
                    id = %d
                )";
                $params[] = '%#' . $lid . '%';
                $params[] = '%' . $lid . '%';
                $params[] = '%"lead_id":' . $lid . '%';
                $params[] = '%"lead_id":"' . $lid . '"%';
                $params[] = '%' . $wpdb->esc_like($clean_s) . '%';
                $params[] = $lid;
            } else {
                $like = '%' . $wpdb->esc_like($clean_s) . '%';
                $where[] = "(mensagem LIKE %s OR contexto LIKE %s)";
                $params[] = $like;
                $params[] = $like;
            }
        }

        if (!empty($categoria)) {
            $where[] = "categoria = %s";
            $params[] = $categoria;
        }

        if (!empty($nivel)) {
            $where[] = "nivel = %s";
            $params[] = $nivel;
        }

        $where_sql = implode(' AND ', $where);

        $count_sql = "SELECT COUNT(*) FROM {$logs_table} WHERE {$where_sql}";
        if (!empty($params)) {
            $count_sql = $wpdb->prepare($count_sql, $params);
        }
        $total = (int) $wpdb->get_var($count_sql);
        $total_pages = ceil($total / $per_page);

        $query = "SELECT * FROM {$logs_table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
        $query_params = array_merge($params, [$per_page, $offset]);
        $logs = $wpdb->get_results($wpdb->prepare($query, $query_params));

        $categories = $wpdb->get_col("SELECT DISTINCT categoria FROM {$logs_table} ORDER BY categoria ASC");
        ?>
        <div class="wrap li-wrap">
            <div class="li-header" style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h2>Lead Intelligence &bull; Logs de Auditoria</h2>
                    <p class="li-subtitle">Registro de eventos do Elementor, sincronizações, webhooks e erros.</p>
                </div>
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <button type="button" class="button button-primary button-large" onclick="liCopyDiagnostic(this)" style="display: inline-flex; align-items: center; gap: 6px; background: #4f46e5; border-color: #4338ca; color: #fff; font-weight: 600; padding: 0 14px; height: 36px; line-height: 34px;">
                        <span class="dashicons dashicons-clipboard" style="font-size: 17px; width: 17px; height: 17px; line-height: 17px;"></span>
                        📋 Copiar Diagnóstico Completo (para IA / Chat)
                    </button>
                    <form method="post" action="" onsubmit="return confirm('Deseja realmente limpar todos os logs?');" style="margin: 0;">
                        <?php wp_nonce_field('li_clear_logs_verify', 'li_nonce'); ?>
                        <button type="submit" name="li_clear_logs" class="button button-secondary" style="height: 36px; line-height: 34px;">Limpar Todos os Logs</button>
                    </form>
                </div>
            </div>

            <!-- FILTRO DE LOGS -->
            <div class="li-card li-filter-bar">
                <form method="get" action="">
                    <input type="hidden" name="page" value="lead-intelligence-logs">
                    <div class="li-filter-row" style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                        <div style="position: relative; flex: 1; min-width: 240px; max-width: 380px;">
                            <span class="dashicons dashicons-search" style="position: absolute; left: 8px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 16px;"></span>
                            <input type="text" name="s" value="<?php echo esc_attr($search ?: ($lead_id ? '#' . $lead_id : '')); ?>" placeholder="Buscar por Lead ID (#22733), telefone, texto..." style="width: 100%; padding-left: 30px; height: 32px; font-size: 13px;">
                        </div>

                        <select name="cat" style="height: 32px;">
                            <option value="">Todas as Categorias</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo esc_attr($cat); ?>" <?php selected($categoria, $cat); ?>><?php echo esc_html($cat); ?></option>
                            <?php endforeach; ?>
                        </select>

                        <select name="lvl" style="height: 32px;">
                            <option value="">Todos os Níveis</option>
                            <option value="info" <?php selected($nivel, 'info'); ?>>Info</option>
                            <option value="warning" <?php selected($nivel, 'warning'); ?>>Aviso (Warning)</option>
                            <option value="error" <?php selected($nivel, 'error'); ?>>Erro (Error)</option>
                            <option value="debug" <?php selected($nivel, 'debug'); ?>>Debug</option>
                        </select>

                        <button type="submit" class="button button-primary" style="height: 32px; display: inline-flex; align-items: center; gap: 4px;">
                            <span class="dashicons dashicons-filter" style="font-size: 15px; width: 15px; height: 15px;"></span> Filtrar Logs
                        </button>
                        <?php if (!empty($categoria) || !empty($nivel) || !empty($search) || !empty($lead_id)): ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-logs')); ?>" class="button" style="height: 32px; line-height: 30px;">Limpar</a>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($lead_id)): ?>
                        <div style="margin-top: 8px; font-size: 12px; color: #1d4ed8; display: flex; align-items: center; gap: 6px;">
                            <span class="dashicons dashicons-info" style="font-size: 16px; width: 16px; height: 16px;"></span>
                            <span>Exibindo histórico de eventos do <strong>Lead #<?php echo esc_html($lead_id); ?></strong>.</span>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-leads&s=' . $lead_id)); ?>" target="_blank" class="button button-small" style="font-size: 11px; padding: 0 6px; height: 22px; line-height: 20px;">Ver Lead no Painel &rarr;</a>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-logs')); ?>" style="color: #dc2626; text-decoration: none; margin-left: 6px;">&times; Remover filtro</a>
                        </div>
                    <?php endif; ?>
                </form>
            </div>

            <!-- TABELA DE LOGS -->
            <div class="li-card" style="padding: 0; overflow-x: auto;">
                <table class="wp-list-table widefat fixed striped li-table">
                    <thead>
                        <tr>
                            <th style="width: 70px;">ID</th>
                            <th style="width: 140px;">Data/Hora</th>
                            <th style="width: 100px;">Nível</th>
                            <th style="width: 130px;">Categoria</th>
                            <th>Mensagem</th>
                            <th style="width: 180px;">Contexto / Detalhes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 40px; color: #64748b;">
                                    Nenhum registro de log encontrado.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td>#<?php echo esc_html($log->id); ?></td>
                                    <td style="font-size: 12px; color: #475569;">
                                        <?php echo esc_html(date_i18n('d/m/Y H:i:s', strtotime($log->created_at))); ?>
                                    </td>
                                    <td>
                                        <?php
                                        $badge = 'li-status-cinza';
                                        if ($log->nivel === 'info') $badge = 'li-status-pendente';
                                        if ($log->nivel === 'warning') $badge = 'li-status-aviso';
                                        if ($log->nivel === 'error') $badge = 'li-status-desqualificado';
                                        if ($log->nivel === 'debug') $badge = 'li-status-debug';
                                        ?>
                                        <span class="li-badge <?php echo esc_attr($badge); ?>"><?php echo esc_html(strtoupper($log->nivel)); ?></span>
                                    </td>
                                    <td><strong><?php echo esc_html($log->categoria); ?></strong></td>
                                    <td>
                                        <?php
                                        $msg_raw = $log->mensagem;
                                        $msg_html = esc_html($msg_raw);
                                        $msg_html = preg_replace_callback('/#(\d{2,})/', function($m) {
                                            $lid = $m[1];
                                            $url = esc_url(admin_url('admin.php?page=lead-intelligence-logs&lead_id=' . $lid));
                                            $lead_view_url = esc_url(admin_url('admin.php?page=lead-intelligence-leads&s=' . $lid));
                                            return '<span style="display:inline-flex; align-items:center; gap:3px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:4px; padding:1px 5px; font-weight:700; font-size:11px;" title="Lead #' . $lid . '">'
                                                 . '<a href="' . $url . '" style="color:#1d4ed8; text-decoration:none;" title="Ver todos os logs do Lead #' . $lid . '">#' . $lid . '</a>'
                                                 . '<a href="' . $lead_view_url . '" target="_blank" style="color:#94a3b8; text-decoration:none; margin-left:2px; font-size:10px;" title="Abrir Lead #' . $lid . ' na lista de Leads">↗</a>'
                                                 . '</span>';
                                        }, $msg_html);
                                        echo $msg_html;
                                        ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($log->contexto)): ?>
                                            <textarea id="li-log-data-<?php echo esc_attr($log->id); ?>" style="display:none;"><?php echo esc_textarea($log->contexto); ?></textarea>
                                            <button type="button" 
                                                    class="button button-small li-btn-view-json" 
                                                    data-log-id="<?php echo esc_attr($log->id); ?>"
                                                    data-log-cat="<?php echo esc_attr($log->categoria); ?>"
                                                    data-log-lvl="<?php echo esc_attr($log->nivel); ?>"
                                                    data-log-time="<?php echo esc_attr(date_i18n('d/m/Y H:i:s', strtotime($log->created_at))); ?>"
                                                    data-log-msg="<?php echo esc_attr($log->mensagem); ?>"
                                                    style="display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 600; color: #1a73e8; border-color: #dadce0; background: #ffffff; cursor: pointer; padding: 3px 8px; border-radius: 4px;">
                                                🔍 Ver JSON
                                            </button>
                                        <?php else: ?>
                                            <span style="color: #94a3b8; font-size: 12px;">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- PAGINAÇÃO -->
            <?php if ($total_pages > 1): ?>
                <div class="li-pagination">
                    <span>Página <?php echo $page; ?> de <?php echo $total_pages; ?> (<?php echo $total; ?> registros)</span>
                    <div class="li-pagination-links">
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <?php 
                            $pag_args = ['paged' => $i, 'cat' => $categoria, 'lvl' => $nivel];
                            if (!empty($search)) $pag_args['s'] = $search;
                            if (!empty($lead_id)) $pag_args['lead_id'] = $lead_id;
                            ?>
                            <a href="<?php echo esc_url(add_query_arg($pag_args)); ?>" class="button <?php echo ($i === $page) ? 'button-primary' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- MODAL DE VISUALIZAÇÃO DO JSON (ESTILO GOOGLE ADS) -->
            <div id="li-json-modal" style="display: none; position: fixed; inset: 0; background: rgba(32, 33, 36, 0.6); z-index: 999999; align-items: center; justify-content: center; padding: 20px; backdrop-filter: blur(2px);">
                <div style="background: #ffffff; border-radius: 8px; width: 100%; max-width: 600px; max-height: 90vh; display: flex; flex-direction: column; box-shadow: 0 8px 28px rgba(0, 0, 0, 0.28); overflow: hidden; border: 1px solid #dadce0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                    
                    <!-- Header do Modal -->
                    <div style="padding: 20px 24px 14px 24px; display: flex; justify-content: space-between; align-items: flex-start; gap: 16px;">
                        <div>
                            <div style="font-size: 14px; color: #5f6368; line-height: 1.5; font-weight: 400;">
                                Compare esses dados com aqueles que você recebeu no sistema de gerenciamento de dados
                            </div>
                            <div id="li-modal-meta-info" style="font-size: 12px; color: #80868b; margin-top: 6px; display: flex; align-items: center; gap: 8px;"></div>
                        </div>
                        <button type="button" id="li-modal-close" style="background: transparent; border: none; font-size: 22px; line-height: 1; cursor: pointer; color: #5f6368; padding: 2px 6px; border-radius: 4px; margin-top: -2px;" title="Fechar">&times;</button>
                    </div>

                    <!-- Mensagem do Log (se houver) -->
                    <div id="li-modal-msg-wrapper" style="padding: 0 24px 12px 24px; display: none;">
                        <div style="font-size: 12px; color: #3c4043; background: #f8f9fa; border: 1px solid #e8eaed; border-radius: 4px; padding: 8px 12px;">
                            <strong style="color: #202124;">Mensagem:</strong> <span id="li-modal-msg-container"></span>
                        </div>
                    </div>

                    <!-- Corpo com JSON Formatado estilo Google Ads -->
                    <div style="padding: 0 24px 14px 24px; flex: 1; overflow: hidden; display: flex; flex-direction: column;">
                        <div style="border: 1px solid #dadce0; border-radius: 4px; background: #ffffff; padding: 14px 16px; overflow: auto; max-height: 440px; box-shadow: inset 0 1px 2px rgba(0,0,0,0.04);">
                            <pre id="li-modal-json-pre" style="margin: 0; color: #202124; font-family: 'Roboto Mono', Menlo, Consolas, Monaco, monospace; font-size: 13px; line-height: 1.6; white-space: pre; tab-size: 2; font-weight: 400;"></pre>
                        </div>
                    </div>

                    <!-- Rodapé com Link de Cópia estilo Google Ads -->
                    <div style="padding: 6px 24px 20px 24px; display: flex; justify-content: space-between; align-items: center;">
                        <button type="button" id="li-modal-copy-btn" style="background: none; border: none; padding: 0; color: #1a73e8; font-size: 14px; font-weight: 500; cursor: pointer; text-decoration: none; outline: none; font-family: inherit; transition: color 0.2s;">
                            Copiar para a área de transferência
                        </button>
                        <button type="button" style="background: #ffffff; border: 1px solid #dadce0; border-radius: 4px; color: #3c4043; font-size: 13px; font-weight: 500; padding: 6px 14px; cursor: pointer;" onclick="document.getElementById('li-json-modal').style.display='none';">
                            Fechar
                        </button>
                    </div>
                </div>
            </div>

            <!-- SCRIPT JS DO MODAL DE LOGS -->
            <script>
            jQuery(document).ready(function($) {
                var $modal = $('#li-json-modal');

                function decodeHtmlEntities(str) {
                    if (!str) return '';
                    var txt = document.createElement('textarea');
                    txt.innerHTML = str;
                    var val = txt.value;
                    // Se houver codificação dupla (&amp;quot; ou &quot;)
                    if (val.indexOf('&quot;') !== -1 || val.indexOf('&amp;') !== -1 || val.indexOf('&#039;') !== -1) {
                        txt.innerHTML = val;
                        val = txt.value;
                    }
                    // Substituição de garantia para aspas residuais
                    return val.replace(/&quot;/g, '"').replace(/&#039;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
                }

                function formatJsonPayload(raw) {
                    if (!raw) return '{}';
                    var decoded = decodeHtmlEntities(raw).trim();
                    
                    try {
                        var parsed = JSON.parse(decoded);
                        // Se o resultado do parse ainda for string (caso tenha sido serializado duas vezes)
                        if (typeof parsed === 'string') {
                            try {
                                parsed = JSON.parse(parsed);
                            } catch (e2) {}
                        }
                        return JSON.stringify(parsed, null, 2);
                    } catch (err) {
                        // Tenta uma limpeza mais agressiva de quebras/aspas caso venha de log de texto
                        try {
                            var clean = decoded.replace(/\\"/g, '"');
                            var parsed3 = JSON.parse(clean);
                            return JSON.stringify(parsed3, null, 2);
                        } catch (err2) {
                            return decoded;
                        }
                    }
                }

                $(document).on('click', '.li-btn-view-json', function(e) {
                    e.preventDefault();
                    var logId   = $(this).data('log-id');
                    var logCat  = $(this).data('log-cat');
                    var logLvl  = $(this).data('log-lvl');
                    var logTime = $(this).data('log-time');
                    var logMsg  = $(this).data('log-msg');
                    var rawVal  = $('#li-log-data-' + logId).val();

                    $('#li-modal-meta-info').html('Log #' + logId + ' &bull; ' + logCat + ' &bull; ' + logTime);

                    if (logMsg && logMsg.trim().length > 0) {
                        $('#li-modal-msg-container').text(logMsg);
                        $('#li-modal-msg-wrapper').show();
                    } else {
                        $('#li-modal-msg-wrapper').hide();
                    }

                    var formatted = formatJsonPayload(rawVal);
                    $('#li-modal-json-pre').text(formatted);

                    $modal.css('display', 'flex');
                });

                $('#li-modal-close').on('click', function() {
                    $modal.hide();
                });

                $modal.on('click', function(e) {
                    if ($(e.target).is($modal)) {
                        $modal.hide();
                    }
                });

                $(document).on('keydown', function(e) {
                    if (e.key === 'Escape' && $modal.is(':visible')) {
                        $modal.hide();
                    }
                });

                $('#li-modal-copy-btn').on('click', function() {
                    var textToCopy = $('#li-modal-json-pre').text();
                    var $btn = $(this);
                    navigator.clipboard.writeText(textToCopy).then(function() {
                        var oldText = $btn.text();
                        $btn.text('✔ Copiado para a área de transferência!').css('color', '#137333');
                        setTimeout(function() {
                            $btn.text('Copiar para a área de transferência').css('color', '#1a73e8');
                        }, 2200);
                    }).catch(function() {
                        var textarea = document.createElement('textarea');
                        textarea.value = textToCopy;
                        document.body.appendChild(textarea);
                        textarea.select();
                        document.execCommand('copy');
                        document.body.removeChild(textarea);
                        $btn.text('✔ Copiado para a área de transferência!').css('color', '#137333');
                        setTimeout(function() {
                            $btn.text('Copiar para a área de transferência').css('color', '#1a73e8');
                        }, 2200);
                    });
                });
            });
            </script>
        </div>
        <?php
    }

        <script>
        function liCopyDiagnostic(btn) {
            var oldHtml = btn.innerHTML;
            btn.innerHTML = '<span class="dashicons dashicons-update" style="animation: rotation 2s infinite linear; font-size:17px; width:17px; height:17px;"></span> Coletando dados da Caixa-Preta...';
            btn.disabled = true;

            jQuery.post(ajaxurl, {
                action: 'li_get_diagnostic_report',
                nonce: '<?php echo wp_create_nonce("li_diagnostic_nonce"); ?>'
            }, function(res) {
                btn.disabled = false;
                if (res && res.success && res.data && res.data.text) {
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(res.data.text).then(function() {
                            btn.innerHTML = '✅ Copiado com Sucesso! Cole no Chat com a IA (Ctrl+V)';
                            btn.style.background = '#059669';
                            btn.style.borderColor = '#047857';
                            setTimeout(function() {
                                btn.innerHTML = oldHtml;
                                btn.style.background = '#4f46e5';
                                btn.style.borderColor = '#4338ca';
                            }, 4500);
                        }).catch(function() {
                            prompt("Copie o texto de diagnóstico abaixo:", res.data.text);
                            btn.innerHTML = oldHtml;
                        });
                    } else {
                        prompt("Copie o texto de diagnóstico abaixo:", res.data.text);
                        btn.innerHTML = oldHtml;
                    }
                } else {
                    alert("Erro ao obter diagnóstico: " + ((res && res.data) ? res.data : 'Falha na resposta do servidor'));
                    btn.innerHTML = oldHtml;
                }
            }).fail(function() {
                btn.disabled = false;
                alert("Erro na requisição AJAX ao servidor.");
                btn.innerHTML = oldHtml;
            });
        }
        </script>
        <style>
        @keyframes rotation { from { transform: rotate(0deg); } to { transform: rotate(359deg); } }
        </style>
        
    }
}