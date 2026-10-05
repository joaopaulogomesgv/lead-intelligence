<?php
namespace LeadIntelligence\MetaCapi;

use LeadIntelligence\Database\DbSchema;
use LeadIntelligence\Database\LeadRepository;
use LeadIntelligence\Admin\Settings;
use LeadIntelligence\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fila e Despachante Assíncrono de Eventos da Conversions API
 */
class CapiQueue {

    public static function init() {
        add_action('lead_intelligence_async_capi_lead', [__CLASS__, 'process_lead_event'], 10, 1);
        add_action('lead_intelligence_async_capi_qualified', [__CLASS__, 'process_qualified_event'], 10, 1);
    }

    /**
     * Agenda envio do evento 'Lead'
     */
    public static function enqueue_lead($lead_id) {
        $settings = Settings::get_settings();
        if (empty($settings['enable_capi_lead']) || empty($settings['capi_pixel_id']) || empty($settings['capi_access_token'])) {
            return;
        }

        // Se suportar eventos em segundo plano, agenda via WP-Cron ou executa
        if (!wp_next_scheduled('lead_intelligence_async_capi_lead', [$lead_id])) {
            wp_schedule_single_event(time(), 'lead_intelligence_async_capi_lead', [$lead_id]);
        }
    }

    /**
     * Agenda envio do evento 'LeadQualified'
     */
    public static function enqueue_qualified($lead_id) {
        $settings = Settings::get_settings();
        if (empty($settings['enable_capi_qualified']) || empty($settings['capi_pixel_id']) || empty($settings['capi_access_token'])) {
            return;
        }

        if (!wp_next_scheduled('lead_intelligence_async_capi_qualified', [$lead_id])) {
            wp_schedule_single_event(time(), 'lead_intelligence_async_capi_qualified', [$lead_id]);
        }
    }

    /**
     * Processa envio do evento Lead
     */
    public static function process_lead_event($lead_id) {
        $lead = LeadRepository::find_by_id($lead_id);
        if (!$lead || !empty($lead->capi_lead_sent)) {
            return; // Já enviado ou lead inexistente
        }

        $result = CapiService::send_lead_event($lead);

        if ($result['success']) {
            global $wpdb;
            $table = DbSchema::get_leads_table();
            $wpdb->update(
                $table,
                [
                    'capi_lead_sent' => 1,
                    'capi_lead_time' => current_time('mysql'),
                    'capi_event_id'  => $result['event_id'] ?? '',
                ],
                ['id' => $lead_id]
            );
        }
    }

    /**
     * Processa envio do evento LeadQualified
     */
    public static function process_qualified_event($lead_id) {
        $lead = LeadRepository::find_by_id($lead_id);
        if (!$lead || !empty($lead->capi_qualified_sent)) {
            return;
        }

        $result = CapiService::send_lead_qualified_event($lead);

        if ($result['success']) {
            global $wpdb;
            $table = DbSchema::get_leads_table();
            $wpdb->update(
                $table,
                [
                    'capi_qualified_sent' => 1,
                    'capi_qualified_time' => current_time('mysql'),
                    'capi_event_id'       => $result['event_id'] ?? '',
                ],
                ['id' => $lead_id]
            );
        }
    }
}
