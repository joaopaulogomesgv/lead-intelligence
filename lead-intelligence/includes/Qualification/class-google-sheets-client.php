<?php
namespace LeadIntelligence\Qualification;

use LeadIntelligence\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cliente HTTP Nativo para Google Sheets API v4 e Google OAuth 2.0
 * Não requer SDK externo, garantindo máxima performance e compatibilidade.
 */
class GoogleSheetsClient {

    const OAUTH_AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
    const OAUTH_TOKEN_URL = 'https://oauth2.googleapis.com/token';
    const SHEETS_API_URL  = 'https://sheets.googleapis.com/v4/spreadsheets';
    const USERINFO_URL    = 'https://www.googleapis.com/oauth2/v2/userinfo';
    const OPTION_NAME     = 'lead_intelligence_google_auth';

    /**
     * Retorna a URI de redirecionamento oficial registrada no Google Cloud Console
     * Usa admin.php limpo sem query string para respeitar estritamente as regras de URI do Google.
     */
    public static function get_redirect_uri() {
        return admin_url('admin.php');
    }

    /**
     * Obtém as credenciais e tokens salvos
     */
    public static function get_auth_data() {
        $defaults = [
            'client_id'       => '',
            'client_secret'   => '',
            'access_token'    => '',
            'refresh_token'   => '',
            'expires_at'      => 0,
            'connected_email' => '',
            'connected_at'    => '',
        ];
        $saved = get_option(self::OPTION_NAME, []);
        return wp_parse_args($saved, $defaults);
    }

    /**
     * Salva ou atualiza os dados de autenticação
     */
    public static function save_auth_data($data) {
        $current = self::get_auth_data();
        $updated = array_merge($current, $data);
        update_option(self::OPTION_NAME, $updated);
    }

    /**
     * Verifica se o plugin está autenticado no Google
     */
    public static function is_connected() {
        $data = self::get_auth_data();
        return !empty($data['refresh_token']) && !empty($data['client_id']);
    }

    /**
     * Desconecta e limpa as credenciais do Google
     */
    public static function disconnect() {
        $data = self::get_auth_data();
        $data['access_token']    = '';
        $data['refresh_token']   = '';
        $data['expires_at']      = 0;
        $data['connected_email'] = '';
        $data['connected_at']    = '';
        update_option(self::OPTION_NAME, $data);
        Logger::info('GoogleSheets', 'Conta Google desconectada com sucesso.');
    }

    /**
     * Gera a URL para autorização OAuth 2.0 no Google
     */
    public static function get_auth_url($custom_client_id = '') {
        $data = self::get_auth_data();
        $client_id = !empty($custom_client_id) ? trim($custom_client_id) : trim($data['client_id'] ?? '');
        if (empty($client_id)) {
            return '';
        }

        $state_data = [
            'action' => 'li_google_auth',
            'nonce'  => wp_create_nonce('li_google_auth_nonce'),
        ];
        $state = base64_encode(wp_json_encode($state_data));

        $params = [
            'client_id'             => $client_id,
            'redirect_uri'          => self::get_redirect_uri(),
            'response_type'         => 'code',
            'scope'                 => 'https://www.googleapis.com/auth/spreadsheets.readonly https://www.googleapis.com/auth/userinfo.email',
            'access_type'           => 'offline',
            'prompt'                => 'consent',
            'state'                 => $state,
            'include_granted_scopes'=> 'true',
        ];

        return self::OAUTH_AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * Troca o código retornado pelo Google por access_token e refresh_token
     */
    public static function exchange_code($code) {
        $data = self::get_auth_data();
        if (empty($data['client_id']) || empty($data['client_secret'])) {
            return new \WP_Error('missing_credentials', 'Client ID ou Client Secret do Google não informados.');
        }

        $response = wp_remote_post(self::OAUTH_TOKEN_URL, [
            'timeout' => 20,
            'body'    => [
                'code'          => trim($code),
                'client_id'     => trim($data['client_id']),
                'client_secret' => trim($data['client_secret']),
                'redirect_uri'  => self::get_redirect_uri(),
                'grant_type'    => 'authorization_code',
            ],
        ]);

        if (is_wp_error($response)) {
            Logger::error('GoogleSheets', 'Falha na comunicação com o Google OAuth: ' . $response->get_error_message());
            return $response;
        }

        $code_status = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code_status !== 200 || empty($body['access_token'])) {
            $err_desc = $body['error_description'] ?? ($body['error'] ?? 'Erro desconhecido ao validar código OAuth');
            Logger::error('GoogleSheets', "Erro OAuth ({$code_status}): {$err_desc}");
            return new \WP_Error('oauth_error', "Erro retornado pelo Google: {$err_desc}");
        }

        $access_token  = $body['access_token'];
        $refresh_token = $body['refresh_token'] ?? $data['refresh_token'];
        $expires_in    = (int) ($body['expires_in'] ?? 3600);

        // Busca o e-mail do usuário conectado para exibição no painel
        $email = self::fetch_user_email($access_token);

        self::save_auth_data([
            'access_token'    => $access_token,
            'refresh_token'   => $refresh_token,
            'expires_at'      => time() + $expires_in - 60,
            'connected_email' => $email,
            'connected_at'    => current_time('mysql'),
        ]);

        Logger::info('GoogleSheets', "Conta Google conectada com sucesso ({$email}).");
        return true;
    }

