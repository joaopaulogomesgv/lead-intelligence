<?php
namespace LeadIntelligence\WhatsApp;

use LeadIntelligence\Database\DbSchema;
use LeadIntelligence\Database\LeadRepository;
use LeadIntelligence\PhoneNormalizer;
use LeadIntelligence\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Processador de Mensagens e Eventos do WhatsApp Cloud API
 * Vincula conversas diretamente ao Lead pelo telefone normalizado.
 */
class MessageHandler {

    /**
     * Processa payload completo recebido da Meta
     */
    public static function process_payload($payload) {
        if (empty($payload['entry']) || !is_array($payload['entry'])) {
            return;
        }

        foreach ($payload['entry'] as $entry) {
            if (empty($entry['changes']) || !is_array($entry['changes'])) {
                continue;
            }

            foreach ($entry['changes'] as $change) {
                $value = $change['value'] ?? [];
                if (empty($value)) {
                    continue;
                }

                // Extrai mapa de contatos (nome do perfil do WhatsApp)
                $contacts_map = [];
                if (!empty($value['contacts']) && is_array($value['contacts'])) {
                    foreach ($value['contacts'] as $c) {
                        $wa_id = $c['wa_id'] ?? '';
                        $name  = $c['profile']['name'] ?? '';
                        if (!empty($wa_id) && !empty($name)) {
                            $contacts_map[$wa_id] = $name;
                        }
                    }
                }

                // 1. Processa mensagens recebidas (Inbound)
                if (!empty($value['messages']) && is_array($value['messages'])) {
                    foreach ($value['messages'] as $msg) {
                        self::handle_single_message($msg, $contacts_map, $value);
                    }
                }

                // 2. Processa atualizações de status de envio (Sent, Delivered, Read)
                if (!empty($value['statuses']) && is_array($value['statuses'])) {
                    foreach ($value['statuses'] as $status_event) {
                        self::handle_status_update($status_event, $value);
                    }
                }
            }
        }
    }

