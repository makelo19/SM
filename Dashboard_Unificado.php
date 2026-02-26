<?php 
// --- MOTOR DE DADOS --- 
if (isset($_GET['api'])) { 
    header('Content-Type: application/json; charset=utf-8'); 
    $arquivo = __DIR__ . '/historico_atendimento.txt'; 
    if (!file_exists($arquivo)) { echo json_encode([]); exit; } 
     
    $linhas = file($arquivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES); 
    $headers = array_map('trim', explode("\t", array_shift($linhas))); 
     
    // --- IDENTIFICAÇÃO DA COLUNA DE VALOR --- 
    $colunaValorNoTxt = 'Valores'; 
    foreach ($headers as $h) { 
        if (stripos($h, 'valor') !== false) { 
            $colunaValorNoTxt = $h; 
            break; 
        } 
    } 
     
    // --- UNIFICAÇÃO DE COLUNAS CASE-INSENSITIVE ---
    $normalizedHeaders = [];
    $seenHeaders = [];
    foreach ($headers as $i => $h) {
        $lower = mb_strtolower($h);
        if (!isset($seenHeaders[$lower])) {
            $seenHeaders[$lower] = $h;
        }
        $normalizedHeaders[$i] = $seenHeaders[$lower];
    }

    $data = []; 
    foreach ($linhas as $linha) { 
        $cols = explode("	", $linha); 
        if(count($cols) < count($headers)) continue; 
        
        $item = [];
        foreach ($cols as $i => $val) {
            if (isset($normalizedHeaders[$i])) {
                $key = $normalizedHeaders[$i];
                // Prioriza valor não vazio
                if (!isset($item[$key]) || $item[$key] === '') {
                    $item[$key] = $val;
                }
            }
        }
         
        $valOriginal = $item[$colunaValorNoTxt] ?? '0'; 
        $valLimpo = str_replace(['R$', '.', ' '], '', $valOriginal); 
        $item['_val'] = floatval(str_replace(',', '.', $valLimpo)); 
        $item['Valores'] = $valOriginal; 
         
        try { 
            $dtStart = new DateTime(str_replace('/', '-', $item['Data'])); 
            $item['_ts'] = $dtStart->getTimestamp(); 
            $item['_dt_iso'] = $dtStart->format('Y-m-d'); 
            $item['_month'] = $dtStart->format('m'); 
            $item['_year'] = $dtStart->format('Y'); 
             
            $dtEnd = !empty($item['Data atual']) ? new DateTime(str_replace('/', '-', $item['Data atual'])) : clone $dtStart; 
            $item['_ts_end'] = $dtEnd->getTimestamp(); 
        } catch (Exception $e) { $item['_ts'] = 0; } 
        $data[] = $item; 
    } 
    echo json_encode($data); exit; 
} 
?> 
 
<!DOCTYPE html> 
<html lang="pt-BR"> 
<head> 
    <meta charset="UTF-8"> 
    <title>BI AUDITOR - SISTEMA ATUALIZADO</title> 
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script> 
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script> 
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script> 
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"> 
 
<style> 
:root { 
  --bg: #f2f2f2; 
  --primary: #2563eb; 
  --accent: #f7a049; 
  --success: #10b981; 
  --danger: #ef4444; 
  --warning: #f59e0b;
} 

