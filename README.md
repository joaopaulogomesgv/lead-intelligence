# Lead Intelligence 🚀

Central própria de inteligência de leads para analisar e qualificar os contatos gerados por campanhas da Meta Ads, com integração nativa ao **Elementor Pro**, **WhatsApp Cloud API**, **Planilhas de Matrículas (CSV/XLSX)** e **Meta Conversions API (CAPI)**.

---

## 📌 Principais Recursos

1. **Captura Nativa e Não-Intrusiva do Elementor Pro**:
   - Atua em `elementor_pro/forms/new_record` como ouvinte passivo.
   - **100% isolado**: Preserva o webhook atual da empresa (InterageZap) sem interferência.
   - Detecção heurística e mapeamento flexível de campos (Nome, E-mail, Telefone, Curso e Área).

2. **Preservação de UTMs e Atribuição Meta Ads**:
   - Script frontend (`utm-preserver.js`) preserva parâmetros de tráfego (`utm_source`, `utm_medium`, `utm_campaign`, `utm_content`, `utm_term`, `fbclid`, `fbc`, `fbp`) em cookies de 30 dias e `localStorage`.
   - Injeção automática em campos ocultos de formulários do Elementor durante toda a navegação.

3. **Importação e Cruzamento Automático de Planilhas**:
   - Leitor universal nativo de arquivos **.CSV** e **.XLSX** (sem dependências pesadas de bibliotecas externas).
   - Assistente visual de mapeamento de colunas com auto-detecção heurística.
   - Cruzamento inteligente priorizando:
     1. **Telefone Normalizado** (padrão brasileiro E.164 com tolerância ao nono dígito e DDI 55).
     2. **E-mail**.
   - Auditoria completa em histórico (`wp_li_lead_history`).

4. **Novo Meta App & Webhook Exclusivo WhatsApp Cloud API**:
   - Endpoint REST próprio: `/wp-json/lead-intelligence/v1/meta/webhook`.
   - Suporte oficial à Meta Graph API `v26.0`.
   - Handshake de verificação GET (`hub.challenge` / `hub.verify_token`).
   - Validação de assinatura criptográfica POST via HMAC-SHA256 (`X-Hub-Signature-256`).
   - Vinculação automática de conversas do WhatsApp ao lead cadastrado no Elementor pelo telefone normalizado.
   - Simulador local de mensagens inbound no painel.

5. **Dashboard de Qualidade & Calculadora de CPL**:
   - Cards de KPIs: Total de leads, Qualificados, Não qualificados, Pendentes e Taxa de qualificação.
   - Calculadora dinâmica de CPL (Custo por Lead) e CPQ (Custo por Lead Qualificado / Matrícula real).
   - Gráfico de evolução diária de captação vs. qualificação.
   - Tabela comparativa de campanhas com destaque para as mais lucrativas (`⭐ Alta Qualidade`).
   - Desempenho por anúncio / criativo.

6. **Meta Conversions API (CAPI v26.0)**:
   - Disparo dos eventos `Lead` e `LeadQualified` (quando a matrícula/qualificação é confirmada).
   - Anonimização com hash **SHA-256** estrito de dados sensíveis (`em`, `ph`, `fn`, `ln`, `country`).
   - Fila assíncrona em segundo plano com retries.
   - Suporte a `test_event_code` para validação em tempo real na aba *"Testar Eventos"* do Gerenciador de Eventos da Meta.

---

## 🛠️ Estrutura do Banco de Dados

O plugin utiliza tabelas dedicadas com o prefixo `$wpdb->prefix . 'li_'` para isolamento total:

* `wp_li_leads`: Cadastro mestre do lead (dados de contato, UTMs, parâmetros Meta Ads, qualificação e WhatsApp).
* `wp_li_lead_history`: Auditoria detalhada de alterações (nunca sobrescreve dados sem histórico).
* `wp_li_whatsapp_messages`: Histórico de mensagens recebidas e enviadas com payload bruto.
* `wp_li_logs`: Logs categorizados do sistema (Elementor, Planilha, WhatsApp, MetaCAPI, Sistema).
* `wp_li_qualification_imports`: Registro de lotes de importação de planilhas.

---

## 📦 Como Empacotar o Plugin

Para gerar o arquivo `.zip` com barras padrão Unix (`/`) compatível com servidores Linux e copiar automaticamente para a Área de Trabalho:

```bash
py build/package.py
```

O arquivo final será gerado em:
* `lead-intelligence.zip` (na raiz do projeto)
* `C:\Users\joao_\OneDrive\Área de Trabalho\lead-intelligence.zip`

---

## 📄 Licença

GPL v2 or later.
