<?php
namespace LeadIntelligence\OpenAiCapi;

use LeadIntelligence\Admin\Settings;
use LeadIntelligence\PhoneNormalizer;
use LeadIntelligence\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Serviço de Integração com a OpenAI Conversions API (OpenAI Ads / ChatGPT Ads)
 * Responsável pela formatação dos eventos servidor-para-servidor,
 * anonimização SHA-256 em UTF-8 e despacho em lote (batch).
 */
class OpenAiCapiService {

    const API_ENDPOINT = 'https://bzr.openai.com/v1/events';
    const INTEGRATION_SOURCE = 'lead_intelligence';

    /**
     * Envia o evento oficial 'lead_created' para a OpenAI Conversions API
     *
     * @param object $lead Objeto do lead vindo de wp_li_leads
     * @return array ['success' => bool, 'message' => string, 'event_id' => string, 'response' => array]
     */
    public static function send_lead_event($lead) {
        $event = self::build_event_payload('lead_created', $lead, [
            'type' => 'customer_action',
        ]);

        return self::dispatch_batch([$event]);
    }

    /**
     * Envia o evento 'custom' com custom_event_name='lead_qualified'
     *
     * @param object $lead Objeto do lead vindo de wp_li_leads
     * @return array ['success' => bool, 'message' => string, 'event_id' => string, 'response' => array]
     */
    public static function send_lead_qualified_event($lead) {
        $event = self::build_event_payload('custom', $lead, [
            'type'   => 'custom',
            'status' => $lead->qualificacao_status ?? 'qualificado',
            'score'  => (int) ($lead->score ?? 0),
        ], 'lead_qualified');

        return self::dispatch_batch([$event]);
    }

    /**
     * Envia o evento oficial 'order_created' (Venda / Matrícula) para a OpenAI Conversions API
     *
     * @param object $lead Objeto do lead vindo de wp_li_leads
     * @param float $value Valor em reais (ex: 100.00)
     * @param string $currency Moeda ISO 4217 (padrão BRL)
     * @return array ['success' => bool, 'message' => string, 'event_id' => string, 'response' => array]
     */
    public static function send_purchase_event($lead, $value = 100.00, $currency = 'BRL') {
        $val = (float) (!empty($value) && $value > 0 ? $value : (!empty($lead->valor_venda) ? $lead->valor_venda : 100.00));
        // A OpenAI exige amount na menor unidade (centavos para BRL/USD): ex: 100.00 -> 10000
        $amount_cents = (int) round($val * 100);
        $course_name  = !empty($lead->tipo_curso) ? $lead->tipo_curso : (!empty($lead->area_interesse) ? $lead->area_interesse : 'Matrícula');

        $data_payload = [
            'type'     => 'contents',
            'amount'   => $amount_cents,
            'currency' => strtoupper($currency),
            'contents' => [
                [
                    'id'           => 'curso_' . ($lead->id ?? '0'),
                    'name'         => $course_name,
                    'content_type' => 'product',
                    'quantity'     => 1,
                    'amount'       => $amount_cents,
                    'currency'     => strtoupper($currency),
                ],
            ],
        ];

        $event = self::build_event_payload('order_created', $lead, $data_payload);
        return self::dispatch_batch([$event]);
    }

    /**
     * Constrói a estrutura do evento de acordo com a documentação da OpenAI
     */
    public static function build_event_payload($event_type, $lead, $data_payload = [], $custom_event_name = '') {
        $event_id = 'li_oai_' . strtolower($event_type) . '_' . ($lead->id ?? wp_rand(1000, 9999)) . '_' . time();
        $timestamp_ms = (int) round(microtime(true) * 1000);

        // Origem da URL (source_url com esquema e host obrigatório para action_source=web)
        $source_url = !empty($lead->pagina_origem) && filter_var($lead->pagina_origem, FILTER_VALIDATE_URL)
            ? $lead->pagina_origem
            : home_url('/');

        $event = [
            'id'            => $event_id,
            'type'          => $event_type,
            'timestamp_ms'  => $timestamp_ms,
            'action_source' => 'web',
            'source_url'    => $source_url,
            'user'          => self::prepare_user_data($lead),
            'data'          => $data_payload ?: ['type' => 'customer_action'],
        ];

        // Se for evento customizado, o custom_event_name é obrigatório
        if ($event_type === 'custom' && !empty($custom_event_name)) {
            $event['custom_event_name'] = strtolower(sanitize_key($custom_event_name));
        }

        // Identificador de atribuição da OpenAI (oppref) se presente
        if (!empty($lead->oppref)) {
            $event['oppref'] = sanitize_text_field($lead->oppref);
        }

        return $event;
    }

