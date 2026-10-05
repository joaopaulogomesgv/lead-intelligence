<?php
namespace LeadIntelligence\MetaCapi;

use LeadIntelligence\Admin\Settings;
use LeadIntelligence\PhoneNormalizer;
use LeadIntelligence\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Serviço de Integração com a Meta Conversions API (CAPI v21.0)
 * Responsável pela formatação, anonimização SHA-256 e despacho de eventos.
 */
class CapiService {

    /**
     * Envia o evento 'Lead' para a Meta Conversions API
     *
     * @param object $lead Objeto do lead vindo de wp_li_leads
     * @return array ['success' => bool, 'message' => string, 'response' => array]
     */
    public static function send_lead_event($lead) {
        return self::dispatch_event('Lead', $lead, 'website');
    }

    /**
     * Envia o evento 'LeadQualified' para a Meta Conversions API
     *
     * @param object $lead Objeto do lead vindo de wp_li_leads
     * @return array ['success' => bool, 'message' => string, 'response' => array]
     */
    public static function send_lead_qualified_event($lead) {
        // Dispara como evento customizado LeadQualified ou QualifiedLead
        return self::dispatch_event('LeadQualified', $lead, 'system_generated');
    }

    /**
     * Prepara payload e despacha para a Graph API da Meta
     */
    public static function dispatch_event($event_name, $lead, $action_source = 'website') {
        $settings = Settings::get_settings();

        $pixel_id  = $settings['capi_pixel_id'];
        $token     = $settings['capi_access_token'];
        $test_code = $settings['capi_test_event_code'];
        $version   = $settings['meta_graph_version'] ?: 'v26.0';

        if (empty($pixel_id) || empty($token)) {
            return [
                'success' => false,
                'message' => 'Pixel ID ou Access Token da Conversions API não configurados.',
                'response' => [],
            ];
        }

        $event_id = 'li_' . strtolower($event_name) . '_' . $lead->id . '_' . time();

        // 1. Dados do Usuário com anonimização SHA-256 estrita
        $user_data = self::prepare_user_data($lead);

        // 2. Parâmetros Customizados
        $custom_data = [
            'currency'         => 'BRL',
            'lead_id'          => $lead->id,
            'lead_uuid'        => $lead->uuid,
            'status'           => $lead->qualificacao_status,
            'curso'            => $lead->tipo_curso ?: 'Geral',
            'area_interesse'   => $lead->area_interesse ?: 'Geral',
        ];

        if ($event_name === 'LeadQualified') {
            $custom_data['score'] = (int) $lead->score;
            $custom_data['origem_qualificacao'] = $lead->qualificacao_origem;
        }

        $event_payload = [
            'event_name'       => $event_name,
            'event_time'       => time(),
            'event_id'         => $event_id,
            'action_source'    => $action_source,
            'event_source_url' => !empty($lead->pagina_origem) && filter_var($lead->pagina_origem, FILTER_VALIDATE_URL) ? $lead->pagina_origem : home_url(),
            'user_data'        => $user_data,
            'custom_data'      => $custom_data,
        ];

        $request_body = [
            'data' => [$event_payload],
        ];

        // Se houver código de teste configurado para a aba "Testar Eventos" do Events Manager
        if (!empty($test_code)) {
            $request_body['test_event_code'] = $test_code;
        }

        $url = "https://graph.facebook.com/{$version}/{$pixel_id}/events";

        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode($request_body),
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            Logger::error('MetaCAPI', "Falha ao enviar {$event_name} do Lead #{$lead->id}: " . $response->get_error_message());
            return [
                'success' => false,
                'message' => $response->get_error_message(),
                'response' => [],
            ];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 200 && !empty($body['events_received'])) {
            Logger::info('MetaCAPI', "Evento {$event_name} enviado com sucesso para Meta CAPI (Lead #{$lead->id}).", [
                'event_id'        => $event_id,
                'events_received' => $body['events_received'],
                'test_code'       => $test_code ?: 'produção',
            ]);

            return [
                'success'  => true,
                'message'  => "Evento {$event_name} recebido pela Meta com sucesso.",
                'event_id' => $event_id,
                'response' => $body,
            ];
        }

        $err_msg = $body['error']['message'] ?? 'Erro desconhecido na Meta CAPI.';
        Logger::error('MetaCAPI', "Erro ao despachar {$event_name} para Meta CAPI (HTTP {$code}): {$err_msg}", $body);

