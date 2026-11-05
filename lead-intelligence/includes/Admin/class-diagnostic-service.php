<?php
namespace LeadIntelligence\Admin;

use LeadIntelligence\Database\DbSchema;
use LeadIntelligence\Logger;
use LeadIntelligence\WhatsApp\PoloRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Serviço Central de Diagnóstico, Telemetria e Caixa-Preta (Para Suporte e IA)
 */
class DiagnosticService {

    public static function init() {
        add_action('wp_ajax_li_get_diagnostic_report', [__CLASS__, 'ajax_get_report']);
        add_action('rest_api_init', [__CLASS__, 'register_rest_routes']);
    }

    public static function register_rest_routes() {
        register_rest_route('lead-intelligence/v1', '/diagnostic', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [__CLASS__, 'rest_get_report'],
            'permission_callback' => [__CLASS__, 'check_rest_permission'],
        ]);
    }

    public static function check_rest_permission(\WP_REST_Request $request) {
        if (current_user_can('manage_options')) {
            return true;
        }
        $token = $request->get_param('token');
        $settings = Settings::get_settings();
        if (!empty($token) && !empty($settings['meta_verify_token']) && hash_equals($settings['meta_verify_token'], $token)) {
            return true;
        }
        return false;
    }

    public static function rest_get_report(\WP_REST_Request $request) {
        $format = $request->get_param('format') ?: 'json';
        if ($format === 'text') {
            header('Content-Type: text/plain; charset=utf-8');
            echo self::get_formatted_text_report();
            exit;
        }
        return new \WP_REST_Response(self::get_full_report(), 200);
    }

    public static function ajax_get_report() {
        check_ajax_referer('li_diagnostic_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permissão negada.');
        }

        wp_send_json_success([
            'text' => self::get_formatted_text_report(),
            'data' => self::get_full_report(),
        ]);
    }

    /**
     * Retorna o relatório completo consolidado em array
     */
    public static function get_full_report() {
        global $wpdb;
        $settings = Settings::get_settings();
        $leads_table = DbSchema::get_leads_table();
        $logs_table = DbSchema::get_logs_table();
        $whatsapp_table = DbSchema::get_whatsapp_table();

        // 1. Polos / Números
        $raw_polos = PoloRepository::get_all();
        $token_app_info = 'Não verificado';
        $waba_subscribed_apps = [];

        if (!empty($raw_polos)) {
            $first_polo = $raw_polos[0];
            $t = PoloRepository::get_access_token_for_polo($first_polo);
            $w = $first_polo['waba_id'] ?? '';
            if (!empty($t)) {
                $a_resp = wp_remote_get('https://graph.facebook.com/v26.0/app', [
                    'headers' => ['Authorization' => 'Bearer ' . $t],
                    'timeout' => 8,
                ]);
                if (!is_wp_error($a_resp)) {
                    $a_body = json_decode(wp_remote_retrieve_body($a_resp), true);
                    if (!empty($a_body['id'])) {
                        $token_app_info = ($a_body['name'] ?? 'App') . ' (ID ' . $a_body['id'] . ')';
                    }
                }
                if (!empty($w)) {
                    $s_resp = wp_remote_get("https://graph.facebook.com/v26.0/{$w}/subscribed_apps", [
                        'headers' => ['Authorization' => 'Bearer ' . $t],
                        'timeout' => 8,
                    ]);
                    if (!is_wp_error($s_resp)) {
                        $s_body = json_decode(wp_remote_retrieve_body($s_resp), true);
                        if (!empty($s_body['data'])) {
                            foreach ($s_body['data'] as $s_item) {
                                $wa_d = $s_item['whatsapp_business_api_data'] ?? [];
                                $waba_subscribed_apps[] = ($wa_d['name'] ?? ($s_item['name'] ?? 'App')) . ' (ID ' . ($wa_d['id'] ?? ($s_item['id'] ?? '')) . ')';
                            }
                        }
                    }
                }
            }
        }
        $polos = [];
        foreach ($raw_polos as $p) {
            $polos[] = [
                'id'              => $p['id'] ?? '',
                'nome'            => $p['nome'] ?? '',
                'phone_number_id' => $p['phone_number_id'] ?? '',
                'display_phone'   => $p['display_phone'] ?? '',
                'waba_id'         => $p['waba_id'] ?? '',
                'status'          => $p['status'] ?? 'ativo',
                'is_default'      => !empty($p['is_default']),
                'has_token'       => !empty($p['access_token']),
            ];
        }

        // 2. Último Webhook Caixa-Preta
        $last_webhook = get_option('lead_intelligence_last_meta_webhook', null);

        // 3. Últimos Logs
        $recent_logs = [];
        if ($wpdb->get_var("SHOW TABLES LIKE '{$logs_table}'") === $logs_table) {
            $recent_logs = $wpdb->get_results(
                "SELECT id, categoria, nivel, mensagem, contexto, created_at FROM {$logs_table} ORDER BY id DESC LIMIT 15",
                ARRAY_A
            );
        }

        // 4. Últimos Leads
        $recent_leads = [];
        if ($wpdb->get_var("SHOW TABLES LIKE '{$leads_table}'") === $leads_table) {
            $recent_leads = $wpdb->get_results(
                "SELECT id, nome, telefone, telefone_normalizado, formulario_nome, utm_source, utm_campaign, ad_id, ad_name, polo, phone_number_id, whatsapp_status, qualificacao_status, created_at FROM {$leads_table} ORDER BY id DESC LIMIT 5",
                ARRAY_A
            );
        }

        // 5. Contagens
        $total_leads = $wpdb->get_var("SELECT COUNT(*) FROM {$leads_table}");
        $total_msgs  = $wpdb->get_var("SELECT COUNT(*) FROM {$whatsapp_table}");

        return [
            'generated_at' => current_time('mysql'),
            'system' => [
                'plugin_version' => defined('LEAD_INTELLIGENCE_VERSION') ? LEAD_INTELLIGENCE_VERSION : 'unknown',
                'db_version'     => get_option('lead_intelligence_db_version', 'none'),
                'php_version'    => PHP_VERSION,
                'wp_version'     => get_bloginfo('version'),
                'mysql_version'  => $wpdb->db_version(),
                'site_url'       => site_url(),
                'webhook_url'    => get_rest_url(null, 'lead-intelligence/v1/meta/webhook'),
                'memory_limit'   => ini_get('memory_limit'),
            ],
            'settings' => [
                'enable_whatsapp_capture'  => !empty($settings['enable_whatsapp_capture']),
                'enable_elementor_capture' => !empty($settings['enable_elementor_capture']),
                'enable_debug_logging'     => !empty($settings['enable_debug_logging']),
                'utm_preservation'         => !empty($settings['utm_preservation']),
                'verify_token_configured'  => !empty($settings['meta_verify_token']),
                'verify_token_preview'     => !empty($settings['meta_verify_token']) ? substr($settings['meta_verify_token'], 0, 4) . '***' : 'vazio',
            ],
            'whatsapp_polos'       => $polos,
            'token_app_info'       => $token_app_info,
            'waba_subscribed_apps' => $waba_subscribed_apps,
            'last_webhook_payload' => $last_webhook,
            'recent_logs'          => $recent_logs,
            'recent_leads'         => $recent_leads,
            'stats'                => [
                'total_leads'    => (int) $total_leads,
                'total_messages' => (int) $total_msgs,
            ],
        ];
    }

    /**
     * Retorna o relatório formatado em texto/markdown limpo para copiar e colar diretamente no chat
     */
    public static function get_formatted_text_report() {
        $rep = self::get_full_report();

        $out = [];
        $out[] = "=== LEAD INTELLIGENCE - RELATÓRIO TÉCNICO DE DIAGNÓSTICO ===";
        $out[] = "📅 Data/Hora: " . $rep['generated_at'];
        $out[] = "📦 Plugin: v" . $rep['system']['plugin_version'] . " | DB: v" . $rep['system']['db_version'] . " | PHP: " . $rep['system']['php_version'] . " | WP: " . $rep['system']['wp_version'];
        $out[] = "🌐 Webhook URL: " . $rep['system']['webhook_url'];
        $out[] = "🔑 Verify Token: " . $rep['settings']['verify_token_preview'];
        $out[] = "⚙️ Captura WhatsApp: " . ($rep['settings']['enable_whatsapp_capture'] ? '🟢 ATIVA' : '🔴 DESATIVADA') . " | Captura Elementor: " . ($rep['settings']['enable_elementor_capture'] ? '🟢 ATIVA' : '🔴 DESATIVADA');
        $out[] = "📊 Total de Leads: " . $rep['stats']['total_leads'] . " | Mensagens WhatsApp no BD: " . $rep['stats']['total_messages'];
        $out[] = "";

        $out[] = "--- AUDITORIA DE TOKENS & APPS NA META ---";
        $out[] = "🔑 Meta App que gerou este Token: " . ($rep['token_app_info'] ?? 'Não verificado');
        $out[] = "📡 Apps oficialmente inscritos na WABA: " . (!empty($rep['waba_subscribed_apps']) ? implode(', ', $rep['waba_subscribed_apps']) : 'NENHUM APP INSCRITO (ou erro de permissão)');
        $out[] = "";

        $out[] = "--- NÚMEROS DE WHATSAPP / POLOS CADASTRADOS ---";
        if (empty($rep['whatsapp_polos'])) {
            $out[] = "(Nenhum número cadastrado no momento)";
        } else {
            foreach ($rep['whatsapp_polos'] as $p) {
                $status_txt = $p['status'] === 'ativo' ? '🟢 ATIVO' : '⚪ INATIVO';
                $def_txt    = $p['is_default'] ? ' [PADRÃO]' : '';
                $tok_txt    = $p['has_token'] ? 'Configurado' : 'AUSENTE';
                $out[] = "* {$status_txt}{$def_txt} {$p['nome']} (Exibido: {$p['display_phone']})";
                $out[] = "  - Phone Number ID: " . ($p['phone_number_id'] ?: '(vazio)');
                $out[] = "  - WABA ID: " . ($p['waba_id'] ?: '(vazio)');
                $out[] = "  - Access Token: {$tok_txt}";
            }
        }
        $out[] = "";

        $out[] = "--- ÚLTIMO EVENTO RECEBIDO NO WEBHOOK (CAIXA-PRETA) ---";
        if (empty($rep['last_webhook_payload'])) {
            $out[] = "⚠️ NENHUM WEBHOOK RECEBIDO AINDA DA META DESDE A INSTALAÇÃO.";
            $out[] = "-> Se você confirmou lead na Meta Ads e esta seção está vazia, o portal developers.facebook.com NÃO chamou o seu servidor.";
            $out[] = "-> Verifique se a URL de Callback está com 200 OK e se o campo 'messages' está ASSINADO (Subscribed) no App da Meta.";
        } else {
            $lw = $rep['last_webhook_payload'];
            $out[] = "Data/Hora: " . ($lw['timestamp'] ?? '-');
            $out[] = "Método: " . ($lw['method'] ?? '-') . " | IP Origem: " . ($lw['ip'] ?? '-');
            $out[] = "Status/Resumo: " . ($lw['result_status'] ?? '-');
            if (!empty($lw['body_raw'])) {
                $out[] = "Payload Bruto Recebido (JSON):";
                $out[] = $lw['body_raw'];
            }
        }
        $out[] = "";

        $out[] = "--- ÚLTIMOS 10 LOGS REGISTRADOS NO SISTEMA ---";
        if (empty($rep['recent_logs'])) {
            $out[] = "(Nenhum log registrado)";
        } else {
            foreach (array_slice($rep['recent_logs'], 0, 10) as $l) {
                $lvl = strtoupper($l['nivel']);
                $out[] = "[{$l['created_at']}] [{$lvl}] [{$l['categoria']}] {$l['mensagem']}";
                if (!empty($l['contexto']) && $l['contexto'] !== 'null') {
                    $out[] = "   Contexto: " . wp_trim_words($l['contexto'], 15);
                }
            }
        }
        $out[] = "";

        $out[] = "--- ÚLTIMOS 5 LEADS CADASTRADOS ---";
        if (empty($rep['recent_leads'])) {
            $out[] = "(Nenhum lead cadastrado ainda)";
        } else {
            foreach ($rep['recent_leads'] as $ld) {
                $ad_txt = !empty($ld['ad_id']) ? " | Ad ID: {$ld['ad_id']}" : "";
                $polo_txt = !empty($ld['polo']) ? " | Polo: {$ld['polo']}" : "";
                $out[] = "#{$ld['id']} - {$ld['nome']} ({$ld['telefone_normalizado']}) | Origem: {$ld['formulario_nome']} | UTM: {$ld['utm_source']}{$ad_txt}{$polo_txt} [{$ld['created_at']}]";
            }
        }
        $out[] = "===============================================================";

        return implode("\n", $out);
    }
}

