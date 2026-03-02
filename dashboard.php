<?php
// DASHBOARD.PHP - COM VISUALIZAÇÃO DE TEMPO DE AUSÊNCIA E MODULAR
session_start();
set_time_limit(300);
ini_set('memory_limit', '512M');

// 1. Segurança
if (!isset($_SESSION['logado']) || !$_SESSION['logado']) {
    header("Location: index.php");
    exit;
}

require_once 'classes.php';
date_default_timezone_set('America/Sao_Paulo');
$db = new Database('dados');

$settings = new Settings('dados/');
$config = new Config($db);

$ID_FIELD_KEY = $settings->getIdFieldKey();
$ID_LABEL = $settings->getIdLabel();
$SYSTEM_NAME = $settings->getSystemName();

// =============================================================================
// 2. AJAX HANDLERS
// =============================================================================
if (isset($_POST['acao']) && $_POST['acao'] == 'ajax_acquire_lock') {
    $locksFile = 'dados/locks.json';
    $port = $_POST['port'] ?? '';
    $user = $_SESSION['nome_completo'] ?? 'Anonimo';
    
    if (!$port) exit(json_encode(['success'=>false]));

    $locks = file_exists($locksFile) ? json_decode(file_get_contents($locksFile), true) : [];
    if (!is_array($locks)) $locks = [];
    
    $now = time();

    // Limpeza de outros processos deste usuário
    foreach ($locks as $p => $info) {
        if (($info['user'] ?? '') === $user && $p != $port) {
            unset($locks[$p]);
        }
    }

    // LÓGICA DE PRESERVAÇÃO DA DATA DE ENTRADA
    if (isset($locks[$port]) && $locks[$port]['user'] === $user) {
        $datetimeEntrada = $locks[$port]['datetime']; 
    } else {
        if (isset($locks[$port])) {
            $lastSeen = $locks[$port]['timestamp'] ?? 0;
            if (($now - $lastSeen) < 120) {
                echo json_encode(['success'=>false, 'locked_by'=>$locks[$port]['user']]);
                exit;
            }
        }
        $datetimeEntrada = date('d/m/Y H:i');
    }

    $locks[$port] = [
        'user' => $user,
        'timestamp' => $now,
        'datetime' => $datetimeEntrada
    ];

    file_put_contents($locksFile, json_encode($locks, JSON_PRETTY_PRINT));
    echo json_encode(['success'=>true]);
    exit;
}

if (isset($_POST['acao']) && $_POST['acao'] == 'ajax_release_lock') {
    $locksFile = 'dados/locks.json';
    $port = $_POST['port'] ?? '';
    $user = $_SESSION['nome_completo'];
    $locks = file_exists($locksFile) ? json_decode(file_get_contents($locksFile), true) : [];
    
    if (isset($locks[$port]) && $locks[$port]['user'] === $user) {
        unset($locks[$port]);
        file_put_contents($locksFile, json_encode($locks, JSON_PRETTY_PRINT));
    }
    echo json_encode(['status'=>'ok']);
    exit;
}

// =============================================================================
// 3. FUNÇÕES AUXILIARES E MONITORAMENTO
// =============================================================================
function getAvailableYears() {
    $base = 'dados/Processos'; $years = [];
    if (is_dir($base)) { foreach (scandir($base) as $d) { if (is_numeric($d)) $years[] = $d; } }
    rsort($years); return $years;
}
function getTargetFiles($year, $month) {
    $base = 'dados/Processos'; $files = [];
    $monthNames = [1=>'Janeiro', 2=>'Fevereiro', 3=>'Março', 4=>'Abril', 5=>'Maio', 6=>'Junho', 7=>'Julho', 8=>'Agosto', 9=>'Setembro', 10=>'Outubro', 11=>'Novembro', 12=>'Dezembro'];
    $targetYears = ($year === 'all') ? getAvailableYears() : [$year];
    foreach ($targetYears as $y) {
        $path = "$base/$y"; if (!is_dir($path)) continue;
        if ($month === 'all') { $glob = glob("$path/*.json"); if ($glob) $files = array_merge($files, $glob); }
        else {
            $mInt = intval($month); $name = $monthNames[$mInt] ?? '';
            if ($name && file_exists("$path/$name.json")) $files[] = "$path/$name.json";
            elseif (file_exists("$path/$mInt.json")) $files[] = "$path/$mInt.json";
            elseif (file_exists("$path/".sprintf('%02d', $mInt).".json")) $files[] = "$path/".sprintf('%02d', $mInt).".json";
        }
    }
    return $files;
}

