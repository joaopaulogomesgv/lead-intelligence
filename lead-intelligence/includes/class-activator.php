<?php
namespace LeadIntelligence;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Executado na ativação do plugin
 */
class Activator {
    public static function activate() {
        // Cria tabelas no banco de dados
        \LeadIntelligence\Database\DbSchema::create_tables();

        // Inicializa configurações padrão caso não existam
        if (false === get_option('lead_intelligence_settings')) {
            $default_settings = [
                'enable_elementor_capture' => 1,
                'enable_debug_logging'     => 1,
                // Mapeamento heurístico padrão de campos do formulário
                'field_mapping'            => [
                    'nome'           => 'nome,name,your-name,first_name,nome_completo',
                    'email'          => 'email,e-mail,your-email,seumelhor_email',
                    'telefone'       => 'telefone,phone,whatsapp,tel,celular,fone,contato',
                    'tipo_curso'     => 'tipo_curso,curso,modalidade,tipo,nivel',
                    'area_interesse' => 'area_interesse,area,interesse,curso_interesse,especializacao',
                ],
                'utm_preservation'         => 1,
                'meta_verify_token'        => wp_generate_password(24, false),
            ];
            update_option('lead_intelligence_settings', $default_settings);
        }

        // Registra log de ativação
        \LeadIntelligence\Logger::info('Sistema', 'Plugin Lead Intelligence ativado com sucesso.');
    }
}
