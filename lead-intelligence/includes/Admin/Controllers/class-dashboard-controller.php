<?php
namespace LeadIntelligence\Admin\Controllers;

use LeadIntelligence\Database\DbSchema;
use LeadIntelligence\Database\LeadRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Controller do Dashboard de Inteligência e Qualidade de Leads
 */
class DashboardController {

    public static function render() {
        global $wpdb;
        $table = DbSchema::get_leads_table();

        // 1. Filtros
        $periodo = isset($_GET['periodo']) ? sanitize_text_field(wp_unslash($_GET['periodo'])) : '30d';
        $filter_campaign = isset($_GET['campanha']) ? sanitize_text_field(wp_unslash($_GET['campanha'])) : '';
        $filter_curso    = isset($_GET['curso']) ? sanitize_text_field(wp_unslash($_GET['curso'])) : '';
        $filter_area     = isset($_GET['area']) ? sanitize_text_field(wp_unslash($_GET['area'])) : '';
        $custom_from     = isset($_GET['from']) ? sanitize_text_field(wp_unslash($_GET['from'])) : '';
        $custom_to       = isset($_GET['to']) ? sanitize_text_field(wp_unslash($_GET['to'])) : '';

        // Cálculo de datas
        $now = current_time('mysql');
        $date_start = '';
        $date_end = $now;

        switch ($periodo) {
            case '7d':
                $date_start = gmdate('Y-m-d 00:00:00', strtotime('-7 days'));
                break;
            case '30d':
                $date_start = gmdate('Y-m-d 00:00:00', strtotime('-30 days'));
                break;
            case 'month':
                $date_start = gmdate('Y-m-01 00:00:00');
                break;
            case 'last_month':
                $date_start = gmdate('Y-m-01 00:00:00', strtotime('first day of last month'));
                $date_end   = gmdate('Y-m-t 23:59:59', strtotime('last day of last month'));
                break;
            case 'custom':
                if (!empty($custom_from)) {
                    $date_start = $custom_from . ' 00:00:00';
                }
                if (!empty($custom_to)) {
                    $date_end = $custom_to . ' 23:59:59';
                }
                break;
            case 'all':
            default:
                $date_start = '';
                break;
        }

        // Construção da cláusula WHERE
        $where = ['1=1'];
        $params = [];

        if (!empty($date_start)) {
            $where[] = "data_cadastro >= %s";
            $params[] = $date_start;
        }
        if (!empty($date_end)) {
            $where[] = "data_cadastro <= %s";
            $params[] = $date_end;
        }
        if (!empty($filter_campaign)) {
            $where[] = "utm_campaign = %s";
            $params[] = $filter_campaign;
        }
        if (!empty($filter_curso)) {
            $where[] = "tipo_curso = %s";
            $params[] = $filter_curso;
        }
        if (!empty($filter_area)) {
            $where[] = "area_interesse = %s";
            $params[] = $filter_area;
        }

        $where_sql = implode(' AND ', $where);

        // 2. Métricas dos Cards
        $metrics_query = "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN qualificacao_status = 'qualificado' THEN 1 ELSE 0 END) as qualificados,
            SUM(CASE WHEN qualificacao_status = 'nao_qualificado' THEN 1 ELSE 0 END) as nao_qualificados,
            SUM(CASE WHEN qualificacao_status = 'pendente' THEN 1 ELSE 0 END) as pendentes
            FROM {$table} WHERE {$where_sql}";

        if (!empty($params)) {
            $metrics_query = $wpdb->prepare($metrics_query, $params);
        }
        $metrics = $wpdb->get_row($metrics_query);

        $total_leads        = $metrics ? (int) $metrics->total : 0;
        $total_qualificados = $metrics ? (int) $metrics->qualificados : 0;
        $total_desqualif    = $metrics ? (int) $metrics->nao_qualificados : 0;
        $total_pendentes    = $metrics ? (int) $metrics->pendentes : 0;

        $taxa_qualificacao = $total_leads > 0 ? round(($total_qualificados / $total_leads) * 100, 1) : 0;

        // 3. Desempenho por Campanha
        $camp_query = "SELECT 
            COALESCE(NULLIF(utm_campaign, ''), 'Direto / Sem Campanha') as campanha,
            COUNT(*) as leads,
            SUM(CASE WHEN qualificacao_status = 'qualificado' THEN 1 ELSE 0 END) as qualificados,
            SUM(CASE WHEN qualificacao_status = 'nao_qualificado' THEN 1 ELSE 0 END) as desqualificados,
            SUM(CASE WHEN qualificacao_status = 'pendente' THEN 1 ELSE 0 END) as pendentes
            FROM {$table} 
            WHERE {$where_sql} 
            GROUP BY campanha 
            ORDER BY qualificados DESC, leads DESC 
            LIMIT 15";

        if (!empty($params)) {
            $camp_query = $wpdb->prepare($camp_query, $params);
        }
        $campaigns = $wpdb->get_results($camp_query);

        // 4. Desempenho por Anúncio / Criativo (ad_name ou utm_content)
        $ad_query = "SELECT 
            COALESCE(NULLIF(ad_name, ''), NULLIF(utm_content, ''), 'Sem Anúncio Identificado') as anuncio,
            COALESCE(NULLIF(utm_campaign, ''), '-') as campanha,
            COUNT(*) as leads,
            SUM(CASE WHEN qualificacao_status = 'qualificado' THEN 1 ELSE 0 END) as qualificados
            FROM {$table} 
            WHERE {$where_sql} 
            GROUP BY anuncio, campanha 
            ORDER BY qualificados DESC, leads DESC 
            LIMIT 10";

        if (!empty($params)) {
            $ad_query = $wpdb->prepare($ad_query, $params);
        }
        $ads = $wpdb->get_results($ad_query);

        // 5. Evolução Diária (Últimos dias)
        $daily_query = "SELECT 
            DATE(data_cadastro) as dia,
            COUNT(*) as total,
            SUM(CASE WHEN qualificacao_status = 'qualificado' THEN 1 ELSE 0 END) as qualificados
            FROM {$table} 
            WHERE {$where_sql} 
            GROUP BY dia 
            ORDER BY dia ASC 
            LIMIT 31";

        if (!empty($params)) {
            $daily_query = $wpdb->prepare($daily_query, $params);
        }
        $daily_evolution = $wpdb->get_results($daily_query);

        // Listas para os filtros dropdown
        $all_campaigns = $wpdb->get_col("SELECT DISTINCT utm_campaign FROM {$table} WHERE utm_campaign != '' ORDER BY utm_campaign ASC");
        $all_cursos    = $wpdb->get_col("SELECT DISTINCT tipo_curso FROM {$table} WHERE tipo_curso != '' ORDER BY tipo_curso ASC");
        $all_areas     = $wpdb->get_col("SELECT DISTINCT area_interesse FROM {$table} WHERE area_interesse != '' ORDER BY area_interesse ASC");

        $max_daily = 1;
        foreach ($daily_evolution as $d) {
            if ($d->total > $max_daily) {
                $max_daily = $d->total;
            }
        }
        ?>
        <div class="wrap li-wrap">
            <div class="li-header" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 15px;">
                <div>
                    <h2>Lead Intelligence &bull; Dashboard de Qualidade</h2>
                    <p class="li-subtitle">Análise avançada da qualidade dos leads gerados pelas campanhas da Meta Ads.</p>
                </div>
                <div>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence-import')); ?>" class="button button-primary button-large">
                        + Importar Planilha de Alunos
                    </a>
                </div>
            </div>

            <!-- BARRA DE FILTROS DO DASHBOARD -->
            <div class="li-card li-filter-bar">
                <form method="get" action="">
                    <input type="hidden" name="page" value="lead-intelligence">

                    <div class="li-filter-row">
                        <div>
                            <select name="periodo" onchange="toggleCustomDates(this.value)">
                                <option value="7d" <?php selected($periodo, '7d'); ?>>Últimos 7 dias</option>
                                <option value="30d" <?php selected($periodo, '30d'); ?>>Últimos 30 dias</option>
                                <option value="month" <?php selected($periodo, 'month'); ?>>Este Mês</option>
                                <option value="last_month" <?php selected($periodo, 'last_month'); ?>>Mês Passado</option>
                                <option value="all" <?php selected($periodo, 'all'); ?>>Todo o Período</option>
                                <option value="custom" <?php selected($periodo, 'custom'); ?>>Personalizado</option>
                            </select>
                        </div>

                        <div id="liCustomDateBox" style="<?php echo ($periodo === 'custom') ? 'display:flex; gap:8px;' : 'display:none;'; ?>">
                            <input type="date" name="from" value="<?php echo esc_attr($custom_from); ?>" placeholder="De">
                            <input type="date" name="to" value="<?php echo esc_attr($custom_to); ?>" placeholder="Até">
                        </div>

                        <?php if (!empty($all_campaigns)): ?>
                            <div>
                                <select name="campanha">
                                    <option value="">Todas as Campanhas</option>
                                    <?php foreach ($all_campaigns as $camp): ?>
                                        <option value="<?php echo esc_attr($camp); ?>" <?php selected($filter_campaign, $camp); ?>>
                                            <?php echo esc_html($camp); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($all_cursos)): ?>
                            <div>
                                <select name="curso">
                                    <option value="">Todos os Cursos</option>
                                    <?php foreach ($all_cursos as $c): ?>
                                        <option value="<?php echo esc_attr($c); ?>" <?php selected($filter_curso, $c); ?>>
                                            <?php echo esc_html($c); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>

