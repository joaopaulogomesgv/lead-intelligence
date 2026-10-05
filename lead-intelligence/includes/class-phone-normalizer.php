<?php
namespace LeadIntelligence;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Normalizador e Sanitizador de Telefones para o Padrão Brasileiro / Internacional
 */
class PhoneNormalizer {

    /**
     * Normaliza um telefone para o formato padrão: 55 + DDD + 9 dígitos (ex: 5534984200518)
     *
     * @param string $phone
     * @return string
     */
    public static function normalize($phone) {
        if (empty($phone)) {
            return '';
        }

        // Remove tudo que não for dígito
        $digits = preg_replace('/\D/', '', (string) $phone);

        if (empty($digits)) {
            return '';
        }

        // Remove zeros à esquerda (ex: 034984200518 -> 34984200518)
        $digits = ltrim($digits, '0');

        $len = strlen($digits);

        // Caso 1: Já veio com DDI 55
        if (substr($digits, 0, 2) === '55') {
            $national = substr($digits, 2);
            $n_len = strlen($national);

            // DDD (2) + 8 dígitos (sem o 9) -> adiciona o 9
            if ($n_len === 10) {
                $ddd = substr($national, 0, 2);
                $num = substr($national, 2);
                return '55' . $ddd . '9' . $num;
            }

            // DDD (2) + 9 dígitos -> perfeito
            if ($n_len === 11) {
                return '55' . $national;
            }

            // Caso especial ou internacional que começa com 55
            return $digits;
        }

        // Caso 2: Não tem 55, mas tem DDD + número
        // 11 dígitos: DDD (2) + 9 dígitos (ex: 34984200518)
        if ($len === 11) {
            return '55' . $digits;
        }

        // 10 dígitos: DDD (2) + 8 dígitos (ex: 3484200518) -> injeta o nono dígito 9
        if ($len === 10) {
            $ddd = substr($digits, 0, 2);
            $num = substr($digits, 2);
            return '55' . $ddd . '9' . $num;
        }

        // Caso 3: Número internacional ou formato não padrão, preserva dígitos se razoável
        if ($len >= 8 && $len <= 15) {
            return $digits;
        }

        return $digits;
    }

    /**
     * Retorna lista de possíveis variações do número para garantir cruzamento flexível
     * (Ex: Com 9º dígito e sem 9º dígito, com e sem DDI 55)
     *
     * @param string $phone
     * @return array
     */
    public static function get_lookup_variations($phone) {
        $normalized = self::normalize($phone);
        if (empty($normalized)) {
            return [];
        }

        $variations = [$normalized];

        // Se for número brasileiro com 13 dígitos: 55 + DDD(2) + 9 + 8 dígitos
        if (strlen($normalized) === 13 && substr($normalized, 0, 2) === '55') {
            $ddd = substr($normalized, 2, 2);
            $num9 = substr($normalized, 4); // 9 dígitos
            $num8 = substr($normalized, 5); // 8 dígitos (sem o 9)

            // Variação sem 9º dígito (55 + DDD + 8 dígitos)
            $variations[] = '55' . $ddd . $num8;

            // Variações sem DDI 55
            $variations[] = $ddd . $num9;
            $variations[] = $ddd . $num8;

            // Com zero no DDD
            $variations[] = '0' . $ddd . $num9;
            $variations[] = '0' . $ddd . $num8;
        }

        return array_values(array_unique($variations));
    }

    /**
     * Formata para exibição bonita na UI: +55 (34) 98420-0518
     *
     * @param string $phone
     * @return string
     */
    public static function format_display($phone) {
        $clean = self::normalize($phone);
        if (strlen($clean) === 13 && substr($clean, 0, 2) === '55') {
            $ddd = substr($clean, 2, 2);
            $part1 = substr($clean, 4, 5);
            $part2 = substr($clean, 9, 4);
            return "+55 ({$ddd}) {$part1}-{$part2}";
        }
        return $phone;
    }
}
