<?php
namespace LeadIntelligence\OpenAiCapi;

use LeadIntelligence\Database\DbSchema;
use LeadIntelligence\Database\LeadRepository;
use LeadIntelligence\Admin\Settings;
use LeadIntelligence\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fila e Despachante Assíncrono de Eventos para a OpenAI Conversions API
 */
class OpenAiCapiQueue {

    public static function init() {
        add_action('lead_intelligence_async_openai_capi_lead', [__CLASS__, 'process_lead_event'], 10, 1);
        add_action('lead_intelligence_async_openai_capi_qualified', [__CLASS__, 'process_qualified_event'], 10, 1);
        add_action('lead_intelligence_async_openai_capi_purchase', [__CLASS__, 'process_purchase_event'], 10, 3);
    }

    /**
     * Agenda envio do evento 'lead_created'
     */
    public static function enqueue_lead($lead_id) {
        $settings = Settings::get_settings();
        if (empty($settings['openai_capi_enabled']) || empty($settings['enable_openai_capi_lead']) || empty($settings['openai_pixel_id']) || empty($settings['openai_api_key'])) {
            return;
        }

        if (!wp_next_scheduled('lead_intelligence_async_openai_capi_lead', [$lead_id])) {
            wp_schedule_single_event(time(), 'lead_intelligence_async_openai_capi_lead', [$lead_id]);
        }
    }

    /**
     * Agenda envio do evento 'lead_qualified'
     */
    public static function enqueue_qualified($lead_id) {
        $settings = Settings::get_settings();
        if (empty($settings['openai_capi_enabled']) || empty($settings['enable_openai_capi_qualified']) || empty($settings['openai_pixel_id']) || empty($settings['openai_api_key'])) {
            return;
        }

        if (!wp_next_scheduled('lead_intelligence_async_openai_capi_qualified', [$lead_id])) {
            wp_schedule_single_event(time(), 'lead_intelligence_async_openai_capi_qualified', [$lead_id]);
        }
    }

    /**
     * Agenda envio do evento 'order_created' (Venda / Matrícula)
     */
    public static function enqueue_purchase($lead_id, $value = 100.00, $currency = 'BRL') {
        $settings = Settings::get_settings();
        if (empty($settings['openai_capi_enabled']) || empty($settings['enable_openai_capi_purchase']) || empty($settings['openai_pixel_id']) || empty($settings['openai_api_key'])) {
            return;
        }

        if (!wp_next_scheduled('lead_intelligence_async_openai_capi_purchase', [$lead_id, $value, $currency])) {
            wp_schedule_single_event(time(), 'lead_intelligence_async_openai_capi_purchase', [$lead_id, $value, $currency]);
        }
    }

    /**
     * Processa envio assíncrono do evento 'lead_created'
     */
    public static function process_lead_event($lead_id) {
        $lead = LeadRepository::find_by_id($lead_id);
        if (!$lead || !empty($lead->openai_capi_lead_sent)) {
            return;
        }

        $result = OpenAiCapiService::send_lead_event($lead);

        if ($result['success']) {
            global $wpdb;
            $table = DbSchema::get_leads_table();
            $wpdb->update(
                $table,
                [
                    'openai_capi_lead_sent' => 1,
                    'openai_capi_lead_time' => current_time('mysql'),
                    'openai_capi_event_id'  => $result['event_id'] ?? '',
                ],
                ['id' => $lead_id]
            );
        }
    }

    /**
     * Processa envio assíncrono do evento 'lead_qualified'
     */
    public static function process_qualified_event($lead_id) {
        $lead = LeadRepository::find_by_id($lead_id);
        if (!$lead || !empty($lead->openai_capi_qualified_sent)) {
            return;
        }

        $result = OpenAiCapiService::send_lead_qualified_event($lead);

        if ($result['success']) {
            global $wpdb;
            $table = DbSchema::get_leads_table();
            $wpdb->update(
                $table,
                [
                    'openai_capi_qualified_sent' => 1,
                    'openai_capi_qualified_time' => current_time('mysql'),
                    'openai_capi_event_id'       => $result['event_id'] ?? '',
                ],
                ['id' => $lead_id]
            );
        }
    }

    /**
     * Processa envio assíncrono do evento 'order_created'
     */
    public static function process_purchase_event($lead_id, $value = 100.00, $currency = 'BRL') {
        $lead = LeadRepository::find_by_id($lead_id);
        if (!$lead || !empty($lead->openai_capi_purchase_sent)) {
            return;
        }

        $result = OpenAiCapiService::send_purchase_event($lead, $value, $currency);

        if ($result['success']) {
            global $wpdb;
            $table = DbSchema::get_leads_table();
            $wpdb->update(
                $table,
                [
                    'openai_capi_purchase_sent' => 1,
                    'openai_capi_purchase_time' => current_time('mysql'),
                    'openai_capi_event_id'       => $result['event_id'] ?? '',
                ],
                ['id' => $lead_id]
            );
        }
    }
}