                        <button type="submit" class="button button-primary">Aplicar Filtros</button>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=lead-intelligence')); ?>" class="button">Resetar</a>
                    </div>
                </form>
            </div>

            <!-- CARDS DE KPIs PRINCIPAIS -->
            <div class="li-metric-grid">
                <div class="li-metric-card">
                    <span class="li-metric-label">Total de Leads</span>
                    <span class="li-metric-value"><?php echo number_format_i18n($total_leads); ?></span>
                    <span class="li-metric-sub">Elementor &amp; Campanhas</span>
                </div>
                <div class="li-metric-card li-card-success">
                    <span class="li-metric-label">Leads Qualificados</span>
                    <span class="li-metric-value"><?php echo number_format_i18n($total_qualificados); ?></span>
                    <span class="li-metric-sub">Matrículas confirmadas</span>
                </div>
                <div class="li-metric-card li-card-warning">
                    <span class="li-metric-label">Pendentes</span>
                    <span class="li-metric-value"><?php echo number_format_i18n($total_pendentes); ?></span>
                    <span class="li-metric-sub">Aguardando planilha</span>
                </div>
                <div class="li-metric-card li-card-danger">
                    <span class="li-metric-label">Não Qualificados</span>
                    <span class="li-metric-value"><?php echo number_format_i18n($total_desqualif); ?></span>
                    <span class="li-metric-sub">Desqualificados/Recusados</span>
                </div>
                <div class="li-metric-card li-card-info">
                    <span class="li-metric-label">Taxa de Qualificação</span>
                    <span class="li-metric-value" style="color: #0284c7;"><?php echo $taxa_qualificacao; ?>%</span>
                    <span class="li-metric-sub">Média do período</span>
                </div>
            </div>