    /**
     * Obtém um token de acesso válido, renovando automaticamente se expirado
     */
    public static function get_valid_access_token() {
        $data = self::get_auth_data();

        if (empty($data['refresh_token'])) {
            return new \WP_Error('not_connected', 'Conta Google não conectada.');
        }

        // Se ainda for válido por mais de 60 segundos, reutiliza
        if (!empty($data['access_token']) && $data['expires_at'] > (time() + 60)) {
            return $data['access_token'];
        }

        // Renova o token de acesso via refresh_token
        $response = wp_remote_post(self::OAUTH_TOKEN_URL, [
            'timeout' => 20,
            'body'    => [
                'client_id'     => trim($data['client_id']),
                'client_secret' => trim($data['client_secret']),
                'refresh_token' => trim($data['refresh_token']),
                'grant_type'    => 'refresh_token',
            ],
        ]);

        if (is_wp_error($response)) {
            Logger::error('GoogleSheets', 'Erro ao renovar token do Google: ' . $response->get_error_message());
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 || empty($body['access_token'])) {
            $err_desc = $body['error_description'] ?? 'Falha ao renovar credencial Google';
            Logger::error('GoogleSheets', "Falha ao renovar token Google ({$code}): {$err_desc}");
            return new \WP_Error('refresh_failed', "Não foi possível renovar o token: {$err_desc}");
        }

        $access_token = $body['access_token'];
        $expires_in   = (int) ($body['expires_in'] ?? 3600);

        self::save_auth_data([
            'access_token' => $access_token,
            'expires_at'   => time() + $expires_in - 60,
        ]);

        return $access_token;
    }

