<?php
namespace LeadIntelligence\Admin\Controllers;

use LeadIntelligence\Database\LeadRepository;
use LeadIntelligence\PhoneNormalizer;
use LeadIntelligence\WhatsApp\WabaClient;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Controller da listagem de Leads capturados
 */
class LeadsController {

    /**
     * Endpoint AJAX para sincronização individual de anúncio da Meta para um lead
     */
    public static function ajax_sync_lead_ad() {
        check_ajax_referer('li_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permissão negada.']);
        }

        $lead_id = isset($_POST['lead_id']) ? (int) $_POST['lead_id'] : 0;
        if ($lead_id <= 0) {
            wp_send_json_error(['message' => 'ID de lead inválido.']);
        }

        $result = WabaClient::sync_lead_ad_data($lead_id);
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    public static function render() {
        $current_page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : 'lead-intelligence-leads';
        $is_qualificacoes = ($current_page === 'lead-intelligence-qualificacoes');

        $sync_message  = null;
        $sync_success  = true;
        $unify_message = null;
        $action_message = null;
        $action_type    = 'success';

        // 1. Exclusão individual de lead
        if (isset($_POST['li_delete_single_lead'])) {
            check_admin_referer('li_leads_bulk_verify', 'li_nonce');
            $lead_id = (int) $_POST['li_delete_single_lead'];
            if ($lead_id > 0) {
                $deleted = LeadRepository::delete_leads_by_ids([$lead_id]);
                if ($deleted > 0) {
                    $action_message = "Lead #{$lead_id} foi excluído com sucesso.";
                    $action_type = 'success';
                }
            }
        }

        // 2. Exclusão em massa de leads selecionados (checkbox)
        if (isset($_POST['li_bulk_action']) && $_POST['li_bulk_action'] === 'delete') {
            check_admin_referer('li_leads_bulk_verify', 'li_nonce');
            $selected = isset($_POST['selected_leads']) && is_array($_POST['selected_leads']) ? $_POST['selected_leads'] : [];
            if (!empty($selected)) {
                $deleted_count = LeadRepository::delete_leads_by_ids($selected);
                $action_message = "{$deleted_count} lead(s) selecionado(s) foram excluídos com sucesso.";
                $action_type = 'success';
            } else {
                $action_message = "Nenhum lead foi selecionado para exclusão.";
                $action_type = 'warning';
            }
        }

        // 3. Exclusão de todos os leads correspondentes ao filtro atual
        if (isset($_POST['li_delete_all_filtered'])) {
            check_admin_referer('li_leads_bulk_verify', 'li_nonce');
            $filter_search  = sanitize_text_field(wp_unslash($_POST['filter_search'] ?? ''));
            $filter_status  = sanitize_text_field(wp_unslash($_POST['filter_status'] ?? ''));
            $filter_channel = sanitize_key(wp_unslash($_POST['filter_channel'] ?? ''));

            $deleted_count = LeadRepository::delete_all_by_filters([
                'search'  => $filter_search,
                'status'  => $filter_status,
                'channel' => $filter_channel,
            ]);
            $action_message = "{$deleted_count} lead(s) do filtro atual foram excluídos com sucesso.";
            $action_type = 'success';
        }

        // 4. Reparo de nomes ausentes a partir das planilhas do Elementor
        if (isset($_POST['li_repair_missing_names'])) {
            check_admin_referer('li_leads_bulk_verify', 'li_nonce');
            $repair_res = LeadRepository::repair_missing_names_from_elementor_files();
            if ($repair_res['repaired'] > 0) {
                $action_message = "Sucesso! {$repair_res['repaired']} lead(s) tiveram seus nomes recuperados e preenchidos com base nas planilhas do Elementor.";
                $action_type = 'success';
            } elseif ($repair_res['files_scanned'] === 0) {
                $action_message = "Nenhum arquivo de submissão do Elementor foi localizado automaticamente na pasta. Utilize a aba 'Cruzamento Elementor' em Importar & Qualificar.";
                $action_type = 'warning';
            } else {
                $action_message = "Varredura concluída nas planilhas do Elementor ({$repair_res['files_scanned']} arquivo(s)). Nenhum nome adicional precisou ser alterado.";
                $action_type = 'info';
            }
        }

        if (isset($_POST['li_sync_all_ads'])) {
            check_admin_referer('li_sync_all_ads_verify', 'li_nonce');
            $sync_res = WabaClient::sync_all_pending_leads();
            $sync_message = $sync_res['message'];
            $sync_success = ($sync_res['updated'] > 0 || $sync_res['total'] === 0);
        }

        if (isset($_POST['li_unify_all_leads'])) {
            check_admin_referer('li_unify_all_leads_verify', 'li_nonce');
            $merged = \LeadIntelligence\Database\DbSchema::unify_and_enrich_leads_by_phone();
            $unify_message = "Unificação concluída com sucesso! {$merged} registros foram mesclados e enriquecidos com as UTMs dos formulários.";
        }

        $search    = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $status    = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : ($is_qualificacoes ? 'qualificado' : '');
        $channel   = isset($_GET['canal']) ? sanitize_key(wp_unslash($_GET['canal'])) : '';
        $page      = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $per_page  = 20;

        $counts = LeadRepository::get_status_counts($channel);

        $leads_data = LeadRepository::get_leads([
            'search'   => $search,
            'status'   => $status,
            'channel'  => $channel,
            'page'     => $page,
            'per_page' => $per_page,
            'orderby'  => 'id',
            'order'    => 'DESC',
        ]);

        $leads = $leads_data['items'];
        $total_pages = $leads_data['pages'];
        $total_leads = $leads_data['total'];

        $taxa_qualificacao = $counts['total'] > 0 
            ? round(($counts['qualificado'] / $counts['total']) * 100, 1) 
            : 0;

        $current_url = admin_url('admin.php?page=' . $current_page);
        $page_title = $is_qualificacoes ? 'Leads Qualificados & Matrículas' : 'Leads Capturados';
        $page_sub   = $is_qualificacoes 
            ? 'Listagem consolidada de leads confirmados via cruzamento de planilhas e WhatsApp.' 
            : 'Monitoramento em tempo real dos leads capturados via formulários Elementor Pro e planilhas.';
        ?>
        <div class="wrap li-wrap">
            <div class="li-header" style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:15px;">
                <div>
                    <h2>Lead Intelligence &bull; <?php echo esc_html($page_title); ?></h2>
                    <p class="li-subtitle"><?php echo esc_html($page_sub); ?></p>
                </div>
                <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                    <form method="post" action="" style="display:inline;">
                        <?php wp_nonce_field('li_unify_all_leads_verify', 'li_nonce'); ?>
                        <button type="submit" name="li_unify_all_leads" class="button button-primary li-btn-faveni" style="display:inline-flex; align-items:center; gap:6px;" title="Cruza registros da Planilha com o Elementor pelo telefone e unifica UTMs e tempo de fechamento">
                            <span class="dashicons dashicons-randomize" style="font-size:16px; width:16px; height:16px; line-height:16px;"></span> Unificar Planilha & Formulários
                        </button>
                    </form>
                    <form method="post" action="" style="display:inline;">
                        <?php wp_nonce_field('li_sync_all_ads_verify', 'li_nonce'); ?>
                        <button type="submit" name="li_sync_all_ads" class="button button-secondary" style="display:inline-flex; align-items:center; gap:6px;">
                            <span class="dashicons dashicons-update" style="font-size:16px; width:16px; height:16px; line-height:16px;"></span> Sincronizar Meta (WhatsApp)
                        </button>
                    </form>
                </div>
            </div>

            <?php if ($action_message): ?>
                <div class="notice notice-<?php echo esc_attr($action_type); ?> is-dismissible" style="margin-bottom: 20px;">
                    <p><strong><?php echo $action_type === 'success' ? '✔' : '⚠️'; ?></strong> <?php echo esc_html($action_message); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($unify_message): ?>
                <div class="notice notice-success is-dismissible" style="margin-bottom: 20px;">
                    <p><strong>✔</strong> <?php echo esc_html($unify_message); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($sync_message): ?>
                <div class="notice <?php echo $sync_success ? 'notice-success' : 'notice-warning'; ?> is-dismissible" style="margin-bottom: 20px;">
                    <p><strong><?php echo $sync_success ? '✔' : '⚠️'; ?></strong> <?php echo esc_html($sync_message); ?></p>
                </div>
            <?php endif; ?>

            <!-- CARDS DE MÉTRICAS -->
            <div class="li-metric-grid">
                <div class="li-metric-card">
                    <span class="li-metric-label">Total de Leads</span>
                    <span class="li-metric-value"><?php echo number_format_i18n($counts['total']); ?></span>
                    <span class="li-metric-sub">Capturados no banco próprio</span>
                </div>
                <div class="li-metric-card li-card-success">
                    <span class="li-metric-label">Qualificados</span>
                    <span class="li-metric-value"><?php echo number_format_i18n($counts['qualificado']); ?></span>
                    <span class="li-metric-sub">Planilha / WhatsApp</span>
                </div>
                <div class="li-metric-card li-card-warning">
                    <span class="li-metric-label">Pendentes</span>
                    <span class="li-metric-value"><?php echo number_format_i18n($counts['pendente']); ?></span>
                    <span class="li-metric-sub">Aguardando cruzamento</span>
                </div>
                <div class="li-metric-card li-card-danger">
                    <span class="li-metric-label">Não Qualificados</span>
                    <span class="li-metric-value"><?php echo number_format_i18n($counts['nao_qualificado']); ?></span>
                    <span class="li-metric-sub">Desqualificados</span>
                </div>
                <div class="li-metric-card li-card-info">
                    <span class="li-metric-label">Taxa de Qualificação</span>
                    <span class="li-metric-value"><?php echo $taxa_qualificacao; ?>%</span>
                    <span class="li-metric-sub">Média do filtro atual</span>
                </div>
            </div>

            <!-- BARRA DE BUSCA E FILTROS -->
            <div class="li-card li-filter-bar">
                <form method="get" action="">
                    <input type="hidden" name="page" value="<?php echo esc_attr($current_page); ?>">

                    <div class="li-filter-row">
                        <div class="li-search-box">
                            <input type="text" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Buscar por nome, e-mail, telefone ou curso...">
                        </div>

                        <select name="canal">
                            <option value="">Todos os Canais</option>
                            <option value="google_ads" <?php selected($channel, 'google_ads'); ?>>🟢 Google Ads</option>
                            <option value="meta_ads" <?php selected($channel, 'meta_ads'); ?>>🔵 Meta Ads</option>
                            <option value="whatsapp" <?php selected($channel, 'whatsapp'); ?>>💬 WhatsApp Direto</option>
                            <option value="organico" <?php selected($channel, 'organico'); ?>>⚪ Direto / Site</option>
                        </select>

                        <select name="status">
                            <option value="">Todos os Status</option>
                            <option value="pendente" <?php selected($status, 'pendente'); ?>>Pendentes</option>
                            <option value="qualificado" <?php selected($status, 'qualificado'); ?>>Qualificados</option>
                            <option value="nao_qualificado" <?php selected($status, 'nao_qualificado'); ?>>Não Qualificados</option>
                            <option value="sem_correspondencia" <?php selected($status, 'sem_correspondencia'); ?>>Sem Correspondência</option>
                        </select>

                        <button type="submit" class="button button-primary">Filtrar</button>
                        <?php if (!empty($search) || !empty($status) || !empty($channel)): ?>
                            <a href="<?php echo esc_url($current_url); ?>" class="button">Limpar Filtros</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- TABELA DE LEADS COM FORMULÁRIO DE AÇÕES EM MASSA -->
            <form method="post" action="" id="liLeadsBulkForm">
                <?php wp_nonce_field('li_leads_bulk_verify', 'li_nonce'); ?>

                <div class="li-card" style="padding: 0; overflow-x: auto;">
                    <!-- BARRA DE AÇÕES EM MASSA -->
                    <div style="padding: 12px 20px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                            <span style="font-size: 13px; font-weight: 600; color: #475569;">Ações em Massa:</span>
                            <button type="submit" name="li_bulk_action" value="delete" id="li_bulk_delete_btn" class="button" disabled style="color: #b91c1c; border-color: #fca5a5; background: #fff5f5; font-weight: 600; display: inline-flex; align-items: center; gap: 6px;" onclick="return confirm('ATENÇÃO: Deseja realmente excluir permanentemente os leads selecionados?');">
                                <span class="dashicons dashicons-trash" style="font-size: 15px; width: 15px; height: 15px; line-height: 15px; color: #dc2626;"></span>
                                Excluir Selecionados (<span id="li_selected_count">0</span>)
                            </button>

                            <?php if ($total_leads > 0): ?>
                                <button type="button" class="button button-link-delete" onclick="openDeleteFilteredModal()" style="color: #dc2626; font-size: 12px; margin-left: 8px;">
                                    Excluir todos os <?php echo number_format_i18n($total_leads); ?> leads deste filtro...
                                </button>
                            <?php endif; ?>

                            <button type="submit" name="li_repair_missing_names" value="1" class="button" style="color: #0369a1; border-color: #bae6fd; background: #f0f9ff; font-weight: 500; display: inline-flex; align-items: center; gap: 6px; margin-left: 12px;" title="Varre as planilhas do Elementor e preenche o nome real dos leads cadastrados como 'Sem nome'">
                                <span class="dashicons dashicons-admin-users" style="font-size: 15px; width: 15px; height: 15px; line-height: 15px; color: #0284c7;"></span>
                                Reparar Nomes das Planilhas
                            </button>
                        </div>

                        <div style="font-size: 12px; color: #64748b;">
                            Total: <strong><?php echo number_format_i18n($total_leads); ?></strong> leads encontrados
                        </div>
                    </div>

                    <table class="wp-list-table widefat fixed striped li-table">
                        <thead>
                            <tr>
                                <th style="width: 40px; text-align: center;">
                                    <input type="checkbox" id="li_select_all" title="Selecionar Todos na Página">
                                </th>
                                <th style="width: 60px;">ID</th>
                                <th style="width: 130px;">Data</th>
                                <th>Lead / Contato</th>
                                <th>Curso / Interesse</th>
                                <th style="width: 230px;">Canal / Origem</th>
                                <th style="width: 130px;">Status</th>
                                <th style="width: 130px;">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($leads)): ?>
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 40px 20px; color: #64748b;">
                                    <span class="dashicons dashicons-id-alt" style="font-size: 36px; width: 36px; height: 36px; color: #94a3b8; margin-bottom: 10px;"></span>
                                    <p style="font-size: 15px; margin: 0;">Nenhum lead encontrado com os filtros selecionados.</p>
                                    <p style="font-size: 13px; margin: 5px 0 0;">Verifique os filtros de canal ou importe novas planilhas.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($leads as $lead): ?>
                                <?php
                                $lead_channel = LeadRepository::get_channel($lead);
                                $chan_info    = LeadRepository::get_channel_info($lead_channel);
                                ?>
                                <tr id="li-row-<?php echo esc_attr($lead->id); ?>">
                                    <td style="text-align: center; vertical-align: middle;">
                                        <input type="checkbox" name="selected_leads[]" value="<?php echo esc_attr($lead->id); ?>" class="li-lead-check" style="margin: 0;">
                                    </td>
                                    <td><strong>#<?php echo esc_html($lead->id); ?></strong></td>
                                    <td>
                                        <div style="font-size: 12px; font-weight: 500;">
                                            <?php echo esc_html(date_i18n('d/m/Y', strtotime($lead->data_cadastro))); ?>
                                        </div>
                                        <div style="font-size: 11px; color: #64748b;">
                                            <?php echo esc_html(date_i18n('H:i', strtotime($lead->data_cadastro))); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-weight: 600; color: #1e293b;">
                                            <?php echo !empty($lead->nome) ? esc_html($lead->nome) : '<span style="color:#94a3b8">Sem nome</span>'; ?>
                                        </div>
                                        <div style="font-size: 12px; color: #0284c7; margin-top: 2px;">
                                            <?php if (!empty($lead->telefone)): ?>
                                                <span>📞 <?php echo esc_html(PhoneNormalizer::format_display($lead->telefone)); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($lead->email)): ?>
                                            <div style="font-size: 12px; color: #64748b;">
                                                ✉️ <?php echo esc_html($lead->email); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($lead->polo)): ?>
                                            <div style="margin-top: 3px;">
                                                <span class="li-badge" style="background: #eff6ff; color: #1d4ed8; font-size: 11px; border: 1px solid #bfdbfe;">
                                                    🏢 <?php echo esc_html($lead->polo); ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($lead->tipo_curso)): ?>
                                            <div class="li-tag li-tag-blue"><?php echo esc_html($lead->tipo_curso); ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($lead->area_interesse)): ?>
                                            <div style="font-size: 12px; color: #334155; margin-top: 4px;">
                                                <strong>Área:</strong> <?php echo esc_html($lead->area_interesse); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <!-- BADGE DE CANAL CLARO E CERTEIRO -->
                                        <div style="margin-bottom: 4px;">
                                            <span class="li-badge <?php echo esc_attr($chan_info['badge_class']); ?>" style="display:inline-flex; align-items:center; gap:5px; font-weight:600; font-size:11px; padding:3px 8px;">
                                                <span><?php echo esc_html($chan_info['dot']); ?></span>
                                                <span><?php echo esc_html($chan_info['label']); ?></span>
                                            </span>
                                        </div>
                                        <div style="font-size: 12px; color: #334155; font-weight: 500;">
                                            <?php echo esc_html(!empty($lead->formulario_nome) ? $lead->formulario_nome : 'Formulário'); ?>
                                        </div>
                                        <?php if (!empty($lead->utm_campaign)): ?>
                                            <div style="font-size: 11px; color: #64748b; margin-top: 2px;">
                                                <span title="Campanha">🎯 <?php echo esc_html($lead->utm_campaign); ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <div style="margin-top: 4px; display: flex; gap: 4px; flex-wrap: wrap;">
                                            <?php if (!empty($lead->gclid)): ?>
                                                <span class="li-badge-mini" style="background:#e0f2fe; color:#0369a1; border-color:#bae6fd;" title="Google Click ID">gclid</span>
                                            <?php endif; ?>
                                            <?php if (!empty($lead->fbclid)): ?>
                                                <span class="li-badge-mini" style="background:#e7f3ff; color:#0866ff; border-color:#d0e7ff;" title="Meta Click ID">fbclid</span>
                                            <?php endif; ?>
                                            <?php if (!empty($lead->ad_id)): ?>
                                                <span class="li-badge-mini" title="Meta Ad ID">Ad #<?php echo esc_html(substr($lead->ad_id, -6)); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php
                                        $badge_class = 'li-status-pendente';
                                        $label = 'Pendente';
                                        if ($lead->qualificacao_status === 'qualificado') {
                                            $badge_class = 'li-status-qualificado';
                                            $label = 'Qualificado';
                                        } elseif ($lead->qualificacao_status === 'nao_qualificado') {
                                            $badge_class = 'li-status-desqualificado';
                                            $label = 'Desqualificado';
                                        } elseif ($lead->qualificacao_status === 'sem_correspondencia') {
                                            $badge_class = 'li-status-cinza';
                                            $label = 'Sem Match';
                                        }
                                        ?>
                                        <span class="li-badge <?php echo esc_attr($badge_class); ?>">
                                            <?php echo esc_html($label); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="display: inline-flex; align-items: center; gap: 5px;">
                                            <button type="button" class="button button-small li-open-modal-btn" data-lead="<?php echo esc_attr(wp_json_encode($lead)); ?>">
                                                Ver Detalhes
                                            </button>
                                            <button type="submit" name="li_delete_single_lead" value="<?php echo esc_attr($lead->id); ?>" class="button button-small" style="color: #b91c1c; border-color: #fecaca; background: #fff5f5; padding: 0 6px; height: 26px; line-height: 24px;" title="Excluir Permanentemente Lead #<?php echo esc_attr($lead->id); ?>" onclick="return confirm('ATENÇÃO: Deseja realmente excluir permanentemente o lead #<?php echo esc_attr($lead->id); ?>? Esta ação não pode ser desfeita.');">
                                                <span class="dashicons dashicons-trash" style="font-size: 14px; width: 14px; height: 14px; line-height: 24px; vertical-align: middle;"></span>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            </form>

            <!-- PAGINAÇÃO -->
            <?php if ($total_pages > 1): ?>
                <div class="li-pagination">
                    <span>Página <?php echo $page; ?> de <?php echo $total_pages; ?> (<?php echo $total_leads; ?> leads no total)</span>
                    <div class="li-pagination-links">
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <?php
                            $link = add_query_arg([
                                'paged'   => $i,
                                's'       => $search,
                                'status'  => $status,
                                'channel' => $channel,
                            ], $current_url);
                            ?>
                            <a href="<?php echo esc_url($link); ?>" class="button <?php echo ($i === $page) ? 'button-primary' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- MODAL DE EXCLUSÃO EM MASSA POR FILTRO -->
            <div id="liDeleteFilteredModal" class="li-modal-backdrop" style="display:none;">
                <div class="li-modal-container" style="max-width: 520px;">
                    <div class="li-modal-header" style="background: #fff1f2; border-bottom: 1px solid #fecdd3;">
                        <h3 style="color: #9f1239; display: flex; align-items: center; gap: 8px;">
                            <span class="dashicons dashicons-warning" style="font-size: 22px;"></span>
                            Exclusão Permanente por Filtro
                        </h3>
                        <button type="button" class="li-modal-close" onclick="closeDeleteFilteredModal()">&times;</button>
                    </div>
                    <form method="post" action="" id="liFormDeleteFiltered">
                        <?php wp_nonce_field('li_leads_bulk_verify', 'li_nonce'); ?>
                        <input type="hidden" name="filter_search" value="<?php echo esc_attr($search); ?>">
                        <input type="hidden" name="filter_status" value="<?php echo esc_attr($status); ?>">
                        <input type="hidden" name="filter_channel" value="<?php echo esc_attr($channel); ?>">

                        <div class="li-modal-body" style="padding: 20px;">
                            <div style="background: #fff5f5; border: 1px solid #fecaca; border-radius: 6px; padding: 12px; margin-bottom: 16px;">
                                <p style="margin: 0 0 8px; font-weight: 700; color: #991b1b; font-size: 14px;">
                                    ⚠️ AÇÃO IRREVERSÍVEL!
                                </p>
                                <p style="margin: 0; font-size: 13px; color: #7f1d1d; line-height: 1.5;">
                                    Você está prestes a excluir permanentemente <strong><?php echo number_format_i18n($total_leads); ?></strong> lead(s) e todos os seus históricos associados.
                                </p>
                            </div>

                            <div style="font-size: 13px; color: #334155; margin-bottom: 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px;">
                                <div style="font-weight: 600; margin-bottom: 6px; color: #0f172a;">Critérios do Filtro Atual:</div>
                                <div>• <strong>Busca:</strong> <?php echo !empty($search) ? esc_html($search) : '<em>(Sem termo de busca)</em>'; ?></div>
                                <div>• <strong>Status:</strong> <?php echo !empty($status) ? esc_html(ucfirst(str_replace('_', ' ', $status))) : '<em>Todos os status</em>'; ?></div>
                                <div>• <strong>Canal:</strong> <?php echo !empty($channel) ? esc_html($channel) : '<em>Todos os canais</em>'; ?></div>
                            </div>

                            <div style="margin-bottom: 16px;">
                                <label style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 6px;">
                                    Para confirmar a exclusão, digite <strong>EXCLUIR</strong> abaixo:
                                </label>
                                <input type="text" id="liConfirmDeleteWord" class="regular-text" style="width: 100%; border: 2px solid #cbd5e1; border-radius: 4px; padding: 8px;" placeholder="Digite EXCLUIR para liberar o botão" autocomplete="off">
                            </div>

                            <div style="display: flex; justify-content: flex-end; gap: 8px;">
                                <button type="button" class="button" onclick="closeDeleteFilteredModal()">Cancelar</button>
                                <button type="submit" name="li_delete_all_filtered" value="1" id="liBtnConfirmDeleteFiltered" class="button" disabled style="background: #dc2626; color: #fff; border-color: #b91c1c; font-weight: 700; display: inline-flex; align-items: center; gap: 6px;">
                                    <span class="dashicons dashicons-trash" style="font-size: 15px; width: 15px; height: 15px; line-height: 15px;"></span>
                                    Confirmar Exclusão de <?php echo number_format_i18n($total_leads); ?> Leads
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- MODAL DE DETALHES DO LEAD -->
            <div id="liLeadModal" class="li-modal-backdrop" style="display:none;">
                <div class="li-modal-container">
                    <div class="li-modal-header">
                        <h3 id="liModalTitle">Detalhes do Lead</h3>
                        <button type="button" class="li-modal-close" onclick="closeLiModal()">&times;</button>
                    </div>
                    <div class="li-modal-body" id="liModalContent">
                        <!-- Preenchido via JavaScript -->
                    </div>
                </div>
            </div>

            <script>
            window.liAdminAjax = {
                url: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>',
                nonce: '<?php echo esc_js(wp_create_nonce('li_ajax_nonce')); ?>'
            };

            function closeLiModal() {
                document.getElementById('liLeadModal').style.display = 'none';
            }

            document.querySelectorAll('.li-open-modal-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var lead = JSON.parse(this.getAttribute('data-lead'));
                    document.getElementById('liModalTitle').innerText = 'Lead #' + lead.id + ' • ' + (lead.nome || 'Sem Nome');

                    var html = '<div class="li-modal-sections">';
                    
                    // Seção 1: Contato e Cadastro
                    html += '<div class="li-modal-box"><h4>📋 Informações Cadastrais</h4>';
                    html += '<p><strong>Data de Cadastro:</strong> ' + lead.data_cadastro + '</p>';
                    html += '<p><strong>Telefone Original:</strong> ' + (lead.telefone || '-') + '</p>';
                    html += '<p><strong>Telefone Normalizado:</strong> ' + (lead.telefone_normalizado || '-') + '</p>';
                    html += '<p><strong>E-mail:</strong> ' + (lead.email || '-') + '</p>';
                    html += '<p><strong>Tipo de Curso:</strong> ' + (lead.tipo_curso || '-') + '</p>';
                    html += '<p><strong>Área de Interesse:</strong> ' + (lead.area_interesse || '-') + '</p>';
                    html += '<p><strong>Polo / Unidade:</strong> ' + (lead.polo ? '<span class="li-badge" style="background:#eff6ff; color:#1d4ed8; font-weight:600;">🏢 ' + lead.polo + '</span>' : '-') + '</p>';
                    html += '</div>';

                    // Seção 2: UTMs e Atribuição Meta Ads / Google Ads
                    var chanBadge = '⚪ Direto / Site';
                    var src = (lead.utm_source || '').toLowerCase();
                    var form = (lead.formulario_nome || '').toLowerCase();
                    if (lead.gclid || src === 'google' || form.indexOf('google') !== -1) {
                        chanBadge = '<span style="color:#1a73e8; font-weight:700;">🟢 Google Ads</span>';
                    } else if (lead.fbclid || lead.ad_id || ['meta','facebook','instagram','fb'].indexOf(src) !== -1 || form.indexOf('meta') !== -1 || form.indexOf('facebook') !== -1) {
                        chanBadge = '<span style="color:#0866ff; font-weight:700;">🔵 Meta Ads</span>';
                    } else if (form.indexOf('whatsapp') !== -1 || lead.whatsapp_status || lead.conversation_id) {
                        chanBadge = '<span style="color:#15803d; font-weight:700;">💬 WhatsApp Direto</span>';
                    }

                    html += '<div class="li-modal-box"><h4>🎯 Atribuição de Campanha & Ads</h4>';
                    html += '<p><strong>Canal de Origem:</strong> ' + chanBadge + '</p>';
                    html += '<p><strong>Campanha:</strong> <span id="liModalCampName" style="font-weight:600; color:#0f172a;">' + (lead.campaign_name || lead.utm_campaign || '-') + '</span></p>';
                    html += '<p><strong>Conjunto / AdSet:</strong> <span id="liModalAdSetName" style="font-weight:600; color:#0f172a;">' + (lead.adset_name || lead.utm_content || '-') + '</span></p>';
                    html += '<p><strong>Anúncio:</strong> <span id="liModalAdName" style="font-weight:600; color:#0f172a;">' + (lead.ad_name || lead.utm_term || '-') + '</span></p>';
                    html += '<hr style="margin: 8px 0; border: none; border-top: 1px dashed #e2e8f0;">';
                    html += '<p><strong>utm_source:</strong> <code>' + (lead.utm_source || '-') + '</code></p>';
                    html += '<p><strong>utm_medium:</strong> <code>' + (lead.utm_medium || '-') + '</code></p>';
                    html += '<p><strong>utm_campaign:</strong> <code id="liModalUtmCamp">' + (lead.utm_campaign || '-') + '</code></p>';
                    html += '<p><strong>utm_content:</strong> <code id="liModalUtmContent">' + (lead.utm_content || '-') + '</code></p>';
                    html += '<p><strong>utm_term:</strong> <code id="liModalUtmTerm">' + (lead.utm_term || '-') + '</code></p>';
                    html += '<p><strong>gclid (Google Ads):</strong> <code style="word-break:break-all;">' + (lead.gclid || '-') + '</code></p>';
                    html += '<p><strong>fbclid / CTWA:</strong> <code style="word-break:break-all;">' + (lead.fbclid || '-') + '</code></p>';
                    html += '<p><strong>Campaign ID / Gad:</strong> <code id="liModalCampId">' + (lead.campaign_id || '-') + '</code></p>';
                    html += '<p><strong>AdSet ID:</strong> <code id="liModalAdSetId">' + (lead.adset_id || '-') + '</code></p>';
                    html += '<p><strong>Ad ID:</strong> <code id="liModalAdId">' + (lead.ad_id || '-') + '</code></p>';

                    if (lead.ad_id) {
                        html += '<div style="margin-top: 14px; padding: 12px; background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 6px;">';
                        html += '<div style="font-size: 11px; font-weight: 600; text-transform: uppercase; color: #0369a1; margin-bottom: 6px;">Sincronização com Meta Marketing API</div>';
                        html += '<button type="button" class="button button-primary" id="liBtnSyncLeadAd" data-lead-id="' + lead.id + '" style="display:inline-flex; align-items:center; gap:6px;">';
                        html += '<span class="dashicons dashicons-update" style="font-size:16px; width:16px; height:16px; line-height:16px;"></span> Buscar Dados Oficiais na Meta';
                        html += '</button>';
                        html += '<div id="liSyncLeadResult" style="margin-top:8px; font-size:12px; display:none;"></div>';
                        html += '</div>';
                    }
                    html += '</div>';

                    // Seção 3: Qualificação e WhatsApp
                    html += '<div class="li-modal-box"><h4>⭐ Qualificação & WhatsApp</h4>';
                    html += '<p><strong>Status de Qualificação:</strong> ' + lead.qualificacao_status + '</p>';
                    html += '<p><strong>Data da Qualificação:</strong> ' + (lead.qualificacao_data || '-') + '</p>';
                    html += '<p><strong>Origem da Qualificação:</strong> ' + (lead.qualificacao_origem || '-') + '</p>';
                    html += '<p><strong>Score:</strong> ' + lead.score + '</p>';
                    html += '<p><strong>Status WhatsApp:</strong> ' + (lead.whatsapp_status || '-') + '</p>';
                    html += '<p><strong>Mensagens Recebidas:</strong> ' + lead.mensagens_recebidas + '</p>';
                    html += '</div>';

                    // Seção 4: Metadados Técnicos
                    html += '<div class="li-modal-box"><h4>🌐 Metadados Técnicos</h4>';
                    html += '<p><strong>Formulário:</strong> ' + (lead.formulario_nome || '-') + ' (' + (lead.formulario_id || '-') + ')</p>';
                    html += '<p><strong>Página de Origem:</strong> <a href="' + (lead.pagina_origem || '#') + '" target="_blank" style="word-break:break-all; font-size:12px;">' + (lead.pagina_origem || '-') + '</a></p>';
                    html += '<p><strong>Referrer Externo:</strong> <span style="word-break:break-all; font-size:12px; color:#475569;">' + (lead.referrer || '-') + '</span></p>';
                    html += '<p><strong>Endereço IP:</strong> ' + (lead.ip_address || '-') + '</p>';
                    html += '<p><strong>User Agent:</strong> <span style="font-size:11px; word-break:break-all;">' + (lead.user_agent || '-') + '</span></p>';
                    html += '</div>';

                    html += '</div>';

                    document.getElementById('liModalContent').innerHTML = html;
                    document.getElementById('liLeadModal').style.display = 'flex';

                    // Handler do botão de sincronização individual
                    var syncBtn = document.getElementById('liBtnSyncLeadAd');
                    if (syncBtn) {
                        syncBtn.addEventListener('click', function() {
                            var leadId = this.getAttribute('data-lead-id');
                            var resultDiv = document.getElementById('liSyncLeadResult');
                            var btn = this;
                            btn.disabled = true;
                            btn.innerHTML = '<span class="dashicons dashicons-update" style="animation:spin 1s infinite linear;"></span> Consultando Meta...';
                            resultDiv.style.display = 'block';
                            resultDiv.innerHTML = '<span style="color:#64748b;">Aguarde, comunicando com a Meta Marketing API...</span>';

                            var formData = new FormData();
                            formData.append('action', 'li_sync_lead_ad');
                            formData.append('lead_id', leadId);
                            formData.append('nonce', window.liAdminAjax.nonce);

                            fetch(window.liAdminAjax.url, {
                                method: 'POST',
                                body: formData
                            })
                            .then(function(r) { return r.json(); })
                            .then(function(res) {
                                btn.disabled = false;
                                btn.innerHTML = '<span class="dashicons dashicons-update"></span> Buscar Dados Oficiais na Meta';
                                if (res.success && res.data) {
                                    var d = res.data.data || res.data.meta || {};
                                    var l = res.data.lead || {};
                                    resultDiv.innerHTML = '<div style="background:#dcfce7; color:#15803d; padding:8px 10px; border-radius:4px; font-weight:500;">✔ ' + (res.data.message || 'Dados sincronizados com sucesso!') + '</div>';
                                    if (document.getElementById('liModalCampName')) document.getElementById('liModalCampName').innerText = l.campaign_name || d.campaign_name || '-';
                                    if (document.getElementById('liModalAdSetName')) document.getElementById('liModalAdSetName').innerText = l.adset_name || d.adset_name || '-';
                                    if (document.getElementById('liModalAdName')) document.getElementById('liModalAdName').innerText = l.ad_name || d.ad_name || '-';
                                    if (document.getElementById('liModalCampId')) document.getElementById('liModalCampId').innerText = l.campaign_id || d.campaign_id || '-';
                                    if (document.getElementById('liModalAdSetId')) document.getElementById('liModalAdSetId').innerText = l.adset_id || d.adset_id || '-';
                                    if (document.getElementById('liModalUtmCamp')) document.getElementById('liModalUtmCamp').innerText = l.utm_campaign || d.campaign_name || '-';
                                    if (document.getElementById('liModalUtmContent')) document.getElementById('liModalUtmContent').innerText = l.utm_content || d.adset_name || '-';
                                    if (document.getElementById('liModalUtmTerm')) document.getElementById('liModalUtmTerm').innerText = l.utm_term || d.ad_name || '-';
                                } else {
                                    var err = (res.data && res.data.message) ? res.data.message : 'Erro ao consultar a Meta.';
                                    resultDiv.innerHTML = '<div style="background:#fee2e2; color:#b91c1c; padding:8px 10px; border-radius:4px;">❌ ' + err + '<br><small style="color:#7f1d1d; display:block; margin-top:4px;">Dica: certifique-se de que o token possua a permissão <strong>ads_read</strong> e a Conta de Anúncios esteja associada ao System User no Meta Business Suite.</small></div>';
                                }
                            })
                            .catch(function(e) {
                                btn.disabled = false;
                                btn.innerHTML = '<span class="dashicons dashicons-update"></span> Buscar Dados Oficiais na Meta';
                                resultDiv.innerHTML = '<div style="background:#fee2e2; color:#b91c1c; padding:8px 10px; border-radius:4px;">❌ Falha na requisição: ' + e.message + '</div>';
                            });
                        });
                    }
                });
            });

            // Controle de Seleção em Massa de Leads
            var selectAllCheckbox = document.getElementById('li_select_all');
            var leadCheckboxes = document.querySelectorAll('.li-lead-check');
            var bulkDeleteBtn = document.getElementById('li_bulk_delete_btn');
            var selectedCountSpan = document.getElementById('li_selected_count');

            function updateBulkDeleteState() {
                var checked = document.querySelectorAll('.li-lead-check:checked');
                var count = checked.length;
                if (selectedCountSpan) {
                    selectedCountSpan.innerText = count;
                }
                if (bulkDeleteBtn) {
                    bulkDeleteBtn.disabled = (count === 0);
                    bulkDeleteBtn.style.opacity = (count > 0) ? '1' : '0.6';
                    bulkDeleteBtn.style.cursor = (count > 0) ? 'pointer' : 'not-allowed';
                }

                leadCheckboxes.forEach(function(cb) {
                    var row = cb.closest('tr');
                    if (row) {
                        row.style.backgroundColor = cb.checked ? '#fef2f2' : '';
                    }
                });

                if (selectAllCheckbox && leadCheckboxes.length > 0) {
                    selectAllCheckbox.checked = (count === leadCheckboxes.length);
                    selectAllCheckbox.indeterminate = (count > 0 && count < leadCheckboxes.length);
                }
            }

            if (selectAllCheckbox) {
                selectAllCheckbox.addEventListener('change', function() {
                    var isChecked = this.checked;
                    leadCheckboxes.forEach(function(cb) {
                        cb.checked = isChecked;
                    });
                    updateBulkDeleteState();
                });
            }

            leadCheckboxes.forEach(function(cb) {
                cb.addEventListener('change', updateBulkDeleteState);
            });

            // Modal de Exclusão por Filtro
            window.openDeleteFilteredModal = function() {
                var modal = document.getElementById('liDeleteFilteredModal');
                if (modal) {
                    modal.style.display = 'flex';
                    var input = document.getElementById('liConfirmDeleteWord');
                    if (input) {
                        input.value = '';
                        setTimeout(function() { input.focus(); }, 100);
                    }
                    var btn = document.getElementById('liBtnConfirmDeleteFiltered');
                    if (btn) btn.disabled = true;
                }
            };

            window.closeDeleteFilteredModal = function() {
                var modal = document.getElementById('liDeleteFilteredModal');
                if (modal) {
                    modal.style.display = 'none';
                }
            };

            var confirmWordInput = document.getElementById('liConfirmDeleteWord');
            if (confirmWordInput) {
                confirmWordInput.addEventListener('input', function() {
                    var btn = document.getElementById('liBtnConfirmDeleteFiltered');
                    if (btn) {
                        btn.disabled = (this.value.trim().toUpperCase() !== 'EXCLUIR');
                    }
                });
            }

            // Fecha ao clicar fora
            window.addEventListener('click', function(e) {
                var modal = document.getElementById('liLeadModal');
                if (e.target === modal) {
                    modal.style.display = 'none';
                }
                var filterModal = document.getElementById('liDeleteFilteredModal');
                if (e.target === filterModal) {
                    filterModal.style.display = 'none';
                }
            });
            </script>
        </div>
        <?php
    }
}
