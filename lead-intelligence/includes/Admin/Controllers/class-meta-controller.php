<?php
namespace LeadIntelligence\Admin\Controllers;

use LeadIntelligence\Admin\Settings;
use LeadIntelligence\MetaCapi\CapiService;
use LeadIntelligence\Database\DbSchema;
use LeadIntelligence\PhoneNormalizer;
use LeadIntelligence\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Controller Administrativo do Módulo Meta Ads & Conversions API
 */
class MetaController {

    public static function render() {
        global $wpdb;
        $settings = Settings::get_settings();
        $test_result = null;

        // Disparo de Evento de Teste
        if (isset($_POST['li_test_capi_ping'])) {
            check_admin_referer('li_capi_test_verify', 'li_nonce');

            $test_pixel = sanitize_text_field(wp_unslash($_POST['test_pixel_id'] ?? $settings['capi_pixel_id']));
            $test_token = sanitize_text_field(wp_unslash($_POST['test_access_token'] ?? $settings['capi_access_token']));
            $test_code  = sanitize_text_field(wp_unslash($_POST['test_event_code'] ?? $settings['capi_test_event_code']));

            if (empty($test_pixel) || empty($test_token)) {
                $test_result = [
                    'success' => false,
                    'message' => 'Pixel ID e Access Token são obrigatórios para realizar o teste.',
                ];
            } else {
                $test_result = CapiService::send_test_ping($test_pixel, $test_token, $test_code);
            }
        }

        // Consulta últimos eventos CAPI registrados no banco
        $leads_table = DbSchema::get_leads_table();
        $sent_events = $wpdb->get_results(
            "SELECT id, nome, telefone_normalizado, email, qualificacao_status, 
                    capi_lead_sent, capi_lead_time, capi_qualified_sent, capi_qualified_time, capi_event_id 
             FROM {$leads_table} 
             WHERE capi_lead_sent = 1 OR capi_qualified_sent = 1 
             ORDER BY updated_at DESC LIMIT 15"
        );

        $total_capi_leads     = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$leads_table} WHERE capi_lead_sent = 1");
        $total_capi_qualified = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$leads_table} WHERE capi_qualified_sent = 1");
        ?>
        <div class="wrap li-wrap">
            <div class="li-header">
                <h2>Lead Intelligence &bull; Meta Ads &amp; Conversions API (CAPI)</h2>
                <p class="li-subtitle">Envie eventos de conversão e qualificação com correspondência avançada (SHA-256) para otimizar os leilões da Meta Ads.</p>
            </div>

            <!-- CARDS DE STATUS CAPI -->
            <div class="li-metric-grid">
                <div class="li-metric-card">
                    <span class="li-metric-label">Status da Conversions API</span>
                    <span class="li-metric-value" style="font-size: 20px; color: <?php echo (!empty($settings['capi_pixel_id']) && !empty($settings['capi_access_token'])) ? '#10b981' : '#f59e0b'; ?>;">
                        <?php echo (!empty($settings['capi_pixel_id']) && !empty($settings['capi_access_token'])) ? 'Pronto / Conectado' : 'Aguardando Configuração'; ?>
                    </span>
                    <span class="li-metric-sub">Graph API v21.0</span>
                </div>
                <div class="li-metric-card li-card-info">
                    <span class="li-metric-label">Eventos 'Lead' Enviados</span>
                    <span class="li-metric-value"><?php echo number_format_i18n($total_capi_leads); ?></span>
                    <span class="li-metric-sub">Captação Elementor</span>
                </div>
                <div class="li-metric-card li-card-success">
                    <span class="li-metric-label">Eventos 'LeadQualified' Enviados</span>
                    <span class="li-metric-value"><?php echo number_format_i18n($total_capi_qualified); ?></span>
                    <span class="li-metric-sub">Matrículas e Alunos Reais</span>
                </div>
            </div>

            <!-- ÁREA DE TESTE EM TEMPO REAL -->
            <div class="li-card" style="border-left: 4px solid #3b82f6;">
                <h3 class="li-card-title">🧪 Testar Envio em Tempo Real no Gerenciador de Eventos da Meta</h3>
                <p class="li-card-desc">
                    Abra o seu <strong>Gerenciador de Eventos da Meta &gt; Seu Pixel &gt; Aba "Testar Eventos"</strong>, copie o código do teste e dispare o evento abaixo para ver o evento aparecendo na hora no painel da Meta:
                </p>

                <?php if ($test_result): ?>
                    <div style="margin-bottom: 18px; padding: 14px; border-radius: 8px; <?php echo $test_result['success'] ? 'background:#dcfce7; color:#15803d; border:1px solid #86efac;' : 'background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5;'; ?>">
                        <strong><?php echo $test_result['success'] ? '✔ Sucesso:' : '❌ Falha:'; ?></strong> <?php echo esc_html($test_result['message']); ?>
                    </div>
                <?php endif; ?>

                <form method="post" action="">
                    <?php wp_nonce_field('li_capi_test_verify', 'li_nonce'); ?>
                    <div style="display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end;">
                        <div style="flex: 1; min-width: 200px;">
                            <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">Pixel / Dataset ID</label>
                            <input type="text" name="test_pixel_id" value="<?php echo esc_attr($settings['capi_pixel_id']); ?>" placeholder="Ex: 123456789012345" required class="regular-text" style="width:100%;">
                        </div>
                        <div style="flex: 2; min-width: 250px;">
                            <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">Access Token da Conversions API</label>
                            <input type="password" name="test_access_token" value="<?php echo esc_attr($settings['capi_access_token']); ?>" placeholder="EAA..." required class="regular-text" style="width:100%;">
                        </div>
                        <div style="flex: 1; min-width: 150px;">
                            <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">Código de Teste (Ex: TEST12345)</label>
                            <input type="text" name="test_event_code" value="<?php echo esc_attr($settings['capi_test_event_code']); ?>" placeholder="TEST..." class="regular-text" style="width:100%;">
                        </div>
                        <div>
                            <button type="submit" name="li_test_capi_ping" class="button button-primary button-large">
                                Disparar Evento Teste &rarr;
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- TABELA DE EVENTOS CAPI DISPACHADOS -->
            <div class="li-card" style="padding: 0; overflow-x: auto;">
                <div style="padding: 18px 24px 10px; border-bottom: 1px solid #e2e8f0;">
                    <h3 class="li-card-title">Últimos Eventos Enviados para a Conversions API</h3>
                </div>

                <table class="wp-list-table widefat fixed striped li-table">
                    <thead>
                        <tr>
                            <th style="width: 70px;">Lead ID</th>
                            <th>Lead / Contato</th>
                            <th style="width: 120px;">Status Qualif.</th>
                            <th style="width: 140px;">Evento Lead</th>
                            <th style="width: 160px;">Evento LeadQualified</th>
                            <th>Event ID (Deduplicação)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($sent_events)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 35px; color: #64748b;">
                                    Nenhum evento foi enviado para a Meta Conversions API ainda. As credenciais podem ser validadas utilizando o botão de teste acima.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($sent_events as $ev): ?>
                                <tr>
                                    <td><strong>#<?php echo esc_html($ev->id); ?></strong></td>
                                    <td>
                                        <strong><?php echo esc_html($ev->nome ?: 'Sem nome'); ?></strong>
                                        <div style="font-size: 12px; color: #64748b;">
                                            <?php echo esc_html(PhoneNormalizer::format_display($ev->telefone_normalizado)); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="li-badge li-status-qualificado">
                                            <?php echo esc_html($ev->qualificacao_status); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($ev->capi_lead_sent)): ?>
                                            <span style="color: #15803d; font-weight: 600; font-size: 12px;">✔ Enviado</span>
                                            <div style="font-size: 11px; color: #64748b;"><?php echo esc_html(date_i18n('d/m H:i', strtotime($ev->capi_lead_time))); ?></div>
                                        <?php else: ?>
                                            <span style="color: #94a3b8; font-size: 12px;">Não enviado</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($ev->capi_qualified_sent)): ?>
                                            <span style="color: #0284c7; font-weight: 600; font-size: 12px;">✔ Qualificado Enviado</span>
                                            <div style="font-size: 11px; color: #64748b;"><?php echo esc_html(date_i18n('d/m H:i', strtotime($ev->capi_qualified_time))); ?></div>
                                        <?php else: ?>
                                            <span style="color: #94a3b8; font-size: 12px;">Pendente</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <code style="font-size: 11px;"><?php echo esc_html($ev->capi_event_id ?: '-'); ?></code>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- GUIA DE OTIMIZAÇÃO DE LEILÃO META ADS -->
            <div class="li-card">
                <h3 class="li-card-title">💡 Como o Evento LeadQualified Otimiza Suas Campanhas na Meta</h3>
                <div style="font-size: 13px; color: #334155; line-height: 1.6;">
                    <p>
                        A grande maioria das campanhas de tráfego pago otimiza apenas para o evento <code>Lead</code> (preenchimento simples de formulário). No entanto, muitos desses leads são curiosos ou desqualificados.
                    </p>
                    <p>
                        Quando nosso plugin dispara o evento <strong><code>LeadQualified</code></strong> (associado à confirmação da matrícula pela planilha ou engajamento no WhatsApp), a Meta Ads recebe o sinal de quem são os <strong>compradores reais</strong>. Com isso:
                    </p>
                    <ul style="margin-left: 20px; list-style-type: disc;">
                        <li>O algoritmo de Machine Learning da Meta passa a buscar no leilão perfis com comportamento idêntico aos seus alunos matriculados.</li>
                        <li>O custo por lead qualificado (CPQ) diminui significativamente.</li>
                        <li>Os identificadores <code>fbc</code>, <code>fbp</code>, <code>em</code>, <code>ph</code>, <code>ip_address</code> e <code>user_agent</code> fornecem uma <strong>taxa de correspondência (Match Quality) excelente</strong>, garantindo atribuição precisa à campanha e anúncio que geraram a venda.</li>
                    </ul>
                </div>
            </div>
        </div>
        <?php
    }
}
