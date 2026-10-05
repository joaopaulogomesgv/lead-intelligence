# Diretrizes do Agente - Plugin Lead Intelligence

Este documento define as regras, restrições e conhecimento arquitetural obrigatório para qualquer Agente de IA que opere ou evolua o plugin **Lead Intelligence**.

---

## 1. Visão Geral do Projeto

* **Nome do Plugin**: Lead Intelligence
* **Objetivo**: Central independente de inteligência de leads para analisar e qualificar os contatos gerados por campanhas da Meta Ads (Faveni).
* **Ambiente**: WordPress 5.8+ / PHP 7.4 até 8.3+ / MySQL 5.7+ / MariaDB 10.3+.
* **Versão Oficial da Meta Graph API**: `v26.0` (dinâmica).

---

## 2. Regras Críticas e Invioláveis

1. **Garantia de Não-Interferência no InterageZap**:
   - O webhook atual da empresa (InterageZap) **NUNCA** deve ser alterado, substituído, interceptado ou removido.
   - O listener do Elementor (`ElementorListener`) atua estritamente como ouvinte passivo (`priority 15` em `elementor_pro/forms/new_record`). Nunca lance exceptions nem altere a resposta JSON do Elementor.
   - O webhook da Meta Cloud API é **próprio e exclusivo** do nosso plugin: `/wp-json/lead-intelligence/v1/meta/webhook`. A Meta permite múltiplos Meta Apps subscritos à mesma WABA.

2. **Banco de Dados Próprio (Isolamento Total)**:
   - **NÃO** utilize `wp_posts` nem `wp_postmeta` para armazenar leads.
   - Utilize sempre as tabelas dedicadas com prefixo `$wpdb->prefix . 'li_'`:
     - `wp_li_leads` (cadastro mestre de leads)
     - `wp_li_lead_history` (auditoria; nunca sobrescrever dados sem registrar o anterior)
     - `wp_li_whatsapp_messages` (histórico de mensagens do WhatsApp)
     - `wp_li_logs` (logs categorizados)
     - `wp_li_qualification_imports` (histórico de importação de planilhas)
   - Sempre utilize `$wpdb->prepare()` para todas as consultas SQL dinâmicas.

