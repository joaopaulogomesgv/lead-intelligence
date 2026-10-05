<?php
namespace LeadIntelligence\Admin\Controllers;

use LeadIntelligence\Database\LeadRepository;
use LeadIntelligence\PhoneNormalizer;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Controller da listagem de Leads capturados
 */
class LeadsController {

    public static function render() {
        $current_page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : 'lead-intelligence-leads';
        $is_qualificacoes = ($current_page === 'lead-intelligence-qualificacoes');

        $search    = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $status    = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : ($is_qualificacoes ? 'qualificado' : '');
        $page      = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $per_page  = 20;

        $counts = LeadRepository::get_status_counts();

        $leads_data = LeadRepository::get_leads([
            'search'   => $search,
            'status'   => $status,
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
            <div class="li-header">
                <div>
                    <h2>Lead Intelligence &bull; <?php echo esc_html($page_title); ?></h2>
                    <p class="li-subtitle"><?php echo esc_html($page_sub); ?></p>
                </div>
            </div>

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
                    <span class="li-metric-sub">Média geral</span>
                </div>
            </div>

            <!-- BARRA DE BUSCA E FILTROS -->
            <div class="li-card li-filter-bar">
                <form method="get" action="">
                    <input type="hidden" name="page" value="lead-intelligence-leads">

                    <div class="li-filter-row">
                        <div class="li-search-box">
                            <input type="text" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Buscar por nome, e-mail, telefone ou curso...">
                        </div>

                        <select name="status">
                            <option value="">Todos os Status</option>
                            <option value="pendente" <?php selected($status, 'pendente'); ?>>Pendentes</option>
                            <option value="qualificado" <?php selected($status, 'qualificado'); ?>>Qualificados</option>
                            <option value="nao_qualificado" <?php selected($status, 'nao_qualificado'); ?>>Não Qualificados</option>
                            <option value="sem_correspondencia" <?php selected($status, 'sem_correspondencia'); ?>>Sem Correspondência</option>
                        </select>

                        <button type="submit" class="button button-primary">Filtrar</button>
                        <?php if (!empty($search) || !empty($status)): ?>
                            <a href="<?php echo esc_url($current_url); ?>" class="button">Limpar Filtros</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- TABELA DE LEADS -->
            <div class="li-card" style="padding: 0; overflow-x: auto;">
                <table class="wp-list-table widefat fixed striped li-table">
                    <thead>
                        <tr>
                            <th style="width: 60px;">ID</th>
                            <th style="width: 130px;">Data</th>
                            <th>Lead / Contato</th>
                            <th>Curso / Interesse</th>
                            <th>Origem / UTM</th>
                            <th style="width: 130px;">Status</th>
                            <th style="width: 100px;">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($leads)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 40px 20px; color: #64748b;">
                                    <span class="dashicons dashicons-id-alt" style="font-size: 36px; width: 36px; height: 36px; color: #94a3b8; margin-bottom: 10px;"></span>
                                    <p style="font-size: 15px; margin: 0;">Nenhum lead encontrado com os filtros selecionados.</p>
                                    <p style="font-size: 13px; margin: 5px 0 0;">Submeta um formulário no Elementor Pro para ver os dados sendo capturados automaticamente aqui.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($leads as $lead): ?>
                                <tr>
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
                                        <div style="font-size: 12px; font-weight: 500;">
                                            <?php echo esc_html(!empty($lead->formulario_nome) ? $lead->formulario_nome : 'Formulário'); ?>
                                        </div>
                                        <?php if (!empty($lead->utm_campaign) || !empty($lead->utm_source)): ?>
                                            <div style="font-size: 11px; color: #64748b; margin-top: 2px;">
                                                <span title="Campanha">🎯 <?php echo esc_html($lead->utm_campaign ?: $lead->utm_source); ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($lead->fbclid)): ?>
                                            <span class="li-badge-mini" title="Meta Click ID detectado">FB Meta</span>
                                        <?php endif; ?>
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
                                        <button type="button" class="button button-small li-open-modal-btn" data-lead="<?php echo esc_attr(wp_json_encode($lead)); ?>">
                                            Ver Detalhes
                                        </button>
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
                    <span>Página <?php echo $page; ?> de <?php echo $total_pages; ?> (<?php echo $total_leads; ?> leads no total)</span>
                    <div class="li-pagination-links">
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <?php
                            $link = add_query_arg([
                                'paged'  => $i,
                                's'      => $search,
                                'status' => $status,
                            ], $current_url);
                            ?>
                            <a href="<?php echo esc_url($link); ?>" class="button <?php echo ($i === $page) ? 'button-primary' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                    </div>
                </div>
            <?php endif; ?>

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
                    html += '</div>';

                    // Seção 2: UTMs e Atribuição Meta Ads
                    html += '<div class="li-modal-box"><h4>🎯 Parâmetros UTM e Meta Ads</h4>';
                    html += '<p><strong>utm_source:</strong> <code>' + (lead.utm_source || '-') + '</code></p>';
                    html += '<p><strong>utm_medium:</strong> <code>' + (lead.utm_medium || '-') + '</code></p>';
                    html += '<p><strong>utm_campaign:</strong> <code>' + (lead.utm_campaign || '-') + '</code></p>';
                    html += '<p><strong>utm_content:</strong> <code>' + (lead.utm_content || '-') + '</code></p>';
                    html += '<p><strong>utm_term:</strong> <code>' + (lead.utm_term || '-') + '</code></p>';
                    html += '<p><strong>fbclid:</strong> <code>' + (lead.fbclid || '-') + '</code></p>';
                    html += '<p><strong>fbc:</strong> <code>' + (lead.fbc || '-') + '</code></p>';
                    html += '<p><strong>fbp:</strong> <code>' + (lead.fbp || '-') + '</code></p>';
                    if (lead.campaign_id) html += '<p><strong>Campaign ID:</strong> <code>' + lead.campaign_id + '</code></p>';
                    if (lead.adset_id) html += '<p><strong>AdSet ID:</strong> <code>' + lead.adset_id + '</code></p>';
                    if (lead.ad_id) html += '<p><strong>Ad ID:</strong> <code>' + lead.ad_id + '</code></p>';
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
                    html += '<p><strong>Página de Origem:</strong> <a href="' + lead.pagina_origem + '" target="_blank">' + (lead.pagina_origem || '-') + '</a></p>';
                    html += '<p><strong>Endereço IP:</strong> ' + (lead.ip_address || '-') + '</p>';
                    html += '<p><strong>User Agent:</strong> <span style="font-size:11px; word-break:break-all;">' + (lead.user_agent || '-') + '</span></p>';
                    html += '</div>';

                    html += '</div>';

                    document.getElementById('liModalContent').innerHTML = html;
                    document.getElementById('liLeadModal').style.display = 'flex';
                });
            });

            // Fecha ao clicar fora
            window.addEventListener('click', function(e) {
                var modal = document.getElementById('liLeadModal');
                if (e.target === modal) {
                    modal.style.display = 'none';
                }
            });
            </script>
        </div>
        <?php
    }
}