    /**
     * Prepara e normaliza os dados do usuário para hashing SHA-256 estrito conforme a OpenAI
     * 
     * Regras:
     * - Listas no plural (máx 3 itens cada): HTTP 400 se ultrapassar.
     * - Telefone: 8 a 15 dígitos sem '+', sem zeros à esquerda, hash SHA-256 minúsculo.
     * - E-mail: trim, minúsculas, hash SHA-256 minúsculo.
     * - Nomes: minúsculas, remove pontuação ASCII e espaços, preserva acentos não-ASCII, SHA-256 minúsculo.
     * - Identificadores não-hasheados: obref, ip_address, user_agent, countries.
     */
    public static function prepare_user_data($lead) {
        $user = [];

        // 1. Telefone (phone_numbers_sha256)
        $phone_raw = $lead->telefone_normalizado ?: $lead->telefone;
        if (!empty($phone_raw)) {
            $clean_phone = self::normalize_phone_for_openai($phone_raw);
            if (!empty($clean_phone) && strlen($clean_phone) >= 8 && strlen($clean_phone) <= 15) {
                $user['phone_numbers_sha256'] = [hash('sha256', $clean_phone)];
            }
        }

        // 2. E-mail (emails_sha256)
        if (!empty($lead->email)) {
            $clean_email = strtolower(trim($lead->email));
            if (is_email($clean_email)) {
                $user['emails_sha256'] = [hash('sha256', $clean_email)];
            }
        }

        // 3. ID Externo (external_ids_sha256)
        $ext_id = !empty($lead->uuid) ? $lead->uuid : (!empty($lead->id) ? 'lead_' . $lead->id : '');
        if (!empty($ext_id)) {
            $user['external_ids_sha256'] = [hash('sha256', trim($ext_id))];
        }

        // 4. Nome e Sobrenome (first_names_sha256 / last_names_sha256)
        if (!empty($lead->nome)) {
            $name_parts = explode(' ', trim($lead->nome));
            $first_name = array_shift($name_parts);
            $last_name  = implode(' ', $name_parts);

            $clean_first = self::normalize_name_for_openai($first_name);
            if (!empty($clean_first)) {
                $user['first_names_sha256'] = [hash('sha256', $clean_first)];
            }

            if (!empty($last_name)) {
                $clean_last = self::normalize_name_for_openai($last_name);
                if (!empty($clean_last)) {
                    $user['last_names_sha256'] = [hash('sha256', $clean_last)];
                }
            }
        }

        // 5. Cookie primário __obref da OpenAI (sem hash)
        if (!empty($lead->obref)) {
            $user['obref'] = sanitize_text_field($lead->obref);
        }

        // 6. Dados de Rede e Dispositivo (sem hash)
        if (!empty($lead->ip_address) && filter_var($lead->ip_address, FILTER_VALIDATE_IP)) {
            $user['ip_address'] = $lead->ip_address;
        }

        if (!empty($lead->user_agent)) {
            $user['user_agent'] = substr(sanitize_text_field($lead->user_agent), 0, 500);
        }

        // 7. País padrão (lista de strings brutas)
        $user['countries'] = ['BR'];

        return $user;
    }

    /**
     * Normalização de telefone para OpenAI:
     * Mantém DDI, remove espaços, parênteses, pontos, hífens, sinal de + e zeros à esquerda.
     */
    public static function normalize_phone_for_openai($phone) {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        // Remove zeros à esquerda
        $digits = ltrim($digits, '0');

        // Se tiver 10 ou 11 dígitos (DDD + número BR), adiciona o DDI 55
        if (strlen($digits) === 10 || strlen($digits) === 11) {
            $digits = '55' . $digits;
        }

        return $digits;
    }

    /**
     * Normalização de nome para OpenAI:
     * Minúsculas, remove pontuação ASCII e espaços, preserva acentos e caracteres UTF-8 não-ASCII.
     */
    public static function normalize_name_for_openai($name) {
        $name = mb_strtolower(trim((string) $name), 'UTF-8');
        // Remove pontuação ASCII e espaços em branco
        $name = preg_replace('/[[:punct:]\s]+/', '', $name);
        return $name;
    }