    /**
     * Processa mensagem individual recebida
     */
    private static function handle_single_message($msg, $contacts_map, $change_value) {
        global $wpdb;
        $leads_table = DbSchema::get_leads_table();
        $whatsapp_table = DbSchema::get_whatsapp_table();
        $history_table = DbSchema::get_history_table();

        $from_raw = $msg['from'] ?? '';
        $msg_id   = $msg['id'] ?? '';
        $type     = $msg['type'] ?? 'text';
        $timestamp = isset($msg['timestamp']) ? (int) $msg['timestamp'] : time();
        $msg_date  = gmdate('Y-m-d H:i:s', $timestamp);

        $phone_norm = PhoneNormalizer::normalize($from_raw);
        if (empty($phone_norm)) {
            return;
        }

        // Extrai texto da mensagem conforme o tipo
        $body = '';
        if ($type === 'text' && !empty($msg['text']['body'])) {
            $body = sanitize_textarea_field($msg['text']['body']);
        } elseif ($type === 'button' && !empty($msg['button']['text'])) {
            $body = '[Botão] ' . sanitize_text_field($msg['button']['text']);
        } elseif ($type === 'interactive') {
            $inter = $msg['interactive'];
            $reply_title = $inter['button_reply']['title'] ?? ($inter['list_reply']['title'] ?? '');
            $body = '[Resposta] ' . sanitize_text_field($reply_title);
        } else {
            $body = "[Mídia: {$type}]";
        }

        $contact_name = $contacts_map[$from_raw] ?? '';

        // Procura lead existente no banco próprio pelo telefone normalizado
        $lead = LeadRepository::find_by_phone($phone_norm);
        $lead_id = 0;

        if ($lead) {
            $lead_id = (int) $lead->id;

            $update_lead = [
                'whatsapp_status'     => 'conversando',
                'ultima_mensagem'     => $msg_date,
                'mensagens_recebidas' => ((int) $lead->mensagens_recebidas) + 1,
                'updated_at'          => current_time('mysql'),
            ];

            // Se o lead ainda não tinha primeira mensagem registrada
            if (empty($lead->primeira_mensagem) || $lead->primeira_mensagem === '0000-00-00 00:00:00') {
                $update_lead['primeira_mensagem'] = $msg_date;
            }

            // Se não tinha nome e o perfil do WhatsApp forneceu
            if (empty($lead->nome) && !empty($contact_name)) {
                $update_lead['nome'] = sanitize_text_field($contact_name);
            }

            $wpdb->update($leads_table, $update_lead, ['id' => $lead_id]);

            // Auditoria
            $wpdb->insert($history_table, [
                'lead_id'        => $lead_id,
                'campo'          => 'whatsapp_mensagem',
                'valor_anterior' => $lead->whatsapp_status,
                'valor_novo'     => 'Mensagem recebida: ' . wp_trim_words($body, 10),
                'origem'         => 'whatsapp',
                'usuario_id'     => 0,
                'created_at'     => current_time('mysql'),
            ]);

            Logger::info('WhatsApp', "Mensagem vinculada ao Lead existente #{$lead_id} ({$phone_norm}).", [
                'lead_id'  => $lead_id,
                'telefone' => $phone_norm,
                'preview'  => wp_trim_words($body, 8),
            ]);
        } else {
            // Lead novo que iniciou contato direto pelo WhatsApp
            $insert_lead = [
                'uuid'                 => wp_generate_uuid4(),
                'nome'                 => sanitize_text_field($contact_name),
                'telefone'             => sanitize_text_field($from_raw),
                'telefone_normalizado' => $phone_norm,
                'formulario_nome'      => 'WhatsApp Cloud Inbound',
                'qualificacao_status'  => 'pendente',
                'whatsapp_status'      => 'conversando',
                'primeira_mensagem'    => $msg_date,
                'ultima_mensagem'      => $msg_date,
                'mensagens_recebidas'  => 1,
                'data_cadastro'        => $msg_date,
                'created_at'           => current_time('mysql'),
                'updated_at'           => current_time('mysql'),
            ];

            $wpdb->insert($leads_table, $insert_lead);
            $lead_id = $wpdb->insert_id;

            $wpdb->insert($history_table, [
                'lead_id'        => $lead_id,
                'campo'          => 'criacao',
                'valor_anterior' => '',
                'valor_novo'     => 'Lead criado a partir de mensagem no WhatsApp Cloud API',
                'origem'         => 'whatsapp',
                'usuario_id'     => 0,
                'created_at'     => current_time('mysql'),
            ]);

            Logger::info('WhatsApp', "Novo lead #{$lead_id} criado via WhatsApp Cloud ({$phone_norm}).");
        }

        // Salva a mensagem no histórico de mensagens do WhatsApp
        $wpdb->insert($whatsapp_table, [
            'lead_id'              => $lead_id,
            'telefone_normalizado' => $phone_norm,
            'message_id'           => sanitize_text_field($msg_id),
            'direcao'              => 'inbound',
            'tipo_mensagem'        => sanitize_text_field($type),
            'conteudo'             => $body,
            'status_entrega'       => 'received',
            'payload_bruto'        => wp_json_encode($msg, JSON_UNESCAPED_UNICODE),
            'created_at'           => $msg_date,
        ]);
    }

    /**
     * Processa atualizações de status de mensagens enviadas (sent, delivered, read)
     */
    private static function handle_status_update($status_event, $change_value) {
        global $wpdb;
        $whatsapp_table = DbSchema::get_whatsapp_table();
        $leads_table    = DbSchema::get_leads_table();

        $msg_id       = $status_event['id'] ?? '';
        $new_status   = $status_event['status'] ?? ''; // sent, delivered, read, failed
        $recipient    = $status_event['recipient_id'] ?? '';
        $conv_id      = $status_event['conversation']['id'] ?? '';

        $phone_norm = PhoneNormalizer::normalize($recipient);

        // Atualiza status na tabela de mensagens se já existir
        if (!empty($msg_id)) {
            $wpdb->update(
                $whatsapp_table,
                ['status_entrega' => sanitize_text_field($new_status)],
                ['message_id' => $msg_id]
            );
        }

        // Se tiver conversation_id e lead correspondente, atualiza conversation_id no lead
        if (!empty($phone_norm)) {
            $lead = LeadRepository::find_by_phone($phone_norm);
            if ($lead) {
                $lead_updates = [];
                if (!empty($conv_id) && empty($lead->conversation_id)) {
                    $lead_updates['conversation_id'] = sanitize_text_field($conv_id);
                }
                if ($new_status === 'read') {
                    $lead_updates['whatsapp_status'] = 'lida';
                }
                if (!empty($lead_updates)) {
                    $lead_updates['updated_at'] = current_time('mysql');
                    $wpdb->update($leads_table, $lead_updates, ['id' => $lead->id]);
                }
            }
        }
    }
}
