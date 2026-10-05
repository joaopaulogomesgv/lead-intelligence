<?php
namespace LeadIntelligence\Elementor;

use LeadIntelligence\Database\LeadRepository;
use LeadIntelligence\Logger;
use LeadIntelligence\PhoneNormalizer;
use LeadIntelligence\Tracking\UtmTracker;
use LeadIntelligence\MetaCapi\CapiQueue;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Listener passivo e não-intrusivo para submissões de formulários Elementor Pro
 * 
 * ATENÇÃO: Esta classe NÃO altera, intercepta ou remove nenhuma ação existente
 * do formulário (incluindo o webhook da InterageZap). Ela atua exclusivamente como
 * observadora assíncrona/leitora de dados.
 */
class ElementorListener {

    public static function init() {
        // Hook disparado na submissão de formulário do Elementor Pro
        add_action('elementor_pro/forms/new_record', [__CLASS__, 'on_new_record'], 15, 2);
    }

    /**
     * Captura submissão do formulário
     *
     * @param \ElementorPro\Modules\Forms\Classes\Form_Record $record
     * @param \ElementorPro\Modules\Forms\Classes\Ajax_Handler $handler
     */
    public static function on_new_record($record, $handler) {
        $settings = get_option('lead_intelligence_settings', []);
        
        // Verifica se a captura do Elementor está ativa nas configurações
        if (isset($settings['enable_elementor_capture']) && empty($settings['enable_elementor_capture'])) {
            return;
        }

        try {
            self::process_record($record);
        } catch (\Throwable $e) {
            // Em caso de qualquer erro imprevisto, registramos no log de erros
            // e NÃO interrompemos o fluxo do Elementor nem de outros plugins/webhooks.
            Logger::error('Elementor', 'Exceção ao capturar formulário Elementor: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }

    /**
     * Processa e persiste os dados do formulário
     *
     * @param \ElementorPro\Modules\Forms\Classes\Form_Record $record
     */
    private static function process_record($record) {
        $raw_fields = $record->get('fields');
        $form_settings = $record->get('form_settings');

        $form_name = !empty($form_settings['form_name']) ? sanitize_text_field($form_settings['form_name']) : 'Formulário Elementor';
        $form_id   = !empty($form_settings['id']) ? sanitize_text_field($form_settings['id']) : '';

        // Mapeia os campos capturados
        $extracted = self::extract_fields($raw_fields);

        // Se não tiver pelo menos telefone ou email, não há como vincular o lead
        if (empty($extracted['telefone']) && empty($extracted['email'])) {
            Logger::warning('Elementor', "Formulário '{$form_name}' enviado sem telefone ou e-mail identificável.", [
                'form_id'    => $form_id,
                'campos_raw' => array_keys((array) $raw_fields),
            ]);
            return;
        }

        // Metadados da requisição
        $referer = !empty($_SERVER['HTTP_REFERER']) ? esc_url_raw($_SERVER['HTTP_REFERER']) : '';
        $ip      = self::get_client_ip();
        $ua      = !empty($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '';

        // Captura consolidada de parâmetros UTM e Meta Clicks (POST > GET > Cookies)
        $tracking = UtmTracker::get_current_tracking_data();

        // Monta os dados para persistência
        $lead_data = [
            'nome'            => $extracted['nome'],
            'email'           => $extracted['email'],
            'telefone'        => $extracted['telefone'],
            'tipo_curso'      => $extracted['tipo_curso'],
            'area_interesse'  => $extracted['area_interesse'],
            'formulario_id'   => $form_id,
            'formulario_nome' => $form_name,
            'pagina_origem'   => $referer,
            'ip_address'      => $ip,
            'user_agent'      => $ua,
            'utm_source'      => $tracking['utm_source'],
            'utm_medium'      => $tracking['utm_medium'],
            'utm_campaign'    => $tracking['utm_campaign'],
            'utm_content'     => $tracking['utm_content'],
            'utm_term'        => $tracking['utm_term'],
            'fbclid'          => $tracking['fbclid'],
            'fbc'             => $tracking['fbc'],
            'fbp'             => $tracking['fbp'],
            'campaign_id'     => $tracking['campaign_id'],
            'adset_id'        => $tracking['adset_id'],
            'ad_id'           => $tracking['ad_id'],
            'campaign_name'   => $tracking['campaign_name'],
            'adset_name'      => $tracking['adset_name'],
            'ad_name'         => $tracking['ad_name'],
        ];

        // Salva ou atualiza no banco próprio
        $lead_id = LeadRepository::save_lead($lead_data, 'elementor');

        // Agenda envio do evento 'Lead' para Meta Conversions API (se ativo nas configurações)
        CapiQueue::enqueue_lead($lead_id);

        Logger::info('Elementor', "Lead capturado com sucesso do Elementor Form '{$form_name}' (ID Lead #{$lead_id}).", [
            'form_name' => $form_name,
            'telefone'  => PhoneNormalizer::format_display($extracted['telefone']),
            'email'     => $extracted['email'],
            'curso'     => $extracted['tipo_curso'],
            'area'      => $extracted['area_interesse'],
        ]);
    }

    /**
     * Mapeia os campos do formulário para o modelo do Lead Intelligence
     */
    private static function extract_fields($raw_fields) {
        $settings = get_option('lead_intelligence_settings', []);
        $config_mapping = isset($settings['field_mapping']) ? $settings['field_mapping'] : [];

        $result = [
            'nome'           => '',
            'email'          => '',
            'telefone'       => '',
            'tipo_curso'     => '',
            'area_interesse' => '',
        ];

        if (empty($raw_fields) || !is_array($raw_fields)) {
            return $result;
        }

        // Tenta associar cada campo do formulário
        foreach ($raw_fields as $field_id => $field_info) {
            $value = isset($field_info['value']) ? trim((string) $field_info['value']) : '';
            if ($value === '') {
                continue;
            }

            $type  = isset($field_info['type']) ? strtolower(trim((string) $field_info['type'])) : '';
            $title = isset($field_info['title']) ? strtolower(trim((string) $field_info['title'])) : '';
            $id    = strtolower(trim((string) $field_id));

            // Heurística baseada no tipo nativo do Elementor
            if ($type === 'email' && empty($result['email'])) {
                $result['email'] = sanitize_email($value);
                continue;
            }
            if ($type === 'tel' && empty($result['telefone'])) {
                $result['telefone'] = sanitize_text_field($value);
                continue;
            }

            // Verifica se casa com mapeamento das configurações
            foreach (['nome', 'email', 'telefone', 'tipo_curso', 'area_interesse'] as $target_key) {
                if (!empty($result[$target_key])) {
                    continue; // Já preenchido
                }

                $configured_aliases = isset($config_mapping[$target_key]) ? $config_mapping[$target_key] : '';
                $aliases = array_map('trim', explode(',', strtolower($configured_aliases)));

                foreach ($aliases as $alias) {
                    if (empty($alias)) {
                        continue;
                    }
                    if ($id === $alias || $title === $alias || strpos($title, $alias) !== false || strpos($id, $alias) !== false) {
                        if ($target_key === 'email') {
                            $result['email'] = sanitize_email($value);
                        } else {
                            $result[$target_key] = sanitize_text_field($value);
                        }
                        break 2;
                    }
                }
            }
        }

        // Se ainda não encontrou telefone ou email, varre por padrões de string
        if (empty($result['email'])) {
            foreach ($raw_fields as $field_info) {
                $val = isset($field_info['value']) ? trim((string) $field_info['value']) : '';
                if (is_email($val)) {
                    $result['email'] = sanitize_email($val);
                    break;
                }
            }
        }

        if (empty($result['telefone'])) {
            foreach ($raw_fields as $field_info) {
                $val = isset($field_info['value']) ? trim((string) $field_info['value']) : '';
                $digits = preg_replace('/\D/', '', $val);
                // Número brasileiro válido tem 10 ou 11 dígitos (ou 12/13 com 55)
                if (in_array(strlen($digits), [10, 11, 12, 13]) && !is_email($val)) {
                    $result['telefone'] = sanitize_text_field($val);
                    break;
                }
            }
        }

        return $result;
    }



    /**
     * Obtém IP real do cliente com suporte a proxies/Cloudflare
     */
    private static function get_client_ip() {
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR'
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip_list = explode(',', sanitize_text_field(wp_unslash($_SERVER[$header])));
                $ip = trim($ip_list[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '';
    }
}