    /**
     * Despacha um lote de eventos para a OpenAI CAPI (até 1.000 eventos)
     *
     * @param array $events Array de eventos construídos
     * @param bool $validate_only Se true, apenas valida a estrutura sem persistir a conversão
     * @return array
     */
    public static function dispatch_batch($events, $validate_only = false) {
        $settings = Settings::get_settings();

        $pixel_id = trim($settings['openai_pixel_id'] ?? '');
        $api_key  = trim($settings['openai_api_key'] ?? '');

        if (empty($pixel_id) || empty($api_key)) {
            return [
                'success'  => false,
                'message'  => 'OpenAI Pixel ID ou API Key da Conversions API não configurados.',
                'response' => [],
            ];
        }

        if (empty($events) || !is_array($events)) {
            return [
                'success'  => false,
                'message'  => 'Nenhum evento fornecido para despacho.',
                'response' => [],
            ];
        }

        // Limite da API: até 1.000 eventos por lote
        $events_batch = array_slice($events, 0, 1000);

        $request_body = [
            'validate_only'      => (bool) $validate_only,
            'integration_source' => self::INTEGRATION_SOURCE,
            'events'             => $events_batch,
        ];

        $url = add_query_arg(['pid' => $pixel_id], self::API_ENDPOINT);

        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode($request_body),
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            $err_msg = $response->get_error_message();
            Logger::error('OpenAiCAPI', 'Falha na conexão HTTP com a OpenAI CAPI: ' . $err_msg, [
                'pixel_id'      => $pixel_id,
                'total_events'  => count($events_batch),
                'validate_only' => $validate_only,
            ]);

            return [
                'success'  => false,
                'message'  => 'Erro de conexão HTTP: ' . $err_msg,
                'response' => [],
            ];
        }

        $code = wp_remote_retrieve_response_code($response);
        $raw_body = wp_remote_retrieve_body($response);
        $body = json_decode($raw_body, true) ?: [];

        $first_event_id = $events_batch[0]['id'] ?? '';

        if ($code >= 200 && $code < 300) {
            Logger::info('OpenAiCAPI', "Lote de eventos despachado com sucesso para a OpenAI CAPI (HTTP {$code}).", [
                'pixel_id'      => $pixel_id,
                'total_events'  => count($events_batch),
                'validate_only' => $validate_only,
                'first_id'      => $first_event_id,
            ]);

            return [
                'success'  => true,
                'message'  => $validate_only ? 'Estrutura validada com sucesso pela OpenAI (modo teste)!' : 'Eventos recebidos com sucesso pela OpenAI.',
                'event_id' => $first_event_id,
                'response' => $body,
            ];
        }

        $err_details = $body['error']['message'] ?? ($body['message'] ?? $raw_body);
        Logger::error('OpenAiCAPI', "Erro retornado pela OpenAI CAPI (HTTP {$code}): {$err_details}", [
            'request_sample' => $events_batch[0] ?? [],
            'response'       => $body,
        ]);

        return [
            'success'  => false,
            'message'  => "Erro da OpenAI (HTTP {$code}): {$err_details}",
            'event_id' => $first_event_id,
            'response' => $body,
        ];
    }

    /**
     * Executa um teste em tempo real de conexão e validação estrutural com a OpenAI CAPI
     */
    public static function test_connection() {
        $dummy_lead = (object) [
            'id'                   => 999999,
            'uuid'                 => 'dummy-uuid-openai-capi-test',
            'nome'                 => 'Lead Teste OpenAI CAPI',
            'email'                => 'teste_capi@faveni.edu.br',
            'telefone'             => '5533999999999',
            'telefone_normalizado' => '5533999999999',
            'tipo_curso'           => 'Pós-Graduação',
            'area_interesse'       => 'Educação',
            'pagina_origem'        => home_url('/'),
            'ip_address'           => '127.0.0.1',
            'user_agent'           => 'LeadIntelligence-Tester/2.8.0',
            'oppref'               => 'test_oppref_12345',
            'obref'                => 'test_obref_67890',
        ];

        $event = self::build_event_payload('lead_created', $dummy_lead, [
            'type' => 'customer_action',
        ]);

        // Executa com validate_only=true conforme a documentação oficial da OpenAI
        return self::dispatch_batch([$event], true);
    }
}
