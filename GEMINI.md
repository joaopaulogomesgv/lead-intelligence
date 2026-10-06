# Guia do Agente Gemini - Plugin Lead Intelligence

Consulte o arquivo [AGENTS.md](file:///c:/Users/joao_/OneDrive/Documentos/Plugin%20de%20Analise%20Faveni/AGENTS.md) para as regras arquiteturais completas.

## Resumo Rápido para a IA:
1. **Webhook InterageZap**: NUNCA alterar, substituir ou remover. O plugin usa ouvinte passivo no Elementor e Webhook próprio (`/wp-json/lead-intelligence/v1/meta/webhook`).
2. **Nova Versão Obrigatória a Cada Atualização**: SEMPRE incrementar a versão do plugin (SemVer) em `lead-intelligence/lead-intelligence.php` (no cabeçalho `Version:` e na constante `LEAD_INTELLIGENCE_VERSION`) ANTES de empacotar.
3. **Empacotamento Seguro (ZIP/WinRAR)**: SEMPRE rodar `python build/package.py` para gerar o `.zip` com barras Unix (`/`) e salvar automaticamente na Área de Trabalho.
4. **Banco de Dados**: Usar exclusivamente as tabelas `$wpdb->prefix . 'li_*'`, sem poluir `wp_posts` ou `wp_postmeta`.
5. **Telefones**: Normalização via `\LeadIntelligence\PhoneNormalizer::normalize()` (+55 DDD 9 dígitos).
6. **Meta Graph API**: Versão padrão `v26.0`.
7. **Meta Conversions API**: Hash SHA-256 em dados sensíveis e fila assíncrona.