            <!-- CALCULADORA DE CPL E INVESTIMENTO -->
            <div class="li-card" style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color: #ffffff;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px;">
                    <div>
                        <h3 style="color: #ffffff; margin: 0 0 6px 0; font-size: 16px;">💰 Calculadora de CPL e Custo por Lead Qualificado</h3>
                        <p style="color: #94a3b8; font-size: 13px; margin: 0;">Informe o valor total investido nas campanhas da Meta no período para calcular o custo real de aquisição:</p>
                    </div>
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <span style="font-size: 13px; color: #cbd5e1;">Investimento Total:</span>
                        <input type="number" id="liInvestimentoInput" value="5000" min="0" step="100" style="width: 130px; font-weight: 700; color: #0f172a; text-align: right; padding: 6px 10px; border-radius: 6px;">
                        <button type="button" class="button button-primary" onclick="recalcularCPL()">Calcular</button>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-top: 20px; border-top: 1px solid rgba(255,255,255,0.1); padding-top: 18px;">
                    <div>
                        <span style="font-size: 12px; color: #94a3b8; text-transform: uppercase;">Custo por Lead Geral (CPL)</span>
                        <div id="liCPLGeral" style="font-size: 26px; font-weight: 700; color: #38bdf8; margin-top: 4px;">R$ 0,00</div>
                    </div>
                    <div>
                        <span style="font-size: 12px; color: #94a3b8; text-transform: uppercase;">Custo por Lead Qualificado (CPQ)</span>
                        <div id="liCPLQualificado" style="font-size: 26px; font-weight: 700; color: #4ade80; margin-top: 4px;">R$ 0,00</div>
                    </div>
                    <div>
                        <span style="font-size: 12px; color: #94a3b8; text-transform: uppercase;">Eficiência da Conversão</span>
                        <div id="liEficiencia" style="font-size: 26px; font-weight: 700; color: #fbbf24; margin-top: 4px;"><?php echo $taxa_qualificacao; ?>%</div>
                    </div>
                </div>
            </div>

