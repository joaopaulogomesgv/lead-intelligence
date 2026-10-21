<?php
/**
 * Plugin Name: Lead Intelligence
 * Plugin URI: https://github.com/lead-intelligence
 * Description: Central própria de inteligência e qualificação de leads Meta Ads, Elementor Pro e WhatsApp Cloud API.
 * Version: 2.5.8
 * Author: Lead Intelligence Team
 * Author URI: https://github.com/lead-intelligence
 * Text Domain: lead-intelligence
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: GPL v2 or later
 */

if (!defined('ABSPATH')) {
    exit;
}

define('LEAD_INTELLIGENCE_VERSION', '2.5.8');
define('LEAD_INTELLIGENCE_PLUGIN_FILE', __FILE__);
define('LEAD_INTELLIGENCE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('LEAD_INTELLIGENCE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('LEAD_INTELLIGENCE_DB_VERSION', '1.1.1');

// Autoloader
require_once LEAD_INTELLIGENCE_PLUGIN_DIR . 'includes/class-autoloader.php';
\LeadIntelligence\Autoloader::register();

/**
 * Ativação do plugin
 */
function lead_intelligence_activate() {
    \LeadIntelligence\Activator::activate();
    \LeadIntelligence\GoogleAds\GoogleAdsSync::schedule_cron();
}
register_activation_hook(__FILE__, 'lead_intelligence_activate');

/**
 * Desativação do plugin
 */
function lead_intelligence_deactivate() {
    \LeadIntelligence\Deactivator::deactivate();
}
register_deactivation_hook(__FILE__, 'lead_intelligence_deactivate');

/**
 * Inicialização principal do plugin
 */
function lead_intelligence_init() {
    // Inicializa logger
    \LeadIntelligence\Logger::init();

    // Inicializa ou atualiza tabelas se necessário
    \LeadIntelligence\Database\DbSchema::maybe_update();

    // Inicializa listener do Elementor Pro (não-intrusivo)
    \LeadIntelligence\Elementor\ElementorListener::init();

    // Inicializa rastreador e preservador de UTMs e parâmetros Meta
    \LeadIntelligence\Tracking\UtmTracker::init();

    // Inicializa Webhook próprio da Meta WhatsApp Cloud API
    \LeadIntelligence\WhatsApp\MetaWebhook::init();

    // Inicializa Webhook Inbound & Relay (repassador para n8n)
    \LeadIntelligence\Webhooks\InboundRelay::init();

    // Inicializa fila assíncrona da Meta Conversions API
    \LeadIntelligence\MetaCapi\CapiQueue::init();

    // Inicializa sincronização e OAuth com Google Sheets API v4
    \LeadIntelligence\Qualification\GoogleSheetsSync::init();

    // Inicializa cliente e sincronização de custos da Google Ads API
    \LeadIntelligence\GoogleAds\GoogleAdsSync::init();

    // Inicializa shortcodes para Frontend e Page Builders (Elementor, etc.)
    \LeadIntelligence\Frontend\Shortcodes::init();

    // Painel administrativo
    if (is_admin()) {
        \LeadIntelligence\Admin\AdminMenu::init();
        \LeadIntelligence\Admin\Settings::init();
    }
}
add_action('plugins_loaded', 'lead_intelligence_init');

/**
 * Intercepta o erro crítico do WordPress para administradores,
 * exibindo o arquivo e a linha do erro em vez da mensagem genérica.
 */
add_filter('wp_php_error_message', function($message, $error) {
    if (is_array($error)) {
        return '<div style="background:#ffffff; border:2px solid #ef4444; border-left:6px solid #dc2626; padding:20px; font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif; color:#1f2937; margin:20px auto; max-width:800px; border-radius:8px; box-shadow:0 4px 6px rgba(0,0,0,0.08); text-align:left;">' .
               '<h2 style="color:#b91c1c; margin:0 0 12px; font-size:18px;">⚠️ Detalhes do Erro Fatal PHP (Lead Intelligence)</h2>' .
               '<p style="font-size:14px; margin:8px 0;"><strong>Mensagem:</strong> <code style="color:#b91c1c; background:#fee2e2; padding:3px 6px; border-radius:4px; font-size:13px;">' . esc_html($error['message'] ?? '') . '</code></p>' .
               '<p style="font-size:13px; margin:8px 0;"><strong>Arquivo:</strong> <code>' . esc_html($error['file'] ?? '') . '</code></p>' .
               '<p style="font-size:13px; margin:8px 0;"><strong>Linha:</strong> <code>' . esc_html($error['line'] ?? '') . '</code></p>' .
               '</div>';
    }
    return $message;
}, 10, 2);
