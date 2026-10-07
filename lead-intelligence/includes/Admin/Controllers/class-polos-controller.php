<?php
namespace LeadIntelligence\Admin\Controllers;

use LeadIntelligence\WhatsApp\PoloRepository;
use LeadIntelligence\WhatsApp\WabaClient;
use LeadIntelligence\PhoneNormalizer;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Controller Administrativo para Gestão Dedicada de Polos e Números de WhatsApp
 */
class PolosController {

    public static function render() {
        $action = sanitize_text_field($_GET['action'] ?? 'list');
        $id     = sanitize_key($_GET['id'] ?? '');

        $notice_success = '';
        $notice_error   = '';
        $test_result    = null;

        // ==========================================
        // PROCESSAMENTO DE AÇÕES (POST)
        // ==========================================

        // 1. Salvar Polo (Novo ou Edição)
        if (isset($_POST['li_save_polo'])) {
            check_admin_referer('li_polo_save_verify', 'li_nonce');

            $nome            = sanitize_text_field(wp_unslash($_POST['polo_nome'] ?? ''));
            $phone_number_id = sanitize_text_field(wp_unslash($_POST['polo_phone_number_id'] ?? ''));
            $display_phone   = sanitize_text_field(wp_unslash($_POST['polo_display_phone'] ?? ''));
            $waba_id         = sanitize_text_field(wp_unslash($_POST['polo_waba_id'] ?? ''));
            $access_token    = sanitize_text_field(wp_unslash($_POST['polo_access_token'] ?? ''));
            $status          = sanitize_text_field(wp_unslash($_POST['polo_status'] ?? 'ativo'));
            $is_default      = isset($_POST['polo_is_default']) ? 1 : 0;
            $edit_id         = sanitize_key(wp_unslash($_POST['polo_id'] ?? ''));

            if (empty($nome)) {
                $notice_error = 'O Nome do Polo é obrigatório.';
            } elseif (empty($phone_number_id)) {
                $notice_error = 'O Phone Number ID da Meta é obrigatório.';
            } else {
                $saved_id = PoloRepository::save([
                    'id'              => $edit_id,
                    'nome'            => $nome,
                    'phone_number_id' => $phone_number_id,
                    'display_phone'   => $display_phone,
                    'waba_id'         => $waba_id,
                    'access_token'    => $access_token,
                    'status'          => $status,
                    'is_default'      => $is_default,
                ]);

                $notice_success = 'Polo salvo com sucesso!';
                $action = 'list';
            }
        }

        // 2. Excluir Polo
        if (isset($_POST['li_delete_polo'])) {
            check_admin_referer('li_polo_delete_verify', 'li_nonce');
            $del_id = sanitize_key(wp_unslash($_POST['delete_polo_id'] ?? ''));

            if (!empty($del_id)) {
                PoloRepository::delete($del_id);
                $notice_success = 'Polo excluído com sucesso!';
                $action = 'list';
            }
        }

        // 3. Testar Conexão de um Polo Específico
        if (isset($_POST['li_test_polo'])) {
            check_admin_referer('li_polo_test_verify', 'li_nonce');
            $target_phone_id = sanitize_text_field(wp_unslash($_POST['target_phone_number_id'] ?? ''));
            $target_polo_id  = sanitize_key(wp_unslash($_POST['target_polo_id'] ?? ''));

            $target_polo = PoloRepository::get_by_id($target_polo_id);
            $test_result = WabaClient::test_connection($target_phone_id);
        }

        // Carrega dados da listagem
        $polos = PoloRepository::get_all();
        $webhook_url = get_rest_url(null, 'lead-intelligence/v1/meta/webhook');

        ?>
        <div class="wrap li-wrap">
            <div class="li-header" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 15px;">
                <div>
                    <h2>Lead Intelligence &bull; Polos & Números do WhatsApp</h2>
                    <p class="li-subtitle">Cadastre e gerencie os números de WhatsApp Cloud API para cada polo da instituição de forma totalmente independente.</p>
                </div>
                <?php if ($action === 'list'): ?>
                    <div>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-polos&action=new')); ?>" class="button button-primary li-btn-faveni" style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 16px; height: auto; font-size: 14px;">
                            <span class="dashicons dashicons-plus-alt2" style="font-size: 18px; width: 18px; height: 18px; line-height: 18px;"></span> Cadastrar Novo Polo
                        </a>
                    </div>
                <?php else: ?>
                    <div>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-polos')); ?>" class="button button-secondary" style="display: inline-flex; align-items: center; gap: 6px;">
                            &larr; Voltar para Lista de Polos
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($notice_success)): ?>
                <div class="notice notice-success is-dismissible" style="margin-bottom: 20px;">
                    <p>✔ <strong><?php echo esc_html($notice_success); ?></strong></p>
                </div>
            <?php endif; ?>

