<?php
namespace LeadIntelligence;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Autoloader PSR-4 para classes do Lead Intelligence
 */
class Autoloader {
    public static function register() {
        spl_autoload_register([__CLASS__, 'autoload']);
    }

    public static function autoload($class) {
        $prefix = 'LeadIntelligence\\';
        $base_dir = LEAD_INTELLIGENCE_PLUGIN_DIR . 'includes/';

        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }

        $relative_class = substr($class, $len);
        $parts = explode('\\', $relative_class);

        $class_name = array_pop($parts);
        // Converte CamelCase para kebab-case: DbSchema -> db-schema, ElementorListener -> elementor-listener
        $kebab = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $class_name));
        $file_name = 'class-' . str_replace('_', '-', $kebab) . '.php';

        $sub_path = '';
        if (!empty($parts)) {
            $sub_path = implode('/', $parts) . '/';
        }

        $file = $base_dir . $sub_path . $file_name;

        if (file_exists($file)) {
            require_once $file;
            return;
        }

        // Fallback direto sem conversão
        $direct_file = $base_dir . $sub_path . 'class-' . strtolower($class_name) . '.php';
        if (file_exists($direct_file)) {
            require_once $direct_file;
        }
    }
}
