<?php
namespace LeadIntelligence\WhatsApp;

use LeadIntelligence\Admin\Settings;
use LeadIntelligence\PhoneNormalizer;
use LeadIntelligence\Logger;
use LeadIntelligence\Database\DbSchema;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cliente da Meta Graph API para WhatsApp Cloud API e Marketing API
 */
class WabaClient {

    /**
     * Testa a conexão e permissões com a Meta Graph API (WhatsApp Cloud)
     *
     * @return array ['success' => bool, 'message' => string, 'data' => array]
     */
        /**
     * Subscreve este Meta App à WABA para recebimento simultâneo de webhooks sem interferir no outro app
     * A Meta permite múltiplos Meta Apps inscritos na mesma WABA recebendo eventos em paralelo.
     *
     * @param string|null $waba_id
     * @param string|null $phone_number_id
     * @return array ['success' => bool, 'message' => string]
     */
    public static function subscribe_waba_to_app($waba_id = null, $phone_number_id = null) {
        $settings = Settings::get_settings();

        $polo = !empty($phone_number_id) ? PoloRepository::find_by_phone_number_id($phone_number_id) : PoloRepository::get_default();

        if ($polo) {
            $waba_id = !empty($waba_id) ? $waba_id : ($polo['waba_id'] ?? '');
            $token   = PoloRepository::get_access_token_for_polo($polo);
        } else {
            $token   = $settings['meta_access_token'];
            $waba_id = !empty($waba_id) ? $waba_id : ($settings['meta_waba_id'] ?? '');
        }

        $version = $settings['meta_graph_version'] ?: 'v26.0';

        if (empty($token) || empty($waba_id)) {
            return [
                'success' => false,
                'message' => 'Token ou WABA ID não configurados para subscrição.',
            ];
        }

        $url = "https://graph.facebook.com/{$version}/{$waba_id}/subscribed_apps?subscribed_fields=messages";

        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => 'Erro ao subscrever App à WABA: ' . $response->get_error_message(),
            ];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 200 && !empty($body['success'])) {
            Logger::info('WhatsApp', "Meta App subscrito com sucesso à WABA {$waba_id} para recebimento simultâneo.");
            return [
                'success' => true,
                'message' => "App subscrito à WABA {$waba_id}! Eventos agora são entregues simultaneamente a ambos os apps.",
            ];
        }

        $error_msg = $body['error']['message'] ?? 'Erro desconhecido na subscrição à WABA.';
        Logger::warning('WhatsApp', "Tentativa de subscrição à WABA {$waba_id}: {$error_msg} (HTTP {$code})");
        return [
            'success' => false,
            'message' => "Subscrição WABA: {$error_msg}",
        ];
    }

    public static function test_connection($phone_number_id = null) {
        $settings = Settings::get_settings();

        $polo = !empty($phone_number_id) ? PoloRepository::find_by_phone_number_id($phone_number_id) : PoloRepository::get_default();

        if ($polo) {
            $phone_number_id = $polo['phone_number_id'];
            $token           = PoloRepository::get_access_token_for_polo($polo);
        } else {
            $token           = $settings['meta_access_token'];
            $phone_number_id = !empty($phone_number_id) ? $phone_number_id : $settings['meta_phone_number_id'];
        }

        $version = $settings['meta_graph_version'] ?: 'v26.0';

        if (empty($token)) {
            return [
                'success' => false,
                'message' => 'Access Token da Meta não configurado. Por favor, insira nas configurações ou no polo.',
                'data'    => [],
            ];
        }

        if (empty($phone_number_id)) {
            return [
                'success' => false,
                'message' => 'Nenhum Phone Number ID configurado para teste.',
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
            $polo_info = PoloRepository::find_by_phone_number_id($phone_number_id);
            $polo_label = $polo_info && !empty($polo_info['nome']) ? " [{$polo_info['nome']}]" : "";
            $target_waba = $polo_info['waba_id'] ?? ($settings['meta_waba_id'] ?? '');

            // Ativa automaticamente a subscrição simultânea da WABA ao App
            $sub_msg = "";
            if (!empty($target_waba)) {
                $sub_res = self::subscribe_waba_to_app($target_waba, $phone_number_id);
                if (!empty($sub_res['success'])) {
                    $sub_msg = " | 🔗 Subscrição Simultânea da WABA Ativada com Sucesso!";
                } else {
                    $sub_msg = " | ⚠️ Atenção Subscrição WABA: " . ($sub_res['message'] ?? '');
                }
            }

            // Inspeciona qual Meta App gerou este Token
            $app_name = 'Desconhecido';
            $app_id   = 'N/D';
            $app_resp = wp_remote_get("https://graph.facebook.com/{$version}/app", [
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'timeout' => 10,
            ]);
            if (!is_wp_error($app_resp)) {
                $app_data = json_decode(wp_remote_retrieve_body($app_resp), true);
                if (!empty($app_data['id'])) {
                    $app_name = $app_data['name'] ?? 'Sem Nome';
                    $app_id   = $app_data['id'];
                }
            }

            // Inspeciona a lista de Apps inscritos nesta WABA
            $sub_apps_info = [];
            if (!empty($target_waba)) {
                $sub_resp = wp_remote_get("https://graph.facebook.com/{$version}/{$target_waba}/subscribed_apps", [
                    'headers' => ['Authorization' => 'Bearer ' . $token],
                    'timeout' => 10,
                ]);
                if (!is_wp_error($sub_resp)) {
                    $sub_data = json_decode(wp_remote_retrieve_body($sub_resp), true);
                    if (!empty($sub_data['data'])) {
                        foreach ($sub_data['data'] as $sa) {
                            $wa_data = $sa['whatsapp_business_api_data'] ?? [];
                            $sub_apps_info[] = ($wa_data['name'] ?? ($sa['name'] ?? 'App')) . ' (ID ' . ($wa_data['id'] ?? ($sa['id'] ?? '')) . ')';
                        }
                    }
                }
            }

            $diag_msg = "App do Token: {$app_name} (ID {$app_id})";
            if (!empty($sub_apps_info)) {
                $diag_msg .= " | Apps Inscritos na WABA: " . implode(', ', $sub_apps_info);
            }

            Logger::info('WhatsApp', "Diagnóstico WABA: {$diag_msg}");

            return [
                'success'        => true,
                'message'        => "Conexão validada com sucesso!{$polo_label} Linha: " . ($body['display_phone_number'] ?? $phone_number_id) . $sub_msg . " | " . $diag_msg,
                'verified_name'  => $body['verified_name'] ?? ($polo_info['nome'] ?? ''),
                'display_phone'  => $body['display_phone_number'] ?? ($polo_info['display_phone'] ?? ''),
                'quality_rating' => $body['quality_rating'] ?? 'GREEN',
                'app_name'       => $app_name,
                'app_id'         => $app_id,
                'subscribed_apps'=> $sub_apps_info,
                'data'           => $body,
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
    public static function send_text_message($to_phone, $message, $phone_number_id = null) {
        $settings = Settings::get_settings();

        $polo = !empty($phone_number_id) ? PoloRepository::find_by_phone_number_id($phone_number_id) : PoloRepository::get_default();

        if ($polo) {
            $phone_number_id = $polo['phone_number_id'];
            $token           = PoloRepository::get_access_token_for_polo($polo);
        } else {
            $token           = $settings['meta_access_token'];
            $phone_number_id = !empty($phone_number_id) ? $phone_number_id : $settings['meta_phone_number_id'];
        }

        $version = $settings['meta_graph_version'] ?: 'v26.0';

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

    /**
     * Consulta detalhes de campanha, conjunto e anúncio na Meta Graph API a partir do ad_id.
     * Suporta múltiplos tokens (System User e CAPI), transient cache e resolução de nós aninhados.
     *
     * @param string $ad_id
     * @param bool   $bypass_cache
     * @return array
     */
    public static function get_ad_details($ad_id, $bypass_cache = false) {
        $ad_id = trim((string) $ad_id);
        if (empty($ad_id)) {
            return [];
        }

        // Cache transient de 24h para evitar requisições repetidas ao mesmo anúncio
        $cache_key = 'li_ad_meta_' . $ad_id;
        if (!$bypass_cache) {
            $cached = get_transient($cache_key);
            if (!empty($cached) && is_array($cached) && !empty($cached['ad_id'])) {
                return $cached;
            }
        }

        $settings = Settings::get_settings();
        $version  = !empty($settings['meta_graph_version']) ? $settings['meta_graph_version'] : 'v26.0';

        // Coleta tokens candidatos: 1º Tokens dos Polos / Números individuais, 2º meta_access_token, 3º capi_access_token
        $tokens = [];
        foreach (PoloRepository::get_all() as $polo) {
            if (!empty($polo['access_token']) && !in_array(trim($polo['access_token']), $tokens, true)) {
                $tokens['polo_' . ($polo['id'] ?? 'unknown')] = trim($polo['access_token']);
            }
        }
        if (!empty($settings['meta_access_token']) && !in_array(trim($settings['meta_access_token']), $tokens, true)) {
            $tokens['meta_access_token'] = trim($settings['meta_access_token']);
        }
        if (!empty($settings['capi_access_token']) && !in_array(trim($settings['capi_access_token']), $tokens, true)) {
            $tokens['capi_access_token'] = trim($settings['capi_access_token']);
        }

        if (empty($tokens)) {
            Logger::warning('WhatsApp', "Nenhum token configurado para consultar o Ad ID {$ad_id} na Meta Graph API.");
            return [];
        }

        $last_error = '';
        $endpoint   = "https://graph.facebook.com/{$version}/{$ad_id}";
        $url        = add_query_arg([
            'fields' => 'id,name,adset_id,campaign_id,adset{id,name},campaign{id,name}',
        ], $endpoint);

        foreach ($tokens as $token_type => $token) {
            $response = wp_remote_get($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                ],
                'timeout' => 15,
            ]);

            if (is_wp_error($response)) {
                $last_error = $response->get_error_message();
                Logger::warning('WhatsApp', "Falha HTTP na consulta do Ad ID {$ad_id} com token [{$token_type}]: {$last_error}");
                continue;
            }

            $code = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);

            if ($code === 200 && !empty($body['id'])) {
                $campaign_id   = $body['campaign']['id'] ?? ($body['campaign_id'] ?? '');
                $campaign_name = $body['campaign']['name'] ?? '';
                $adset_id      = $body['adset']['id'] ?? ($body['adset_id'] ?? '');
                $adset_name    = $body['adset']['name'] ?? '';
                $ad_name       = $body['name'] ?? '';

                // Fallback secundário: se tiver ID de campanha mas o nome aninhado vier vazio
                if (!empty($campaign_id) && empty($campaign_name)) {
                    $c_url = add_query_arg(['fields' => 'name'], "https://graph.facebook.com/{$version}/{$campaign_id}");
                    $c_res = wp_remote_get($c_url, [
                        'headers' => ['Authorization' => 'Bearer ' . $token],
                        'timeout' => 10,
                    ]);
                    if (!is_wp_error($c_res) && wp_remote_retrieve_response_code($c_res) === 200) {
                        $c_body = json_decode(wp_remote_retrieve_body($c_res), true);
                        $campaign_name = $c_body['name'] ?? '';
                    }
                }

                // Fallback secundário: se tiver ID de adset mas o nome aninhado vier vazio
                if (!empty($adset_id) && empty($adset_name)) {
                    $as_url = add_query_arg(['fields' => 'name'], "https://graph.facebook.com/{$version}/{$adset_id}");
                    $as_res = wp_remote_get($as_url, [
                        'headers' => ['Authorization' => 'Bearer ' . $token],
                        'timeout' => 10,
                    ]);
                    if (!is_wp_error($as_res) && wp_remote_retrieve_response_code($as_res) === 200) {
                        $as_body = json_decode(wp_remote_retrieve_body($as_res), true);
                        $adset_name = $as_body['name'] ?? '';
                    }
                }

                $data = [
                    'ad_id'         => $body['id'] ?? $ad_id,
                    'ad_name'       => $ad_name,
                    'adset_id'      => $adset_id,
                    'adset_name'    => $adset_name,
                    'campaign_id'   => $campaign_id,
                    'campaign_name' => $campaign_name,
                    'token_used'    => $token_type,
                ];

                set_transient($cache_key, $data, 24 * HOUR_IN_SECONDS);

                Logger::info('WhatsApp', "Dados do anúncio {$ad_id} enriquecidos com sucesso via Meta Graph API.", [
                    'ad_id'       => $ad_id,
                    'campanha'    => $campaign_name,
                    'adset'       => $adset_name,
                    'anuncio'     => $ad_name,
                    'token_usado' => $token_type,
                ]);

                return $data;
            }

            $meta_err_msg = $body['error']['message'] ?? "HTTP {$code}";
            $last_error   = "Erro Meta ({$meta_err_msg})";

            Logger::warning('WhatsApp', "Consulta ao Ad ID {$ad_id} falhou com token [{$token_type}]: {$meta_err_msg}", [
                'ad_id'      => $ad_id,
                'code'       => $code,
                'token_type' => $token_type,
                'response'   => $body['error'] ?? $body,
            ]);
        }

        Logger::error('WhatsApp', "Não foi possível puxar os dados do anúncio {$ad_id} na Meta. Último erro: {$last_error}. Dica: certifique-se de que o token possua a permissão 'ads_read' e a Conta de Anúncios correspondente vinculada no Meta Business Suite.");
        return [];
    }

    /**
     * Diagnóstico e teste de consulta de anúncio para o Painel Admin
     *
     * @param string $ad_id
     * @return array
     */
    public static function test_ad_details($ad_id) {
        $ad_id = trim((string) $ad_id);
        if (empty($ad_id)) {
            return [
                'success' => false,
                'message' => 'Por favor, informe um Ad ID numérico para teste (ex: 120251967573610530).',
            ];
        }

        $settings = Settings::get_settings();
        $version  = !empty($settings['meta_graph_version']) ? $settings['meta_graph_version'] : 'v26.0';

        $tokens = [];
        if (!empty($settings['meta_access_token'])) {
            $tokens['Access Token do Sistema (WhatsApp)'] = trim($settings['meta_access_token']);
        }
        if (!empty($settings['capi_access_token']) && !in_array(trim($settings['capi_access_token']), $tokens, true)) {
            $tokens['Access Token da CAPI'] = trim($settings['capi_access_token']);
        }

        if (empty($tokens)) {
            return [
                'success' => false,
                'message' => 'Nenhum Access Token configurado nas opções do plugin.',
            ];
        }

        $attempts = [];
        foreach ($tokens as $token_label => $token) {
            $endpoint = "https://graph.facebook.com/{$version}/{$ad_id}";
            $url      = add_query_arg([
                'fields' => 'id,name,adset_id,campaign_id,adset{id,name},campaign{id,name}',
            ], $endpoint);

            $response = wp_remote_get($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                ],
                'timeout' => 15,
            ]);

            if (is_wp_error($response)) {
                $attempts[] = [
                    'token' => $token_label,
                    'error' => $response->get_error_message(),
                ];
                continue;
            }

            $code = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);

            if ($code === 200 && !empty($body['id'])) {
                $data = self::get_ad_details($ad_id, true);
                return [
                    'success'     => true,
                    'ad_id'       => $ad_id,
                    'token_label' => $token_label,
                    'data'        => $data,
                    'message'     => 'Anúncio consultado com sucesso na Meta Marketing API!',
                ];
            }

            $attempts[] = [
                'token'     => $token_label,
                'http_code' => $code,
                'error'     => $body['error']['message'] ?? 'Erro desconhecido',
                'type'      => $body['error']['type'] ?? '',
                'code_num'  => $body['error']['code'] ?? 0,
            ];
        }

        return [
            'success'  => false,
            'ad_id'    => $ad_id,
            'attempts' => $attempts,
            'message'  => 'A Meta rejeitou a consulta ao anúncio. Veja o diagnóstico detalhado abaixo.',
        ];
    }

    /**
     * Sincroniza dados da Meta Ads para um lead específico
     *
     * @param int $lead_id
     * @return array
     */
    public static function sync_lead_ad_data($lead_id) {
        global $wpdb;
        $leads_table   = DbSchema::get_leads_table();
        $history_table = DbSchema::get_history_table();
        $lead_id       = (int) $lead_id;

        $lead = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$leads_table} WHERE id = %d", $lead_id));
        if (!$lead) {
            return ['success' => false, 'message' => "Lead #{$lead_id} não encontrado."];
        }

        $ad_id = trim((string) $lead->ad_id);

        // Se o lead não tem ad_id, tenta recuperar do payload bruto das mensagens do WhatsApp
        if (empty($ad_id)) {
            $whatsapp_table = DbSchema::get_whatsapp_table();
            $msg = $wpdb->get_row($wpdb->prepare(
                "SELECT payload_bruto FROM {$whatsapp_table} WHERE lead_id = %d AND INSTR(payload_bruto, 'source_id') > 0 ORDER BY id DESC LIMIT 1",
                $lead_id
            ));
            if ($msg && !empty($msg->payload_bruto)) {
                $json = json_decode($msg->payload_bruto, true);
                $ad_id = $json['referral']['source_id'] ?? '';
                if (!empty($ad_id)) {
                    $wpdb->update($leads_table, ['ad_id' => $ad_id], ['id' => $lead_id]);
                    $lead->ad_id = $ad_id;
                }
            }
        }

        if (empty($ad_id)) {
            return [
                'success' => false,
                'message' => "O Lead #{$lead_id} não possui Ad ID registrado (não veio de anúncio Click-to-WhatsApp ou Elementor com Ad ID).",
            ];
        }

        // Força busca direta na Meta (bypass cache)
        $ad_meta = self::get_ad_details($ad_id, true);

        if (empty($ad_meta) || (empty($ad_meta['campaign_name']) && empty($ad_meta['adset_name']))) {
            return [
                'success' => false,
                'message' => "Não foi possível puxar os dados do Ad ID {$ad_id} na Meta. Verifique se o token possui a permissão 'ads_read' e se a Conta de Anúncios está vinculada.",
            ];
        }

        $campaign_id   = !empty($ad_meta['campaign_id']) ? $ad_meta['campaign_id'] : $lead->campaign_id;
        $campaign_name = !empty($ad_meta['campaign_name']) ? $ad_meta['campaign_name'] : $lead->campaign_name;
        $adset_id      = !empty($ad_meta['adset_id']) ? $ad_meta['adset_id'] : $lead->adset_id;
        $adset_name    = !empty($ad_meta['adset_name']) ? $ad_meta['adset_name'] : $lead->adset_name;
        $ad_name       = !empty($ad_meta['ad_name']) ? $ad_meta['ad_name'] : $lead->ad_name;

        $update_data = [
            'campaign_id'   => sanitize_text_field($campaign_id),
            'campaign_name' => sanitize_text_field($campaign_name),
            'adset_id'      => sanitize_text_field($adset_id),
            'adset_name'    => sanitize_text_field($adset_name),
            'ad_name'       => sanitize_text_field($ad_name),
            'utm_campaign'  => sanitize_text_field($campaign_name),
            'utm_content'   => sanitize_text_field($adset_name),
            'utm_term'      => sanitize_text_field($ad_name),
            'updated_at'    => current_time('mysql'),
        ];

        $wpdb->update($leads_table, $update_data, ['id' => $lead_id]);

        // Registra no histórico
        $wpdb->insert($history_table, [
            'lead_id'        => $lead_id,
            'campo'          => 'meta_campaign_sync',
            'valor_anterior' => "Campanha: {$lead->campaign_name} | AdSet: {$lead->adset_name} | Ad: {$lead->ad_name}",
            'valor_novo'     => "Campanha: {$campaign_name} | AdSet: {$adset_name} | Ad: {$ad_name}",
            'origem'         => 'meta_api_sync',
            'usuario_id'     => get_current_user_id() ?: 0,
            'created_at'     => current_time('mysql'),
        ]);

        $updated_lead = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$leads_table} WHERE id = %d", $lead_id));

        return [
            'success' => true,
            'message' => "Lead #{$lead_id} sincronizado com sucesso! Campanha: '{$campaign_name}'.",
            'lead'    => $updated_lead,
            'meta'    => $ad_meta,
        ];
    }

    /**
     * Sincroniza todos os leads pendentes que possuem ad_id
     *
     * @return array
     */
    public static function sync_all_pending_leads() {
        global $wpdb;
        $leads_table = DbSchema::get_leads_table();

        // Busca leads que têm ad_id mas faltam dados de campanha, conjunto ou anúncio (ou estão com 'Converse Conosco')
        $leads = $wpdb->get_results(
            "SELECT id, ad_id, campaign_name, adset_name, ad_name FROM {$leads_table} 
             WHERE ad_id IS NOT NULL AND ad_id != '' 
             AND (campaign_name IS NULL OR campaign_name = '' OR campaign_name = 'Converse Conosco' OR adset_name IS NULL OR adset_name = '' OR ad_name IS NULL OR ad_name = '') 
             ORDER BY id DESC LIMIT 50"
        );

        if (empty($leads)) {
            return [
                'total'      => 0,
                'updated'    => 0,
                'failed'     => 0,
                'message'    => 'Nenhum lead com Ad ID pendente de sincronização encontrado.',
            ];
        }

        $updated = 0;
        $failed  = 0;

        foreach ($leads as $l) {
            $res = self::sync_lead_ad_data($l->id);
            if ($res['success']) {
                $updated++;
            } else {
                $failed++;
            }
        }

        return [
            'total'   => count($leads),
            'updated' => $updated,
            'failed'  => $failed,
            'message' => "Sincronização concluída: {$updated} atualizados, {$failed} falhas de " . count($leads) . " leads processados.",
        ];
    }
}
