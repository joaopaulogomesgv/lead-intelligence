<?php
namespace LeadIntelligence\Tracking;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gerenciador e Preservador de UTMs e Parâmetros Meta Ads
 */
class UtmTracker {

    /**
     * Chaves monitoradas de UTMs e Meta
     */
    const TRACKING_KEYS = [
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
        'utm_term',
        'fbclid',
        'gclid',
        'gbraid',
        'wbraid',
        'gad_source',
        'gad_campaignid',
        'referrer',
        'page_url',
        'fbc',
        'fbp',
        'campaign_id',
        'adset_id',
        'ad_id',
        'campaign_name',
        'adset_name',
        'ad_name',
    ];

    public static function init() {
        // Enfileira script frontend de captura e injeção de UTMs
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_frontend_tracker']);

        // Captura e grava cookies via PHP caso cheguem na requisição
        add_action('init', [__CLASS__, 'capture_server_side']);
    }

    /**
     * Enfileira o script JavaScript responsável por persistir UTMs e injetar nos formulários
     */
    public static function enqueue_frontend_tracker() {
        $settings = get_option('lead_intelligence_settings', []);
        if (isset($settings['utm_preservation']) && empty($settings['utm_preservation'])) {
            return;
        }

        wp_enqueue_script(
            'li-utm-preserver',
            LEAD_INTELLIGENCE_PLUGIN_URL . 'assets/js/utm-preserver.js',
            [],
            LEAD_INTELLIGENCE_VERSION,
            true
        );

        wp_localize_script('li-utm-preserver', 'liTrackingConfig', [
            'cookiePrefix' => 'li_',
            'cookieDays'   => 30,
            'trackingKeys' => self::TRACKING_KEYS,
        ]);
    }

    /**
     * Captura parâmetros via PHP e define cookies caso não existam
     */
    public static function capture_server_side() {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return;
        }

        $cookie_lifetime = time() + (30 * DAY_IN_SECONDS);
        $cookie_path = COOKIEPATH ? COOKIEPATH : '/';
        $cookie_domain = COOKIE_DOMAIN ? COOKIE_DOMAIN : '';
        $is_ssl = is_ssl();

        // Captura fbclid da URL e gera o cookie _fbc padrão da Meta caso não exista
        if (!empty($_GET['fbclid'])) {
            $fbclid = sanitize_text_field(wp_unslash($_GET['fbclid']));
            $fbc_val = 'fb.1.' . time() . '.' . $fbclid;

            if (empty($_COOKIE['_fbc'])) {
                setcookie('_fbc', $fbc_val, [
                    'expires'  => $cookie_lifetime,
                    'path'     => $cookie_path,
                    'domain'   => $cookie_domain,
                    'secure'   => $is_ssl,
                    'httponly' => false,
                    'samesite' => 'Lax',
                ]);
                $_COOKIE['_fbc'] = $fbc_val;
            }

            setcookie('li_fbclid', $fbclid, [
                'expires'  => $cookie_lifetime,
                'path'     => $cookie_path,
                'domain'   => $cookie_domain,
                'secure'   => $is_ssl,
                'httponly' => false,
                'samesite' => 'Lax',
            ]);
            $_COOKIE['li_fbclid'] = $fbclid;
        }

        // Garante existência de _fbp padrão da Meta caso o pixel não tenha disparado ainda
        if (empty($_COOKIE['_fbp'])) {
            $fbp_val = 'fb.1.' . time() . '.' . wp_rand(1000000000, 9999999999);
            setcookie('_fbp', $fbp_val, [
                'expires'  => $cookie_lifetime,
                'path'     => $cookie_path,
                'domain'   => $cookie_domain,
                'secure'   => $is_ssl,
                'httponly' => false,
                'samesite' => 'Lax',
            ]);
            $_COOKIE['_fbp'] = $fbp_val;
        }

        // Captura as UTMs da URL e salva em cookies li_*
        foreach (self::TRACKING_KEYS as $key) {
            if (!empty($_GET[$key])) {
                $val = sanitize_text_field(wp_unslash($_GET[$key]));
                setcookie('li_' . $key, $val, [
                    'expires'  => $cookie_lifetime,
                    'path'     => $cookie_path,
                    'domain'   => $cookie_domain,
                    'secure'   => $is_ssl,
                    'httponly' => false,
                    'samesite' => 'Lax',
                ]);
                $_COOKIE['li_' . $key] = $val;
            }
        }
    }

    /**
     * Extrai todos os dados de rastreamento disponíveis (POST > GET > COOKIE > META COOKIES)
     *
     * @return array
     */
    public static function get_current_tracking_data() {
        $result = [];

        // Extração de parâmetros da URL de onde partiu a requisição (essencial para AJAX do Elementor)
        $referer_params = [];
        if (!empty($_SERVER['HTTP_REFERER'])) {
            $query_str = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_QUERY);
            if (!empty($query_str)) {
                parse_str($query_str, $referer_params);
            }
        }

        foreach (self::TRACKING_KEYS as $key) {
            $val = '';

            // 1. Prioridade: Campo enviado no formulário (POST)
            if (!empty($_POST[$key])) {
                $val = sanitize_text_field(wp_unslash($_POST[$key]));
            }
            // 2. Parâmetro na URL atual (GET)
            elseif (!empty($_GET[$key])) {
                $val = sanitize_text_field(wp_unslash($_GET[$key]));
            }
            // 3. Parâmetro na URL da página de origem do AJAX (HTTP_REFERER)
            elseif (!empty($referer_params[$key])) {
                $val = sanitize_text_field(wp_unslash($referer_params[$key]));
            }
            // 4. Cookie específico li_*
            elseif (!empty($_COOKIE['li_' . $key])) {
                $val = sanitize_text_field(wp_unslash($_COOKIE['li_' . $key]));
            }

            // Tratamento especial para fbc e fbp padrão da Meta
            if ($key === 'fbc' && empty($val) && !empty($_COOKIE['_fbc'])) {
                $val = sanitize_text_field(wp_unslash($_COOKIE['_fbc']));
            }
            if ($key === 'fbp' && empty($val) && !empty($_COOKIE['_fbp'])) {
                $val = sanitize_text_field(wp_unslash($_COOKIE['_fbp']));
            }

            // Se tem fbclid mas não tem fbc, formata no padrão da Meta Conversions API
            if ($key === 'fbc' && empty($val) && !empty($result['fbclid'])) {
                $val = 'fb.1.' . time() . '.' . $result['fbclid'];
            }

            $result[$key] = $val;
        }

        // Mapeamentos inteligentes de equivalência Google Ads
        if (empty($result['campaign_id']) && !empty($result['gad_campaignid'])) {
            $result['campaign_id'] = $result['gad_campaignid'];
        }
        if (empty($result['utm_campaign']) && !empty($result['gad_campaignid'])) {
            $result['utm_campaign'] = $result['gad_campaignid'];
        }
        if (empty($result['utm_source']) && !empty($result['gclid'])) {
            $result['utm_source'] = 'google_ads';
        }
        if (empty($result['utm_medium']) && !empty($result['gclid'])) {
            $result['utm_medium'] = 'cpc';
        }

        return $result;
    }
}