3. **Empacotamento ZIP Seguro (Regra Obrigatória do Windows/Linux)**:
   - **NUNCA** utilize o comando `Compress-Archive` do PowerShell (ele gera barras invertidas `\` que quebram a estrutura no Linux).
   - Execute **SEMPRE**: `py build/package.py`.
   - O script gera caminhos no padrão Unix (`/`), salva na raiz do projeto e copia automaticamente para a Área de Trabalho do usuário (`C:\Users\joao_\OneDrive\Área de Trabalho\lead-intelligence.zip`).

4. **Normalização de Telefones**:
   - Qualquer telefone recebido (Elementor, Planilha ou WhatsApp) deve ser normalizado via `\LeadIntelligence\PhoneNormalizer::normalize($phone)` para o padrão E.164 brasileiro: `55 + DDD (2 dígitos) + 9 dígitos` (13 dígitos).
   - O cruzamento de dados deve ser tolerante a números com ou sem o 9º dígito e com/sem DDI 55 (`get_lookup_variations()`).

5. **Meta Conversions API (CAPI)**:
   - Toda transmissão de dados sensíveis (`email`, `telefone`, `nome`) deve ser hasheada em **SHA-256** em minúsculas e sem espaços conforme a documentação oficial da Meta.
   - Os cookies `_fbc`, `_fbp`, `client_ip_address` e `client_user_agent` **não** devem ser hasheados.
   - Nunca disparar eventos automaticamente se os toggles de CAPI estiverem desligados nas configurações.

---

## 3. Estrutura de Arquivos e Responsabilidades

```
lead-intelligence/
├── lead-intelligence.php                 # Ponto de entrada, defines e hooks de ciclo de vida
├── assets/
│   ├── css/admin-common.css              # Estilos da interface administrativa, cards, badges e modal
│   └── js/utm-preserver.js               # Captura e persistência de UTMs e injeção em formulários
├── includes/
│   ├── class-autoloader.php              # Autoloader PSR-4 para namespace LeadIntelligence\
│   ├── class-activator.php               # Criação de tabelas e opções padrão
│   ├── class-deactivator.php             # Limpeza de crons na desativação
│   ├── class-logger.php                  # Sistema centralizado de logs (info, warning, error, debug)
│   ├── class-phone-normalizer.php        # Normalizador de telefones BR/internacional (+55, 9º dígito)
│   ├── Database/
│   │   ├── class-db-schema.php           # Estrutura SQL das 5 tabelas wp_li_*
│   │   └── class-lead-repository.php     # CRUD de leads, filtros, paginação e histórico
│   ├── Elementor/
│   │   └── class-elementor-listener.php  # Ouvinte passivo no hook elementor_pro/forms/new_record
│   ├── Tracking/
│   │   └── class-utm-tracker.php         # Rastreamento de UTMs e Meta Clicks (Cookies + Server-Side)
│   ├── Qualification/
│   │   ├── class-spreadsheet-parser.php  # Leitor universal ultrarrápido de CSV e XLSX
│   │   └── class-matcher.php             # Cruzamento inteligente (Telefone normalizado > E-mail)
│   ├── WhatsApp/
│   │   ├── class-meta-webhook.php        # Endpoint REST /wp-json/lead-intelligence/v1/meta/webhook
│   │   ├── class-message-handler.php     # Parser de mensagens e vinculação ao lead
│   │   └── class-waba-client.php         # Cliente Meta Graph API v26.0 (teste de conexão)
│   ├── MetaCapi/
│   │   ├── class-capi-service.php        # Montagem de eventos e hash SHA-256 para Meta CAPI
│   │   └── class-capi-queue.php          # Fila assíncrona com retry
│   └── Admin/
│       ├── class-admin-menu.php          # Registro dos menus e submenus
│       ├── class-settings.php            # Tela de configurações com tokens mascarados
│       └── Controllers/
│           ├── class-dashboard-controller.php  # Dashboard de qualidade, CPL e evolução diária
│           ├── class-leads-controller.php      # Lista de leads e modal detalhado
│           ├── class-import-controller.php     # Assistente de upload e De-Para de planilhas
│           ├── class-whatsapp-controller.php   # Painel do WhatsApp e simulador de mensagens
│           ├── class-meta-controller.php       # Painel da Meta CAPI e teste de disparo
│           └── class-logs-controller.php       # Auditoria de logs e limpeza
build/
└── package.py                            # Script Python de empacotamento com caminhos Unix
```

---

## 4. Endpoints e Parâmetros Chave

| Finalidade | Endpoint / Hook | Detalhes |
| :--- | :--- | :--- |
| **Elementor Capture** | `elementor_pro/forms/new_record` | Passivo, try/catch seguro |
| **Meta Webhook (GET)** | `/wp-json/lead-intelligence/v1/meta/webhook` | Valida `hub.verify_token`, ecoa `hub.challenge` |
| **Meta Webhook (POST)**| `/wp-json/lead-intelligence/v1/meta/webhook` | Valida `X-Hub-Signature-256`, processa `messages` e `statuses` |
| **Meta Conversions API**| `https://graph.facebook.com/v26.0/{pixel_id}/events` | Eventos `Lead` e `LeadQualified` |
| **Graph API WhatsApp** | `https://graph.facebook.com/v26.0/{phone_number_id}` | Teste de conexão e envio |

---

## 5. Fluxo de Trabalho Recomendado para Novas Features

1. **Alterou código PHP ou assets?** Sempre execute `py build/package.py` para sincronizar o arquivo `.zip` na Área de Trabalho do usuário.
2. **Adicionou colunas no banco?** Atualize a constante `LEAD_INTELLIGENCE_DB_VERSION` em `lead-intelligence.php` e adicione o campo em `class-db-schema.php` (o método `DbSchema::maybe_update()` rodará o `dbDelta` automaticamente).
3. **Manteve segurança de ponta?** Sempre use `check_admin_referer()` em formulários POST do admin, `sanitize_*` em inputs e `esc_attr` / `esc_html` em outputs.
