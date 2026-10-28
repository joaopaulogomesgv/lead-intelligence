<?php
namespace LeadIntelligence;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Autoloader PSR-4 blindado para classes do Lead Intelligence.
 * Compatível com ambientes Windows e Linux (ext4/xfs case-sensitive).
 */
class Autoloader {

    /**
     * Cache em memória para caminhos de classes já resolvidos
     * @var array<string, string|false>
     */
    private static $resolved_cache = [];

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

        if (array_key_exists($class, self::$resolved_cache)) {
            if (self::$resolved_cache[$class] !== false) {
                require_once self::$resolved_cache[$class];
            }
            return;
        }

        $relative_class = substr($class, $len);
        $parts = explode('\\', $relative_class);
        $class_name = array_pop($parts);

        // Gera candidatos de nome de arquivo
        $kebab = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $class_name));
        $file_candidates = [
            'class-' . str_replace('_', '-', $kebab) . '.php',
            'class-' . str_replace(['whats-app', 'open-ai'], ['whatsapp', 'open-ai'], $kebab) . '.php',
            'class-' . str_replace(['whats-app', 'open-ai'], ['whatsapp', 'openai'], $kebab) . '.php',
            'class-' . strtolower(str_replace('_', '', $class_name)) . '.php',
        ];
        $file_candidates = array_values(array_unique($file_candidates));

        // Gera variações de sub-caminhos (case original, minúsculo, variações de subpastas)
        $sub_paths = [];
        if (!empty($parts)) {
            $orig_path = implode('/', $parts) . '/';
            $lower_path = strtolower($orig_path);
            $sub_paths[] = $orig_path;
            if ($lower_path !== $orig_path) {
                $sub_paths[] = $lower_path;
            }
            // Se houver múltiplos níveis (ex: Admin/Controllers), testa variações mistas
            if (count($parts) > 1) {
                $mixed1 = $parts[0] . '/' . strtolower(implode('/', array_slice($parts, 1))) . '/';
                if (!in_array($mixed1, $sub_paths, true)) {
                    $sub_paths[] = $mixed1;
                }
            }
        } else {
            $sub_paths[] = '';
        }

        // 1. Busca rápida por existência de arquivo
        foreach ($sub_paths as $sub_path) {
            foreach ($file_candidates as $file_name) {
                $file = $base_dir . $sub_path . $file_name;
                if (file_exists($file)) {
                    self::$resolved_cache[$class] = $file;
                    require_once $file;
                    return;
                }
            }
        }

        // 2. Fallback resiliente: resolução de diretórios e arquivos case-insensitive para Linux
        $found_file = self::locate_case_insensitive($base_dir, $parts, $file_candidates);
        if ($found_file && file_exists($found_file)) {
            self::$resolved_cache[$class] = $found_file;
            require_once $found_file;
            return;
        }

        self::$resolved_cache[$class] = false;
    }

    /**
     * Localiza arquivo navegando na hierarquia de diretórios de forma case-insensitive.
     * Garante compatibilidade total em servidores Linux ext4 onde maiúsculas/minúsculas diferem.
     *
     * @param string $base_dir
     * @param array $parts
     * @param array $file_candidates
     * @return string|null
     */
    private static function locate_case_insensitive($base_dir, array $parts, array $file_candidates) {
        $current = rtrim($base_dir, '/\\');

        foreach ($parts as $part) {
            if (!is_dir($current)) {
                return null;
            }

            // Teste rápido exato
            if (is_dir($current . '/' . $part)) {
                $current = $current . '/' . $part;
                continue;
            }

            // Varre diretório procurando match insensível a maiúsculas/minúsculas
            $matched = false;
            $dh = @opendir($current);
            if ($dh) {
                while (($entry = readdir($dh)) !== false) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }
                    if (strcasecmp($entry, $part) === 0 && is_dir($current . '/' . $entry)) {
                        $current = $current . '/' . $entry;
                        $matched = true;
                        break;
                    }
                }
                closedir($dh);
            }

            if (!$matched) {
                return null;
            }
        }

        if (!is_dir($current)) {
            return null;
        }

        // Procura pelos candidatos de arquivo dentro da pasta final
        foreach ($file_candidates as $candidate) {
            if (file_exists($current . '/' . $candidate)) {
                return $current . '/' . $candidate;
            }
        }

        // Varre a pasta final procurando por qualquer arquivo compatível
        $dh = @opendir($current);
        if ($dh) {
            while (($entry = readdir($dh)) !== false) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                foreach ($file_candidates as $candidate) {
                    if (strcasecmp($entry, $candidate) === 0 && is_file($current . '/' . $entry)) {
                        closedir($dh);
                        return $current . '/' . $entry;
                    }
                }
            }
            closedir($dh);
        }

        return null;
    }
}
