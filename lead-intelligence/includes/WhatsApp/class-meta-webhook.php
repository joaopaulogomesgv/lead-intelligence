<?php
namespace LeadIntelligence\WhatsApp;

use LeadIntelligence\Logger;
use LeadIntelligence\Admin\Settings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Controller REST do Webhook Oficial da Meta Cloud API (WhatsApp)
 * Endpoint: /wp-json/lead-intelligence/v1/meta/webhook
 */
class MetaWebhook {

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_routes() {
        register_rest_route('lead-intelligence/v1', '/meta/webhook', [
            [
                'methods'             => \WP_REST_Server::READABLE, // GET
                'callback'            => [__CLASS__, 'handle_verification'],
                'permission_callback' => '__return_true',
            ],
            [
                'methods'             => \WP_REST_Server::CREATABLE, // POST
                'callback'            => [__CLASS__, 'handle_incoming_events'],
                'permission_callback' => '__return_true',
            ],
        ]);
    }

    /**
     * Handshake de Verificação da Meta (GET)
     * Parâmetros esperados pela Meta:
     * - hub.mode = 'subscribe'
     * - hub.verify_token = token configurado no painel
     * - hub.challenge = string aleatória enviada pela Meta que deve ser ecoada
     */
    public static function handle_verification(\WP_REST_Request $request) {
        $params = $request->get_query_params();

        $mode         = isset($params['hub_mode']) ? $params['hub_mode'] : ($params['hub.mode'] ?? '');
        $verify_token = isset($params['hub_verify_token']) ? $params['hub_verify_token'] : ($params['hub.verify_token'] ?? '');
        $challenge    = isset($params['hub_challenge']) ? $params['hub_challenge'] : ($params['hub.challenge'] ?? '');

        $settings = Settings::get_settings();
        $expected_token = $settings['meta_verify_token'];

        Logger::info('WhatsApp', 'Requisição de verificação de Webhook recebida da Meta.', [
            'mode'      => $mode,
            'token_rec' => $verify_token ? substr($verify_token, 0, 4) . '***' : 'vazio',
        ]);

        if ($mode === 'subscribe' && !empty($verify_token) && !empty($expected_token) && hash_equals($expected_token, $verify_token)) {
            // A Meta exige retorno do challenge em texto puro e código HTTP 200
            Logger::info('WhatsApp', 'Webhook verificado com sucesso pela Meta Graph API.');
            header('Content-Type: text/plain');
            echo $challenge;
            exit;
        }

        Logger::warning('WhatsApp', 'Falha na verificação do Webhook. Token incorreto ou modo inválido.', [
            'expected' => substr($expected_token, 0, 4) . '***',
            'received' => substr($verify_token, 0, 4) . '***',
        ]);

        return new \WP_REST_Response(['error' => 'Forbidden - Invalid verification token'], 403);
    }

    /**
     * Recepção de Eventos da WhatsApp Cloud API (POST)
     */
    public static function handle_incoming_events(\WP_REST_Request $request) {
        $raw_body = $request->get_body();
        $headers  = $request->get_headers();

        $settings = Settings::get_settings();

        // 1. Validação de Assinatura Criptográfica X-Hub-Signature-256 (se App Secret estiver configurado)
        if (!empty($settings['meta_app_secret'])) {
            $signature_header = $request->get_header('x_hub_signature_256');
            if (empty($signature_header)) {
                $signature_header = $request->get_header('x-hub-signature-256');
            }

            if (!empty($signature_header)) {
                $expected_sig = 'sha256=' . hash_hmac('sha256', $raw_body, $settings['meta_app_secret']);
                if (!hash_equals($expected_sig, $signature_header)) {
                    Logger::error('WhatsApp', 'Assinatura X-Hub-Signature-256 inválida da Meta.', [
                        'received_sig' => $signature_header,
                    ]);
                    return new \WP_REST_Response(['error' => 'Invalid signature'], 401);
                }
            }
        }

        $payload = json_decode($raw_body, true);

        if (empty($payload)) {
            return new \WP_REST_Response(['status' => 'ignored_empty_payload'], 200);
        }

        Logger::debug('WhatsApp', 'Evento recebido no Webhook WhatsApp Cloud API.', [
            'object'  => $payload['object'] ?? 'unknown',
            'entries' => count($payload['entry'] ?? []),
        ]);

        // 2. Processa as mensagens e eventos de status
        try {
            MessageHandler::process_payload($payload);
        } catch (\Throwable $e) {
            Logger::error('WhatsApp', 'Erro ao processar payload do WhatsApp: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }

        // 3. A Meta exige resposta 200 OK imediata para não reenviar eventos
        return new \WP_REST_Response(['status' => 'success'], 200);
    }
}