            <!-- GRÁFICO DE EVOLUÇÃO DIÁRIA -->
            <?php if (!empty($daily_evolution)): ?>
                <div class="li-card">
                    <h3 class="li-card-title">📈 Evolução Diária de Captação vs. Qualificação</h3>
                    <p class="li-card-desc">Volume diário de leads gerados e leads confirmados na qualificação.</p>

                    <div style="display: flex; align-items: flex-end; gap: 8px; height: 180px; padding-top: 20px; overflow-x: auto;">
                        <?php foreach ($daily_evolution as $day): ?>
                            <?php
                            $height_total = round(($day->total / $max_daily) * 140);
                            $height_qual  = $day->total > 0 ? round(($day->qualificados / $day->total) * $height_total) : 0;
                            $day_label    = date_i18n('d/m', strtotime($day->dia));
                            ?>
                            <div style="display: flex; flex-direction: column; align-items: center; flex: 1; min-width: 32px;" title="<?php echo esc_attr("{$day->dia}\nTotal: {$day->total}\nQualificados: {$day->qualificados}"); ?>">
                                <div style="font-size: 10px; color: #64748b; margin-bottom: 4px;"><?php echo $day->total; ?></div>
                                <div style="width: 22px; height: <?php echo max(4, $height_total); ?>px; background: #e2e8f0; border-radius: 4px; position: relative; overflow: hidden; display: flex; align-items: flex-end;">
                                    <div style="width: 100%; height: <?php echo $height_qual; ?>px; background: #10b981; border-radius: 0 0 4px 4px;"></div>
                                </div>
                                <div style="font-size: 10px; color: #94a3b8; margin-top: 6px;"><?php echo esc_html($day_label); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div style="display: flex; gap: 20px; justify-content: center; margin-top: 15px; font-size: 12px; color: #64748b;">
                        <span style="display: flex; align-items: center; gap: 6px;">
                            <span style="width: 12px; height: 12px; background: #e2e8f0; border-radius: 3px; display: inline-block;"></span>
                            Total de Leads
                        </span>
                        <span style="display: flex; align-items: center; gap: 6px;">
                            <span style="width: 12px; height: 12px; background: #10b981; border-radius: 3px; display: inline-block;"></span>
                            Leads Qualificados
                        </span>
                    </div>
                </div>
            <?php endif; ?>

            <!-- RELATÓRIO 1: DESEMPENHO POR CAMPANHA -->
            <div class="li-card" style="padding: 0; overflow-x: auto;">
                <div style="padding: 20px 24px 12px; border-bottom: 1px solid #e2e8f0;">
                    <h3 class="li-card-title">🎯 Qualidade de Leads por Campanha (Meta Ads)</h3>
                    <p class="li-card-desc" style="margin-bottom: 0;">Descubra quais campanhas geram alunos reais com a maior taxa de qualificação e menor desperdício de verba.</p>
                </div>

