<?php
namespace LeadIntelligence\GoogleAds;

use LeadIntelligence\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Agendador e Cron de Sincronização Diária da Google Ads API
 */
class GoogleAdsSync {

    const CRON_HOOK = 'lead_intelligence_cron_google_ads_sync';

    public static function init() {
        add_action(self::CRON_HOOK, [__CLASS__, 'run_scheduled_sync']);
        add_action('admin_init', [__CLASS__, 'handle_oauth_callback']);
    }

    /**
     * Trata o retorno do fluxo OAuth na tela de configurações
     */
    public static function handle_oauth_callback() {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        if (isset($_GET['google_ads_auth']) && isset($_GET['code'])) {
            $code = sanitize_text_field(wp_unslash($_GET['code']));
            $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';

            if (!wp_verify_nonce($state, 'li_google_ads_oauth_state')) {
                wp_safe_redirect(admin_url('admin.php?page=lead-intelligence-settings&tab=google_ads_api&auth_error=' . urlencode('Falha de verificação de segurança (nonce expirado).')));
                exit;
            }

            $res = GoogleAdsClient::exchange_code_for_token($code);
            if ($res['success']) {
                // Tenta puxar automaticamente os gastos dos últimos 30 dias após conectar
                GoogleAdsClient::sync_campaign_costs();
                wp_safe_redirect(admin_url('admin.php?page=lead-intelligence-settings&tab=google_ads_api&auth_success=1'));
            } else {
                wp_safe_redirect(admin_url('admin.php?page=lead-intelligence-settings&tab=google_ads_api&auth_error=' . urlencode($res['message'])));
            }
            exit;
        }
    }

    /**
     * Executa a rotina agendada via WP-Cron
     */
    public static function run_scheduled_sync() {
        Logger::info('GoogleAdsSync', 'Iniciando sincronização diária agendada dos gastos do Google Ads...');
        $res = GoogleAdsClient::sync_campaign_costs();
        if ($res['success']) {
            Logger::info('GoogleAdsSync', 'Sincronização diária concluída: ' . $res['message']);
        } else {
            Logger::warning('GoogleAdsSync', 'Falha na sincronização diária: ' . $res['message']);
        }
    }

    /**
     * Agenda o cron diário se ainda não estiver agendado
     */
    public static function schedule_cron() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            // Agenda para rodar todo dia às 04:00 da madrugada
            $time = strtotime('tomorrow 04:00:00');
            wp_schedule_event($time, 'daily', self::CRON_HOOK);
        }
    }

    /**
     * Limpa o cron ao desativar o plugin
     */
    public static function clear_cron() {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }
}