$currentYear = date('Y'); $currentMonth = date('n');
$f_ano = $_GET['f_ano'] ?? $currentYear; 
$f_mes = $_GET['f_mes'] ?? $currentMonth;
$f_dt_ini = $_GET['f_dt_ini'] ?? ''; 
$f_dt_fim = $_GET['f_dt_fim'] ?? '';

// Identifica filtros modulares
$dynamicFiltersSchema = [];
$uniqueDynamicOptions = [];
$f_dynamic = [];

$VALUE_FIELD_KEY = '';
$hasMoney = false;
$moneyFieldLabel = 'Volume Financeiro';

$statusFieldKey = '';
$hasStatus = false;
$statusFieldLabel = 'Status Global';

$schemaFields = $config->getFields('Base_processos_schema');
foreach($schemaFields as $f) {
    if (isset($f['deleted']) && $f['deleted']) continue;
    if ($f['type'] === 'title') continue;
    
    if ($f['type'] === 'money' && !$hasMoney) {
        $VALUE_FIELD_KEY = $f['key'];
        $moneyFieldLabel = $f['label'];
        $hasMoney = true;
    }
    if ($f['key'] === 'STATUS') {
        $statusFieldKey = $f['key'];
        $statusFieldLabel = $f['label'];
        $hasStatus = true;
    }

    $showFilter = (isset($f['show_dashboard_filter']) && $f['show_dashboard_filter']) || 
                  (!isset($f['show_dashboard_filter']) && isset($f['show_filter']) && $f['show_filter']);
                  
    if ($showFilter && $f['key'] !== 'DATA' && $f['key'] !== 'Ultima_Alteracao') {
        $dynamicFiltersSchema[] = $f;
        $uniqueDynamicOptions[$f['key']] = [];
        $f_dynamic[$f['key']] = $_GET['f_' . $f['key']] ?? '';
    }
}

// --- LÓGICA DE MONITORAMENTO ---
$locksFile = 'dados/locks.json';
$activeEdits = [];

if (file_exists($locksFile)) {
    $locksData = json_decode(file_get_contents($locksFile), true) ?? [];
    $now = time();
    $cleanLocks = [];
    $hasChanges = false;

    foreach ($locksData as $port => $info) {
        $lastSeen = $info['timestamp'] ?? 0;
        $strEntrada = $info['datetime'] ?? date('d/m/Y H:i'); 
        $dtObj = DateTime::createFromFormat('d/m/Y H:i', $strEntrada);
        $timestampEntrada = $dtObj ? $dtObj->getTimestamp() : $lastSeen; 
        $totalWorkTime = $now - $timestampEntrada;
        $idleTime = $now - $lastSeen;        

        if ($idleTime < 7200) { 
            $cleanLocks[$port] = $info;
            if ($idleTime <= 600) { 
                $statusLabel = "Online"; $statusClass = "st-online"; $badgeClass = "bg-success"; $rowClass = "border-start border-success border-4"; $sortOrder = 1; $pulseClass = "pulse-dot";
            } elseif ($idleTime <= 3600) { 
                $statusLabel = "Ausente"; $statusClass = "st-away"; $badgeClass = "bg-warning text-dark"; $rowClass = "border-start border-warning border-4"; $sortOrder = 2; $pulseClass = "";
            } else { 
                $statusLabel = "Offline"; $statusClass = "st-offline"; $badgeClass = "bg-secondary"; $rowClass = "border-start border-secondary border-4 opacity-75"; $sortOrder = 3; $pulseClass = "";
            }
            $h = floor($totalWorkTime / 3600); $m = floor(($totalWorkTime % 3600) / 60); $s = $totalWorkTime % 60;
            $timeStr = sprintf('%02d:%02d:%02d', $h, $m, $s);
            $ih = floor($idleTime / 3600); $im = floor(($idleTime % 3600) / 60); $is = $idleTime % 60;
            $idleStr = ($ih > 0) ? sprintf('%02dh %02dm', $ih, $im) : sprintf('%02dm %02ds', $im, $is);

            $activeEdits[] = ['port'=>$port, 'user'=>$info['user']??'Desconhecido', 'raw_seconds'=>$totalWorkTime, 'time_fmt'=>$timeStr, 'idle_seconds'=>$idleTime, 'idle_fmt'=>$idleStr, 'status_lbl'=>$statusLabel, 'badge_cls'=>$badgeClass, 'row_cls'=>$rowClass, 'pulse'=>$pulseClass, 'sort'=>$sortOrder];
        } else { $hasChanges = true; }
    }
    if ($hasChanges) file_put_contents($locksFile, json_encode($cleanLocks, JSON_PRETTY_PRINT));
}
usort($activeEdits, function($a, $b) { if ($a['sort'] === $b['sort']) return $b['raw_seconds'] <=> $a['raw_seconds']; return $a['sort'] <=> $b['sort']; });

