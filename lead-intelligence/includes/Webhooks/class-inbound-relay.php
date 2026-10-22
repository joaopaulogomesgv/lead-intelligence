<?php
namespace LeadIntelligence\Webhooks;

use LeadIntelligence\Database\LeadRepository;
use LeadIntelligence\Logger;
use LeadIntelligence\PhoneNormalizer;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Controller do Webhook Inbound & Relay (Repasse para n8n)
 *
 * Endpoint: /wp-json/lead-intelligence/v1/webhook/inbound
 * 
 * Fluxo:
 * 1. Recebe requisição externa com dados de lead (JSON ou POST data).
 * 2. Extrai, normaliza o telefone (padrão E.164 +55 9 dígitos) e salva no Lead Intelligence.
 * 3. Repassa os dados enriquecidos para o webhook de destino (ex: n8n).
 * 4. Retorna resposta JSON confirmando o salvamento e o repasse.
 */
class InboundRelay {

    const ENDPOINT_NAMESPACE = 'lead-intelligence/v1';
    const ENDPOINT_ROUTE     = '/webhook/inbound';

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_rest_routes']);
    }

    /**
     * Registra o endpoint REST
     */
    public static function register_rest_routes() {
        register_rest_route(self::ENDPOINT_NAMESPACE, self::ENDPOINT_ROUTE, [
            [
                'methods'             => \WP_REST_Server::CREATABLE, // POST
                'callback'            => [__CLASS__, 'handle_inbound_post'],
                'permission_callback' => '__return_true', // Aberto para receber webhooks externos
            ],
            [
                'methods'             => \WP_REST_Server::READABLE, // GET (health check / teste)
                'callback'            => [__CLASS__, 'handle_inbound_get'],
                'permission_callback' => '__return_true',
            ],
        ]);
    }

    /**
     * Endpoint GET: teste de status, handshake da Meta (hub.challenge) e instruções de uso
     */
    public static function handle_inbound_get(\WP_REST_Request $request) {
        // Se a Meta enviar verificação de webhook via GET com hub.challenge
        $hub_challenge = $request->get_param('hub_challenge') ?: ($request->get_param('hub.challenge') ?: '');
        if (!empty($hub_challenge)) {
            return new \WP_REST_Response((int) $hub_challenge, 200);
        }

        $settings = \LeadIntelligence\Admin\Settings::get_settings();
        $target_url = $settings['webhook_relay_target_url'] ?? '';

        return new \WP_REST_Response([
            'status'       => 'online',
            'message'      => 'Endpoint Inbound Webhook do Lead Intelligence está ativo e pronto para receber dados via POST.',
            'relay_to'     => !empty($target_url) ? esc_url_raw($target_url) : 'Nenhum webhook de destino configurado',
            'relay_active' => !empty($settings['enable_webhook_relay']),
            'timestamp'    => current_time('mysql'),
        ], 200);
    }

    /**
     * Endpoint POST: processa a entrada do lead e repassa para o webhook de destino (n8n)
     */
    public static function handle_inbound_post(\WP_REST_Request $request) {
        $settings = \LeadIntelligence\Admin\Settings::get_settings();

        // Obtém o payload bruto e parâmetros parseados
        $json_params = $request->get_json_params();
        $all_params  = $request->get_params();
        $raw_body    = $request->get_body();

        $payload = !empty($json_params) && is_array($json_params) ? $json_params : (is_array($all_params) ? $all_params : []);

        if (empty($payload) && !empty($raw_body)) {
            $decoded = json_decode($raw_body, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        if (empty($payload)) {
            Logger::warning('InboundRelay', 'Requisição recebida com payload vazio no endpoint inbound.');
            return new \WP_REST_Response([
                'success' => false,
                'error'   => 'Payload vazio ou formato inválido.',
            ], 400);
        }

        // Detecção de Ping / Verificação de Teste do Google Ads, Meta Ads ou CRM
        // O Google Ads faz um ping inicial ao cadastrar o webhook enviando apenas google_key (sem user_column_data)
        $has_columns    = !empty($payload['user_column_data']) && is_array($payload['user_column_data']);
        $has_field_data = !empty($payload['field_data']) && is_array($payload['field_data']);

        $is_explicit_test = !empty($payload['is_test']) 
            || !empty($payload['test']) 
            || !empty($payload['ping'])
            || (isset($payload['lead_id']) && (strpos((string)$payload['lead_id'], 'test') !== false || in_array((string)$payload['lead_id'], ['0', 'test', 'dummy', 'test_lead_id'], true)));

        // É apenas um ping de conexão quando:
        // 1. É um challenge da Meta (hub_challenge)
        // 2. É teste explícito SEM colunas de dados reais preenchidas
        // 3. É ping do Google Ads com google_key SEM colunas de usuário (validação inicial de URL)
        $is_test_ping = (!empty($request->get_param('hub_challenge')) || !empty($request->get_param('hub.challenge')))
            || ($is_explicit_test && !$has_columns && !$has_field_data)
            || (isset($payload['google_key']) && !$has_columns && !$has_field_data && empty($payload['phone']) && empty($payload['telefone']));

        if ($is_test_ping) {
            Logger::info('InboundRelay', 'Ping/Teste de validação de webhook recebido com sucesso (Google Ads / Meta).', [
                'is_google_test' => true,
                'google_key'     => $payload['google_key'] ?? '',
                'lead_id'        => $payload['lead_id'] ?? '',
            ]);

            // Se o relay estiver configurado e ativo, repassa o teste para o n8n também
            $target_url = !empty($settings['webhook_relay_target_url']) ? trim($settings['webhook_relay_target_url']) : '';
            $relay_active = !isset($settings['enable_webhook_relay']) || !empty($settings['enable_webhook_relay']);
            if ($relay_active && !empty($target_url)) {
                self::forward_to_target($target_url, $payload, null, 0);
            }

            return new \WP_REST_Response([
                'success' => true,
                'status'  => 'google_ads_lead_form_verified',
                'message' => 'Webhook do Google Ads verificado e conectado com sucesso!',
            ], 200);
        }

        // Parâmetros opcionais passados na query string da URL (ex: ?utm_campaign=formulario google)
        $query_params = [
            'utm_source'      => sanitize_text_field($request->get_param('utm_source') ?: ''),
            'utm_medium'      => sanitize_text_field($request->get_param('utm_medium') ?: ''),
            'utm_campaign'    => sanitize_text_field($request->get_param('utm_campaign') ?: ''),
            'formulario_nome' => sanitize_text_field($request->get_param('formulario_nome') ?: ($request->get_param('form_name') ?: '')),
        ];

        // 1. Extração flexível dos dados do lead (Google Ads, Meta Ads ou formulários genéricos)
        $extracted = self::extract_lead_fields($payload, $query_params, $settings);

        // Se for lead fictício de teste da Meta ou Google (ex: <phone_number> ou test@example.com)
        if (strpos($extracted['telefone'], '<') !== false || strpos($extracted['email'], '<') !== false || $extracted['email'] === 'test@example.com') {
            return new \WP_REST_Response([
                'success' => true,
                'status'  => 'test_accepted',
                'message' => 'Evento de teste reconhecido com sucesso.',
            ], 200);
        }

        // Se não tiver pelo menos telefone ou email, não é possível criar o cadastro
        if (empty($extracted['telefone']) && empty($extracted['email'])) {
            Logger::warning('InboundRelay', 'Lead rejeitado: telefone ou e-mail ausente no payload.', [
                'payload_amostra' => array_slice($payload, 0, 10),
            ]);
            return new \WP_REST_Response([
                'success' => false,
                'error'   => 'O payload precisa conter pelo menos um telefone ou e-mail válido.',
            ], 422);
        }

        // Metadados da requisição
        $ip = self::get_client_ip();
        $ua = !empty($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : 'Webhook/Inbound';

        // 2. Monta o modelo de dados para o LeadRepository
        $lead_data = [
            'nome'                => $extracted['nome'],
            'email'               => $extracted['email'],
            'telefone'            => $extracted['telefone'],
            'tipo_curso'          => $extracted['tipo_curso'],
            'area_interesse'      => $extracted['area_interesse'],
            'polo'                => $extracted['polo'],
            'formulario_nome'     => !empty($extracted['formulario_nome']) ? $extracted['formulario_nome'] : ($settings['webhook_inbound_form_name'] ?? 'Formulário Google'),
            'ip_address'          => $ip,
            'user_agent'          => $ua,
            'utm_source'          => !empty($extracted['utm_source']) ? $extracted['utm_source'] : 'google_lead_form',
            'utm_medium'          => !empty($extracted['utm_medium']) ? $extracted['utm_medium'] : ($settings['webhook_inbound_utm_medium'] ?? 'lead_form'),
            'utm_campaign'        => !empty($extracted['utm_campaign']) ? $extracted['utm_campaign'] : ($settings['webhook_inbound_utm_campaign'] ?? 'formulario google'),
            'utm_content'         => $extracted['utm_content'] ?? '',
            'utm_term'            => $extracted['utm_term'] ?? '',
            'gclid'               => $extracted['gclid'] ?? '',
            'fbclid'              => $extracted['fbclid'] ?? '',
            'gad_source'          => $extracted['gad_source'] ?? '',
            'etapa_funil'         => !empty($extracted['etapa_funil']) ? $extracted['etapa_funil'] : 'Formulário Enviado',
            'qualificacao_status' => !empty($extracted['qualificacao_status']) ? $extracted['qualificacao_status'] : 'pendente',
            'score'               => isset($extracted['score']) ? (int) $extracted['score'] : 10,
        ];

        // 3. Salva no banco de dados do Lead Intelligence
        $lead_id = LeadRepository::save_lead($lead_data, 'webhook_inbound');

        // Busca o lead salvo com dados normalizados
        $saved_lead = LeadRepository::find_by_id($lead_id);

        Logger::info('InboundRelay', "Lead #{$lead_id} salvo com sucesso via Inbound Webhook ({$lead_data['utm_campaign']}).", [
            'lead_id'      => $lead_id,
            'nome'         => $extracted['nome'],
            'telefone'     => PhoneNormalizer::format_display($extracted['telefone']),
            'email'        => $extracted['email'],
            'curso'        => $extracted['tipo_curso'],
            'utm_campaign' => $lead_data['utm_campaign'],
            'utm_source'   => $lead_data['utm_source'],
            'utm_medium'   => $lead_data['utm_medium'],
            'formulario'   => $lead_data['formulario_nome'],
            'origem'       => !empty($payload['user_column_data']) ? 'Google Ads Lead Form' : 'Inbound Webhook',
        ]);

        // 4. Repasse (Relay) para o webhook do n8n (se configurado e ativo)
        $target_url = !empty($settings['webhook_relay_target_url']) ? trim($settings['webhook_relay_target_url']) : '';
        $relay_active = !isset($settings['enable_webhook_relay']) || !empty($settings['enable_webhook_relay']);

        $forward_info = [
            'attempted' => false,
            'success'   => false,
            'code'      => null,
            'target'    => $target_url,
        ];

        if ($relay_active && !empty($target_url)) {
            $forward_info = self::forward_to_target($target_url, $payload, $saved_lead, $lead_id);
        }

        return new \WP_REST_Response([
            'success'        => true,
            'lead_id'        => $lead_id,
            'message'        => 'Lead salvo com sucesso no Lead Intelligence' . ($forward_info['success'] ? ' e repassado para o webhook.' : '.'),
            'forward_info'   => $forward_info,
            'normalized_data' => [
                'nome'                 => $saved_lead ? $saved_lead->nome : $extracted['nome'],
                'telefone_normalizado' => $saved_lead ? $saved_lead->telefone_normalizado : PhoneNormalizer::normalize($extracted['telefone']),
                'email'                => $saved_lead ? $saved_lead->email : $extracted['email'],
                'utm_campaign'         => $lead_data['utm_campaign'],
                'utm_source'           => $lead_data['utm_source'],
            ],
        ], 200);
    }

    /**
     * Encaminha os dados do lead para a URL de destino (n8n)
     *
     * @param string $target_url
     * @param array $original_payload
     * @param object|null $saved_lead
     * @param int $lead_id
     * @return array
     */
    public static function forward_to_target($target_url, $original_payload, $saved_lead, $lead_id) {
        // Monta payload enriquecido preservando todas as chaves originais
        $forward_payload = is_array($original_payload) ? $original_payload : [];

        // Injeta bloco padronizado com os dados tratados e normalizados pelo Lead Intelligence
        $forward_payload['_lead_intelligence'] = [
            'lead_id'              => $lead_id,
            'uuid'                 => $saved_lead ? ($saved_lead->uuid ?? '') : '',
            'nome'                 => $saved_lead ? ($saved_lead->nome ?? '') : ($original_payload['nome'] ?? ''),
            'email'                => $saved_lead ? ($saved_lead->email ?? '') : ($original_payload['email'] ?? ''),
            'telefone_normalizado' => $saved_lead ? ($saved_lead->telefone_normalizado ?? '') : '',
            'telefone_formatado'   => $saved_lead ? PhoneNormalizer::format_display($saved_lead->telefone_normalizado ?? '') : '',
            'formulario_nome'      => $saved_lead ? ($saved_lead->formulario_nome ?? '') : 'Formulário Google',
            'utm_source'           => $saved_lead ? ($saved_lead->utm_source ?? '') : 'google_ads',
            'utm_medium'           => $saved_lead ? ($saved_lead->utm_medium ?? '') : 'lead_form',
            'utm_campaign'         => $saved_lead ? ($saved_lead->utm_campaign ?? '') : 'formulario google',
            'utm_content'          => $saved_lead ? ($saved_lead->utm_content ?? '') : '',
            'utm_term'             => $saved_lead ? ($saved_lead->utm_term ?? '') : '',
            'gclid'                => $saved_lead ? ($saved_lead->gclid ?? '') : '',
            'status'               => $saved_lead ? ($saved_lead->status ?? 'novo') : 'novo',
            'etapa_funil'          => $saved_lead ? ($saved_lead->etapa_funil ?? '') : 'Formulário Enviado',
            'qualificacao_status'  => $saved_lead ? ($saved_lead->qualificacao_status ?? 'pendente') : 'pendente',
            'canal_identificado'   => 'Google Ads Lead Form',
            'created_at'           => current_time('mysql'),
            'source'               => 'lead_intelligence_inbound_relay',
        ];

        // Disponibiliza as UTMs e dados limpos diretamente na raiz do JSON para nós do n8n
        $forward_payload['lead_id']         = $lead_id;
        $forward_payload['utm_campaign']    = $saved_lead ? $saved_lead->utm_campaign : ($forward_payload['utm_campaign'] ?? 'formulario google');
        $forward_payload['utm_source']      = $saved_lead ? $saved_lead->utm_source : ($forward_payload['utm_source'] ?? 'google_ads');
        $forward_payload['utm_medium']      = $saved_lead ? $saved_lead->utm_medium : ($forward_payload['utm_medium'] ?? 'lead_form');
        $forward_payload['formulario_nome'] = $saved_lead ? $saved_lead->formulario_nome : 'Formulário Google';
        if ($saved_lead) {
            if (!empty($saved_lead->nome))                 $forward_payload['nome']                 = $saved_lead->nome;
            if (!empty($saved_lead->email))                $forward_payload['email']                = $saved_lead->email;
            if (!empty($saved_lead->telefone))             $forward_payload['telefone']             = $saved_lead->telefone;
            if (!empty($saved_lead->telefone_normalizado)) $forward_payload['telefone_normalizado'] = $saved_lead->telefone_normalizado;
            if (!empty($saved_lead->polo))                 $forward_payload['polo']                 = $saved_lead->polo;
            if (!empty($saved_lead->tipo_curso))           $forward_payload['tipo_curso']           = $saved_lead->tipo_curso;
        }

        $response = wp_remote_post($target_url, [
            'method'      => 'POST',
            'timeout'     => 15,
            'redirection' => 5,
            'httpversion' => '1.1',
            'blocking'    => true,
            'headers'     => [
                'Content-Type' => 'application/json; charset=utf-8',
                'User-Agent'   => 'LeadIntelligence-Relay/1.0 (WordPress)',
            ],
            'body'        => wp_json_encode($forward_payload, JSON_UNESCAPED_UNICODE),
        ]);

        if (is_wp_error($response)) {
            $err = $response->get_error_message();
            Logger::error('InboundRelay', "Falha ao repassar Lead #{$lead_id} para webhook: {$err}", [
                'target_url' => $target_url,
            ]);
            return [
                'attempted' => true,
                'success'   => false,
                'error'     => $err,
                'target'    => $target_url,
            ];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $is_success = ($code >= 200 && $code < 300);

        if ($is_success) {
            Logger::info('InboundRelay', "Lead #{$lead_id} repassado com sucesso para o webhook (HTTP {$code}).", [
                'target_url' => $target_url,
                'response'   => substr($body, 0, 200),
            ]);
        } else {
            Logger::warning('InboundRelay', "Webhook de destino retornou HTTP {$code} ao repassar Lead #{$lead_id}.", [
                'target_url' => $target_url,
                'response'   => substr($body, 0, 300),
            ]);
        }

        return [
            'attempted' => true,
            'success'   => $is_success,
            'code'      => $code,
            'target'    => $target_url,
        ];
    }

    /**
     * Mapeador tolerante para extrair campos do payload recebido
     *
     * @param array $payload
     * @param array $query_params
     * @param array $settings
     * @return array
     */
    private static function extract_lead_fields($payload, $query_params = [], $settings = []) {
        $flat = [];

        // 1. Suporte oficial ao Google Ads Lead Form (user_column_data)
        if (!empty($payload['user_column_data']) && is_array($payload['user_column_data'])) {
            foreach ($payload['user_column_data'] as $col) {
                if (is_array($col)) {
                    $col_id = strtoupper(trim((string) ($col['column_id'] ?? '')));
                    $val    = trim((string) ($col['string_value'] ?? ''));
                    if ($val === '') continue;

                    switch ($col_id) {
                        case 'FULL_NAME':
                        case 'FIRST_NAME':
                            $flat['nome'] = $val;
                            break;
                        case 'LAST_NAME':
                            $flat['sobrenome'] = $val;
                            break;
                        case 'PHONE_NUMBER':
                            $flat['telefone'] = $val;
                            break;
                        case 'EMAIL':
                            $flat['email'] = $val;
                            break;
                        case 'POSTAL_CODE':
                            $flat['cep'] = $val;
                            break;
                        case 'CITY':
                            $flat['cidade'] = $val;
                            break;
                        default:
                            $col_name = strtolower(str_replace(' ', '_', trim((string) ($col['column_name'] ?? ''))));
                            if ($col_name) {
                                $flat[$col_name] = $val;
                            }
                            break;
                    }
                }
            }
        }

        // 2. Suporte a field_data (formato nativo de formulários de leads da Meta / Facebook Lead Ads)
        if (!empty($payload['field_data']) && is_array($payload['field_data'])) {
            foreach ($payload['field_data'] as $field) {
                if (is_array($field) && !empty($field['name'])) {
                    $fn = strtolower(trim((string) $field['name']));
                    $val = '';
                    if (isset($field['values']) && is_array($field['values']) && !empty($field['values'][0])) {
                        $val = (string) $field['values'][0];
                    } elseif (isset($field['value'])) {
                        $val = is_array($field['value']) ? ($field['value'][0] ?? '') : (string) $field['value'];
                    }
                    if ($val !== '') {
                        $flat[$fn] = trim($val);
                    }
                }
            }
        }

        // 3. Normaliza chaves planas em minúsculo
        foreach ($payload as $k => $v) {
            if (is_scalar($v) || is_null($v)) {
                $flat[strtolower(trim((string) $k))] = trim((string) $v);
            }
        }

        // Função auxiliar para buscar primeiro valor encontrado
        $find = function(array $candidates) use ($flat) {
            foreach ($candidates as $cand) {
                $cand_lower = strtolower($cand);
                if (isset($flat[$cand_lower]) && $flat[$cand_lower] !== '') {
                    return $flat[$cand_lower];
                }
            }
            return '';
        };

        $nome = $find(['nome', 'name', 'full_name', 'first_name', 'cliente', 'contato', 'lead_nome']);
        $email = $find(['email', 'e-mail', 'mail', 'lead_email', 'user_email', 'work_email']);
        $telefone = $find(['telefone', 'phone', 'phone_number', 'raw_phone_number', 'whatsapp', 'celular', 'tel', 'mobile', 'telefone_normalizado', 'lead_telefone', 'user_phone', 'telefone_contato']);

        $tipo_curso = $find(['tipo_curso', 'curso', 'course', 'modalidade', 'grau']);
        $area_interesse = $find(['area_interesse', 'area', 'segmento']);
        $polo = $find(['polo', 'unidade', 'cidade', 'campus', 'polo_apoio']);

        // Configurações padrão definidas no plugin (padrão oficial: google_lead_form)
        $cfg_source   = !empty($settings['webhook_inbound_utm_source']) && $settings['webhook_inbound_utm_source'] !== 'google_ads' 
            ? trim($settings['webhook_inbound_utm_source']) 
            : 'google_lead_form';
        $cfg_medium   = !empty($settings['webhook_inbound_utm_medium']) ? trim($settings['webhook_inbound_utm_medium']) : 'lead_form';
        $cfg_campaign = !empty($settings['webhook_inbound_utm_campaign']) ? trim($settings['webhook_inbound_utm_campaign']) : 'formulario google';
        $cfg_form_nm  = !empty($settings['webhook_inbound_form_name']) ? trim($settings['webhook_inbound_form_name']) : 'Formulário Google';

        // Determinação das UTMs com prioridade (Query String > Payload Limpo > Configuração Padrão)
        $raw_extracted_source = $find(['utm_source', 'source', 'origem']);
        $raw_extracted_medium = $find(['utm_medium', 'medium', 'meio']);

        $utm_source = !empty($query_params['utm_source']) 
            ? $query_params['utm_source'] 
            : ($raw_extracted_source ?: $cfg_source);

        $utm_medium = !empty($query_params['utm_medium']) 
            ? $query_params['utm_medium'] 
            : ($raw_extracted_medium ?: $cfg_medium);

        // Se for lead nativo de formulário do Google Ads e vier com utm_source genérica 'google_ads' ou vazia, padroniza como 'google_lead_form'
        $is_google_form_payload = (!empty($payload['user_column_data']) || !empty($payload['google_key']) || $utm_medium === 'lead_form');
        if ($is_google_form_payload && (empty($utm_source) || $utm_source === 'google_ads' || $utm_source === 'google')) {
            $utm_source = 'google_lead_form';
        }

        // Se no payload vier campaign_id numérico (ex: 21849182), substitui pelo nome legível amigável "formulario google"
        $payload_campaign = $find(['utm_campaign', 'campaign', 'campanha', 'campaign_name']);
        if (!empty($query_params['utm_campaign'])) {
            $utm_campaign = $query_params['utm_campaign'];
        } elseif (!empty($payload_campaign) && !is_numeric($payload_campaign) && strpos($payload_campaign, 'camp_') !== 0) {
            $utm_campaign = $payload_campaign;
        } else {
            $utm_campaign = $cfg_campaign;
        }

        $form_nome = !empty($query_params['formulario_nome']) 
            ? $query_params['formulario_nome'] 
            : ($find(['formulario_nome', 'form_name', 'origem_nome', 'titulo']) ?: $cfg_form_nm);

        $utm_content  = $find(['utm_content', 'content', 'anuncio', 'ad_name']);
        $utm_term     = $find(['utm_term', 'term', 'palavra_chave', 'keyword']);

        $gclid      = $find(['gclid', 'google_click_id']);
        $fbclid     = $find(['fbclid', 'facebook_click_id']);
        $gad_source = $find(['gad_source']);

        $etapa_funil = $find(['etapa_funil', 'etapa', 'status', 'situacao']) ?: 'Formulário Enviado';
        $qualificacao_status = $find(['qualificacao_status']) ?: 'pendente';

        return [
            'nome'                => sanitize_text_field($nome),
            'email'               => sanitize_email($email),
            'telefone'            => sanitize_text_field($telefone),
            'tipo_curso'          => sanitize_text_field($tipo_curso),
            'area_interesse'      => sanitize_text_field($area_interesse),
            'polo'                => sanitize_text_field($polo),
            'formulario_nome'     => sanitize_text_field($form_nome),
            'utm_source'          => sanitize_text_field($utm_source),
            'utm_medium'          => sanitize_text_field($utm_medium),
            'utm_campaign'        => sanitize_text_field($utm_campaign),
            'utm_content'         => sanitize_text_field($utm_content),
            'utm_term'            => sanitize_text_field($utm_term),
            'gclid'               => sanitize_text_field($gclid),
            'fbclid'              => sanitize_text_field($fbclid),
            'gad_source'          => sanitize_text_field($gad_source),
            'etapa_funil'         => sanitize_text_field($etapa_funil),
            'qualificacao_status' => sanitize_text_field($qualificacao_status),
        ];
    }

    /**
     * Retorna o IP do cliente
     */
    private static function get_client_ip() {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return sanitize_text_field($_SERVER['HTTP_CF_CONNECTING_IP']);
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return sanitize_text_field(trim($parts[0]));
        }
        return !empty($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : '127.0.0.1';
    }

    /**
     * Retorna a URL pública completa do endpoint de entrada
     */
    public static function get_inbound_url() {
        return get_rest_url(null, self::ENDPOINT_NAMESPACE . self::ENDPOINT_ROUTE);
    }
}
