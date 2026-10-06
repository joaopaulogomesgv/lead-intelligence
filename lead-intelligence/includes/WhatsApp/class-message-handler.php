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
        $timestamp = !empty($msg['timestamp']) ? (int) $msg['timestamp'] : null;
        $msg_date  = $timestamp ? wp_date('Y-m-d H:i:s', $timestamp) : current_time('mysql');

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

        // 1. Extração de Metadados de Anúncio Meta (Click-to-WhatsApp Referral)
        $referral    = $msg['referral'] ?? [];
        $ctwa_clid   = $referral['ctwa_clid'] ?? '';
        $ad_id       = $referral['source_id'] ?? '';
        $ad_headline = $referral['headline'] ?? '';
        $source_url  = $referral['source_url'] ?? '';

        $ad_utms = [];
        if (!empty($source_url)) {
            $parsed_query = parse_url($source_url, PHP_URL_QUERY);
            if (!empty($parsed_query)) {
                parse_str($parsed_query, $ad_utms);
            }
        }

        // Tenta enriquecer com a Meta Marketing Graph API se tiver ad_id
        $ad_meta = [];
        if (!empty($ad_id)) {
            $ad_meta = WabaClient::get_ad_details($ad_id);
        }

        $campaign_id   = !empty($ad_meta['campaign_id']) ? $ad_meta['campaign_id'] : ($ad_utms['campaign_id'] ?? '');
        $campaign_name = !empty($ad_meta['campaign_name']) ? $ad_meta['campaign_name'] : (!empty($ad_utms['utm_campaign']) ? $ad_utms['utm_campaign'] : (!empty($ad_utms['campaign_name']) ? $ad_utms['campaign_name'] : $ad_headline));
        $adset_id      = !empty($ad_meta['adset_id']) ? $ad_meta['adset_id'] : ($ad_utms['adset_id'] ?? '');
        $adset_name    = !empty($ad_meta['adset_name']) ? $ad_meta['adset_name'] : (!empty($ad_utms['adset_name']) ? $ad_utms['adset_name'] : (!empty($ad_utms['utm_content']) ? $ad_utms['utm_content'] : ''));
        $ad_name       = !empty($ad_meta['ad_name']) ? $ad_meta['ad_name'] : (!empty($ad_utms['ad_name']) ? $ad_utms['ad_name'] : (!empty($ad_utms['utm_term']) ? $ad_utms['utm_term'] : $ad_headline));

        $utm_source   = !empty($ad_utms['utm_source']) ? sanitize_text_field($ad_utms['utm_source']) : (!empty($referral) ? 'meta_ads' : '');
        $utm_medium   = !empty($ad_utms['utm_medium']) ? sanitize_text_field($ad_utms['utm_medium']) : (!empty($referral) ? 'whatsapp' : '');
        $utm_campaign = !empty($campaign_name) ? sanitize_text_field($campaign_name) : (!empty($ad_headline) ? sanitize_text_field($ad_headline) : '');
        $utm_content  = !empty($adset_name) ? sanitize_text_field($adset_name) : (!empty($ad_utms['utm_content']) ? sanitize_text_field($ad_utms['utm_content']) : '');
        $utm_term     = !empty($ad_name) ? sanitize_text_field($ad_name) : (!empty($ad_utms['utm_term']) ? sanitize_text_field($ad_utms['utm_term']) : '');

        // 2. Extração heurística inteligente de Curso / Área de Interesse no texto da mensagem
        $inferred_tipo_curso = '';
        $inferred_area       = '';

        if (preg_match('/(?:pós[\s\-]?graduação|pos[\s\-]?graduacao)/iu', $body)) {
            $inferred_tipo_curso = 'Pós-Graduação';
        } elseif (preg_match('/(?:segunda\s+graduação|segunda\s+graduacao)/iu', $body)) {
            $inferred_tipo_curso = 'Segunda Graduação';
        } elseif (preg_match('/(?:graduação|graduacao)/iu', $body)) {
            $inferred_tipo_curso = 'Graduação';
        }

        if (preg_match('/(?:em|sobre|do curso de|de)\s+([A-ZÁÉÍÓÚÂÊÎÔÛÃÕÇ\s]{3,50})(?:\s+e\s+(?:gostaria|quero)|\s*[\.\,\!]|$)/iu', $body, $m_curso)) {
            $c = trim($m_curso[1]);
            if (!empty($c) && !in_array(mb_strtolower($c, 'UTF-8'), ['um', 'uma', 'mais', 'informações', 'informacoes', 'bolsa', 'desconto', 'valores'])) {
                $inferred_area = sanitize_text_field($c);
            }
        }

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

            // Enriquecimento com dados do anúncio ou mensagem se o lead tiver campos vazios
            if (empty($lead->tipo_curso) && !empty($inferred_tipo_curso)) {
                $update_lead['tipo_curso'] = $inferred_tipo_curso;
            }
            if (empty($lead->area_interesse) && !empty($inferred_area)) {
                $update_lead['area_interesse'] = $inferred_area;
            }
            if (empty($lead->fbclid) && !empty($ctwa_clid)) {
                $update_lead['fbclid'] = sanitize_text_field($ctwa_clid);
            }
            if (empty($lead->ad_id) && !empty($ad_id)) {
                $update_lead['ad_id'] = sanitize_text_field($ad_id);
            }
            if (empty($lead->campaign_id) && !empty($campaign_id)) {
                $update_lead['campaign_id'] = sanitize_text_field($campaign_id);
            }
            if (!empty($campaign_name) && (empty($lead->campaign_name) || $lead->campaign_name === 'Converse Conosco' || $lead->campaign_name === $ad_headline)) {
                $update_lead['campaign_name'] = sanitize_text_field($campaign_name);
            }
            if (empty($lead->adset_id) && !empty($adset_id)) {
                $update_lead['adset_id'] = sanitize_text_field($adset_id);
            }
            if (!empty($adset_name) && (empty($lead->adset_name) || $lead->adset_name === '-')) {
                $update_lead['adset_name'] = sanitize_text_field($adset_name);
            }
            if (!empty($ad_name) && (empty($lead->ad_name) || $lead->ad_name === 'Converse Conosco' || $lead->ad_name === $ad_headline)) {
                $update_lead['ad_name'] = sanitize_text_field($ad_name);
            }
            if (empty($lead->utm_source) && !empty($utm_source)) {
                $update_lead['utm_source'] = $utm_source;
            }
            if (empty($lead->utm_medium) && !empty($utm_medium)) {
                $update_lead['utm_medium'] = $utm_medium;
            }
            if (!empty($utm_campaign) && (empty($lead->utm_campaign) || $lead->utm_campaign === 'Converse Conosco' || $lead->utm_campaign === $ad_headline)) {
                $update_lead['utm_campaign'] = $utm_campaign;
            }
            if (!empty($utm_content) && empty($lead->utm_content)) {
                $update_lead['utm_content'] = $utm_content;
            }
            if (!empty($utm_term) && (empty($lead->utm_term) || $lead->utm_term === 'Converse Conosco' || $lead->utm_term === $ad_headline)) {
                $update_lead['utm_term'] = $utm_term;
            }

            $wpdb->update($leads_table, $update_lead, ['id' => $lead_id]);

            // Auditoria (registra apenas no início da conversa pelo WhatsApp para evitar sobrecarregar o histórico)
            $is_first_interaction = empty($lead->primeira_mensagem) || $lead->primeira_mensagem === '0000-00-00 00:00:00' || ((int) $lead->mensagens_recebidas) <= 1;
            if ($is_first_interaction) {
                $wpdb->insert($history_table, [
                    'lead_id'        => $lead_id,
                    'campo'          => 'whatsapp_conversa_iniciada',
                    'valor_anterior' => $lead->whatsapp_status,
                    'valor_novo'     => 'Início de conversa no WhatsApp: ' . wp_trim_words($body, 10),
                    'origem'         => 'whatsapp',
                    'usuario_id'     => 0,
                    'created_at'     => current_time('mysql'),
                ]);
            }

            Logger::info('WhatsApp', "Mensagem vinculada ao Lead existente #{$lead_id} ({$phone_norm}).", [
                'lead_id'  => $lead_id,
                'telefone' => $phone_norm,
                'preview'  => wp_trim_words($body, 8),
            ]);
        } else {
            // Lead novo que iniciou contato direto pelo WhatsApp
            $form_origem = !empty($referral) ? 'Click-to-WhatsApp Ads' : 'WhatsApp Cloud Inbound';
            $insert_lead = [
                'uuid'                 => wp_generate_uuid4(),
                'nome'                 => sanitize_text_field($contact_name),
                'telefone'             => sanitize_text_field($from_raw),
                'telefone_normalizado' => $phone_norm,
                'formulario_nome'      => $form_origem,
                'tipo_curso'           => $inferred_tipo_curso,
                'area_interesse'       => $inferred_area,
                'fbclid'               => sanitize_text_field($ctwa_clid),
                'campaign_id'          => sanitize_text_field($campaign_id),
                'campaign_name'        => sanitize_text_field($campaign_name),
                'adset_id'             => sanitize_text_field($adset_id),
                'adset_name'           => sanitize_text_field($adset_name),
                'ad_id'                => sanitize_text_field($ad_id),
                'ad_name'              => sanitize_text_field($ad_name),
                'utm_source'           => $utm_source,
                'utm_medium'           => $utm_medium,
                'utm_campaign'         => $utm_campaign,
                'utm_content'          => $utm_content,
                'utm_term'             => $utm_term,
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
                'valor_novo'     => "Lead criado a partir de mensagem no WhatsApp ({$form_origem})",
                'origem'         => 'whatsapp',
                'usuario_id'     => 0,
                'created_at'     => current_time('mysql'),
            ]);

            Logger::info('WhatsApp', "Novo lead #{$lead_id} criado via WhatsApp Cloud ({$phone_norm}).", [
                'origem'     => $form_origem,
                'curso'      => $inferred_tipo_curso,
                'area'       => $inferred_area,
                'ctwa_clid'  => $ctwa_clid,
                'ad_id'      => $ad_id,
            ]);
        }

        // Armazena a mensagem consolidada por contato/lead para evitar inchaço do banco
        $existing_msg = $wpdb->get_row($wpdb->prepare(
            "SELECT id, payload_bruto FROM {$whatsapp_table} WHERE telefone_normalizado = %s ORDER BY id DESC LIMIT 1",
            $phone_norm
        ));

        if ($existing_msg) {
            $update_msg_data = [
                'lead_id'        => $lead_id,
                'message_id'     => sanitize_text_field($msg_id),
                'direcao'        => 'inbound',
                'tipo_mensagem'  => sanitize_text_field($type),
                'conteudo'       => $body,
                'status_entrega' => 'received',
                'created_at'     => $msg_date,
            ];
            // Se esta nova mensagem trouxer dados de anúncio Meta (referral), atualiza payload bruto
            if (!empty($referral)) {
                $update_msg_data['payload_bruto'] = wp_json_encode($msg, JSON_UNESCAPED_UNICODE);
            }
            $wpdb->update($whatsapp_table, $update_msg_data, ['id' => $existing_msg->id]);
        } else {
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

    /**
     * Reenriquece leads existentes a partir do histórico de mensagens brutas
     */
    public static function retro_enrich_leads() {
        global $wpdb;
        $whatsapp_table = DbSchema::get_whatsapp_table();
        $leads_table    = DbSchema::get_leads_table();

        $rows = $wpdb->get_results("SELECT id, lead_id, conteudo, payload_bruto FROM {$whatsapp_table} WHERE lead_id > 0");
        if (empty($rows)) {
            return;
        }

        foreach ($rows as $row) {
            $lead = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$leads_table} WHERE id = %d", $row->lead_id));
            if (!$lead) {
                continue;
            }

            $updates = [];
            $body = (string) $row->conteudo;

            // 1. Extração do payload_bruto
            $payload = !empty($row->payload_bruto) ? json_decode($row->payload_bruto, true) : [];
            $referral = $payload['referral'] ?? [];

            if (!empty($referral)) {
                $ctwa_clid   = $referral['ctwa_clid'] ?? '';
                $ad_id       = $referral['source_id'] ?? '';
                $ad_headline = $referral['headline'] ?? '';
                $source_url  = $referral['source_url'] ?? '';

                $ad_utms = [];
                if (!empty($source_url)) {
                    $parsed_query = parse_url($source_url, PHP_URL_QUERY);
                    if (!empty($parsed_query)) {
                        parse_str($parsed_query, $ad_utms);
                    }
                }

                $ad_meta = [];
                if (!empty($ad_id)) {
                    $ad_meta = WabaClient::get_ad_details($ad_id);
                }

                $campaign_id   = !empty($ad_meta['campaign_id']) ? $ad_meta['campaign_id'] : ($ad_utms['campaign_id'] ?? '');
                $campaign_name = !empty($ad_meta['campaign_name']) ? $ad_meta['campaign_name'] : (!empty($ad_utms['utm_campaign']) ? $ad_utms['utm_campaign'] : (!empty($ad_utms['campaign_name']) ? $ad_utms['campaign_name'] : $ad_headline));
                $adset_id      = !empty($ad_meta['adset_id']) ? $ad_meta['adset_id'] : ($ad_utms['adset_id'] ?? '');
                $adset_name    = !empty($ad_meta['adset_name']) ? $ad_meta['adset_name'] : (!empty($ad_utms['adset_name']) ? $ad_utms['adset_name'] : (!empty($ad_utms['utm_content']) ? $ad_utms['utm_content'] : ''));
                $ad_name       = !empty($ad_meta['ad_name']) ? $ad_meta['ad_name'] : (!empty($ad_utms['ad_name']) ? $ad_utms['ad_name'] : (!empty($ad_utms['utm_term']) ? $ad_utms['utm_term'] : $ad_headline));

                if (empty($lead->fbclid) && !empty($ctwa_clid)) {
                    $updates['fbclid'] = sanitize_text_field($ctwa_clid);
                }
                if (empty($lead->ad_id) && !empty($ad_id)) {
                    $updates['ad_id'] = sanitize_text_field($ad_id);
                }
                if (empty($lead->campaign_id) && !empty($campaign_id)) {
                    $updates['campaign_id'] = sanitize_text_field($campaign_id);
                }
                if (!empty($campaign_name) && (empty($lead->campaign_name) || $lead->campaign_name === 'Converse Conosco' || $lead->campaign_name === $ad_headline)) {
                    $updates['campaign_name'] = sanitize_text_field($campaign_name);
                }
                if (empty($lead->adset_id) && !empty($adset_id)) {
                    $updates['adset_id'] = sanitize_text_field($adset_id);
                }
                if (!empty($adset_name) && (empty($lead->adset_name) || $lead->adset_name === '-')) {
                    $updates['adset_name'] = sanitize_text_field($adset_name);
                }
                if (!empty($ad_name) && (empty($lead->ad_name) || $lead->ad_name === 'Converse Conosco' || $lead->ad_name === $ad_headline)) {
                    $updates['ad_name'] = sanitize_text_field($ad_name);
                }
                if (empty($lead->utm_source)) {
                    $updates['utm_source'] = !empty($ad_utms['utm_source']) ? sanitize_text_field($ad_utms['utm_source']) : 'meta_ads';
                }
                if (empty($lead->utm_medium)) {
                    $updates['utm_medium'] = !empty($ad_utms['utm_medium']) ? sanitize_text_field($ad_utms['utm_medium']) : 'whatsapp';
                }
                if (!empty($campaign_name) && (empty($lead->utm_campaign) || $lead->utm_campaign === 'Converse Conosco' || $lead->utm_campaign === $ad_headline)) {
                    $updates['utm_campaign'] = sanitize_text_field($campaign_name);
                }
                if (!empty($adset_name) && empty($lead->utm_content)) {
                    $updates['utm_content'] = sanitize_text_field($adset_name);
                }
                if (!empty($ad_name) && (empty($lead->utm_term) || $lead->utm_term === 'Converse Conosco' || $lead->utm_term === $ad_headline)) {
                    $updates['utm_term'] = sanitize_text_field($ad_name);
                }
                if (empty($lead->formulario_nome) || $lead->formulario_nome === 'WhatsApp Cloud Inbound') {
                    $updates['formulario_nome'] = 'Click-to-WhatsApp Ads';
                }
            }

            // 2. Extração de Curso do texto
            if (empty($lead->tipo_curso)) {
                if (preg_match('/(?:pós[\s\-]?graduação|pos[\s\-]?graduacao)/iu', $body)) {
                    $updates['tipo_curso'] = 'Pós-Graduação';
                } elseif (preg_match('/(?:segunda\s+graduação|segunda\s+graduacao)/iu', $body)) {
                    $updates['tipo_curso'] = 'Segunda Graduação';
                } elseif (preg_match('/(?:graduação|graduacao)/iu', $body)) {
                    $updates['tipo_curso'] = 'Graduação';
                }
            }

            if (empty($lead->area_interesse)) {
                if (preg_match('/(?:em|sobre|do curso de|de)\s+([A-ZÁÉÍÓÚÂÊÎÔÛÃÕÇ\s]{3,50})(?:\s+e\s+(?:gostaria|quero)|\s*[\.\,\!]|$)/iu', $body, $m_curso)) {
                    $c = trim($m_curso[1]);
                    if (!empty($c) && !in_array(mb_strtolower($c, 'UTF-8'), ['um', 'uma', 'mais', 'informações', 'informacoes', 'bolsa', 'desconto', 'valores'])) {
                        $updates['area_interesse'] = sanitize_text_field($c);
                    }
                }
            }

            if (!empty($updates)) {
                $updates['updated_at'] = current_time('mysql');
                $wpdb->update($leads_table, $updates, ['id' => $lead->id]);
            }
        }
    }
}
