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
                'polo'           => 'polo,unidade,cidade,polo_apoio,campus,local',
            ],
            'meta_app_id'              => '',
            'meta_app_secret'          => '',
            'meta_waba_id'             => '',
            'meta_phone_number_id'     => '',
            'meta_phone_numbers'       => [],
            'meta_access_token'        => '',
            'meta_verify_token'        => wp_generate_password(24, false),
            'meta_graph_version'       => 'v26.0',
            'capi_pixel_id'            => '',
            'capi_access_token'        => '',
            'capi_test_event_code'     => '',
            'enable_capi_lead'         => 0,
            'enable_capi_qualified'    => 0,
            'logo_dark'                => '',
            'logo_light'               => '',
            'logo_compact'             => '',
        ];

        $saved = get_option('lead_intelligence_settings', []);
        return wp_parse_args($saved, $defaults);
    }

    /**
     * Retorna a lista configurada de polos e números de WhatsApp via PoloRepository
     *
     * @return array
     */
    public static function get_phone_numbers() {
        return \LeadIntelligence\WhatsApp\PoloRepository::get_all();
    }

    /**
     * Localiza o polo pelo Phone Number ID recebido da Meta
     *
     * @param string $phone_number_id
     * @return array|null
     */
    public static function find_polo_by_phone_number_id($phone_number_id) {
        return \LeadIntelligence\WhatsApp\PoloRepository::find_by_phone_number_id($phone_number_id);
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
            'polo'           => sanitize_text_field($_POST['field_mapping_polo'] ?? ''),
        ];

        // Meta Cloud API
        $meta_app_id          = sanitize_text_field($_POST['meta_app_id'] ?? '');
        $meta_waba_id         = sanitize_text_field($_POST['meta_waba_id'] ?? '');
        $meta_phone_number_id = sanitize_text_field($_POST['meta_phone_number_id'] ?? '');
        $meta_verify_token    = sanitize_text_field($_POST['meta_verify_token'] ?? '');
        $meta_graph_version   = sanitize_text_field($_POST['meta_graph_version'] ?? 'v26.0');

        // Múltiplos Polos & Números da Meta Cloud API
        $meta_polos = [];
        $raw_polos = $_POST['meta_polos'] ?? [];
        $default_phone_id = '';

        if (is_array($raw_polos)) {
            $has_default = false;
            foreach ($raw_polos as $idx => $p) {
                $p_nome = sanitize_text_field($p['nome'] ?? '');
                $p_id   = sanitize_text_field($p['phone_number_id'] ?? '');
                $p_disp = sanitize_text_field($p['display_phone'] ?? '');
                $p_def  = !empty($p['is_default']) ? 1 : 0;

                if (empty($p_nome) && empty($p_id)) {
                    continue;
                }

                if (empty($p_nome)) {
                    $p_nome = 'Polo ' . ($idx + 1);
                }

                if ($p_def) {
                    $has_default = true;
                    $default_phone_id = $p_id;
                }

                $meta_polos[] = [
                    'id'              => 'polo_' . ($idx + 1),
                    'nome'            => $p_nome,
                    'phone_number_id' => $p_id,
                    'display_phone'   => $p_disp,
                    'is_default'      => $p_def,
                ];
            }

            if (!$has_default && !empty($meta_polos)) {
                $meta_polos[0]['is_default'] = 1;
                $default_phone_id = $meta_polos[0]['phone_number_id'];
            }
        }

        // Fallback para manter o campo simples legado atualizado
        if (empty($default_phone_id) && !empty($meta_polos)) {
            $default_phone_id = $meta_polos[0]['phone_number_id'] ?? '';
        }
        if (empty($default_phone_id) && !empty($meta_phone_number_id)) {
            $default_phone_id = $meta_phone_number_id;
        }

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
            'meta_phone_number_id'     => $default_phone_id,
            'meta_phone_numbers'       => $meta_polos,
            'meta_access_token'        => $meta_access_token,
            'meta_verify_token'        => !empty($meta_verify_token) ? $meta_verify_token : $current['meta_verify_token'],
            'meta_graph_version'       => $meta_graph_version,
            'capi_pixel_id'            => $capi_pixel_id,
            'capi_access_token'        => $capi_access_token,
            'capi_test_event_code'     => $capi_test_event_code,
            'enable_capi_lead'         => $enable_capi_lead,
            'enable_capi_qualified'    => $enable_capi_qual,
            'logo_dark'                => !empty($_POST['logo_dark']) ? esc_url_raw($_POST['logo_dark']) : '',
            'logo_light'               => !empty($_POST['logo_light']) ? esc_url_raw($_POST['logo_light']) : '',
            'logo_compact'             => !empty($_POST['logo_compact']) ? esc_url_raw($_POST['logo_compact']) : '',
        ];

        update_option('lead_intelligence_settings', $new_settings);
        Logger::info('Configurações', 'Configurações do Lead Intelligence atualizadas pelo administrador.');

        add_settings_error('li_messages', 'li_message', 'Configurações salvas com sucesso!', 'updated');
    }

    public static function render() {
        wp_enqueue_media();
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
                        <tr>
                            <th scope="row"><label for="field_mapping_polo">Polo / Unidade</label></th>
                            <td>
                                <input type="text" id="field_mapping_polo" name="field_mapping_polo" value="<?php echo esc_attr($settings['field_mapping']['polo'] ?? 'polo,unidade,cidade,polo_apoio,campus,local'); ?>" class="regular-text">
                                <p class="description">Ex: <code>polo, unidade, cidade, polo_apoio, campus, local</code></p>
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
                            <th scope="row" style="vertical-align: top; padding-top: 15px;">
                                <label>Polos & Números do WhatsApp</label>
                            </th>
                            <td>
                                <?php $polos_list = \LeadIntelligence\WhatsApp\PoloRepository::get_all(); ?>
                                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px; max-width: 650px;">
                                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 15px; flex-wrap: wrap;">
                                        <div>
                                            <div style="font-weight: 700; font-size: 14px; color: #1e293b; margin-bottom: 4px;">
                                                🏢 Gestão Dedicada de Polos (<?php echo count($polos_list); ?> cadastrados)
                                            </div>
                                            <div style="font-size: 13px; color: #64748b; line-height: 1.4;">
                                                Cadastre cada polo separadamente, visualize a lista completa e edite ou exclua qualquer unidade a qualquer momento.
                                            </div>
                                        </div>
                                        <div>
                                            <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-polos')); ?>" class="button button-primary li-btn-faveni" style="display: inline-flex; align-items: center; gap: 6px; padding: 4px 14px; height: auto;">
                                                <span>Acessar Polos WhatsApp</span> &rarr;
                                            </a>
                                        </div>
                                    </div>
                                </div>
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

                <div class="li-card">
                    <h3 class="li-card-title">5. Logotipo do Dashboard</h3>
                    <p class="li-card-desc">Personalize a identidade visual exibida no topo do menu lateral do Dashboard.</p>

                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="li_logo_light">Logotipo Menu Aberto</label></th>
                            <td>
                                <div style="display: flex; gap: 8px; align-items: center; max-width: 600px;">
                                    <input type="url" id="li_logo_light" name="logo_light" value="<?php echo esc_attr(!empty($settings['logo_light']) ? $settings['logo_light'] : ($settings['logo_dark'] ?? '')); ?>" class="regular-text" style="flex:1;" placeholder="https://.../logo-faveni-completa.png">
                                    <button type="button" class="button li-media-upload-btn" data-target="li_logo_light">Biblioteca de Mídia</button>
                                </div>
                                <p class="description">Exibida quando a barra lateral estiver aberta/expandida. Ao clicar nela no Dashboard, o menu é recolhido.</p>
                                <?php 
                                $preview_logo = !empty($settings['logo_light']) ? $settings['logo_light'] : ($settings['logo_dark'] ?? '');
                                if (!empty($preview_logo)): ?>
                                    <div style="margin-top: 10px; padding: 12px; background: #ffffff; display: inline-block; border-radius: 8px; border: 1px solid #e2e8f0;">
                                        <img src="<?php echo esc_url($preview_logo); ?>" style="max-height: 48px; max-width: 260px; object-fit: contain; display: block;" />
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="li_logo_compact">Logotipo Menu Minimizado (Ícone)</label></th>
                            <td>
                                <div style="display: flex; gap: 8px; align-items: center; max-width: 600px;">
                                    <input type="url" id="li_logo_compact" name="logo_compact" value="<?php echo esc_attr($settings['logo_compact'] ?? ''); ?>" class="regular-text" style="flex:1;" placeholder="https://.../icone-tocha-faveni.png">
                                    <button type="button" class="button li-media-upload-btn" data-target="li_logo_compact">Biblioteca de Mídia</button>
                                </div>
                                <p class="description">Exibida quando o menu lateral estiver minimizado/compacto (ex: ícone da tocha ou brasão). Ao clicar nela, o menu se expande.</p>
                                <?php if (!empty($settings['logo_compact'])): ?>
                                    <div style="margin-top: 10px; padding: 12px; background: #163930; display: inline-block; border-radius: 8px; border: 1px solid rgba(255,255,255,0.1);">
                                        <img src="<?php echo esc_url($settings['logo_compact']); ?>" style="max-height: 44px; max-width: 44px; object-fit: contain; display: block;" />
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>

                <p class="submit">
                    <input type="submit" name="li_save_settings" id="submit" class="button button-primary button-large" value="Salvar Configurações">
                </p>
            </form>

            <script>
            jQuery(document).ready(function($) {
                $('.li-media-upload-btn').on('click', function(e) {
                    e.preventDefault();
                    var targetId = $(this).data('target');
                    var customUploader = wp.media({
                        title: 'Selecionar Logotipo',
                        button: { text: 'Usar este Logotipo' },
                        multiple: false
                    }).on('select', function() {
                        var attachment = customUploader.state().get('selection').first().toJSON();
                        $('#' + targetId).val(attachment.url);
                    }).open();
                });
            });
            </script>
        </div>
        <?php
    }
}