    /**
     * Busca o e-mail do usuário autenticado no Google
     */
    private static function fetch_user_email($access_token) {
        $response = wp_remote_get(self::USERINFO_URL, [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
            ],
        ]);

        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            return $body['email'] ?? '';
        }

        return '';
    }

    /**
     * Extrai o ID da planilha do Google a partir de uma URL completa ou do próprio ID
     */
    public static function extract_spreadsheet_id($input) {
        $input = trim((string) $input);
        if (empty($input)) {
            return '';
        }

        // Padrão de URL: /d/([a-zA-Z0-9-_]+)
        if (preg_match('#/d/([a-zA-Z0-9-_]+)#', $input, $matches)) {
            return $matches[1];
        }

        // Se já for o ID puro (geralmente ~44 caracteres)
        if (preg_match('#^[a-zA-Z0-9-_]{20,80}$#', $input)) {
            return $input;
        }

        return $input;
    }

    /**
     * Extrai o GID (ID da aba) se presente na URL
     */
    public static function extract_gid($url) {
        if (preg_match('#[#&?]gid=([0-9]+)#', (string) $url, $matches)) {
            return (int) $matches[1];
        }
        return null;
    }

    /**
     * Busca os metadados da planilha e a lista de abas (sheets)
     */
    public static function get_spreadsheet_tabs($spreadsheet_id) {
        $token = self::get_valid_access_token();
        if (is_wp_error($token)) {
            return $token;
        }

        $id = self::extract_spreadsheet_id($spreadsheet_id);
        if (empty($id)) {
            return new \WP_Error('invalid_id', 'ID da planilha do Google inválido.');
        }

        $url = self::SHEETS_API_URL . '/' . rawurlencode($id) . '?fields=sheets(properties(sheetId,title,index))';
        $response = wp_remote_get($url, [
            'timeout' => 25,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 403) {
            return new \WP_Error('permission_denied', 'Permissão negada pelo Google. Verifique se o e-mail conectado tem acesso de visualização à planilha.');
        }

        if ($code === 404) {
            return new \WP_Error('not_found', 'Planilha não encontrada. Verifique se o link ou ID está correto.');
        }

        if ($code !== 200 || !isset($body['sheets'])) {
            $msg = $body['error']['message'] ?? 'Erro desconhecido ao consultar a planilha no Google.';
            return new \WP_Error('api_error', "Google Sheets API ({$code}): {$msg}");
        }

        $tabs = [];
        foreach ($body['sheets'] as $sheet) {
            $props = $sheet['properties'] ?? [];
            if (!empty($props['title'])) {
                $tabs[] = [
                    'id'    => $props['sheetId'] ?? 0,
                    'title' => $props['title'],
                    'index' => $props['index'] ?? 0,
                ];
            }
        }

        return $tabs;
    }

    /**
     * Busca os valores das linhas da planilha
     *
     * @param string $spreadsheet_id ID ou URL da planilha
     * @param string $range Nome da aba ou intervalo (ex: 'Leads V2' ou 'Leads!A1:Z5000')
     * @param int $max_rows Limite máximo de linhas (0 = todas)
     * @return array|\WP_Error ['headers' => array, 'rows' => array, 'total_rows' => int]
     */
    public static function fetch_sheet_data($spreadsheet_id, $range = '', $max_rows = 5000) {
        $token = self::get_valid_access_token();
        if (is_wp_error($token)) {
            return $token;
        }

        $id = self::extract_spreadsheet_id($spreadsheet_id);
        if (empty($id)) {
            return new \WP_Error('invalid_id', 'ID da planilha do Google inválido.');
        }

        // Se o range for apenas o nome da aba ou vazio, formata para abranger todas as colunas
        $clean_range = trim((string) $range);
        if (empty($clean_range)) {
            $clean_range = 'A:ZZ';
        } elseif (strpos($clean_range, '!') === false) {
            $clean_range = "'" . str_replace("'", "''", $clean_range) . "'!A:ZZ";
        }

        $url = self::SHEETS_API_URL . '/' . rawurlencode($id) . '/values/' . rawurlencode($clean_range) . '?majorDimension=ROWS&valueRenderOption=FORMATTED_VALUE';

        $response = wp_remote_get($url, [
            'timeout' => 35,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 403) {
            return new \WP_Error('permission_denied', 'Permissão negada (403). Seu e-mail Google precisa ter pelo menos acesso de "Visualizador" na planilha.');
        }

        if ($code !== 200 || !isset($body['values'])) {
            $msg = $body['error']['message'] ?? 'Não foi possível ler as linhas da planilha.';
            return new \WP_Error('fetch_error', "Google Sheets API ({$code}): {$msg}");
        }

        $values = $body['values'];
        if (empty($values) || count($values) < 1) {
            return [
                'headers'    => [],
                'rows'       => [],
                'total_rows' => 0,
            ];
        }

        // Primeira linha são os cabeçalhos
        $raw_headers = array_shift($values);
        $headers = [];
        foreach ($raw_headers as $idx => $h) {
            $h_clean = trim((string) $h);
            $headers[$idx] = !empty($h_clean) ? $h_clean : "Coluna_" . ($idx + 1);
        }

        $rows = [];
        $header_count = count($headers);

        foreach ($values as $row_idx => $raw_row) {
            if ($max_rows > 0 && count($rows) >= $max_rows) {
                break;
            }

            // Ignora linhas totalmente vazias
            $has_data = false;
            foreach ($raw_row as $cell) {
                if (trim((string) $cell) !== '') {
                    $has_data = true;
                    break;
                }
            }
            if (!$has_data) {
                continue;
            }

            $assoc_row = [];
            foreach ($headers as $col_idx => $header_name) {
                $assoc_row[$header_name] = isset($raw_row[$col_idx]) ? trim((string) $raw_row[$col_idx]) : '';
            }

            $rows[] = $assoc_row;
        }

        return [
            'headers'    => array_values($headers),
            'rows'       => $rows,
            'total_rows' => count($rows),
        ];
    }

    /**
     * Busca dados com Otimização Inteligente de Janela (Tail Range via batchGet)
     * Para planilhas gigantes (ex: 30.000+ linhas), busca apenas a linha de cabeçalho + as últimas N linhas,
     * reduzindo o consumo de memória e tempo de CPU em mais de 95%.
     *
     * @param string $spreadsheet_id ID ou URL da planilha
     * @param string $tab_name Nome da aba
     * @param string $window 'tail_1000'|'tail_2000'|'tail_3000'|'tail_5000'|'all'
     * @return array|\WP_Error
     */
    public static function fetch_sheet_data_smart($spreadsheet_id, $tab_name = '', $window = 'tail_2000') {
        $token = self::get_valid_access_token();
        if (is_wp_error($token)) {
            return $token;
        }

        $id = self::extract_spreadsheet_id($spreadsheet_id);
        if (empty($id)) {
            return new \WP_Error('invalid_id', 'ID da planilha do Google inválido.');
        }

        $clean_tab = trim((string) $tab_name);

        // Se for carga completa ('all'), busca tudo diretamente
        if ($window === 'all') {
            return self::fetch_sheet_data($spreadsheet_id, $clean_tab, 0);
        }

        $limit_rows = 2000;
        if (preg_match('/tail_(\d+)/', $window, $m)) {
            $limit_rows = (int) $m[1];
        }

        // 1. Consulta metadados para descobrir o total de linhas (gridProperties.rowCount)
        $meta_url = self::SHEETS_API_URL . '/' . rawurlencode($id) . '?fields=sheets(properties(sheetId,title,gridProperties.rowCount))';
        $meta_res = wp_remote_get($meta_url, [
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ],
        ]);

        $total_grid_rows = 0;
        $matched_title = '';

        if (!is_wp_error($meta_res) && wp_remote_retrieve_response_code($meta_res) === 200) {
            $meta_body = json_decode(wp_remote_retrieve_body($meta_res), true);
            if (!empty($meta_body['sheets'])) {
                foreach ($meta_body['sheets'] as $sheet_meta) {
                    $props = $sheet_meta['properties'] ?? [];
                    $t = $props['title'] ?? '';
                    if (empty($clean_tab) || strcasecmp($clean_tab, $t) === 0) {
                        $matched_title = $t;
                        $total_grid_rows = (int) ($props['gridProperties']['rowCount'] ?? 0);
                        break;
                    }
                }
            }
        }

        if (empty($matched_title)) {
            $matched_title = !empty($clean_tab) ? $clean_tab : 'Sheet1';
        }

        // Se a planilha for pequena (menos que o limite), busca padrão
        if ($total_grid_rows <= ($limit_rows + 50)) {
            return self::fetch_sheet_data($spreadsheet_id, $matched_title, $limit_rows);
        }

        // 2. OTIMIZAÇÃO POR BATCHGET:
        // Range 1 = Cabeçalho (Linha 1): 'Aba'!A1:ZZ1
        // Range 2 = Últimas N linhas: 'Aba'!A{start}:ZZ{end}
        $start_row = max(2, $total_grid_rows - $limit_rows);
        $escaped_title = "'" . str_replace("'", "''", $matched_title) . "'";
        $range_header  = "{$escaped_title}!A1:ZZ1";
        $range_data    = "{$escaped_title}!A{$start_row}:ZZ{$total_grid_rows}";

        $batch_url = self::SHEETS_API_URL . '/' . rawurlencode($id) . '/values:batchGet?' .
                     'ranges=' . rawurlencode($range_header) . '&' .
                     'ranges=' . rawurlencode($range_data) . '&' .
                     'majorDimension=ROWS&valueRenderOption=FORMATTED_VALUE';

        $batch_res = wp_remote_get($batch_url, [
            'timeout' => 35,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ],
        ]);

        if (is_wp_error($batch_res)) {
            return $batch_res;
        }

        $code = wp_remote_retrieve_response_code($batch_res);
        $body = json_decode(wp_remote_retrieve_body($batch_res), true);

        if ($code !== 200 || empty($body['valueRanges']) || count($body['valueRanges']) < 2) {
            // Fallback: se falhar o batchGet, usa fetch_sheet_data normal com limite
            return self::fetch_sheet_data($spreadsheet_id, $matched_title, $limit_rows);
        }

        $header_values = $body['valueRanges'][0]['values'][0] ?? [];
        $data_values   = $body['valueRanges'][1]['values'] ?? [];

        if (empty($header_values)) {
            return [
                'headers'    => [],
                'rows'       => [],
                'total_rows' => 0,
            ];
        }

        $headers = [];
        foreach ($header_values as $idx => $h) {
            $h_clean = trim((string) $h);
            $headers[$idx] = !empty($h_clean) ? $h_clean : "Coluna_" . ($idx + 1);
        }

        $rows = [];
        foreach ($data_values as $raw_row) {
            $has_data = false;
            foreach ($raw_row as $cell) {
                if (trim((string) $cell) !== '') {
                    $has_data = true;
                    break;
                }
            }
            if (!$has_data) {
                continue;
            }

            $assoc_row = [];
            foreach ($headers as $col_idx => $header_name) {
                $assoc_row[$header_name] = isset($raw_row[$col_idx]) ? trim((string) $raw_row[$col_idx]) : '';
            }
            $rows[] = $assoc_row;
        }

        return [
            'headers'    => array_values($headers),
            'rows'       => $rows,
            'total_rows' => count($rows),
            'optimized'  => true,
            'window'     => $window,
            'start_row'  => $start_row,
        ];
    }
}