// --- PROCESSAMENTO DE DADOS (KPIs, Charts) ---
$targetFiles = getTargetFiles($f_ano, $f_mes);
$totalValor = 0; $totalQtd = 0; 
$statusCount = []; 
$statsByUser = [];

foreach ($targetFiles as $file) {
    $content = @file_get_contents($file); if (!$content) continue;
    $rows = json_decode($content, true); if (!is_array($rows)) continue;
    foreach ($rows as $r) {
        
        $atendente = trim($r['Nome_atendente'] ?? 'Desconhecido');
        if (!$atendente) $atendente = 'Desconhecido';

        // Filters mapping Options extraction
        foreach($dynamicFiltersSchema as $f) {
            $fv = trim($r[$f['key']] ?? '');
            if ($fv !== '') {
                $uniqueDynamicOptions[$f['key']][$fv] = true;
            }
        }
        
        // Date Filter
        $filterTs = 0;
        $dataStr = $r['DATA'] ?? '';
        $luStr = $r['Ultima_Alteracao'] ?? '';

        if ($luStr) {
            $dtObj = DateTime::createFromFormat('d/m/Y H:i:s', $luStr) ?: DateTime::createFromFormat('d/m/Y H:i', $luStr);
            if ($dtObj) $filterTs = $dtObj->getTimestamp();
        }
        if (!$filterTs && $dataStr) {
            $dtObj = DateTime::createFromFormat('d/m/Y H:i:s', $dataStr) ?: DateTime::createFromFormat('d/m/Y H:i', $dataStr) ?: DateTime::createFromFormat('d/m/Y', $dataStr);
            if ($dtObj) $filterTs = $dtObj->getTimestamp();
        }

        if ($f_dt_ini || $f_dt_fim) {
            if ($filterTs) {
                if ($f_dt_ini && $filterTs < strtotime($f_dt_ini)) continue;
                if ($f_dt_fim && $filterTs > strtotime($f_dt_fim)) continue;
            }
        }
        
        // Evaluate dynamic filters matching
        $skip = false;
        foreach($dynamicFiltersSchema as $f) {
            $fkey = $f['key'];
            if ($f_dynamic[$fkey] !== '') {
                $rVal = trim($r[$fkey] ?? '');
                if ($rVal !== $f_dynamic[$fkey]) {
                    $skip = true; break;
                }
            }
        }
        if ($skip) continue;

        $totalQtd++;

        if ($hasStatus) {
            $st = trim($r[$statusFieldKey] ?? 'Outros');
            if (!$st) $st = 'Outros';
            $statusKey = mb_strtoupper($st, 'UTF-8');
            if (!isset($statusCount[$statusKey])) $statusCount[$statusKey] = 0;
            $statusCount[$statusKey]++; 
        }

        // TMA Duration Calculation (Alteration specific logic)
        $tsIni = 0; $tsFim = 0;
        if ($dataStr) {
            $dt = DateTime::createFromFormat('d/m/Y H:i:s', $dataStr) ?: 
                  DateTime::createFromFormat('d/m/Y H:i', $dataStr) ?: 
                  DateTime::createFromFormat('d/m/Y', $dataStr);
            if ($dt) $tsIni = $dt->getTimestamp();
        }
        if ($luStr) {
            $dt = DateTime::createFromFormat('d/m/Y H:i:s', $luStr) ?: 
                  DateTime::createFromFormat('d/m/Y H:i', $luStr) ?: 
                  DateTime::createFromFormat('d/m/Y', $luStr);
            if ($dt) $tsFim = $dt->getTimestamp();
        }

        $duration = 0;
        if ($tsIni && $tsFim && $tsFim >= $tsIni) {
            $duration = $tsFim - $tsIni;
        }

        if (!isset($statsByUser[$atendente])) {
            $statsByUser[$atendente] = ['qtd'=>0, 'valor'=>0, 'total_duration'=>0];
        }
        $statsByUser[$atendente]['qtd']++;
        $statsByUser[$atendente]['total_duration'] += $duration;
        
        if ($hasMoney) {
            $vRaw = $r[$VALUE_FIELD_KEY] ?? '0';
            $vFloat = (float)str_replace(['.', ','], ['', '.'], str_replace(['R$', ' ', "\u{00a0}"], '', $vRaw));
            $statsByUser[$atendente]['valor'] += $vFloat;
            $totalValor += $vFloat;
        }
    }
}

