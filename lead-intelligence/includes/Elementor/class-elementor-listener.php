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

        // 1. Extração direta de campos ocultos existentes no formulário (gclid, fbclid, referrer, page_url, etc.)
        $form_tracking = [];
        if (!empty($raw_fields) && is_array($raw_fields)) {
            foreach ($raw_fields as $f_id => $f_info) {
                $f_key   = strtolower(trim((string) $f_id));
                $f_title = isset($f_info['title']) ? strtolower(trim((string) $f_info['title'])) : '';
                $f_val   = isset($f_info['value']) ? trim((string) $f_info['value']) : '';
                if ($f_val === '') {
                    continue;
                }

                foreach (['gclid', 'fbclid', 'referrer', 'page_url', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'campaign_id', 'gad_campaignid', 'gad_source'] as $tk) {
                    if ($f_key === $tk || $f_title === $tk || strpos($f_key, $tk) !== false || strpos($f_title, $tk) !== false) {
                        $form_tracking[$tk] = sanitize_text_field($f_val);
                    }
                }
            }
        }

        // 2. Extração via URL da página de submissão (page_url enviado ou HTTP_REFERER)
        $page_url_candidate = !empty($form_tracking['page_url']) ? $form_tracking['page_url'] : $referer;
        $url_params = [];
        if (!empty($page_url_candidate)) {
            $parsed_q = parse_url($page_url_candidate, PHP_URL_QUERY);
            if (!empty($parsed_q)) {
                parse_str($parsed_q, $url_params);
            }
        }

        // 3. Captura consolidada de parâmetros UTM e Meta Clicks (Cookies + Server-Side)
        $tracking = UtmTracker::get_current_tracking_data();

        // Mapeamentos unificados com máxima prioridade (Form Fields > URL Query > Tracker Cookies)
        $gclid          = !empty($form_tracking['gclid']) ? $form_tracking['gclid'] : (!empty($url_params['gclid']) ? sanitize_text_field($url_params['gclid']) : (!empty($tracking['gclid']) ? $tracking['gclid'] : ''));
        $fbclid         = !empty($form_tracking['fbclid']) ? $form_tracking['fbclid'] : (!empty($url_params['fbclid']) ? sanitize_text_field($url_params['fbclid']) : (!empty($tracking['fbclid']) ? $tracking['fbclid'] : ''));
        $referrer_final = !empty($form_tracking['referrer']) ? $form_tracking['referrer'] : (!empty($tracking['referrer']) ? $tracking['referrer'] : '');
        $gad_source     = !empty($form_tracking['gad_source']) ? $form_tracking['gad_source'] : (!empty($url_params['gad_source']) ? sanitize_text_field($url_params['gad_source']) : (!empty($tracking['gad_source']) ? $tracking['gad_source'] : ''));

        $campaign_id    = !empty($form_tracking['campaign_id']) ? $form_tracking['campaign_id'] : (!empty($form_tracking['gad_campaignid']) ? $form_tracking['gad_campaignid'] : (!empty($url_params['gad_campaignid']) ? sanitize_text_field($url_params['gad_campaignid']) : (!empty($url_params['campaign_id']) ? sanitize_text_field($url_params['campaign_id']) : (!empty($tracking['campaign_id']) ? $tracking['campaign_id'] : ''))));

        $utm_source     = !empty($form_tracking['utm_source']) ? $form_tracking['utm_source'] : (!empty($url_params['utm_source']) ? sanitize_text_field($url_params['utm_source']) : (!empty($tracking['utm_source']) ? $tracking['utm_source'] : ''));
        $utm_medium     = !empty($form_tracking['utm_medium']) ? $form_tracking['utm_medium'] : (!empty($url_params['utm_medium']) ? sanitize_text_field($url_params['utm_medium']) : (!empty($tracking['utm_medium']) ? $tracking['utm_medium'] : ''));
        $utm_campaign   = !empty($form_tracking['utm_campaign']) ? $form_tracking['utm_campaign'] : (!empty($url_params['utm_campaign']) ? sanitize_text_field($url_params['utm_campaign']) : (!empty($tracking['utm_campaign']) ? $tracking['utm_campaign'] : ''));

        if (empty($utm_source) && !empty($gclid)) {
            $utm_source = 'google_ads';
        }
        if (empty($utm_campaign) && !empty($campaign_id)) {
            $utm_campaign = $campaign_id;
        }

        // Monta os dados para persistência
        $lead_data = [
            'nome'            => $extracted['nome'],
            'email'           => $extracted['email'],
            'telefone'        => $extracted['telefone'],
            'tipo_curso'      => $extracted['tipo_curso'],
            'area_interesse'  => $extracted['area_interesse'],
            'polo'            => $extracted['polo'] ?? '',
            'formulario_id'   => $form_id,
            'formulario_nome' => $form_name,
            'pagina_origem'   => $page_url_candidate,
            'ip_address'      => $ip,
            'user_agent'      => $ua,
            'gclid'           => $gclid,
            'gad_source'      => $gad_source,
            'referrer'        => $referrer_final,
            'utm_source'      => $utm_source,
            'utm_medium'      => $utm_medium,
            'utm_campaign'    => $utm_campaign,
            'utm_content'     => !empty($form_tracking['utm_content']) ? $form_tracking['utm_content'] : (!empty($url_params['utm_content']) ? sanitize_text_field($url_params['utm_content']) : $tracking['utm_content']),
            'utm_term'        => !empty($form_tracking['utm_term']) ? $form_tracking['utm_term'] : (!empty($url_params['utm_term']) ? sanitize_text_field($url_params['utm_term']) : $tracking['utm_term']),
            'fbclid'          => $fbclid,
            'fbc'             => $tracking['fbc'],
            'fbp'             => $tracking['fbp'],
            'campaign_id'     => $campaign_id,
            'adset_id'        => $tracking['adset_id'],
            'ad_id'           => $tracking['ad_id'],
            'campaign_name'   => !empty($tracking['campaign_name']) ? $tracking['campaign_name'] : $utm_campaign,
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
        $settings = \LeadIntelligence\Admin\Settings::get_settings();
        $config_mapping = isset($settings['field_mapping']) ? $settings['field_mapping'] : [];

        $result = [
            'nome'           => '',
            'email'          => '',
            'telefone'       => '',
            'tipo_curso'     => '',
            'area_interesse' => '',
            'polo'           => '',
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

            // Heurística direta para Nome pelo ID ou Título comum
            if (empty($result['nome']) && ($id === 'name' || $id === 'nome' || $title === 'nome' || $title === 'name' || strpos($title, 'nome') !== false || strpos($title, 'name') !== false)) {
                if (!is_email($value) && !preg_match('/^[\d\s\+\-\(\)]{8,}$/', $value)) {
                    $result['nome'] = sanitize_text_field($value);
                    continue;
                }
            }

            // Verifica se casa com mapeamento das configurações
            foreach (['nome', 'email', 'telefone', 'tipo_curso', 'area_interesse', 'polo'] as $target_key) {
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

        // Se ainda não encontrou Nome, varre campos que não sejam e-mail nem telefone
        if (empty($result['nome'])) {
            foreach ($raw_fields as $field_info) {
                $val = isset($field_info['value']) ? trim((string) $field_info['value']) : '';
                if ($val === '' || is_email($val)) {
                    continue;
                }
                $digits = preg_replace('/\D/', '', $val);
                if (in_array(strlen($digits), [10, 11, 12, 13])) {
                    continue;
                }

                $title = isset($field_info['title']) ? strtolower(trim((string) $field_info['title'])) : '';
                $id    = isset($field_info['id']) ? strtolower(trim((string) $field_info['id'])) : '';

                if (strpos($title, 'nome') !== false || strpos($id, 'nome') !== false || strpos($title, 'name') !== false || strpos($id, 'name') !== false) {
                    $result['nome'] = sanitize_text_field($val);
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
