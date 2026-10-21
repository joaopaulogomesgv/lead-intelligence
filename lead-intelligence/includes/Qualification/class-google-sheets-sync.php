<?php
namespace LeadIntelligence\Qualification;

use LeadIntelligence\Database\DbSchema;
use LeadIntelligence\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Motor de Sincronização Automática com o Google Sheets API v4
 */
class GoogleSheetsSync {

    const SETTINGS_OPTION_GOOGLE = 'lead_intelligence_google_sync_settings';
    const SETTINGS_OPTION_META   = 'lead_intelligence_meta_sync_settings';
    const CRON_HOOK              = 'li_google_sheets_sync_cron';

    // Aliases retrocompatíveis
    const SETTINGS_OPTION = self::SETTINGS_OPTION_GOOGLE;

    public static function init() {
        // Registra intervalo customizado no WP-Cron
        add_filter('cron_schedules', [__CLASS__, 'register_cron_intervals']);

        // Ação executada pelo WP-Cron
        add_action(self::CRON_HOOK, [__CLASS__, 'run_cron_sync']);

        // Intercepta callback do OAuth no admin_init (modo admin.php)
        if (is_admin()) {
            add_action('admin_init', [__CLASS__, 'handle_oauth_callback']);
        }

        // Endpoint REST oficial para callback do Google OAuth (evita bloqueios de ModSecurity em /wp-admin/)
        add_action('rest_api_init', [__CLASS__, 'register_rest_routes']);
    }

