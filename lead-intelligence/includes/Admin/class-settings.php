<?php
namespace LeadIntelligence\Admin;

use LeadIntelligence\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gerenciador de Configurações do Plugin
 */
class Settings {

    public static function init() {
        add_action('admin_init', [__CLASS__, 'handle_save']);
    }

    public static function get_settings() {
        $defaults = [
            'enable_elementor_capture' => 1,
            'enable_debug_logging'     => 1,
            'utm_preservation'         => 1,
            'field_mapping'            => [
                'nome'           => 'nome,name,your-name,first_name,nome_completo',
                'email'          => 'email,e-mail,your-email,seumelhor_email',
                'telefone'       => 'telefone,phone,whatsapp,tel,celular,fone,contato',
                'tipo_curso'     => 'tipo_curso,curso,modalidade,tipo,nivel',
                'area_interesse' => 'area_interesse,area,interesse,curso_interesse,especializacao',
            ],
            'meta_app_id'              => '',
            'meta_app_secret'          => '',
            'meta_waba_id'             => '',
            'meta_phone_number_id'     => '',
            'meta_access_token'        => '',
            'meta_verify_token'        => wp_generate_password(24, false),
            'meta_graph_version'       => 'v26.0',
            'capi_pixel_id'            => '',
            'capi_access_token'        => '',
            'capi_test_event_code'     => '',
            'enable_capi_lead'         => 0,
            'enable_capi_qualified'    => 0,
        ];

        $saved = get_option('lead_intelligence_settings', []);
        return wp_parse_args($saved, $defaults);
    }

    public static function handle_save() {
        if (!isset($_POST['li_save_settings'])) {
            return;
        }

        if (!check_admin_referer('li_settings_verify', 'li_nonce')) {
            wp_die('Erro de verificação de segurança (nonce inválido).');
        }

        if (!current_user_can('manage_options')) {
            wp_die('Permissão insuficiente.');
        }

        $current = self::get_settings();

        $enable_elementor = isset($_POST['enable_elementor_capture']) ? 1 : 0;
        $enable_debug     = isset($_POST['enable_debug_logging']) ? 1 : 0;
        $utm_preservation = isset($_POST['utm_preservation']) ? 1 : 0;

        $field_mapping = [
            'nome'           => sanitize_text_field($_POST['field_mapping_nome'] ?? ''),
            'email'          => sanitize_text_field($_POST['field_mapping_email'] ?? ''),
            'telefone'       => sanitize_text_field($_POST['field_mapping_telefone'] ?? ''),
            'tipo_curso'     => sanitize_text_field($_POST['field_mapping_tipo_curso'] ?? ''),
            'area_interesse' => sanitize_text_field($_POST['field_mapping_area_interesse'] ?? ''),
        ];

        // Meta Cloud API
        $meta_app_id          = sanitize_text_field($_POST['meta_app_id'] ?? '');
        $meta_waba_id         = sanitize_text_field($_POST['meta_waba_id'] ?? '');
        $meta_phone_number_id = sanitize_text_field($_POST['meta_phone_number_id'] ?? '');
        $meta_verify_token    = sanitize_text_field($_POST['meta_verify_token'] ?? '');
        $meta_graph_version   = sanitize_text_field($_POST['meta_graph_version'] ?? 'v21.0');

        // Preserva tokens sensíveis se vier mascarado
        $meta_app_secret   = sanitize_text_field($_POST['meta_app_secret'] ?? '');
        if (strpos($meta_app_secret, '••••') !== false || empty($meta_app_secret)) {
            $meta_app_secret = $current['meta_app_secret'];
        }

        $meta_access_token = sanitize_text_field($_POST['meta_access_token'] ?? '');
        if (strpos($meta_access_token, '••••') !== false || empty($meta_access_token)) {
            $meta_access_token = $current['meta_access_token'];
        }

        // Meta Conversions API
        $capi_pixel_id        = sanitize_text_field($_POST['capi_pixel_id'] ?? '');
        $capi_test_event_code = sanitize_text_field($_POST['capi_test_event_code'] ?? '');
        $enable_capi_lead     = isset($_POST['enable_capi_lead']) ? 1 : 0;
        $enable_capi_qual     = isset($_POST['enable_capi_qualified']) ? 1 : 0;

        $capi_access_token    = sanitize_text_field($_POST['capi_access_token'] ?? '');
        if (strpos($capi_access_token, '••••') !== false || empty($capi_access_token)) {
            $capi_access_token = $current['capi_access_token'];
        }

        $new_settings = [
            'enable_elementor_capture' => $enable_elementor,
            'enable_debug_logging'     => $enable_debug,
            'utm_preservation'         => $utm_preservation,
            'field_mapping'            => $field_mapping,
            'meta_app_id'              => $meta_app_id,
            'meta_app_secret'          => $meta_app_secret,
            'meta_waba_id'             => $meta_waba_id,
            'meta_phone_number_id'     => $meta_phone_number_id,
            'meta_access_token'        => $meta_access_token,
            'meta_verify_token'        => !empty($meta_verify_token) ? $meta_verify_token : $current['meta_verify_token'],
            'meta_graph_version'       => $meta_graph_version,
            'capi_pixel_id'            => $capi_pixel_id,
            'capi_access_token'        => $capi_access_token,
            'capi_test_event_code'     => $capi_test_event_code,
            'enable_capi_lead'         => $enable_capi_lead,
            'enable_capi_qualified'    => $enable_capi_qual,
        ];

        update_option('lead_intelligence_settings', $new_settings);
        Logger::info('Configurações', 'Configurações do Lead Intelligence atualizadas pelo administrador.');

        add_settings_error('li_messages', 'li_message', 'Configurações salvas com sucesso!', 'updated');
    }