                <table class="wp-list-table widefat fixed striped li-table">
                    <thead>
                        <tr>
                            <th>Campanha (UTM Campaign)</th>
                            <th style="width: 110px; text-align: center;">Total Leads</th>
                            <th style="width: 120px; text-align: center;">Qualificados</th>
                            <th style="width: 130px; text-align: center;">Não Qualificados</th>
                            <th style="width: 180px;">Taxa de Qualificação</th>
                            <th style="width: 120px; text-align: center;">Classificação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($campaigns)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 35px; color: #64748b;">
                                    Nenhuma campanha identificada no período selecionado.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($campaigns as $camp): ?>
                                <?php
                                $camp_taxa = $camp->leads > 0 ? round(($camp->qualificados / $camp->leads) * 100, 1) : 0;
                                $is_high_quality = ($camp_taxa >= 25 && $camp->leads >= 5);
                                ?>
                                <tr>
                                    <td>
                                        <strong><?php echo esc_html($camp->campanha); ?></strong>
                                    </td>
                                    <td style="text-align: center; font-weight: 600;">
                                        <?php echo number_format_i18n($camp->leads); ?>
                                    </td>
                                    <td style="text-align: center;">
                                        <span style="font-weight: 700; color: #15803d;">
                                            <?php echo number_format_i18n($camp->qualificados); ?>
                                        </span>
                                    </td>
                                    <td style="text-align: center; color: #b91c1c;">
                                        <?php echo number_format_i18n($camp->desqualificados); ?>
                                    </td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <div style="flex: 1; height: 8px; background: #e2e8f0; border-radius: 4px; overflow: hidden;">
                                                <div style="width: <?php echo min(100, $camp_taxa); ?>%; height: 100%; background: <?php echo $camp_taxa >= 20 ? '#10b981' : ($camp_taxa >= 10 ? '#f59e0b' : '#ef4444'); ?>; border-radius: 4px;"></div>
                                            </div>
                                            <span style="font-size: 13px; font-weight: 700; min-width: 45px; text-align: right;">
                                                <?php echo $camp_taxa; ?>%
                                            </span>
                                        </div>
                                    </td>
                                    <td style="text-align: center;">
                                        <?php if ($is_high_quality): ?>
                                            <span class="li-badge li-status-qualificado" title="Campanha com alto retorno de qualificação">⭐ Alta Qualidade</span>
                                        <?php elseif ($camp_taxa < 10 && $camp->leads >= 10): ?>
                                            <span class="li-badge li-status-desqualificado" title="Alto custo por aluno real">⚠️ Baixa Qualidade</span>
                                        <?php else: ?>
                                            <span class="li-badge li-status-cinza">Normal</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- RELATÓRIO 2: QUALIDADE POR ANÚNCIO / CRIATIVO -->
            <div class="li-card" style="padding: 0; overflow-x: auto;">
                <div style="padding: 20px 24px 12px; border-bottom: 1px solid #e2e8f0;">
                    <h3 class="li-card-title">📢 Qualidade por Anúncio / Criativo</h3>
                    <p class="li-card-desc" style="margin-bottom: 0;">Identifique quais criativos atraem o público mais qualificado para a equipe de vendas.</p>
                </div>

                <table class="wp-list-table widefat fixed striped li-table">
                    <thead>
                        <tr>
                            <th>Anúncio / Criativo (ad_name / utm_content)</th>
                            <th>Campanha</th>
                            <th style="width: 110px; text-align: center;">Leads</th>
                            <th style="width: 120px; text-align: center;">Qualificados</th>
                            <th style="width: 150px; text-align: center;">Taxa de Qualificação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($ads)): ?>
                            <tr>
                                <td colspan="5" style="text-align: center; padding: 35px; color: #64748b;">
                                    Nenhum anúncio identificado no período.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($ads as $ad): ?>
                                <?php
                                $ad_taxa = $ad->leads > 0 ? round(($ad->qualificados / $ad->leads) * 100, 1) : 0;
                                ?>
                                <tr>
                                    <td><strong><?php echo esc_html($ad->anuncio); ?></strong></td>
                                    <td><span style="color: #64748b;"><?php echo esc_html($ad->campanha); ?></span></td>
                                    <td style="text-align: center; font-weight: 600;"><?php echo number_format_i18n($ad->leads); ?></td>
                                    <td style="text-align: center; font-weight: 700; color: #15803d;"><?php echo number_format_i18n($ad->qualificados); ?></td>
                                    <td style="text-align: center;">
                                        <span class="li-badge <?php echo $ad_taxa >= 20 ? 'li-status-qualificado' : 'li-status-pendente'; ?>">
                                            <?php echo $ad_taxa; ?>%
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <script>
            function toggleCustomDates(val) {
                var box = document.getElementById('liCustomDateBox');
                if (val === 'custom') {
                    box.style.display = 'flex';
                } else {
                    box.style.display = 'none';
                }
            }

            function recalcularCPL() {
                var investimento = parseFloat(document.getElementById('liInvestimentoInput').value) || 0;
                var totalLeads = <?php echo (int) $total_leads; ?>;
                var qualificados = <?php echo (int) $total_qualificados; ?>;

                var cplGeral = totalLeads > 0 ? (investimento / totalLeads) : 0;
                var cplQual = qualificados > 0 ? (investimento / qualificados) : 0;

                document.getElementById('liCPLGeral').innerText = 'R$ ' + cplGeral.toFixed(2).replace('.', ',');
                document.getElementById('liCPLQualificado').innerText = 'R$ ' + cplQual.toFixed(2).replace('.', ',');
            }

            document.addEventListener('DOMContentLoaded', recalcularCPL);
            </script>
        </div>
        <?php
    }
}
