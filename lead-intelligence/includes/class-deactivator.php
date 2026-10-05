<?php
namespace LeadIntelligence;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Executado na desativação do plugin
 */
class Deactivator {
    public static function deactivate() {
        // Limpeza de agendamentos WP-Cron futuros se houver
        wp_clear_scheduled_hook('lead_intelligence_hourly_cron');
        \LeadIntelligence\Logger::info('Sistema', 'Plugin Lead Intelligence desativado.');
    }
}
