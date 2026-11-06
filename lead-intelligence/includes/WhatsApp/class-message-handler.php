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
                        self::handle_status_update($status_event, $value, $contacts_map);
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
        $now = current_time('timestamp');
        // Se o timestamp for mock de testes (ex: Meta usa 2017) ou descalibrado em mais de 48h, usa o momento atual
        if (!$timestamp || abs($now - $timestamp) > 86400 * 2) {
            $msg_date = current_time('mysql');
        } else {
            $msg_date = wp_date('Y-m-d H:i:s', $timestamp);
        }

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

        // Extrai metadados da linha/número receptor da Meta (Multi-Polo)
        $metadata               = $change_value['metadata'] ?? [];
        $received_phone_id      = sanitize_text_field($metadata['phone_number_id'] ?? '');
        $received_display_phone = sanitize_text_field($metadata['display_phone_number'] ?? '');

        // Identifica o Polo associado a este Phone Number ID
        $polo_info = null;
        if (!empty($received_phone_id)) {
            $polo_info = \LeadIntelligence\Admin\Settings::find_polo_by_phone_number_id($received_phone_id);
        }

        // Fallback 1: Tenta localizar pelo número de exibição se não achou pelo Phone Number ID
        if (!$polo_info && !empty($received_display_phone)) {
            $polo_info = \LeadIntelligence\WhatsApp\PoloRepository::find_by_phone($received_display_phone);
        }

        // Fallback 2: Se ainda não localizou mas há apenas 1 polo ativo ou um polo padrão, associa com segurança para não perder o lead
        if (!$polo_info) {
            $active_polos = \LeadIntelligence\WhatsApp\PoloRepository::get_active();
            if (count($active_polos) === 1) {
                $polo_info = $active_polos[0];
                Logger::info('WhatsApp', "Mensagem associada com segurança ao único Polo ativo '{$polo_info['nome']}' (Phone ID recebido: {$received_phone_id}).", [
                    'received_phone_id'      => $received_phone_id,
                    'received_display_phone' => $received_display_phone,
                    'polo'                   => $polo_info['nome'],
                ]);
            } elseif ($default_polo = \LeadIntelligence\WhatsApp\PoloRepository::get_default()) {
                if (($default_polo['status'] ?? 'ativo') === 'ativo') {
                    $polo_info = $default_polo;
                    Logger::info('WhatsApp', "Mensagem associada com segurança ao Polo Padrão '{$polo_info['nome']}' (Phone ID recebido: {$received_phone_id}).", [
                        'received_phone_id'      => $received_phone_id,
                        'received_display_phone' => $received_display_phone,
                        'polo'                   => $polo_info['nome'],
                    ]);
                }
            }
        }

        if (!$polo_info || ($polo_info['status'] ?? 'ativo') !== 'ativo') {
            Logger::warning('WhatsApp', "Mensagem ignorada: O Phone Number ID '{$received_phone_id}' (Telefone '{$received_display_phone}') não pertence a nenhum número de WhatsApp ativo configurado no plugin.", [
                'received_phone_id'      => $received_phone_id,
                'received_display_phone' => $received_display_phone,
            ]);
            return;
        }

        $polo_nome = !empty($polo_info['nome']) ? $polo_info['nome'] : ('Polo ' . $received_display_phone);

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

            // Se o lead não tinha nome OU tinha nome provisório/genérico ("Lead WhatsApp...") e o perfil forneceu o nome real
            $is_placeholder_name = empty($lead->nome) 
                || in_array(trim($lead->nome), ['Lead WhatsApp (Meta Ads)', 'Sem nome', 'Lead WhatsApp'], true)
                || preg_match('/^Lead WhatsApp/i', trim($lead->nome));

            if (!empty($contact_name) && $is_placeholder_name) {
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

            // Se o ad_id atual estiver vazio ou for um hash provisório de conversa (não numérico de 8 a 25 dígitos)
            $is_conv_hash = (!empty($lead->ad_id) && !preg_match('/^[0-9]{8,25}$/', (string) $lead->ad_id));
            if ((empty($lead->ad_id) || $is_conv_hash) && !empty($ad_id) && preg_match('/^[0-9]{8,25}$/', (string) $ad_id)) {
                $update_lead['ad_id'] = sanitize_text_field($ad_id);
            } elseif (empty($lead->ad_id) && !empty($ad_id)) {
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

            // Atualiza página de origem / link do anúncio se o lead ainda não tiver
            if (empty($lead->pagina_origem)) {
                if (!empty($source_url)) {
                    $update_lead['pagina_origem'] = esc_url_raw($source_url);
                } elseif (!empty($ad_id) && preg_match('/^[0-9]{8,25}$/', $ad_id)) {
                    $update_lead['pagina_origem'] = 'https://adsmanager.facebook.com/adsmanager/manage/ads?selected_ad_ids=' . $ad_id;
                }
            }

            // Atualiza polo e phone_number_id se o lead ainda não tiver
            if (empty($lead->polo) && !empty($polo_nome)) {
                $update_lead['polo'] = $polo_nome;
            }
            if (empty($lead->phone_number_id) && !empty($received_phone_id)) {
                $update_lead['phone_number_id'] = $received_phone_id;
            }

            $wpdb->update($leads_table, $update_lead, ['id' => $lead_id]);

            // Auditoria (registra apenas no início da conversa pelo WhatsApp para evitar sobrecarregar o histórico)
            $is_first_interaction = empty($lead->primeira_mensagem) || $lead->primeira_mensagem === '0000-00-00 00:00:00' || ((int) $lead->mensagens_recebidas) <= 1;
            if ($is_first_interaction) {
                $polo_txt = !empty($polo_nome) ? " ({$polo_nome})" : "";
                $wpdb->insert($history_table, [
                    'lead_id'        => $lead_id,
                    'campo'          => 'whatsapp_conversa_iniciada',
                    'valor_anterior' => $lead->whatsapp_status,
                    'valor_novo'     => "Início de conversa no WhatsApp{$polo_txt}: " . wp_trim_words($body, 10),
                    'origem'         => 'whatsapp',
                    'usuario_id'     => 0,
                    'created_at'     => current_time('mysql'),
                ]);
            }

            Logger::info('WhatsApp', "Mensagem vinculada ao Lead existente #{$lead_id} ({$phone_norm}).", [
                'lead_id'  => $lead_id,
                'polo'     => $polo_nome,
                'telefone' => $phone_norm,
                'preview'  => wp_trim_words($body, 8),
            ]);
        } else {
            // Lock atômico para evitar duplicações concorrentes por múltiplos webhooks simultâneos da Meta
            $lock_key = 'li_lead_lock_' . $phone_norm;
            $got_lock = add_option($lock_key, time(), '', 'no');
            if (!$got_lock) {
                usleep(120000); // 120ms
                $existing_lead = LeadRepository::find_by_phone($phone_norm);
                if ($existing_lead) {
                    $lead_id = (int) $existing_lead->id;
                    $wpdb->update($leads_table, [
                        'whatsapp_status'     => 'conversando',
                        'ultima_mensagem'     => $msg_date,
                        'mensagens_recebidas' => ((int) $existing_lead->mensagens_recebidas) + 1,
                        'updated_at'          => current_time('mysql'),
                    ], ['id' => $lead_id]);

                    if (!empty($msg_id)) {
                        $wpdb->insert($whatsapp_table, [
                            'lead_id'              => $lead_id,
                            'message_id'           => $msg_id,
                            'direcao'              => 'inbound',
                            'tipo'                 => $type,
                            'conteudo'             => $body,
                            'polo'                 => $polo_nome,
                            'phone_number_id'      => $received_phone_id,
                            'telefone'             => $from_raw,
                            'telefone_normalizado' => $phone_norm,
                            'status_entrega'       => 'recebido',
                            'payload_bruto'        => !empty($change_value) ? wp_json_encode($change_value, JSON_UNESCAPED_UNICODE) : null,
                            'data_envio'           => $msg_date,
                            'created_at'           => current_time('mysql'),
                        ]);
                    }
                    return;
                }
            }

            // Lead novo que iniciou contato direto pelo WhatsApp
            $form_origem = !empty($referral) ? 'Click-to-WhatsApp Ads' : 'WhatsApp Cloud Inbound';
            $insert_lead = [
                'uuid'                 => wp_generate_uuid4(),
                'nome'                 => sanitize_text_field($contact_name),
                'telefone'             => sanitize_text_field($from_raw),
                'telefone_normalizado' => $phone_norm,
                'formulario_nome'      => $form_origem,
                'pagina_origem'        => !empty($source_url) ? esc_url_raw($source_url) : (!empty($ad_id) && preg_match('/^[0-9]{8,25}$/', $ad_id) ? ('https://adsmanager.facebook.com/adsmanager/manage/ads?selected_ad_ids=' . $ad_id) : ''),
                'tipo_curso'           => $inferred_tipo_curso,
                'area_interesse'       => $inferred_area,
                'polo'                 => $polo_nome,
                'phone_number_id'      => $received_phone_id,
                'fbclid'               => sanitize_text_field($ctwa_clid),
                'campaign_id'          => sanitize_text_field($campaign_id),
                'campaign_name'        => sanitize_text_field($campaign_name),
                'adset_id'             => sanitize_text_field($adset_id),
                'adset_name'           => sanitize_text_field($adset_name),
                'ad_id'                => sanitize_text_field($ad_id),
                'ad_name'              => sanitize_text_field($ad_name),
                'utm_source'           => !empty($utm_source) ? $utm_source : 'meta_ads',
                'utm_medium'           => !empty($utm_medium) ? $utm_medium : 'whatsapp',
                'utm_campaign'         => !empty($utm_campaign) ? $utm_campaign : 'WhatsApp Inbound',
                'utm_content'          => $utm_content,
                'utm_term'             => $utm_term,
                'qualificacao_status'  => 'pendente',
                'qualificacao_dados'   => !empty($referral) ? wp_json_encode(['referral' => $referral], JSON_UNESCAPED_UNICODE) : null,
                'whatsapp_status'      => 'conversando',
                'primeira_mensagem'    => $msg_date,
                'ultima_mensagem'      => $msg_date,
                'mensagens_recebidas'  => 1,
                'data_cadastro'        => current_time('mysql'),
                'created_at'           => current_time('mysql'),
                'updated_at'           => current_time('mysql'),
            ];

            $wpdb->insert($leads_table, $insert_lead);
            $lead_id = $wpdb->insert_id;
            delete_option($lock_key);

            $polo_info_txt = !empty($polo_nome) ? " via {$polo_nome}" : "";
            $wpdb->insert($history_table, [
                'lead_id'        => $lead_id,
                'campo'          => 'criacao',
                'valor_anterior' => '',
                'valor_novo'     => "Lead criado a partir de mensagem no WhatsApp ({$form_origem}{$polo_info_txt})",
                'origem'         => 'whatsapp',
                'usuario_id'     => 0,
                'created_at'     => current_time('mysql'),
            ]);

            Logger::info('WhatsApp', "Novo lead #{$lead_id} criado via WhatsApp Cloud ({$phone_norm}).", [
                'origem'     => $form_origem,
                'polo'       => $polo_nome,
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
                'lead_id'         => $lead_id,
                'message_id'      => sanitize_text_field($msg_id),
                'direcao'         => 'inbound',
                'tipo_mensagem'   => sanitize_text_field($type),
                'conteudo'        => $body,
                'status_entrega'  => 'received',
                'polo'            => $polo_nome,
                'phone_number_id' => $received_phone_id,
                'created_at'      => $msg_date,
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
                'polo'                 => $polo_nome,
                'phone_number_id'      => $received_phone_id,
                'payload_bruto'        => wp_json_encode($msg, JSON_UNESCAPED_UNICODE),
                'created_at'           => $msg_date,
            ]);
        }
    }

    /**
     * Processa atualizações de status de mensagens enviadas (sent, delivered, read)
     * e cria leads caso seja uma conversa iniciada por anúncio Meta (referral_conversion)
     */
    private static function handle_status_update($status_event, $change_value, $contacts_map = []) {
        global $wpdb;
        $whatsapp_table = DbSchema::get_whatsapp_table();
        $leads_table    = DbSchema::get_leads_table();
        $history_table  = DbSchema::get_history_table();

        $msg_id       = $status_event['id'] ?? '';
        $new_status   = $status_event['status'] ?? ''; // sent, delivered, read, failed
        $recipient    = $status_event['recipient_id'] ?? '';
        $conv_id      = $status_event['conversation']['id'] ?? '';
        $conv_origin  = $status_event['conversation']['origin']['type'] ?? '';
        $pricing_cat  = $status_event['pricing']['category'] ?? '';

        $phone_norm = PhoneNormalizer::normalize($recipient);
        if (empty($phone_norm)) {
            return;
        }

        // Extrai metadados da linha/número receptor da Meta (Multi-Polo)
        $metadata               = $change_value['metadata'] ?? [];
        $received_phone_id      = sanitize_text_field($metadata['phone_number_id'] ?? '');
        $received_display_phone = sanitize_text_field($metadata['display_phone_number'] ?? '');

        $polo_info = null;
        if (!empty($received_phone_id)) {
            $polo_info = \LeadIntelligence\Admin\Settings::find_polo_by_phone_number_id($received_phone_id);
        }
        if (!$polo_info && !empty($received_display_phone)) {
            $polo_info = \LeadIntelligence\WhatsApp\PoloRepository::find_by_phone($received_display_phone);
        }
        if (!$polo_info) {
            $active_polos = \LeadIntelligence\WhatsApp\PoloRepository::get_active();
            if (count($active_polos) === 1) {
                $polo_info = $active_polos[0];
            } elseif ($default_polo = \LeadIntelligence\WhatsApp\PoloRepository::get_default()) {
                $polo_info = $default_polo;
            }
        }
        $polo_nome = !empty($polo_info['nome']) ? $polo_info['nome'] : (!empty($received_display_phone) ? ('Polo ' . $received_display_phone) : 'Polo WhatsApp');

        // Atualiza status na tabela de mensagens se já existir
        if (!empty($msg_id)) {
            $wpdb->update(
                $whatsapp_table,
                ['status_entrega' => sanitize_text_field($new_status)],
                ['message_id' => $msg_id]
            );
        }

        $lead = LeadRepository::find_by_phone($phone_norm);

        if ($lead) {
            $lead_updates = [];
            if (!empty($conv_id) && empty($lead->conversation_id)) {
                $lead_updates['conversation_id'] = sanitize_text_field($conv_id);
            }
            if ($new_status === 'read') {
                $lead_updates['whatsapp_status'] = 'lida';
            } elseif (empty($lead->whatsapp_status)) {
                $lead_updates['whatsapp_status'] = sanitize_text_field($new_status);
            }
            if (empty($lead->polo) && !empty($polo_nome)) {
                $lead_updates['polo'] = $polo_nome;
            }
            if (empty($lead->phone_number_id) && !empty($received_phone_id)) {
                $lead_updates['phone_number_id'] = $received_phone_id;
            }
            if (!empty($lead_updates)) {
                $lead_updates['updated_at'] = current_time('mysql');
                $wpdb->update($leads_table, $lead_updates, ['id' => $lead->id]);
            }
        } else {
            // Lock atômico para evitar duplicações concorrentes por múltiplos webhooks simultâneos da Meta
            $lock_key = 'li_lead_lock_' . $phone_norm;
            $got_lock = add_option($lock_key, time(), '', 'no');
            if (!$got_lock) {
                usleep(120000); // Aguarda 120ms para o processo concorrente concluir
                $existing_lead = LeadRepository::find_by_phone($phone_norm);
                if ($existing_lead) {
                    if (!empty($conv_id) && empty($existing_lead->conversation_id)) {
                        $wpdb->update($leads_table, ['conversation_id' => sanitize_text_field($conv_id)], ['id' => $existing_lead->id]);
                    }
                    return;
                }
            }

            // Se o lead ainda não existe e este evento indica um contato no WhatsApp (especialmente anúncio Meta Ads)
            $is_referral  = ($conv_origin === 'referral_conversion' || $pricing_cat === 'referral_conversion');
            $contact_name = $contacts_map[$recipient] ?? ($contacts_map[$phone_norm] ?? '');
            if (empty($contact_name)) {
                $contact_name = $is_referral ? 'Lead WhatsApp (Meta Ads)' : ('Lead WhatsApp ' . substr($phone_norm, -4));
            }

            $now_mysql = current_time('mysql');
            $new_lead_data = [
                'uuid'                 => wp_generate_uuid4(),
                'nome'                 => sanitize_text_field($contact_name),
                'telefone'             => sanitize_text_field($recipient),
                'telefone_normalizado' => $phone_norm,
                'formulario_nome'      => $is_referral ? 'WhatsApp Click-to-WhatsApp (Meta Ads)' : 'WhatsApp Cloud API',
                'polo'                 => $polo_nome,
                'phone_number_id'      => $received_phone_id,
                'conversation_id'      => sanitize_text_field($conv_id),
                'fbclid'               => $is_referral ? sanitize_text_field($conv_id) : '',
                'ad_id'                => '', // Não gravar hash de conversa em ad_id (evita "Ad #de2de687c2...")
                'utm_source'           => 'meta_ads',
                'utm_medium'           => 'whatsapp',
                'utm_campaign'         => $is_referral ? 'Click-to-WhatsApp' : 'WhatsApp Inbound',
                'qualificacao_status'  => 'pendente',
                'qualificacao_dados'   => !empty($referral) ? wp_json_encode(['referral' => $referral], JSON_UNESCAPED_UNICODE) : null,
                'whatsapp_status'      => ($new_status === 'read' ? 'lida' : sanitize_text_field($new_status)),
                'primeira_mensagem'    => $now_mysql,
                'ultima_mensagem'      => $now_mysql,
                'mensagens_recebidas'  => 0,
                'mensagens_enviadas'   => 1,
                'data_cadastro'        => $now_mysql,
                'created_at'           => $now_mysql,
                'updated_at'           => $now_mysql,
            ];

            $wpdb->insert($leads_table, $new_lead_data);
            $new_lead_id = (int) $wpdb->insert_id;
            delete_option($lock_key);

            if ($new_lead_id > 0) {
                $polo_txt = !empty($polo_nome) ? " via {$polo_nome}" : "";
                $wpdb->insert($history_table, [
                    'lead_id'        => $new_lead_id,
                    'campo'          => 'criacao',
                    'valor_anterior' => '',
                    'valor_novo'     => "Lead criado a partir de evento WhatsApp ({$new_lead_data['formulario_nome']}{$polo_txt})",
                    'origem'         => 'whatsapp',
                    'usuario_id'     => 0,
                    'created_at'     => $now_mysql,
                ]);

                Logger::info('WhatsApp', "Novo lead #{$new_lead_id} criado a partir de evento WhatsApp ({$phone_norm}).", [
                    'polo'        => $polo_nome,
                    'status'      => $new_status,
                    'conv_id'     => $conv_id,
                    'conv_origin' => $conv_origin,
                ]);
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