            <?php if (!empty($notice_error)): ?>
                <div class="notice notice-error is-dismissible" style="margin-bottom: 20px;">
                    <p>❌ <strong><?php echo esc_html($notice_error); ?></strong></p>
                </div>
            <?php endif; ?>

            <?php if ($test_result): ?>
                <div class="notice <?php echo $test_result['success'] ? 'notice-success' : 'notice-error'; ?> is-dismissible" style="margin-bottom: 20px;">
                    <p>
                        <strong><?php echo $test_result['success'] ? '✔ Conexão Validada:' : '❌ Erro de Conexão:'; ?></strong>
                        <?php echo esc_html($test_result['message']); ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php
            // ==========================================
            // VISUALIZAÇÃO: FORMULÁRIO (NOVO OU EDITAR)
            // ==========================================
            if ($action === 'new' || $action === 'edit'):
                $polo_data = [
                    'id'              => '',
                    'nome'            => '',
                    'phone_number_id' => '',
                    'display_phone'   => '',
                    'waba_id'         => '',
                    'access_token'    => '',
                    'status'          => 'ativo',
                    'is_default'      => empty($polos) ? 1 : 0,
                ];

                $form_title = 'Cadastrar Novo Polo';
                if ($action === 'edit' && !empty($id)) {
                    $found = PoloRepository::get_by_id($id);
                    if ($found) {
                        $polo_data = $found;
                        $form_title = 'Editar Polo: ' . esc_html($polo_data['nome']);
                    }
                }
            ?>
                <div class="li-card" style="max-width: 860px;">
                    <h3 class="li-card-title"><?php echo esc_html($form_title); ?></h3>
                    <p class="li-card-desc">Configure os detalhes da linha de WhatsApp desta unidade. As mensagens recebidas por este número serão atribuídas automaticamente a este polo.</p>

