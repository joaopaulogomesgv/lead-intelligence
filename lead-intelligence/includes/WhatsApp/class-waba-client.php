<?php
namespace LeadIntelligence\WhatsApp;

use LeadIntelligence\Admin\Settings;
use LeadIntelligence\PhoneNormalizer;
use LeadIntelligence\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cliente da Meta Graph API para WhatsApp Cloud API (v21.0)
 */
class WabaClient {

    /**
     * Testa a conexão e permissões com a Meta Graph API
     *
     * @return array ['success' => bool, 'message' => string, 'data' => array]
     */
    public static function test_connection() {
        $settings = Settings::get_settings();

        $token           = $settings['meta_access_token'];
        $phone_number_id = $settings['meta_phone_number_id'];
        $version         = $settings['meta_graph_version'] ?: 'v26.0';

        if (empty($token)) {
            return [
                'success' => false,
                'message' => 'Access Token da Meta não configurado. Por favor, insira nas configurações.',
                'data'    => [],
            ];
        }

        if (empty($phone_number_id)) {
            return [
                'success' => false,
                'message' => 'Phone Number ID não configurado.',
                'data'    => [],
            ];
        }

        $url = "https://graph.facebook.com/{$version}/{$phone_number_id}?fields=verified_name,code_verification_status,display_phone_number,quality_rating";

        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => 'Falha de conexão com a Meta: ' . $response->get_error_message(),
                'data'    => [],
            ];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 200 && !empty($body['id'])) {
            return [
                'success' => true,
                'message' => 'Conexão validada com sucesso! Número: ' . ($body['display_phone_number'] ?? $phone_number_id),
                'data'    => $body,
            ];
        }

        $error_msg = $body['error']['message'] ?? 'Erro desconhecido na Graph API.';
        return [
            'success' => false,
            'message' => "Erro da Meta (HTTP {$code}): {$error_msg}",
            'data'    => $body,
        ];
    }

    /**
     * Envia mensagem de texto via WhatsApp Cloud API
     */
    public static function send_text_message($to_phone, $message) {
        $settings = Settings::get_settings();

        $token           = $settings['meta_access_token'];
        $phone_number_id = $settings['meta_phone_number_id'];
        $version         = $settings['meta_graph_version'] ?: 'v26.0';

        if (empty($token) || empty($phone_number_id)) {
            return false;
        }

        $normalized = PhoneNormalizer::normalize($to_phone);
        $url = "https://graph.facebook.com/{$version}/{$phone_number_id}/messages";

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $normalized,
            'type'              => 'text',
            'text'              => [
                'preview_url' => false,
                'body'        => $message,
            ],
        ];

        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode($payload),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            Logger::error('WhatsApp', 'Falha no envio de mensagem: ' . $response->get_error_message());
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 200 && !empty($body['messages'][0]['id'])) {
            return $body['messages'][0]['id'];
        }

        Logger::error('WhatsApp', 'Erro no envio de mensagem WhatsApp Cloud API.', $body);
        return false;
    }
}
