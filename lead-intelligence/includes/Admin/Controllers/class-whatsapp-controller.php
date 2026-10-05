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
        $sim_result = null;

        // Ação 1: Testar Conexão com Graph API
        if (isset($_POST['li_test_waba_conn'])) {
            check_admin_referer('li_waba_test_verify', 'li_nonce');
            $test_result = WabaClient::test_connection();
        }

        // Ação 2: Simulador de Evento Inbound (Teste Local sem precisar da Meta)
        if (isset($_POST['li_simulate_inbound'])) {
            check_admin_referer('li_waba_sim_verify', 'li_nonce');

            $sim_phone = sanitize_text_field(wp_unslash($_POST['sim_phone'] ?? ''));
            $sim_text  = sanitize_text_field(wp_unslash($_POST['sim_text'] ?? 'Olá, gostaria de saber mais sobre a pós!'));

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
                                            'display_phone_number' => '5534999999999',
                                            'phone_number_id'      => $settings['meta_phone_number_id'] ?: 'mock_phone_123',
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
                $sim_result = "Simulação enviada com sucesso para o número <strong>{$norm}</strong>. Verifique o registro na tabela abaixo e o status no Lead correspondente!";
            }
        }

        // Métricas de WhatsApp
        $whatsapp_table = DbSchema::get_whatsapp_table();
        $total_msgs     = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$whatsapp_table}");
        $inbound_msgs   = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$whatsapp_table} WHERE direcao = 'inbound'");
        $linked_leads   = (int) $wpdb->get_var("SELECT COUNT(DISTINCT lead_id) FROM {$whatsapp_table} WHERE lead_id > 0");

        // Últimas 20 mensagens
        $messages = $wpdb->get_results(
            "SELECT m.*, l.nome as lead_nome 
             FROM {$whatsapp_table} m 
             LEFT JOIN " . DbSchema::get_leads_table() . " l ON m.lead_id = l.id 
             ORDER BY m.id DESC LIMIT 20"
        );
        ?>
        <div class="wrap li-wrap">
            <div class="li-header">
                <h2>Lead Intelligence &bull; WhatsApp Cloud API</h2>
                <p class="li-subtitle">Recepção de eventos, histórico de mensagens e vinculação automática de leads por telefone.</p>
            </div>

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
                        <form method="post" action="" style="display: inline;">
                            <?php wp_nonce_field('li_waba_test_verify', 'li_nonce'); ?>
                            <button type="submit" name="li_test_waba_conn" class="button button-secondary">
                                ⚡ Testar Conexão com Graph API
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

            <!-- CARDS DE MÉTRICAS -->
            <div class="li-metric-grid">
                <div class="li-metric-card">
                    <span class="li-metric-label">Total de Mensagens</span>
                    <span class="li-metric-value"><?php echo number_format_i18n($total_msgs); ?></span>
                    <span class="li-metric-sub">Eventos gravados</span>
                </div>
                <div class="li-metric-card li-card-success">
                    <span class="li-metric-label">Mensagens Recebidas</span>
                    <span class="li-metric-value"><?php echo number_format_i18n($inbound_msgs); ?></span>
                    <span class="li-metric-sub">Inbound dos clientes</span>
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
                    <div style="display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end;">
                        <div style="flex: 1; min-width: 220px;">
                            <label for="sim_phone" style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">Telefone do Lead (ex: 34984200518 ou +55 34 98420-0518)</label>
                            <input type="text" id="sim_phone" name="sim_phone" placeholder="DDD + Telefone" required class="regular-text" style="width: 100%;">
                        </div>
                        <div style="flex: 2; min-width: 300px;">
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

            <!-- HISTÓRICO DE MENSAGENS -->
            <div class="li-card" style="padding: 0; overflow-x: auto;">
                <div style="padding: 18px 24px 10px; border-bottom: 1px solid #e2e8f0;">
                    <h3 class="li-card-title">Últimas Mensagens e Eventos Registrados</h3>
                </div>

                <table class="wp-list-table widefat fixed striped li-table">
                    <thead>
                        <tr>
                            <th style="width: 60px;">ID</th>
                            <th style="width: 140px;">Data/Hora</th>
                            <th>Telefone / Lead Vinculado</th>
                            <th style="width: 100px;">Direção</th>
                            <th>Mensagem</th>
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
                                    </td>
                                    <td>
                                        <span class="li-badge <?php echo $m->direcao === 'inbound' ? 'li-status-qualificado' : 'li-status-cinza'; ?>">
                                            <?php echo $m->direcao === 'inbound' ? 'Recebida' : 'Enviada'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span style="font-size: 13px; color: #1e293b;"><?php echo esc_html($m->conteudo); ?></span>
                                    </td>
                                    <td>
                                        <span class="li-badge li-status-cinza">
                                            <?php echo esc_html($m->status_entrega ?: 'ok'); ?>
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