                    <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-polos')); ?>">
                        <?php wp_nonce_field('li_polo_save_verify', 'li_nonce'); ?>
                        <input type="hidden" name="polo_id" value="<?php echo esc_attr($polo_data['id']); ?>">

                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="polo_nome">Nome do Polo / Unidade <span style="color: #ef4444;">*</span></label></th>
                                <td>
                                    <input type="text" id="polo_nome" name="polo_nome" value="<?php echo esc_attr($polo_data['nome']); ?>" class="regular-text" required placeholder="Ex: Polo Ipatinga, Polo Caratinga, Polo Vitória">
                                    <p class="description">Identificação interna e no cadastro dos leads.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="polo_display_phone">Telefone do WhatsApp</label></th>
                                <td>
                                    <input type="text" id="polo_display_phone" name="polo_display_phone" value="<?php echo esc_attr($polo_data['display_phone']); ?>" class="regular-text" placeholder="Ex: +55 (31) 98765-4321">
                                    <p class="description">Número visível da linha para facilitar a conferência na listagem.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="polo_phone_number_id">Phone Number ID (Meta) <span style="color: #ef4444;">*</span></label></th>
                                <td>
                                    <input type="text" id="polo_phone_number_id" name="polo_phone_number_id" value="<?php echo esc_attr($polo_data['phone_number_id']); ?>" class="regular-text" required placeholder="Ex: 1183668341506522" style="font-family: monospace;">
                                    <p class="description">Identificador numérico exclusivo deste número gerado no painel da Meta (Meta Developers &gt; WhatsApp &gt; Configuração da API).</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="polo_waba_id">WABA ID (Opcional)</label></th>
                                <td>
                                    <input type="text" id="polo_waba_id" name="polo_waba_id" value="<?php echo esc_attr($polo_data['waba_id']); ?>" class="regular-text" placeholder="Deixe em branco para usar a WABA global">
                                    <p class="description">Preencha apenas se este polo pertencer a uma WhatsApp Business Account diferente da configurada nas Configurações Gerais.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="polo_access_token">Access Token Específico (Opcional)</label></th>
                                <td>
                                    <input type="password" id="polo_access_token" name="polo_access_token" value="<?php echo !empty($polo_data['access_token']) ? '••••••••••••••••' : ''; ?>" class="large-text" placeholder="Deixe em branco para usar o token global">
                                    <p class="description">Preencha apenas se este polo utilizar um Token de Usuário do Sistema exclusivo. Se deixado vazio, herdará automaticamente o Token Global configurado.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="polo_status">Status do Polo</label></th>
                                <td>
                                    <select id="polo_status" name="polo_status">
                                        <option value="ativo" <?php selected($polo_data['status'], 'ativo'); ?>>🟢 Ativo (Recebendo mensagens e vinculando leads)</option>
                                        <option value="inativo" <?php selected($polo_data['status'], 'inativo'); ?>>⚪ Inativo (Pausado temporariamente)</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Polo Principal / Padrão</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="polo_is_default" value="1" <?php checked(!empty($polo_data['is_default'])); ?>>
                                        Definir como polo principal da instituição (utilizado para envios de testes padrão)
                                    </label>
                                </td>
                            </tr>
                        </table>

                        <p class="submit" style="display: flex; gap: 12px; align-items: center; margin-top: 20px;">
                            <button type="submit" name="li_save_polo" class="button button-primary button-large li-btn-faveni">
                                💾 Salvar Polo
                            </button>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-polos')); ?>" class="button button-large">
                                Cancelar
                            </a>
                        </p>
                    </form>
                </div>

            <?php
            // ==========================================
            // VISUALIZAÇÃO: LISTAGEM DE POLOS
            // ==========================================
            else:
            ?>
                <!-- CARD EXPLICATIVO DE ARQUITETURA -->
                <div class="li-card" style="border-left: 4px solid #10b981; margin-bottom: 20px;">
                    <h3 class="li-card-title" style="margin-bottom: 4px;">🔗 Webhook Unificado da Meta Cloud API</h3>
                    <p class="li-card-desc" style="margin-bottom: 12px;">
                        Você precisa cadastrar a URL de Retorno de Chamada <strong>apenas uma única vez</strong> no Meta Developers. Quando um lead manda mensagem para qualquer um dos seus números, a Meta avisa qual <code>Phone Number ID</code> recebeu o contato e o Lead Intelligence vincula o lead ao polo correspondente instantaneamente!
                    </p>
                    <div style="background: #f8fafc; padding: 10px 14px; border-radius: 6px; border: 1px solid #e2e8f0; display: inline-flex; align-items: center; gap: 12px;">
                        <span style="font-size: 11px; text-transform: uppercase; color: #64748b; font-weight: 600;">Callback URL:</span>
                        <code style="font-size: 13px; color: #0284c7; font-weight: 600;"><?php echo esc_url($webhook_url); ?></code>
                    </div>
                </div>

                <!-- TABELA DE POLOS CADASTRADOS -->
                <div class="li-card" style="padding: 0; overflow-x: auto;">
                    <div style="padding: 16px 20px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center;">
                        <h3 class="li-card-title" style="margin: 0;">Polos Cadastrados (<?php echo count($polos); ?>)</h3>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-polos&action=new')); ?>" class="button button-secondary">
                            ➕ Novo Polo
                        </a>
                    </div>

