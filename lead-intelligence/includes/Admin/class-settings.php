<?php
namespace LeadIntelligence\Admin;

use LeadIntelligence\Logger;
use LeadIntelligence\WhatsApp\PoloRepository;
use LeadIntelligence\WhatsApp\WabaClient;
use LeadIntelligence\PhoneNormalizer;
use LeadIntelligence\MetaCapi\CapiService;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gerenciador Unificado de Configurações do Plugin com Abas Nativas (UX Limpa)
 */
class Settings {

    public static function init() {
        add_action('admin_init', [__CLASS__, 'handle_actions']);
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
                'area_interesse' => 'area_interesse,area,curso_interesse,especializacao',
                'polo'           => 'polo,unidade,cidade,polo_apoio,campus,local',
            ],
            'enable_whatsapp_capture'  => 1,
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
            'enable_webhook_relay'     => 1,
            'webhook_relay_target_url' => 'https://n8n.favbot.ai/webhook/446479c3-932c-4289-8c1a-4b06e7d4b960',
            'webhook_inbound_key'      => '4b06e7d4b960',
            'webhook_inbound_utm_campaign' => 'formulario google',
            'webhook_inbound_utm_source'   => 'google_lead_form',
            'webhook_inbound_utm_medium'   => 'lead_form',
            'webhook_inbound_form_name'    => 'Formulário Google',
            'google_ads_developer_token'   => '',
            'google_ads_client_id'         => '',
            'google_ads_client_secret'     => '',
            'google_ads_customer_id'       => '',
            'google_ads_login_customer_id' => '',
            'google_ads_refresh_token'     => '',
            'google_ads_connected'         => 0,
            'enable_google_ads_costs_sync' => 1,
            'google_lead_form_mappings'    => [],
            'openai_capi_enabled'          => 0,
            'openai_pixel_id'              => '',
            'openai_api_key'               => '',
            'enable_openai_capi_lead'      => 1,
            'enable_openai_capi_qualified' => 1,
            'enable_openai_capi_purchase'  => 1,
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
        return PoloRepository::get_all();
    }

    /**
     * Localiza o polo pelo Phone Number ID recebido da Meta
     *
     * @param string $phone_number_id
     * @return array|null
     */
    public static function find_polo_by_phone_number_id($phone_number_id) {
        return PoloRepository::find_by_phone_number_id($phone_number_id);
    }

    /**
     * Roteador de Processamento de Formulários e Ações Administrativas
     */
    public static function handle_actions() {
        // 1. Salvar Polo / Número de WhatsApp
        if (isset($_POST['li_save_polo'])) {
            self::process_save_polo();
            return;
        }

        // 1.1 Alternar Status do Polo / Número (Toggle Switch Ativo/Inativo)
        if (isset($_POST['toggle_polo_id'])) {
            self::process_toggle_polo_status();
            return;
        }

        // 2. Excluir Polo / Número de WhatsApp
        if (isset($_POST['li_delete_polo'])) {
            self::process_delete_polo();
            return;
        }

        // 3. Salvar Configurações Gerais
        if (isset($_POST['li_save_settings'])) {
            self::process_save_settings();
            return;
        }

        // 4. Desconectar WhatsApp Oficial / Pausar Captura
        if (isset($_POST['li_disconnect_whatsapp'])) {
            self::process_disconnect_whatsapp();
            return;
        }

        // 5. Reativar Captura de WhatsApp
        if (isset($_POST['li_reconnect_whatsapp'])) {
            self::process_reconnect_whatsapp();
            return;
        }
    }

    /**
     * Desconecta o WhatsApp e pausa a criação de leads pelo webhook
     */
    private static function process_disconnect_whatsapp() {
        if (!check_admin_referer('li_whatsapp_disconnect_verify', 'li_nonce') || !current_user_can('manage_options')) {
            wp_die('Permissão insuficiente ou nonce inválido.');
        }

        $current = self::get_settings();
        $current['enable_whatsapp_capture'] = 0;
        $current['meta_access_token']        = '';
        $current['meta_waba_id']             = '';
        $current['meta_app_id']              = '';
        $current['meta_app_secret']          = '';
        $current['meta_phone_number_id']     = '';

        // Inativa os polos para segurança absoluta
        $polos = PoloRepository::get_all();
        foreach ($polos as &$p) {
            $p['status'] = 'inativo';
        }
        update_option('lead_intelligence_polos', $polos);
        update_option('lead_intelligence_settings', $current);

        Logger::info('WhatsApp', 'WhatsApp desconectado com sucesso pelo administrador. Captura de novos leads pausada.');
        wp_safe_redirect(add_query_arg(['page' => 'lead-intelligence-settings', 'tab' => 'whatsapp', 'wa_disconnected' => 1], admin_url('admin.php')));
        exit;
    }

    /**
     * Reativa a captura de mensagens do WhatsApp
     */
    private static function process_reconnect_whatsapp() {
        if (!check_admin_referer('li_whatsapp_reconnect_verify', 'li_nonce') || !current_user_can('manage_options')) {
            wp_die('Permissão insuficiente ou nonce inválido.');
        }

        $current = self::get_settings();
        $current['enable_whatsapp_capture'] = 1;
        update_option('lead_intelligence_settings', $current);

        Logger::info('WhatsApp', 'Captura de mensagens do WhatsApp reativada pelo administrador.');
        wp_safe_redirect(add_query_arg(['page' => 'lead-intelligence-settings', 'tab' => 'whatsapp', 'wa_reconnected' => 1], admin_url('admin.php')));
        exit;
    }

    /**
     * Salva ou edita um Polo / Número de WhatsApp individualmente
     */
    private static function process_save_polo() {
        if (!check_admin_referer('li_polo_save_verify', 'li_nonce') || !current_user_can('manage_options')) {
            wp_die('Permissão insuficiente ou nonce inválido.');
        }

        $nome            = sanitize_text_field(wp_unslash($_POST['polo_nome'] ?? ''));
        $phone_number_id = sanitize_text_field(wp_unslash($_POST['polo_phone_number_id'] ?? ''));
        $display_phone   = sanitize_text_field(wp_unslash($_POST['polo_display_phone'] ?? ''));
        $waba_id         = sanitize_text_field(wp_unslash($_POST['polo_waba_id'] ?? ''));
        $access_token    = sanitize_text_field(wp_unslash($_POST['polo_access_token'] ?? ''));
        $status          = sanitize_text_field(wp_unslash($_POST['polo_status'] ?? 'ativo'));
        $is_default      = isset($_POST['polo_is_default']) ? 1 : 0;
        $edit_id         = sanitize_key(wp_unslash($_POST['polo_id'] ?? ''));

        if (empty($nome)) {
            add_settings_error('li_messages', 'li_polo_err', 'O Nome do Polo / Unidade é obrigatório.', 'error');
            return;
        }
        if (empty($phone_number_id)) {
            add_settings_error('li_messages', 'li_polo_err', 'O Phone Number ID da Meta é obrigatório.', 'error');
            return;
        }

        PoloRepository::save([
            'id'              => $edit_id,
            'nome'            => $nome,
            'phone_number_id' => $phone_number_id,
            'display_phone'   => $display_phone,
            'waba_id'         => $waba_id,
            'access_token'    => $access_token,
            'status'          => $status,
            'is_default'      => $is_default,
        ]);

        Logger::info('Polos', "Número/Polo {$nome} ({$phone_number_id}) salvo com sucesso.");
        wp_safe_redirect(add_query_arg(['page' => 'lead-intelligence-settings', 'tab' => 'whatsapp', 'saved' => 1], admin_url('admin.php')));
        exit;
    }

    /**
     * Alterna rapidamente o status de um polo/número entre ativo e inativo
     */
    private static function process_toggle_polo_status() {
        if (!check_admin_referer('li_polo_toggle_verify', 'li_nonce') || !current_user_can('manage_options')) {
            wp_die('Permissão insuficiente ou nonce inválido.');
        }

        $polo_id = sanitize_key(wp_unslash($_POST['toggle_polo_id'] ?? ''));
        if (!empty($polo_id)) {
            $new_status = PoloRepository::toggle_status($polo_id);
            $polo = PoloRepository::get_by_id($polo_id);
            $polo_name = $polo['nome'] ?? 'Polo';
            $status_label = ($new_status === 'ativo') ? 'ativado' : 'desativado';
            Logger::info('Polos', "Número/polo {$polo_name} foi {$status_label} com sucesso.");
        }

        wp_safe_redirect(add_query_arg(['page' => 'lead-intelligence-settings', 'tab' => 'whatsapp'], admin_url('admin.php')));
        exit;
    }

    /**
     * Exclui um Polo / Número de WhatsApp
     */
    private static function process_delete_polo() {
        if (!check_admin_referer('li_polo_delete_verify', 'li_nonce') || !current_user_can('manage_options')) {
            wp_die('Permissão insuficiente ou nonce inválido.');
        }

        $del_id = sanitize_key(wp_unslash($_POST['delete_polo_id'] ?? ''));
        if (!empty($del_id)) {
            PoloRepository::delete($del_id);
            Logger::info('Polos', "Polo ID {$del_id} removido.");
        }

        wp_safe_redirect(add_query_arg(['page' => 'lead-intelligence-settings', 'tab' => 'whatsapp', 'deleted' => 1], admin_url('admin.php')));
        exit;
    }

    /**
     * Processa a gravação das opções gerais
     */
    private static function process_save_settings() {
        if (!check_admin_referer('li_settings_verify', 'li_nonce') || !current_user_can('manage_options')) {
            wp_die('Erro de verificação de segurança (nonce inválido).');
        }

        $current = self::get_settings();
        $tab     = sanitize_key($_POST['current_tab'] ?? 'meta');

        $enable_elementor = isset($_POST['enable_elementor_capture']) ? 1 : 0;
        $enable_debug     = isset($_POST['enable_debug_logging']) ? 1 : 0;
        $utm_preservation = isset($_POST['utm_preservation']) ? 1 : 0;

        $field_mapping = [
            'nome'           => sanitize_text_field($_POST['field_mapping_nome'] ?? $current['field_mapping']['nome']),
            'email'          => sanitize_text_field($_POST['field_mapping_email'] ?? $current['field_mapping']['email']),
            'telefone'       => sanitize_text_field($_POST['field_mapping_telefone'] ?? $current['field_mapping']['telefone']),
            'tipo_curso'     => sanitize_text_field($_POST['field_mapping_tipo_curso'] ?? $current['field_mapping']['tipo_curso']),
            'area_interesse' => sanitize_text_field($_POST['field_mapping_area_interesse'] ?? $current['field_mapping']['area_interesse']),
            'polo'           => sanitize_text_field($_POST['field_mapping_polo'] ?? ($current['field_mapping']['polo'] ?? '')),
        ];

        // Meta Cloud API
        $meta_app_id        = sanitize_text_field($_POST['meta_app_id'] ?? $current['meta_app_id']);
        $meta_waba_id       = sanitize_text_field($_POST['meta_waba_id'] ?? $current['meta_waba_id']);
        $meta_verify_token  = sanitize_text_field($_POST['meta_verify_token'] ?? $current['meta_verify_token']);
        $meta_graph_version = sanitize_text_field($_POST['meta_graph_version'] ?? $current['meta_graph_version']);

        $meta_app_secret   = sanitize_text_field($_POST['meta_app_secret'] ?? '');
        if (strpos($meta_app_secret, '••••') !== false || empty($meta_app_secret)) {
            $meta_app_secret = $current['meta_app_secret'];
        }

        $meta_access_token = sanitize_text_field($_POST['meta_access_token'] ?? '');
        if (strpos($meta_access_token, '••••') !== false || empty($meta_access_token)) {
            $meta_access_token = $current['meta_access_token'];
        }

        // Meta Conversions API
        $capi_pixel_id        = sanitize_text_field($_POST['capi_pixel_id'] ?? $current['capi_pixel_id']);
        $capi_test_event_code = sanitize_text_field($_POST['capi_test_event_code'] ?? $current['capi_test_event_code']);
        $enable_capi_lead     = isset($_POST['enable_capi_lead']) ? 1 : (isset($_POST['has_capi_form']) ? 0 : $current['enable_capi_lead']);
        $enable_capi_qual     = isset($_POST['enable_capi_qualified']) ? 1 : (isset($_POST['has_capi_form']) ? 0 : $current['enable_capi_qualified']);

        $capi_access_token    = sanitize_text_field($_POST['capi_access_token'] ?? '');
        if (strpos($capi_access_token, '••••') !== false || empty($capi_access_token)) {
            $capi_access_token = $current['capi_access_token'];
        }

        $enable_relay = isset($_POST['enable_webhook_relay']) ? 1 : (isset($_POST['has_forms_tab']) ? 0 : $current['enable_webhook_relay']);
        $target_relay = !empty($_POST['webhook_relay_target_url']) ? esc_url_raw(trim($_POST['webhook_relay_target_url'])) : $current['webhook_relay_target_url'];
        $inbound_key  = !empty($_POST['webhook_inbound_key']) ? sanitize_text_field(trim($_POST['webhook_inbound_key'])) : ($current['webhook_inbound_key'] ?? '4b06e7d4b960');
        $inbound_campaign = !empty($_POST['webhook_inbound_utm_campaign']) ? sanitize_text_field(trim($_POST['webhook_inbound_utm_campaign'])) : ($current['webhook_inbound_utm_campaign'] ?? 'formulario google');
        $inbound_source   = !empty($_POST['webhook_inbound_utm_source']) ? sanitize_text_field(trim($_POST['webhook_inbound_utm_source'])) : ($current['webhook_inbound_utm_source'] ?? 'google_lead_form');
        $inbound_medium   = !empty($_POST['webhook_inbound_utm_medium']) ? sanitize_text_field(trim($_POST['webhook_inbound_utm_medium'])) : ($current['webhook_inbound_utm_medium'] ?? 'lead_form');
        $inbound_form_nm  = !empty($_POST['webhook_inbound_form_name']) ? sanitize_text_field(trim($_POST['webhook_inbound_form_name'])) : ($current['webhook_inbound_form_name'] ?? 'Formulário Google');

        // Mapeamentos De-Para do Google Ads Lead Form (ID da Campanha / ID do Formulário)
        $google_mappings = $current['google_lead_form_mappings'] ?? [];
        if (isset($_POST['has_forms_tab'])) {
            $google_mappings = [];
            if (!empty($_POST['gform_mapping_id']) && is_array($_POST['gform_mapping_id'])) {
                foreach ($_POST['gform_mapping_id'] as $idx => $id_val) {
                    $id_clean = sanitize_text_field(trim($id_val));
                    if (empty($id_clean)) continue;
                    $google_mappings[] = [
                        'id_google'     => $id_clean,
                        'tipo_id'       => in_array($_POST['gform_mapping_tipo'][$idx] ?? '', ['campaign', 'form']) ? $_POST['gform_mapping_tipo'][$idx] : 'campaign',
                        'nome_amigavel' => sanitize_text_field($_POST['gform_mapping_nome'][$idx] ?? ''),
                        'instancia'     => sanitize_text_field($_POST['gform_mapping_instancia'][$idx] ?? ''),
                        'tipo_curso'    => sanitize_text_field($_POST['gform_mapping_curso'][$idx] ?? ''),
                    ];
                }
            }
        }

        // Google Ads API
        $gads_dev_token = sanitize_text_field($_POST['google_ads_developer_token'] ?? '');
        if (strpos($gads_dev_token, '••••') !== false || empty($gads_dev_token)) {
            $gads_dev_token = $current['google_ads_developer_token'] ?? '';
        }
        $gads_client_id     = sanitize_text_field($_POST['google_ads_client_id'] ?? ($current['google_ads_client_id'] ?? ''));
        $gads_client_secret = sanitize_text_field($_POST['google_ads_client_secret'] ?? '');
        if (strpos($gads_client_secret, '••••') !== false || empty($gads_client_secret)) {
            $gads_client_secret = $current['google_ads_client_secret'] ?? '';
        }
        $gads_customer_id   = sanitize_text_field($_POST['google_ads_customer_id'] ?? ($current['google_ads_customer_id'] ?? ''));
        $gads_login_mcc     = sanitize_text_field($_POST['google_ads_login_customer_id'] ?? ($current['google_ads_login_customer_id'] ?? ''));
        $gads_refresh_token = sanitize_text_field($_POST['google_ads_refresh_token'] ?? '');
        if (strpos($gads_refresh_token, '••••') !== false || empty($gads_refresh_token)) {
            $gads_refresh_token = $current['google_ads_refresh_token'] ?? '';
        }
        $enable_gads_sync   = isset($_POST['enable_google_ads_costs_sync']) ? 1 : (isset($_POST['has_gads_form']) ? 0 : ($current['enable_google_ads_costs_sync'] ?? 1));

        // Parâmetros da OpenAI Conversions API (ChatGPT Ads)
        $openai_capi_enabled = isset($_POST['has_openai_tab']) ? (isset($_POST['openai_capi_enabled']) ? 1 : 0) : ($current['openai_capi_enabled'] ?? 0);
        $openai_pixel_id     = sanitize_text_field($_POST['openai_pixel_id'] ?? ($current['openai_pixel_id'] ?? ''));
        $openai_api_key      = sanitize_text_field($_POST['openai_api_key'] ?? '');
        if (strpos($openai_api_key, '••••') !== false || empty($openai_api_key)) {
            $openai_api_key = $current['openai_api_key'] ?? '';
        }
        $enable_oai_lead     = isset($_POST['has_openai_tab']) ? (isset($_POST['enable_openai_capi_lead']) ? 1 : 0) : ($current['enable_openai_capi_lead'] ?? 1);
        $enable_oai_qual     = isset($_POST['has_openai_tab']) ? (isset($_POST['enable_openai_capi_qualified']) ? 1 : 0) : ($current['enable_openai_capi_qualified'] ?? 1);
        $enable_oai_purch    = isset($_POST['has_openai_tab']) ? (isset($_POST['enable_openai_capi_purchase']) ? 1 : 0) : ($current['enable_openai_capi_purchase'] ?? 1);

        $new_settings = [
            'enable_whatsapp_capture'  => isset($_POST['has_whatsapp_form']) ? (isset($_POST['enable_whatsapp_capture']) ? 1 : 0) : ($current['enable_whatsapp_capture'] ?? 1),
            'enable_elementor_capture' => isset($_POST['has_forms_tab']) ? $enable_elementor : $current['enable_elementor_capture'],
            'enable_debug_logging'     => isset($_POST['has_forms_tab']) ? $enable_debug : $current['enable_debug_logging'],
            'utm_preservation'         => isset($_POST['has_forms_tab']) ? $utm_preservation : $current['utm_preservation'],
            'field_mapping'            => $field_mapping,
            'meta_app_id'              => $meta_app_id,
            'meta_app_secret'          => $meta_app_secret,
            'meta_waba_id'             => $meta_waba_id,
            'meta_phone_number_id'     => $current['meta_phone_number_id'],
            'meta_phone_numbers'       => $current['meta_phone_numbers'],
            'meta_access_token'        => $meta_access_token,
            'meta_verify_token'        => !empty($meta_verify_token) ? $meta_verify_token : $current['meta_verify_token'],
            'meta_graph_version'       => !empty($meta_graph_version) ? $meta_graph_version : 'v26.0',
            'capi_pixel_id'            => $capi_pixel_id,
            'capi_access_token'        => $capi_access_token,
            'capi_test_event_code'     => $capi_test_event_code,
            'enable_capi_lead'         => $enable_capi_lead,
            'enable_capi_qualified'    => $enable_capi_qual,
            'openai_capi_enabled'          => $openai_capi_enabled,
            'openai_pixel_id'              => $openai_pixel_id,
            'openai_api_key'               => $openai_api_key,
            'enable_openai_capi_lead'      => $enable_oai_lead,
            'enable_openai_capi_qualified' => $enable_oai_qual,
            'enable_openai_capi_purchase'  => $enable_oai_purch,
            'logo_dark'                => isset($_POST['logo_dark']) ? esc_url_raw($_POST['logo_dark']) : $current['logo_dark'],
            'logo_light'               => isset($_POST['logo_light']) ? esc_url_raw($_POST['logo_light']) : $current['logo_light'],
            'logo_compact'             => isset($_POST['logo_compact']) ? esc_url_raw($_POST['logo_compact']) : $current['logo_compact'],
            'enable_webhook_relay'     => $enable_relay,
            'webhook_relay_target_url' => $target_relay,
            'webhook_inbound_key'      => $inbound_key,
            'webhook_inbound_utm_campaign' => $inbound_campaign,
            'webhook_inbound_utm_source'   => $inbound_source,
            'webhook_inbound_utm_medium'   => $inbound_medium,
            'webhook_inbound_form_name'    => $inbound_form_nm,
            'google_lead_form_mappings'    => $google_mappings,
            'google_ads_developer_token'   => $gads_dev_token,
            'google_ads_client_id'         => $gads_client_id,
            'google_ads_client_secret'     => $gads_client_secret,
            'google_ads_customer_id'       => $gads_customer_id,
            'google_ads_login_customer_id' => $gads_login_mcc,
            'google_ads_refresh_token'     => $gads_refresh_token,
            'google_ads_connected'         => !empty($gads_refresh_token) ? 1 : ($current['google_ads_connected'] ?? 0),
            'enable_google_ads_costs_sync' => $enable_gads_sync,
        ];

        update_option('lead_intelligence_settings', $new_settings);
        Logger::info('Configurações', 'Configurações do Lead Intelligence atualizadas na aba ' . esc_html($tab));

        wp_safe_redirect(add_query_arg(['page' => 'lead-intelligence-settings', 'tab' => $tab, 'updated' => 1], admin_url('admin.php')));
        exit;
    }

    /**
     * Renderiza a interface administrativa de configurações com Abas Nativas
     */
    public static function render() {
        wp_enqueue_media();
        $settings = self::get_settings();

        $active_tab = sanitize_key($_GET['tab'] ?? 'whatsapp');
        $allowed_tabs = ['whatsapp', 'meta', 'google_ads_api', 'openai_capi', 'forms', 'capi', 'branding'];
        if (!in_array($active_tab, $allowed_tabs, true)) {
            $active_tab = 'whatsapp';
        }

        // Teste de conexão individual (se disparado)
        $test_result = null;
        if (isset($_POST['li_test_polo']) && check_admin_referer('li_polo_test_verify', 'li_nonce')) {
            $target_phone_id = sanitize_text_field(wp_unslash($_POST['target_phone_number_id'] ?? ''));
            $test_result = WabaClient::test_connection($target_phone_id);
        }

        // Teste CAPI Meta (se disparado)
        $capi_test_result = null;
        if (isset($_POST['li_test_capi_fire']) && check_admin_referer('li_capi_test_verify', 'li_nonce')) {
            $dummy_lead = (object) [
                'id'                   => 999999,
                'uuid'                 => wp_generate_uuid4(),
                'email'                => 'teste_capi@faveni.edu.br',
                'telefone'             => '5533999999999',
                'telefone_normalizado' => '5533999999999',
                'nome'                 => 'Lead Teste Conversions API',
                'tipo_curso'           => 'Pós-Graduação',
                'area_interesse'       => 'Educação',
                'pagina_origem'        => home_url(),
                'qualificacao_status'  => 'qualificado',
                'score'                => 100,
                'qualificacao_origem'  => 'teste_painel',
            ];
            $capi_test_result = CapiService::dispatch_event('Lead', $dummy_lead);
        }

        // Teste OpenAI CAPI (se disparado com validate_only)
        $openai_test_result = null;
        if (isset($_POST['li_test_openai_capi']) && check_admin_referer('li_openai_test_verify', 'li_nonce')) {
            $openai_test_result = \LeadIntelligence\OpenAiCapi\OpenAiCapiService::test_connection();
        }

        // Teste Google Ads API
        $gads_test_result = null;
        if (isset($_POST['li_test_google_ads']) && check_admin_referer('li_google_ads_test_verify', 'li_nonce')) {
            $gads_test_result = \LeadIntelligence\GoogleAds\GoogleAdsClient::test_connection();
        }

        // Sincronização manual Google Ads API
        $gads_sync_result = null;
        if (isset($_POST['li_sync_google_ads_now']) && check_admin_referer('li_google_ads_sync_verify', 'li_nonce')) {
            $gads_sync_result = \LeadIntelligence\GoogleAds\GoogleAdsClient::sync_campaign_costs();
        }

        // Desconectar Google Ads API
        if (isset($_POST['li_disconnect_google_ads']) && check_admin_referer('li_google_ads_disconnect_verify', 'li_nonce')) {
            $settings['google_ads_refresh_token'] = '';
            $settings['google_ads_connected']     = 0;
            update_option('lead_intelligence_settings', $settings);
            delete_transient('li_gads_access_token');
            wp_safe_redirect(admin_url('admin.php?page=lead-intelligence-settings&tab=google_ads_api&disconnected=1'));
            exit;
        }
        ?>
        <div class="wrap li-wrap">
            <div class="li-header" style="margin-bottom: 16px;">
                <h2 style="display: flex; align-items: center; gap: 8px;">
                    <span class="dashicons dashicons-admin-generic" style="font-size: 26px; width: 26px; height: 26px;"></span>
                    Lead Intelligence &bull; Configurações
                </h2>
                <p class="li-subtitle">Central unificada de conexões, números de WhatsApp da instituição, integrações de anúncios e formulários.</p>
            </div>

            <?php if (!empty($_GET['updated']) || !empty($_GET['saved'])): ?>
                <div class="notice notice-success is-dismissible" style="margin-bottom: 20px;">
                    <p>✔ Alterações salvas com sucesso!</p>
                </div>
            <?php elseif (!empty($_GET['deleted'])): ?>
                <div class="notice notice-info is-dismissible" style="margin-bottom: 20px;">
                    <p>🗑️ Polo / Número removido com sucesso.</p>
                </div>
            <?php elseif (!empty($_GET['auth_success'])): ?>
                <div class="notice notice-success is-dismissible" style="margin-bottom: 20px;">
                    <p>✔ Conta Google Ads autorizada e conectada com sucesso!</p>
                </div>
            <?php elseif (!empty($_GET['auth_error'])): ?>
                <div class="notice notice-error is-dismissible" style="margin-bottom: 20px;">
                    <p>❌ Erro de autorização Google Ads: <?php echo esc_html($_GET['auth_error']); ?></p>
                </div>
            <?php elseif (!empty($_GET['disconnected'])): ?>
                <div class="notice notice-info is-dismissible" style="margin-bottom: 20px;">
                    <p>Conta Google Ads desconectada com sucesso.</p>
                </div>
            <?php elseif (!empty($_GET['wa_disconnected'])): ?>
                <div class="notice notice-warning is-dismissible" style="margin-bottom: 20px;">
                    <p>🛑 <strong>WhatsApp desconectado com sucesso!</strong> A captura de mensagens pelo webhook foi pausada e nenhum lead novo do WhatsApp será salvo no banco de dados.</p>
                </div>
            <?php elseif (!empty($_GET['wa_reconnected'])): ?>
                <div class="notice notice-success is-dismissible" style="margin-bottom: 20px;">
                    <p>✔ <strong>Captura de mensagens do WhatsApp reativada com sucesso!</strong></p>
                </div>
            <?php endif; ?>

            <?php settings_errors('li_messages'); ?>

            <!-- NAVEGAÇÃO POR ABAS NATIVAS DO WORDPRESS -->
            <nav class="nav-tab-wrapper wp-clearfix" style="margin-bottom: 22px;">
                <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-settings&tab=whatsapp')); ?>" 
                   class="nav-tab <?php echo $active_tab === 'whatsapp' ? 'nav-tab-active' : ''; ?>" style="font-weight: 600;">
                    🏢 Números de WhatsApp (Polos Isolados)
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-settings&tab=meta')); ?>" 
                   class="nav-tab <?php echo $active_tab === 'meta' ? 'nav-tab-active' : ''; ?>" style="font-weight: 600;">
                    🔗 Conexão Meta &amp; Webhook
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-settings&tab=google_ads_api')); ?>" 
                   class="nav-tab <?php echo $active_tab === 'google_ads_api' ? 'nav-tab-active' : ''; ?>" style="font-weight: 600;">
                    🟢 Google Ads API &amp; Gastos
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-settings&tab=forms')); ?>" 
                   class="nav-tab <?php echo $active_tab === 'forms' ? 'nav-tab-active' : ''; ?>" style="font-weight: 600;">
                    📝 Formulários &amp; Elementor
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-settings&tab=capi')); ?>" 
                   class="nav-tab <?php echo $active_tab === 'capi' ? 'nav-tab-active' : ''; ?>" style="font-weight: 600;">
                    🎯 Meta Conversions API (CAPI)
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-settings&tab=openai_capi')); ?>" 
                   class="nav-tab <?php echo $active_tab === 'openai_capi' ? 'nav-tab-active' : ''; ?>" style="font-weight: 600;">
                    🤖 OpenAI CAPI (ChatGPT Ads)
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-settings&tab=branding')); ?>" 
                   class="nav-tab <?php echo $active_tab === 'branding' ? 'nav-tab-active' : ''; ?>" style="font-weight: 600;">
                    🎨 Aparência &amp; Logos
                </a>
            </nav>

            <!-- CONTEÚDO DA ABA ATIVA -->
            <?php
            switch ($active_tab) {
                case 'whatsapp':
                    self::render_tab_whatsapp($test_result);
                    break;
                case 'meta':
                    self::render_tab_meta($settings);
                    break;
                case 'google_ads_api':
                    self::render_tab_google_ads_api($settings, $gads_test_result, $gads_sync_result);
                    break;
                case 'forms':
                    self::render_tab_forms($settings);
                    break;
                case 'capi':
                    self::render_tab_capi($settings, $capi_test_result);
                    break;
                case 'openai_capi':
                    self::render_tab_openai_capi($settings, $openai_test_result);
                    break;
                case 'branding':
                    self::render_tab_branding($settings);
                    break;
            }
            ?>

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

        </div>
        <?php
    }

    /**
     * ABA 1: Gestão de Múltiplos Números de WhatsApp / Polos Isolados
     */
    private static function render_tab_whatsapp($test_result = null) {
        $webhook_url = get_rest_url(null, 'lead-intelligence/v1/meta/webhook');
        $action = sanitize_key($_GET['action'] ?? 'list');
        $id     = sanitize_key($_GET['id'] ?? '');
        $polos  = PoloRepository::get_all();

        // Se o usuário clicou em Novo ou Editar Polo
        if ($action === 'new' || $action === 'edit'):
            $edit_polo = ($action === 'edit' && !empty($id)) ? PoloRepository::get_by_id($id) : null;
            $curr_settings = self::get_settings();
            $verify_tok = $curr_settings['meta_verify_token'] ?? '2QcW***';
        ?>
            <!-- INFORMATIVO TÉCNICO PROFISSIONAL: GUIA COMPLETO DE CONFIGURAÇÃO NA META -->
            <div class="li-card" style="margin-bottom: 24px; border-left: 4px solid #4f46e5; background: #ffffff; padding: 24px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 14px; margin-bottom: 18px; border-bottom: 1px solid #e2e8f0; padding-bottom: 14px;">
                    <div>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span style="font-size: 24px;">📘</span>
                            <h3 style="margin: 0; font-size: 17px; font-weight: 700; color: #1e1b4b;">
                                Manual de Configuração: Conectando o 2º Aplicativo da Meta (Zero Conflito com CRM/InterageZap)
                            </h3>
                        </div>
                        <p style="margin: 4px 0 0; font-size: 13px; color: #64748b; line-height: 1.5;">
                            Como a sua empresa já possui o WhatsApp conectado ao aplicativo principal (InterageZap), este <strong>segundo aplicativo</strong> operará em paralelo oficial via Meta Graph API v26.0. Siga os 4 passos obrigatórios abaixo para que os leads e nomes de anúncios cheguem 100% completos:
                        </p>
                    </div>
                    <span class="li-badge" style="background: #e0e7ff; color: #4338ca; font-weight: 700; font-size: 11px; padding: 5px 10px; border-radius: 6px;">
                        🛡️ Multi-App Oficial Meta (Inscrição Simultânea)
                    </span>
                </div>

                <!-- GRADE DE 4 ETAPAS -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px; margin-bottom: 20px;">
                    
                    <!-- PASSO 1 -->
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px;">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                            <span style="background: #4f46e5; color: #fff; width: 22px; height: 22px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700;">1</span>
                            <h4 style="margin: 0; font-size: 13px; font-weight: 700; color: #1e293b;">Atribuir Ativos no Business</h4>
                        </div>
                        <p style="font-size: 12px; color: #475569; margin: 0 0 8px 0; line-height: 1.4;">
                            Em <strong>Configurações do Negócio &gt; Usuários do Sistema</strong>, selecione o usuário e adicione <strong>2 ativos obrigatórios</strong>:
                        </p>
                        <ul style="margin: 0; padding-left: 16px; font-size: 11px; color: #334155; line-height: 1.5;">
                            <li>📌 <strong>Conta do WhatsApp (WABA)</strong>: Controle total.</li>
                            <li>📌 <strong>Conta de Anúncios</strong>: Marcar <em>"Ver desempenho"</em> (essencial para ler nomes de campanhas!).</li>
                        </ul>
                    </div>

                    <!-- PASSO 2 -->
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px;">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                            <span style="background: #4f46e5; color: #fff; width: 22px; height: 22px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700;">2</span>
                            <h4 style="margin: 0; font-size: 13px; font-weight: 700; color: #1e293b;">Gerar Token com 3 Permissões</h4>
                        </div>
                        <p style="font-size: 12px; color: #475569; margin: 0 0 8px 0; line-height: 1.4;">
                            Clique em <strong>"Gerar novo token"</strong>, selecione o <strong>2º App</strong> (ex: <code>App Bm Ative Gv</code>) e marque:
                        </p>
                        <ul style="margin: 0; padding-left: 16px; font-size: 11px; color: #334155; line-height: 1.5;">
                            <li>✅ <code>whatsapp_business_messaging</code></li>
                            <li>✅ <code>whatsapp_business_management</code></li>
                            <li>✅ <code>ads_read</code> <strong style="color:#b91c1c;">(Obrigatória para nomes de anúncios!)</strong></li>
                        </ul>
                    </div>

                    <!-- PASSO 3 -->
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px;">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                            <span style="background: #4f46e5; color: #fff; width: 22px; height: 22px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700;">3</span>
                            <h4 style="margin: 0; font-size: 13px; font-weight: 700; color: #1e293b;">Webhook no Meta Developers</h4>
                        </div>
                        <p style="font-size: 12px; color: #475569; margin: 0 0 8px 0; line-height: 1.4;">
                            No <strong>developers.facebook.com</strong> &gt; Seu 2º App &gt; <em>WhatsApp &gt; Configuração de Produção</em>:
                        </p>
                        <ul style="margin: 0; padding-left: 16px; font-size: 11px; color: #334155; line-height: 1.5;">
                            <li>Cole a <strong>URL de Callback</strong> abaixo.</li>
                            <li>Cole o <strong>Verify Token</strong> abaixo.</li>
                            <li>Na tabela, marque a chave <strong>messages</strong> (coluna "Assinar").</li>
                            <li>Mantenha o App no status <strong>Publicado</strong>.</li>
                        </ul>
                    </div>

                    <!-- PASSO 4 -->
                    <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 16px;">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                            <span style="background: #16a34a; color: #fff; width: 22px; height: 22px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700;">4</span>
                            <h4 style="margin: 0; font-size: 13px; font-weight: 700; color: #14532d;">Salvar e Testar no Plugin</h4>
                        </div>
                        <p style="font-size: 12px; color: #166534; margin: 0 0 8px 0; line-height: 1.4;">
                            Após preencher os campos do formulário abaixo e clicar em <strong>Salvar</strong>:
                        </p>
                        <div style="font-size: 11px; color: #15803d; line-height: 1.5;">
                            ✔ Clique no botão <strong>"🧪 Testar"</strong> na listagem.<br>
                            ✔ O plugin inscreve formalmente o 2º App na WABA (<code>subscribed_apps</code>).<br>
                            ✔ A Meta passa a enviar as mensagens para os dois apps simultaneamente!
                        </div>
                    </div>

                </div>

                <!-- BOX COM DADOS DE CÓPIA RÁPIDA -->
                <div style="background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 8px; padding: 14px 18px; display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 12px;">
                    <div style="background: #ffffff; padding: 8px 12px; border-radius: 6px; border: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; gap: 10px;">
                        <div style="overflow: hidden;">
                            <div style="font-size: 10px; text-transform: uppercase; color: #64748b; font-weight: 700;">URL de Callback do Webhook</div>
                            <code style="font-size: 11px; color: #0284c7; font-weight: 600; white-space: nowrap;"><?php echo esc_url($webhook_url); ?></code>
                        </div>
                        <button type="button" class="button button-small" onclick="navigator.clipboard.writeText('<?php echo esc_js($webhook_url); ?>'); this.innerText='Copiado!'; setTimeout(()=>{this.innerText='Copiar'}, 2000);">
                            Copiar
                        </button>
                    </div>
                    <div style="background: #ffffff; padding: 8px 12px; border-radius: 6px; border: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; gap: 10px;">
                        <div>
                            <div style="font-size: 10px; text-transform: uppercase; color: #64748b; font-weight: 700;">Verify Token</div>
                            <code style="font-size: 12px; color: #059669; font-weight: 700;"><?php echo esc_html($verify_tok); ?></code>
                        </div>
                        <button type="button" class="button button-small" onclick="navigator.clipboard.writeText('<?php echo esc_js($verify_tok); ?>'); this.innerText='Copiado!'; setTimeout(()=>{this.innerText='Copiar'}, 2000);">
                            Copiar
                        </button>
                    </div>
                </div>
            </div>

            <!-- FORMULÁRIO DE CADASTRO -->
            <div class="li-card" style="max-width: 900px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #e2e8f0; padding-bottom: 14px;">
                    <div>
                        <h3 class="li-card-title" style="margin: 0;">
                            <?php echo $edit_polo ? '✏️ Editar Número / Polo' : '➕ Cadastrar Novo Número de WhatsApp'; ?>
                        </h3>
                        <p class="li-card-desc" style="margin: 3px 0 0;">
                            Configure a identificação deste número isolado na Meta Cloud API para recebimento de conversas.
                        </p>
                    </div>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-settings&tab=whatsapp')); ?>" class="button button-secondary">
                        &larr; Voltar para a Lista
                    </a>
                </div>

                <form method="post" action="">
                    <?php wp_nonce_field('li_polo_save_verify', 'li_nonce'); ?>
                    <input type="hidden" name="polo_id" value="<?php echo esc_attr($edit_polo['id'] ?? ''); ?>">

                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="polo_nome">Nome do Polo / Unidade <span style="color:#dc2626;">*</span></label></th>
                            <td>
                                <input type="text" id="polo_nome" name="polo_nome" value="<?php echo esc_attr($edit_polo['nome'] ?? ''); ?>" required class="regular-text" placeholder="Ex: Faveni Matriz, Polo RJ, Comercial Geral">
                                <p class="description">Identificador interno da unidade ou equipe responsável por este número.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="polo_phone_number_id">Phone Number ID da Meta <span style="color:#dc2626;">*</span></label></th>
                            <td>
                                <input type="text" id="polo_phone_number_id" name="polo_phone_number_id" value="<?php echo esc_attr($edit_polo['phone_number_id'] ?? ''); ?>" required class="regular-text" style="font-family: monospace;" placeholder="Ex: 104829104859182">
                                <p class="description" style="color:#0369a1; font-weight:500;">📍 <strong>Onde obter:</strong> developers.facebook.com &gt; Seu App &gt; WhatsApp &gt; <em>Configuração da API</em> &gt; campo <strong>"ID do número de telefone"</strong> (código numérico de 15 dígitos).</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="polo_display_phone">Telefone de Exibição</label></th>
                            <td>
                                <input type="text" id="polo_display_phone" name="polo_display_phone" value="<?php echo esc_attr($edit_polo['display_phone'] ?? ''); ?>" class="regular-text" placeholder="Ex: +55 (33) 99842-0051">
                                <p class="description">Número formatado visível para identificação rápida.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="polo_waba_id">WABA ID deste Número</label></th>
                            <td>
                                <input type="text" id="polo_waba_id" name="polo_waba_id" value="<?php echo esc_attr($edit_polo['waba_id'] ?? ''); ?>" class="regular-text" placeholder="Ex: 1541100330230704">
                                <p class="description" style="color:#0369a1; font-weight:500;">📍 <strong>Onde obter:</strong> Na mesma tela acima, copie a <strong>"Identificação da conta do WhatsApp Business"</strong> (WABA ID).</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="polo_access_token">Access Token deste Meta App</label></th>
                            <td>
                                <input type="password" id="polo_access_token" name="polo_access_token" value="<?php echo !empty($edit_polo['access_token']) ? '••••••••••••••••' : ''; ?>" class="large-text">
                                <p class="description" style="color:#0369a1; font-weight:500;">📍 <strong>Onde gerar:</strong> business.facebook.com &gt; <em>Configurações do Negócio</em> &gt; <em>Usuários do Sistema</em> &gt; Gerar Token com permissões <code>whatsapp_business_messaging</code>, <code>whatsapp_business_management</code> e <code>ads_read</code>.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Polo Padrão</th>
                            <td>
                                <label style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer;">
                                    <input type="checkbox" name="polo_is_default" value="1" <?php checked(!empty($edit_polo['is_default'])); ?>>
                                    <span>Definir este número como padrão principal da instituição</span>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="polo_status">Status</label></th>
                            <td>
                                <select id="polo_status" name="polo_status">
                                    <option value="ativo" <?php selected(($edit_polo['status'] ?? 'ativo'), 'ativo'); ?>>🟢 Ativo</option>
                                    <option value="inativo" <?php selected(($edit_polo['status'] ?? ''), 'inativo'); ?>>⚪ Inativo</option>
                                </select>
                            </td>
                        </tr>
                    </table>

                    <div style="margin-top: 25px; padding-top: 15px; border-top: 1px solid #e2e8f0; display: flex; gap: 10px;">
                        <button type="submit" name="li_save_polo" class="button button-primary button-large">
                            💾 <?php echo $edit_polo ? 'Salvar Alterações' : 'Cadastrar Polo'; ?>
                        </button>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-settings&tab=whatsapp')); ?>" class="button button-large">
                            Cancelar
                        </a>
                    </div>
                </form>
            </div>
        <?php
        else:
            // Listagem de Polos (Padrão)
            $settings_current = self::get_settings();
            $capture_on       = !isset($settings_current['enable_whatsapp_capture']) || !empty($settings_current['enable_whatsapp_capture']);
            $has_active       = false;
            foreach ($polos as $p) {
                if (($p['status'] ?? 'ativo') === 'ativo') {
                    $has_active = true;
                    break;
                }
            }
            $is_connected = $capture_on && $has_active;
        ?>
            <!-- CARD DE STATUS DE CONEXÃO DO WHATSAPP -->
            <div class="li-card" style="margin-bottom: 20px; border-left: 4px solid <?php echo $is_connected ? '#10b981' : '#f59e0b'; ?>; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px;">
                <div>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <h4 style="margin: 0; font-size: 15px; font-weight: 700; color: #0f172a;">
                            Status da Conexão do WhatsApp (Meta Cloud API)
                        </h4>
                        <?php if ($is_connected): ?>
                            <span class="li-badge" style="background: rgba(16, 185, 129, 0.15); color: #059669; font-weight: 700;">🟢 Conectado &amp; Capturando Leads</span>
                        <?php else: ?>
                            <span class="li-badge" style="background: rgba(245, 158, 11, 0.15); color: #d97706; font-weight: 700;">⚪ Desconectado / Nenhum Número Conectado</span>
                        <?php endif; ?>
                    </div>
                    <p style="margin: 4px 0 0; font-size: 13px; color: #64748b;">
                        <?php if ($is_connected): ?>
                            O webhook está ativo, processando mensagens recebidas e salvando leads vinculados à Meta para os números cadastrados.
                        <?php else: ?>
                            A captura está <strong>desconectada / sem números ativos</strong>. Nenhuma mensagem recebida da Meta será salva como lead no plugin.
                        <?php endif; ?>
                    </p>
                </div>
                <div>
                    <?php if ($is_connected): ?>
                        <form method="post" action="" onsubmit="return confirm('Tem certeza que deseja DESCONECTAR o WhatsApp? A captura de mensagens e criação de novos leads via WhatsApp serão pausadas imediatamente.');">
                            <?php wp_nonce_field('li_whatsapp_disconnect_verify', 'li_nonce'); ?>
                            <button type="submit" name="li_disconnect_whatsapp" class="button" style="color: #dc2626; border-color: #fca5a5; font-weight: 600; display: inline-flex; align-items: center; gap: 6px;">
                                🛑 Desconectar WhatsApp
                            </button>
                        </form>
                    <?php else: ?>
                        <form method="post" action="">
                            <?php wp_nonce_field('li_whatsapp_reconnect_verify', 'li_nonce'); ?>
                            <button type="submit" name="li_reconnect_whatsapp" class="button button-primary" style="background: #10b981; border-color: #10b981; font-weight: 600; display: inline-flex; align-items: center; gap: 6px;">
                                🟢 Reativar Captura de Mensagens
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- RESUMO DO TESTE DE CONEXÃO WABA -->
            <?php if ($test_result): ?>
                <?php if (!empty($test_result['success'])): ?>
                    <div class="notice notice-success is-dismissible" style="margin-bottom: 20px; padding: 12px 16px;">
                        <strong style="font-size: 14px;">✔ Conexão com a Meta Realizada com Sucesso!</strong>
                        <div style="margin-top: 6px; font-size: 13px;">
                            Nome Registrado: <strong><?php echo esc_html(!empty($test_result['verified_name']) ? $test_result['verified_name'] : (!empty($test_result['data']['verified_name']) ? $test_result['data']['verified_name'] : 'N/D')); ?></strong> |
                            Telefone Oficial: <strong><?php echo esc_html(!empty($test_result['display_phone']) ? $test_result['display_phone'] : (!empty($test_result['data']['display_phone_number']) ? $test_result['data']['display_phone_number'] : 'N/D')); ?></strong> |
                            Qualidade: <strong><?php echo esc_html(!empty($test_result['quality_rating']) ? $test_result['quality_rating'] : (!empty($test_result['data']['quality_rating']) ? $test_result['data']['quality_rating'] : 'GREEN')); ?></strong>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="notice notice-error is-dismissible" style="margin-bottom: 20px; padding: 12px 16px;">
                        <strong style="font-size: 14px;">❌ Falha de Conexão com a Meta: <?php echo esc_html($test_result['message']); ?></strong>
                        <div style="margin-top: 6px; font-size: 12px; color: #475569;">
                            Verifique se o <strong>Phone Number ID</strong> está correto e se o token possui a permissão <code>whatsapp_business_messaging</code>.
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <!-- CABEÇALHO DA LISTA DE POLOS -->
            <div class="li-card" style="padding: 0; overflow-x: auto;">
                <div style="padding: 16px 20px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                    <div>
                        <h3 class="li-card-title" style="margin: 0; font-size: 16px;">Números de WhatsApp / Polos Cadastrados</h3>
                        <p class="li-card-desc" style="margin: 2px 0 0; font-size: 12px;">
                            Cada polo opera de forma independente. Mensagens inbound do webhook são roteadas automaticamente para a unidade correspondente pelo Phone Number ID.
                        </p>
                    </div>
                    <div>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-settings&tab=whatsapp&action=new')); ?>" class="button button-primary button-large" style="display: inline-flex; align-items: center; gap: 6px;">
                            <span class="dashicons dashicons-plus-alt2" style="font-size: 16px; width: 16px; height: 16px; line-height: 16px;"></span>
                            Cadastrar Novo Número
                        </a>
                    </div>
                </div>

                <table class="wp-list-table widefat fixed striped li-table">
                    <thead>
                        <tr>
                            <th style="width: 220px;">Polo / Unidade</th>
                            <th style="width: 170px;">Número Exibido</th>
                            <th>Phone Number ID da Meta</th>
                            <th style="width: 110px; text-align: center;">Padrão</th>
                            <th style="width: 100px; text-align: center;">Status</th>
                            <th style="width: 220px; text-align: right;">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($polos)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 40px; color: #64748b;">
                                    Nenhum número de WhatsApp cadastrado ainda.<br><br>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-settings&tab=whatsapp&action=new')); ?>" class="button button-primary">
                                        + Cadastrar Primeiro Número
                                    </a>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($polos as $p): ?>
                                <tr>
                                    <td>
                                        <strong style="font-size: 14px; color: #0f172a;"><?php echo esc_html($p['nome']); ?></strong>
                                        <?php if (!empty($p['waba_id'])): ?>
                                            <div style="font-size: 11px; color: #64748b;">WABA: <?php echo esc_html($p['waba_id']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="font-weight: 600; color: #059669;">
                                            📞 <?php echo esc_html($p['display_phone'] ?: '-'); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <code style="font-size: 12px; background: #f1f5f9; padding: 2px 6px; border-radius: 4px;">
                                            <?php echo esc_html($p['phone_number_id']); ?>
                                        </code>
                                    </td>
                                    <td style="text-align: center;">
                                        <?php if (!empty($p['is_default'])): ?>
                                            <span class="li-badge" style="background: rgba(16, 185, 129, 0.15); color: #059669; font-weight: 700;">
                                                ⭐ Padrão
                                            </span>
                                        <?php else: ?>
                                            <span style="color: #94a3b8;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: center; vertical-align: middle;">
                                        <div style="display: inline-flex; align-items: center; justify-content: center; gap: 8px;">
                                            <form method="post" action="" style="display: inline-flex; align-items: center; margin: 0; padding: 0;">
                                                <?php wp_nonce_field('li_polo_toggle_verify', 'li_nonce'); ?>
                                                <input type="hidden" name="toggle_polo_id" value="<?php echo esc_attr($p['id']); ?>">
                                                <label class="li-switch" title="Clique para <?php echo (($p['status'] ?? 'ativo') === 'ativo') ? 'desativar' : 'ativar'; ?> este número">
                                                    <input type="checkbox" name="li_toggle_polo_status" value="1" <?php checked(($p['status'] ?? 'ativo'), 'ativo'); ?> onchange="this.form.submit();">
                                                    <span class="li-slider"></span>
                                                </label>
                                            </form>
                                            <span style="font-size: 11px; font-weight: 700; color: <?php echo (($p['status'] ?? 'ativo') === 'ativo') ? '#059669' : '#94a3b8'; ?>;">
                                                <?php echo (($p['status'] ?? 'ativo') === 'ativo') ? 'Ativo' : 'Inativo'; ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td style="text-align: right;">
                                        <div style="display: inline-flex; align-items: center; gap: 6px;">
                                            <!-- Testar Conexão -->
                                            <form method="post" action="" style="display: inline;">
                                                <?php wp_nonce_field('li_polo_test_verify', 'li_nonce'); ?>
                                                <input type="hidden" name="target_phone_number_id" value="<?php echo esc_attr($p['phone_number_id']); ?>">
                                                <button type="submit" name="li_test_polo" class="button button-secondary" title="Testa a conexão deste número com a Graph API da Meta" style="font-size: 12px; padding: 0 8px; height: 28px; line-height: 26px;">
                                                    🧪 Testar
                                                </button>
                                            </form>

                                            <!-- Editar -->
                                            <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-settings&tab=whatsapp&action=edit&id=' . urlencode($p['id']))); ?>" class="button button-secondary" style="font-size: 12px; padding: 0 8px; height: 28px; line-height: 26px;">
                                                ✏️ Editar
                                            </a>

                                            <!-- Excluir -->
                                            <form method="post" action="" style="display: inline;" onsubmit="return confirm('Tem certeza que deseja excluir o polo <?php echo esc_js($p['nome']); ?>?');">
                                                <?php wp_nonce_field('li_polo_delete_verify', 'li_nonce'); ?>
                                                <input type="hidden" name="delete_polo_id" value="<?php echo esc_attr($p['id']); ?>">
                                                <button type="submit" name="li_delete_polo" class="button button-secondary" style="color: #dc2626; border-color: #fca5a5; font-size: 12px; padding: 0 8px; height: 28px; line-height: 26px;" title="Remover este polo">
                                                    🗑️
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <!-- GUIA PROFISSIONAL: MANUAL DE INTEGRAÇÃO & ENVIO SIMULTÂNEO -->
            <div class="li-card" style="margin-top: 25px; border-top: 4px solid #6366f1; background: #ffffff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); padding: 24px;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 15px; margin-bottom: 20px; border-bottom: 1px solid #e2e8f0; padding-bottom: 16px;">
                    <div>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span style="font-size: 22px;">📘</span>
                            <h3 style="margin: 0; font-size: 18px; font-weight: 700; color: #1e1b4b;">
                                Manual Oficial de Integração: Como Configurar e Ativar Qualquer Número no WhatsApp Cloud API
                            </h3>
                        </div>
                        <p style="margin: 4px 0 0; font-size: 13px; color: #64748b; line-height: 1.5;">
                            Siga este procedimento passo a passo para conectar novos números de WhatsApp ou ativar o <strong>envio simultâneo (Multi-App)</strong> sem interferir no seu sistema atual (ex: InterageZap).
                        </p>
                    </div>
                    <div>
                        <span class="li-badge" style="background: #e0e7ff; color: #4338ca; font-weight: 700; font-size: 12px; padding: 6px 12px; border-radius: 6px;">
                            🚀 Multi-App Habilitado (Zero Conflitos)
                        </span>
                    </div>
                </div>

                <!-- CARDS DE ETAPAS INTERATIVAS -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; margin-bottom: 24px;">
                    
                    <!-- ETAPA 1 -->
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px; display: flex; flex-direction: column;">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 10px;">
                            <span style="background: #4f46e5; color: #fff; width: 24px; height: 24px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700;">1</span>
                            <h4 style="margin: 0; font-size: 14px; font-weight: 700; color: #1e293b;">Obter Dados no Meta Business</h4>
                        </div>
                        <p style="font-size: 12px; color: #475569; margin: 0 0 12px 0; line-height: 1.5; flex: 1;">
                            No <strong>Business Manager</strong> &gt; <em>Contas do WhatsApp</em> &gt; selecione sua WABA:
                        </p>
                        <ul style="margin: 0; padding-left: 18px; font-size: 12px; color: #334155; line-height: 1.6;">
                            <li><strong>Phone Number ID</strong>: ID de 15 dígitos do chip.</li>
                            <li><strong>WABA ID</strong>: Identificador da conta WhatsApp.</li>
                            <li><strong>Token do Usuário do Sistema</strong> com permissões:
                                <code style="background:#e2e8f0; font-size:10px;">whatsapp_business_messaging</code>, 
                                <code style="background:#e2e8f0; font-size:10px;">whatsapp_business_management</code> e 
                                <code style="background:#e2e8f0; font-size:10px;">ads_read</code>.
                            </li>
                        </ul>
                    </div>

                    <!-- ETAPA 2 -->
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px; display: flex; flex-direction: column;">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 10px;">
                            <span style="background: #4f46e5; color: #fff; width: 24px; height: 24px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700;">2</span>
                            <h4 style="margin: 0; font-size: 14px; font-weight: 700; color: #1e293b;">Cadastrar no Plugin</h4>
                        </div>
                        <p style="font-size: 12px; color: #475569; margin: 0 0 12px 0; line-height: 1.5; flex: 1;">
                            Clique no botão verde <strong>"+ Cadastrar Novo Número"</strong> acima e insira:
                        </p>
                        <ul style="margin: 0; padding-left: 18px; font-size: 12px; color: #334155; line-height: 1.6;">
                            <li>Nome da Unidade / Polo (ex: <code>Polo VNI</code>).</li>
                            <li>Telefone formatado com DDD.</li>
                            <li>Phone Number ID e WABA ID obtidos.</li>
                            <li>Token permanente do Usuário do Sistema.</li>
                            <li>Deixe o status como <strong>🟢 Ativo</strong>.</li>
                        </ul>
                    </div>

                    <!-- ETAPA 3 -->
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px; display: flex; flex-direction: column;">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 10px;">
                            <span style="background: #4f46e5; color: #fff; width: 24px; height: 24px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700;">3</span>
                            <h4 style="margin: 0; font-size: 14px; font-weight: 700; color: #1e293b;">Apontar o Webhook no App</h4>
                        </div>
                        <p style="font-size: 12px; color: #475569; margin: 0 0 12px 0; line-height: 1.5; flex: 1;">
                            No <strong>developers.facebook.com</strong> &gt; Seu App &gt; <em>Webhooks</em> &gt; <code>Whatsapp Business Account</code>:
                        </p>
                        <ul style="margin: 0; padding-left: 18px; font-size: 12px; color: #334155; line-height: 1.6;">
                            <li>Cole a <strong>URL de Callback</strong> abaixo.</li>
                            <li>Cole o <strong>Verify Token</strong>.</li>
                            <li>Na lista de campos, assine <strong>messages</strong>.</li>
                            <li>Coloque o App em modo <strong>Ao Vivo (Live)</strong>.</li>
                        </ul>
                    </div>

                    <!-- ETAPA 4 -->
                    <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 18px; display: flex; flex-direction: column;">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 10px;">
                            <span style="background: #16a34a; color: #fff; width: 24px; height: 24px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700;">4</span>
                            <h4 style="margin: 0; font-size: 14px; font-weight: 700; color: #14532d;">Ativar Envio Simultâneo</h4>
                        </div>
                        <p style="font-size: 12px; color: #166534; margin: 0 0 12px 0; line-height: 1.5; flex: 1;">
                            Na lista acima, clique no botão <strong>"🧪 Testar"</strong> ao lado do seu número:
                        </p>
                        <div style="font-size: 12px; color: #15803d; line-height: 1.5;">
                            ✔ O plugin valida a conexão com a Meta.<br>
                            ✔ Inscreve automaticamente o App na WABA (<code>subscribed_apps</code>).<br>
                            ✔ A Meta passa a enviar para <strong>ambos os sistemas ao mesmo tempo</strong>!
                        </div>
                    </div>

                </div>

                <!-- DADOS RÁPIDOS PARA CÓPIA (WEBHOOK & TOKEN) -->
                <div style="background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 8px; padding: 16px 20px;">
                    <div style="font-size: 12px; font-weight: 700; color: #334155; text-transform: uppercase; margin-bottom: 10px; display: flex; align-items: center; gap: 6px;">
                        <span>📋</span> Dados de Conexão Rápida para o Portal da Meta (developers.facebook.com)
                    </div>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 14px;">
                        <div style="background: #ffffff; padding: 10px 14px; border-radius: 6px; border: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; gap: 10px;">
                            <div style="overflow: hidden;">
                                <div style="font-size: 10px; text-transform: uppercase; color: #64748b; font-weight: 700;">URL de Callback do Webhook</div>
                                <code style="font-size: 12px; color: #0284c7; font-weight: 600; white-space: nowrap;"><?php echo esc_url($webhook_url); ?></code>
                            </div>
                            <button type="button" class="button button-small" onclick="navigator.clipboard.writeText('<?php echo esc_js($webhook_url); ?>'); this.innerText='Copiado!'; setTimeout(()=>{this.innerText='Copiar'}, 2000);">
                                Copiar
                            </button>
                        </div>
                        <div style="background: #ffffff; padding: 10px 14px; border-radius: 6px; border: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; gap: 10px;">
                            <div>
                                <div style="font-size: 10px; text-transform: uppercase; color: #64748b; font-weight: 700;">Verify Token (Token de Verificação)</div>
                                <code style="font-size: 13px; color: #059669; font-weight: 700;"><?php echo esc_html($settings_current['meta_verify_token']); ?></code>
                            </div>
                            <button type="button" class="button button-small" onclick="navigator.clipboard.writeText('<?php echo esc_js($settings_current['meta_verify_token']); ?>'); this.innerText='Copiado!'; setTimeout(()=>{this.innerText='Copiar'}, 2000);">
                                Copiar
                            </button>
                        </div>
                    </div>
                </div>
            </div>

        <?php
        endif;
    }

    /**
     * ABA 2: Conexão Meta & Webhook
     */
    private static function render_tab_meta($settings) {
        $webhook_url = get_rest_url(null, 'lead-intelligence/v1/meta/webhook');
        $meta_url_params = 'utm_source=meta&utm_medium=cpc&utm_campaign={{campaign.name}}&utm_content={{adset.name}}&utm_term={{ad.name}}&campaign_id={{campaign.id}}&adset_id={{adset.id}}&ad_id={{ad.id}}&campaign_name={{campaign.name}}&adset_name={{adset.name}}&ad_name={{ad.name}}';
        ?>
        <!-- GUIA DE RASTREAMENTO DE ANÚNCIOS META ADS -->
        <div class="li-card" style="border-left: 4px solid #0081fb; margin-bottom: 20px; background: #ffffff;">
            <h3 class="li-card-title" style="margin: 0 0 6px 0; font-size: 16px; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                <span>🎯</span> Guia: Como Rastrear Nome de Anúncio e Campanha no Meta Ads (Facebook &amp; Instagram)
            </h3>
            <p class="li-card-desc" style="margin: 0 0 14px 0; font-size: 13px; color: #475569;">
                O Lead Intelligence é 100% preparado para capturar e exibir o <strong>Nome do Anúncio</strong>, <strong>Conjunto</strong> e <strong>Campanha</strong> de cada lead. Veja abaixo como configurar no Gerenciador de Anúncios da Meta para nunca mais esquecer:
            </p>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 14px;">
                <!-- FLUXO 1: ANÚNCIOS PARA O SITE / FORMULÁRIO ELEMENTOR -->
                <div style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; padding: 14px 16px;">
                    <div style="font-weight: 700; font-size: 12px; color: #1e3a8a; text-transform: uppercase; margin-bottom: 6px; display: flex; align-items: center; gap: 6px;">
                        <span>🌐</span> 1. Anúncios para o Site (Elementor / Landing Page)
                    </div>
                    <p style="margin: 0 0 8px 0; font-size: 12px; color: #475569; line-height: 1.5;">
                        No <strong>Meta Ads Manager</strong>, edite seu anúncio (nível <strong>Anúncio / Ad</strong>) &gt; role até a seção <strong>Rastreamento</strong> &gt; campo <strong>"Parâmetros de URL"</strong> e cole:
                    </p>
                    <div style="display: flex; gap: 8px; align-items: center; margin-bottom: 8px;">
                        <code style="font-size: 11px; background: #ffffff; color: #0284c7; padding: 7px 10px; border-radius: 4px; border: 1px solid #cbd5e1; flex: 1; overflow-x: auto; white-space: nowrap; font-family: monospace; font-weight: 600;">
                            <?php echo esc_html($meta_url_params); ?>
                        </code>
                        <button type="button" class="button button-secondary button-small" onclick="navigator.clipboard.writeText('<?php echo esc_js($meta_url_params); ?>'); this.innerText='Copiado!'; setTimeout(()=>{this.innerText='Copiar'}, 2000);" style="font-weight: 600; white-space: nowrap;">
                            📋 Copiar Parâmetros
                        </button>
                    </div>
                    <div style="font-size: 11px; color: #64748b; line-height: 1.4;">
                        ✔ A Meta preenche as variáveis <code>{{ad.name}}</code>, <code>{{campaign.name}}</code> etc. dinamicamente a cada clique.<br>
                        ✔ O plugin preserva esses dados por 30 dias nos cookies e injeta no formulário do Elementor.
                    </div>
                </div>

                <!-- FLUXO 2: ANÚNCIOS CLICK-TO-WHATSAPP (CTWA) -->
                <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 14px 16px;">
                    <div style="font-weight: 700; font-size: 12px; color: #166534; text-transform: uppercase; margin-bottom: 6px; display: flex; align-items: center; gap: 6px;">
                        <span>💬</span> 2. Anúncios Click-to-WhatsApp (Conversas no WhatsApp)
                    </div>
                    <p style="margin: 0 0 8px 0; font-size: 12px; color: #334155; line-height: 1.5;">
                        Quando o lead inicia a conversa pelo anúncio, a Meta envia o <code>ad_id</code> pelo webhook.
                    </p>
                    <div style="font-size: 12px; color: #15803d; line-height: 1.5;">
                        • Certifique-se de que o <strong>Access Token</strong> configurado abaixo tenha a permissão <code>ads_read</code>.<br>
                        • O plugin consulta a Meta Marketing API e preenche o <strong>Nome Oficial do Anúncio</strong> e da <strong>Campanha</strong> automaticamente!<br>
                        • Você também pode clicar no botão de sincronização no menu <strong>Meta Ads</strong> a qualquer momento.
                    </div>
                </div>
            </div>
        </div>

        <div class="li-card" style="border-left: 4px solid #10b981; margin-bottom: 20px;">
            <h3 class="li-card-title">🔗 Webhook Próprio do Plugin (Meta Cloud API)</h3>
            <p class="li-card-desc">
                Este é o endpoint exclusivo do Lead Intelligence. Ele <strong>NÃO substitui nem interfere</strong> no webhook atual do InterageZap. A Meta permite múltiplos Apps e webhooks subscritos na mesma WABA.
            </p>

            <div style="background: #f8fafc; padding: 14px 18px; border-radius: 8px; border: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; gap: 15px; flex-wrap: wrap; margin-bottom: 15px;">
                <div>
                    <div style="font-size: 11px; text-transform: uppercase; color: #64748b; font-weight: 600;">URL de Retorno de Chamada (Callback URL)</div>
                    <code style="font-size: 14px; color: #0284c7; font-weight: 600;"><?php echo esc_url($webhook_url); ?></code>
                </div>
                <button type="button" class="button" onclick="navigator.clipboard.writeText('<?php echo esc_js($webhook_url); ?>'); alert('URL do Webhook copiada com sucesso!');">
                    📋 Copiar URL
                </button>
            </div>

            <div style="background: #f8fafc; padding: 14px 18px; border-radius: 8px; border: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; gap: 15px; flex-wrap: wrap;">
                <div>
                    <div style="font-size: 11px; text-transform: uppercase; color: #64748b; font-weight: 600;">Verify Token (Token de Verificação)</div>
                    <code style="font-size: 14px; color: #059669; font-weight: 700;"><?php echo esc_html($settings['meta_verify_token']); ?></code>
                </div>
                <button type="button" class="button" onclick="navigator.clipboard.writeText('<?php echo esc_js($settings['meta_verify_token']); ?>'); alert('Verify Token copiado!');">
                    📋 Copiar Token
                </button>
            </div>
        </div>

        <div class="li-card" style="border-left: 4px solid #6366f1; margin-top: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                <div>
                    <h3 class="li-card-title" style="margin: 0 0 6px 0; font-size: 16px; color: #1e1b4b; display: flex; align-items: center; gap: 8px;">
                        <span>📱</span> Gestão Descentralizada de Credenciais (Multi-App)
                    </h3>
                    <p class="li-card-desc" style="margin: 0; font-size: 13px; color: #475569; max-width: 780px; line-height: 1.5;">
                        Como cada número de WhatsApp da sua instituição possui seu próprio Meta App, o <strong>Phone Number ID</strong>, o <strong>WABA ID</strong> e o <strong>Access Token</strong> devem ser configurados diretamente no cadastro de cada número/polo na aba <strong>Números de WhatsApp (Polos Isolados)</strong>. Cada linha opera de forma 100% isolada e independente.
                    </p>
                </div>
                <div>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-settings&tab=whatsapp')); ?>" class="button button-primary button-large" style="display: inline-flex; align-items: center; gap: 6px;">
                        <span class="dashicons dashicons-phone" style="font-size: 16px; width: 16px; height: 16px; line-height: 16px;"></span>
                        Gerenciar Números de WhatsApp
                    </a>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * ABA 3: Formulários & Elementor
     */
    private static function render_tab_forms($settings) {
        $inbound_relay_url = get_rest_url(null, 'lead-intelligence/v1/webhook/inbound');
        ?>
        <form method="post" action="">
            <?php wp_nonce_field('li_settings_verify', 'li_nonce'); ?>
            <input type="hidden" name="current_tab" value="forms">
            <input type="hidden" name="has_forms_tab" value="1">

            <div class="li-card" style="margin-bottom: 20px;">
                <h3 class="li-card-title">1. Captura Passiva de Formulários (Elementor Pro)</h3>
                <p class="li-card-desc">
                    O plugin ouve passivamente o evento de envio do Elementor sem interceptar ou modificar o webhook do InterageZap.
                </p>

                <table class="form-table">
                    <tr>
                        <th scope="row">Ativar Captura do Elementor</th>
                        <td>
                            <label class="li-switch">
                                <input type="checkbox" name="enable_elementor_capture" value="1" <?php checked($settings['enable_elementor_capture'], 1); ?>>
                                <span class="li-slider"></span>
                            </label>
                            <p class="description">Captura e salva automaticamente no banco isolado de leads.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Preservar UTMs e Meta Clicks</th>
                        <td>
                            <label class="li-switch">
                                <input type="checkbox" name="utm_preservation" value="1" <?php checked($settings['utm_preservation'], 1); ?>>
                                <span class="li-slider"></span>
                            </label>
                            <p class="description">Guarda <code>utm_source</code>, <code>utm_campaign</code>, <code>ad_name</code>, <code>fbclid</code>, <code>gclid</code> e parâmetros do Google e Meta nos cookies e formulários.</p>
                            <p class="description" style="margin-top: 6px; background: #eff6ff; padding: 8px 12px; border-radius: 6px; border: 1px solid #bfdbfe; color: #1e3a8a;">
                                💡 <strong>Dica Meta Ads:</strong> Para que o nome do anúncio e da campanha venham preenchidos automaticamente do Facebook e Instagram, use o nosso modelo de parâmetros de URL: <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-settings&tab=meta')); ?>" style="font-weight: 700; color: #0284c7;">Ver modelo de URL na aba Conexão Meta &rarr;</a>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Modo Debug / Logs Detalhados</th>
                        <td>
                            <label class="li-switch">
                                <input type="checkbox" name="enable_debug_logging" value="1" <?php checked($settings['enable_debug_logging'], 1); ?>>
                                <span class="li-slider"></span>
                            </label>
                            <p class="description">Registra dados detalhados na aba de Logs para auditoria técnica.</p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="li-card" style="margin-bottom: 20px;">
                <h3 class="li-card-title">2. Mapeamento Flexível de Campos (De-Para)</h3>
                <p class="li-card-desc">Identificadores dos campos nos formulários do Elementor, separados por vírgula.</p>

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
                            <p class="description">Ex: <code>tipo_curso, curso, modalidade, tipo, pos_graduacao</code></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="field_mapping_area_interesse">Área de Interesse</label></th>
                        <td>
                            <input type="text" id="field_mapping_area_interesse" name="field_mapping_area_interesse" value="<?php echo esc_attr($settings['field_mapping']['area_interesse']); ?>" class="regular-text">
                            <p class="description">Ex: <code>area_interesse, area, interesse, especializacao</code></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="field_mapping_polo">Polo / Unidade</label></th>
                        <td>
                            <input type="text" id="field_mapping_polo" name="field_mapping_polo" value="<?php echo esc_attr($settings['field_mapping']['polo'] ?? ''); ?>" class="regular-text">
                            <p class="description">Ex: <code>polo, unidade, cidade, polo_apoio, campus</code></p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="li-card" style="margin-bottom: 20px; border-left: 4px solid #f59e0b;">
                <h3 class="li-card-title">3. Webhook Inbound & Retransmissão (Relay n8n)</h3>
                <p class="li-card-desc">
                    Permite receber dados de webhooks externos, gravá-los no Lead Intelligence primeiro e retransmiti-los automaticamente para o n8n ou outros sistemas.
                </p>

                <div style="background: #f8fafc; padding: 14px 18px; border-radius: 6px; border: 1px solid #e2e8f0; margin-bottom: 18px; display: flex; flex-direction: column; gap: 14px;">
                    <div>
                        <div style="font-size: 11px; text-transform: uppercase; color: #64748b; font-weight: 700; margin-bottom: 4px;">ENDPOINT DE ENTRADA PARA ENVIAR AO PLUGIN:</div>
                        <div style="display: flex; gap: 8px; align-items: center;">
                            <code id="li_inbound_url_display" style="font-size: 13px; color: #0284c7; font-weight: 600; background: #ffffff; padding: 7px 12px; border-radius: 4px; border: 1px solid #cbd5e1; flex: 1; user-select: all;"><?php echo esc_url($inbound_relay_url); ?></code>
                            <button type="button" class="button button-secondary" onclick="navigator.clipboard.writeText('<?php echo esc_js($inbound_relay_url); ?>'); this.innerText='Copiado!'; setTimeout(()=>{this.innerText='Copiar URL'}, 2000);">Copiar URL</button>
                        </div>
                    </div>

                    <div>
                        <div style="font-size: 11px; text-transform: uppercase; color: #64748b; font-weight: 700; margin-bottom: 4px;">CHAVE DO WEBHOOK (COLE NO CAMPO "CHAVE" DO GOOGLE ADS OU META ADS):</div>
                        <div style="display: flex; gap: 8px; align-items: center;">
                            <input type="text" id="webhook_inbound_key" name="webhook_inbound_key" value="<?php echo esc_attr($settings['webhook_inbound_key'] ?? '4b06e7d4b960'); ?>" class="regular-text" style="font-family: monospace; font-weight: 700; font-size: 13px; color: #0f172a; background: #ffffff; height: 34px; max-width: 360px;" placeholder="Ex: 4b06e7d4b960">
                            <button type="button" class="button button-secondary" onclick="navigator.clipboard.writeText(document.getElementById('webhook_inbound_key').value); this.innerText='Copiado!'; setTimeout(()=>{this.innerText='Copiar Chave'}, 2000);">Copiar Chave</button>
                        </div>
                        <p class="description" style="margin: 4px 0 0 0;">Esta é a chave padrão definida para validação das extensões de formulário de leads do Google Ads e Meta Ads.</p>
                    </div>

                    <!-- GUIA VISUAL DE PARÂMETROS NA URL DO GOOGLE ADS -->
                    <div style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 6px; padding: 12px 16px; margin-top: 6px;">
                        <div style="font-size: 12px; font-weight: 700; color: #0f172a; margin-bottom: 6px; display: flex; align-items: center; gap: 6px;">
                            <span>💡</span> Como identificar Campanhas, Formulários e Instâncias diretamente na URL do Webhook do Google Ads:
                        </div>
                        <p style="margin: 0 0 8px 0; font-size: 12px; color: #475569; line-height: 1.5;">
                            No Google Ads, ao criar a extensão de formulário de lead, você pode adicionar parâmetros na URL do Webhook. O plugin captura automaticamente esses valores e preenche os campos do lead:
                        </p>
                        <?php 
                        $example_url = $inbound_relay_url . '?utm_campaign=pos-graduacao-2026&formulario_nome=Formul%C3%A1rio+P%C3%B3s+01&instancia=Faveni&tipo_curso=P%C3%B3s-Gradua%C3%A7%C3%A3o';
                        ?>
                        <div style="display: flex; gap: 8px; align-items: center; margin-bottom: 8px;">
                            <code style="font-size: 11px; background: #f1f5f9; color: #0f766e; padding: 6px 10px; border-radius: 4px; border: 1px solid #e2e8f0; flex: 1; overflow-x: auto; white-space: nowrap; font-family: monospace;">
                                <?php echo esc_html($example_url); ?>
                            </code>
                            <button type="button" class="button button-small" onclick="navigator.clipboard.writeText('<?php echo esc_js($example_url); ?>'); this.innerText='Copiado!'; setTimeout(()=>{this.innerText='Copiar Exemplo'}, 2000);">Copiar Exemplo</button>
                        </div>
                        <div style="display: flex; gap: 12px; flex-wrap: wrap; font-size: 11px; color: #64748b;">
                            <span>• <code>utm_campaign</code>: Nome amigável da campanha</span>
                            <span>• <code>formulario_nome</code>: Nome do recurso de formulário</span>
                            <span>• <code>instancia</code>: Faveni, Sao Paulo, Cerquilho, etc.</span>
                            <span>• <code>tipo_curso</code>: Pós-Graduação, Graduação, etc.</span>
                        </div>
                    </div>
                </div>

                <table class="form-table">
                    <tr>
                        <th scope="row">Ativar Retransmissão Automática</th>
                        <td>
                            <label class="li-switch">
                                <input type="checkbox" name="enable_webhook_relay" value="1" <?php checked(!empty($settings['enable_webhook_relay'])); ?>>
                                <span class="li-slider"></span>
                            </label>
                            <p class="description">Após salvar no Lead Intelligence, encaminha o payload para a URL de destino abaixo.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="webhook_relay_target_url">URL de Destino do Relay (n8n)</label></th>
                        <td>
                            <input type="url" id="webhook_relay_target_url" name="webhook_relay_target_url" value="<?php echo esc_attr($settings['webhook_relay_target_url'] ?? ''); ?>" class="large-text" placeholder="https://n8n.favbot.ai/webhook/...">
                            <p class="description">Webhook do n8n para onde o lead será despachado após ser seguro no banco local.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="webhook_inbound_utm_campaign">UTM Campaign (Campanha Padrão)</label></th>
                        <td>
                            <input type="text" id="webhook_inbound_utm_campaign" name="webhook_inbound_utm_campaign" value="<?php echo esc_attr($settings['webhook_inbound_utm_campaign'] ?? 'formulario google'); ?>" class="regular-text" placeholder="formulario google">
                            <p class="description">Nome da campanha atribuído aos leads que entram pelo formulário do Google Ads / Webhook Inbound (ex: <code>formulario google</code>).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="webhook_inbound_utm_source">UTM Source (Origem Padrão)</label></th>
                        <td>
                            <input type="text" id="webhook_inbound_utm_source" name="webhook_inbound_utm_source" value="<?php echo esc_attr($settings['webhook_inbound_utm_source'] ?? 'google_lead_form'); ?>" class="regular-text" placeholder="google_lead_form">
                            <p class="description">Origem do lead no Lead Intelligence e repassada ao n8n (ex: <code>google_lead_form</code>).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="webhook_inbound_utm_medium">UTM Medium (Mídia Padrão)</label></th>
                        <td>
                            <input type="text" id="webhook_inbound_utm_medium" name="webhook_inbound_utm_medium" value="<?php echo esc_attr($settings['webhook_inbound_utm_medium'] ?? 'lead_form'); ?>" class="regular-text" placeholder="lead_form">
                            <p class="description">Meio ou formato de captura (ex: <code>lead_form</code>).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="webhook_inbound_form_name">Nome do Formulário</label></th>
                        <td>
                            <input type="text" id="webhook_inbound_form_name" name="webhook_inbound_form_name" value="<?php echo esc_attr($settings['webhook_inbound_form_name'] ?? 'Formulário Google'); ?>" class="regular-text" placeholder="Formulário Google">
                            <p class="description">Identificação visível na listagem de leads e no histórico (ex: <code>Formulário Google</code>).</p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="li-card" style="margin-bottom: 20px; border-left: 4px solid #10b981;">
                <h3 class="li-card-title">4. De-Para Inteligente de Formulários e Campanhas do Google Ads</h3>
                <p class="li-card-desc">
                    O Google Ads envia identificadores numéricos no payload (ex: <code>campaign_id: 24381253178</code> e <code>form_id: 432482534580</code>). Cadastre aqui regras de correspondência para dar nomes amigáveis às suas campanhas e formulários, além de definir automaticamente a <strong>Instância</strong> e o <strong>Curso</strong>.
                </p>

                <?php 
                $gform_mappings = !empty($settings['google_lead_form_mappings']) && is_array($settings['google_lead_form_mappings']) 
                    ? $settings['google_lead_form_mappings'] 
                    : [];
                ?>

                <div style="overflow-x: auto; margin-bottom: 12px;">
                    <table class="wp-list-table widefat fixed striped" id="li_gform_mapping_table" style="border: 1px solid #cbd5e1; border-radius: 6px;">
                        <thead>
                            <tr style="background: #f8fafc;">
                                <th style="width: 25%; font-weight: 600;">ID do Google Ads (campaign_id / form_id)</th>
                                <th style="width: 18%; font-weight: 600;">Tipo de ID</th>
                                <th style="width: 22%; font-weight: 600;">Nome Amigável / Campanha</th>
                                <th style="width: 16%; font-weight: 600;">Instância</th>
                                <th style="width: 14%; font-weight: 600;">Tipo de Curso</th>
                                <th style="width: 5%; text-align: center; font-weight: 600;">Remover</th>
                            </tr>
                        </thead>
                        <tbody id="li_gform_mapping_tbody">
                            <?php if (!empty($gform_mappings)): ?>
                                <?php foreach ($gform_mappings as $idx => $map): ?>
                                    <tr>
                                        <td>
                                            <input type="text" name="gform_mapping_id[]" value="<?php echo esc_attr($map['id_google'] ?? ''); ?>" placeholder="Ex: 24381253178" class="regular-text" style="width: 100%; font-family: monospace; font-weight: 600;">
                                        </td>
                                        <td>
                                            <select name="gform_mapping_tipo[]" style="width: 100%;">
                                                <option value="campaign" <?php selected(($map['tipo_id'] ?? 'campaign'), 'campaign'); ?>>📢 Campanha (campaign_id)</option>
                                                <option value="form" <?php selected(($map['tipo_id'] ?? ''), 'form'); ?>>📝 Formulário (form_id)</option>
                                            </select>
                                        </td>
                                        <td>
                                            <input type="text" name="gform_mapping_nome[]" value="<?php echo esc_attr($map['nome_amigavel'] ?? ''); ?>" placeholder="Ex: Pós-Graduação Faveni 2026" style="width: 100%;">
                                        </td>
                                        <td>
                                            <input type="text" name="gform_mapping_instancia[]" value="<?php echo esc_attr($map['instancia'] ?? ''); ?>" placeholder="Ex: Faveni" style="width: 100%;">
                                        </td>
                                        <td>
                                            <input type="text" name="gform_mapping_curso[]" value="<?php echo esc_attr($map['tipo_curso'] ?? ''); ?>" placeholder="Ex: Pós-Graduação" style="width: 100%;">
                                        </td>
                                        <td style="text-align: center; vertical-align: middle;">
                                            <button type="button" class="button button-small" onclick="this.closest('tr').remove();" style="color: #dc2626;" title="Remover regra">&times;</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr id="li_gform_empty_row">
                                    <td colspan="6" style="text-align: center; color: #64748b; padding: 18px;">
                                        Nenhuma regra de mapeamento cadastrada. Adicione uma regra abaixo ou passe parâmetros via query string na URL do webhook.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <button type="button" class="button" id="li_add_gform_mapping_btn" style="display: inline-flex; align-items: center; gap: 6px; font-weight: 600;">
                    <span class="dashicons dashicons-plus-alt2" style="font-size: 16px; width: 16px; height: 16px; line-height: 16px;"></span>
                    Adicionar Regra de Mapeamento
                </button>
            </div>

            <script>
            document.addEventListener('DOMContentLoaded', function() {
                var addBtn = document.getElementById('li_add_gform_mapping_btn');
                var tbody = document.getElementById('li_gform_mapping_tbody');
                if (addBtn && tbody) {
                    addBtn.addEventListener('click', function() {
                        var emptyRow = document.getElementById('li_gform_empty_row');
                        if (emptyRow) emptyRow.remove();

                        var tr = document.createElement('tr');
                        tr.innerHTML = '<td><input type="text" name="gform_mapping_id[]" value="" placeholder="Ex: 24381253178" class="regular-text" style="width: 100%; font-family: monospace; font-weight: 600;" required></td>' +
                            '<td><select name="gform_mapping_tipo[]" style="width: 100%;"><option value="campaign">📢 Campanha (campaign_id)</option><option value="form">📝 Formulário (form_id)</option></select></td>' +
                            '<td><input type="text" name="gform_mapping_nome[]" value="" placeholder="Ex: Pós-Graduação Faveni 2026" style="width: 100%;"></td>' +
                            '<td><input type="text" name="gform_mapping_instancia[]" value="" placeholder="Ex: Faveni" style="width: 100%;"></td>' +
                            '<td><input type="text" name="gform_mapping_curso[]" value="" placeholder="Ex: Pós-Graduação" style="width: 100%;"></td>' +
                            '<td style="text-align: center; vertical-align: middle;"><button type="button" class="button button-small" onclick="this.closest(\'tr\').remove();" style="color: #dc2626;" title="Remover regra">&times;</button></td>';
                        tbody.appendChild(tr);
                    });
                }
            });
            </script>
            </div>

            <p class="submit">
                <button type="submit" name="li_save_settings" class="button button-primary button-large">
                    Salvar Configurações de Formulários
                </button>
            </p>
        </form>
        <?php
    }

    /**
     * ABA 4: Meta Conversions API (CAPI)
     */
    private static function render_tab_capi($settings, $capi_test_result = null) {
        ?>
        <?php if ($capi_test_result): ?>
            <?php if (!empty($capi_test_result['success'])): ?>
                <div class="notice notice-success is-dismissible" style="margin-bottom: 20px; padding: 12px 16px;">
                    <strong style="font-size: 14px;">✔ Evento de Teste Enviado para a Conversions API!</strong>
                    <div style="margin-top: 4px; font-size: 12px;">Eventos recebidos pela Meta: <?php echo esc_html($capi_test_result['response']['events_received'] ?? $capi_test_result['events_received'] ?? 1); ?>. Verifique no Gerenciador de Eventos da Meta.</div>
                </div>
            <?php else: ?>
                <div class="notice notice-error is-dismissible" style="margin-bottom: 20px; padding: 12px 16px;">
                    <strong style="font-size: 14px;">❌ Falha no Envio CAPI: <?php echo esc_html($capi_test_result['message'] ?? $capi_test_result['error'] ?? 'Erro desconhecido'); ?></strong>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <form method="post" action="">
            <?php wp_nonce_field('li_settings_verify', 'li_nonce'); ?>
            <input type="hidden" name="current_tab" value="capi">
            <input type="hidden" name="has_capi_form" value="1">

            <div class="li-card" style="margin-bottom: 20px;">
                <h3 class="li-card-title">Meta Conversions API (CAPI)</h3>
                <p class="li-card-desc">
                    Transmissão server-side com dados sensíveis hasheados em SHA-256 e preservação de cookies de clique.
                </p>

                <table class="form-table">
                    <tr>
                        <th scope="row">Disparar Evento 'Lead'</th>
                        <td>
                            <label class="li-switch">
                                <input type="checkbox" name="enable_capi_lead" value="1" <?php checked($settings['enable_capi_lead'], 1); ?>>
                                <span class="li-slider"></span>
                            </label>
                            <p class="description">Dispara o evento <code>Lead</code> para a Meta assim que um formulário for capturado.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Disparar Evento 'LeadQualified'</th>
                        <td>
                            <label class="li-switch">
                                <input type="checkbox" name="enable_capi_qualified" value="1" <?php checked($settings['enable_capi_qualified'], 1); ?>>
                                <span class="li-slider"></span>
                            </label>
                            <p class="description">Dispara <code>LeadQualified</code> quando o lead for marcado como qualificado ou matrícula confirmada.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="capi_pixel_id">Pixel ID / Dataset ID</label></th>
                        <td>
                            <input type="text" id="capi_pixel_id" name="capi_pixel_id" value="<?php echo esc_attr($settings['capi_pixel_id']); ?>" class="regular-text" placeholder="Ex: 123456789012345">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="capi_access_token">Access Token da Conversions API</label></th>
                        <td>
                            <input type="password" id="capi_access_token" name="capi_access_token" value="<?php echo !empty($settings['capi_access_token']) ? '••••••••••••••••' : ''; ?>" class="large-text">
                            <p class="description">Token gerado no Gerenciador de Eventos da Meta &gt; Configurações &gt; Gerar Token de Acesso.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="capi_test_event_code">Código de Teste (Test Event Code)</label></th>
                        <td>
                            <input type="text" id="capi_test_event_code" name="capi_test_event_code" value="<?php echo esc_attr($settings['capi_test_event_code']); ?>" class="regular-text" placeholder="Ex: TEST12345">
                            <p class="description">Código temporário da aba "Testar Eventos". Remova este código quando for rodar em produção.</p>
                        </td>
                    </tr>
                </table>
            </div>

            <p class="submit">
                <button type="submit" name="li_save_settings" class="button button-primary button-large">
                    Salvar Configurações CAPI
                </button>
            </p>
        </form>

        <?php if (!empty($settings['capi_pixel_id'])): ?>
            <div class="li-card" style="border-left: 4px solid #0081fb;">
                <h3 class="li-card-title">🧪 Disparo de Teste CAPI em Tempo Real</h3>
                <p class="li-card-desc">Envie um evento de teste agora mesmo para validar a conexão direta do seu servidor com os servidores da Meta.</p>

                <form method="post" action="">
                    <?php wp_nonce_field('li_capi_test_verify', 'li_nonce'); ?>
                    <button type="submit" name="li_test_capi_fire" class="button button-secondary">
                        🚀 Disparar Evento 'Lead' de Teste Agora
                    </button>
                </form>
            </div>
        <?php endif; ?>
        <?php
    }

    /**
     * ABA: OpenAI Conversions API (ChatGPT Ads)
     */
    private static function render_tab_openai_capi($settings, $openai_test_result = null) {
        ?>
        <?php if ($openai_test_result): ?>
            <?php if (!empty($openai_test_result['success'])): ?>
                <div class="notice notice-success is-dismissible" style="margin-bottom: 20px; padding: 12px 16px; border-left-color: #10b981;">
                    <strong style="font-size: 14px;">✔ Teste da OpenAI Conversions API Concluído com Sucesso!</strong>
                    <div style="margin-top: 4px; font-size: 12px; color: #047857;">
                        <?php echo esc_html($openai_test_result['message']); ?> O payload com <code>validate_only: true</code> foi aceito e validado pelos servidores da OpenAI sem salvar dados reais.
                    </div>
                </div>
            <?php else: ?>
                <div class="notice notice-error is-dismissible" style="margin-bottom: 20px; padding: 12px 16px;">
                    <strong style="font-size: 14px;">❌ Falha no Teste OpenAI CAPI: <?php echo esc_html($openai_test_result['message'] ?? 'Erro desconhecido'); ?></strong>
                    <?php if (!empty($openai_test_result['response'])): ?>
                        <pre style="margin-top: 8px; background: #fff1f2; padding: 8px; border-radius: 4px; font-size: 11px; overflow-x: auto;"><?php echo esc_html(wp_json_encode($openai_test_result['response'], JSON_PRETTY_PRINT)); ?></pre>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <form method="post" action="">
            <?php wp_nonce_field('li_settings_verify', 'li_nonce'); ?>
            <input type="hidden" name="current_tab" value="openai_capi">
            <input type="hidden" name="has_openai_tab" value="1">

            <div class="li-card" style="margin-bottom: 20px;">
                <h3 class="li-card-title" style="display: flex; align-items: center; gap: 8px;">
                    <span>🤖</span> OpenAI Ads &bull; Conversions API (CAPI)
                </h3>
                <p class="li-card-desc">
                    Transmissão servidor-para-servidor oficial para a <strong>OpenAI (ChatGPT Ads)</strong> via endpoint <code>https://bzr.openai.com/v1/events?pid=&lt;PIXEL-ID&gt;</code>.<br>
                    Suporta anonimização estrita SHA-256 em UTF-8, preservação do token de atribuição <code>oppref</code> e leitura do cookie nativo <code>__obref</code>.
                </p>

                <table class="form-table">
                    <tr>
                        <th scope="row">Ativar OpenAI Conversions API</th>
                        <td>
                            <label class="li-switch">
                                <input type="checkbox" name="openai_capi_enabled" value="1" <?php checked(!empty($settings['openai_capi_enabled'])); ?>>
                                <span class="li-slider"></span>
                            </label>
                            <p class="description">Habilita a transmissão de eventos de conversão diretamente para o Gerenciador de Anúncios da OpenAI.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="openai_pixel_id">OpenAI Pixel ID (PID) <span style="color:#ef4444;">*</span></label></th>
                        <td>
                            <input type="text" id="openai_pixel_id" name="openai_pixel_id" value="<?php echo esc_attr($settings['openai_pixel_id'] ?? ''); ?>" class="regular-text" placeholder="Ex: px_abc123xyz">
                            <p class="description">Provisionado na aba &ldquo;Conversões&rdquo; do Gerenciador de Anúncios da OpenAI.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="openai_api_key">OpenAI API Key (Bearer Token) <span style="color:#ef4444;">*</span></label></th>
                        <td>
                            <input type="password" id="openai_api_key" name="openai_api_key" value="<?php echo !empty($settings['openai_api_key']) ? '••••••••••••••••' : ''; ?>" class="large-text" placeholder="Ex: sk-ads-...">
                            <p class="description">Chave de API com permissão para despacho de conversões.</p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="li-card" style="margin-bottom: 20px;">
                <h3 class="li-card-title">Eventos Automatizados</h3>
                <p class="li-card-desc">Selecione quais etapas do funil de vendas devem disparar conversões para a OpenAI.</p>

                <table class="form-table">
                    <tr>
                        <th scope="row">Disparar Evento 'lead_created'</th>
                        <td>
                            <label class="li-switch">
                                <input type="checkbox" name="enable_openai_capi_lead" value="1" <?php checked(!empty($settings['enable_openai_capi_lead'])); ?>>
                                <span class="li-slider"></span>
                            </label>
                            <p class="description">Dispara <code>lead_created</code> assim que o formulário do Elementor Pro ou Landing Page for enviado.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Disparar Evento 'lead_qualified'</th>
                        <td>
                            <label class="li-switch">
                                <input type="checkbox" name="enable_openai_capi_qualified" value="1" <?php checked(!empty($settings['enable_openai_capi_qualified'])); ?>>
                                <span class="li-slider"></span>
                            </label>
                            <p class="description">Dispara evento customizado <code>lead_qualified</code> quando o lead for marcado como qualificado via importação de planilha.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Disparar Evento 'order_created' (Venda)</th>
                        <td>
                            <label class="li-switch">
                                <input type="checkbox" name="enable_openai_capi_purchase" value="1" <?php checked(!empty($settings['enable_openai_capi_purchase'])); ?>>
                                <span class="li-slider"></span>
                            </label>
                            <p class="description">Dispara <code>order_created</code> com o valor da matrícula/curso quando o status do lead for alterado para matriculado.</p>
                        </td>
                    </tr>
                </table>
            </div>

            <p class="submit">
                <button type="submit" name="li_save_settings" class="button button-primary button-large">
                    Salvar Configurações da OpenAI
                </button>
            </p>
        </form>

        <?php if (!empty($settings['openai_pixel_id']) && !empty($settings['openai_api_key'])): ?>
            <div class="li-card" style="border-left: 4px solid #10b981; margin-top: 24px;">
                <h3 class="li-card-title" style="color: #047857;">🧪 Teste de Conexão com OpenAI CAPI (Dry-Run Seguro)</h3>
                <p class="li-card-desc">
                    Valida as credenciais e o formato do payload diretamente com os servidores da OpenAI utilizando o parâmetro oficial <code>validate_only: true</code>.
                    Nenhum dado fictício ou conversão real será gravada na sua conta de anúncios.
                </p>

                <form method="post" action="">
                    <?php wp_nonce_field('li_openai_test_verify', 'li_nonce'); ?>
                    <button type="submit" name="li_test_openai_capi" class="button button-secondary">
                        🤖 Validar Conexão OpenAI CAPI Agora
                    </button>
                </form>
            </div>
        <?php endif; ?>
        <?php
    }

    /**
     * ABA 5: Aparência & Logos
     */
    private static function render_tab_branding($settings) {
        ?>
        <form method="post" action="">
            <?php wp_nonce_field('li_settings_verify', 'li_nonce'); ?>
            <input type="hidden" name="current_tab" value="branding">

            <div class="li-card" style="margin-bottom: 20px;">
                <h3 class="li-card-title">Logotipos do Dashboard</h3>
                <p class="li-card-desc">Defina a imagem de logotipo para cabeçalhos e relatórios do Lead Intelligence.</p>

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="logo_dark">Logotipo Principal (Fundo Claro)</label></th>
                        <td>
                            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                                <input type="text" id="logo_dark" name="logo_dark" value="<?php echo esc_attr($settings['logo_dark']); ?>" class="regular-text">
                                <button type="button" class="button li-upload-logo-btn" data-target="#logo_dark">Selecionar da Mídia</button>
                            </div>
                            <?php if (!empty($settings['logo_dark'])): ?>
                                <div style="margin-top: 10px; padding: 10px; background: #f8fafc; display: inline-block; border-radius: 6px; border: 1px solid #e2e8f0;">
                                    <img src="<?php echo esc_url($settings['logo_dark']); ?>" style="max-height: 40px; display: block;">
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="logo_compact">Ícone Compacto (Favicon/Símbolo)</label></th>
                        <td>
                            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                                <input type="text" id="logo_compact" name="logo_compact" value="<?php echo esc_attr($settings['logo_compact']); ?>" class="regular-text">
                                <button type="button" class="button li-upload-logo-btn" data-target="#logo_compact">Selecionar da Mídia</button>
                            </div>
                        </td>
                    </tr>
                </table>
            </div>

            <p class="submit">
                <button type="submit" name="li_save_settings" class="button button-primary button-large">
                    Salvar Logotipos
                </button>
            </p>
        </form>

        <script>
        jQuery(document).ready(function($) {
            $('.li-upload-logo-btn').on('click', function(e) {
                e.preventDefault();
                var targetInput = $(this).data('target');
                var frame = wp.media({
                    title: 'Selecionar Logotipo',
                    button: { text: 'Usar este Logotipo' },
                    multiple: false
                });
                frame.on('select', function() {
                    var attachment = frame.state().get('selection').first().toJSON();
                    $(targetInput).val(attachment.url);
                });
                frame.open();
            });
        });
        </script>
        <?php
    }

    /**
     * ABA: Google Ads API & Gastos de Campanhas
     */
    private static function render_tab_google_ads_api($settings, $test_result = null, $sync_result = null) {
        $is_connected = !empty($settings['google_ads_connected']) && !empty($settings['google_ads_refresh_token']);
        $auth_url     = \LeadIntelligence\GoogleAds\GoogleAdsClient::get_auth_url();
        $last_sync    = get_option('lead_intelligence_last_gads_sync', 'Nunca');
        $last_records = get_option('lead_intelligence_last_gads_sync_records', 0);
        ?>
        <?php if ($test_result): ?>
            <?php if (!empty($test_result['success'])): ?>
                <div class="notice notice-success is-dismissible" style="margin-bottom: 20px; padding: 12px 16px;">
                    <strong style="font-size: 14px;">✔ <?php echo esc_html($test_result['message']); ?></strong>
                </div>
            <?php else: ?>
                <div class="notice notice-error is-dismissible" style="margin-bottom: 20px; padding: 12px 16px;">
                    <strong style="font-size: 14px;">❌ Falha na Conexão Google Ads:</strong>
                    <div style="margin-top: 4px;"><?php echo esc_html($test_result['message']); ?></div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($sync_result): ?>
            <?php if (!empty($sync_result['success'])): ?>
                <div class="notice notice-success is-dismissible" style="margin-bottom: 20px; padding: 12px 16px;">
                    <strong style="font-size: 14px;">✔ <?php echo esc_html($sync_result['message']); ?></strong>
                    <div style="margin-top: 4px; font-size: 12px;">Período consultado: <?php echo esc_html($sync_result['date_start']); ?> até <?php echo esc_html($sync_result['date_end']); ?>. Os relatórios na aba Google Ads foram atualizados.</div>
                </div>
            <?php else: ?>
                <div class="notice notice-error is-dismissible" style="margin-bottom: 20px; padding: 12px 16px;">
                    <strong style="font-size: 14px;">❌ Falha ao Sincronizar Gastos:</strong>
                    <div style="margin-top: 4px;"><?php echo esc_html($sync_result['message']); ?></div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- CARD DE STATUS E AÇÕES RÁPIDAS -->
        <div class="li-card" style="margin-bottom: 20px; background: <?php echo $is_connected ? '#f0fdf4' : '#f8fafc'; ?>; border-color: <?php echo $is_connected ? '#86efac' : '#e2e8f0'; ?>;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                <div>
                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 6px;">
                        <span class="dashicons dashicons-google" style="font-size: 24px; width: 24px; height: 24px; color: #1a73e8;"></span>
                        <h3 style="margin: 0; font-size: 16px; font-weight: 700;">Google Ads API &bull; Status da Conexão</h3>
                        <?php if ($is_connected): ?>
                            <span class="li-badge" style="background: #15803d; color: #fff; font-size: 11px; padding: 3px 8px; border-radius: 4px;">🟢 CONECTADO</span>
                        <?php else: ?>
                            <span class="li-badge" style="background: #64748b; color: #fff; font-size: 11px; padding: 3px 8px; border-radius: 4px;">⚪ NÃO CONECTADO</span>
                        <?php endif; ?>
                    </div>
                    <p style="margin: 0; font-size: 13px; color: #475569;">
                        Última sincronização de gastos: <strong><?php echo esc_html($last_sync); ?></strong> 
                        <?php if ($last_records > 0): ?>
                            (<?php echo (int) $last_records; ?> registros diários gravados)
                        <?php endif; ?>
                    </p>
                </div>

                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <?php if (!empty($auth_url) && !$is_connected): ?>
                        <a href="<?php echo esc_url($auth_url); ?>" class="button button-primary" style="display: inline-flex; align-items: center; gap: 6px; background: #1a73e8; border-color: #1557b0;">
                            <span class="dashicons dashicons-google"></span> Autorizar Google Ads (1-Clique)
                        </a>
                    <?php endif; ?>

                    <?php if ($is_connected): ?>
                        <form method="post" action="" style="display: inline;">
                            <?php wp_nonce_field('li_google_ads_test_verify', 'li_nonce'); ?>
                            <button type="submit" name="li_test_google_ads" value="1" class="button button-secondary" style="display: inline-flex; align-items: center; gap: 6px;">
                                <span class="dashicons dashicons-admin-tools"></span> Testar Conexão API
                            </button>
                        </form>

                        <form method="post" action="" style="display: inline;">
                            <?php wp_nonce_field('li_google_ads_sync_verify', 'li_nonce'); ?>
                            <button type="submit" name="li_sync_google_ads_now" value="1" class="button button-primary" style="display: inline-flex; align-items: center; gap: 6px; background: #059669; border-color: #047857;">
                                <span class="dashicons dashicons-update"></span> Sincronizar Últimos 30 Dias Agora
                            </button>
                        </form>

                        <form method="post" action="" style="display: inline;" onsubmit="return confirm('Deseja realmente desconectar a conta do Google Ads?');">
                            <?php wp_nonce_field('li_google_ads_disconnect_verify', 'li_nonce'); ?>
                            <button type="submit" name="li_disconnect_google_ads" value="1" class="button button-link-delete" style="color: #dc2626;">
                                Desconectar
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <form method="post" action="">
            <?php wp_nonce_field('li_settings_verify', 'li_nonce'); ?>
            <input type="hidden" name="current_tab" value="google_ads_api">
            <input type="hidden" name="has_gads_form" value="1">

            <div class="li-card" style="margin-bottom: 20px;">
                <h3 class="li-card-title">Credenciais de Acesso à Google Ads API</h3>
                <p class="li-card-desc">
                    Permite ao Lead Intelligence consultar automaticamente o investimento (cost), cliques e impressões de cada campanha do Google Ads para cruzar com as UTMs e calcular o CPL real e o custo por matrícula confirmada.
                </p>

                <table class="form-table">
                    <tr>
                        <th scope="row">Sincronização Diária Automática</th>
                        <td>
                            <label class="li-switch">
                                <input type="checkbox" name="enable_google_ads_costs_sync" value="1" <?php checked($settings['enable_google_ads_costs_sync'] ?? 1, 1); ?>>
                                <span class="li-slider"></span>
                            </label>
                            <p class="description">Executa diariamente às 04:00 da madrugada via WP-Cron para atualizar os dados de investimento do dia anterior.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="google_ads_customer_id">Customer ID (ID da Conta) <span style="color:#dc2626;">*</span></label></th>
                        <td>
                            <input type="text" id="google_ads_customer_id" name="google_ads_customer_id" value="<?php echo esc_attr($settings['google_ads_customer_id'] ?? ''); ?>" class="regular-text" placeholder="Ex: 123-456-7890 ou 1234567890">
                            <p class="description">Número de 10 dígitos da sua conta de anúncios do Google Ads (exibido no topo da tela do painel do Google Ads).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="google_ads_login_customer_id">Login Customer ID (MCC / Administrador)</label></th>
                        <td>
                            <input type="text" id="google_ads_login_customer_id" name="google_ads_login_customer_id" value="<?php echo esc_attr($settings['google_ads_login_customer_id'] ?? ''); ?>" class="regular-text" placeholder="Ex: 987-654-3210 (opcional)">
                            <p class="description">Obrigatório somente se a conta de anúncios for gerenciada sob uma conta administradora (MCC).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="google_ads_developer_token">Developer Token <span style="color:#dc2626;">*</span></label></th>
                        <td>
                            <input type="password" id="google_ads_developer_token" name="google_ads_developer_token" value="<?php echo !empty($settings['google_ads_developer_token']) ? '••••••••••••••••' : ''; ?>" class="regular-text" autocomplete="new-password">
                            <p class="description">Token gerado na Central da API do Google Ads (na conta MCC: <em>Ferramentas &amp; Configurações &gt; Central da API</em>).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="google_ads_client_id">OAuth 2.0 Client ID <span style="color:#dc2626;">*</span></label></th>
                        <td>
                            <input type="text" id="google_ads_client_id" name="google_ads_client_id" value="<?php echo esc_attr($settings['google_ads_client_id'] ?? ''); ?>" class="large-text" placeholder="Ex: 123456789-abcdef.apps.googleusercontent.com">
                            <p class="description">Client ID gerado no Google Cloud Console com tipo de aplicativo <em>Aplicativo da Web</em>.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="google_ads_client_secret">OAuth 2.0 Client Secret <span style="color:#dc2626;">*</span></label></th>
                        <td>
                            <input type="password" id="google_ads_client_secret" name="google_ads_client_secret" value="<?php echo !empty($settings['google_ads_client_secret']) ? '••••••••••••••••' : ''; ?>" class="regular-text" autocomplete="new-password">
                            <p class="description">Chave secreta gerada no Google Cloud Console junto com o Client ID.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="google_ads_refresh_token">Refresh Token</label></th>
                        <td>
                            <input type="password" id="google_ads_refresh_token" name="google_ads_refresh_token" value="<?php echo !empty($settings['google_ads_refresh_token']) ? '••••••••••••••••' : ''; ?>" class="large-text" autocomplete="new-password" placeholder="Preenchido automaticamente ao autorizar ou cole aqui manualmente">
                            <p class="description">Token de atualização permanente. Pode ser obtido automaticamente clicando no botão <strong>Autorizar Google Ads</strong> no topo após salvar o Client ID e Secret.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">URI de Redirecionamento Autorizado</th>
                        <td>
                            <input type="text" readonly value="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-settings&tab=google_ads_api&google_ads_auth=1')); ?>" class="large-text" onclick="this.select();">
                            <p class="description">Copie e cole este endereço exatamente como está em <em>URIs de redirecionamento autorizados</em> no Google Cloud Console.</p>
                        </td>
                    </tr>
                </table>
            </div>

            <p class="submit">
                <button type="submit" name="li_save_settings" class="button button-primary button-large">
                    Salvar Configurações do Google Ads
                </button>
            </p>
        </form>

        <!-- GUIA RÁPIDO PASSO A PASSO -->
        <div class="li-card" style="margin-top: 24px; background: #f8fafc; border: 1px solid #e2e8f0;">
            <h4 style="margin: 0 0 10px 0; font-size: 14px; color: #1e293b;">📘 Como Obter as Credenciais no Google Cloud e Google Ads:</h4>
            <ol style="margin: 0; padding-left: 20px; font-size: 13px; color: #475569; line-height: 1.6;">
                <li>No <strong>Google Cloud Console</strong>, crie um projeto e ative a biblioteca <strong>Google Ads API</strong> em <em>APIs e Serviços &gt; Biblioteca</em>.</li>
                <li>Em <em>APIs e Serviços &gt; Credenciais</em>, crie uma credencial <strong>ID do cliente OAuth</strong> do tipo <em>Aplicativo da Web</em>.</li>
                <li>Em <strong>URIs de redirecionamento autorizados</strong>, adicione a URL informada na tabela acima.</li>
                <li>Na sua conta MCC do Google Ads, vá em <em>Ferramentas e Configurações &gt; Central da API</em> e copie seu <strong>Developer Token</strong>.</li>
                <li>Cole o Developer Token, Client ID e Secret nesta tela, clique em <strong>Salvar</strong> e depois clique no botão <strong>Autorizar Google Ads</strong> no topo para conectar!</li>
            </ol>
        </div>
        <?php
    }

        
}