.navbar-superior-fixa {
    position: fixed; top: 0; left: 0; width: 100%; height: 60px;
    background-color: #003366; display: flex; align-items: center;
    justify-content: space-between; padding: 0 25px; box-sizing: border-box;
    z-index: 10000; box-shadow: 0 2px 10px rgba(0,0,0,0.3); font-family: Arial, sans-serif;
}
.navbar-superior-fixa .titulo-nav { color: #fff; font-size: 18px; font-weight: bold; }
.navbar-superior-fixa .usuario-nav { color: #fff; font-size: 14px; font-weight: 600; }

body { font-family: 'Arial', sans-serif; background: var(--bg); margin: 0; padding: 20px; color: #333; } 
.filter-grid { background: #fff; padding: 20px; border-radius: 12px; border: 1px solid #e5e7eb; margin-bottom: 20px; display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; } 
.f-group { display: flex; flex-direction: column; gap: 5px; } 
.f-label { font-size: 11px; font-weight: 700; color: #6b7280; text-transform: uppercase; } 
.f-input { padding: 8px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 13px; } 
 
.flag-box { background: #fff7ed; border: 1px solid #fed7aa; border-radius: 8px; height: 100px; overflow-y: auto; padding: 8px; } 
.flag-item { display: flex; align-items: center; gap: 8px; font-size: 11px; cursor: pointer; padding: 2px; } 
 
.metric-selector { display: flex; gap: 10px; margin-bottom: 20px; background: #fff; padding: 10px; border-radius: 12px; border: 1px solid #e5e7eb; } 
.m-btn { flex: 1; border: none; padding: 12px; border-radius: 8px; cursor: pointer; font-weight: 700; background: #f3f4f6; color: #6b7280; } 
.m-btn.active { background: var(--primary); color: white; } 
 
.kpi-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 15px; margin-bottom: 20px; } 
.kpi-card { background: #fff; padding: 20px; border-radius: 12px; border-top: 4px solid var(--accent); cursor: pointer; transition: transform 0.2s; } 
.kpi-card:hover { transform: translateY(-3px); } 
.kpi-val { font-size: 26px; font-weight: 800; margin: 5px 0; } 
.kpi-sub { font-size: 12px; color: #6b7280; } 
 
.charts-stack { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; } 
.chart-card { background: #fff; padding: 20px; border-radius: 12px; border: 1px solid #e5e7eb; grid-column: span 2; } 
.chart-header { display: flex; justify-content: space-between; align-items: flex-start; border-left: 5px solid var(--accent); padding-left: 15px; margin-bottom: 15px; } 
 
.chart-scroll-box { width: 100%; max-height: 550px; overflow-y: auto; border: 1px solid #f1f5f9; } 
 
.matrix-card { background: #fff; border-radius: 12px; border: 1px solid #e5e7eb; margin-top: 25px; overflow: hidden; } 
table { width: 100%; border-collapse: collapse; } 
th { background: var(--primary); padding: 15px; text-align: left; font-size: 11px; color: #fff; text-transform: uppercase; } 
td { padding: 12px 15px; border-bottom: 1px solid #f1f5f9; font-size: 13px; } 
.row-p { font-weight: 700; cursor: pointer; } 
.row-c { display: none; background: #fff7ed; } 
.row-c.show { display: table-row; } 
 
.container-botoes { display: flex; justify-content: center; gap: 10px; margin-top: 20px; } 
.btn-refresh { background: var(--success); color: white; border: none; padding: 8px 15px; border-radius: 8px; cursor: pointer; font-weight: bold; font-size: 12px; } 
.btn-clear { background: var(--danger); color: white; border: none; padding: 8px 15px; border-radius: 8px; cursor: pointer; font-weight: bold; font-size: 12px; } 
.btn-excel { background: #1d7044; color: white; border: none; padding: 8px 15px; border-radius: 8px; cursor: pointer; font-weight: bold; font-size: 12px; } 

/* ======================================= */
/* 🔵 NOVO: ESTILO DO MODAL GRANDE E BUSCA */
/* ======================================= */
.modal-excel {
    display: none; position: fixed; z-index: 20000; left: 0; top: 0; width: 100%; height: 100%;
    background-color: rgba(0,0,0,0.5); align-items: center; justify-content: center;
}
.modal-content {
    background: white; padding: 25px; border-radius: 12px; 
    width: 90%; /* Largura grande */
    max-width: 1000px; /* Limite máximo largo */
    height: 85vh; /* Altura de 85% da tela */
    box-shadow: 0 5px 30px rgba(0,0,0,0.3);
    display: flex; flex-direction: column; /* Organização vertical */
}
.modal-header { 
    font-weight: bold; font-size: 20px; margin-bottom: 15px; color: var(--primary); 
    border-bottom: 2px solid #eee; padding-bottom: 10px; flex-shrink: 0;
}
.excel-tools {
    display: flex; gap: 10px; margin-bottom: 15px; align-items: center; flex-shrink: 0;
    background: #f8fafc; padding: 10px; border-radius: 8px; border: 1px solid #e2e8f0;
}
.search-excel-input {
    flex: 1; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px;
}
.btn-action-sm {
    padding: 8px 15px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 12px;
    background: #e2e8f0; color: #475569; transition: all 0.2s;
}
.btn-action-sm:hover { background: #cbd5e1; color: #1e293b; }
.btn-confirm-excel { 
    background: #1d7044; color: white; border: none; padding: 12px 20px; border-radius: 8px; 
    cursor: pointer; font-weight: bold; width: 100%; margin-top: 15px; font-size: 14px; flex-shrink: 0;
}
.btn-cancel-excel { 
    background: #ef4444; color: white; border: none; padding: 12px 20px; border-radius: 8px; 
    cursor: pointer; font-weight: bold; width: 100%; margin-top: 10px; font-size: 14px; flex-shrink: 0;
}

/* Lista de colunas com Grid Responsivo denso */
.column-list { 
    flex: 1; /* Ocupa o resto do espaço */
    display: grid; 
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); /* Colunas adaptáveis */
    gap: 8px; 
    overflow-y: auto; 
    padding: 10px; background: #f9fafb; border-radius: 8px; border: 1px solid #e5e7eb;
    align-content: start;
}
/* Item da lista */
.col-label {
    display: flex; align-items: center; gap: 8px; font-size: 12px; 
    padding: 6px; background: white; border: 1px solid #e5e7eb; border-radius: 4px;
    cursor: pointer; user-select: none;
}
.col-label:hover { border-color: var(--primary); background: #eff6ff; }
.col-label input { accent-color: var(--primary); transform: scale(1.1); }
</style> 
</head> 
<body> 

<div class="navbar-superior-fixa">
    <div class="titulo-nav">📈 Dashboard</div>
    <div class="usuario-nav">👤 <?php echo $nome_completo ?? 'Usuário'; ?></div>
</div>

<br/><br/><br/>

<div class="metric-selector"> 
    <button class="m-btn active" id="m_qtd" onclick="setMetric('qtd')">➕ VOLUMETRIA (QTD)</button> 
    <button class="m-btn" id="m_val" onclick="setMetric('val')">💰 VALORES (R$)</button> 
    <button class="m-btn" id="m_tma" onclick="setMetric('tma')">⏰ TMA INTERVALO (MIN)</button> 
</div> 

<div class="kpi-row"> 
    <div class="kpi-card" onclick="filterByCategory('')"><div class="f-label">Total</div><div class="kpi-val" id="k_g_main">0</div><div id="k_g_sub" class="kpi-sub">---</div></div> 
    <div class="kpi-card" onclick="filterByCategory('Procedente')" style="border-top-color:var(--success)"><div class="f-label">Procedentes</div><div class="kpi-val" id="k_p_main" style="color:var(--success)">0</div><div id="k_p_sub" class="kpi-sub">---</div></div> 
    <div class="kpi-card" onclick="filterByCategory('Parcialmente Procedente')" style="border-top-color:var(--warning)"><div class="f-label">Parcialmente Procedentes</div><div class="kpi-val" id="k_pp_main" style="color:var(--warning)">0</div><div id="k_pp_sub" class="kpi-sub">---</div></div>
    <div class="kpi-card" onclick="filterByCategory('Improcedente')" style="border-top-color:var(--danger)"><div class="f-label">Improcedentes</div><div class="kpi-val" id="k_i_main" style="color:var(--danger)">0</div><div id="k_i_sub" class="kpi-sub">---</div></div> 
</div> 

<div class="filter-grid"> 
    <div class="f-group"> 
        <label class="f-label">Data e Hora (Início/Fim)</label> 
        <input type="datetime-local" id="f_start" class="f-input" onchange="applyFilters()"> 
        <input type="datetime-local" id="f_end" class="f-input" onchange="applyFilters()" style="margin-top:5px"> 
    </div> 
    <div class="f-group"> 
        <label class="f-label">Período</label> 
        <select id="f_period" class="f-input" onchange="applyFilters()"> 
            <option value="">Todos</option> 
            <option value="HOJE" selected>HOJE</option> 
            <option value="01">Janeiro</option><option value="02">Fevereiro</option><option value="03">Março</option> 
            <option value="04">Abril</option><option value="05">Maio</option><option value="06">Junho</option> 
            <option value="07">Julho</option><option value="08">Agosto</option><option value="09">Setembro</option> 
            <option value="10">Outubro</option><option value="11">Novembro</option><option value="12">Dezembro</option> 
        </select> 
        <select id="f_year" class="f-input" onchange="applyFilters()" style="margin-top:5px"><option value="">Todos os Anos</option></select> 
    </div> 
    <div class="f-group" style="grid-column: span 2;"> 
        <label class="f-label">Equipe (Filtro por Usuário)</label> 
        <div id="flags_user" class="flag-box" style="height: 60px; display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 5px;"></div> 
    </div> 
</div> 

<div class="container-botoes"> 
    <button class="btn-excel" onclick="openExcelModal()"><i class="fas fa-file-excel"></i> EXCEL PERSONALIZADO</button> 
    <button class="btn-refresh" onclick="init()"><i class="fas fa-sync-alt"></i> ATUALIZAR DADOS</button> 
    <button class="btn-clear" onclick="resetAll()"><i class="fas fa-eraser"></i> LIMPAR TUDO</button> 
</div> 

<div class="charts-stack"> 
    <div class="chart-card"> 
        <div class="chart-header"> 
            <div><p style="font-weight:800; margin:0">RANKING EQUIPE</p></div> 
            <select id="f_limit_user" class="f-input" onchange="renderUserChart()" style="border:1px solid var(--primary); font-weight:bold;"> 
                <option value="10" selected>Top 10</option> 
                <option value="999999">Todos</option> 
            </select> 
        </div> 
        <div class="chart-scroll-box"><div id="cont_user" class="chart-inner"><canvas id="c_user"></canvas></div></div> 
    </div> 
    <div class="chart-card"> 
        <div class="chart-header"> 
            <div><p style="font-weight:800; margin:0">DISTRIBUIÇÃO POR TEMAS</p></div> 
            <div id="flags_title" class="flag-box" style="width:300px; height:40px"></div> 
            <div id="flags_theme" class="flag-box" style="width:300px; height:40px"></div> 
            <div style="display:flex; gap:10px; align-items:center;"> 
                <select id="f_tipo_fechamento" class="f-input" onchange="applyFilters()" style="border:1px solid var(--accent); font-weight:bold; max-width: 150px;"> 
                    <option value="">Todos Tipos</option> 
                </select> 
            </div> 
        </div> 
        <div class="chart-scroll-box"><div id="cont_theme" class="chart-inner"><canvas id="c_theme"></canvas></div></div> 
    </div> 
</div> 

<div class="matrix-card"> 
    <table id="matrix"> 
        <thead><tr><th>Estrutura Hierárquica</th><th>QTD</th><th>Financeiro</th><th>TMA</th></tr></thead> 
        <tbody></tbody> 
    </table> 
</div>

<div id="modalExcel" class="modal-excel">
    <div class="modal-content">
        <div class="modal-header">Selecione as Colunas do Relatório</div>
        
        <div class="excel-tools">
            <input type="text" id="excelSearch" class="search-excel-input" placeholder="🔍 Pesquisar coluna..." onkeyup="filterExcelColumns()">
            <button class="btn-action-sm" onclick="toggleSelectAll(true)">Marcar Visíveis</button>
            <button class="btn-action-sm" onclick="toggleSelectAll(false)">Desmarcar Visíveis</button>
        </div>

        <div id="excelColumnList" class="column-list">
            </div>

        <button class="btn-confirm-excel" onclick="processExcelExport()">GERAR EXCEL</button>
        <button class="btn-cancel-excel" onclick="closeExcelModal()">CANCELAR</button>
    </div>
</div>

<script> 
Chart.register(ChartDataLabels); 
let rawData = [], filteredData = [], activeMetric = 'qtd', charts = {}, activeCategory = ''; 
const money = (v) => v.toLocaleString('pt-br',{style:'currency',currency:'BRL'}); 

async function init() { 
    const res = await fetch('?api=1'); 
    rawData = await res.json(); 
    const users = [...new Set(rawData.map(d => d.usuarioLogado))].sort(); 
    document.getElementById('flags_user').innerHTML = users.map(u => `<label class="flag-item"><input type="checkbox" value="${u}" onchange="applyFilters()"> ${u}</label>`).join(''); 
    const tipos = [...new Set(rawData.map(d => d['Tipo/Fechamento']).filter(t => t))].sort(); 
    document.getElementById('f_tipo_fechamento').innerHTML = '<option value="">Todos Tipos</option>' + tipos.map(t => `<option value="${t}">${t}</option>`).join(''); 
    const anosValidos = [...new Set(rawData.map(d => d._year))].filter(y => y && y !== "0").sort((a,b) => b-a); 
    const sy = document.getElementById('f_year'); 
    sy.innerHTML = '<option value="">Todos os Anos</option>' + anosValidos.map(y => `<option value="${y}">${y}</option>`).join(''); 
    const anoAtual = new Date().getFullYear().toString(); 
    if (anosValidos.includes(anoAtual)) sy.value = anoAtual; 
    applyFilters(); 
} 

function applyFilters() { 
    const vS = document.getElementById('f_start').value, vE = document.getElementById('f_end').value; 
    const tsS = vS ? new Date(vS).getTime()/1000 : null, tsE = vE ? new Date(vE).getTime()/1000 : null; 
    const period = document.getElementById('f_period').value, year = document.getElementById('f_year').value; 
    const selU = Array.from(document.querySelectorAll('#flags_user input:checked')).map(i => i.value); 
    const selTipo = document.getElementById('f_tipo_fechamento').value; 
    const today = new Date().toISOString().split('T')[0]; 

    const tBox = document.getElementById('flags_title'), thBox = document.getElementById('flags_theme'); 
    const selT = tBox ? Array.from(tBox.querySelectorAll('input:checked')).map(i => i.value) : []; 
    const selTh = thBox ? Array.from(thBox.querySelectorAll('input:checked')).map(i => i.value) : []; 

    filteredData = rawData.filter(d => { 
        if(year && d._year !== year) return false; 
        if(tsS || tsE) { 
            if(tsS && d._ts < tsS) return false; 
            if(tsE && d._ts > tsE) return false; 
        } else { 
            if(period === "HOJE") { if(d._dt_iso !== today) return false; } 
            else if(period && d._month !== period) return false; 
        } 
        if(selU.length > 0 && !selU.includes(d.usuarioLogado)) return false; 
        if(activeCategory && d.Categoria !== activeCategory) return false; 
        if(selTipo && d['Tipo/Fechamento'] !== selTipo) return false; 
        if(selT.length > 0) { 
            const dTs = (d['Título(s)'] || '').split(';').map(x => x.trim()); 
            if(!dTs.some(t => selT.includes(t))) return false; 
        } 
        if(selTh.length > 0) { 
            const dThs = (d['Tema(s)'] || '').split(';').map(x => x.trim()); 
            if(!dThs.some(tm => selTh.includes(tm))) return false; 
        } 
        return true; 
    }); 
    updateFlags(true);  
    renderAll(); 
} 

// --- FUNÇÕES DE EXCEL COM BUSCA E SELEÇÃO AVANÇADA ---

function openExcelModal() {
    if (filteredData.length === 0) { alert("Não há dados para exportar."); return; }
    
    // Obtém todas as colunas
    const todasColunas = Object.keys(rawData[0]).filter(key => key !== 'ID' && !key.startsWith('_'));
    
    // Reseta a busca
    document.getElementById('excelSearch').value = '';

    const container = document.getElementById('excelColumnList');
    container.innerHTML = todasColunas.map(col => `
        <label class="col-label">
            <input type="checkbox" class="col-check" value="${col}" checked> 
            <span>${col}</span>
        </label>
    `).join('');
    
    document.getElementById('modalExcel').style.display = 'flex';
}

function closeExcelModal() { document.getElementById('modalExcel').style.display = 'none'; }

// Filtra colunas na tela (Case Insensitive)
function filterExcelColumns() {
    const term = document.getElementById('excelSearch').value.toLowerCase();
    const labels = document.querySelectorAll('.col-label');
    
    labels.forEach(label => {
        const text = label.textContent.toLowerCase();
        if (text.includes(term)) {
            label.style.display = 'flex'; // Mostra se der match
        } else {
            label.style.display = 'none'; // Esconde se não der
        }
    });
}

// Marca/Desmarca APENAS OS VISÍVEIS (filtrados)
function toggleSelectAll(status) {
    const labels = document.querySelectorAll('.col-label');
    labels.forEach(label => {
        // Checa se o elemento está visível (display != none)
        if (label.style.display !== 'none') {
            const checkbox = label.querySelector('input');
            checkbox.checked = status;
        }
    });
}

function processExcelExport() {
    // Pega apenas os marcados
    const colunasSelecionadas = Array.from(document.querySelectorAll('.col-check:checked')).map(cb => cb.value);
    
    if (colunasSelecionadas.length === 0) {
        alert("Selecione ao menos uma coluna.");
        return;
    }

    const formatarDataBR = (dataStr) => { 
        if (!dataStr || typeof dataStr !== 'string') return dataStr; 
        if (dataStr.includes('T') && dataStr.includes('Z')) { 
            const d = new Date(dataStr); 
            return `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}/${d.getFullYear()} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`; 
        } 
        return dataStr; 
    }; 

    const listaFinal = filteredData.map(item => { 
        let novaLinha = {}; 
        colunasSelecionadas.forEach(col => { 
            let valor = item[col] || ""; 
            if (col.toLowerCase().includes("data") && !col.toLowerCase().includes("atual")) { 
                valor = formatarDataBR(valor); 
            } 
            novaLinha[col] = valor; 
        }); 
        return novaLinha; 
    }); 

    const ws = XLSX.utils.json_to_sheet(listaFinal, { header: colunasSelecionadas }); 
    ws['!cols'] = colunasSelecionadas.map(c => ({ wch: 25 })); 
    const wb = XLSX.utils.book_new(); 
    XLSX.utils.book_append_sheet(wb, ws, "Relatorio"); 
    const d = new Date().toLocaleDateString('pt-BR').replace(/\//g, '-'); 
    XLSX.writeFile(wb, `relatorio_personalizado_${d}.xlsx`);
    closeExcelModal();
}

// --- RESTO DAS FUNÇÕES DE RENDERIZAÇÃO (MANTIDAS) ---

function draw(id, containerId, ds, color, showP) { 
    if(charts[id]) charts[id].destroy(); 
    const container = document.getElementById(containerId); 
    const canvas = document.getElementById(id); 
    const calcHeight = Math.max(400, ds.length * 45); 
    container.style.height = calcHeight + "px"; 
    canvas.height = calcHeight; 

    charts[id] = new Chart(canvas, { 
        type: 'bar', 
        data: { labels: ds.map(x => x.n), datasets: [{ data: ds.map(x => x.v), backgroundColor: color, borderRadius: 5, barThickness: 25 }] }, 
        options: { 
            indexAxis: 'y', maintainAspectRatio: false, layout: { padding: { right: 110 } }, 
            plugins: { legend: { display: false }, datalabels: { anchor: 'end', align: 'right', color: '#1e293b', font: { weight: 'bold', size: 11 }, formatter: (v, ctx) => activeMetric === 'val' ? money(v) : (showP ? ds[ctx.dataIndex].p : formatValue(v)) } }, 
            scales: { x: { display: false }, y: { grid: { display: false }, ticks: { autoSkip: false } } } 
        } 
    }); 
} 

function renderUserChart() { 
    const group = {}, unique = {}; 
    const limit = parseInt(document.getElementById('f_limit_user').value); 
    filteredData.forEach(d => { if(!unique[d.ID]) unique[d.ID] = d; }); 
    Object.values(unique).forEach(d => { 
        const u = d.usuarioLogado; 
        if(!group[u]) group[u] = { q:0, v:0, t:0, c:0 }; 
        group[u].q++; group[u].v += d._val; group[u].t += d._tma; group[u].c++; 
    }); 
    let ds = Object.entries(group).map(([n, o]) => ({ n, v: activeMetric === 'qtd' ? o.q : (activeMetric === 'val' ? o.v : o.t/o.c) })).sort((a,b) => b.v - a.v); 
    ds = ds.slice(0, limit); 
    draw('c_user', 'cont_user', ds, '#2563eb', false); 
} 

function renderThemeChart() { 
    const group = {}; let total = 0; 
    filteredData.forEach(d => { 
        const tms = (d['Tema(s)'] || '').split(';').map(x => x.trim()); 
        tms.forEach(tm => { 
            if(!tm) return; 
            if(!group[tm]) group[tm] = { v:0 }; 
            const part = (activeMetric === 'val' ? (d._val / tms.length) : (activeMetric === 'qtd' ? 1/tms.length : d._tma/tms.length)); 
            group[tm].v += part; total += part; 
        }); 
    }); 
    let ds = Object.entries(group).map(([n, o]) => ({ n, v: o.v, p: total > 0 ? ((o.v / total) * 100).toFixed(1) + '%' : '0%' })).sort((a,b) => b.v - a.v).slice(0, 15); 
    draw('c_theme', 'cont_theme', ds, '#f28c00', true); 
} 

function formatValue(v) { 
    if(activeMetric === 'val') return money(v); 
    if(activeMetric === 'tma') return Math.round(v) + " min"; 
    return Math.round(v); 
} 

function updateKPIs() { 
    const getU = (data) => { 
        if (data.length === 0) return { q: 0, v: 0, t: 0 }; 
        const uniqueArr = Object.values(data.reduce((acc, d) => { if (!acc[d.ID]) acc[d.ID] = d; return acc; }, {})).sort((a, b) => a._ts - b._ts); 
        const totalVal = uniqueArr.reduce((a, b) => a + b._val, 0); 
        let totalMinutos = 0; 
        for (let i = 0; i < uniqueArr.length; i++) { 
            let diff = (i < uniqueArr.length - 1) ? uniqueArr[i+1]._ts - uniqueArr[i]._ts : uniqueArr[i]._ts_end - uniqueArr[i]._ts; 
            totalMinutos += (diff > 0 && diff < 3600) ? diff / 60 : 5; 
        } 
        return { q: uniqueArr.length, v: totalVal, t: Math.round(totalMinutos / (uniqueArr.length || 1)) }; 
    }; 
    const g = getU(filteredData), p = getU(filteredData.filter(d => d.Categoria === 'Procedente')), i = getU(filteredData.filter(d => d.Categoria === 'Improcedente')), pp = getU(filteredData.filter(d => d.Categoria === 'Parcialmente Procedente')); 
    const setK = (id, obj) => { 
        document.getElementById(`k_${id}_main`).innerText = activeMetric === 'val' ? money(obj.v) : (activeMetric === 'tma' ? obj.t+" min" : obj.q); 
        document.getElementById(`k_${id}_sub`).innerText = `R$: ${money(obj.v)} | TMA: ${obj.t} min`; 
    }; 
    setK('g', g); setK('p', p); setK('i', i); setK('pp', pp); 
} 

function updateFlags(isMasterFilter = false) { 
    const tBox = document.getElementById('flags_title'), thBox = document.getElementById('flags_theme'); 
    const prevT = Array.from(tBox.querySelectorAll('input:checked')).map(i => i.value); 
    const prevTh = Array.from(thBox.querySelectorAll('input:checked')).map(i => i.value); 
    const titles = new Set(), themes = new Set(); 
    if(isMasterFilter){ 
        filteredData.forEach(d => { 
            (d['Título(s)'] || '').split(';').forEach(t => titles.add(t.trim())); 
            (d['Tema(s)'] || '').split(';').forEach(t => themes.add(t.trim())); 
        }); 
        tBox.innerHTML = [...titles].sort().filter(x=>x).map(t => `<label class="flag-item"><input type="checkbox" value="${t}" ${prevT.includes(t)?'checked':''} onchange="applyFilters()"> ${t}</label>`).join(''); 
        thBox.innerHTML = [...themes].sort().filter(x=>x).map(t => `<label class="flag-item"><input type="checkbox" value="${t}" ${prevTh.includes(t)?'checked':''} onchange="applyFilters()"> ${t}</label>`).join(''); 
    } 
} 

function renderMatrix() { 
    const matrix = {}; 
    filteredData.forEach(d => { 
        const tits = (d['Título(s)'] || 'Outros').split(';').map(x => x.trim()); 
        tits.forEach(tit => { 
            if(!tit) return; 
            if(!matrix[tit]) matrix[tit] = { q:0, v:0, t:0, c:0, temas:{} }; 
            const tms = (d['Tema(s)'] || 'Geral').split(';').map(x => x.trim()); 
            matrix[tit].v += (d._val/tits.length); matrix[tit].t += d._tma; matrix[tit].c++; matrix[tit].q += (1/tits.length); 
            tms.forEach(tm => { 
                if(!tm) return; 
                if(!matrix[tit].temas[tm]) matrix[tit].temas[tm] = { q:0, v:0, t:0, c:0 }; 
                matrix[tit].temas[tm].q += (1/(tits.length*tms.length)); 
                matrix[tit].temas[tm].v += (d._val/(tits.length*tms.length)); 
                matrix[tit].temas[tm].t += d._tma; matrix[tit].temas[tm].c++; 
            }); 
        }); 
    }); 
    document.querySelector('#matrix tbody').innerHTML = Object.entries(matrix).sort((a,b) => b[1].q - a[1].q).map(([tit, d], i) => { 
        let h = `<tr class="row-p" onclick="toggleM('m${i}')"><td>▶ ${tit}</td><td>${Math.round(d.q)}</td><td>${money(d.v)}</td><td>${Math.round(d.t/d.c)} min</td></tr>`; 
        Object.entries(d.temas).sort((a,b) => b[1].q - a[1].q).forEach(([tm, td]) => { 
            h += `<tr class="row-c m${i}"><td style="padding-left:25px; color:#64748b">↳ ${tm}</td><td>${Math.round(td.q)}</td><td>${money(td.v)}</td><td>${Math.round(td.t/td.c)} min</td></tr>`; 
        }); 
        return h; 
    }).join(''); 
} 

function setMetric(m) { activeMetric = m; document.querySelectorAll('.m-btn').forEach(b => b.classList.remove('active')); document.getElementById('m_'+m).classList.add('active'); renderAll(); } 
function filterByCategory(cat) { activeCategory = cat; applyFilters(); } 
function resetAll() { location.reload(); } 
function toggleM(cls) { document.querySelectorAll('.'+cls).forEach(el => el.classList.toggle('show')); } 
function renderAll() { updateKPIs(); renderUserChart(); renderThemeChart(); renderMatrix(); } 

init(); 
</script> 
</body> 
</html>