        return [
            'success'  => false,
            'message'  => "Erro da Meta (HTTP {$code}): {$err_msg}",
            'response' => $body,
        ];
    }

    /**
     * Formata e anonimiza os dados do usuário conforme as diretrizes oficiais da Meta
     */
    public static function prepare_user_data($lead) {
        $user_data = [];

        // E-mail: minúsculo, sem espaços, SHA-256
        if (!empty($lead->email)) {
            $clean_email = strtolower(trim($lead->email));
            $user_data['em'] = [hash('sha256', $clean_email)];
        }

        // Telefone: normalizado com DDI +55..., apenas dígitos, SHA-256
        $phone_raw = !empty($lead->telefone_normalizado) ? $lead->telefone_normalizado : $lead->telefone;
        if (!empty($phone_raw)) {
            $norm = PhoneNormalizer::normalize($phone_raw);
            $user_data['ph'] = [hash('sha256', $norm)];
        }

        // Nome e Sobrenome
        if (!empty($lead->nome)) {
            $parts = explode(' ', trim($lead->nome));
            $first_name = strtolower(trim(array_shift($parts)));
            $last_name  = strtolower(trim(implode(' ', $parts)));

            if (!empty($first_name)) {
                $user_data['fn'] = [hash('sha256', $first_name)];
            }
            if (!empty($last_name)) {
                $user_data['ln'] = [hash('sha256', $last_name)];
            }
        }

        // Cookies Meta nativos (não devem ser hasheados)
        if (!empty($lead->fbc)) {
            $user_data['fbc'] = $lead->fbc;
        } elseif (!empty($_COOKIE['_fbc'])) {
            $user_data['fbc'] = sanitize_text_field(wp_unslash($_COOKIE['_fbc']));
        }

        if (!empty($lead->fbp)) {
            $user_data['fbp'] = $lead->fbp;
        } elseif (!empty($_COOKIE['_fbp'])) {
            $user_data['fbp'] = sanitize_text_field(wp_unslash($_COOKIE['_fbp']));
        }

        // IP e User-Agent do cliente (não hasheados)
        if (!empty($lead->ip_address) && filter_var($lead->ip_address, FILTER_VALIDATE_IP)) {
            $user_data['client_ip_address'] = $lead->ip_address;
        }
        if (!empty($lead->user_agent)) {
            $user_data['client_user_agent'] = $lead->user_agent;
        }

        // País padrão Brasil
        $user_data['country'] = [hash('sha256', 'br')];

        return $user_data;
    }

    /**
     * Dispara evento de teste sintético para validar a configuração no Gerenciador de Eventos da Meta
     */
    public static function send_test_ping($pixel_id, $token, $test_code, $version = 'v26.0') {
        $dummy_lead = (object) [
            'id'                   => 9999,
            'uuid'                 => wp_generate_uuid4(),
            'nome'                 => 'Lead Teste Conversions API',
            'email'                => 'teste.capi@faveni.edu.br',
            'telefone'             => '34999999999',
            'telefone_normalizado' => '5534999999999',
            'tipo_curso'           => 'Pós-Graduação',
            'area_interesse'       => 'Educação',
            'pagina_origem'        => home_url(),
            'ip_address'           => '127.0.0.1',
            'user_agent'           => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) LeadIntelligence-CAPI/1.0',
            'fbc'                  => 'fb.1.' . time() . '.mock_fbclid_123',
            'fbp'                  => 'fb.1.' . time() . '.1234567890',
            'qualificacao_status'  => 'qualificado',
            'score'                => 100,
            'qualificacao_origem'  => 'teste_painel',
        ];

        $event_id = 'li_test_ping_' . time();
        $user_data = self::prepare_user_data($dummy_lead);

        $payload = [
            'data' => [
                [
                    'event_name'       => 'LeadQualified',
                    'event_time'       => time(),
                    'event_id'         => $event_id,
                    'action_source'    => 'system_generated',
                    'event_source_url' => home_url(),
                    'user_data'        => $user_data,
                    'custom_data'      => [
                        'currency' => 'BRL',
                        'value'    => 100.00,
                        'status'   => 'qualificado_teste',
                    ],
                ]
            ],
        ];

        if (!empty($test_code)) {
            $payload['test_event_code'] = $test_code;
        }

        $url = "https://graph.facebook.com/{$version}/{$pixel_id}/events";

        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode($payload),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => 'Falha de conexão com a Meta: ' . $response->get_error_message(),
            ];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 200 && !empty($body['events_received'])) {
            return [
                'success' => true,
                'message' => "Evento de teste aceito pela Meta! Eventos processados: {$body['events_received']}. Verifique agora na aba 'Testar Eventos' do seu Gerenciador de Eventos da Meta.",
                'data'    => $body,
            ];
        }

        $err_msg = $body['error']['message'] ?? 'Erro desconhecido na Meta CAPI.';
        return [
            'success' => false,
            'message' => "Erro retornado pela Meta (HTTP {$code}): {$err_msg}",
            'data'    => $body,
        ];
    }
}
