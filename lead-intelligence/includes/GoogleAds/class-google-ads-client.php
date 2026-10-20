<?php
namespace LeadIntelligence\GoogleAds;

use LeadIntelligence\Admin\Settings;
use LeadIntelligence\Database\LeadRepository;
use LeadIntelligence\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cliente Oficial da Google Ads API (REST v18)
 * Consulta e sincronização de métricas de investimento e custos por campanha
 */
class GoogleAdsClient {

    const API_VERSION = 'v18';
    const OAUTH_TOKEN_URL = 'https://oauth2.googleapis.com/token';
    const OAUTH_AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
    const SCOPE           = 'https://www.googleapis.com/auth/adwords';

    /**
     * Gera URL de autorização OAuth2 para obter o Refresh Token
     */
    public static function get_auth_url() {
        $settings = Settings::get_settings();
        $client_id = trim($settings['google_ads_client_id'] ?? '');

        if (empty($client_id)) {
            return '';
        }

        $redirect_uri = admin_url('admin.php?page=lead-intelligence-settings&tab=google_ads_api&google_ads_auth=1');

        $params = [
            'client_id'       => $client_id,
            'redirect_uri'    => $redirect_uri,
            'response_type'   => 'code',
            'scope'           => self::SCOPE,
            'access_type'     => 'offline',
            'prompt'          => 'consent',
            'state'           => wp_create_nonce('li_google_ads_oauth_state'),
        ];

        return self::OAUTH_AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * Troca o authorization code pelo Refresh Token
     */
    public static function exchange_code_for_token($code) {
        $settings = Settings::get_settings();
        $client_id     = trim($settings['google_ads_client_id'] ?? '');
        $client_secret = trim($settings['google_ads_client_secret'] ?? '');
        $redirect_uri  = admin_url('admin.php?page=lead-intelligence-settings&tab=google_ads_api&google_ads_auth=1');

        if (empty($client_id) || empty($client_secret)) {
            return ['success' => false, 'message' => 'Client ID ou Client Secret não configurados.'];
        }

        $response = wp_remote_post(self::OAUTH_TOKEN_URL, [
            'method'  => 'POST',
            'timeout' => 20,
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body'    => [
                'code'          => $code,
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'redirect_uri'  => $redirect_uri,
                'grant_type'    => 'authorization_code',
            ],
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (empty($data['refresh_token'])) {
            $err = $data['error_description'] ?? $data['error'] ?? 'Falha ao obter refresh token do Google Ads.';
            return ['success' => false, 'message' => $err];
        }

        $settings['google_ads_refresh_token'] = $data['refresh_token'];
        $settings['google_ads_connected']     = 1;
        update_option('lead_intelligence_settings', $settings);

        Logger::info('GoogleAdsAPI', 'Conta Google Ads autorizada com sucesso via OAuth2.');
        return ['success' => true, 'message' => 'Conta Google Ads autorizada com sucesso!'];
    }

    /**
     * Obtém um access_token válido renovando via refresh token
     */
    public static function get_access_token() {
        $settings = Settings::get_settings();
        $client_id     = trim($settings['google_ads_client_id'] ?? '');
        $client_secret = trim($settings['google_ads_client_secret'] ?? '');
        $refresh_token = trim($settings['google_ads_refresh_token'] ?? '');

        if (empty($client_id) || empty($client_secret) || empty($refresh_token)) {
            return false;
        }

        $cached_token = get_transient('li_gads_access_token');
        if (!empty($cached_token)) {
            return $cached_token;
        }

        $response = wp_remote_post(self::OAUTH_TOKEN_URL, [
            'method'  => 'POST',
            'timeout' => 15,
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body'    => [
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'refresh_token' => $refresh_token,
                'grant_type'    => 'refresh_token',
            ],
        ]);

        if (is_wp_error($response)) {
            Logger::error('GoogleAdsAPI', 'Erro de rede ao renovar access token: ' . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!empty($data['access_token'])) {
            $expires = max(60, (int) ($data['expires_in'] ?? 3600) - 300);
            set_transient('li_gads_access_token', $data['access_token'], $expires);
            return $data['access_token'];
        }

        $err = $data['error_description'] ?? $data['error'] ?? 'Erro desconhecido ao renovar token.';
        Logger::error('GoogleAdsAPI', 'Falha ao renovar access token: ' . $err);
        return false;
    }

    /**
     * Formata e limpa o Customer ID (remove hífens e espaços)
     */
    public static function clean_customer_id($customer_id) {
        return preg_replace('/[^0-9]/', '', (string) $customer_id);
    }

    /**
     * Executa teste de conexão e credenciais com a Google Ads API
     */
    public static function test_connection() {
        $settings = Settings::get_settings();
        $dev_token   = trim($settings['google_ads_developer_token'] ?? '');
        $customer_id = self::clean_customer_id($settings['google_ads_customer_id'] ?? '');

        if (empty($dev_token)) {
            return ['success' => false, 'message' => 'Developer Token não informado nas configurações.'];
        }

        if (empty($customer_id) || strlen($customer_id) < 8) {
            return ['success' => false, 'message' => 'Customer ID inválido ou não informado (precisa ter 10 dígitos, ex: 123-456-7890).'];
        }

        $token = self::get_access_token();
        if (!$token) {
            return ['success' => false, 'message' => 'Não foi possível autenticar. Verifique o Client ID, Client Secret e Refresh Token.'];
        }

        $url = "https://googleads.googleapis.com/" . self::API_VERSION . "/customers/{$customer_id}/googleAds:searchStream";

        $headers = [
            'developer-token' => $dev_token,
            'Authorization'   => 'Bearer ' . $token,
            'Content-Type'    => 'application/json',
        ];

        $login_mcc = self::clean_customer_id($settings['google_ads_login_customer_id'] ?? '');
        if (!empty($login_mcc)) {
            $headers['login-customer-id'] = $login_mcc;
        }

        $query = "SELECT customer.id, customer.descriptive_name, customer.currency_code, customer.time_zone FROM customer LIMIT 1";

        $response = wp_remote_post($url, [
            'method'  => 'POST',
            'timeout' => 20,
            'headers' => $headers,
            'body'    => wp_json_encode(['query' => $query]),
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'message' => 'Erro de conexão: ' . $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($code !== 200) {
            $err_msg = 'Erro HTTP ' . $code;
            if (isset($data['error']['message'])) {
                $err_msg = $data['error']['message'];
            } elseif (isset($data[0]['error']['message'])) {
                $err_msg = $data[0]['error']['message'];
            }
            return ['success' => false, 'message' => $err_msg];
        }

        $account_name = 'Conta ' . $customer_id;
        $currency     = 'BRL';
        if (!empty($data[0]['results'][0]['customer']['descriptiveName'])) {
            $account_name = $data[0]['results'][0]['customer']['descriptiveName'];
        }
        if (!empty($data[0]['results'][0]['customer']['currencyCode'])) {
            $currency = $data[0]['results'][0]['customer']['currencyCode'];
        }

        return [
            'success'      => true,
            'account_name' => $account_name,
            'currency'     => $currency,
            'message'      => "Conexão estabelecida com sucesso! Conta: {$account_name} ({$customer_id}) | Moeda: {$currency}",
        ];
    }

    /**
     * Sincroniza gastos e métricas do Google Ads para um período específico
     *
     * @param string $date_start Formato Y-m-d
     * @param string $date_end   Formato Y-m-d
     * @return array
     */
    public static function sync_campaign_costs($date_start = '', $date_end = '') {
        $settings = Settings::get_settings();
        $dev_token   = trim($settings['google_ads_developer_token'] ?? '');
        $customer_id = self::clean_customer_id($settings['google_ads_customer_id'] ?? '');

        if (empty($dev_token) || empty($customer_id)) {
            return ['success' => false, 'message' => 'Google Ads API não configurada (Developer Token ou Customer ID ausente).'];
        }

        $token = self::get_access_token();
        if (!$token) {
            return ['success' => false, 'message' => 'Falha na autenticação OAuth2 com o Google Ads.'];
        }

        // Padrão: últimos 30 dias se não especificado
        if (empty($date_start)) {
            $date_start = wp_date('Y-m-d', strtotime('-30 days'));
        }
        if (empty($date_end)) {
            $date_end = wp_date('Y-m-d');
        }

        $url = "https://googleads.googleapis.com/" . self::API_VERSION . "/customers/{$customer_id}/googleAds:searchStream";

        $headers = [
            'developer-token' => $dev_token,
            'Authorization'   => 'Bearer ' . $token,
            'Content-Type'    => 'application/json',
        ];

        $login_mcc = self::clean_customer_id($settings['google_ads_login_customer_id'] ?? '');
        if (!empty($login_mcc)) {
            $headers['login-customer-id'] = $login_mcc;
        }

        // Query GAQL oficial
        $query = "SELECT 
                    segments.date, 
                    campaign.id, 
                    campaign.name, 
                    metrics.cost_micros, 
                    metrics.clicks, 
                    metrics.impressions, 
                    metrics.conversions 
                  FROM campaign 
                  WHERE segments.date >= '{$date_start}' 
                    AND segments.date <= '{$date_end}' 
                    AND campaign.status != 'REMOVED'";

        $response = wp_remote_post($url, [
            'method'  => 'POST',
            'timeout' => 45,
            'headers' => $headers,
            'body'    => wp_json_encode(['query' => $query]),
        ]);

        if (is_wp_error($response)) {
            Logger::error('GoogleAdsAPI', 'Erro ao sincronizar gastos: ' . $response->get_error_message());
            return ['success' => false, 'message' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($code !== 200) {
            $err_msg = 'Erro HTTP ' . $code;
            if (isset($data['error']['message'])) {
                $err_msg = $data['error']['message'];
            } elseif (isset($data[0]['error']['message'])) {
                $err_msg = $data[0]['error']['message'];
            }
            Logger::error('GoogleAdsAPI', "Falha HTTP {$code} na consulta de custos: {$err_msg}");
            return ['success' => false, 'message' => $err_msg];
        }

        $records_saved = 0;
        $total_cost    = 0.00;

        if (!empty($data) && is_array($data)) {
            foreach ($data as $batch) {
                if (empty($batch['results']) || !is_array($batch['results'])) {
                    continue;
                }

                foreach ($batch['results'] as $row) {
                    $c_date = $row['segments']['date'] ?? '';
                    $c_id   = (string) ($row['campaign']['id'] ?? '');
                    $c_name = $row['campaign']['name'] ?? '';
                    
                    // cost_micros / 1.000.000 = Reais (R$)
                    $cost_micros = (float) ($row['metrics']['costMicros'] ?? 0);
                    $c_cost      = round($cost_micros / 1000000, 2);
                    $c_clicks    = (int) ($row['metrics']['clicks'] ?? 0);
                    $c_impr      = (int) ($row['metrics']['impressions'] ?? 0);
                    $c_conv      = (float) ($row['metrics']['conversions'] ?? 0);

                    if (empty($c_id) || empty($c_date)) {
                        continue;
                    }

                    LeadRepository::record_campaign_cost([
                        'canal'         => 'google_ads',
                        'campaign_id'   => $c_id,
                        'campaign_name' => $c_name,
                        'date'          => $c_date,
                        'cost'          => $c_cost,
                        'clicks'        => $c_clicks,
                        'impressions'   => $c_impr,
                        'conversions'   => $c_conv,
                    ]);

                    $records_saved++;
                    $total_cost += $c_cost;
                }
            }
        }

        // Atualiza carimbo de data/hora do último sync
        update_option('lead_intelligence_last_gads_sync', current_time('mysql'));
        update_option('lead_intelligence_last_gads_sync_records', $records_saved);

        Logger::info('GoogleAdsAPI', "Sincronização concluída: {$records_saved} registros diários gravados. Investimento total processado: R$ " . number_format($total_cost, 2, ',', '.'));

        return [
            'success'       => true,
            'records'       => $records_saved,
            'total_cost'    => $total_cost,
            'date_start'    => $date_start,
            'date_end'      => $date_end,
            'message'       => "Sincronização realizada com sucesso! {$records_saved} registros diários atualizados (Total: R$ " . number_format($total_cost, 2, ',', '.') . ").",
        ];
    }
}