    public static function render() {
        $settings = self::get_settings();
        $webhook_url = get_rest_url(null, 'lead-intelligence/v1/meta/webhook');
        ?>
        <div class="wrap li-wrap">
            <div class="li-header">
                <h2>Lead Intelligence &bull; Configurações</h2>
                <p class="li-subtitle">Controle de captura de formulários Elementor, mapeamento de campos e integrações Meta.</p>
            </div>

            <?php settings_errors('li_messages'); ?>

            <form method="post" action="">
                <?php wp_nonce_field('li_settings_verify', 'li_nonce'); ?>

                <div class="li-card">
                    <h3 class="li-card-title">1. Captura do Elementor Pro</h3>
                    <p class="li-card-desc">Nosso plugin captura automaticamente os formulários do Elementor sem alterar nem interferir no webhook atual da sua empresa (InterageZap).</p>

                    <table class="form-table">
                        <tr>
                            <th scope="row">Ativar Captura do Elementor</th>
                            <td>
                                <label class="li-switch">
                                    <input type="checkbox" name="enable_elementor_capture" value="1" <?php checked($settings['enable_elementor_capture'], 1); ?>>
                                    <span class="li-slider"></span>
                                </label>
                                <p class="description">Quando ativo, toda submissão de formulário do Elementor Pro é salva no banco próprio de leads.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Modo Debug / Logs Detalhados</th>
                            <td>
                                <label class="li-switch">
                                    <input type="checkbox" name="enable_debug_logging" value="1" <?php checked($settings['enable_debug_logging'], 1); ?>>
                                    <span class="li-slider"></span>
                                </label>
                                <p class="description">Registra detalhes adicionais de payloads e eventos na aba de Logs.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Preservar UTMs e Meta Clicks</th>
                            <td>
                                <label class="li-switch">
                                    <input type="checkbox" name="utm_preservation" value="1" <?php checked($settings['utm_preservation'], 1); ?>>
                                    <span class="li-slider"></span>
                                </label>
                                <p class="description">Guarda automaticamente <code>utm_source</code>, <code>utm_medium</code>, <code>utm_campaign</code>, <code>utm_content</code>, <code>utm_term</code>, <code>fbclid</code>, <code>fbc</code> e <code>fbp</code> nos cookies e injeta nos formulários do Elementor durante a navegação.</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="li-card">
                    <h3 class="li-card-title">2. Mapeamento Flexível de Campos (De-Para)</h3>
                    <p class="li-card-desc">Informe os identificadores (IDs) ou rótulos dos campos usados nos seus formulários do Elementor, separados por vírgula. O sistema identifica automaticamente sem que você precise alterar seus formulários.</p>

                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="field_mapping_nome">Nome do Lead</label></th>
                            <td>
                                <input type="text" id="field_mapping_nome" name="field_mapping_nome" value="<?php echo esc_attr($settings['field_mapping']['nome']); ?>" class="regular-text">
                                <p class="description">Ex: <code>nome, name, your-name, nome_completo, primeiro_nome</code></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="field_mapping_email">E-mail</label></th>
                            <td>
                                <input type="text" id="field_mapping_email" name="field_mapping_email" value="<?php echo esc_attr($settings['field_mapping']['email']); ?>" class="regular-text">
                                <p class="description">Ex: <code>email, e-mail, your-email, seumelhor_email</code></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="field_mapping_telefone">Telefone / WhatsApp</label></th>
                            <td>
                                <input type="text" id="field_mapping_telefone" name="field_mapping_telefone" value="<?php echo esc_attr($settings['field_mapping']['telefone']); ?>" class="regular-text">
                                <p class="description">Ex: <code>telefone, phone, whatsapp, tel, celular, fone</code></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="field_mapping_tipo_curso">Tipo de Curso</label></th>
                            <td>
                                <input type="text" id="field_mapping_tipo_curso" name="field_mapping_tipo_curso" value="<?php echo esc_attr($settings['field_mapping']['tipo_curso']); ?>" class="regular-text">
                                <p class="description">Ex: <code>tipo_curso, curso, modalidade, tipo, pos_graduacao, graduacao</code></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="field_mapping_area_interesse">Área de Interesse</label></th>
                            <td>
                                <input type="text" id="field_mapping_area_interesse" name="field_mapping_area_interesse" value="<?php echo esc_attr($settings['field_mapping']['area_interesse']); ?>" class="regular-text">
                                <p class="description">Ex: <code>area_interesse, area, interesse, especializacao, curso_interesse</code></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="li-card">
                    <h3 class="li-card-title">3. WhatsApp Cloud API / Meta App (Fase 5 - Preparado)</h3>
                    <p class="li-card-desc">Configuração do novo Meta App independente para testar o recebimento dos eventos da WABA sem tocar no InterageZap.</p>

                    <table class="form-table">
                        <tr>
                            <th scope="row">URL do Webhook Próprio</th>
                            <td>
                                <input type="text" readonly value="<?php echo esc_url($webhook_url); ?>" class="large-text" style="background:#f1f5f9; font-family: monospace;">
                                <p class="description">Cadastre esta URL no seu Meta App em <strong>WhatsApp &gt; Configuração &gt; URL de Retorno de Chamada</strong>.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="meta_verify_token">Verify Token</label></th>
                            <td>
                                <input type="text" id="meta_verify_token" name="meta_verify_token" value="<?php echo esc_attr($settings['meta_verify_token']); ?>" class="regular-text" style="font-family: monospace;">
                                <p class="description">Token de verificação exigido pela Meta na validação do webhook.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="meta_app_id">Meta App ID</label></th>
                            <td>
                                <input type="text" id="meta_app_id" name="meta_app_id" value="<?php echo esc_attr($settings['meta_app_id']); ?>" class="regular-text">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="meta_app_secret">Meta App Secret</label></th>
                            <td>
                                <input type="password" id="meta_app_secret" name="meta_app_secret" value="<?php echo !empty($settings['meta_app_secret']) ? '••••••••••••••••' : ''; ?>" class="regular-text">
                                <p class="description">Usado para validação criptográfica da assinatura <code>X-Hub-Signature-256</code>.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="meta_waba_id">WABA ID (WhatsApp Business Account)</label></th>
                            <td>
                                <input type="text" id="meta_waba_id" name="meta_waba_id" value="<?php echo esc_attr($settings['meta_waba_id']); ?>" class="regular-text">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="meta_phone_number_id">Phone Number ID</label></th>
                            <td>
                                <input type="text" id="meta_phone_number_id" name="meta_phone_number_id" value="<?php echo esc_attr($settings['meta_phone_number_id']); ?>" class="regular-text">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="meta_access_token">Access Token do Sistema (System User)</label></th>
                            <td>
                                <input type="password" id="meta_access_token" name="meta_access_token" value="<?php echo !empty($settings['meta_access_token']) ? '••••••••••••••••' : ''; ?>" class="large-text">
                                <p class="description">
                                    Token permanente gerado no Business Manager da Meta.<br>
                                    <strong style="color: #0284c7;">📌 Captura Automática de Campanhas:</strong> Para que o plugin puxe automaticamente os nomes de <strong>Campanha, Conjunto (AdSet) e Anúncio</strong> dos leads de Click-to-WhatsApp, este Token deve pertencer a um Usuário do Sistema com a permissão <code>ads_read</code> (além das de WhatsApp) e a sua Conta de Anúncios deve estar vinculada a ele no Meta Business Suite.
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="meta_graph_version">Versão da Graph API</label></th>
                            <td>
                                <input type="text" id="meta_graph_version" name="meta_graph_version" value="<?php echo esc_attr($settings['meta_graph_version']); ?>" class="small-text">
                                <p class="description">Padrão recomendado: <code>v26.0</code></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="li-card">
                    <h3 class="li-card-title">4. Meta Conversions API (CAPI)</h3>
                    <p class="li-card-desc">Envio direto de eventos de conversão e qualificação via servidor para o Pixel/Dataset da Meta com correspondência avançada hasheada em SHA-256.</p>

                    <table class="form-table">
                        <tr>
                            <th scope="row">Ativar Envio de 'Lead'</th>
                            <td>
                                <label class="li-switch">
                                    <input type="checkbox" name="enable_capi_lead" value="1" <?php checked($settings['enable_capi_lead'], 1); ?>>
                                    <span class="li-slider"></span>
                                </label>
                                <p class="description">Dispara o evento <code>Lead</code> para a Meta assim que o formulário do Elementor é submetido.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Ativar Envio de 'LeadQualified'</th>
                            <td>
                                <label class="li-switch">
                                    <input type="checkbox" name="enable_capi_qualified" value="1" <?php checked($settings['enable_capi_qualified'], 1); ?>>
                                    <span class="li-slider"></span>
                                </label>
                                <p class="description">Dispara o evento <code>LeadQualified</code> para a Meta quando o lead é confirmado na planilha de qualificação ou no WhatsApp.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="capi_pixel_id">Pixel ID / Dataset ID</label></th>
                            <td>
                                <input type="text" id="capi_pixel_id" name="capi_pixel_id" value="<?php echo esc_attr($settings['capi_pixel_id']); ?>" class="regular-text" placeholder="Ex: 123456789012345">
                                <p class="description">Identificador numérico do Pixel/Dataset no Gerenciador de Eventos da Meta.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="capi_access_token">Access Token da Conversions API</label></th>
                            <td>
                                <input type="password" id="capi_access_token" name="capi_access_token" value="<?php echo !empty($settings['capi_access_token']) ? '••••••••••••••••' : ''; ?>" class="large-text">
                                <p class="description">Token gerado em: Gerenciador de Eventos &gt; Configurações &gt; API de Conversões &gt; Gerar Token de Acesso.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="capi_test_event_code">Código de Teste (Opcional)</label></th>
                            <td>
                                <input type="text" id="capi_test_event_code" name="capi_test_event_code" value="<?php echo esc_attr($settings['capi_test_event_code']); ?>" class="regular-text" placeholder="Ex: TEST12345">
                                <p class="description">Código copiado da aba "Testar Eventos" do Gerenciador de Eventos da Meta. Limpe este campo quando for rodar em produção.</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <p class="submit">
                    <input type="submit" name="li_save_settings" id="submit" class="button button-primary button-large" value="Salvar Configurações">
                </p>
            </form>
        </div>
        <?php
    }
}