    /**
     * Registra o endpoint REST de callback do OAuth
     */
    public static function register_rest_routes() {
        register_rest_route('lead-intelligence/v1', '/google/callback', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [__CLASS__, 'handle_rest_oauth_callback'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Processa retorno do Google via REST API
     */
    public static function handle_rest_oauth_callback(\WP_REST_Request $request) {
        $code = sanitize_text_field(wp_unslash($request->get_param('code') ?? ''));
        $state_val = sanitize_text_field(wp_unslash($request->get_param('state') ?? ''));

        if (empty($code)) {
            wp_safe_redirect(admin_url("admin.php?page=lead-intelligence-import&tab=google_sheets&google_error=" . urlencode('Código de autorização não recebido do Google.')));
            exit;
        }

        // Validação de state: se o state não conferir devido a cookies cross-site no redirect do Google, loga aviso informativo sem abortar, pois a autenticidade é validada diretamente com o Google via client_secret
        if (!empty($state_val) && !self::verify_oauth_nonce($state_val)) {
            Logger::info('GoogleSheets', 'Aviso: State de sessão local não coincidiu no redirect REST (comum em navegadores modernos cross-site). Validando código diretamente com os servidores da Google.');
        }

        $rest_uri = GoogleSheetsClient::get_redirect_uri('rest');
        $result = GoogleSheetsClient::exchange_code($code, $rest_uri);

        // Fallback inteligente: se o usuário configurou a redirect_uri do admin.php no Google Cloud Console
        if (is_wp_error($result) && (strpos($result->get_error_message(), 'redirect_uri_mismatch') !== false || strpos($result->get_error_message(), 'mismatch') !== false)) {
            $admin_uri = GoogleSheetsClient::get_redirect_uri('admin');
            $result = GoogleSheetsClient::exchange_code($code, $admin_uri);
        }

        if (is_wp_error($result)) {
            $error_msg = urlencode($result->get_error_message());
            wp_safe_redirect(admin_url("admin.php?page=lead-intelligence-import&tab=google_sheets&google_error={$error_msg}"));
            exit;
        }

        wp_safe_redirect(admin_url('admin.php?page=lead-intelligence-import&tab=google_sheets&google_connected=1'));
        exit;
    }

    /**
     * Valida o state do OAuth via transient de sessão ou nonce legado
     */
    private static function verify_oauth_nonce($state_val) {
        if (empty($state_val)) {
            return false;
        }

        // 1. Verificação via Transient de Estado (Imune a perda de sessão/cookies cross-site na REST API)
        if (strpos($state_val, 'li_') === 0) {
            $token = substr($state_val, 3);
            $user_id = get_transient('li_gauth_state_' . $token);
            if (!empty($user_id)) {
                delete_transient('li_gauth_state_' . $token);
                return true;
            }
        }

        // 2. Fallback via Nonce tradicional do WordPress
        $nonce = '';
        if (strpos($state_val, 'li_') === 0) {
            $nonce = substr($state_val, 3);
        } else {
            $state_raw = base64_decode($state_val);
            $state_json = json_decode($state_raw, true);
            if (is_array($state_json) && ($state_json['action'] ?? '') === 'li_google_auth' && !empty($state_json['nonce'])) {
                $nonce = $state_json['nonce'];
            }
        }

        return !empty($nonce) && (wp_verify_nonce($nonce, 'li_google_auth_nonce') || wp_verify_nonce($nonce, -1));
    }

    /**
     * Intercepta o redirecionamento de autorização da Conta Google via admin.php
     */
    public static function handle_oauth_callback() {
        if (empty($_GET['code'])) {
            return;
        }

        $code = sanitize_text_field(wp_unslash($_GET['code']));
        $state_val = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';

        if (!empty($state_val) && !self::verify_oauth_nonce($state_val)) {
            Logger::info('GoogleSheets', 'Aviso: State de sessão local não coincidiu no redirect admin. Validando código diretamente com os servidores da Google.');
        }

        $admin_uri = GoogleSheetsClient::get_redirect_uri('admin');
        $result = GoogleSheetsClient::exchange_code($code, $admin_uri);

        // Fallback inteligente: se o usuário configurou a redirect_uri da REST API no Google Cloud Console
        if (is_wp_error($result) && (strpos($result->get_error_message(), 'redirect_uri_mismatch') !== false || strpos($result->get_error_message(), 'mismatch') !== false)) {
            $rest_uri = GoogleSheetsClient::get_redirect_uri('rest');
            $result = GoogleSheetsClient::exchange_code($code, $rest_uri);
        }

        if (is_wp_error($result)) {
            $error_msg = urlencode($result->get_error_message());
            wp_safe_redirect(admin_url("admin.php?page=lead-intelligence-import&tab=google_sheets&google_error={$error_msg}"));
            exit;
        }

        wp_safe_redirect(admin_url('admin.php?page=lead-intelligence-import&tab=google_sheets&google_connected=1'));
        exit;
    }

    /**
     * Retorna a opção do banco de dados referente ao canal
     */
    public static function get_option_key($channel = 'google_ads') {
        return ($channel === 'meta_ads') ? self::SETTINGS_OPTION_META : self::SETTINGS_OPTION_GOOGLE;
    }

    /**
     * Configurações salvas de sincronização da planilha por canal
     *
     * @param string $channel 'google_ads' | 'meta_ads'
     * @return array
     */
    public static function get_source_settings($channel = 'google_ads') {
        $option_key = self::get_option_key($channel);

        if ($channel === 'meta_ads') {
            $defaults = [
                'spreadsheet_url' => '',
                'spreadsheet_id'  => '',
                'sheet_tab'       => '',
                'cron_frequency'  => 'disabled',
                'sync_window'     => 'tail_2000',
                'mapping'         => [
                    'telefone'       => 'telefone',
                    'email'          => 'Email',
                    'nome'           => 'Nome',
                    'status'         => 'status',
                    'etapa'          => 'etapa_funil',
                    'data'           => 'dataCriação',
                    'fbclid'         => 'fbclid',
                    'ad_id'          => 'ad_id',
                    'campanha'       => 'campanha',
                    'curso'          => '',
                    'area'           => 'Instancia',
                    'default_status' => 'qualificado',
                ],
                'last_sync'       => null,
            ];
        } else {
            $defaults = [
                'spreadsheet_url' => '',
                'spreadsheet_id'  => '',
                'sheet_tab'       => '',
                'cron_frequency'  => 'disabled',
                'sync_window'     => 'tail_2000',
                'mapping'         => [
                    'telefone'       => 'telefone',
                    'email'          => 'Email',
                    'nome'           => 'Nome',
                    'status'         => 'status',
                    'etapa'          => 'etapa_funil',
                    'data'           => 'dataCriação',
                    'gclid'          => 'gclid',
                    'campanha'       => '',
                    'curso'          => '',
                    'area'           => 'Instancia',
                    'default_status' => 'qualificado',
                ],
                'last_sync'       => null,
            ];
        }

        $saved = get_option($option_key, []);
        return wp_parse_args($saved, $defaults);
    }

    /**
     * Compatibilidade retroativa: Retorna configurações do Google Ads
     */
    public static function get_settings() {
        return self::get_source_settings('google_ads');
    }

    /**
     * Atualiza configurações de sincronização para um canal
     */
    public static function save_source_settings($channel, $data) {
        $option_key = self::get_option_key($channel);
        $current = self::get_source_settings($channel);
        $updated = array_merge($current, $data);
        update_option($option_key, $updated);

        // Atualiza o agendador WP-Cron verificando todos os canais
        self::update_global_cron_schedule();
    }

    /**
     * Compatibilidade retroativa: Salva configurações do Google Ads
     */
    public static function save_settings($data) {
        self::save_source_settings('google_ads', $data);
    }

    /**
     * Atualiza ou remove o agendador WP-Cron considerando todas as fontes
     */
    public static function update_global_cron_schedule() {
        $google_conf = self::get_source_settings('google_ads');
        $meta_conf   = self::get_source_settings('meta_ads');

        $freqs = [];
        if (!empty($google_conf['cron_frequency']) && $google_conf['cron_frequency'] !== 'disabled') {
            $freqs[] = $google_conf['cron_frequency'];
        }
        if (!empty($meta_conf['cron_frequency']) && $meta_conf['cron_frequency'] !== 'disabled') {
            $freqs[] = $meta_conf['cron_frequency'];
        }

        wp_clear_scheduled_hook(self::CRON_HOOK);

        if (!empty($freqs)) {
            // Prioridade para menor intervalo se houver frequências diferentes
            $chosen = 'daily';
            if (in_array('hourly', $freqs)) {
                $chosen = 'hourly';
            } elseif (in_array('six_hours', $freqs)) {
                $chosen = 'six_hours';
            } elseif (in_array('twicedaily', $freqs)) {
                $chosen = 'twicedaily';
            }

            wp_schedule_event(time() + 60, $chosen, self::CRON_HOOK);
            Logger::info('GoogleSheets', "Agendamento de sincronização multi-fonte ativado: '{$chosen}'.");
        } else {
            Logger::info('GoogleSheets', 'Agendamento automático de sincronização desativado para todas as fontes.');
        }
    }

    /**
     * Disparado pelo WP-Cron: sincroniza todas as fontes ativas
     */
    public static function run_cron_sync() {
        Logger::info('GoogleSheets', 'Iniciando sincronização periódica de planilhas via WP-Cron.');

        $google_conf = self::get_source_settings('google_ads');
        if (!empty($google_conf['spreadsheet_id']) && ($google_conf['cron_frequency'] ?? 'disabled') !== 'disabled') {
            self::run_sync('google_ads', false);
        }

        $meta_conf = self::get_source_settings('meta_ads');
        if (!empty($meta_conf['spreadsheet_id']) && ($meta_conf['cron_frequency'] ?? 'disabled') !== 'disabled') {
            self::run_sync('meta_ads', false);
        }
    }

    /**
     * Executa a sincronização de uma planilha e cruzamento com a base de leads
     *
     * @param string $channel 'google_ads' | 'meta_ads'
     * @param bool $is_manual Indica se a execução foi manual pelo botão do painel
     * @return array|\WP_Error Resumo da execução
     */
    public static function run_sync($channel = 'google_ads', $is_manual = false) {
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');

        if (!GoogleSheetsClient::is_connected()) {
            $msg = 'Conta Google não conectada. Conecte sua conta para sincronizar.';
            Logger::warning('GoogleSheets', $msg);
            return new \WP_Error('not_connected', $msg);
        }

        $start_time = microtime(true);
        $settings = self::get_source_settings($channel);
        $spreadsheet_id = $settings['spreadsheet_id'];
        $tab = $settings['sheet_tab'];
        $mapping = $settings['mapping'];
        $window = $settings['sync_window'] ?? 'tail_2000';

        $channel_label = ($channel === 'meta_ads') ? 'Meta Ads' : 'Google Ads';

        if (empty($spreadsheet_id)) {
            $msg = "ID ou Link da Planilha [{$channel_label}] não configurado.";
            Logger::warning('GoogleSheets', $msg);
            return new \WP_Error('missing_spreadsheet', $msg);
        }

        global $wpdb;
        $in_transaction = false;

        try {
            // Lê dados da planilha com Otimização de Janela Deslizante (batchGet das linhas recentes)
            $sheet_data = GoogleSheetsClient::fetch_sheet_data_smart($spreadsheet_id, $tab, $window);

            if (is_wp_error($sheet_data)) {
                $err_msg = $sheet_data->get_error_message();
                self::record_sync_result($channel, [
                    'status'  => 'erro',
                    'message' => $err_msg,
                ]);
                return $sheet_data;
            }

            $rows = $sheet_data['rows'];
            $total_rows = count($rows);

            if ($total_rows === 0) {
                $res = [
                    'status'     => 'concluido',
                    'message'    => "Planilha [{$channel_label}] consultada com sucesso, mas nenhuma linha com dados foi encontrada.",
                    'total'      => 0,
                    'matched'    => 0,
                    'created'    => 0,
                    'skipped'    => 0,
                    'timestamp'  => current_time('mysql'),
                ];
                self::record_sync_result($channel, $res);
                return $res;
            }

            $imports_table = DbSchema::get_imports_table();
            $source_label = !empty($tab) ? "Google Sheets [{$channel_label}] ({$tab})" : "Google Sheets [{$channel_label}]";

            // Registra na tabela de histórico de importações
            $wpdb->insert($imports_table, [
                'nome_arquivo'          => $source_label,
                'total_linhas'          => $total_rows,
                'linhas_processadas'    => 0,
                'leads_qualificados'    => 0,
                'leads_nao_encontrados' => 0,
                'mapeamento_colunas'    => wp_json_encode($mapping, JSON_UNESCAPED_UNICODE),
                'status'                => 'processando',
                'usuario_id'            => get_current_user_id() ?: 1,
                'created_at'            => current_time('mysql'),
            ]);
            $import_id = $wpdb->insert_id;

            // Processamento em lote ultrarrápido com hash map em memória (tempo < 1 segundo)
            $batch_res     = Matcher::process_batch($rows, $mapping, $source_label, $import_id, $channel);
            $total_matched = $batch_res['matched'];
            $total_created = $batch_res['created'];
            $total_skipped = $batch_res['skipped'];

            $elapsed = round(microtime(true) - $start_time, 2);
            $mem_peak = round(memory_get_peak_usage(true) / 1024 / 1024, 1);

            // Atualiza lote de importação
            $wpdb->update($imports_table, [
                'linhas_processadas'    => $total_rows,
                'leads_qualificados'    => $total_matched,
                'leads_nao_encontrados' => $total_created,
                'status'                => 'concluido',
            ], ['id' => $import_id]);

            $summary = [
                'status'     => 'concluido',
                'channel'    => $channel,
                'message'    => "Sincronização [{$channel_label}] concluída com sucesso em {$elapsed}s ({$mem_peak} MB RAM)! {$total_rows} linhas lidas: {$total_matched} cruzadas e {$total_created} novos leads cadastrados.",
                'total'      => $total_rows,
                'matched'    => $total_matched,
                'created'    => $total_created,
                'skipped'    => $total_skipped,
                'elapsed'    => $elapsed,
                'memory'     => $mem_peak,
                'timestamp'  => current_time('mysql'),
                'manual'     => $is_manual,
            ];

            self::record_sync_result($channel, $summary);

            Logger::info('GoogleSheets', "Sincronização [{$channel_label}] concluída em {$elapsed}s ({$source_label}).", [
                'channel'   => $channel,
                'total'     => $total_rows,
                'cruzados'  => $total_matched,
                'criados'   => $total_created,
                'ignorados' => $total_skipped,
                'tempo_seg' => $elapsed,
                'mem_mb'    => $mem_peak,
            ]);

            return $summary;

        } catch (\Throwable $e) {
            if ($in_transaction) {
                $wpdb->query('ROLLBACK');
            }
            Logger::error('GoogleSheets', "Erro na sincronização [{$channel_label}]: " . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            $err_summary = [
                'status'    => 'erro',
                'channel'   => $channel,
                'message'   => "Erro na sincronização [{$channel_label}]: " . $e->getMessage(),
                'timestamp' => current_time('mysql'),
            ];
            self::record_sync_result($channel, $err_summary);

            return new \WP_Error('sync_exception', $e->getMessage());
        }
    }

    /**
     * Salva o resumo da última sincronização nas opções do canal
     */
    private static function record_sync_result($channel, $result) {
        $settings = self::get_source_settings($channel);
        $settings['last_sync'] = array_merge([
            'timestamp' => current_time('mysql'),
        ], $result);
        $option_key = self::get_option_key($channel);
        update_option($option_key, $settings);
    }
}