                    <table class="wp-list-table widefat fixed striped li-table">
                        <thead>
                            <tr>
                                <th style="width: 25%;">Polo / Unidade</th>
                                <th style="width: 18%;">Telefone WhatsApp</th>
                                <th style="width: 20%;">Phone Number ID (Meta)</th>
                                <th style="width: 12%;">WABA / Token</th>
                                <th style="width: 10%;">Status</th>
                                <th style="width: 15%; text-align: right;">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($polos)): ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; padding: 40px; color: #64748b;">
                                        <p style="font-size: 16px; margin: 0 0 10px 0;">Nenhum polo cadastrado ainda.</p>
                                        <p style="margin: 0 0 15px 0;">Cadastre o primeiro polo para começar a rastrear e qualificar leads por unidade.</p>
                                        <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-polos&action=new')); ?>" class="button button-primary li-btn-faveni">
                                            Cadastrar Primeiro Polo
                                        </a>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($polos as $polo): ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight: 700; font-size: 14px; color: #1e293b;">
                                                🏢 <?php echo esc_html($polo['nome']); ?>
                                            </div>
                                            <?php if (!empty($polo['is_default'])): ?>
                                                <div style="margin-top: 4px;">
                                                    <span class="li-badge" style="background: #dbeafe; color: #1e40af; font-size: 11px; font-weight: 600;">
                                                        ★ Polo Principal
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($polo['display_phone'])): ?>
                                                <div style="font-weight: 600; color: #0f172a;">
                                                    📞 <?php echo esc_html(PhoneNormalizer::format_display($polo['display_phone'])); ?>
                                                </div>
                                            <?php else: ?>
                                                <span style="color: #94a3b8; font-size: 12px;">Não informado</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <code style="font-size: 12px; background: #f1f5f9; padding: 3px 6px; border-radius: 4px; color: #334155;">
                                                <?php echo esc_html($polo['phone_number_id']); ?>
                                            </code>
                                        </td>
                                        <td>
                                            <div style="font-size: 11px; color: #64748b;">
                                                WABA: <?php echo !empty($polo['waba_id']) ? '<code>' . esc_html($polo['waba_id']) . '</code>' : '<span style="color:#0284c7;">Global</span>'; ?><br>
                                                Token: <?php echo !empty($polo['access_token']) ? '<span style="color:#10b981;">Específico</span>' : '<span style="color:#0284c7;">Global</span>'; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if (empty($polo['status']) || $polo['status'] === 'ativo'): ?>
                                                <span class="li-badge li-badge-success" style="font-size: 11px;">🟢 Ativo</span>
                                            <?php else: ?>
                                                <span class="li-badge li-badge-neutral" style="font-size: 11px;">⚪ Inativo</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: right;">
                                            <div style="display: inline-flex; align-items: center; gap: 6px;">
                                                <!-- Testar Conexão -->
                                                <form method="post" action="" style="display: inline;">
                                                    <?php wp_nonce_field('li_polo_test_verify', 'li_nonce'); ?>
                                                    <input type="hidden" name="target_polo_id" value="<?php echo esc_attr($polo['id']); ?>">
                                                    <input type="hidden" name="target_phone_number_id" value="<?php echo esc_attr($polo['phone_number_id']); ?>">
                                                    <button type="submit" name="li_test_polo" class="button button-small" title="Testar conexão da linha na Meta">
                                                        ⚡ Testar
                                                    </button>
                                                </form>

                                                <!-- Editar -->
                                                <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-polos&action=edit&id=' . urlencode($polo['id']))); ?>" class="button button-small">
                                                    ✏️ Editar
                                                </a>

                                                <!-- Excluir -->
                                                <form method="post" action="" style="display: inline;" onsubmit="return confirm('Tem certeza que deseja excluir o polo \'<?php echo esc_js($polo['nome']); ?>\'?');">
                                                    <?php wp_nonce_field('li_polo_delete_verify', 'li_nonce'); ?>
                                                    <input type="hidden" name="delete_polo_id" value="<?php echo esc_attr($polo['id']); ?>">
                                                    <button type="submit" name="li_delete_polo" class="button button-small button-link-delete" style="color: #ef4444;" title="Excluir polo">
                                                        🗑️
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