// Finalize TMA
foreach ($statsByUser as $u => &$s) {
    if ($s['qtd'] > 0) {
        $s['tma'] = $s['total_duration'] / $s['qtd'];
    } else {
        $s['tma'] = 0;
    }
}
unset($s);

// Sorting for Charts
$rankingProducao = $statsByUser;
uasort($rankingProducao, function($a, $b) { return $b['qtd'] <=> $a['qtd']; });

$rankingValor = $statsByUser;
uasort($rankingValor, function($a, $b) { return $b['valor'] <=> $a['valor']; });

$rankingTMA = $statsByUser;
uasort($rankingTMA, function($a, $b) { return $b['tma'] <=> $a['tma']; });

// Helper Format
function fmtTime($s) {
    if ($s == 0) return '-'; if ($s < 60) return round($s).'s';
    $m = floor($s/60); $h = floor($m/60); return ($h>0) ? "{$h}h ".($m%60)."m" : "{$m}m";
}
$optYears = getAvailableYears();

// Calculate Team Average
$allTmas = array_column($statsByUser, 'tma');
$allTmas = array_filter($allTmas, function($v){ return $v > 0; }); 
$mediaTMA = (count($allTmas) > 0) ? array_sum($allTmas) / count($allTmas) : 0;
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wiz Dashboard - Monitoramento</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>
    <style>
        :root { --wiz-orange: #FF8C00; --wiz-navy: #003366; --bg-gray: #f0f2f5; }
        body { background-color: var(--bg-gray); font-family: 'Segoe UI', sans-serif; padding-bottom: 50px; }
        .navbar-wiz { background: linear-gradient(90deg, var(--wiz-navy), #001f3f); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .card-wiz { border: none; border-radius: 12px; background: white; box-shadow: 0 3px 10px rgba(0,0,0,0.03); }
        .btn-refresh { background-color: #ffc107; color: var(--wiz-navy); font-weight: 700; border: none; }
        .btn-refresh:hover { background-color: #e0a800; }
        .btn-wiz { background-color: var(--wiz-orange); color: white; font-weight: 600; }
        .btn-wiz:hover { background-color: #e67e00; color: white; }
        .kpi-val { font-size: 1.8rem; font-weight: 800; color: var(--wiz-navy); }
        .text-navy { color: var(--wiz-navy); }
        .text-orange { color: var(--wiz-orange); }
        .live-timer { font-variant-numeric: tabular-nums; }
        .pulse-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; margin-right: 4px; animation: pulse 1.5s infinite; }
        .bg-success .pulse-dot { background: #d1e7dd; }
        @keyframes pulse { 0% { opacity: 0.5; } 100% { opacity: 1; } }
    </style>
</head>
<body>

<nav class="navbar navbar-dark navbar-wiz mb-4 sticky-top">
    <div class="container-fluid px-4">
        <a class="navbar-brand fw-bold" href="#"><i class="fas fa-chart-line me-2"></i>WIZ DASHBOARD</a>
        <div class="d-flex align-items-center">
            <span class="text-white small me-3"><i class="fas fa-user-circle me-1"></i> <?= htmlspecialchars($_SESSION['nome_completo'] ?? 'Usuário') ?></span>
        </div>
    </div>
</nav>

<div class="container-fluid px-4 pb-5">

    <!-- LIVE MONITOR -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card card-wiz border-start border-4 border-danger">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 text-danger fw-bold"><i class="fas fa-tower-broadcast me-2 live-timer text-danger"></i>EM EDIÇÃO (TEMPO REAL)</h6>
                    <span class="badge bg-danger rounded-pill"><?= count($activeEdits) ?> Ativos</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="bg-light text-secondary small">
                                <tr>
                                    <th>Colaborador</th>
                                    <th><?= htmlspecialchars($ID_LABEL) ?></th>
                                    <th>Tempo em Edição</th>
                                    <th>Tempo Ocioso</th> <th>Status (Sinal)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($activeEdits as $edit): 
                                     $cls = $edit['raw_seconds'] > 600 ? 'text-danger fw-bold' : 'text-navy';
                                     $idleCls = $edit['idle_seconds'] > 600 ? 'text-warning fw-bold' : 'text-muted small';
                                ?>
                                <tr>
                                    <td class="fw-bold text-navy ps-4"><i class="fas fa-user me-2 opacity-50"></i><?= htmlspecialchars($edit['user']) ?></td>
                                    <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($edit['port']) ?></span></td>
                                    <td class="<?= $cls ?> live-timer" data-seconds="<?= $edit['raw_seconds'] ?>">
                                        <i class="fas fa-clock me-1"></i> <span class="clock-display"><?= $edit['time_fmt'] ?></span>
                                    </td>
                                    <td class="<?= $idleCls ?>">
                                        <i class="fas fa-hourglass-half me-1"></i> <?= $edit['idle_fmt'] ?>
                                    </td>
                                    <td>
                                        <span class="badge <?= $edit['badge_cls'] ?> bg-opacity-75">
                                            <span class="ping-dot <?= $edit['pulse'] ?>"></span>
                                            <?= $edit['status_lbl'] ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if(empty($activeEdits)): ?>
                                    <tr><td colspan="5" class="text-center py-4 text-muted small"><i class="fas fa-check-circle me-1 text-success"></i> Nenhum registro em edição.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- FILTERS -->
    <div class="card card-wiz mb-4">
        <div class="card-body py-3">
            <form id="filterForm" method="GET" class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small fw-bold mb-1">Mês</label>
                    <select name="f_mes" class="form-select form-select-sm bg-light fw-bold text-navy">
                        <option value="all" <?= $f_mes=='all'?'selected':'' ?>>Todos</option>
                        <?php for($i=1; $i<=12; $i++) { $nm=$db->getPortugueseMonth($i); echo "<option value='$i' ".($f_mes==$i?'selected':'').">$nm</option>"; } ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold mb-1">Ano</label>
                    <select name="f_ano" class="form-select form-select-sm bg-light fw-bold text-navy">
                        <option value="all" <?= $f_ano=='all'?'selected':'' ?>>Todos</option>
                        <?php foreach($optYears as $y) echo "<option value='$y' ".($f_ano==$y?'selected':'').">$y</option>"; ?>
                    </select>
                </div>
                
                <?php foreach($dynamicFiltersSchema as $f): 
                      $fkey = $f['key'];
                      $opts = array_keys($uniqueDynamicOptions[$fkey] ?? []);
                      sort($opts);
                ?>
                <div class="col-md-2">
                    <label class="form-label small fw-bold mb-1"><?= htmlspecialchars($f['label']) ?></label>
                    <select name="f_<?= $fkey ?>" class="form-select form-select-sm bg-light text-navy">
                        <option value="">Todos</option>
                        <?php foreach($opts as $opt): ?>
                            <option value="<?= htmlspecialchars($opt) ?>" <?= ($f_dynamic[$fkey] === (string)$opt) ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endforeach; ?>

                <!-- Optional Date Range -->
                <div class="col-md-2">
                    <label class="form-label small fw-bold mb-1">Data/Hora Início</label>
                    <input type="datetime-local" name="f_dt_ini" class="form-control form-control-sm" value="<?= $f_dt_ini ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold mb-1">Data/Hora Fim</label>
                    <input type="datetime-local" name="f_dt_fim" class="form-control form-control-sm" value="<?= $f_dt_fim ?>">
                </div>
                
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" id="btnFilter" class="btn btn-wiz btn-sm flex-fill"><i class="fas fa-filter me-1"></i> Filtrar</button>
                    <button type="button" id="btnRefresh" class="btn btn-refresh btn-sm flex-fill"><i class="fas fa-sync-alt me-1"></i> Atualizar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- KPIS -->
    <div class="row g-4 mb-4">
        <?php if($hasMoney): ?>
        <div class="col-md-4">
            <div class="card card-wiz h-100 border-start border-4 border-success p-3">
                <div class="small text-uppercase text-muted fw-bold"><?= htmlspecialchars($moneyFieldLabel) ?></div>
                <div class="fs-2 fw-bold text-success"><i class="<?= htmlspecialchars($settings->getCurrencyIcon()) ?> me-2"></i><?= htmlspecialchars($settings->getCurrencySymbol()) ?> <?= number_format($totalValor, 2, ',', '.') ?></div>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="col-md-<?= $hasMoney ? '4' : '6' ?>">
            <div class="card card-wiz h-100 border-start border-4 border-primary p-3">
                <div class="small text-uppercase text-muted fw-bold">Quantidade Registros</div>
                <div class="fs-2 fw-bold text-navy"><?= number_format($totalQtd, 0, ',', '.') ?></div>
            </div>
        </div>
        <div class="col-md-<?= $hasMoney ? '4' : '6' ?>">
            <div class="card card-wiz h-100 border-start border-4 border-warning p-3">
                <div class="small text-uppercase text-muted fw-bold">TMA Médio (Equipe)</div>
                <div class="fs-2 fw-bold text-orange"><?= fmtTime($mediaTMA) ?></div>
            </div>
        </div>
    </div>

    <!-- CHARTS ROW 1 -->
    <div class="row g-4 mb-4">
        <div class="col-12">
            <div class="card card-wiz h-100">
                <div class="card-header bg-white py-3 border-0"><h6 class="m-0 fw-bold text-navy">Ranking de Produção (Qtd)</h6></div>
                <div class="card-body"><canvas id="chartProducao" height="100"></canvas></div>
            </div>
        </div>
        <?php if($hasStatus): ?>
        <div class="col-12">
            <div class="card card-wiz h-100">
                <div class="card-header bg-white py-3 border-0"><h6 class="m-0 fw-bold text-navy"><?= htmlspecialchars($statusFieldLabel) ?></h6></div>
                <div class="card-body"><canvas id="chartStatus" height="100"></canvas></div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- CHARTS ROW 2 -->
    <div class="row g-4">
        <div class="col-12">
            <div class="card card-wiz h-100">
                <div class="card-header bg-white py-3 border-0"><h6 class="m-0 fw-bold text-navy">Ranking TMA (Tempo Médio)</h6></div>
                <div class="card-body"><canvas id="chartTMA" height="100"></canvas></div>
            </div>
        </div>
        <?php if($hasMoney): ?>
        <div class="col-12">
            <div class="card card-wiz h-100">
                <div class="card-header bg-white py-3 border-0"><h6 class="m-0 fw-bold text-navy"><?= htmlspecialchars($moneyFieldLabel) ?></h6></div>
                <div class="card-body"><canvas id="chartValor" height="100"></canvas></div>
            </div>
        </div>
        <?php endif; ?>
    </div>

</div>

<script>
    Chart.register(ChartDataLabels);
    Chart.defaults.font.family = "'Segoe UI', sans-serif";
    
    // Data Preparation
    const labelsProd = <?= json_encode(array_keys($rankingProducao)) ?>;
    const dataProd = <?= json_encode(array_column($rankingProducao, 'qtd')) ?>;
    
    const labelsVal = <?= json_encode(array_keys($rankingValor)) ?>;
    const dataVal = <?= json_encode(array_column($rankingValor, 'valor')) ?>;
    
    const labelsTMA = <?= json_encode(array_keys($rankingTMA)) ?>;
    const dataTMA = <?= json_encode(array_column($rankingTMA, 'tma')) ?>; // Seconds
    const dataTMAmin = dataTMA.map(s => (s/60).toFixed(1)); // Minutes for chart

    // 1. Chart Produção
    new Chart(document.getElementById('chartProducao'), {
        type: 'bar',
        data: {
            labels: labelsProd,
            datasets: [{
                label: 'Qtd Registros',
                data: dataProd,
                backgroundColor: '#003366',
                borderRadius: 4
            }]
        },
        options: { 
            responsive: true, 
            plugins: { 
                legend: { display: false },
                datalabels: { anchor: 'end', align: 'top', font: { weight: 'bold' } }
            }, 
            scales: { y: { beginAtZero: true } } 
        }
    });

    <?php if($hasMoney): ?>
    // 2. Chart Valor
    new Chart(document.getElementById('chartValor'), {
        type: 'bar',
        data: {
            labels: labelsVal,
            datasets: [{
                label: 'Volume',
                data: dataVal,
                backgroundColor: '#28a745',
                borderRadius: 4
            }]
        },
        options: { 
            responsive: true, 
            plugins: { 
                legend: { display: false },
                datalabels: { 
                    anchor: 'end', 
                    align: 'top', 
                    formatter: (value) => value.toLocaleString('pt-BR', {minimumFractionDigits: 2}),
                    font: { weight: 'bold' }
                }
            }, 
            scales: { y: { beginAtZero: true } } 
        }
    });
    <?php endif; ?>

    // 3. Chart TMA
    new Chart(document.getElementById('chartTMA'), {
        type: 'bar',
        data: {
            labels: labelsTMA,
            datasets: [{
                label: 'Tempo Médio (min)',
                data: dataTMAmin,
                backgroundColor: '#FF8C00',
                borderRadius: 4
            }]
        },
        options: { 
            responsive: true, 
            layout: { padding: { top: 30 } },
            plugins: { 
                legend: { display: false },
                datalabels: { anchor: 'end', align: 'top', font: { weight: 'bold' } }
            }, 
            scales: { y: { beginAtZero: true } } 
        }
    });

    <?php if($hasStatus): ?>
    // 4. Chart Status
    var chStatus = document.getElementById('chartStatus');
    if (chStatus) {
        new Chart(chStatus, { 
            type: 'bar', 
            data: { 
                labels: <?= json_encode(array_keys($statusCount)) ?>, 
                datasets: [{ 
                    label: 'Status Global',
                    data: <?= json_encode(array_values($statusCount)) ?>, 
                    backgroundColor: '#003366', 
                    borderRadius: 4
                }] 
            }, 
            options: { 
                responsive: true, 
                plugins: { 
                    legend: { display: false },
                    datalabels: { anchor: 'end', align: 'top', font: { weight: 'bold' } }
                },
                scales: { y: { beginAtZero: true } } 
            } 
        });
    }
    <?php endif; ?>

    // --- CRONÔMETRO VIVO ---
    function startLiveTimers() {
        setInterval(function() {
            var timers = document.querySelectorAll('.live-timer');
            timers.forEach(function(td) {
                var s = parseInt(td.getAttribute('data-seconds'));
                s++;
                td.setAttribute('data-seconds', s);
                
                var h = Math.floor(s/3600);
                var m = Math.floor((s%3600)/60);
                var sec = s%60;
                
                h = h < 10 ? '0'+h : h;
                m = m < 10 ? '0'+m : m;
                sec = sec < 10 ? '0'+sec : sec;
                
                var display = td.querySelector('.clock-display');
                if(display) display.innerText = h + ':' + m + ':' + sec;
                
                if(s > 600) { 
                    td.classList.remove('text-navy'); 
                    td.classList.add('text-danger', 'fw-bold'); 
                }
            });
        }, 1000);
    }
    document.addEventListener('DOMContentLoaded', startLiveTimers);

    // Auto-refresh a cada 60s
    setInterval(function(){ window.location.reload(); }, 60000);

    // ==========================================
    // LOADING STATES
    // ==========================================
    document.addEventListener('DOMContentLoaded', function() {
        const filterForm = document.getElementById('filterForm');
        const btnFilter = document.getElementById('btnFilter');
        const btnRefresh = document.getElementById('btnRefresh');

        if (filterForm) {
            filterForm.addEventListener('submit', function() {
                // Desativa botões
                if (btnFilter) {
                    btnFilter.disabled = true;
                    // Altera texto e adiciona spinner
                    btnFilter.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Carregando...';
                }
                if (btnRefresh) {
                    btnRefresh.disabled = true;
                }
            });
        }

        if (btnRefresh) {
            btnRefresh.addEventListener('click', function() {
                if (btnFilter) btnFilter.disabled = true;
                this.disabled = true;
                // Executa reload
                window.location.reload();
            });
        }
    });
</script>
</body>
</html>
