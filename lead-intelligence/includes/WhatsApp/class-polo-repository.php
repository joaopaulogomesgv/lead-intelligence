<?php
namespace LeadIntelligence\WhatsApp;

use LeadIntelligence\Admin\Settings;
use LeadIntelligence\PhoneNormalizer;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Repositório dedicado para gerenciamento individual de Polos e Números de WhatsApp Cloud API
 */
class PoloRepository {

    const OPTION_KEY = 'lead_intelligence_polos';

    /**
     * Retorna todos os polos cadastrados
     *
     * @return array
     */
    public static function get_all() {
        $polos = get_option(self::OPTION_KEY, null);

        // Se ainda não existir a opção dedicada, migra automaticamente do settings
        if ($polos === null) {
            $polos = self::migrate_from_legacy_settings();
            update_option(self::OPTION_KEY, $polos);
        }

        if (!is_array($polos)) {
            $polos = [];
        }

        return $polos;
    }

    /**
     * Retorna apenas os polos ativos
     *
     * @return array
     */
    public static function get_active() {
        $all = self::get_all();
        return array_values(array_filter($all, function($p) {
            return empty($p['status']) || $p['status'] === 'ativo';
        }));
    }

    /**
     * Obtém polo por ID único
     *
     * @param string $id
     * @return array|null
     */
    public static function get_by_id($id) {
        $polos = self::get_all();
        foreach ($polos as $polo) {
            if ($polo['id'] === $id) {
                return $polo;
            }
        }
        return null;
    }

    /**
     * Localiza polo pelo Phone Number ID da Meta
     *
     * @param string $phone_number_id
     * @return array|null
     */
    public static function find_by_phone_number_id($phone_number_id) {
        if (empty($phone_number_id)) {
            return null;
        }
        $polos = self::get_all();
        foreach ($polos as $polo) {
            if ((string) ($polo['phone_number_id'] ?? '') === (string) $phone_number_id) {
                return $polo;
            }
        }
        return null;
    }

    /**
     * Localiza polo pelo telefone exibido
     *
     * @param string $phone
     * @return array|null
     */
    public static function find_by_phone($phone) {
        if (empty($phone)) {
            return null;
        }
        $norm = PhoneNormalizer::normalize($phone);
        $polos = self::get_all();
        foreach ($polos as $polo) {
            if (!empty($polo['display_phone']) && PhoneNormalizer::normalize($polo['display_phone']) === $norm) {
                return $polo;
            }
        }
        return null;
    }

    /**
     * Retorna o polo padrão / principal
     *
     * @return array|null
     */
    public static function get_default() {
        $polos = self::get_all();
        foreach ($polos as $polo) {
            if (!empty($polo['is_default'])) {
                return $polo;
            }
        }
        return !empty($polos) ? $polos[0] : null;
    }

    /**
     * Salva ou atualiza um polo individualmente
     *
     * @param array $data
     * @return string ID do polo salvo
     */
    public static function save($data) {
        $polos = self::get_all();

        $id = !empty($data['id']) ? sanitize_key($data['id']) : 'polo_' . uniqid();
        $is_new = true;

        $nome = sanitize_text_field($data['nome'] ?? 'Novo Polo');
        $phone_number_id = sanitize_text_field($data['phone_number_id'] ?? '');
        $display_phone = sanitize_text_field($data['display_phone'] ?? '');
        $waba_id = sanitize_text_field($data['waba_id'] ?? '');
        $access_token = sanitize_text_field($data['access_token'] ?? '');
        $status = (!empty($data['status']) && $data['status'] === 'inativo') ? 'inativo' : 'ativo';
        $is_default = !empty($data['is_default']) ? 1 : 0;

        // Se o token vier mascarado e estiver editando, preserva o anterior
        if (!empty($data['id']) && (strpos($access_token, '••••') !== false || empty($access_token))) {
            $existing_polo = self::get_by_id($id);
            if ($existing_polo && !empty($existing_polo['access_token'])) {
                $access_token = $existing_polo['access_token'];
            }
        }

        // Se este polo for marcado como padrão, desmarca os demais
        if ($is_default) {
            foreach ($polos as &$p) {
                $p['is_default'] = 0;
            }
            unset($p);
        }

        $now = current_time('mysql');

        $polo_record = [
            'id'              => $id,
            'nome'            => $nome,
            'phone_number_id' => $phone_number_id,
            'display_phone'   => $display_phone,
            'waba_id'         => $waba_id,
            'access_token'    => $access_token,
            'status'          => $status,
            'is_default'      => $is_default,
            'updated_at'      => $now,
        ];

        // Atualiza se já existir
        foreach ($polos as $idx => $existing) {
            if ($existing['id'] === $id) {
                $polo_record['created_at'] = $existing['created_at'] ?? $now;
                $polos[$idx] = $polo_record;
                $is_new = false;
                break;
            }
        }

        if ($is_new) {
            $polo_record['created_at'] = $now;
            // Se for o único polo, garante que é o padrão
            if (empty($polos)) {
                $polo_record['is_default'] = 1;
            }
            $polos[] = $polo_record;
        }

        update_option(self::OPTION_KEY, $polos);

        // Sincroniza com as configurações gerais legadas para compatibilidade
        self::sync_to_legacy_settings();

        return $id;
    }

