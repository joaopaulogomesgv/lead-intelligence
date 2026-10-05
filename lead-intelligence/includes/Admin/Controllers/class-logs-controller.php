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
        $page       = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $per_page   = 50;
        $offset     = ($page - 1) * $per_page;

        $where = ['1=1'];
        $params = [];

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
                <div>
                    <form method="post" action="" onsubmit="return confirm('Deseja realmente limpar todos os logs?');">
                        <?php wp_nonce_field('li_clear_logs_verify', 'li_nonce'); ?>
                        <button type="submit" name="li_clear_logs" class="button button-secondary">Limpar Todos os Logs</button>
                    </form>
                </div>
            </div>

            <!-- FILTRO DE LOGS -->
            <div class="li-card li-filter-bar">
                <form method="get" action="">
                    <input type="hidden" name="page" value="lead-intelligence-logs">
                    <div class="li-filter-row">
                        <select name="cat">
                            <option value="">Todas as Categorias</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo esc_attr($cat); ?>" <?php selected($categoria, $cat); ?>><?php echo esc_html($cat); ?></option>
                            <?php endforeach; ?>
                        </select>

                        <select name="lvl">
                            <option value="">Todos os Níveis</option>
                            <option value="info" <?php selected($nivel, 'info'); ?>>Info</option>
                            <option value="warning" <?php selected($nivel, 'warning'); ?>>Aviso (Warning)</option>
                            <option value="error" <?php selected($nivel, 'error'); ?>>Erro (Error)</option>
                            <option value="debug" <?php selected($nivel, 'debug'); ?>>Debug</option>
                        </select>

                        <button type="submit" class="button button-primary">Filtrar Logs</button>
                        <?php if (!empty($categoria) || !empty($nivel)): ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-logs')); ?>" class="button">Limpar</a>
                        <?php endif; ?>
                    </div>
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
                                    <td><?php echo esc_html($log->mensagem); ?></td>
                                    <td>
                                        <?php if (!empty($log->contexto)): ?>
                                            <details>
                                                <summary style="cursor: pointer; color: #0284c7; font-size: 12px;">Ver JSON</summary>
                                                <pre style="background: #0f172a; color: #f8fafc; padding: 8px; font-size: 11px; border-radius: 4px; overflow-x: auto; max-height: 150px;"><?php echo esc_html($log->contexto); ?></pre>
                                            </details>
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
                            <a href="<?php echo esc_url(add_query_arg(['paged' => $i, 'cat' => $categoria, 'lvl' => $nivel])); ?>" class="button <?php echo ($i === $page) ? 'button-primary' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
