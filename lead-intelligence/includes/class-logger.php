<?php
namespace LeadIntelligence;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sistema Central de Logs do Lead Intelligence
 */
class Logger {

    public static function init() {
        // Nada a inicializar preventivamente
    }

    public static function log($categoria, $nivel, $mensagem, $contexto = null) {
        // Verifica se debug está habilitado caso seja nível debug
        if ($nivel === 'debug') {
            $settings = get_option('lead_intelligence_settings', []);
            if (empty($settings['enable_debug_logging'])) {
                return;
            }
        }

        global $wpdb;
        $logs_table = \LeadIntelligence\Database\DbSchema::get_logs_table();

        // Se a tabela ainda não existir (ex: antes da ativação), ignora silenciosamente
        if ($wpdb->get_var("SHOW TABLES LIKE '{$logs_table}'") !== $logs_table) {
            return;
        }

        $contexto_json = null;
        if (!empty($contexto)) {
            $contexto_json = wp_json_encode($contexto, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $wpdb->insert(
            $logs_table,
            [
                'categoria'  => sanitize_text_field($categoria),
                'nivel'      => sanitize_text_field($nivel),
                'mensagem'   => sanitize_textarea_field($mensagem),
                'contexto'   => $contexto_json,
                'created_at' => current_time('mysql'),
            ],
            ['%s', '%s', '%s', '%s', '%s']
        );
    }

    public static function info($categoria, $mensagem, $contexto = null) {
        self::log($categoria, 'info', $mensagem, $contexto);
    }

    public static function error($categoria, $mensagem, $contexto = null) {
        self::log($categoria, 'error', $mensagem, $contexto);
    }

    public static function warning($categoria, $mensagem, $contexto = null) {
        self::log($categoria, 'warning', $mensagem, $contexto);
    }

    public static function debug($categoria, $mensagem, $contexto = null) {
        self::log($categoria, 'debug', $mensagem, $contexto);
    }

    /**
     * Limpa logs antigos para manter o banco leve
     */
    public static function clean_old_logs($dias = 30) {
        global $wpdb;
        $logs_table = \LeadIntelligence\Database\DbSchema::get_logs_table();
        $date = wp_date('Y-m-d H:i:s', strtotime("-{$dias} days"));
        $wpdb->query($wpdb->prepare("DELETE FROM {$logs_table} WHERE created_at < %s", $date));
    }
}