    /**
     * Exclui um polo por ID
     *
     * @param string $id
     * @return bool
     */
    public static function delete($id) {
        $polos = self::get_all();
        $filtered = [];
        $was_default = false;

        foreach ($polos as $p) {
            if ($p['id'] === $id) {
                if (!empty($p['is_default'])) {
                    $was_default = true;
                }
                continue;
            }
            $filtered[] = $p;
        }

        // Se excluiu o polo padrão e ainda restarem polos, define o primeiro como padrão
        if ($was_default && !empty($filtered)) {
            $filtered[0]['is_default'] = 1;
        }

        update_option(self::OPTION_KEY, $filtered);
        self::sync_to_legacy_settings();
        return true;
    }

    /**
     * Retorna o Access Token a ser usado por um polo (específico ou global como fallback)
     *
     * @param array|null $polo
     * @return string
     */
    public static function get_access_token_for_polo($polo = null) {
        if ($polo && !empty($polo['access_token'])) {
            return $polo['access_token'];
        }
        $settings = Settings::get_settings();
        return $settings['meta_access_token'] ?? '';
    }

    /**
     * Retorna o WABA ID a ser usado por um polo (específico ou global como fallback)
     *
     * @param array|null $polo
     * @return string
     */
    public static function get_waba_id_for_polo($polo = null) {
        if ($polo && !empty($polo['waba_id'])) {
            return $polo['waba_id'];
        }
        $settings = Settings::get_settings();
        return $settings['meta_waba_id'] ?? '';
    }

    /**
     * Migra dados anteriores de settings para a nova estrutura dedicada
     */
    private static function migrate_from_legacy_settings() {
        $settings = Settings::get_settings();
        $polos = [];

        if (!empty($settings['meta_phone_numbers']) && is_array($settings['meta_phone_numbers'])) {
            foreach ($settings['meta_phone_numbers'] as $p) {
                if (empty($p['phone_number_id']) && empty($p['nome'])) {
                    continue;
                }
                $polos[] = [
                    'id'              => sanitize_key($p['id'] ?? ('polo_' . uniqid())),
                    'nome'            => sanitize_text_field($p['nome'] ?? 'Polo Principal'),
                    'phone_number_id' => sanitize_text_field($p['phone_number_id'] ?? ''),
                    'display_phone'   => sanitize_text_field($p['display_phone'] ?? ''),
                    'waba_id'         => sanitize_text_field($p['waba_id'] ?? ''),
                    'access_token'    => '',
                    'status'          => 'ativo',
                    'is_default'      => !empty($p['is_default']) ? 1 : 0,
                    'created_at'      => current_time('mysql'),
                    'updated_at'      => current_time('mysql'),
                ];
            }
        }

        // Se ainda não tiver nenhum polo mas tiver meta_phone_number_id nas configurações antigas
        if (empty($polos) && !empty($settings['meta_phone_number_id'])) {
            $polos[] = [
                'id'              => 'polo_matriz',
                'nome'            => 'Polo Matriz',
                'phone_number_id' => $settings['meta_phone_number_id'],
                'display_phone'   => '',
                'waba_id'         => $settings['meta_waba_id'] ?? '',
                'access_token'    => '',
                'status'          => 'ativo',
                'is_default'      => 1,
                'created_at'      => current_time('mysql'),
                'updated_at'      => current_time('mysql'),
            ];
        }

        return $polos;
    }

    /**
     * Sincroniza o polo padrão com o campo legado nas configurações gerais
     */
    private static function sync_to_legacy_settings() {
        $polos = self::get_all();
        $default_polo = self::get_default();

        $settings = Settings::get_settings();
        $settings['meta_phone_numbers'] = $polos;
        if ($default_polo && !empty($default_polo['phone_number_id'])) {
            $settings['meta_phone_number_id'] = $default_polo['phone_number_id'];
        }
        update_option('lead_intelligence_settings', $settings);
    }
}
