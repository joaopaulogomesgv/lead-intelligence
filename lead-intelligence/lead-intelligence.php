<?php
/**
 * Plugin Name: Lead Intelligence
 * Plugin URI: https://github.com/lead-intelligence
 * Description: Central própria de inteligência e qualificação de leads Meta Ads, Elementor Pro e WhatsApp Cloud API.
 * Version: 1.5.1
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

define('LEAD_INTELLIGENCE_VERSION', '1.5.1');
define('LEAD_INTELLIGENCE_PLUGIN_FILE', __FILE__);
define('LEAD_INTELLIGENCE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('LEAD_INTELLIGENCE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('LEAD_INTELLIGENCE_DB_VERSION', '1.0.3');

// Autoloader
require_once LEAD_INTELLIGENCE_PLUGIN_DIR . 'includes/class-autoloader.php';
\LeadIntelligence\Autoloader::register();

/**
 * Ativação do plugin
 */
function lead_intelligence_activate() {
    \LeadIntelligence\Activator::activate();
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

    // Inicializa fila assíncrona da Meta Conversions API
    \LeadIntelligence\MetaCapi\CapiQueue::init();

    // Inicializa sincronização e OAuth com Google Sheets API v4
    \LeadIntelligence\Qualification\GoogleSheetsSync::init();

    // Inicializa shortcodes para Frontend e Page Builders (Elementor, etc.)
    \LeadIntelligence\Frontend\Shortcodes::init();

    // Painel administrativo
    if (is_admin()) {
        \LeadIntelligence\Admin\AdminMenu::init();
        \LeadIntelligence\Admin\Settings::init();
    }
}
add_action('plugins_loaded', 'lead_intelligence_init');
