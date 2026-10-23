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
        $current_page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : 'lead-intelligence';
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
            $filter_search    = sanitize_text_field(wp_unslash($_POST['filter_search'] ?? ''));
            $filter_status    = sanitize_text_field(wp_unslash($_POST['filter_status'] ?? ''));
            $filter_channel   = sanitize_key(wp_unslash($_POST['filter_channel'] ?? ''));
            $filter_date_from = sanitize_text_field(wp_unslash($_POST['filter_date_from'] ?? ''));
            $filter_date_to   = sanitize_text_field(wp_unslash($_POST['filter_date_to'] ?? ''));

            $deleted_count = LeadRepository::delete_all_by_filters([
                'search'    => $filter_search,
                'status'    => $filter_status,
                'channel'   => $filter_channel,
                'date_from' => $filter_date_from,
                'date_to'   => $filter_date_to,
            ]);
            $action_message = "{$deleted_count} lead(s) do filtro e período selecionados foram excluídos com sucesso.";
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
            $nonce_valid = isset($_POST['li_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['li_nonce'])), 'li_sync_all_ads_verify');
            if (!$nonce_valid && !current_user_can('manage_options')) {
                wp_die(__('Permissão negada ou sessão expirada.', 'lead-intelligence'));
            }
            $sync_res = WabaClient::sync_all_pending_leads();
            $sync_message = $sync_res['message'];
            $sync_success = ($sync_res['updated'] > 0 || $sync_res['total'] === 0);
        }

        if (isset($_POST['li_unify_all_leads'])) {
            $nonce_valid = isset($_POST['li_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['li_nonce'])), 'li_unify_all_leads_verify');
            if (!$nonce_valid && !current_user_can('manage_options')) {
                wp_die(__('Permissão negada ou sessão expirada.', 'lead-intelligence'));
            }
            $merged = \LeadIntelligence\Database\DbSchema::unify_and_enrich_leads_by_phone();
            \LeadIntelligence\Database\DbSchema::recalibrate_funnel_and_qualification();
            $unify_message = "Unificação concluída com sucesso! {$merged} registros foram mesclados e enriquecidos com as UTMs dos formulários.";
        }

        if (isset($_POST['li_sync_gsheets'])) {
            $nonce_valid = isset($_POST['li_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['li_nonce'])), 'li_sync_gsheets_verify');
            if (!$nonce_valid && !current_user_can('manage_options')) {
                wp_die(__('Permissão negada ou sessão expirada.', 'lead-intelligence'));
            }
            $messages = [];
            $has_error = false;

            // 1. Sincroniza Google Ads se configurado
            $google_conf = \LeadIntelligence\Qualification\GoogleSheetsSync::get_source_settings('google_ads');
            if (!empty($google_conf['spreadsheet_id'])) {
                $res_g = \LeadIntelligence\Qualification\GoogleSheetsSync::run_sync('google_ads', true);
                if (is_wp_error($res_g)) {
                    $messages[] = 'Google Ads: ' . $res_g->get_error_message();
                    $has_error = true;
                } else {
                    $messages[] = $res_g['message'];
                }
            }

            // 2. Sincroniza Meta Ads se configurado
            $meta_conf = \LeadIntelligence\Qualification\GoogleSheetsSync::get_source_settings('meta_ads');
            if (!empty($meta_conf['spreadsheet_id'])) {
                $res_m = \LeadIntelligence\Qualification\GoogleSheetsSync::run_sync('meta_ads', true);
                if (is_wp_error($res_m)) {
                    $messages[] = 'Meta Ads: ' . $res_m->get_error_message();
                    $has_error = true;
                } else {
                    $messages[] = $res_m['message'];
                }
            }

            // 3. Recalibra etapas e qualificação de todos os leads imediatamente
            \LeadIntelligence\Database\DbSchema::recalibrate_funnel_and_qualification();
            \LeadIntelligence\Database\DbSchema::unify_and_enrich_leads_by_phone();

            if (empty($messages)) {
                $action_message = 'Nenhuma planilha do Google Sheets está configurada. Configure o link da planilha em "Qualificação > Google Sheets".';
                $action_type = 'warning';
            } else {
                $action_message = implode('<br>', $messages);
                $action_type = $has_error ? 'warning' : 'success';
            }
        }

        // Tratamento de Período (Padrão: Hoje, ou Todo se houver pesquisa ativa)
        $custom_from      = isset($_GET['from']) ? sanitize_text_field(wp_unslash($_GET['from'])) : '';
        $custom_to        = isset($_GET['to']) ? sanitize_text_field(wp_unslash($_GET['to'])) : '';
        $search           = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $has_custom_dates = (!empty($custom_from) || !empty($custom_to));
        $explicit_period  = isset($_GET['periodo']) && $_GET['periodo'] !== '';
        $is_period_fixed  = !empty($_GET['li_explicit_period']);

        if ($has_custom_dates) {
            $periodo = 'custom';
        } elseif ($explicit_period) {
            $periodo = sanitize_text_field(wp_unslash($_GET['periodo']));
            if ($periodo !== 'custom') {
                $custom_from = '';
                $custom_to   = '';
            }
            // Se o usuário digitou uma busca textual (nome, email, telefone, etc.) e o período veio como 'today'
            // sem que ele tenha clicado explicitamente no botão de período, libera para 'all'
            // para que a janela de 24h não sobreponha e oculte o contato procurado!
            if (!empty($search) && $periodo === 'today' && !$is_period_fixed) {
                $periodo = 'all';
            }
        } else {
            // Sem período informado na URL:
            // Se há pesquisa por texto (nome, email, telefone, leadid), busca em todo o histórico ('all')
            // Se não há pesquisa, o padrão operacional diário é 'today' (Hoje)
            $periodo = !empty($search) ? 'all' : 'today';
        }

        $date_start = '';
        $date_end   = '';

        switch ($periodo) {
            case 'today':
                $date_start = wp_date('Y-m-d 00:00:00');
                $date_end   = wp_date('Y-m-d 23:59:59');
                break;
            case 'yesterday':
                $date_start = wp_date('Y-m-d 00:00:00', strtotime('-1 day'));
                $date_end   = wp_date('Y-m-d 23:59:59', strtotime('-1 day'));
                break;
            case '7d':
                $date_start = wp_date('Y-m-d 00:00:00', strtotime('-7 days'));
                $date_end   = wp_date('Y-m-d 23:59:59');
                break;
            case '30d':
                $date_start = wp_date('Y-m-d 00:00:00', strtotime('-30 days'));
                $date_end   = wp_date('Y-m-d 23:59:59');
                break;
            case 'month':
                $date_start = wp_date('Y-m-01 00:00:00');
                $date_end   = wp_date('Y-m-d 23:59:59');
                break;
            case 'custom':
                if (!empty($custom_from)) $date_start = $custom_from . ' 00:00:00';
                if (!empty($custom_to))   $date_end   = $custom_to . ' 23:59:59';
                break;
            case 'all':
            default:
                $date_start = '';
                $date_end   = '';
                break;
        }

        $search     = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $status     = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : ($is_qualificacoes ? 'qualificado' : '');
        $channel    = isset($_GET['canal']) ? sanitize_key(wp_unslash($_GET['canal'])) : '';
        $utm_source = isset($_GET['utm_source']) ? sanitize_text_field(wp_unslash($_GET['utm_source'])) : '';
        $page       = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $per_page   = 20;

        $available_utm_sources = LeadRepository::get_distinct_utm_sources();
        $counts = LeadRepository::get_status_counts($channel, $date_start, $date_end, $utm_source);

        $leads_data = LeadRepository::get_leads([
            'search'     => $search,
            'status'     => $status,
            'channel'    => $channel,
            'utm_source' => $utm_source,
            'date_from'  => !empty($date_start) ? substr($date_start, 0, 10) : '',
            'date_to'    => !empty($date_end) ? substr($date_end, 0, 10) : '',
            'page'       => $page,
            'per_page'   => $per_page,
            'orderby'    => 'id',
            'order'      => 'DESC',
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
            <div class="li-header" style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:15px; margin-bottom: 20px;">
                <div>
                    <h2>Lead Intelligence &bull; <?php echo esc_html($page_title); ?></h2>
                    <p class="li-subtitle"><?php echo esc_html($page_sub); ?></p>
                </div>
                <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                    <?php 
                    $is_gsheets_connected = \LeadIntelligence\Qualification\GoogleSheetsClient::is_connected();
                    $gsheet_settings = \LeadIntelligence\Qualification\GoogleSheetsSync::get_source_settings('google_ads');
                    $has_gsheet_configured = $is_gsheets_connected && !empty($gsheet_settings['spreadsheet_id']);
                    ?>
                    <?php if ($has_gsheet_configured): ?>
                        <form method="post" action="" style="display:inline;">
                            <?php wp_nonce_field('li_sync_gsheets_verify', 'li_nonce'); ?>
                            <button type="submit" name="li_sync_gsheets" class="button button-primary" style="display:inline-flex; align-items:center; gap:6px; background:#059669; border-color:#047857;" title="Puxa os status atualizados diretamente do Google Sheets">
                                <span class="dashicons dashicons-update" style="font-size:16px; width:16px; height:16px; line-height:16px;"></span> Sincronizar Google Sheets
                            </button>
                        </form>
                    <?php endif; ?>
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
            <div class="li-metric-grid" style="margin-bottom: 20px;">
                <div class="li-metric-card">
                    <span class="li-metric-label">Total de Leads</span>
                    <span class="li-metric-value"><?php echo number_format_i18n($counts['total']); ?></span>
                    <span class="li-metric-sub">Capturados no período</span>
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

            <!-- CARD DE FILTRO LIMPO EM LINHA ÚNICA (PADRÃO NATIVO WORDPRESS) -->
            <div class="li-card" style="margin-bottom: 20px; padding: 16px 20px;">
                <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" id="liFilterForm" style="display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end;">
                    <input type="hidden" name="page" value="<?php echo esc_attr($current_page); ?>">
                    <input type="hidden" name="periodo" id="liFilterPeriodo" value="<?php echo esc_attr($periodo); ?>">
                    <?php if ($is_period_fixed): ?>
                        <input type="hidden" name="li_explicit_period" value="1">
                    <?php endif; ?>

                    <!-- Seletor de Período -->
                    <div>
                        <label style="display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 6px;">Período</label>
                        <div style="display: inline-flex; background: #f1f5f9; padding: 3px; border-radius: 6px; border: 1px solid #cbd5e1;">
                            <?php 
                            $periods = [
                                'today'     => 'Hoje',
                                'yesterday' => 'Ontem',
                                '7d'        => '7 Dias',
                                '30d'       => '30 Dias',
                                'month'     => 'Este Mês',
                                'all'       => 'Todo',
                                'custom'    => 'Personalizado'
                            ];
                            foreach ($periods as $k => $label): ?>
                                <a href="<?php echo esc_url(add_query_arg(['periodo' => $k, 'canal' => $channel, 'status' => $status, 's' => $search, 'li_explicit_period' => 1], remove_query_arg(['from', 'to']))); ?>" 
                                   class="button-link" 
                                   style="padding: 4px 10px; font-size: 12px; font-weight: 600; text-decoration: none; border-radius: 4px; <?php echo $periodo === $k ? 'background: #0073aa; color: #fff;' : 'color: #334155;'; ?>">
                                    <?php echo esc_html($label); ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Datas Customizadas -->
                    <?php if ($periodo === 'custom'): ?>
                        <div style="display: flex; gap: 8px; align-items: center;">
                            <div>
                                <label style="display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 6px;">De</label>
                                <input type="date" name="from" value="<?php echo esc_attr($custom_from); ?>" style="height: 32px; font-size: 13px;">
                            </div>
                            <div>
                                <label style="display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 6px;">Até</label>
                                <input type="date" name="to" value="<?php echo esc_attr($custom_to); ?>" style="height: 32px; font-size: 13px;">
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Campo de Pesquisa Fluido -->
                    <div style="flex: 1 1 200px; min-width: 160px;">
                        <label style="display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 6px;">Pesquisar</label>
                        <input type="text" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Buscar por nome, e-mail, telefone..." style="width: 100%; height: 32px; font-size: 13px;">
                    </div>

                    <!-- Filtro Específico por utm_source -->
                    <div style="min-width: 170px;">
                        <label style="display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 6px;">🏷️ utm_source</label>
                        <input type="text" name="utm_source" list="li_utm_sources_list" value="<?php echo esc_attr($utm_source); ?>" placeholder="Ex: generica-20-10-26" style="width: 100%; height: 32px; font-size: 13px;">
                        <datalist id="li_utm_sources_list">
                            <?php foreach ($available_utm_sources as $src_opt): ?>
                                <option value="<?php echo esc_attr($src_opt); ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>

                    <!-- Select de Canal -->
                    <div style="min-width: 140px;">
                        <label style="display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 6px;">Canal</label>
                        <select name="canal" style="width: 100%; height: 32px; font-size: 13px;">
                            <option value="">Todos os Canais</option>
                            <option value="planilha" <?php selected($channel, 'planilha'); ?>>📊 Todas as Planilhas</option>
                            <option value="planilha_google" <?php selected($channel, 'planilha_google'); ?>>📊 Planilha (Google Ads)</option>
                            <option value="planilha_meta" <?php selected($channel, 'planilha_meta'); ?>>📊 Planilha (Meta Ads)</option>
                            <option value="chatgpt" <?php selected($channel, 'chatgpt'); ?>>🤖 ChatGPT Ads</option>
                            <option value="google_lead_form" <?php selected($channel, 'google_lead_form'); ?>>📋 Google Lead Form</option>
                            <option value="google_ads" <?php selected($channel, 'google_ads'); ?>>🟢 Google Ads (Site)</option>
                            <option value="meta_ads" <?php selected($channel, 'meta_ads'); ?>>🔵 Meta Ads (Site)</option>
                            <option value="whatsapp" <?php selected($channel, 'whatsapp'); ?>>💬 WhatsApp Direto</option>
                            <option value="organico" <?php selected($channel, 'organico'); ?>>⚪ Direto / Site</option>
                        </select>
                    </div>

                    <!-- Select de Status -->
                    <div style="min-width: 130px;">
                        <label style="display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 6px;">Status</label>
                        <select name="status" style="width: 100%; height: 32px; font-size: 13px;">
                            <option value="">Todos os Status</option>
                            <option value="pendente" <?php selected($status, 'pendente'); ?>>Pendentes</option>
                            <option value="qualificado" <?php selected($status, 'qualificado'); ?>>Qualificados / Vendas</option>
                            <option value="nao_qualificado" <?php selected($status, 'nao_qualificado'); ?>>Não Qualificados</option>
                            <option value="sem_correspondencia" <?php selected($status, 'sem_correspondencia'); ?>>Sem Correspondência</option>
                        </select>
                    </div>

                    <!-- Botões Filtrar e Limpar -->
                    <div style="display: flex; gap: 8px;">
                        <button type="submit" class="button button-primary" style="height: 32px; display: inline-flex; align-items: center; gap: 5px;">
                            🔍 Filtrar
                        </button>
                        <a href="<?php echo esc_url($current_url); ?>" class="button button-secondary" style="height: 32px; line-height: 30px;">
                            Limpar
                        </a>
                    </div>
                </form>
            </div>

            <script>
            (function() {
                var form = document.getElementById('liFilterForm');
                if (!form) return;
                form.addEventListener('submit', function() {
                    var sInput = form.querySelector('input[name="s"]');
                    var pInput = document.getElementById('liFilterPeriodo');
                    var expInput = form.querySelector('input[name="li_explicit_period"]');
                    // Se o usuário está buscando por termo textual e o período estava como 'today' sem fixação explícita, expande para 'all'
                    if (sInput && sInput.value.trim() !== '') {
                        if (!expInput && pInput && pInput.value === 'today') {
                            pInput.value = 'all';
                        }
                    }
                });
            })();
            </script>

            <!-- CARD DE DESTAQUE: FILTRO ATIVO POR PESQUISA -->
            <?php if (!empty($search)): ?>
                <div style="margin-bottom: 15px; padding: 12px 18px; background: #f0fdf4; border: 1px solid #86efac; border-left: 4px solid #16a34a; border-radius: 8px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                        <span style="font-size: 18px;">🔍</span>
                        <div style="font-size: 13px; color: #15803d;">
                            Pesquisa ativa: <strong>"<?php echo esc_html($search); ?>"</strong>
                            <span style="margin-left: 10px; color: #374151; font-weight: 500;">
                                (Período: <strong><?php echo esc_html($periods[$periodo] ?? 'Personalizado'); ?></strong>)
                            </span>
                            <span style="margin-left: 12px; color: #0f172a; font-weight: 600;">
                                📊 Encontrados: <strong><?php echo (int) $total_leads; ?> lead(s)</strong>
                            </span>
                        </div>
                    </div>
                    <div>
                        <a href="<?php echo esc_url(remove_query_arg(['s', 'paged'])); ?>" class="button button-secondary button-small" style="color: #b91c1c; border-color: #fca5a5;">
                            ✕ Limpar Pesquisa
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <!-- CARD DE DESTAQUE: FILTRO ATIVO POR UTM SOURCE -->
            <?php if (!empty($utm_source)): ?>
                <div style="margin-bottom: 15px; padding: 12px 18px; background: #f0f9ff; border: 1px solid #7dd3fc; border-left: 4px solid #0284c7; border-radius: 8px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                        <span style="font-size: 18px;">🏷️</span>
                        <div style="font-size: 13px; color: #0369a1;">
                            Filtro ativo: <strong>utm_source = </strong>
                            <code style="background: #ffffff; border: 1px solid #bae6fd; padding: 3px 8px; border-radius: 4px; color: #0369a1; font-weight: 700; font-size: 13px;"><?php echo esc_html($utm_source); ?></code>
                            <span style="margin-left: 12px; color: #0f172a; font-weight: 600;">
                                📊 Encontrados: <strong><?php echo (int) $total_leads; ?> leads</strong> 
                                (<?php echo (int) $counts['qualificado']; ?> qualificados / vendas)
                            </span>
                        </div>
                    </div>
                    <div>
                        <a href="<?php echo esc_url(remove_query_arg('utm_source')); ?>" class="button button-secondary button-small" style="color: #b91c1c; border-color: #fca5a5;">
                            ✕ Limpar Filtro UTM
                        </a>
                    </div>
                </div>
            <?php endif; ?>

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

                        <div style="font-size: 12px; color: #64748b; display: flex; align-items: center; gap: 12px;">
                            <button type="button" id="liResetColWidths" class="button" style="font-size: 11px; height: 26px; line-height: 24px; display: inline-flex; align-items: center; gap: 4px; color: #475569; padding: 0 9px; border-color: #cbd5e1; background: #ffffff;" title="Restaura as larguras padrão de todas as colunas">
                                <span class="dashicons dashicons-image-rotate" style="font-size: 13px; width: 13px; height: 13px; line-height: 13px; color: #64748b;"></span>
                                Redefinir Colunas
                            </button>
                            <span>Total: <strong><?php echo number_format_i18n($total_leads); ?></strong> leads encontrados</span>
                        </div>
                    </div>

                    <table id="liLeadsTable" class="wp-list-table widefat fixed striped li-table li-resizable-table">
                        <thead>
                            <tr>
                                <th data-col-id="check" style="width: 40px; min-width: 40px; max-width: 40px; text-align: center; position: relative;">
                                    <input type="checkbox" id="li_select_all" title="Selecionar Todos na Página">
                                </th>
                                <th data-col-id="id" style="width: 60px; min-width: 48px; position: relative;">
                                    <span>ID</span>
                                    <span class="li-col-resizer" title="Arraste para redimensionar coluna (duplo clique para restaurar)"></span>
                                </th>
                                <th data-col-id="leadid" style="width: 85px; min-width: 65px; position: relative;">
                                    <span>Lead ID</span>
                                    <span class="li-col-resizer" title="Arraste para redimensionar coluna (duplo clique para restaurar)"></span>
                                </th>
                                <th data-col-id="data" style="width: 100px; min-width: 80px; position: relative;">
                                    <span>Data</span>
                                    <span class="li-col-resizer" title="Arraste para redimensionar coluna (duplo clique para restaurar)"></span>
                                </th>
                                <th data-col-id="nome" style="width: 230px; min-width: 140px; position: relative;">
                                    <span>Nome / Contato</span>
                                    <span class="li-col-resizer" title="Arraste para redimensionar coluna (duplo clique para restaurar)"></span>
                                </th>
                                <th data-col-id="instancia" style="width: 120px; min-width: 85px; position: relative;">
                                    <span>Instância</span>
                                    <span class="li-col-resizer" title="Arraste para redimensionar coluna (duplo clique para restaurar)"></span>
                                </th>
                                <th data-col-id="curso" style="width: 120px; min-width: 85px; position: relative;">
                                    <span>Curso / Interesse</span>
                                    <span class="li-col-resizer" title="Arraste para redimensionar coluna (duplo clique para restaurar)"></span>
                                </th>
                                <th data-col-id="canal" style="width: 150px; min-width: 100px; position: relative;">
                                    <span>Canal</span>
                                    <span class="li-col-resizer" title="Arraste para redimensionar coluna (duplo clique para restaurar)"></span>
                                </th>
                                <th data-col-id="utm" style="width: 160px; min-width: 100px; position: relative;">
                                    <span>UTM</span>
                                    <span class="li-col-resizer" title="Arraste para redimensionar coluna (duplo clique para restaurar)"></span>
                                </th>
                                <th data-col-id="etapa" style="width: 150px; min-width: 100px; text-align: center; position: relative;">
                                    <span>Etapa do Funil</span>
                                    <span class="li-col-resizer" title="Arraste para redimensionar coluna (duplo clique para restaurar)"></span>
                                </th>
                                <th data-col-id="status" style="width: 110px; min-width: 80px; text-align: center; position: relative;">
                                    <span>Status</span>
                                    <span class="li-col-resizer" title="Arraste para redimensionar coluna (duplo clique para restaurar)"></span>
                                </th>
                                <th data-col-id="acoes" style="width: 85px; min-width: 75px; text-align: center; position: relative;">
                                    <span>Ações</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($leads)): ?>
                            <tr>
                                <td colspan="12" style="text-align: center; padding: 40px 20px; color: #64748b;">
                                    <span class="dashicons dashicons-id-alt" style="font-size: 36px; width: 36px; height: 36px; color: #94a3b8; margin-bottom: 10px;"></span>
                                    <p style="font-size: 15px; margin: 0; font-weight: 600; color: #334155;">Nenhum lead encontrado com os filtros selecionados.</p>
                                    <p style="font-size: 13px; margin: 5px 0 15px;">
                                        <?php if (!empty($search)): ?>
                                            Nenhum contato coincide com a busca "<strong><?php echo esc_html($search); ?></strong>"<?php echo ($periodo !== 'all') ? ' no período <strong>' . esc_html($periods[$periodo] ?? $periodo) . '</strong>' : ''; ?>.
                                        <?php else: ?>
                                            Verifique os filtros de canal, período ou importe novas planilhas.
                                        <?php endif; ?>
                                    </p>
                                    <?php if ($periodo !== 'all'): ?>
                                        <div style="margin-top: 10px;">
                                            <a href="<?php echo esc_url(add_query_arg(['periodo' => 'all'], remove_query_arg(['from', 'to']))); ?>" class="button button-primary" style="font-size: 13px; font-weight: 600;">
                                                🔍 Buscar em Todo o Período
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($leads as $lead): 
                                $lead_channel = LeadRepository::get_channel($lead);
                                $chan_info    = LeadRepository::get_channel_info($lead_channel);
                                $etapa_raw = !empty($lead->etapa_funil) && strcasecmp(trim($lead->etapa_funil), 'pendente') !== 0
                                    ? trim($lead->etapa_funil)
                                    : ($lead->qualificacao_status === 'qualificado' ? 'Matrícula Realizada' : ($lead->qualificacao_status === 'nao_qualificado' ? 'Desqualificado' : 'Formulário Enviado'));

                                // Estilização visual inteligente da Etapa do Funil baseada em termos comerciais reais
                                $etapa_lower = mb_strtolower($etapa_raw, 'UTF-8');
                                if (preg_match('/(venda|vendid|matricul|aluno|fechado|ganho|pago|aprovad)/iu', $etapa_lower)) {
                                    $etapa_badge_style = 'background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0;';
                                    $etapa_icon = '🎓';
                                } elseif (preg_match('/(desqualificad|nao[ _-]?convertid|não[ _-]?convertid|perda|perdido|cancelad|recusad|invalido|inválido|sem[ _-]?perfil|descarte)/iu', $etapa_lower)) {
                                    $etapa_badge_style = 'background: #ffe4e6; color: #be123c; border: 1px solid #fecdd3;';
                                    $etapa_icon = '❌';
                                } elseif (preg_match('/(tentativa|contato|atendimento|aguardando)/iu', $etapa_lower)) {
                                    $etapa_badge_style = 'background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd;';
                                    $etapa_icon = '📞';
                                } elseif (preg_match('/(negocia|proposta|analis|boleto|remarketing)/iu', $etapa_lower)) {
                                    $etapa_badge_style = 'background: #fef3c7; color: #b45309; border: 1px solid #fde68a;';
                                    $etapa_icon = '⏳';
                                } else {
                                    $etapa_badge_style = 'background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0;';
                                    $etapa_icon = '📝';
                                }

                                $lead_course = $lead->tipo_curso ?: ($lead->area_interesse ?: '-');
                                $lead_phone_clean = preg_replace('/\D/', '', $lead->telefone_normalizado ?: ($lead->telefone ?? ''));
                                
                                // Extrai apenas a utm_source limpa
                                $source_display = $lead->utm_source;
                                if (empty($source_display) && !empty($lead->pagina_origem) && strpos($lead->pagina_origem, 'utm_source=') !== false) {
                                    $parsed_q = parse_url($lead->pagina_origem, PHP_URL_QUERY);
                                    if (!empty($parsed_q)) {
                                        parse_str($parsed_q, $q_params);
                                        if (!empty($q_params['utm_source'])) {
                                            $source_display = sanitize_text_field($q_params['utm_source']);
                                        }
                                    }
                                }
                            ?>
                                <tr id="li-row-<?php echo esc_attr($lead->id); ?>">
                                    <td style="text-align: center; vertical-align: middle;">
                                        <input type="checkbox" name="selected_leads[]" value="<?php echo esc_attr($lead->id); ?>" class="li-lead-check" style="margin: 0;">
                                    </td>
                                    <td><strong>#<?php echo esc_html($lead->id); ?></strong></td>
                                    <td>
                                        <?php if (!empty($lead->leadid)): ?>
                                            <code style="background: #f8fafc; color: #334155; border: 1px solid #cbd5e1; font-weight: 700; font-size: 11px; padding: 2px 6px; border-radius: 4px; display: inline-block; white-space: nowrap;" title="Lead ID da Planilha: <?php echo esc_attr($lead->leadid); ?>">
                                                #<?php echo esc_html($lead->leadid); ?>
                                            </code>
                                        <?php else: ?>
                                            <span style="color: #cbd5e1; font-size: 12px;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="font-size: 12px; font-weight: 600;">
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
                                        <?php if (!empty($lead_phone_clean)): ?>
                                            <div style="margin-top: 2px;">
                                                <a href="https://wa.me/<?php echo esc_attr($lead_phone_clean); ?>" target="_blank" style="color: #059669; font-weight: 600; text-decoration: none; font-size: 12px;">
                                                    💬 <?php echo esc_html(PhoneNormalizer::format_display($lead->telefone ?: $lead->telefone_normalizado)); ?>
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($lead->email)): ?>
                                            <div style="font-size: 11px; color: #64748b;">
                                                <?php echo esc_html($lead->email); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $inst_val = !empty($lead->instancia) ? $lead->instancia : (!empty($lead->polo) ? $lead->polo : '');
                                        ?>
                                        <?php if (!empty($inst_val)): ?>
                                            <span class="li-badge" style="background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; font-weight: 600; font-size: 11px; padding: 2px 7px; border-radius: 4px; display: inline-flex; align-items: center; gap: 4px; text-transform: capitalize;" title="Instância: <?php echo esc_attr($inst_val); ?>">
                                                <span>🏛️</span> <?php echo esc_html($inst_val); ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color: #cbd5e1; font-size: 12px;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span style="font-size: 12px; color: #334155;"><?php echo esc_html($lead_course); ?></span>
                                    </td>
                                    <td>
                                        <span class="li-badge <?php echo esc_attr($chan_info['badge_class']); ?>" style="display:inline-flex; align-items:center; gap:5px; font-weight:600; font-size:11px; padding:3px 8px;">
                                            <span><?php echo esc_html($chan_info['dot']); ?></span>
                                            <span><?php echo esc_html($chan_info['label']); ?></span>
                                        </span>
                                        <?php if ($lead_channel === 'google_lead_form'): 
                                            $form_lead_display = !empty($lead->formulario_nome) ? $lead->formulario_nome : (!empty($lead->origem) ? $lead->origem : 'Formulário Google');
                                        ?>
                                            <div style="margin-top: 3px;" title="<?php echo esc_attr($form_lead_display); ?>">
                                                <code style="background: #fffbeb; color: #b45309; border: 1px solid #fde68a; font-size: 10px; padding: 1px 5px; border-radius: 3px; display: inline-block; max-width: 140px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                    📝 <?php echo esc_html($form_lead_display); ?>
                                                </code>
                                            </div>
                                        <?php elseif (strpos($lead_channel, 'planilha') !== false): 
                                            $plan_origin_raw = !empty($lead->pagina_origem) && strpos($lead->pagina_origem, 'Planilha:') !== false 
                                                ? trim(str_replace('Planilha:', '', $lead->pagina_origem)) 
                                                : (!empty($lead->formulario_nome) ? $lead->formulario_nome : 'Planilha');
                                        ?>
                                            <div style="margin-top: 3px;" title="<?php echo esc_attr($plan_origin_raw); ?>">
                                                <code style="background: #f5f3ff; color: #6d28d9; border: 1px solid #ddd6fe; font-size: 10px; padding: 1px 5px; border-radius: 3px; display: inline-block; max-width: 140px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                    📁 <?php echo esc_html($plan_origin_raw); ?>
                                                </code>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($source_display)): ?>
                                            <a href="<?php echo esc_url(add_query_arg(['utm_source' => $source_display, 'periodo' => $periodo], $current_url)); ?>" style="text-decoration: none;" title="Clique para filtrar apenas leads com utm_source = '<?php echo esc_attr($source_display); ?>'">
                                                <code style="background: #e0f2fe; color: #0369a1; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; cursor: pointer; transition: all 0.2s;" onmouseover="this.style.background='#bae6fd'; this.style.color='#0284c7';" onmouseout="this.style.background='#e0f2fe'; this.style.color='#0369a1';">
                                                    <span>🏷️</span> <?php echo esc_html($source_display); ?>
                                                </code>
                                            </a>
                                        <?php else: ?>
                                            <span style="color: #94a3b8; font-size: 11px; font-style: italic;">Sem UTM</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: center;">
                                        <span class="li-badge" style="display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 600; padding: 3px 8px; border-radius: 4px; <?php echo esc_attr($etapa_badge_style); ?>">
                                            <span><?php echo esc_html($etapa_icon); ?></span>
                                            <span><?php echo esc_html($etapa_raw); ?></span>
                                        </span>
                                    </td>
                                    <td style="text-align: center;">
                                        <span class="li-badge <?php echo $lead->qualificacao_status === 'qualificado' ? 'li-status-qualificado' : ($lead->qualificacao_status === 'nao_qualificado' ? 'li-status-desqualificado' : 'li-status-cinza'); ?>" style="font-weight: 600;">
                                            <?php 
                                             if ($lead->qualificacao_status === 'qualificado') {
                                                echo 'Venda';
                                            } elseif ($lead->qualificacao_status === 'nao_qualificado') {
                                                echo 'Não Qualificado';
                                            } else {
                                                echo 'Pendente';
                                            }
                                            ?>
                                        </span>
                                    </td>
                                    <td style="text-align: center;">
                                        <button type="button" class="button button-small li-open-modal-btn" data-lead="<?php echo esc_attr(wp_json_encode($lead)); ?>" style="height: 28px; line-height: 26px;">
                                            Detalhes
                                        </button>
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
                <div class="li-pagination" style="margin-top: 15px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <span style="font-size: 13px; color: #64748b;">Página <?php echo $page; ?> de <?php echo $total_pages; ?> (<?php echo $total_leads; ?> leads no total)</span>
                    <div class="li-pagination-links" style="display: flex; gap: 4px;">
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <?php
                            $link = add_query_arg([
                                'paged'      => $i,
                                'periodo'    => $periodo,
                                'from'       => $custom_from,
                                'to'         => $custom_to,
                                's'          => $search,
                                'status'     => $status,
                                'canal'      => $channel,
                                'utm_source' => $utm_source,
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
                        <input type="hidden" name="filter_date_from" value="<?php echo esc_attr(!empty($date_start) ? substr($date_start, 0, 10) : ''); ?>">
                        <input type="hidden" name="filter_date_to" value="<?php echo esc_attr(!empty($date_end) ? substr($date_end, 0, 10) : ''); ?>">

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
                                <div>• <strong>Período:</strong> <?php 
                                    $p_labels = [
                                        'today'     => 'Hoje (' . wp_date('d/m/Y') . ')',
                                        'yesterday' => 'Ontem (' . wp_date('d/m/Y', strtotime('-1 day')) . ')',
                                        '7d'        => 'Últimos 7 dias',
                                        '30d'       => 'Últimos 30 dias',
                                        'month'     => 'Este mês',
                                        'custom'    => 'Personalizado (' . (!empty($custom_from) ? wp_date('d/m/Y', strtotime($custom_from)) : '') . ' até ' . (!empty($custom_to) ? wp_date('d/m/Y', strtotime($custom_to)) : '') . ')',
                                        'all'       => 'Todo o histórico (sem filtro de data)',
                                    ];
                                    echo esc_html($p_labels[$periodo] ?? 'Personalizado');
                                ?></div>
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

            <!-- MODAL DE DETALHES DO LEAD (SaaS Premium Redesign) -->
            <div id="liLeadModal" class="li-modal-backdrop" style="display:none;">
                <div class="li-modal-container">
                    <div class="li-modal-header">
                        <div class="li-modal-header-left">
                            <div class="li-lead-avatar" id="liModalAvatar">LD</div>
                            <div class="li-lead-title-meta">
                                <div class="li-lead-title-row">
                                    <h3 id="liModalTitle">Detalhes do Lead</h3>
                                    <span class="li-lead-id-pill" id="liModalIdBadge">#0</span>
                                </div>
                                <div class="li-lead-subtitle-row" id="liModalSubMeta">
                                    <span id="liModalDate"><span class="dashicons dashicons-calendar-alt"></span> -</span>
                                    <span class="li-dot-sep">•</span>
                                    <span id="liModalChanTag">⚪ Direto / Site</span>
                                </div>
                            </div>
                        </div>
                        <div class="li-modal-header-right">
                            <a id="liModalWhatsAppBtn" href="#" target="_blank" class="li-btn-wa-header" style="display:none;" title="Conversar no WhatsApp">
                                <span class="dashicons dashicons-whatsapp"></span>
                                <span>WhatsApp</span>
                            </a>
                            <button type="button" class="li-modal-close" onclick="closeLiModal()" title="Fechar (Esc)">&times;</button>
                        </div>
                    </div>
                    <div class="li-modal-body" id="liModalContent">
                        <!-- Preenchido dinamicamente via JavaScript -->
                    </div>
                </div>
            </div>

            <script>
            window.liAdminAjax = {
                url: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>',
                nonce: '<?php echo esc_js(wp_create_nonce('li_ajax_nonce')); ?>'
            };

            function closeLiModal() {
                var m = document.getElementById('liLeadModal');
                if (m) m.style.display = 'none';
            }

            // Fechar ao clicar no backdrop (fora do container)
            var liModalBackdrop = document.getElementById('liLeadModal');
            if (liModalBackdrop) {
                liModalBackdrop.addEventListener('click', function(e) {
                    if (e.target === this) {
                        closeLiModal();
                    }
                });
            }

            // Fechar ao pressionar a tecla ESC
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' || e.keyCode === 27) {
                    var m = document.getElementById('liLeadModal');
                    if (m && m.style.display !== 'none') {
                        closeLiModal();
                    }
                }
            });

            // Função global de cópia para a área de transferência com feedback visual
            window.liCopyToClipboard = function(text, el) {
                if (!text || text === '-' || text === '—') return;
                
                function showSuccess() {
                    if (el) {
                        el.classList.add('li-copied');
                        var icon = el.querySelector('.dashicons');
                        var oldClass = icon ? icon.className : '';
                        if (icon) icon.className = 'dashicons dashicons-yes';
                        setTimeout(function() {
                            el.classList.remove('li-copied');
                            if (icon) icon.className = oldClass;
                        }, 1400);
                    }
                }

                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(showSuccess).catch(function() {
                        fallbackCopy(text);
                    });
                } else {
                    fallbackCopy(text);
                }

                function fallbackCopy(str) {
                    var ta = document.createElement('textarea');
                    ta.value = str;
                    ta.style.position = 'fixed';
                    ta.style.opacity = '0';
                    document.body.appendChild(ta);
                    ta.focus();
                    ta.select();
                    try {
                        document.execCommand('copy');
                        showSuccess();
                    } catch (e) {}
                    document.body.removeChild(ta);
                }
            };

            document.querySelectorAll('.li-open-modal-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var lead = JSON.parse(this.getAttribute('data-lead'));
                    
                    // Helpers de escape e formatação
                    function escH(s) {
                        if (s === null || s === undefined) return '';
                        return String(s)
                            .replace(/&/g, '&amp;')
                            .replace(/</g, '&lt;')
                            .replace(/>/g, '&gt;')
                            .replace(/"/g, '&quot;')
                            .replace(/'/g, '&#039;');
                    }

                    function truncate(str, max) {
                        if (!str) return '';
                        return str.length > max ? str.substring(0, max) + '...' : str;
                    }

                    function makeRow(label, valHtml, icon) {
                        var iconHtml = icon ? '<span class="dashicons ' + icon + '" style="font-size:14px; width:14px; height:14px; line-height:14px; opacity:0.75;"></span> ' : '';
                        return '<div class="li-modal-row">' +
                               '<div class="li-row-label">' + iconHtml + label + '</div>' +
                               '<div class="li-row-value">' + valHtml + '</div>' +
                               '</div>';
                    }

                    function makeText(val, isBold) {
                        if (!val || val === '-' || val === 'null' || val === 'undefined') {
                            return '<span class="li-val-empty">—</span>';
                        }
                        return '<span class="' + (isBold ? 'li-val-bold' : '') + '">' + escH(val) + '</span>';
                    }

                    function makeCopyPill(val) {
                        if (!val || val === '-' || val === 'null' || val === 'undefined') {
                            return '<span class="li-val-empty">—</span>';
                        }
                        return '<span class="li-copy-pill" onclick="liCopyToClipboard(\'' + escH(val) + '\', this)" title="Clique para copiar: ' + escH(val) + '">' +
                               '<span>' + escH(val) + '</span>' +
                               '<span class="dashicons dashicons-admin-page"></span>' +
                               '</span>';
                    }

                    // Extrair iniciais para o Avatar Monograma
                    var nameParts = (lead.nome || '').trim().split(/\s+/);
                    var initials = 'LD';
                    if (nameParts.length === 1 && nameParts[0].length > 0) {
                        initials = nameParts[0].substring(0, 2).toUpperCase();
                    } else if (nameParts.length >= 2) {
                        initials = (nameParts[0][0] + nameParts[nameParts.length - 1][0]).toUpperCase();
                    }
                    var avatarEl = document.getElementById('liModalAvatar');
                    if (avatarEl) avatarEl.innerText = initials;

                    // Título e ID Badge
                    document.getElementById('liModalTitle').innerText = lead.nome || 'Lead sem Nome';
                    document.getElementById('liModalIdBadge').innerText = '#' + lead.id;
                    document.getElementById('liModalDate').innerHTML = '<span class="dashicons dashicons-calendar-alt"></span> ' + (lead.data_cadastro || '-');

                    // Verificação de origem
                    var src = (lead.utm_source || '').toLowerCase();
                    var med = (lead.utm_medium || '').toLowerCase();
                    var camp = (lead.utm_campaign || '').toLowerCase();
                    var campName = (lead.campaign_name || '').toLowerCase();
                    var form = (lead.formulario_nome || '').toLowerCase();
                    var pag = (lead.pagina_origem || '').toLowerCase();
                    var ref = (lead.referrer || '').toLowerCase();
                    var mot = (lead.motivo_qualificacao || '').toLowerCase();
                    var qualOrigem = (lead.qualificacao_origem || '').toLowerCase();
                    var formId = (lead.formulario_id || '').toLowerCase();
                    var orig = (lead.origem || '').toLowerCase();

                    var isPlanilha = (
                        src.indexOf('planilha') !== -1 || 
                        form.indexOf('planilha') !== -1 || 
                        pag.indexOf('planilha:') !== -1 ||
                        mot.indexOf('cadastrado via planilha') !== -1 ||
                        (qualOrigem.indexOf('planilha:') !== -1 && (!formId || formId.indexOf('planilha') !== -1))
                    );

                    var isGoogleLeadForm = (
                        src === 'google_lead_form' ||
                        src === 'google-lead-form' ||
                        src.indexOf('lead_form') !== -1 ||
                        med === 'lead_form' ||
                        med === 'leadform' ||
                        form.indexOf('google lead form') !== -1 ||
                        form.indexOf('lead form') !== -1 ||
                        form.indexOf('leadform') !== -1 ||
                        form.indexOf('formulario google') !== -1 ||
                        form.indexOf('formulário google') !== -1 ||
                        form.indexOf('formulario do google') !== -1 ||
                        form.indexOf('formulário do google') !== -1 ||
                        form.indexOf('google form') !== -1 ||
                        orig.indexOf('lead form') !== -1 ||
                        orig.indexOf('google ads lead form') !== -1 ||
                        orig.indexOf('formulario google') !== -1 ||
                        orig.indexOf('formulário google') !== -1 ||
                        pag.indexOf('lead form') !== -1 ||
                        pag.indexOf('google_lead_form') !== -1 ||
                        pag.indexOf('formulario google') !== -1 ||
                        pag.indexOf('formulário google') !== -1 ||
                        camp.indexOf('formulario google') !== -1 ||
                        camp.indexOf('formulário google') !== -1 ||
                        camp.indexOf('formulario_google') !== -1 ||
                        camp.indexOf('formulario-google') !== -1 ||
                        camp.indexOf('formulario do google') !== -1 ||
                        camp.indexOf('formulário do google') !== -1 ||
                        camp.indexOf('google_lead_form') !== -1 ||
                        camp.indexOf('lead_form') !== -1 ||
                        camp.indexOf('lead form') !== -1 ||
                        camp.indexOf('leadform') !== -1 ||
                        camp.indexOf('form google') !== -1 ||
                        camp.indexOf('google form') !== -1 ||
                        campName.indexOf('formulario google') !== -1 ||
                        campName.indexOf('formulário google') !== -1 ||
                        campName.indexOf('formulario_google') !== -1 ||
                        campName.indexOf('formulario-google') !== -1 ||
                        campName.indexOf('formulario do google') !== -1 ||
                        campName.indexOf('formulário do google') !== -1 ||
                        campName.indexOf('google_lead_form') !== -1 ||
                        campName.indexOf('lead_form') !== -1 ||
                        campName.indexOf('lead form') !== -1 ||
                        campName.indexOf('leadform') !== -1 ||
                        campName.indexOf('form google') !== -1 ||
                        campName.indexOf('google form') !== -1
                    );

                    // Badge do Canal de Origem
                    var chanBadge = '<span class="li-badge-channel li-badge-direct">⚪ Direto / Site</span>';
                    if (isPlanilha) {
                        if (form.indexOf('google') !== -1 || src.indexOf('google') !== -1) {
                            chanBadge = '<span class="li-badge-channel li-badge-planilha"><span class="dashicons dashicons-media-spreadsheet"></span> Planilha (Google Ads)</span>';
                        } else if (form.indexOf('meta') !== -1 || src.indexOf('meta') !== -1) {
                            chanBadge = '<span class="li-badge-channel li-badge-planilha"><span class="dashicons dashicons-media-spreadsheet"></span> Planilha (Meta Ads)</span>';
                        } else {
                            chanBadge = '<span class="li-badge-channel li-badge-planilha"><span class="dashicons dashicons-media-spreadsheet"></span> Planilha (Importado)</span>';
                        }
                    } else if (isGoogleLeadForm) {
                        chanBadge = '<span class="li-badge-channel li-badge-gform"><span class="dashicons dashicons-forms"></span> Google Lead Form</span>';
                    } else if (['chat-ads', 'chatgpt', 'chat-gpt', 'openai', 'chat_ads'].indexOf(src) !== -1 || src.indexOf('chat-ads') !== -1 || src.indexOf('chatgpt') !== -1 || form.indexOf('chat ads') !== -1 || form.indexOf('chat-ads') !== -1 || form.indexOf('chatgpt') !== -1 || pag.indexOf('chat-gpt') !== -1 || pag.indexOf('chatgpt') !== -1 || ref.indexOf('chatgpt') !== -1) {
                        chanBadge = '<span class="li-badge-channel li-badge-chatgpt">🤖 ChatGPT Ads</span>';
                    } else if (lead.gclid || src === 'google' || src === 'google_ads' || form.indexOf('google') !== -1) {
                        chanBadge = '<span class="li-badge-channel li-badge-google"><span class="dashicons dashicons-google"></span> Google Ads</span>';
                    } else if (lead.fbclid || lead.ad_id || ['meta','facebook','instagram','fb'].indexOf(src) !== -1 || form.indexOf('meta') !== -1 || form.indexOf('facebook') !== -1) {
                        chanBadge = '<span class="li-badge-channel li-badge-meta"><span class="dashicons dashicons-facebook"></span> Meta Ads</span>';
                    } else if (form.indexOf('whatsapp') !== -1 || lead.whatsapp_status || lead.conversation_id) {
                        chanBadge = '<span class="li-badge-channel li-badge-whatsapp"><span class="dashicons dashicons-whatsapp"></span> WhatsApp Direto</span>';
                    }

                    var chanTagEl = document.getElementById('liModalChanTag');
                    if (chanTagEl) chanTagEl.innerHTML = chanBadge;

                    // Botão rápido de WhatsApp no topo
                    var waBtn = document.getElementById('liModalWhatsAppBtn');
                    var phoneDigits = (lead.telefone_normalizado || lead.telefone || '').replace(/\D/g, '');
                    if (waBtn) {
                        if (phoneDigits.length >= 10) {
                            waBtn.href = 'https://wa.me/' + phoneDigits;
                            waBtn.style.display = 'inline-flex';
                        } else {
                            waBtn.style.display = 'none';
                        }
                    }

                    var html = '';

                    // Banner de Origem (Planilha ou Google Lead Form) com Dark Glass
                    if (isPlanilha) {
                        var planSourceStr = lead.pagina_origem || lead.formulario_nome || lead.qualificacao_origem || 'Planilha';
                        html += '<div class="li-modal-alert li-alert-planilha">' +
                                '<div class="li-alert-icon"><span class="dashicons dashicons-media-spreadsheet"></span></div>' +
                                '<div class="li-alert-body">' +
                                '<strong>Lead Originado de Planilha:</strong> Este lead não existia no banco e foi importado automaticamente a partir da planilha: ' +
                                '<code class="li-chip-purple">' + escH(planSourceStr) + '</code>' +
                                '</div>' +
                                '</div>';
                    } else if (isGoogleLeadForm) {
                        var formSourceStr = lead.formulario_nome || lead.utm_campaign || lead.origem || 'Formulário Google';
                        html += '<div class="li-modal-alert li-alert-google-form">' +
                                '<div class="li-alert-icon"><span class="dashicons dashicons-forms"></span></div>' +
                                '<div class="li-alert-body">' +
                                '<strong>Lead de Formulário Google (Google Lead Form):</strong> Origem / Campanha identificada: ' +
                                '<code class="li-chip-amber">' + escH(formSourceStr) + '</code>' +
                                '</div>' +
                                '</div>';
                    }

                    html += '<div class="li-modal-sections">';
                    
                    // ==========================================
                    // Seção 1: Informações Cadastrais
                    // ==========================================
                    html += '<div class="li-modal-box">';
                    html += '<h4><span class="dashicons dashicons-id"></span> Informações Cadastrais</h4>';
                    html += makeRow('Data de Cadastro', makeText(lead.data_cadastro), 'dashicons-calendar-alt');
                    
                    // Telefone Original com Cópia e Link WhatsApp direto
                    var telOrigHtml = '<span class="li-val-empty">—</span>';
                    if (lead.telefone) {
                        telOrigHtml = '<span class="li-copy-pill" onclick="liCopyToClipboard(\'' + escH(lead.telefone) + '\', this)" title="Clique para copiar">' +
                                      '<span>' + escH(lead.telefone) + '</span>' +
                                      '<span class="dashicons dashicons-admin-page"></span>' +
                                      '</span>';
                    }
                    html += makeRow('Telefone Original', telOrigHtml, 'dashicons-phone');

                    // Telefone Normalizado
                    html += makeRow('Telefone Normalizado', makeCopyPill(lead.telefone_normalizado), 'dashicons-smartphone');

                    // E-mail com Cópia
                    var emailHtml = '<span class="li-val-empty">—</span>';
                    if (lead.email) {
                        emailHtml = '<span class="li-copy-pill" onclick="liCopyToClipboard(\'' + escH(lead.email) + '\', this)" title="Clique para copiar">' +
                                    '<span>' + escH(lead.email) + '</span>' +
                                    '<span class="dashicons dashicons-admin-page"></span>' +
                                    '</span>';
                    }
                    html += makeRow('E-mail', emailHtml, 'dashicons-email');
                    html += makeRow('Tipo de Curso', makeText(lead.tipo_curso, true), 'dashicons-welcome-learn-more');
                    html += makeRow('Área de Interesse', makeText(lead.area_interesse, true), 'dashicons-tag');
                    
                    var instVal = lead.instancia || lead.polo;
                    var instHtml = instVal 
                        ? '<span class="li-badge" style="background:#f0fdf4; color:#166534; border:1px solid #bbf7d0; font-weight:600; padding:2px 8px; border-radius:5px; text-transform:capitalize;">🏛️ ' + escH(instVal) + '</span>'
                        : '<span class="li-val-empty">—</span>';
                    html += makeRow('Instância (Planilha)', instHtml, 'dashicons-building');

                    html += makeRow('Lead ID (Planilha)', makeCopyPill(lead.leadid), 'dashicons-id');
                    html += '</div>';

                    // ==========================================
                    // Seção 2: Atribuição de Campanha & Ads
                    // ==========================================
                    html += '<div class="li-modal-box">';
                    html += '<h4><span class="dashicons dashicons-chart-bar"></span> Atribuição de Campanha & Ads</h4>';
                    html += makeRow('Canal de Origem', chanBadge, 'dashicons-share');

                    // Nomes das Campanhas com alto contraste (branco/legível)
                    var campDisplay = lead.campaign_name || lead.utm_campaign;
                    html += makeRow('Campanha', '<span id="liModalCampName" class="li-val-bold">' + (campDisplay ? escH(campDisplay) : '<span class="li-val-empty">—</span>') + '</span>', 'dashicons-megaphone');

                    var adsetDisplay = lead.adset_name || lead.utm_content;
                    html += makeRow('Conjunto / AdSet', '<span id="liModalAdSetName" class="li-val-bold">' + (adsetDisplay ? escH(adsetDisplay) : '<span class="li-val-empty">—</span>') + '</span>', 'dashicons-category');

                    var adDisplay = lead.ad_name || lead.utm_term;
                    html += makeRow('Anúncio', '<span id="liModalAdName" class="li-val-bold">' + (adDisplay ? escH(adDisplay) : '<span class="li-val-empty">—</span>') + '</span>', 'dashicons-format-image');

                    html += '<div class="li-modal-divider"><span>Parâmetros de Rastreamento (UTMs)</span></div>';
                    html += makeRow('utm_source', makeCopyPill(lead.utm_source));
                    html += makeRow('utm_medium', makeCopyPill(lead.utm_medium));
                    html += makeRow('utm_campaign', '<span id="liModalUtmCamp">' + makeCopyPill(lead.utm_campaign) + '</span>');
                    html += makeRow('utm_content', '<span id="liModalUtmContent">' + makeCopyPill(lead.utm_content) + '</span>');
                    html += makeRow('utm_term', '<span id="liModalUtmTerm">' + makeCopyPill(lead.utm_term) + '</span>');

                    html += '<div class="li-modal-divider"><span>Identificadores de Cliques & Anúncios</span></div>';
                    html += makeRow('gclid (Google Ads)', makeCopyPill(lead.gclid));
                    html += makeRow('fbclid / CTWA', makeCopyPill(lead.fbclid));
                    html += makeRow('Campaign ID / Gad', '<span id="liModalCampId">' + makeCopyPill(lead.campaign_id) + '</span>');
                    html += makeRow('AdSet ID', '<span id="liModalAdSetId">' + makeCopyPill(lead.adset_id) + '</span>');
                    html += makeRow('Ad ID', '<span id="liModalAdId">' + makeCopyPill(lead.ad_id) + '</span>');

                    if (lead.ad_id) {
                        html += '<div class="li-meta-sync-card">';
                        html += '<div class="li-meta-sync-title"><span class="dashicons dashicons-facebook" style="font-size:14px; width:14px; height:14px; line-height:14px;"></span> Sincronização com Meta Marketing API</div>';
                        html += '<button type="button" class="li-btn-meta-sync" id="liBtnSyncLeadAd" data-lead-id="' + lead.id + '">';
                        html += '<span class="dashicons dashicons-update" style="font-size:15px; width:15px; height:15px; line-height:15px;"></span> Buscar Dados Oficiais na Meta';
                        html += '</button>';
                        html += '<div id="liSyncLeadResult" style="margin-top:8px; font-size:12px; display:none;"></div>';
                        html += '</div>';
                    }
                    html += '</div>';

                    // ==========================================
                    // Seção 3: Qualificação & Funil
                    // ==========================================
                    var etapaFunil = lead.etapa_funil || (lead.qualificacao_status === 'qualificado' ? 'Matrícula Realizada' : (lead.qualificacao_status === 'nao_qualificado' ? 'Desqualificado' : 'Formulário Enviado'));
                    
                    var statusQualBadge = '<span class="li-pill-qual-wait"><span class="dashicons dashicons-clock"></span> Pendente</span>';
                    if (lead.qualificacao_status === 'qualificado') {
                        statusQualBadge = '<span class="li-pill-qual-ok"><span class="dashicons dashicons-yes-alt"></span> Venda / Qualificado</span>';
                    } else if (lead.qualificacao_status === 'nao_qualificado') {
                        statusQualBadge = '<span class="li-pill-qual-no"><span class="dashicons dashicons-dismiss"></span> Desqualificado</span>';
                    }

                    html += '<div class="li-modal-box">';
                    html += '<h4><span class="dashicons dashicons-star-filled"></span> Qualificação & WhatsApp</h4>';
                    html += makeRow('Status de Qualificação', statusQualBadge, 'dashicons-awards');
                    html += makeRow('Etapa do Funil', '<span class="li-pill-stage">🎯 ' + escH(etapaFunil) + '</span>', 'dashicons-filter');
                    html += makeRow('Data da Qualificação', makeText(lead.qualificacao_data), 'dashicons-calendar');
                    html += makeRow('Origem da Qualificação', makeText(lead.qualificacao_origem), 'dashicons-database');
                    
                    var scoreVal = (lead.score !== undefined && lead.score !== null) ? lead.score : '0';
                    html += makeRow('Score do Lead', '<span class="li-score-badge">★ ' + scoreVal + '</span>', 'dashicons-performance');

                    var waStatusHtml = lead.whatsapp_status 
                        ? '<span class="li-badge" style="background:#f0fdf4; color:#15803d; border:1px solid #bbf7d0; font-weight:600; padding:2px 7px; border-radius:5px;">' + escH(lead.whatsapp_status) + '</span>'
                        : '<span class="li-val-empty">—</span>';
                    html += makeRow('Status WhatsApp', waStatusHtml, 'dashicons-whatsapp');

                    var msgCount = parseInt(lead.mensagens_recebidas, 10) || 0;
                    var msgHtml = msgCount > 0 
                        ? '<span class="li-badge-channel li-badge-whatsapp">💬 ' + msgCount + ' ' + (msgCount === 1 ? 'mensagem' : 'mensagens') + '</span>'
                        : '<span class="li-val-empty">0 mensagens</span>';
                    html += makeRow('Mensagens Recebidas', msgHtml, 'dashicons-format-chat');
                    html += '</div>';

                    // ==========================================
                    // Seção 4: Metadados Técnicos
                    // ==========================================
                    html += '<div class="li-modal-box">';
                    html += '<h4><span class="dashicons dashicons-admin-site-alt3"></span> Metadados Técnicos</h4>';
                    
                    var formDisplay = '<span class="li-val-empty">—</span>';
                    if (lead.formulario_nome) {
                        formDisplay = '<span class="li-val-bold">' + escH(lead.formulario_nome) + '</span>' + (lead.formulario_id ? ' <small style="color:#64748b;">(' + escH(lead.formulario_id) + ')</small>' : '');
                    }
                    html += makeRow('Formulário', formDisplay, 'dashicons-feedback');

                    var pagHtml = '<span class="li-val-empty">—</span>';
                    if (lead.pagina_origem) {
                        pagHtml = '<a href="' + escH(lead.pagina_origem) + '" target="_blank" class="li-link-out" title="' + escH(lead.pagina_origem) + '">' +
                                  '<span>' + escH(truncate(lead.pagina_origem, 34)) + '</span>' +
                                  '<span class="dashicons dashicons-external"></span>' +
                                  '</a>';
                    }
                    html += makeRow('Página de Origem', pagHtml, 'dashicons-admin-links');

                    var refHtml = '<span class="li-val-empty">—</span>';
                    if (lead.referrer) {
                        refHtml = '<span style="color:#94a3b8; font-size:12px;" title="' + escH(lead.referrer) + '">' + escH(truncate(lead.referrer, 34)) + '</span>';
                    }
                    html += makeRow('Referrer Externo', refHtml, 'dashicons-migrate');
                    html += makeRow('Endereço IP', makeCopyPill(lead.ip_address), 'dashicons-networking');
                    
                    var uaHtml = '<span class="li-val-empty">—</span>';
                    if (lead.user_agent) {
                        uaHtml = '<div class="li-ua-box" title="' + escH(lead.user_agent) + '">' + escH(lead.user_agent) + '</div>';
                    }
                    html += makeRow('User Agent', uaHtml, 'dashicons-desktop');
                    html += '</div>';

                    html += '</div>'; // fim li-modal-sections

                    document.getElementById('liModalContent').innerHTML = html;
                    document.getElementById('liLeadModal').style.display = 'flex';

                    // Handler do botão de sincronização individual com a Meta
                    var syncBtn = document.getElementById('liBtnSyncLeadAd');
                    if (syncBtn) {
                        syncBtn.addEventListener('click', function() {
                            var leadId = this.getAttribute('data-lead-id');
                            var resultDiv = document.getElementById('liSyncLeadResult');
                            var btn = this;
                            btn.disabled = true;
                            btn.innerHTML = '<span class="dashicons dashicons-update" style="animation:spin 1s infinite linear;"></span> Consultando Meta...';
                            resultDiv.style.display = 'block';
                            resultDiv.innerHTML = '<span style="color:#94a3b8;">Aguarde, comunicando com a Meta Marketing API...</span>';

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
                                    resultDiv.innerHTML = '<div style="background:rgba(16,185,129,0.15); border:1px solid rgba(16,185,129,0.3); color:#34d399; padding:8px 10px; border-radius:6px; font-weight:500;">✔ ' + (res.data.message || 'Dados sincronizados com sucesso!') + '</div>';
                                    if (document.getElementById('liModalCampName')) document.getElementById('liModalCampName').innerText = l.campaign_name || d.campaign_name || '—';
                                    if (document.getElementById('liModalAdSetName')) document.getElementById('liModalAdSetName').innerText = l.adset_name || d.adset_name || '—';
                                    if (document.getElementById('liModalAdName')) document.getElementById('liModalAdName').innerText = l.ad_name || d.ad_name || '—';
                                    if (document.getElementById('liModalCampId')) document.getElementById('liModalCampId').innerHTML = makeCopyPill(l.campaign_id || d.campaign_id);
                                    if (document.getElementById('liModalAdSetId')) document.getElementById('liModalAdSetId').innerHTML = makeCopyPill(l.adset_id || d.adset_id);
                                    if (document.getElementById('liModalUtmCamp')) document.getElementById('liModalUtmCamp').innerHTML = makeCopyPill(l.utm_campaign || d.campaign_name);
                                    if (document.getElementById('liModalUtmContent')) document.getElementById('liModalUtmContent').innerHTML = makeCopyPill(l.utm_content || d.adset_name);
                                    if (document.getElementById('liModalUtmTerm')) document.getElementById('liModalUtmTerm').innerHTML = makeCopyPill(l.utm_term || d.ad_name);
                                } else {
                                    var err = (res.data && res.data.message) ? res.data.message : 'Erro ao consultar a Meta.';
                                    resultDiv.innerHTML = '<div style="background:rgba(239,68,68,0.15); border:1px solid rgba(239,68,68,0.3); color:#f87171; padding:8px 10px; border-radius:6px;">❌ ' + err + '<br><small style="color:#fca5a5; display:block; margin-top:4px;">Dica: certifique-se de que o token possua a permissão <strong>ads_read</strong> e a Conta de Anúncios esteja associada ao System User no Meta Business Suite.</small></div>';
                                }
                            })
                            .catch(function(e) {
                                btn.disabled = false;
                                btn.innerHTML = '<span class="dashicons dashicons-update"></span> Buscar Dados Oficiais na Meta';
                                resultDiv.innerHTML = '<div style="background:rgba(239,68,68,0.15); border:1px solid rgba(239,68,68,0.3); color:#f87171; padding:8px 10px; border-radius:6px;">❌ Falha na requisição: ' + e.message + '</div>';
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

            // Ordenação dinâmica por clique em qualquer coluna da tabela principal
            var liSortMainState = { col: null, dir: 0 };
            window.liSortMainTable = function(columnKey) {
                if (liSortMainState.col === columnKey) {
                    liSortMainState.dir = (liSortMainState.dir + 1) % 3;
                } else {
                    liSortMainState.col = columnKey;
                    liSortMainState.dir = 1;
                }

                var rows = Array.from(document.querySelectorAll('tr.li-lead-row-main'));
                if (!rows.length) return;

                var tbody = rows[0].parentNode;

                rows.forEach(function(row, idx) {
                    if (!row.hasAttribute('data-original-index')) {
                        row.setAttribute('data-original-index', idx);
                    }
                });

                var tableEl = tbody.closest('table');
                if (tableEl) {
                    var allThs = tableEl.querySelectorAll('th.li-sortable-th, #liThStatusMain');
                    allThs.forEach(function(th) {
                        th.classList.remove('li-sort-active-asc', 'li-sort-active-desc');
                        th.style.background = '';
                        var icon = th.querySelector('.li-sort-icon');
                        if (icon) {
                            icon.innerHTML = '<span style="opacity: 0.55; font-size: 11px;">⇅</span>';
                        }
                        var label = th.querySelector('.li-status-sort-label');
                        if (label && th.id.indexOf('Status') !== -1) {
                            label.textContent = 'Status';
                        }
                    });

                    if (liSortMainState.dir !== 0) {
                        var thId = (columnKey === 'status') ? 'liThStatusMain' : ('liTh_main_' + columnKey);
                        var activeTh = document.getElementById(thId);
                        if (activeTh) {
                            var isAsc = (liSortMainState.dir === 1);
                            activeTh.classList.add(isAsc ? 'li-sort-active-asc' : 'li-sort-active-desc');
                            var iconEl = activeTh.querySelector('.li-sort-icon');

                            var badgeText = '';
                            if (columnKey === 'id') {
                                badgeText = isAsc ? '▼ Recentes' : '▲ Antigos';
                            } else if (columnKey === 'date') {
                                badgeText = isAsc ? '▼ Recentes' : '▲ Antigos';
                            } else if (columnKey === 'name') {
                                badgeText = isAsc ? '▲ A-Z' : '▼ Z-A';
                            } else if (columnKey === 'course') {
                                badgeText = isAsc ? '▲ A-Z' : '▼ Z-A';
                            } else if (columnKey === 'channel') {
                                badgeText = isAsc ? '▲ A-Z' : '▼ Z-A';
                            } else if (columnKey === 'etapa') {
                                badgeText = isAsc ? '🎓 Matrículas' : '❌ Desqualificados';
                            } else if (columnKey === 'status') {
                                var statusLabel = activeTh.querySelector('.li-status-sort-label');
                                if (statusLabel) {
                                    statusLabel.textContent = isAsc ? 'Status (Qualificados)' : 'Status (Desqualificados)';
                                }
                                badgeText = isAsc ? '🟢 Qualificados' : '🔴 Desqualificados';
                            } else {
                                badgeText = isAsc ? '▲ 1º' : '▼ 2º';
                            }

                            if (iconEl) {
                                var badgeClass = isAsc ? 'li-sort-badge li-sort-badge-asc' : 'li-sort-badge li-sort-badge-desc';
                                iconEl.innerHTML = '<span class="' + badgeClass + '">' + badgeText + '</span>';
                            }
                        }
                    }
                }

                if (liSortMainState.dir === 0) {
                    rows.sort(function(a, b) {
                        return parseInt(a.getAttribute('data-original-index'), 10) - parseInt(b.getAttribute('data-original-index'), 10);
                    });
                } else {
                    var isAsc = (liSortMainState.dir === 1);
                    rows.sort(function(a, b) {
                        var res = 0;
                        if (columnKey === 'id') {
                            var idA = parseInt(a.getAttribute('data-lead-id') || '0', 10);
                            var idB = parseInt(b.getAttribute('data-lead-id') || '0', 10);
                            res = isAsc ? (idB - idA) : (idA - idB);
                        } else if (columnKey === 'date') {
                            var dtA = a.getAttribute('data-lead-date-raw') || '';
                            var dtB = b.getAttribute('data-lead-date-raw') || '';
                            res = isAsc ? dtB.localeCompare(dtA) : dtA.localeCompare(dtB);
                        } else if (columnKey === 'name') {
                            var nA = (a.getAttribute('data-lead-name-raw') || '').trim();
                            var nB = (b.getAttribute('data-lead-name-raw') || '').trim();
                            if (!nA && nB) return 1;
                            if (nA && !nB) return -1;
                            res = isAsc ? nA.localeCompare(nB, 'pt-BR') : nB.localeCompare(nA, 'pt-BR');
                        } else if (columnKey === 'course') {
                            var csA = (a.getAttribute('data-lead-course-raw') || '').trim();
                            var csB = (b.getAttribute('data-lead-course-raw') || '').trim();
                            if (!csA && csB) return 1;
                            if (csA && !csB) return -1;
                            res = isAsc ? csA.localeCompare(csB, 'pt-BR') : csB.localeCompare(csA, 'pt-BR');
                        } else if (columnKey === 'channel') {
                            var chA = (a.getAttribute('data-lead-channel-raw') || '').trim();
                            var chB = (b.getAttribute('data-lead-channel-raw') || '').trim();
                            if (!chA && chB) return 1;
                            if (chA && !chB) return -1;
                            res = isAsc ? chA.localeCompare(chB, 'pt-BR') : chB.localeCompare(chA, 'pt-BR');
                        } else if (columnKey === 'etapa') {
                            function getEtapaWeight(e, reverse) {
                                e = (e || '').toLowerCase();
                                if (e.indexOf('matr') !== -1 || e.indexOf('matric') !== -1) return reverse ? 6 : 1;
                                if (e.indexOf('analis') !== -1 || e.indexOf('negoc') !== -1 || e.indexOf('atend') !== -1) return reverse ? 5 : 2;
                                if (e.indexOf('contato') !== -1) return reverse ? 4 : 3;
                                if (e.indexOf('remarket') !== -1) return reverse ? 3 : 4;
                                if (e.indexOf('form') !== -1 || e.indexOf('envia') !== -1) return reverse ? 2 : 5;
                                if (e.indexOf('desqual') !== -1 || e.indexOf('perda') !== -1 || e.indexOf('não') !== -1 || e.indexOf('nao') !== -1) return reverse ? 1 : 6;
                                return reverse ? 3 : 4;
                            }
                            var eA = a.getAttribute('data-lead-etapa') || '';
                            var eB = b.getAttribute('data-lead-etapa') || '';
                            res = getEtapaWeight(eA, !isAsc) - getEtapaWeight(eB, !isAsc);
                        } else if (columnKey === 'status') {
                            function getStatusWeight(st, reverse) {
                                st = (st || 'pendente').toLowerCase();
                                if (reverse) {
                                    if (st === 'nao_qualificado' || st === 'desqualificado') return 1;
                                    if (st === 'pendente') return 2;
                                    return 3;
                                } else {
                                    if (st === 'qualificado') return 1;
                                    if (st === 'pendente') return 2;
                                    return 3;
                                }
                            }
                            var sA = a.getAttribute('data-lead-status') || 'pendente';
                            var sB = b.getAttribute('data-lead-status') || 'pendente';
                            res = getStatusWeight(sA, !isAsc) - getStatusWeight(sB, !isAsc);
                        }

                        if (res !== 0) return res;
                        return parseInt(a.getAttribute('data-original-index'), 10) - parseInt(b.getAttribute('data-original-index'), 10);
                    });
                }

                var fragment = document.createDocumentFragment();
                rows.forEach(function(row) {
                    fragment.appendChild(row);
                });
                tbody.appendChild(fragment);
            };

            window.liSortTableByStatusMain = function() {
                window.liSortMainTable('status');
            };

            // =========================================================================
            // REDIMENSIONADOR DE COLUNAS ESTILO EXCEL (COM PERSISTÊNCIA EM LOCALSTORAGE)
            // =========================================================================
            (function() {
                var table = document.getElementById('liLeadsTable');
                if (!table) return;

                var STORAGE_KEY = 'li_leads_table_col_widths_v3';
                var defaultWidths = {
                    'check': 40,
                    'id': 60,
                    'leadid': 85,
                    'data': 100,
                    'nome': 230,
                    'instancia': 120,
                    'curso': 120,
                    'canal': 150,
                    'utm': 160,
                    'etapa': 150,
                    'status': 110,
                    'acoes': 85
                };

                function updateTableMinWidth() {
                    var ths = table.querySelectorAll('thead th[data-col-id]');
                    var total = 0;
                    ths.forEach(function(th) {
                        var w = parseInt(th.style.width, 10) || th.getBoundingClientRect().width || 100;
                        total += w;
                    });
                    if (total > 0) {
                        table.style.minWidth = total + 'px';
                    }
                }

                function applyWidths(widths) {
                    var ths = table.querySelectorAll('thead th[data-col-id]');
                    ths.forEach(function(th) {
                        var colId = th.getAttribute('data-col-id');
                        var w = (widths && widths[colId]) ? widths[colId] : defaultWidths[colId];
                        if (w) {
                            th.style.width = w + 'px';
                            th.style.minWidth = (colId === 'check' ? 40 : Math.min(w, 60)) + 'px';
                        }
                    });
                    updateTableMinWidth();
                }

                function loadSavedWidths() {
                    try {
                        var saved = localStorage.getItem(STORAGE_KEY);
                        if (saved) {
                            return JSON.parse(saved);
                        }
                    } catch(e) {}
                    return null;
                }

                function saveCurrentWidths() {
                    var widths = {};
                    var ths = table.querySelectorAll('thead th[data-col-id]');
                    ths.forEach(function(th) {
                        var colId = th.getAttribute('data-col-id');
                        var w = Math.round(th.getBoundingClientRect().width);
                        if (colId && w > 0) {
                            widths[colId] = w;
                        }
                    });
                    try {
                        localStorage.setItem(STORAGE_KEY, JSON.stringify(widths));
                    } catch(e) {}
                    updateTableMinWidth();
                }

                var saved = loadSavedWidths();
                if (saved) {
                    applyWidths(saved);
                } else {
                    applyWidths(defaultWidths);
                }

                var resetBtn = document.getElementById('liResetColWidths');
                if (resetBtn) {
                    resetBtn.addEventListener('click', function(e) {
                        e.preventDefault();
                        try {
                            localStorage.removeItem(STORAGE_KEY);
                        } catch(e) {}
                        applyWidths(defaultWidths);
                    });
                }

                var activeTh = null;
                var activeResizer = null;
                var startX = 0;
                var startWidth = 0;
                var minWidth = 50;

                table.querySelectorAll('.li-col-resizer').forEach(function(resizer) {
                    resizer.addEventListener('dblclick', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        var th = resizer.closest('th');
                        if (!th) return;
                        var colId = th.getAttribute('data-col-id');
                        var defW = defaultWidths[colId] || 120;
                        th.style.width = defW + 'px';
                        saveCurrentWidths();
                    });

                    resizer.addEventListener('mousedown', function(e) {
                        e.preventDefault();
                        e.stopPropagation();

                        activeResizer = resizer;
                        activeTh = resizer.closest('th');
                        if (!activeTh) return;

                        startX = e.clientX;
                        startWidth = activeTh.getBoundingClientRect().width;
                        var colId = activeTh.getAttribute('data-col-id');
                        minWidth = colId === 'check' ? 40 : 60;

                        activeResizer.classList.add('is-resizing');
                        document.body.classList.add('li-is-col-resizing');

                        window.addEventListener('mousemove', onMouseMove);
                        window.addEventListener('mouseup', onMouseUp);
                    });
                });

                function onMouseMove(e) {
                    if (!activeTh) return;
                    var delta = e.clientX - startX;
                    var newWidth = Math.max(minWidth, Math.round(startWidth + delta));
                    activeTh.style.width = newWidth + 'px';
                    activeTh.style.minWidth = newWidth + 'px';
                    updateTableMinWidth();
                }

                function onMouseUp() {
                    if (activeResizer) {
                        activeResizer.classList.remove('is-resizing');
                    }
                    document.body.classList.remove('li-is-col-resizing');
                    window.removeEventListener('mousemove', onMouseMove);
                    window.removeEventListener('mouseup', onMouseUp);

                    saveCurrentWidths();
                    activeTh = null;
                    activeResizer = null;
                }
            })();

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
