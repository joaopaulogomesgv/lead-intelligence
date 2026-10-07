<?php
namespace LeadIntelligence\Admin\Controllers;

use LeadIntelligence\Admin\Settings;
use LeadIntelligence\WhatsApp\WabaClient;
use LeadIntelligence\WhatsApp\MessageHandler;
use LeadIntelligence\Database\DbSchema;
use LeadIntelligence\PhoneNormalizer;
use LeadIntelligence\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Controller Administrativo do Módulo WhatsApp Cloud API
 */
class WhatsAppController {

    public static function render() {
        global $wpdb;
        $settings = Settings::get_settings();
        $webhook_url = get_rest_url(null, 'lead-intelligence/v1/meta/webhook');

        $test_result = null;
        $ad_test_result = null;
        $sim_result = null;
        $cleanup_result = null;

        // Ação 1: Testar Conexão com Graph API WhatsApp
        if (isset($_POST['li_test_waba_conn'])) {
            check_admin_referer('li_waba_test_verify', 'li_nonce');
            $target_phone_id = sanitize_text_field(wp_unslash($_POST['test_phone_number_id'] ?? ''));
            $test_result = WabaClient::test_connection($target_phone_id);
        }

        // Ação 1.1: Testar Consulta de Anúncio Meta (Marketing API)
        if (isset($_POST['li_test_ad_conn'])) {
            check_admin_referer('li_ad_test_verify', 'li_nonce');
            $test_ad_id = sanitize_text_field(wp_unslash($_POST['test_ad_id'] ?? ''));
            $ad_test_result = WabaClient::test_ad_details($test_ad_id);
        }

        // Ação 1.2: Sincronizar Todos os Leads Pendentes com a Meta
        if (isset($_POST['li_sync_all_ads'])) {
            check_admin_referer('li_sync_all_ads_verify', 'li_nonce');
            $sync_res = WabaClient::sync_all_pending_leads();
            $cleanup_result = $sync_res['message'];
        }

        // Ação 2: Simulador de Evento Inbound (Teste Local sem precisar da Meta)
        if (isset($_POST['li_simulate_inbound'])) {
            check_admin_referer('li_waba_sim_verify', 'li_nonce');

            $sim_phone   = sanitize_text_field(wp_unslash($_POST['sim_phone'] ?? ''));
            $sim_text    = sanitize_text_field(wp_unslash($_POST['sim_text'] ?? 'Olá, gostaria de saber mais sobre a pós!'));
            $sim_phone_id = sanitize_text_field(wp_unslash($_POST['sim_phone_number_id'] ?? ''));

            if (empty($sim_phone_id)) {
                $sim_phone_id = $settings['meta_phone_number_id'] ?: 'mock_phone_123';
            }

            $polo_info = Settings::find_polo_by_phone_number_id($sim_phone_id);
            $sim_display_phone = !empty($polo_info['display_phone']) ? preg_replace('/\D/', '', $polo_info['display_phone']) : '5534999999999';

            if (!empty($sim_phone)) {
                $norm = PhoneNormalizer::normalize($sim_phone);
                $mock_payload = [
                    'object' => 'whatsapp_business_account',
                    'entry'  => [
                        [
                            'id'      => $settings['meta_waba_id'] ?: 'mock_waba_123',
                            'changes' => [
                                [
                                    'value' => [
                                        'messaging_product' => 'whatsapp',
                                        'metadata'          => [
                                            'display_phone_number' => $sim_display_phone,
                                            'phone_number_id'      => $sim_phone_id,
                                        ],
                                        'contacts' => [
                                            [
                                                'profile' => ['name' => 'Lead de Teste'],
                                                'wa_id'   => $norm,
                                            ]
                                        ],
                                        'messages' => [
                                            [
                                                'from'      => $norm,
                                                'id'        => 'wamid.TEST_' . time(),
                                                'timestamp' => (string) time(),
                                                'type'      => 'text',
                                                'text'      => ['body' => $sim_text],
                                            ]
                                        ]
                                    ],
                                    'field' => 'messages'
                                ]
                            ]
                        ]
                    ]
                ];

                MessageHandler::process_payload($mock_payload);
                $polo_lbl = !empty($polo_info['nome']) ? " [{$polo_info['nome']}]" : "";
                $sim_result = "Simulação enviada com sucesso para o polo<strong>{$polo_lbl}</strong> com o número <strong>{$norm}</strong>. Verifique o registro na tabela abaixo e o status no Lead!";
            }
        }

        // Ação 3: Otimizar Tabela do WhatsApp (remove duplicidades de testes e mensagens repetidas do mesmo contato)
        $whatsapp_table = DbSchema::get_whatsapp_table();
        $leads_table    = DbSchema::get_leads_table();

        if (isset($_POST['li_cleanup_duplicates'])) {
            check_admin_referer('li_cleanup_messages_verify', 'li_nonce');
            $affected = $wpdb->query(
                "DELETE FROM {$whatsapp_table} 
                 WHERE id NOT IN (
                     SELECT max_id FROM (
                         SELECT MAX(id) as max_id 
                         FROM {$whatsapp_table} 
                         GROUP BY telefone_normalizado
                     ) as keep_rows
                 )"
            );
            $cleanup_result = "Tabela otimizada com sucesso! " . intval($affected) . " registro(s) repetido(s) removido(s), mantendo apenas a interação consolidada mais recente de cada lead.";
        }

        // Métricas de WhatsApp Consolidadas
        $total_contatos = (int) $wpdb->get_var("SELECT COUNT(DISTINCT telefone_normalizado) FROM {$whatsapp_table}");
        $total_msgs_cad = (int) $wpdb->get_var("SELECT SUM(mensagens_recebidas) FROM {$leads_table}");
        $total_inbound  = max($total_contatos, $total_msgs_cad);
        $linked_leads   = (int) $wpdb->get_var("SELECT COUNT(DISTINCT lead_id) FROM {$whatsapp_table} WHERE lead_id > 0");
        $latest_ad_id   = $wpdb->get_var("SELECT ad_id FROM {$leads_table} WHERE ad_id IS NOT NULL AND ad_id != '' ORDER BY id DESC LIMIT 1") ?: '120251967573610530';

        // Buscar a interação mais recente de cada Lead / Contato (1 linha por contato para economizar espaço e evitar repetição visual)
        $messages = $wpdb->get_results(
            "SELECT m.*, l.nome as lead_nome, l.mensagens_recebidas, l.whatsapp_status as lead_whatsapp_status 
             FROM {$whatsapp_table} m 
             INNER JOIN (
                 SELECT MAX(id) as max_id 
                 FROM {$whatsapp_table} 
                 GROUP BY telefone_normalizado
             ) latest ON m.id = latest.max_id 
             LEFT JOIN {$leads_table} l ON m.lead_id = l.id 
             ORDER BY m.id DESC LIMIT 25"
        );
        ?>
        <div class="wrap li-wrap">
            <div class="li-header">
                <h2>Lead Intelligence &bull; WhatsApp Cloud API</h2>
                <p class="li-subtitle">Recepção de eventos, métricas de engajamento e vinculação automática de leads por telefone.</p>
            </div>

            <?php if ($cleanup_result): ?>
                <div class="notice notice-success is-dismissible" style="margin-bottom: 20px;"><p>✔ <?php echo esc_html($cleanup_result); ?></p></div>
            <?php endif; ?>

            <!-- CARDS DE STATUS DA INTEGRAÇÃO -->
            <div class="li-card" style="border-left: 4px solid #10b981;">
                <h3 class="li-card-title">🔗 Endpoint do Seu Webhook Próprio</h3>
                <p class="li-card-desc">Este é o endpoint exclusivo do plugin. Ele <strong>NÃO substitui nem interfere</strong> no webhook atual do InterageZap.</p>

                <div style="background: #f8fafc; padding: 14px 18px; border-radius: 8px; border: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; gap: 15px; flex-wrap: wrap;">
                    <div>
                        <div style="font-size: 11px; text-transform: uppercase; color: #64748b; font-weight: 600;">URL do Webhook (Callback URL)</div>
                        <code style="font-size: 14px; color: #0284c7; font-weight: 600;"><?php echo esc_url($webhook_url); ?></code>
                    </div>
                    <div>
                        <button type="button" class="button" onclick="navigator.clipboard.writeText('<?php echo esc_js($webhook_url); ?>'); alert('URL do Webhook copiada!');">
                            Copiar URL
                        </button>
                    </div>
                </div>

                <div style="margin-top: 15px; display: flex; align-items: center; gap: 20px; flex-wrap: wrap;">
                    <div>
                        <span style="font-size: 12px; color: #64748b;">Verify Token Configurado:</span>
                        <code style="font-weight: 700;"><?php echo esc_html($settings['meta_verify_token']); ?></code>
                    </div>
                    <div>
                        <form method="post" action="" style="display: inline-flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                            <?php wp_nonce_field('li_waba_test_verify', 'li_nonce'); ?>
                            <?php $all_polos = Settings::get_phone_numbers(); ?>
                            <?php if (count($all_polos) > 1): ?>
                                <select name="test_phone_number_id" style="font-size: 13px; height: 32px; max-width: 260px;">
                                    <?php foreach ($all_polos as $p): ?>
                                        <option value="<?php echo esc_attr($p['phone_number_id']); ?>">
                                            <?php echo esc_html($p['nome']); ?> (<?php echo esc_html($p['display_phone'] ?: $p['phone_number_id']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                            <button type="submit" name="li_test_waba_conn" class="button button-secondary">
                                ⚡ Testar Conexão com Graph API WhatsApp
                            </button>
                        </form>
                    </div>
                </div>

                <?php if ($test_result): ?>
                    <div style="margin-top: 15px; padding: 12px; border-radius: 6px; <?php echo $test_result['success'] ? 'background:#dcfce7; color:#15803d;' : 'background:#fee2e2; color:#b91c1c;'; ?>">
                        <strong><?php echo $test_result['success'] ? '✔ Sucesso:' : '❌ Erro:'; ?></strong> <?php echo esc_html($test_result['message']); ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- CARD DE DIAGNÓSTICO E ATRIBUIÇÃO DE ANÚNCIOS META ADS -->
            <div class="li-card" style="border-left: 4px solid #0284c7;">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:15px;">
                    <div>
                        <h3 class="li-card-title">🎯 Diagnóstico de Anúncios Meta Ads (Marketing API)</h3>
                        <p class="li-card-desc">Valide se o seu Access Token possui permissão para ler o nome da Campanha, Conjunto e Anúncio a partir do Ad ID gerado pelo WhatsApp (Click-to-WhatsApp).</p>
                    </div>
                    <div>
                        <form method="post" action="" style="display:inline;">
                            <?php wp_nonce_field('li_sync_all_ads_verify', 'li_nonce'); ?>
                            <button type="submit" name="li_sync_all_ads" class="button button-secondary" style="display:inline-flex; align-items:center; gap:6px;">
                                <span class="dashicons dashicons-update"></span> Sincronizar Todos os Leads Pendentes
                            </button>
                        </form>
                    </div>
                </div>

                <form method="post" action="" style="margin-top: 15px; background: #f8fafc; padding: 16px; border-radius: 8px; border: 1px solid #e2e8f0;">
                    <?php wp_nonce_field('li_ad_test_verify', 'li_nonce'); ?>
                    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                        <label for="test_ad_id" style="font-weight: 600; font-size: 13px; color: #1e293b;">Ad ID para Teste:</label>
                        <input type="text" id="test_ad_id" name="test_ad_id" value="<?php echo esc_attr($_POST['test_ad_id'] ?? $latest_ad_id); ?>" class="regular-text" placeholder="Ex: 120251967573610530" required>
                        <button type="submit" name="li_test_ad_conn" class="button button-primary">
                            🔍 Consultar Anúncio na Meta
                        </button>
                    </div>
                    <p style="font-size: 12px; color: #64748b; margin: 8px 0 0;">
                        💡 O Ad ID é enviado pela Meta quando o lead clica no anúncio e abre o WhatsApp. Exemplo recente capturado: <code><?php echo esc_html($latest_ad_id); ?></code>
                    </p>
                </form>

                <?php if ($ad_test_result): ?>
                    <?php if ($ad_test_result['success']): ?>
                        <div style="margin-top: 15px; padding: 14px; border-radius: 6px; background: #dcfce7; border: 1px solid #86efac; color: #166534;">
                            <strong style="font-size: 14px;">✔ Anúncio Encontrado com Sucesso!</strong>
                            <div style="margin-top: 8px; font-size: 13px; display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 10px;">
                                <div><strong>Campanha:</strong> <?php echo esc_html($ad_test_result['data']['campaign_name'] ?: '-'); ?> (<code><?php echo esc_html($ad_test_result['data']['campaign_id'] ?: '-'); ?></code>)</div>
                                <div><strong>Conjunto (AdSet):</strong> <?php echo esc_html($ad_test_result['data']['adset_name'] ?: '-'); ?> (<code><?php echo esc_html($ad_test_result['data']['adset_id'] ?: '-'); ?></code>)</div>
                                <div><strong>Anúncio:</strong> <?php echo esc_html($ad_test_result['data']['ad_name'] ?: '-'); ?> (<code><?php echo esc_html($ad_test_result['data']['ad_id'] ?: '-'); ?></code>)</div>
                                <div><strong>Token Validado:</strong> <code><?php echo esc_html($ad_test_result['token_label'] ?? 'OK'); ?></code></div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div style="margin-top: 15px; padding: 14px; border-radius: 6px; background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b;">
                            <strong style="font-size: 14px;">❌ Falha na Consulta do Anúncio: <?php echo esc_html($ad_test_result['message']); ?></strong>
                            <?php if (!empty($ad_test_result['attempts'])): ?>
                                <ul style="margin: 8px 0 10px 18px; font-size: 12px;">
                                    <?php foreach ($ad_test_result['attempts'] as $att): ?>
                                        <li><strong><?php echo esc_html($att['token']); ?>:</strong> HTTP <?php echo esc_html($att['http_code'] ?? 'ERR'); ?> &mdash; <?php echo esc_html($att['error']); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            <div style="background: #fff; padding: 12px; border-radius: 6px; border: 1px solid #fecaca; color: #334155; font-size: 12px; line-height: 1.5; margin-top: 10px;">
                                <strong style="color: #991b1b;">Como Resolver no Meta Business Manager:</strong>
                                <ol style="margin: 6px 0 0 18px; padding: 0;">
                                    <li>Acesse as <strong>Configurações do Negócio</strong> no Meta Business Suite (<a href="https://business.facebook.com/settings/" target="_blank">business.facebook.com/settings</a>).</li>
                                    <li>Em <strong>Usuários &gt; Usuários do Sistema</strong>, selecione o usuário do sistema do seu token.</li>
                                    <li>Clique em <strong>Gerar Novo Token</strong> (ou edite o atual) e marque a permissão obrigatória: <strong><code>ads_read</code></strong> (além de <code>whatsapp_business_messaging</code>).</li>
                                    <li>Em <strong>Contas &gt; Contas de Anúncios</strong>, selecione sua Conta de Anúncios da Faveni, clique em <strong>Adicionar Pessoas</strong> e vincule esse Usuário do Sistema com permissão para <em>"Ver desempenho"</em> ou <em>"Gerenciar campanhas"</em>.</li>
                                    <li>Cole o novo token nas <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-settings')); ?>">Configurações do Lead Intelligence</a> e clique em Salvar.</li>
                                </ol>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- CARDS DE MÉTRICAS -->
            <div class="li-metric-grid">
                <div class="li-metric-card">
                    <span class="li-metric-label">Contatos WhatsApp</span>
                    <span class="li-metric-value"><?php echo number_format_i18n($total_contatos); ?></span>
                    <span class="li-metric-sub">Leads únicos em contato</span>
                </div>
                <div class="li-metric-card li-card-success">
                    <span class="li-metric-label">Mensagens Recebidas</span>
                    <span class="li-metric-value"><?php echo number_format_i18n($total_inbound); ?></span>
                    <span class="li-metric-sub">Total acumulado de msgs</span>
                </div>
                <div class="li-metric-card li-card-info">
                    <span class="li-metric-label">Leads Vinculados</span>
                    <span class="li-metric-value"><?php echo number_format_i18n($linked_leads); ?></span>
                    <span class="li-metric-sub">Elementor ↔ WhatsApp</span>
                </div>
            </div>

            <!-- SIMULADOR LOCAL DE MENSAGENS -->
            <div class="li-card">
                <h3 class="li-card-title">🧪 Simulador de Mensagem Inbound (Teste Rápido de Vinculação)</h3>
                <p class="li-card-desc">Teste como o sistema normaliza o telefone e vincula automaticamente a conversa a um lead já capturado pelo Elementor, sem precisar aguardar um evento real da Meta.</p>

                <?php if ($sim_result): ?>
                    <div class="notice notice-success is-dismissible" style="margin: 0 0 16px 0;"><p><?php echo wp_kses_post($sim_result); ?></p></div>
                <?php endif; ?>

                <form method="post" action="">
                    <?php wp_nonce_field('li_waba_sim_verify', 'li_nonce'); ?>
                    <?php $sim_polos = Settings::get_phone_numbers(); ?>
                    <div style="display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end;">
                        <?php if (!empty($sim_polos)): ?>
                            <div style="flex: 1; min-width: 180px;">
                                <label for="sim_phone_number_id" style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">Polo Destino</label>
                                <select id="sim_phone_number_id" name="sim_phone_number_id" style="width: 100%; height: 36px;">
                                    <?php foreach ($sim_polos as $p): ?>
                                        <option value="<?php echo esc_attr($p['phone_number_id']); ?>" <?php selected(!empty($p['is_default'])); ?>>
                                            <?php echo esc_html($p['nome']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                        <div style="flex: 1; min-width: 200px;">
                            <label for="sim_phone" style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">Telefone do Lead (ex: 34984200518)</label>
                            <input type="text" id="sim_phone" name="sim_phone" placeholder="DDD + Telefone" required class="regular-text" style="width: 100%;">
                        </div>
                        <div style="flex: 2; min-width: 260px;">
                            <label for="sim_text" style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">Conteúdo da Mensagem</label>
                            <input type="text" id="sim_text" name="sim_text" value="Olá! Preenchi o formulário no site e quero informações sobre a pós-graduação." class="regular-text" style="width: 100%;">
                        </div>
                        <div>
                            <button type="submit" name="li_simulate_inbound" class="button button-primary button-large">
                                Simular Recebimento &rarr;
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- HISTÓRICO CONSOLIDADO DE INTERAÇÕES -->
            <div class="li-card" style="padding: 0; overflow-x: auto;">
                <div style="padding: 18px 24px; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
                    <div>
                        <h3 class="li-card-title" style="margin-bottom: 2px;">Últimas Interações de Leads no WhatsApp</h3>
                        <p class="li-card-desc" style="margin: 0; font-size: 12px;">Visualização consolidada: 1 linha por lead/contato com a última mensagem e contador de engajamento.</p>
                    </div>
                    <div>
                        <form method="post" action="" style="display: inline;" onsubmit="return confirm('Deseja otimizar o banco de dados removendo mensagens repetidas antigas e mantendo apenas a interação mais recente de cada lead?');">
                            <?php wp_nonce_field('li_cleanup_messages_verify', 'li_nonce'); ?>
                            <button type="submit" name="li_cleanup_duplicates" class="button button-secondary" title="Remove mensagens repetidas antigas para economizar espaço no MySQL">
                                🧹 Otimizar Tabela (Remover Repetições)
                            </button>
                        </form>
                    </div>
                </div>

                <table class="wp-list-table widefat fixed striped li-table">
                    <thead>
                        <tr>
                            <th style="width: 60px;">ID</th>
                            <th style="width: 140px;">Última Interação</th>
                            <th>Telefone / Lead Vinculado</th>
                            <th>Última Mensagem</th>
                            <th style="width: 140px; text-align: center;">Total Mensagens</th>
                            <th style="width: 110px;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($messages)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 35px; color: #64748b;">
                                    Nenhuma mensagem do WhatsApp registrada ainda. Configure o webhook no Meta Developers ou utilize o simulador acima para testar.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($messages as $m): ?>
                                <tr>
                                    <td>#<?php echo esc_html($m->id); ?></td>
                                    <td style="font-size: 12px; color: #64748b;">
                                        <?php echo esc_html(date_i18n('d/m/Y H:i', strtotime($m->created_at))); ?>
                                    </td>
                                    <td>
                                        <div style="font-weight: 600;">
                                            📞 <?php echo esc_html(PhoneNormalizer::format_display($m->telefone_normalizado)); ?>
                                        </div>
                                        <?php if (!empty($m->lead_id)): ?>
                                            <div style="font-size: 12px; color: #0284c7; margin-top: 2px;">
                                                <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-leads&s=' . urlencode($m->telefone_normalizado))); ?>">
                                                    🔗 Lead #<?php echo esc_html($m->lead_id); ?> (<?php echo esc_html($m->lead_nome ?: 'Ver lead'); ?>)
                                                </a>
                                            </div>
                                        <?php else: ?>
                                            <span style="font-size: 11px; color: #94a3b8;">Lead avulso</span>
                                        <?php endif; ?>
                                        <?php if (!empty($m->polo)): ?>
                                            <div style="margin-top: 4px;">
                                                <span class="li-badge" style="background: #eff6ff; color: #1d4ed8; font-size: 11px; border: 1px solid #bfdbfe;">
                                                    🏢 <?php echo esc_html($m->polo); ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span style="font-size: 13px; color: #1e293b;"><?php echo esc_html($m->conteudo); ?></span>
                                    </td>
                                    <td style="text-align: center;">
                                        <?php 
                                        $tot_msgs = !empty($m->mensagens_recebidas) ? (int) $m->mensagens_recebidas : 1; 
                                        ?>
                                        <span class="li-badge li-status-cinza" style="font-weight: 600;">
                                            💬 <?php echo esc_html($tot_msgs); ?> <?php echo $tot_msgs === 1 ? 'mensagem' : 'mensagens'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="li-badge <?php echo (!empty($m->lead_whatsapp_status) && $m->lead_whatsapp_status === 'conversando') || $m->status_entrega === 'received' ? 'li-status-qualificado' : 'li-status-cinza'; ?>">
                                            <?php echo esc_html(ucfirst($m->lead_whatsapp_status ?: $m->status_entrega ?: 'Ativo')); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- GUIA PASSO A PASSO OFICIAL DA META -->
            <div class="li-card">
                <h3 class="li-card-title">📖 Guia Oficial de Conexão no Meta Developers</h3>
                <div style="font-size: 13px; color: #334155; line-height: 1.6;">
                    <ol style="margin-left: 20px; padding: 0;">
                        <li>Acesse o <strong><a href="https://developers.facebook.com/apps/" target="_blank">Meta for Developers</a></strong> e selecione seu <strong>Novo Meta App</strong> (tipo Empresa/Negócios).</li>
                        <li>No menu lateral, adicione o produto <strong>WhatsApp</strong> e clique em <strong>Configuração (Configuration)</strong>.</li>
                        <li>No campo <strong>URL de Retorno de Chamada (Callback URL)</strong>, cole: <code><?php echo esc_url($webhook_url); ?></code></li>
                        <li>No campo <strong>Token de Verificação (Verify Token)</strong>, informe: <code><?php echo esc_html($settings['meta_verify_token']); ?></code></li>
                        <li>Clique em <strong>Verificar e Salvar</strong>. A Meta fará uma chamada GET imediata e nosso plugin responderá com o challenge confirmando a ativação.</li>
                        <li>Em <strong>Campos do Webhook (Webhook Fields)</strong>, clique em <strong>Gerenciar</strong> e ative a subscrição de <strong><code>messages</code></strong>.</li>
                        <li><em>Observação Importante:</em> O webhook do InterageZap continuará funcionando sem qualquer alteração no Meta App atual dele. A Meta despacha cópias independentes dos eventos para cada app subscrito à mesma WABA.</li>
                    </ol>
                </div>
            </div>
        </div>
        <?php
    }
}
