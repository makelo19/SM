<?php
session_start();
$usuarioLogadoSessao = $_SESSION['nome_completo'] ?? null;

header('Content-Type: application/json; charset=utf-8');

$arquivo = __DIR__ . '/historico_atendimento.txt';

if (!file_exists($arquivo) || !is_readable($arquivo)) {
    echo json_encode([]);
    exit;
}

$linhas = file($arquivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if (!$linhas || count($linhas) < 1) {
    echo json_encode([]);
    exit;
}

// Cabeçalho
$cabecalho = explode("\t", array_shift($linhas));
$cabecalho = array_map('trim', $cabecalho);

// Mapas das colunas fixas
$mapaFixos = [
    'ID'              => 'id',
    'Data'            => 'data',
    'Categoria'       => 'categoria',
    'Título(s)'       => 'titulos',
    'Tema(s)'         => 'temas',
    'Tipo/Fechamento' => 'sfSelecionado'
];

$historicoLinhas = [];

// Primeiro, filtra e organiza os registros
foreach ($linhas as $linha) {
    $linha = trim($linha);
    if ($linha === '') continue;

    $valores = explode("\t", $linha);
    $valores = array_pad($valores, count($cabecalho), '');

    $registro = ['campos' => []];
    $usuarioDoRegistro = null;

    foreach ($cabecalho as $index => $coluna) {
        $valor = isset($valores[$index]) ? trim($valores[$index]) : '';

        if (isset($mapaFixos[$coluna])) {
            $chaveJson = $mapaFixos[$coluna];
            $registro[$chaveJson] = $valor;
        } else {
            $registro['campos'][$coluna] = $valor;
            if ($coluna === 'usuarioLogado') {
                $usuarioDoRegistro = $valor;
            }
        }
    }

    if ($usuarioDoRegistro === $usuarioLogadoSessao) {
        $historicoLinhas[] = $registro;
    }
}

// Agrupa por ID para formar atendimentos únicos
$historico = [];
foreach ($historicoLinhas as $reg) {
    $id = $reg['id'];
    if (!isset($historico[$id])) {
        // Cria o atendimento
        $historico[$id] = $reg;
        // Inicializa arrays para titulos e temas
        $historico[$id]['titulos'] = [];
        $historico[$id]['temas'] = [];
    }
    // Adiciona título e tema se não existir
    if (!in_array($reg['titulos'], $historico[$id]['titulos']) && $reg['titulos'] !== '') {
        $historico[$id]['titulos'][] = $reg['titulos'];
    }
    if (!in_array($reg['temas'], $historico[$id]['temas']) && $reg['temas'] !== '') {
        $historico[$id]['temas'][] = $reg['temas'];
    }
}

// Converte arrays de volta para strings separadas por "; "
foreach ($historico as &$reg) {
    $reg['titulos'] = implode('; ', $reg['titulos']);
    $reg['temas'] = implode('; ', $reg['temas']);
}

// Retorna como array indexado
echo json_encode(array_values($historico), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
