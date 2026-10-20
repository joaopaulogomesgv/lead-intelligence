<?php
namespace LeadIntelligence\Admin\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Controller Administrativo para Gestão Dedicada de Polos e Números de WhatsApp (Redirecionador para Configurações)
 */
class PolosController {

    public static function render() {
        wp_safe_redirect(admin_url('admin.php?page=lead-intelligence-settings&tab=whatsapp'));
        exit;
    }
}
