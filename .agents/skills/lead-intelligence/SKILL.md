---
name: lead-intelligence
description: >-
  Especialista no plugin WordPress Lead Intelligence. Use esta skill para desenvolver,
  depurar, manter e estender funcionalidades do plugin de inteligência de leads (Elementor Pro,
  WhatsApp Cloud API, Planilhas de Qualificação, Meta Conversions API e Dashboard de Qualidade).
---

# Skill: Especialista no Plugin Lead Intelligence

Esta skill orienta o agente em como inspecionar, desenvolver e empacotar qualquer recurso para o plugin **Lead Intelligence**.

## Princípios Invioláveis
1. **Não tocar no Webhook do InterageZap**: O plugin atua como ouvinte passivo em `elementor_pro/forms/new_record` e possui webhook próprio na Meta (`/wp-json/lead-intelligence/v1/meta/webhook`).
2. **Tabelas Próprias**: Leads são armazenados em `wp_li_leads` e histórico em `wp_li_lead_history`. Nunca use posts/postmeta.
3. **Empacotamento**: Sempre execute `py build/package.py` para gerar o `.zip` com barras Unix (`/`) e salvar na Área de Trabalho.
4. **Meta Graph API**: Padrão `v26.0`.
5. **Normalização de Telefones**: Sempre usar `\LeadIntelligence\PhoneNormalizer::normalize($phone)` (+55 DDD 9 dígitos).

## Módulos Principais

### 1. Elementor Pro
- Arquivo: `includes/Elementor/class-elementor-listener.php`
- Hook: `elementor_pro/forms/new_record` (prioridade 15)
- Extração de campos via heurística e de-para das configurações.

### 2. UTMs e Preservação
- Frontend: `assets/js/utm-preserver.js` (cookies de 30 dias, localStorage, injeção oculta em formulários)
- Backend: `includes/Tracking/class-utm-tracker.php`

### 3. Planilhas de Qualificação
- Parser: `includes/Qualification/class-spreadsheet-parser.php` (CSV e XLSX nativos)
- Cruzamento: `includes/Qualification/class-matcher.php` (Prioridade 1 = Telefone normalizado, Prioridade 2 = E-mail)

### 4. WhatsApp Cloud API
- Webhook: `includes/WhatsApp/class-meta-webhook.php` (`/wp-json/lead-intelligence/v1/meta/webhook`)
- Handshake: GET com `hub.verify_token` e eco de `hub.challenge`
- Eventos: POST com validação HMAC SHA-256 (`X-Hub-Signature-256`)
- Vinculação: `includes/WhatsApp/class-message-handler.php`

### 5. Meta Conversions API (CAPI)
- Serviço: `includes/MetaCapi/class-capi-service.php` (hashing SHA-256 de dados sensíveis)
- Fila assíncrona: `includes/MetaCapi/class-capi-queue.php`
- Eventos: `Lead` e `LeadQualified`

### 6. Interface Administrativa
- Menus: `includes/Admin/class-admin-menu.php`
- Controllers: `includes/Admin/Controllers/` (`DashboardController`, `LeadsController`, `ImportController`, `WhatsAppController`, `MetaController`, `LogsController`)
- Configurações: `includes/Admin/class-settings.php`
- CSS: `assets/css/admin-common.css`

## Procedimento de Empacotamento
Sempre que concluir alterações:
```bash
py build/package.py
```
Isso atualiza `lead-intelligence.zip` na raiz e na Área de Trabalho do usuário.
